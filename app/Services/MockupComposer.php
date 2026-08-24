<?php

namespace App\Services;

use App\Models\MockupTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use RuntimeException;

/**
 * Composites a customer's artwork onto a product photo.
 *
 * Nothing here is generative: every pixel of the design that comes out is a
 * pixel the customer put in, geometrically transformed. That is deliberate --
 * the mockup is a promise about what will be printed, so the artwork is warped
 * and lit but never redrawn.
 *
 * The passes, in order:
 *   1. fit the design inside the template's print quad, preserving its aspect
 *   2. perspective-warp it onto the quad, so it sits in the garment's plane
 *   3. displace it by the garment's own luminance, so it rides the folds
 *   4. soft-light the garment's shading over it, so it picks up the lighting
 *   5. clip to the garment mask and composite onto the photo
 */
class MockupComposer
{
    /**
     * Bumped whenever a pass changes in a way that alters output. Feeds the
     * cache key, so old renders are never served for new code.
     */
    public const PIPELINE_VERSION = 1;

    public function __construct(private readonly string $disk = 'public') {}

    public static function isSupported(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * @return array{contents: string, width: int, height: int}
     */
    public function render(string $designContents, MockupTemplate $template): array
    {
        $this->guardSupport();

        $base = $this->open($this->path($template->base_path));
        $width = $base->getImageWidth();
        $height = $base->getImageHeight();

        $print = $this->placeDesign($designContents, $template, $width, $height);
        $print = $this->rideTheFolds($print, $template, $base);
        $print = $this->applyGarmentLight($print, $template, $base);
        $print = $this->clipToGarment($print, $template);

        $base->compositeImage($print, Imagick::COMPOSITE_OVER, 0, 0);
        $base->setImageFormat('webp');
        $base->setImageCompressionQuality(92);

        $contents = $base->getImageBlob();

        $base->clear();
        $print->clear();

        return ['contents' => $contents, 'width' => $width, 'height' => $height];
    }

    /**
     * Fit the artwork inside the quad's nominal size, then perspective-warp its
     * corners onto the quad. Fitting first (rather than stretching to the quad)
     * keeps the artwork's aspect ratio -- a logo should be bent by the garment,
     * not squashed by the template.
     */
    private function placeDesign(
        string $designContents,
        MockupTemplate $template,
        int $canvasWidth,
        int $canvasHeight
    ): Imagick {
        $quad = $template->quad();
        [$nominalWidth, $nominalHeight] = $this->nominalSize($quad);

        $design = new Imagick;
        $design->readImageBlob($designContents);
        $design->setImageFormat('png');
        $design->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

        // `bestfit` on thumbnailImage preserves aspect; the transparent extent
        // then centres it in the quad's nominal box.
        $design->thumbnailImage($nominalWidth, $nominalHeight, true);
        $design->setImageBackgroundColor(new ImagickPixel('transparent'));
        $design->setGravity(Imagick::GRAVITY_CENTER);
        $design->extentImage(
            $nominalWidth,
            $nominalHeight,
            (int) round(($design->getImageWidth() - $nominalWidth) / 2),
            (int) round(($design->getImageHeight() - $nominalHeight) / 2)
        );

        $design->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
        $design->distortImage(Imagick::DISTORTION_PERSPECTIVE, [
            0, 0, $quad[0][0], $quad[0][1],
            $nominalWidth, 0, $quad[1][0], $quad[1][1],
            $nominalWidth, $nominalHeight, $quad[2][0], $quad[2][1],
            0, $nominalHeight, $quad[3][0], $quad[3][1],
        ], true);

        // distortImage reframes the canvas around the result; put it back onto a
        // full-size one at the offset it reports.
        $page = $design->getImagePage();
        $placed = new Imagick;
        $placed->newImage($canvasWidth, $canvasHeight, new ImagickPixel('transparent'));
        $placed->setImageFormat('png');
        $placed->compositeImage($design, Imagick::COMPOSITE_OVER, $page['x'], $page['y']);
        $design->clear();

        return $placed;
    }

    private function rideTheFolds(Imagick $print, MockupTemplate $template, Imagick $base): Imagick
    {
        if ($template->displacement_scale <= 0) {
            return $print;
        }

        $map = $this->luminance($base);
        $map->blurImage(5, 2);

        $scale = $template->displacement_scale;
        $print->setImageArtifact('compose:args', "{$scale}x{$scale}");
        $print->compositeImage($map, Imagick::COMPOSITE_DISPLACE, 0, 0);
        $map->clear();

        return $print;
    }

    /**
     * Soft light rather than multiply: multiply darkens the whole print and
     * visibly shifts its hue, which would misrepresent the colour the customer
     * is going to receive. Soft light adds the garment's light and shade while
     * leaving the artwork's own colour intact.
     */
    private function applyGarmentLight(Imagick $print, MockupTemplate $template, Imagick $base): Imagick
    {
        if ($template->shading_strength <= 0) {
            return $print;
        }

        $shading = $this->luminance($base);

        if ($template->shading_strength < 100) {
            // Pull the map toward mid-grey, which is soft light's neutral point.
            $neutral = new Imagick;
            $neutral->newImage(
                $base->getImageWidth(),
                $base->getImageHeight(),
                new ImagickPixel('gray50')
            );
            $shading->setImageArtifact('compose:args', (string) (100 - $template->shading_strength));
            $shading->compositeImage($neutral, Imagick::COMPOSITE_BLEND, 0, 0);
            $neutral->clear();
        }

        // Soft light discards the print's alpha, so keep a copy and put it back.
        $alpha = clone $print;
        $alpha->setImageAlphaChannel(Imagick::ALPHACHANNEL_EXTRACT);

        $print->compositeImage($shading, Imagick::COMPOSITE_SOFTLIGHT, 0, 0);
        $print->compositeImage($alpha, Imagick::COMPOSITE_COPYOPACITY, 0, 0);

        $shading->clear();
        $alpha->clear();

        return $print;
    }

    /**
     * The mask arrives as a black-and-white image, not an image with an alpha
     * channel, so it cannot clip by itself -- it is opaque everywhere. Multiply
     * it into the print's own alpha instead, which both clips to the garment and
     * keeps the print transparent outside its quad.
     */
    private function clipToGarment(Imagick $print, MockupTemplate $template): Imagick
    {
        if (! $template->mask_path) {
            return $print;
        }

        $mask = $this->open($this->path($template->mask_path));

        $alpha = clone $print;
        $alpha->setImageAlphaChannel(Imagick::ALPHACHANNEL_EXTRACT);
        $alpha->compositeImage($mask, Imagick::COMPOSITE_MULTIPLY, 0, 0);

        $print->compositeImage($alpha, Imagick::COMPOSITE_COPYOPACITY, 0, 0);

        $mask->clear();
        $alpha->clear();

        return $print;
    }

    private function luminance(Imagick $base): Imagick
    {
        $map = clone $base;
        $map->modulateImage(100, 0, 100);
        $map->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);

        return $map;
    }

    /**
     * The quad's nominal size: the longest opposing edges, so neither dimension
     * of the artwork is lost to foreshortening.
     *
     * @param  array<int, array{0: float, 1: float}>  $quad
     * @return array{0: int, 1: int}
     */
    private function nominalSize(array $quad): array
    {
        $edge = fn (array $a, array $b): float => sqrt(
            ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2
        );

        $width = (int) round(max($edge($quad[0], $quad[1]), $edge($quad[3], $quad[2])));
        $height = (int) round(max($edge($quad[0], $quad[3]), $edge($quad[1], $quad[2])));

        return [max(1, $width), max(1, $height)];
    }

    private function open(string $absolutePath): Imagick
    {
        if (! is_file($absolutePath)) {
            Log::error('Mockup template asset missing.', ['path' => $absolutePath]);
            throw new RuntimeException("Mockup template asset missing: {$relative}");
        }

        $image = new Imagick($absolutePath);
        $image->setImageFormat('png');

        return $image;
    }

    private function path(string $relative): string
    {
        return Storage::disk($this->disk)->path($relative);
    }

    private function guardSupport(): void
    {
        if (! self::isSupported()) {
            throw new RuntimeException(
                'The imagick PHP extension is required to render mockups. '
                .'Install ImageMagick and the imagick extension on this host.'
            );
        }
    }
}
