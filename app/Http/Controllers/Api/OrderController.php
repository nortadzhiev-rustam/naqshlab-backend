<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function store(Request $request): OrderResource|JsonResponse
    {
        $userId = $request->header('x-user-id');

        if (! $userId || ! User::find($userId)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'totalAmount' => ['required', 'numeric', 'min:0'],
            'shippingAddress' => ['required', 'array'],
            'shippingAddress.fullName' => ['required', 'string'],
            'shippingAddress.addressLine1' => ['required', 'string'],
            'shippingAddress.addressLine2' => ['nullable', 'string'],
            'shippingAddress.city' => ['required', 'string'],
            'shippingAddress.postalCode' => ['required', 'string'],
            'shippingAddress.country' => ['required', 'string'],
            'stripePaymentIntentId' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'string', 'exists:products,id'],
            'items.*.variantId' => ['nullable', 'string', 'exists:product_variants,id'],
            'items.*.presetDesignId' => ['nullable', 'string', 'exists:preset_designs,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unitPrice' => ['required', 'numeric', 'min:0'],
            'items.*.customizationData' => ['nullable', 'array'],
        ]);

        $order = Order::create([
            'user_id' => $userId,
            'total_amount' => $validated['totalAmount'],
            'shipping_address' => $validated['shippingAddress'],
            'stripe_payment_intent_id' => $validated['stripePaymentIntentId'] ?? null,
            'status' => OrderStatus::Pending,
        ]);

        foreach ($validated['items'] as $item) {
            $order->items()->create([
                'product_id' => $item['productId'],
                'variant_id' => $item['variantId'] ?? null,
                'preset_design_id' => $item['presetDesignId'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unitPrice'],
                'customization_data' => $item['customizationData'] ?? null,
            ]);
        }

        $order->load(['items.product', 'items.variant', 'items.presetDesign']);

        return (new OrderResource($order))->response()->setStatusCode(201);
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

        $order->update(['status' => $validated['status']]);

        return new OrderResource($order->fresh());
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

        $order->update(['status' => $validated['status']]);

        return response()->json(null, 204);
    }
}
