<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Imagick;
use ImagickException;
use Psr\Log\LoggerInterface;

final readonly class ImageOptimizer
{
    private const int MAX_DIMENSION = 2000;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function getImageRatio(string $filePath): float
    {
        $imagick = new Imagick();

        try {
            // Ping reads only metadata (dimensions, EXIF) without decoding pixel data
            $imagick->pingImage($filePath);

            return $this->calculateRatio($imagick);
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    public function getImageRatioFromBlob(string $imageContent): float
    {
        $imagick = new Imagick();

        try {
            $imagick->pingImageBlob($imageContent);

            return $this->calculateRatio($imagick);
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    private function calculateRatio(Imagick $imagick): float
    {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        // EXIF orientations 5-8 display the image rotated by 90°/270°,
        // so the rendered width/height are swapped compared to stored pixels
        $rotatedOrientations = [
            Imagick::ORIENTATION_LEFTTOP,
            Imagick::ORIENTATION_RIGHTTOP,
            Imagick::ORIENTATION_RIGHTBOTTOM,
            Imagick::ORIENTATION_LEFTBOTTOM,
        ];

        if (in_array($imagick->getImageOrientation(), $rotatedOrientations, true)) {
            [$width, $height] = [$height, $width];
        }

        if ($height === 0) {
            return 1.0;
        }

        return $width / $height;
    }

    public function optimize(string $filePath): void
    {
        // Use JPEG size hint for faster decoding of large JPEGs (skips unnecessary DCT detail)
        $imagick = new Imagick();
        $imagick->setOption('jpeg:size', self::MAX_DIMENSION . 'x' . self::MAX_DIMENSION);
        $imagick->readImage($filePath);

        try {
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
                return;
            }

            $this->logger->info('Downscaling image from {width}x{height}', [
                'width' => $width,
                'height' => $height,
                'path' => $filePath,
            ]);

            // Apply EXIF orientation to pixel data (must happen before stripping metadata)
            $imagick->autoOrient();
            $imagick->scaleImage(self::MAX_DIMENSION, self::MAX_DIMENSION, true);

            // Remove EXIF, ICC profiles, and other metadata
            $imagick->stripImage();
            $imagick->setImageCompressionQuality(85);

            // Write to temp path first to avoid corrupting the original if encoding fails
            $optimizedPath = $filePath . '_optimized';

            try {
                $imagick->writeImage($optimizedPath);
                rename($optimizedPath, $filePath);
            } catch (ImagickException $e) {
                // Format encoder not available (e.g. AVIF) — keep original file
                if (file_exists($optimizedPath)) {
                    unlink($optimizedPath);
                }

                $this->logger->warning('Could not write optimized image, using original', [
                    'path' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }
}
