<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminController extends Controller
{
    public function stats(): JsonResponse
    {
        $totalOrders = Order::count();
        $pendingOrders = Order::where('status', OrderStatus::Pending)->count();
        $totalRevenue = Order::where('status', '!=', OrderStatus::Cancelled)->sum('total_amount');
        $totalProducts = Product::count();

        return response()->json([
            'totalOrders' => $totalOrders,
            'pendingOrders' => $pendingOrders,
            'totalRevenue' => (float) $totalRevenue,
            'totalProducts' => $totalProducts,
        ]);
    }

    public function orders(Request $request): AnonymousResourceCollection
    {
        $query = Order::with(['user', 'items'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return OrderResource::collection($query->get());
    }

    public function showOrder(string $id): OrderResource|JsonResponse
    {
        $order = Order::with(['user', 'items.product', 'items.variant', 'items.presetDesign'])
            ->find($id);

        if (! $order) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new OrderResource($order);
    }
}
