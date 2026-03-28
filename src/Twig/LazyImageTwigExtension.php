<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class LazyImageTwigExtension extends AbstractExtension
{
    private const string PLACEHOLDER_IMAGE = '/img/placeholder-puzzle.jpg';

    public function __construct(
        readonly private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('lazy_puzzle_image', $this->lazyPuzzleImage(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Generates a lazy-loaded puzzle image with wrapper and proper attributes.
     *
     * @param string|null $path Image path in S3
     * @param string $filter Imgproxy preset (puzzle_small, puzzle_medium)
     * @param string $alt Alt text for the image
     * @param int $position Position in list (1-based). First 4 positions are eager-loaded.
     * @param int $size Display size in pixels (60, 80, or 90)
     * @param string $class Additional CSS classes for the wrapper
     */
    public function lazyPuzzleImage(
        null|string $path,
        string $filter,
        null|string $alt,
        int $position = 999,
        int $size = 80,
        string $class = '',
        null|int $maxHeight = null,
        null|float $imageRatio = null,
    ): string {
        $src = $this->getImageSrc($path, $filter);
        $sizeClass = $this->getSizeClass($size);
        $maxHeight ??= $size;

        // Calculate accurate width/height from aspect ratio
        [$imgWidth, $imgHeight] = $this->calculateDimensions($size, $maxHeight, $imageRatio);

        // First 4 images are above-the-fold (eager loading)
        $isEager = $position <= 4;
        $loading = $isEager ? 'eager' : 'lazy';

        // Build wrapper classes
        $wrapperClasses = trim(sprintf('lazy-img-wrapper %s %s', $sizeClass, $class));

        // Build img classes and onload handler
        // Use both onload (for fresh loads) and inline complete check (for cached images after Turbo morph)
        if ($isEager) {
            $imgClasses = 'lazy-img loaded';
            $extraAttrs = '';
        } else {
            $imgClasses = 'lazy-img';
            $extraAttrs = ' onload="this.classList.add(\'loaded\')"';
        }

        return sprintf(
            '<span class="%s" style="width:%dpx;height:%dpx"><picture><img src="%s" alt="%s" loading="%s" class="%s" width="%d" height="%d" style="max-height:%dpx"%s></picture></span>',
            htmlspecialchars($wrapperClasses, ENT_QUOTES, 'UTF-8'),
            $size,
            $maxHeight,
            htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($alt ?? '', ENT_QUOTES, 'UTF-8'),
            $loading,
            $imgClasses,
            $imgWidth,
            $imgHeight,
            $maxHeight,
            $extraAttrs,
        );
    }

    /**
     * Calculate accurate width and height from aspect ratio.
     *
     * @return array{int, int} [width, height]
     */
    private function calculateDimensions(int $size, int $maxHeight, null|float $ratio): array
    {
        // No ratio available - fall back to container dimensions (square assumption)
        if ($ratio === null || $ratio <= 0) {
            return [$size, $maxHeight];
        }

        if ($ratio >= 1) {
            // Landscape or square: width is constrained by $size
            $width = $size;
            $height = (int) round($size / $ratio);

            if ($height > $maxHeight) {
                $height = $maxHeight;
                $width = (int) round($maxHeight * $ratio);
            }
        } else {
            // Portrait: height is constrained by $maxHeight
            $height = $maxHeight;
            $width = (int) round($maxHeight * $ratio);

            if ($width > $size) {
                $width = $size;
                $height = (int) round($size / $ratio);
            }
        }

        return [$width, $height];
    }

    private function getImageSrc(null|string $path, string $filter): string
    {
        if ($path === null) {
            return self::PLACEHOLDER_IMAGE;
        }

        return $this->imageThumbnail->thumbnailUrl($path, $filter);
    }

    private function getSizeClass(int $size): string
    {
        return match (true) {
            $size <= 40 => 'lazy-img-40',
            $size <= 50 => 'lazy-img-50',
            $size <= 60 => 'lazy-img-60',
            $size <= 80 => 'lazy-img-80',
            $size <= 90 => 'lazy-img-90',
            default => 'lazy-img-100',
        };
    }
}
