<?php

namespace Tests\Feature;

use App\Models\MockupTemplate;
use App\Services\MockupComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use Tests\Support\MockupFixtures;
use Tests\TestCase;

class MockupComposerTest extends TestCase
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

    private function template(array $overrides = []): MockupTemplate
    {
        return MockupTemplate::factory()->create($overrides);
    }

    public function test_it_renders_a_mockup_at_the_base_image_size(): void
    {
        $result = (new MockupComposer)->render(MockupFixtures::design(), $this->template());

        $this->assertSame(900, $result['width']);
        $this->assertSame(900, $result['height']);

        $image = new Imagick;
        $image->readImageBlob($result['contents']);
        $this->assertSame('WEBP', strtoupper($image->getImageFormat()));
        $this->assertSame(900, $image->getImageWidth());
    }

    public function test_the_design_lands_inside_the_print_quad(): void
    {
        $result = (new MockupComposer)->render(MockupFixtures::design(), $this->template());

        $image = new Imagick;
        $image->readImageBlob($result['contents']);

        // The quad's centre should carry the design's magenta; a point well
        // outside it should still be bare fabric.
        $this->assertTrue($this->hasMagentaNear($image, 460, 430), 'expected the print inside the quad');
        $this->assertFalse($this->hasMagentaNear($image, 780, 200), 'expected bare fabric outside the quad');
    }

    public function test_rendering_is_deterministic_so_results_can_be_cached(): void
    {
        $composer = new MockupComposer;
        $template = $this->template();
        $design = MockupFixtures::design();

        $this->assertSame(
            hash('sha256', $composer->render($design, $template)['contents']),
            hash('sha256', $composer->render($design, $template)['contents']),
        );
    }

    public function test_the_mask_keeps_the_print_off_the_background(): void
    {
        // A quad that runs off the garment's right edge; the mask should clip it.
        $template = $this->template([
            'print_area' => ['quad' => [[600, 250], [980, 250], [980, 600], [600, 600]]],
        ]);

        $image = new Imagick;
        $image->readImageBlob((new MockupComposer)->render(MockupFixtures::design(), $template)['contents']);

        $this->assertFalse(
            $this->hasMagentaNear($image, 860, 400),
            'the print should be clipped where the garment mask ends'
        );
    }

    public function test_the_shading_pass_can_be_turned_off(): void
    {
        $composer = new MockupComposer;
        $design = MockupFixtures::design();

        $lit = $composer->render($design, $this->template(['shading_strength' => 100]))['contents'];
        $flat = $composer->render($design, $this->template(['shading_strength' => 0]))['contents'];

        $this->assertNotSame(hash('sha256', $lit), hash('sha256', $flat));
    }

    public function test_a_missing_template_asset_is_reported_clearly(): void
    {
        $this->expectExceptionMessageMatches('/template asset missing/i');

        (new MockupComposer)->render(
            MockupFixtures::design(),
            $this->template(['base_path' => 'mockup-templates/nope.png'])
        );
    }

    private function hasMagentaNear(Imagick $image, int $x, int $y, int $radius = 26): bool
    {
        $region = clone $image;
        $region->cropImage($radius * 2, $radius * 2, $x - $radius, $y - $radius);

        $iterator = $region->getPixelIterator();
        foreach ($iterator as $row) {
            foreach ($row as $pixel) {
                /** @var ImagickPixel $pixel */
                $c = $pixel->getColor();
                if ($c['r'] > 130 && $c['g'] < 110 && $c['b'] > 60 && $c['b'] < 170) {
                    return true;
                }
            }
        }

        return false;
    }
}
