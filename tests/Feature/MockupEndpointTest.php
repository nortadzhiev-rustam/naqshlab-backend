<?php

namespace Tests\Feature;

use App\Enums\MockupStatus;
use App\Jobs\GenerateMockup;
use App\Models\Mockup;
use App\Models\MockupTemplate;
use App\Models\Product;
use App\Services\MockupComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MockupFixtures;
use Tests\TestCase;

class MockupEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! MockupComposer::isSupported()) {
            $this->markTestSkipped('The imagick extension is not installed.');
        }

        MockupFixtures::writeTemplateAssets();
    }

    private function headers(): array
    {
        return ['x-api-key' => config('app.api_secret_key')];
    }

    private function design(): string
    {
        return 'data:image/png;base64,'.base64_encode(MockupFixtures::design());
    }

    public function test_it_queues_a_mockup_for_each_matching_template(): void
    {
        Queue::fake();

        $product = Product::factory()->create();
        MockupTemplate::factory()->count(2)->create(['product_id' => $product->id]);

        $response = $this->postJson('/api/mockups', [
            'design' => $this->design(),
            'productId' => $product->id,
        ], $this->headers());

        $response->assertOk()->assertJsonCount(2);
        $response->assertJsonPath('0.status', 'PENDING');

        Queue::assertPushed(GenerateMockup::class, 2);
        $this->assertSame(2, Mockup::count());
    }

    public function test_identical_artwork_is_not_rendered_twice(): void
    {
        Queue::fake();

        $product = Product::factory()->create();
        MockupTemplate::factory()->create(['product_id' => $product->id]);

        $payload = ['design' => $this->design(), 'productId' => $product->id];

        $this->postJson('/api/mockups', $payload, $this->headers())->assertOk();
        $this->postJson('/api/mockups', $payload, $this->headers())->assertOk();

        Queue::assertPushed(GenerateMockup::class, 1);
        $this->assertSame(1, Mockup::count());
    }

    public function test_it_renders_end_to_end_and_exposes_a_url(): void
    {
        $product = Product::factory()->create();
        MockupTemplate::factory()->create(['product_id' => $product->id]);

        $response = $this->postJson('/api/mockups', [
            'design' => $this->design(),
            'productId' => $product->id,
        ], $this->headers());

        $response->assertOk()->assertJsonPath('0.status', 'READY');

        $mockup = Mockup::sole();
        $this->assertSame(MockupStatus::Ready, $mockup->status);
        $this->assertSame(900, $mockup->width);
        Storage::disk('public')->assertExists($mockup->path);

        $this->getJson("/api/mockups/{$mockup->cache_key}", $this->headers())
            ->assertOk()
            ->assertJsonPath('status', 'READY');
    }

    public function test_it_falls_back_to_category_templates(): void
    {
        Queue::fake();

        MockupTemplate::factory()->create(['product_id' => null, 'category' => 'MUG']);

        $this->postJson('/api/mockups', [
            'design' => $this->design(),
            'category' => 'MUG',
        ], $this->headers())->assertOk()->assertJsonCount(1);
    }

    public function test_it_reports_when_no_template_matches(): void
    {
        $this->postJson('/api/mockups', [
            'design' => $this->design(),
            'category' => 'POSTER',
        ], $this->headers())->assertNotFound();
    }

    public function test_it_rejects_a_design_that_is_not_an_image(): void
    {
        MockupTemplate::factory()->create(['product_id' => null, 'category' => 'MUG']);

        $this->postJson('/api/mockups', [
            'design' => base64_encode('this is not an image'),
            'category' => 'MUG',
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors('design');
    }

    public function test_mockups_still_require_the_api_key(): void
    {
        $this->postJson('/api/mockups', ['design' => $this->design()])->assertUnauthorized();
    }
}
