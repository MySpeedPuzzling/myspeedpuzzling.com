<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Imagick;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Services\ImageOptimizer;

final class ImageOptimizerTest extends TestCase
{
    private ImageOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->optimizer = new ImageOptimizer(new NullLogger());
    }

    public function testGetImageRatioForSquareImage(): void
    {
        $path = $this->createTestImage(100, 100);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(1.0, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioForPortraitImage(): void
    {
        $path = $this->createTestImage(135, 200);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(0.675, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioForLandscapeImage(): void
    {
        $path = $this->createTestImage(200, 135);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(1.481, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioSwapsDimensionsForRotatedExifOrientation(): void
    {
        // 200x100 stored pixels with EXIF orientation 6 (rotate 90° CW) render as 100x200
        $path = $this->createTestImage(200, 100, exifOrientation: 6);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(0.5, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioKeepsDimensionsForUprightExifOrientation(): void
    {
        // Orientation 3 (rotate 180°) does not swap width/height
        $path = $this->createTestImage(200, 100, exifOrientation: 3);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(2.0, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioFromBlob(): void
    {
        $path = $this->createTestImage(135, 200);

        try {
            $content = file_get_contents($path);
            assert(is_string($content));

            $ratio = $this->optimizer->getImageRatioFromBlob($content);
            self::assertEqualsWithDelta(0.675, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioFromBlobSwapsDimensionsForRotatedExifOrientation(): void
    {
        $path = $this->createTestImage(200, 100, exifOrientation: 8);

        try {
            $content = file_get_contents($path);
            assert(is_string($content));

            $ratio = $this->optimizer->getImageRatioFromBlob($content);
            self::assertEqualsWithDelta(0.5, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    private function createTestImage(int $width, int $height, null|int $exifOrientation = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_img_') . '.jpg';

        $imagick = new Imagick();
        $imagick->newImage($width, $height, 'white');
        $imagick->setImageFormat('jpeg');
        $data = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        // Imagick cannot write an EXIF profile into a fresh image,
        // so splice a minimal APP1 segment with the orientation tag manually
        if ($exifOrientation !== null) {
            $exif = "Exif\0\0" . "II*\0" . pack('V', 8)
                . pack('v', 1)
                . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $exifOrientation) . pack('v', 0)
                . pack('V', 0);
            $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
            $data = substr($data, 0, 2) . $app1 . substr($data, 2);
        }

        file_put_contents($path, $data);

        return $path;
    }
}
