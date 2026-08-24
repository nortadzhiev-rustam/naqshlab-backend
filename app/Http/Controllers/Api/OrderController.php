<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\PresetDesign;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(Request $request): OrderResource|JsonResponse
    {
        $userId = $request->header('x-user-id');

        if (! $userId || ! User::find($userId)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'shippingAddress' => ['required', 'array'],
            'shippingAddress.fullName' => ['required', 'string'],
            'shippingAddress.addressLine1' => ['required', 'string'],
            'shippingAddress.addressLine2' => ['nullable', 'string'],
            'shippingAddress.city' => ['required', 'string'],
            'shippingAddress.postalCode' => ['required', 'string'],
            'shippingAddress.country' => ['required', 'string'],
            'stripePaymentIntentId' => ['nullable', 'string'],
            ...$this->lineItemRules(),
        ]);

        $order = DB::transaction(function () use ($validated, $userId): Order {
            $priced = $this->priceItems($validated['items'], lockStock: true);

            $order = Order::create([
                'user_id' => $userId,
                'total_amount' => $priced['total'],
                'shipping_address' => $validated['shippingAddress'],
                'stripe_payment_intent_id' => $validated['stripePaymentIntentId'] ?? null,
                'status' => OrderStatus::Pending,
            ]);

            foreach ($priced['items'] as $item) {
                $order->items()->create($item);
            }

            $this->reserveStock($this->stockQuantities($priced['items']));

            return $order;
        });

        $order->load(['items.product', 'items.variant', 'items.presetDesign']);

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    /**
     * Price a cart without creating anything, so the caller can set up payment
     * for an amount the server actually agrees with.
     */
    public function quote(Request $request): JsonResponse
    {
        $userId = $request->header('x-user-id');

        if (! $userId || ! User::find($userId)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate($this->lineItemRules());

        $priced = $this->priceItems($validated['items']);

        return response()->json([
            'totalAmount' => $priced['total'],
            'items' => array_map(fn (array $item): array => [
                'productId' => $item['product_id'],
                'variantId' => $item['variant_id'],
                'presetDesignId' => $item['preset_design_id'],
                'quantity' => $item['quantity'],
                'unitPrice' => $item['unit_price'],
            ], $priced['items']),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $userId = $request->header('x-user-id');

        if (! $userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $orders = Order::where('user_id', $userId)
            ->with(['items.product'])
            ->latest()
            ->get();

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $id): OrderResource|JsonResponse
    {
        $userId = $request->header('x-user-id');

        if (! $userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $order = Order::where('id', $id)
            ->where('user_id', $userId)
            ->with(['items.product', 'items.variant', 'items.presetDesign'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new OrderResource($order);
    }

    public function updateStatus(Request $request, string $id): OrderResource|JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
        ]);

        $order = Order::find($id);

        if (! $order) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new OrderResource($this->applyStatus($order, OrderStatus::from($validated['status'])));
    }

    public function updateStatusByPaymentIntent(Request $request, string $intentId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
        ]);

        $order = Order::where('stripe_payment_intent_id', $intentId)->first();

        if (! $order) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->applyStatus($order, OrderStatus::from($validated['status']));

        return response()->json(null, 204);
    }

    /**
     * Cart line rules shared by `store` and `quote`.
     *
     * `unitPrice` and `totalAmount` are deliberately absent: the client may send
     * them, but the server re-derives every price from the product and variant
     * records so a tampered cart cannot set what it pays.
     *
     * @return array<string, array<int, string>>
     */
    private function lineItemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'string', 'exists:products,id'],
            'items.*.variantId' => ['nullable', 'string'],
            'items.*.presetDesignId' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.customizationData' => ['nullable', 'array'],
        ];
    }

    /**
     * Re-derive unit prices from the catalogue and confirm every line is orderable.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, total: float}
     *
     * @throws ValidationException
     */
    private function priceItems(array $items, bool $lockStock = false): array
    {
        $products = Product::whereIn('id', array_column($items, 'productId'))
            ->withCount('variants')
            ->get()
            ->keyBy('id');

        $variantIds = array_values(array_filter(array_column($items, 'variantId')));
        $variants = collect();

        if ($variantIds !== []) {
            $query = ProductVariant::whereIn('id', $variantIds);

            if ($lockStock) {
                $query->lockForUpdate();
            }

            $variants = $query->get()->keyBy('id');
        }

        $presetIds = array_values(array_filter(array_column($items, 'presetDesignId')));
        $presets = $presetIds === []
            ? collect()
            : PresetDesign::whereIn('id', $presetIds)->get()->keyBy('id');

        $priced = [];
        $total = 0.0;
        $claimed = [];

        foreach ($items as $index => $item) {
            $product = $products[$item['productId']];
            $variant = null;

            if (! empty($item['variantId'])) {
                $variant = $variants[$item['variantId']] ?? null;

                if (! $variant || $variant->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        "items.{$index}.variantId" => "The selected option is not available for {$product->name}.",
                    ]);
                }
            } elseif ($product->variants_count > 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.variantId" => "Please choose an option for {$product->name}.",
                ]);
            }

            if (! empty($item['presetDesignId'])) {
                $preset = $presets[$item['presetDesignId']] ?? null;

                if (! $preset || $preset->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        "items.{$index}.presetDesignId" => "The selected design is not available for {$product->name}.",
                    ]);
                }
            }

            $unitPrice = round((float) $product->base_price + (float) ($variant?->price_modifier ?? 0), 2);
            $total += $unitPrice * $item['quantity'];

            if ($variant) {
                $claimed[$variant->id] = ($claimed[$variant->id] ?? 0) + $item['quantity'];

                if ($claimed[$variant->id] > $variant->stock) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Only {$variant->stock} left in stock for {$product->name} — {$variant->label}.",
                    ]);
                }
            }

            $priced[] = [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'preset_design_id' => $item['presetDesignId'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $unitPrice,
                'customization_data' => $item['customizationData'] ?? null,
            ];
        }

        return ['items' => $priced, 'total' => round($total, 2)];
    }

    /**
     * Stock held by an order is released when it is cancelled and re-taken if it
     * is ever revived, so the reserved quantity always matches the live orders.
     *
     * @throws ValidationException
     */
    private function applyStatus(Order $order, OrderStatus $status): Order
    {
        return DB::transaction(function () use ($order, $status): Order {
            if ($order->status === $status) {
                return $order;
            }

            $quantities = $this->stockQuantities(
                $order->items()->get()->map(fn ($item): array => [
                    'variant_id' => $item->variant_id,
                    'quantity' => $item->quantity,
                ])->all()
            );

            if ($status === OrderStatus::Cancelled) {
                $this->releaseStock($quantities);
            } elseif ($order->status === OrderStatus::Cancelled) {
                $this->reserveStock($quantities);
            }

            $order->update(['status' => $status]);

            return $order->fresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function stockQuantities(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            if (! $item['variant_id']) {
                continue;
            }

            $quantities[$item['variant_id']] = ($quantities[$item['variant_id']] ?? 0) + $item['quantity'];
        }

        return $quantities;
    }

    /**
     * @param  array<string, int>  $quantities
     *
     * @throws ValidationException
     */
    private function reserveStock(array $quantities): void
    {
        foreach ($quantities as $variantId => $quantity) {
            $variant = ProductVariant::lockForUpdate()->find($variantId);

            if (! $variant || $variant->stock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => 'This item sold out while the order was being placed.',
                ]);
            }

            $variant->decrement('stock', $quantity);
        }
    }

    /**
     * @param  array<string, int>  $quantities
     */
    private function releaseStock(array $quantities): void
    {
        foreach ($quantities as $variantId => $quantity) {
            ProductVariant::whereKey($variantId)->increment('stock', $quantity);
        }
    }
}
