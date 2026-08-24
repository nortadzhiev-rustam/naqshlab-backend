<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MockupTemplateResource;
use App\Models\MockupTemplate;
use App\Services\MockupComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AdminMockupTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MockupTemplate::query()->orderBy('sort_order')->latest();

        if ($request->filled('productId')) {
            $query->where('product_id', $request->query('productId'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        return MockupTemplateResource::collection($query->get());
    }

    public function show(string $id): MockupTemplateResource|JsonResponse
    {
        $template = MockupTemplate::find($id);

        if (! $template) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new MockupTemplateResource($template);
    }

    public function store(Request $request): MockupTemplateResource|JsonResponse
    {
        $validated = $request->validate($this->rules());

        $template = MockupTemplate::create($this->attributes($validated));

        return (new MockupTemplateResource($template))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): MockupTemplateResource|JsonResponse
    {
        $template = MockupTemplate::find($id);

        if (! $template) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate($this->rules(partial: true));

        $template->update($this->attributes($validated, $template));

        return new MockupTemplateResource($template->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $template = MockupTemplate::find($id);

        if (! $template) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $template->delete();

        return response()->json(null, 204);
    }

    /**
     * Store a base photo or garment mask and report its pixel size, which the
     * admin quad picker needs to map clicks onto the image's own coordinates.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:10240'],
            'kind' => ['required', Rule::in(['base', 'mask'])],
        ]);

        $file = $request->file('image');
        $path = $file->storeAs(
            'mockup-templates',
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'public'
        );

        [$width, $height] = getimagesize($file->getRealPath()) ?: [null, null];

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'width' => $width,
            'height' => $height,
        ], 201);
    }

    /**
     * Render one mockup synchronously from an unsaved template, so geometry and
     * the displacement and shading strengths can be tuned before committing
     * them. Unsaved is the point: tuning against a saved record would mean
     * writing every intermediate guess.
     */
    public function preview(Request $request): JsonResponse
    {
        if (! MockupComposer::isSupported()) {
            return response()->json(['message' => 'Mockup rendering is unavailable on this host.'], 503);
        }

        $validated = $request->validate([
            'design' => ['required', 'string'],
            'basePath' => ['required', 'string'],
            'maskPath' => ['nullable', 'string'],
            'printArea' => ['required', 'array'],
            'printArea.quad' => ['required', 'array', 'size:4'],
            'printArea.quad.*' => ['required', 'array', 'size:2'],
            'printArea.quad.*.*' => ['required', 'numeric'],
            'displacementScale' => ['required', 'integer', 'min:0', 'max:255'],
            'shadingStrength' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $design = $this->decodeDesign($validated['design']);

        if ($design === null) {
            return response()->json(['message' => 'The design must be a base64-encoded image.'], 422);
        }

        $template = new MockupTemplate([
            'name' => 'preview',
            'base_path' => $validated['basePath'],
            'mask_path' => $validated['maskPath'] ?? null,
            'print_area' => $validated['printArea'],
            'displacement_scale' => $validated['displacementScale'],
            'shading_strength' => $validated['shadingStrength'],
        ]);

        try {
            $result = (new MockupComposer)->render($design, $template);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'dataUrl' => 'data:image/webp;base64,'.base64_encode($result['contents']),
            'width' => $result['width'],
            'height' => $result['height'],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'basePath' => [$required, 'string'],
            'maskPath' => ['nullable', 'string'],
            'productId' => ['nullable', 'string', 'exists:products,id'],
            'category' => ['nullable', 'string'],
            'printArea' => [$required, 'array'],
            'printArea.quad' => [$required, 'array', 'size:4'],
            'printArea.quad.*' => ['required', 'array', 'size:2'],
            'printArea.quad.*.*' => ['required', 'numeric'],
            'displacementScale' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'shadingStrength' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated, ?MockupTemplate $existing = null): array
    {
        $map = [
            'name' => 'name',
            'basePath' => 'base_path',
            'maskPath' => 'mask_path',
            'productId' => 'product_id',
            'category' => 'category',
            'printArea' => 'print_area',
            'displacementScale' => 'displacement_scale',
            'shadingStrength' => 'shading_strength',
            'sortOrder' => 'sort_order',
            'isActive' => 'is_active',
        ];

        $attributes = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $attributes[$column] = $validated[$input];
            } elseif (! $existing) {
                // Let the column defaults stand on create.
                continue;
            }
        }

        return $attributes;
    }

    private function decodeDesign(string $value): ?string
    {
        if (preg_match('/^data:image\/\w+;base64,(.+)$/s', $value, $matches) === 1) {
            $value = $matches[1];
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false || $decoded === '' || @getimagesizefromstring($decoded) === false) {
            return null;
        }

        return $decoded;
    }
}
