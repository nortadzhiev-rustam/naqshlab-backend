<?php

namespace Tests\Feature;

use App\Models\MockupTemplate;
use App\Models\Product;
use App\Models\User;
use App\Services\MockupComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\MockupFixtures;
use Tests\TestCase;

class AdminMockupTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $admin = User::factory()->admin()->create();

        return [
            'x-api-key' => config('app.api_secret_key'),
            'x-user-id' => (string) $admin->id,
            'x-user-role' => 'admin',
        ];
    }

    private function customerHeaders(): array
    {
        $user = User::factory()->create();

        return [
            'x-api-key' => config('app.api_secret_key'),
            'x-user-id' => (string) $user->id,
            'x-user-role' => 'customer',
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Front chest',
            'basePath' => 'mockup-templates/base.png',
            'maskPath' => 'mockup-templates/mask.png',
            'category' => 'APPAREL',
            'printArea' => ['quad' => [[300, 250], [640, 285], [615, 620], [285, 585]]],
            'displacementScale' => 12,
            'shadingStrength' => 70,
        ], $overrides);
    }

    public function test_an_admin_can_create_a_template(): void
    {
        $response = $this->postJson('/api/admin/mockup-templates', $this->payload(), $this->adminHeaders());

        $response->assertCreated()
            ->assertJsonPath('name', 'Front chest')
            ->assertJsonPath('printArea.quad.0.0', 300)
            ->assertJsonPath('displacementScale', 12);

        // Column defaults must survive into the response, not come back null.
        $response->assertJsonPath('isActive', true)->assertJsonPath('sortOrder', 0);

        $this->assertSame(1, MockupTemplate::count());
    }

    public function test_a_customer_cannot_manage_templates(): void
    {
        $this->postJson('/api/admin/mockup-templates', $this->payload(), $this->customerHeaders())
            ->assertForbidden();

        $this->getJson('/api/admin/mockup-templates', $this->customerHeaders())
            ->assertForbidden();
    }

    public function test_the_quad_must_have_four_corners(): void
    {
        $this->postJson('/api/admin/mockup-templates', $this->payload([
            'printArea' => ['quad' => [[0, 0], [10, 0], [10, 10]]],
        ]), $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('printArea.quad');
    }

    public function test_a_template_can_be_scoped_to_a_product(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/admin/mockup-templates', $this->payload([
            'productId' => $product->id,
        ]), $this->adminHeaders())->assertCreated();

        $this->getJson("/api/admin/mockup-templates?productId={$product->id}", $this->adminHeaders())
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_an_admin_can_update_and_delete_a_template(): void
    {
        $template = MockupTemplate::factory()->create();
        $headers = $this->adminHeaders();

        $this->putJson("/api/admin/mockup-templates/{$template->id}", [
            'shadingStrength' => 25,
        ], $headers)->assertOk()->assertJsonPath('shadingStrength', 25);

        $this->deleteJson("/api/admin/mockup-templates/{$template->id}", [], $headers)
            ->assertNoContent();

        $this->assertSame(0, MockupTemplate::count());
    }

    public function test_uploading_a_base_photo_reports_its_pixel_size(): void
    {
        $response = $this->post('/api/admin/mockup-templates/upload', [
            'image' => UploadedFile::fake()->image('shirt.png', 1200, 900),
            'kind' => 'base',
        ], $this->adminHeaders());

        $response->assertCreated()
            ->assertJsonPath('width', 1200)
            ->assertJsonPath('height', 900);
    }

    public function test_preview_renders_an_unsaved_template(): void
    {
        if (! MockupComposer::isSupported()) {
            $this->markTestSkipped('The imagick extension is not installed.');
        }

        MockupFixtures::writeTemplateAssets();

        $response = $this->postJson('/api/admin/mockup-templates/preview', $this->payload([
            'design' => 'data:image/png;base64,'.base64_encode(MockupFixtures::design()),
        ]), $this->adminHeaders());

        $response->assertOk()->assertJsonPath('width', 900);
        $this->assertStringStartsWith('data:image/webp;base64,', $response->json('dataUrl'));

        // Previewing must not persist anything.
        $this->assertSame(0, MockupTemplate::count());
    }

    public function test_preview_reports_a_missing_base_photo(): void
    {
        if (! MockupComposer::isSupported()) {
            $this->markTestSkipped('The imagick extension is not installed.');
        }

        $this->postJson('/api/admin/mockup-templates/preview', $this->payload([
            'design' => 'data:image/png;base64,'.base64_encode(MockupFixtures::design()),
            'basePath' => 'mockup-templates/absent.png',
        ]), $this->adminHeaders())->assertStatus(422);
    }
}
