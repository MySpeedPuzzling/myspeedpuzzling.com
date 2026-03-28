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

    private function createTestImage(int $width, int $height): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_img_') . '.jpg';

        $imagick = new Imagick();
        $imagick->newImage($width, $height, 'white');
        $imagick->setImageFormat('jpeg');
        $imagick->writeImage($path);
        $imagick->clear();
        $imagick->destroy();

        return $path;
    }
}
