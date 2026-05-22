<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProductCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query();

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->query('customizable') === 'true') {
            $query->where('is_customizable', true);
        }

        if ($request->query('includeVariantCount') === 'true') {
            $query->withCount('variants');
        }

        [$orderByColumn, $orderByDirection] = $this->parseOrderBy($request->query('orderBy', 'createdAt:desc'));

        $query->orderBy($orderByColumn, $orderByDirection);

        if ($request->filled('take')) {
            $query->limit((int) $request->query('take'));
        }

        return ProductResource::collection($query->get());
    }

    public function showBySlug(string $slug): ProductResource|JsonResponse
    {
        $product = Product::where('slug', $slug)->with(['variants', 'presetDesigns'])->first();

        if (! $product) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new ProductResource($product);
    }

    public function show(string $id): ProductResource|JsonResponse
    {
        $product = Product::with('variants')->find($id);

        if (! $product) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new ProductResource($product);
    }

    public function store(Request $request): ProductResource|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9-]+$/', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'basePrice' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', Rule::enum(ProductCategory::class)],
            'isCustomizable' => ['boolean'],
            'images' => ['nullable', 'array'],
        ]);

        $product = Product::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'base_price' => $validated['basePrice'],
            'category' => $validated['category'],
            'is_customizable' => $validated['isCustomizable'] ?? false,
            'images' => $validated['images'] ?? [],
        ]);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): ProductResource|JsonResponse
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string'],
            'slug' => ['sometimes', 'required', 'string', 'regex:/^[a-z0-9-]+$/', Rule::unique('products', 'slug')->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'basePrice' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'category' => ['sometimes', 'required', Rule::enum(ProductCategory::class)],
            'isCustomizable' => ['boolean'],
            'images' => ['sometimes', 'nullable', 'array'],
            'images.*' => ['string', 'url'],
        ]);

        $product->update([
            'name' => $validated['name'] ?? $product->name,
            'slug' => $validated['slug'] ?? $product->slug,
            'description' => $validated['description'] ?? $product->description,
            'base_price' => $validated['basePrice'] ?? $product->base_price,
            'category' => $validated['category'] ?? $product->category->value,
            'is_customizable' => $validated['isCustomizable'] ?? $product->is_customizable,
            'images' => $validated['images'] ?? $product->images,
        ]);

        return new ProductResource($product->fresh());
    }

    public function addVariant(Request $request, string $id): ProductVariantResource|JsonResponse
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'label' => ['required', 'string'],
            'imageUrl' => ['nullable', 'string', 'url'],
            'priceModifier' => ['required', 'numeric'],
            'stock' => ['required', 'integer', 'min:0'],
        ]);

        $variant = $product->variants()->create([
            'label' => $validated['label'],
            'image_url' => $validated['imageUrl'] ?? null,
            'price_modifier' => $validated['priceModifier'],
            'stock' => $validated['stock'],
        ]);

        return (new ProductVariantResource($variant))->response()->setStatusCode(201);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:5120'], // 5MB max
            'productId' => ['sometimes', 'nullable', 'string', 'exists:products,id'],
        ]);

        $path = $request->file('image')->store('products', 'public');
        $url = asset('storage/'.$path);

        if ($request->filled('productId')) {
            $product = Product::find($request->input('productId'));
            $product->images = array_merge($product->images ?? [], [$url]);
            $product->save();
        }

        return response()->json([
            'url' => $url,
            'path' => $path,
        ], 201);
    }

    public function deleteVariant(string $id, string $variantId): JsonResponse
    {
        $variant = ProductVariant::where('id', $variantId)->where('product_id', $id)->first();

        if (! $variant) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $variant->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array{string, string}
     */
    private function parseOrderBy(string $orderBy): array
    {
        $columnMap = [
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
            'basePrice' => 'base_price',
            'name' => 'name',
        ];

        [$column, $direction] = array_pad(explode(':', $orderBy, 2), 2, 'desc');
        $column = $columnMap[$column] ?? 'created_at';
        $direction = in_array(strtolower($direction), ['asc', 'desc']) ? $direction : 'desc';

        return [$column, $direction];
    }
}
