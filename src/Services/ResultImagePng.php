<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Imagick;

/**
 * The result share card as an opaque PNG (8-bit RGB). The card is a photo under opaque overlays - not a single pixel is
 * transparent - so dropping the alpha channel keeps every pixel as it was, while Intervention's PNG encoder always
 * writes RGBA: 10 % more bytes for the same picture (ResultImagePngTest compares the pixels of both).
 *
 * zlib level 6 instead of ImageMagick's default 7: the same picture, ~1 % more bytes, a third less time to encode
 * (measured on an 800×800 card, 2026-11-09).
 */
final class ResultImagePng
{
    private const string COMPRESSION_LEVEL = '6';

    /**
     * 65535 / 255 = 257 sixteen-bit values per 8-bit level: floor((v + 128) / 257) = round(v / 257) for every v
     */
    private const int HALF_AN_8_BIT_STEP = 128;

    /**
     * Encodes a copy: the image given stays as it is. Metadata is not removed here - ImageMetadata::strip() first.
     */
    public static function encode(Imagick $image): string
    {
        $png = clone $image;

        try {
            // ImageMagick (6.9, Q16) keeps 16 bits per channel and its RGBA writer rounds them to 8, while the RGB
            // writer cuts them off - one level darker on most pixels. Half an 8-bit step first makes the cut round.
            $png->evaluateImage(Imagick::EVALUATE_ADD, self::HALF_AN_8_BIT_STEP, Imagick::CHANNEL_DEFAULT);
            // Flattens any transparency over the background - a no-op for the card, whose pixels are all opaque
            $png->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $png->setImageFormat('PNG24');
            $png->setImageCompression(Imagick::COMPRESSION_ZIP);
            $png->setOption('png:compression-level', self::COMPRESSION_LEVEL);

            return $png->getImageBlob();
        } finally {
            $png->clear();
        }
    }
}
