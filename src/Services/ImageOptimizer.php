<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Imagick;
use ImagickException;
use Psr\Log\LoggerInterface;

final readonly class ImageOptimizer
{
    private const int MAX_DIMENSION = 2000;
    private const int RESIZE_QUALITY = 85;
    // ImageMagick reports a lossless WebP as quality 100
    private const int LOSSLESS = 100;

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

    /**
     * Every uploaded image passes here before it is stored: one larger than 2000 px is scaled down, and
     * whatever size it is, its metadata goes (GPS position, camera, capture time, XMP, IPTC, comments -
     * see ImageMetadata). Only the colour profile stays. An image without metadata that fits is stored
     * byte for byte as uploaded - the browser's own resize already produced exactly that.
     */
    public function optimize(string $filePath): void
    {
        $imagick = new Imagick();

        // A JPEG size hint lets libjpeg decode a big photo at a fraction of its size (faster, far less
        // memory). ImageMagick 6 turns a hint larger than the photo into a 2x UPSCALE (a 1200 px photo
        // was stored at 2000 px), so the hint is only given when both sides reach it - then it can only shrink
        if ($this->shorterSide($filePath) >= self::MAX_DIMENSION) {
            $imagick->setOption('jpeg:size', self::MAX_DIMENSION . 'x' . self::MAX_DIMENSION);
        }

        $imagick->readImage($filePath);

        try {
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $tooLarge = $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION;

            if ($tooLarge === false && ImageMetadata::isCarriedBy($imagick, $filePath) === false) {
                return;
            }

            // Re-encoding keeps one frame only - an animation that fits is kept as it is
            if ($tooLarge === false && $imagick->getNumberImages() > 1) {
                return;
            }

            // Read before anything changes: a photo that only loses its metadata is written at its own quality
            $quality = $tooLarge ? self::RESIZE_QUALITY : $this->sourceQuality($imagick);

            if ($quality === self::LOSSLESS) {
                $imagick->setOption('webp:lossless', 'true');
            }

            // Apply EXIF orientation to pixel data (must happen before stripping metadata)
            $imagick->autoOrient();

            if ($tooLarge) {
                $this->logger->info('Downscaling image from {width}x{height}', [
                    'width' => $width,
                    'height' => $height,
                    'path' => $filePath,
                ]);

                $imagick->scaleImage(self::MAX_DIMENSION, self::MAX_DIMENSION, true);
            }

            ImageMetadata::strip($imagick);
            $imagick->setImageCompressionQuality($quality);

            // Write to temp path first to avoid corrupting the original if encoding fails
            $optimizedPath = $filePath . '_optimized';

            try {
                $imagick->writeImage($optimizedPath);
                rename($optimizedPath, $filePath);
            } catch (ImagickException $e) {
                // Format encoder not available (e.g. AVIF) — keep original file, metadata included:
                // an upload never fails over this, but someone should look
                if (file_exists($optimizedPath)) {
                    unlink($optimizedPath);
                }

                $this->logger->warning('Could not write optimized image, using original', [
                    'path' => $filePath,
                    'exception' => $e,
                ]);
            }
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    private function shorterSide(string $filePath): int
    {
        $imagick = new Imagick();

        try {
            // Ping reads only the header
            $imagick->pingImage($filePath);

            return min($imagick->getImageWidth(), $imagick->getImageHeight());
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    /**
     * JPEG: its own quality (ImageMagick estimates it from the quantization tables), so the photo looks
     * and weighs the same. A lossless WebP stays lossless. Anything else (PNG is lossless anyway): the
     * quality downscaled uploads get.
     */
    private function sourceQuality(Imagick $imagick): int
    {
        $format = strtoupper($imagick->getImageFormat());
        $quality = $imagick->getImageCompressionQuality();

        if ($format === 'WEBP' && $quality >= self::LOSSLESS) {
            return self::LOSSLESS;
        }

        if ($format === 'JPEG' && $quality > 0) {
            return max(75, min(95, $quality));
        }

        return self::RESIZE_QUALITY;
    }
}
