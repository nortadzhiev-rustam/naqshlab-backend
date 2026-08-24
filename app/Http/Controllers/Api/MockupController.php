<?php

namespace App\Http\Controllers\Api;

use App\Enums\MockupStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MockupResource;
use App\Jobs\GenerateMockup;
use App\Models\Mockup;
use App\Models\MockupTemplate;
use App\Services\MockupComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MockupController extends Controller
{
    /** Roughly 8 MB of artwork once base64 is decoded. */
    private const MAX_DESIGN_BYTES = 8_388_608;

    /**
     * Queue a mockup of this artwork on every template that applies to the
     * product. Identical artwork on the same template is rendered once and
     * reused, so a repeat request is a lookup rather than a render.
     */
    public function store(Request $request): AnonymousResourceCollection|JsonResponse
    {
        if (! MockupComposer::isSupported()) {
            return response()->json([
                'message' => 'Mockup rendering is unavailable on this host.',
            ], 503);
        }

        $validated = $request->validate([
            'design' => ['required', 'string'],
            'productId' => ['nullable', 'string', 'exists:products,id'],
            'category' => ['nullable', 'string'],
            'templateIds' => ['nullable', 'array'],
            'templateIds.*' => ['string'],
        ]);

        $design = $this->decodeDesign($validated['design']);
        $templates = $this->resolveTemplates($validated);

        if ($templates->isEmpty()) {
            return response()->json(['message' => 'No mockup templates match this product.'], 404);
        }

        $designHash = hash('sha256', $design);
        $designPath = Mockup::designPathFor($designHash);

        if (! Storage::disk('local')->exists($designPath)) {
            Storage::disk('local')->put($designPath, $design);
        }

        $mockups = $templates->map(function (MockupTemplate $template) use ($designHash): Mockup {
            $cacheKey = hash('sha256', implode(':', [
                $designHash,
                $template->renderSignature(),
                MockupComposer::PIPELINE_VERSION,
            ]));

            $mockup = Mockup::firstOrCreate(
                ['cache_key' => $cacheKey],
                [
                    'design_hash' => $designHash,
                    'mockup_template_id' => $template->id,
                    'status' => MockupStatus::Pending,
                ]
            );

            if ($mockup->wasRecentlyCreated || $mockup->status === MockupStatus::Failed) {
                $mockup->update(['status' => MockupStatus::Pending, 'failure_reason' => null]);
                GenerateMockup::dispatch($mockup->id);
            }

            return $mockup->refresh();
        });

        return MockupResource::collection($mockups);
    }

    public function show(string $cacheKey): MockupResource|JsonResponse
    {
        $mockup = Mockup::where('cache_key', $cacheKey)->first();

        if (! $mockup) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new MockupResource($mockup);
    }

    /**
     * Accepts either a data URL from the studio canvas or bare base64.
     *
     * @throws ValidationException
     */
    private function decodeDesign(string $value): string
    {
        if (preg_match('/^data:image\/\w+;base64,(.+)$/s', $value, $matches) === 1) {
            $value = $matches[1];
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false || $decoded === '') {
            throw ValidationException::withMessages([
                'design' => 'The design must be a base64-encoded image.',
            ]);
        }

        if (strlen($decoded) > self::MAX_DESIGN_BYTES) {
            throw ValidationException::withMessages([
                'design' => 'The design is too large to render.',
            ]);
        }

        if (@getimagesizefromstring($decoded) === false) {
            throw ValidationException::withMessages([
                'design' => 'The design must be a valid image.',
            ]);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Collection<int, MockupTemplate>
     */
    private function resolveTemplates(array $validated): Collection
    {
        $query = MockupTemplate::where('is_active', true)->orderBy('sort_order');

        if (! empty($validated['templateIds'])) {
            return $query->whereIn('id', $validated['templateIds'])->get();
        }

        if (! empty($validated['productId'])) {
            $forProduct = (clone $query)->where('product_id', $validated['productId'])->get();

            if ($forProduct->isNotEmpty()) {
                return $forProduct;
            }
        }

        if (! empty($validated['category'])) {
            return $query->whereNull('product_id')
                ->where('category', $validated['category'])
                ->get();
        }

        return collect();
    }
}
