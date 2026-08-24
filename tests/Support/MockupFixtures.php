<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Builds stand-in template assets so the compositing tests do not depend on
 * binary fixtures being checked into the repo.
 */
class MockupFixtures
{
    public static function writeTemplateAssets(string $disk = 'public', int $size = 900): void
    {
        $base = new Imagick;
        $base->newImage($size, $size, new ImagickPixel('#8fa8c8'));

        // Fabric folds, so there is real luminance variation to displace against.
        $folds = new Imagick;
        $folds->newImage($size, $size, new ImagickPixel('gray50'));
        $draw = new ImagickDraw;
        for ($i = 0; $i < 14; $i++) {
            $shade = (int) (128 + 90 * sin($i * 1.7));
            $draw->setStrokeColor(new ImagickPixel("rgb({$shade},{$shade},{$shade})"));
            $draw->setStrokeWidth(26);
            $draw->setFillOpacity(0);
            $points = [];
            for ($y = -20; $y <= $size + 20; $y += 30) {
                $points[] = ['x' => 60 + $i * 62 + 26 * sin($y / 110.0 + $i), 'y' => $y];
            }
            $draw->polyline($points);
        }
        $folds->drawImage($draw);
        $folds->blurImage(18, 8);
        $base->compositeImage($folds, Imagick::COMPOSITE_OVERLAY, 0, 0);
        $base->setImageFormat('png');

        $mask = new Imagick;
        $mask->newImage($size, $size, new ImagickPixel('black'));
        $maskDraw = new ImagickDraw;
        $maskDraw->setFillColor(new ImagickPixel('white'));
        $maskDraw->roundRectangle(170, 120, 730, 800, 90, 120);
        $mask->drawImage($maskDraw);
        $mask->blurImage(6, 3);
        $mask->setImageFormat('png');

        Storage::disk($disk)->put('mockup-templates/base.png', $base->getImageBlob());
        Storage::disk($disk)->put('mockup-templates/mask.png', $mask->getImageBlob());
    }

    public static function design(int $size = 420): string
    {
        $design = new Imagick;
        $design->newImage($size, $size, new ImagickPixel('transparent'));

        $draw = new ImagickDraw;
        $draw->setStrokeColor(new ImagickPixel('#d81e5b'));
        $draw->setStrokeWidth(5);
        $draw->setFillOpacity(0);
        for ($i = 0; $i <= 6; $i++) {
            $p = $i * ($size / 6);
            $draw->line($p, 0, $p, $size);
            $draw->line(0, $p, $size, $p);
        }
        $design->drawImage($draw);
        $design->setImageFormat('png');

        return $design->getImageBlob();
    }
}
