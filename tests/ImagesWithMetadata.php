<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Imagick;
use ImagickDraw;

/**
 * Test images that carry what phone photos carry: EXIF with the camera, a GPS position and an
 * orientation, a comment and a colour profile. Imagick cannot write EXIF into a fresh image, so the
 * EXIF segment / chunks are spliced in by hand. Every image has a red block in its top-left corner,
 * so a test can tell where "up" is.
 */
final class ImagesWithMetadata
{
    public const string CAMERA = 'Apple';

    /**
     * A colour profile header without tags: enough for ImageMagick to keep it, and to compare bytes.
     */
    public static function colourProfile(): string
    {
        $header = pack('N', 132) . 'lcms' . pack('N', 0x02100000) . 'mntr' . 'RGB ' . 'XYZ '
            . str_repeat("\0", 12) . 'acsp' . 'APPL' . pack('N', 0) . str_repeat("\0", 16) . pack('N', 0)
            . pack('NNN', 0xF6D6, 0x10000, 0xD32D) . 'test' . str_repeat("\0", 44);

        return $header . pack('N', 0);
    }

    public static function jpeg(
        int $width,
        int $height,
        null|int $orientation = null,
        bool $exif = true,
        null|string $comment = 'taken at home',
        bool $colourProfile = true,
    ): string {
        $imagick = self::canvas($width, $height);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(92);

        if ($colourProfile) {
            $imagick->profileImage('icc', self::colourProfile());
        }

        if ($comment !== null) {
            $imagick->commentImage($comment);
        }

        $data = $imagick->getImageBlob();
        $imagick->clear();

        if ($exif === false) {
            return $data;
        }

        $segment = "Exif\0\0" . self::tiff($orientation);

        return substr($data, 0, 2) . "\xFF\xE1" . pack('n', strlen($segment) + 2) . $segment . substr($data, 2);
    }

    /**
     * A PNG with an eXIf chunk (camera + GPS) and a tEXt comment, both before the image data.
     */
    public static function png(int $width, int $height): string
    {
        $imagick = self::canvas($width, $height);
        $imagick->setImageFormat('png');
        $data = $imagick->getImageBlob();
        $imagick->clear();

        $idat = strpos($data, 'IDAT');
        assert(is_int($idat));
        $chunks = self::pngChunk('eXIf', self::tiff(null)) . self::pngChunk('tEXt', "Comment\0taken at home");

        return substr($data, 0, $idat - 4) . $chunks . substr($data, $idat - 4);
    }

    /**
     * A lossless WebP in the extended format with an EXIF chunk (camera + GPS) after the image data.
     */
    public static function losslessWebp(int $width, int $height): string
    {
        $imagick = self::canvas($width, $height);
        $imagick->setImageFormat('webp');
        $imagick->setOption('webp:lossless', 'true');
        $data = $imagick->getImageBlob();
        $imagick->clear();
        assert(substr($data, 12, 4) === 'VP8L');

        $exif = self::tiff(null);
        $exifChunk = 'EXIF' . pack('V', strlen($exif)) . $exif . (strlen($exif) % 2 === 1 ? "\0" : '');
        // VP8X: flags (0x08 = has EXIF), 3 reserved bytes, canvas width - 1 and height - 1 as 24-bit numbers
        $vp8x = 'VP8X' . pack('V', 10) . "\x08\0\0\0"
            . substr(pack('V', $width - 1), 0, 3) . substr(pack('V', $height - 1), 0, 3);
        $body = 'WEBP' . $vp8x . substr($data, 12) . $exifChunk;

        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    /**
     * @return list<string> chunk types of a PNG, in order
     */
    public static function pngChunkTypes(string $png): array
    {
        $types = [];
        $offset = 8;

        while ($offset + 8 <= strlen($png)) {
            /** @var array{length: int, type: string} $chunk */
            $chunk = unpack('Nlength/a4type', substr($png, $offset, 8));
            $types[] = $chunk['type'];
            $offset += 12 + $chunk['length'];
        }

        return $types;
    }

    /**
     * @return list<string> chunk types of a WebP, in order
     */
    public static function webpChunkTypes(string $webp): array
    {
        $types = [];
        $offset = 12;

        while ($offset + 8 <= strlen($webp)) {
            /** @var array{type: string, length: int} $chunk */
            $chunk = unpack('a4type/Vlength', substr($webp, $offset, 8));
            $types[] = $chunk['type'];
            $offset += 8 + $chunk['length'] + ($chunk['length'] % 2);
        }

        return $types;
    }

    private static function canvas(int $width, int $height): Imagick
    {
        $imagick = new Imagick();
        $imagick->newPseudoImage($width, $height, 'gradient:skyblue-darkgreen');
        $marker = new ImagickDraw();
        $marker->setFillColor('#ff0000');
        $marker->rectangle(0, 0, intdiv($width, 5), intdiv($height, 5));
        $imagick->drawImage($marker);

        return $imagick;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /**
     * TIFF structure of an EXIF block (little-endian): IFD0 with the camera make, an optional
     * orientation and a pointer to the GPS IFD holding a latitude and a longitude.
     */
    private static function tiff(null|int $orientation): string
    {
        $make = self::CAMERA . "\0";
        $entryCount = $orientation === null ? 2 : 3;

        $ifd0Offset = 8;
        $makeOffset = $ifd0Offset + 2 + $entryCount * 12 + 4;
        $gpsOffset = $makeOffset + strlen($make);
        $latitudeOffset = $gpsOffset + 2 + 4 * 12 + 4;
        $longitudeOffset = $latitudeOffset + 24;

        // Entries sorted by tag: Make (ASCII), Orientation (SHORT), GPSInfo (LONG pointer)
        $ifd0 = pack('v', $entryCount) . pack('vvV', 0x010F, 2, strlen($make)) . pack('V', $makeOffset);

        if ($orientation !== null) {
            $ifd0 .= pack('vvV', 0x0112, 3, 1) . pack('vv', $orientation, 0);
        }

        $ifd0 .= pack('vvV', 0x8825, 4, 1) . pack('V', $gpsOffset) . pack('V', 0);

        $gps = pack('v', 4)
            . pack('vvV', 0x0001, 2, 2) . "N\0\0\0"
            . pack('vvV', 0x0002, 5, 3) . pack('V', $latitudeOffset)
            . pack('vvV', 0x0003, 2, 2) . "E\0\0\0"
            . pack('vvV', 0x0004, 5, 3) . pack('V', $longitudeOffset)
            . pack('V', 0);

        $latitude = pack('VVVVVV', 50, 1, 5, 1, 1500, 100);
        $longitude = pack('VVVVVV', 14, 1, 25, 1, 1668, 100);

        return 'II' . pack('v', 42) . pack('V', $ifd0Offset) . $ifd0 . $make . $gps . $latitude . $longitude;
    }
}
