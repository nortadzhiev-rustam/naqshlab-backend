<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PresetDesign;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPricingTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return [
            'x-api-key' => 'test-secret-key',
            'x-user-id' => (string) $user->id,
            'x-user-role' => $user->role->value,
        ];
    }

    private function address(): array
    {
        return [
            'fullName' => 'Rustam N',
            'addressLine1' => '12 Rudaki Ave',
            'city' => 'Dushanbe',
            'postalCode' => '734000',
            'country' => 'TJ',
        ];
    }

    public function test_order_total_ignores_client_supplied_prices(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 25.00]);
        $variant = ProductVariant::factory()->for($product)->create([
            'price_modifier' => 5.00,
            'stock' => 10,
        ]);

        $response = $this->postJson('/api/orders', [
            'totalAmount' => 0.02,
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 2,
                'unitPrice' => 0.01,
            ]],
        ], $this->headers($user));

        $response->assertCreated();
        $this->assertSame(60.0, (float) $response->json('totalAmount'));
        $this->assertSame(30.0, (float) $response->json('items.0.unitPrice'));
    }

    public function test_unit_price_combines_base_price_and_variant_modifier(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 19.99]);
        $variant = ProductVariant::factory()->for($product)->create(['price_modifier' => 2.50]);

        $response = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 3,
            ]],
        ], $this->headers($user));

        $response->assertCreated();
        $this->assertSame(22.49, (float) $response->json('items.0.unitPrice'));
        $this->assertSame(67.47, (float) $response->json('totalAmount'));
    }

    public function test_variant_belonging_to_another_product_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $foreignVariant = ProductVariant::factory()->create(['price_modifier' => -1000.00]);

        $response = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'variantId' => $foreignVariant->id,
                'quantity' => 1,
            ]],
        ], $this->headers($user));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.variantId');
        $this->assertSame(0, Order::count());
    }

    public function test_preset_design_belonging_to_another_product_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $foreignDesign = PresetDesign::factory()->create();

        $response = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'presetDesignId' => $foreignDesign->id,
                'quantity' => 1,
            ]],
        ], $this->headers($user));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.presetDesignId');
    }

    public function test_product_with_variants_requires_one_to_be_chosen(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        ProductVariant::factory()->for($product)->create();

        $response = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'quantity' => 1,
            ]],
        ], $this->headers($user));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.variantId');
    }

    public function test_product_without_variants_can_be_ordered_directly(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 12.00]);

        $response = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'quantity' => 2,
            ]],
        ], $this->headers($user));

        $response->assertCreated();
        $this->assertSame(24.0, (float) $response->json('totalAmount'));
    }

    public function test_placing_an_order_decrements_variant_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 10]);

        $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 3,
            ]],
        ], $this->headers($user))->assertCreated();

        $this->assertSame(7, $variant->fresh()->stock);
    }

    public function test_order_is_rejected_and_rolled_back_when_stock_is_short(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 2]);

        $response = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 3,
            ]],
        ], $this->headers($user));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('items.0.quantity');
        $this->assertSame(2, $variant->fresh()->stock);
        $this->assertSame(0, Order::count());
    }

    public function test_repeated_lines_for_one_variant_are_summed_against_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 3]);

        $line = [
            'productId' => $product->id,
            'variantId' => $variant->id,
            'quantity' => 2,
        ];

        $response = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [$line, $line + ['customizationData' => ['text' => 'hi']]],
        ], $this->headers($user));

        $response->assertStatus(422);
        $this->assertSame(3, $variant->fresh()->stock);
    }

    public function test_cancelling_an_order_releases_its_stock(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 10]);

        $orderId = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 4,
            ]],
        ], $this->headers($user))->json('id');

        $this->assertSame(6, $variant->fresh()->stock);

        $this->patchJson("/api/orders/{$orderId}/status", [
            'status' => 'CANCELLED',
        ], $this->headers($admin))->assertOk();

        $this->assertSame(10, $variant->fresh()->stock);
    }

    public function test_reviving_a_cancelled_order_retakes_its_stock(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 5]);

        $orderId = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 2,
            ]],
        ], $this->headers($user))->json('id');

        $this->patchJson("/api/orders/{$orderId}/status", ['status' => 'CANCELLED'], $this->headers($admin));
        $this->assertSame(5, $variant->fresh()->stock);

        $this->patchJson("/api/orders/{$orderId}/status", ['status' => 'PROCESSING'], $this->headers($admin))->assertOk();
        $this->assertSame(3, $variant->fresh()->stock);
    }

    public function test_repeating_a_status_does_not_move_stock_twice(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 8]);

        $orderId = $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 3,
            ]],
        ], $this->headers($user))->json('id');

        $this->patchJson("/api/orders/{$orderId}/status", ['status' => 'CANCELLED'], $this->headers($admin));
        $this->patchJson("/api/orders/{$orderId}/status", ['status' => 'CANCELLED'], $this->headers($admin));

        $this->assertSame(8, $variant->fresh()->stock);
    }

    public function test_failed_payment_webhook_releases_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 6]);

        $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'stripePaymentIntentId' => 'pi_test_123',
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 2,
            ]],
        ], $this->headers($user))->assertCreated();

        $this->assertSame(4, $variant->fresh()->stock);

        $this->patchJson('/api/orders/by-payment-intent/pi_test_123', [
            'status' => 'CANCELLED',
        ], ['x-api-key' => 'test-secret-key'])->assertNoContent();

        $this->assertSame(6, $variant->fresh()->stock);
        $this->assertSame(OrderStatus::Cancelled, Order::first()->status);
    }

    public function test_quote_prices_a_cart_without_creating_or_reserving_anything(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['base_price' => 15.00]);
        $variant = ProductVariant::factory()->for($product)->create([
            'price_modifier' => 3.00,
            'stock' => 4,
        ]);

        $response = $this->postJson('/api/orders/quote', [
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 2,
                'unitPrice' => 0.01,
            ]],
        ], $this->headers($user));

        $response->assertOk();
        $this->assertSame(36.0, (float) $response->json('totalAmount'));
        $this->assertSame(18.0, (float) $response->json('items.0.unitPrice'));

        $this->assertSame(0, Order::count());
        $this->assertSame(4, $variant->fresh()->stock);
    }

    public function test_quote_reports_a_cart_that_cannot_be_fulfilled(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 1]);

        $this->postJson('/api/orders/quote', [
            'items' => [[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'quantity' => 5,
            ]],
        ], $this->headers($user))->assertStatus(422);
    }

    public function test_orders_still_require_a_known_user(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/orders', [
            'shippingAddress' => $this->address(),
            'items' => [['productId' => $product->id, 'quantity' => 1]],
        ], ['x-api-key' => 'test-secret-key'])->assertStatus(401);
    }
}
