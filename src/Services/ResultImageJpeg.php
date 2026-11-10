<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Imagick;

/**
 * The result share card as a progressive JPEG. It is a photo under two overlays, which is what JPEG is for: a fifth of
 * the bytes of a PNG and a twentieth of the time to encode it (measured on an 800×800 card, 2026-11-10; the PNG encoder
 * was over half of the whole drawing).
 */
final class ResultImageJpeg
{
    private const int QUALITY = 88;

    /**
     * Encodes a copy: the image given stays as it is. Metadata is not removed here - ImageMetadata::strip() first.
     */
    public static function encode(Imagick $image): string
    {
        $jpeg = clone $image;

        try {
            // A PNG writer converts a CMYK print file by itself, a JPEG writer keeps it - and browsers show it wrong
            if ($jpeg->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                $jpeg->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            // Flattens any transparency over the background - a no-op for the card, whose pixels are all opaque
            $jpeg->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            // PJPEG is a plain JPEG file written progressively - ImageMagick's one reliable switch for that
            $jpeg->setImageFormat('pjpeg');
            $jpeg->setImageCompression(Imagick::COMPRESSION_JPEG);
            $jpeg->setImageCompressionQuality(self::QUALITY);
            $jpeg->setSamplingFactors(['2x2', '1x1', '1x1']);

            return $jpeg->getImageBlob();
        } finally {
            $jpeg->clear();
        }
    }
}
