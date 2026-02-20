<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Imagick;
use Psr\Log\LoggerInterface;

final readonly class ImageOptimizer
{
    private const int MAX_DIMENSION = 2000;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function optimize(string $filePath): void
    {
        $imagick = new Imagick($filePath);

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

            $imagick->scaleImage(self::MAX_DIMENSION, self::MAX_DIMENSION, true);
            $imagick->setImageCompressionQuality(92);
            $imagick->writeImage($filePath);
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }
}
