<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Imagick;

/**
 * Everything in an image file that can tell who took a photo, where, when and with what device:
 * EXIF (GPS position, camera, capture time, the embedded thumbnail), XMP, IPTC, comments and PNG
 * text chunks. People photograph puzzles at home - a stored image must never carry any of it.
 * The colour profile is the one piece of metadata that stays: without it colours would shift.
 */
final class ImageMetadata
{
    /** @var list<string> */
    private const array COLOUR_PROFILES = ['icc', 'icm'];

    /** @var list<string> */
    private const array PNG_METADATA_CHUNKS = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    /** @var list<string> */
    private const array WEBP_METADATA_CHUNKS = ['EXIF', 'XMP '];

    /**
     * Imagick does not read every place metadata can hide - ImageMagick 6 skips a PNG eXIf chunk and a
     * WebP XMP chunk - so PNG and WebP files are also checked chunk by chunk.
     */
    public static function isCarriedBy(Imagick $imagick, string $filePath): bool
    {
        foreach ($imagick->getImageProfiles('*', false) as $profile) {
            if (is_string($profile) && !in_array(strtolower($profile), self::COLOUR_PROFILES, true)) {
                return true;
            }
        }

        if ($imagick->getImageProperties('comment', false) !== []) {
            return true;
        }

        return self::containerCarriesMetadata($filePath);
    }

    /**
     * Apply the EXIF orientation to the pixels before calling this (Imagick::autoOrient()), or the
     * image shows sideways once the orientation tag is gone.
     */
    public static function strip(Imagick $imagick): void
    {
        $colourProfiles = $imagick->getImageProfiles('icc', true);

        // All profiles (EXIF, XMP, IPTC, 8BIM, ...), the comment and ImageMagick's own date properties
        $imagick->stripImage();

        foreach ($colourProfiles as $name => $profile) {
            if (is_string($profile)) {
                $imagick->profileImage((string) $name, $profile);
            }
        }

        // stripImage() also tells the PNG encoder to leave out the colour chunks (iCCP, gAMA, cHRM, sRGB);
        // keep those, leave out only the ones that carry text or EXIF (ImageMagick writes every "exif:*"
        // property of the source as a tEXt chunk otherwise)
        $imagick->setImageArtifact('png:exclude-chunk', 'EXIF,iTXt,tEXt,zTXt,date,tIME');
    }

    private static function containerCarriesMetadata(string $filePath): bool
    {
        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $signature = (string) fread($handle, 12);

            if (str_starts_with($signature, "\x89PNG\r\n\x1a\n")) {
                return self::pngCarriesMetadata($handle);
            }

            if (str_starts_with($signature, 'RIFF') && substr($signature, 8, 4) === 'WEBP') {
                return self::webpCarriesMetadata($handle);
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private static function pngCarriesMetadata($handle): bool
    {
        fseek($handle, 8);

        while (($header = fread($handle, 8)) !== false && strlen($header) === 8) {
            /** @var array{length: int, type: string} $chunk */
            $chunk = unpack('Nlength/a4type', $header);

            if (in_array($chunk['type'], self::PNG_METADATA_CHUNKS, true)) {
                return true;
            }

            if ($chunk['type'] === 'IEND' || fseek($handle, $chunk['length'] + 4, SEEK_CUR) !== 0) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param resource $handle
     */
    private static function webpCarriesMetadata($handle): bool
    {
        fseek($handle, 12);

        while (($header = fread($handle, 8)) !== false && strlen($header) === 8) {
            /** @var array{type: string, length: int} $chunk */
            $chunk = unpack('a4type/Vlength', $header);

            if (in_array($chunk['type'], self::WEBP_METADATA_CHUNKS, true)) {
                return true;
            }

            // Chunks are padded to an even size
            if (fseek($handle, $chunk['length'] + ($chunk['length'] % 2), SEEK_CUR) !== 0) {
                return false;
            }
        }

        return false;
    }
}
