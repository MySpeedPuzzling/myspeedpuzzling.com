<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Twig\LazyImageTwigExtension;
use PHPUnit\Framework\TestCase;

final class LazyImageTwigExtensionTest extends TestCase
{
    private LazyImageTwigExtension $extension;

    protected function setUp(): void
    {
        $imageThumbnail = new ImageThumbnailTwigExtension('https://imgproxy.test');
        $this->extension = new LazyImageTwigExtension($imageThumbnail);
    }

    public function testSquareImageWithRatio(): void
    {
        $html = $this->extension->lazyPuzzleImage('/img.jpg', 'puzzle_small', 'alt', 999, 80, '', null, 1.0);

        self::assertStringContainsString('width="80"', $html);
        self::assertStringContainsString('height="80"', $html);
    }

    public function testPortraitImageWithRatio(): void
    {
        // 135x200 image → ratio 0.675
        $html = $this->extension->lazyPuzzleImage('/img.jpg', 'puzzle_small', 'alt', 999, 90, '', 60, 0.675);

        // Portrait: height=60, width=60*0.675=40.5 → 41
        self::assertStringContainsString('width="41"', $html);
        self::assertStringContainsString('height="60"', $html);
    }

    public function testLandscapeImageWithRatio(): void
    {
        // 200x135 image → ratio ~1.481
        $html = $this->extension->lazyPuzzleImage('/img.jpg', 'puzzle_small', 'alt', 999, 90, '', 60, 1.481);

        // Landscape: width=90, height=90/1.481=60.8 → capped at maxHeight=60
        // So: height=60, width=60*1.481=88.9 → 89
        self::assertStringContainsString('width="89"', $html);
        self::assertStringContainsString('height="60"', $html);
    }

    public function testNullRatioFallsBackToContainerDimensions(): void
    {
        $html = $this->extension->lazyPuzzleImage('/img.jpg', 'puzzle_small', 'alt', 999, 90, '', 60, null);

        // Falls back to size x maxHeight
        self::assertStringContainsString('width="90"', $html);
        self::assertStringContainsString('height="60"', $html);
    }

    public function testZeroRatioTreatedAsNull(): void
    {
        $html = $this->extension->lazyPuzzleImage('/img.jpg', 'puzzle_small', 'alt', 999, 90, '', 60, 0.0);

        // Zero ratio is invalid, falls back to container dimensions
        self::assertStringContainsString('width="90"', $html);
        self::assertStringContainsString('height="60"', $html);
    }

    public function testNegativeRatioTreatedAsNull(): void
    {
        $html = $this->extension->lazyPuzzleImage('/img.jpg', 'puzzle_small', 'alt', 999, 80, '', null, -1.0);

        self::assertStringContainsString('width="80"', $html);
        self::assertStringContainsString('height="80"', $html);
    }

    public function testNullPathReturnsPlaceholder(): void
    {
        $html = $this->extension->lazyPuzzleImage(null, 'puzzle_small', 'alt', 999, 80, '', null, 0.675);

        self::assertStringContainsString('/img/placeholder-puzzle.jpg', $html);
    }

    public function testEagerLoadingForFirstFourPositions(): void
    {
        $html = $this->extension->lazyPuzzleImage('/img.jpg', 'puzzle_small', 'alt', 1, 80, '', null, 1.0);

        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('lazy-img loaded', $html);
    }

    public function testLazyLoadingForPositionBeyondFour(): void
    {
        $html = $this->extension->lazyPuzzleImage('/img.jpg', 'puzzle_small', 'alt', 5, 80, '', null, 1.0);

        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('onload=', $html);
    }
}
