<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Imagick;

/**
 * Photos with the EXIF blocks real devices write - the TIFF structure of an EXIF APP1 segment / PNG eXIf chunk / WebP
 * EXIF chunk built tag by tag, in either byte order (Apple and Samsung write little-endian, Nikon and Canon big-endian):
 * IFD0, the Exif IFD, the GPS IFD and IFD1 with a thumbnail. Imagick cannot write EXIF into a fresh image, so the block
 * is spliced in by hand. Every value here is made up.
 */
final class ExifPhotos
{
    public const string SERIAL_NUMBER = 'SN-4815162342';
    public const string LENS_SERIAL_NUMBER = 'LENS-0815';
    public const string OWNER = 'Alex Example';
    public const string USER_COMMENT = 'birthday at grandmas';
    public const string MODEL = 'iPhone 15 Pro';
    public const string MAKER_NOTE = 'secret maker note bytes';

    // Latitude 50°5'15.00" N, longitude 14°25'16.68" E - written as EXIF rationals
    public const array GPS_LATITUDE = [50, 5, 1500];
    public const array GPS_LONGITUDE = [14, 25, 1668];

    private const int ASCII = 2;
    private const int SHORT = 3;
    private const int LONG = 4;
    private const int RATIONAL = 5;
    private const int UNDEFINED = 7;

    /**
     * An iPhone photo: everything a phone writes - the capture time with its offset, the camera, GPS, serial numbers,
     * owner, a user comment, a maker note and a thumbnail.
     */
    public static function iphoneJpeg(int $width = 64, int $height = 48): string
    {
        return self::jpeg($width, $height, self::iphoneExif());
    }

    public static function iphoneExif(bool $bigEndian = false): string
    {
        return self::tiff(
            ifd0: [
                0x010F => self::ascii('Apple'),
                0x0110 => self::ascii(self::MODEL),
                0x0112 => self::short(6),
                0x0131 => self::ascii('17.4.1'),
                0x0132 => self::ascii('2026:10:21 14:03:12'),
                0x013B => self::ascii(self::OWNER),
                0x8298 => self::ascii('(c) ' . self::OWNER),
            ],
            exif: [
                0x829A => self::rational([[1, 120]]),
                0x829D => self::rational([[178, 100]]),
                0x8827 => self::short(64),
                0x9003 => self::ascii('2026:10:21 14:03:12'),
                0x9011 => self::ascii('+02:00'),
                0x920A => self::rational([[686, 100]]),
                0x927C => [self::UNDEFINED, strlen(self::MAKER_NOTE), self::MAKER_NOTE],
                0x9286 => self::userComment(self::USER_COMMENT),
                0xA42C => self::ascii(self::SERIAL_NUMBER),
                0xA431 => self::ascii(self::SERIAL_NUMBER),
                0xA435 => self::ascii(self::LENS_SERIAL_NUMBER),
            ],
            gps: [
                0x0001 => self::ascii('N'),
                0x0002 => self::rational([[self::GPS_LATITUDE[0], 1], [self::GPS_LATITUDE[1], 1], [self::GPS_LATITUDE[2], 100]]),
                0x0003 => self::ascii('E'),
                0x0004 => self::rational([[self::GPS_LONGITUDE[0], 1], [self::GPS_LONGITUDE[1], 1], [self::GPS_LONGITUDE[2], 100]]),
            ],
            thumbnail: self::thumbnailJpeg(),
            bigEndian: $bigEndian,
        );
    }

    /**
     * A system camera in big-endian (Motorola) byte order: firmware as Software, a capture time without an offset.
     */
    public static function nikonExif(): string
    {
        return self::tiff(
            ifd0: [
                0x010F => self::ascii('NIKON CORPORATION'),
                0x0110 => self::ascii('NIKON Z 6_2'),
                0x0112 => self::short(1),
                0x0131 => self::ascii('Ver.1.50'),
            ],
            exif: [
                0x829A => self::rational([[1, 250]]),
                0x9003 => self::ascii('2026:03:14 09:26:53'),
                0xA431 => self::ascii(self::SERIAL_NUMBER),
            ],
            bigEndian: true,
        );
    }

    /**
     * What an iPhone/Mac screenshot carries: a user comment "Screenshot", no camera.
     */
    public static function screenshotExif(): string
    {
        return self::tiff(
            ifd0: [0x0112 => self::short(1)],
            exif: [0x9286 => self::userComment('Screenshot')],
        );
    }

    /**
     * An editor's export: Software only, a camera clock that was never set.
     */
    public static function editorExif(): string
    {
        return self::tiff(
            ifd0: [0x0131 => self::ascii('Adobe Photoshop 25.0 (Macintosh)')],
            exif: [0x9003 => self::ascii('0000:00:00 00:00:00')],
        );
    }

    public static function jpeg(int $width, int $height, null|string $exif): string
    {
        $imagick = new Imagick();
        $imagick->newPseudoImage($width, $height, 'gradient:skyblue-darkgreen');
        $imagick->setImageFormat('jpeg');
        $data = $imagick->getImageBlob();
        $imagick->clear();

        if ($exif === null) {
            return $data;
        }

        $segment = "Exif\0\0" . $exif;

        return substr($data, 0, 2) . "\xFF\xE1" . pack('n', strlen($segment) + 2) . $segment . substr($data, 2);
    }

    /**
     * A PNG with the block in an eXIf chunk before the image data (ImageMagick 6 does not read it).
     */
    public static function png(int $width, int $height, string $exif): string
    {
        $imagick = new Imagick();
        $imagick->newPseudoImage($width, $height, 'gradient:white-black');
        $imagick->setImageFormat('png');
        $data = $imagick->getImageBlob();
        $imagick->clear();

        $idat = strpos($data, 'IDAT');
        assert(is_int($idat));
        $chunk = pack('N', strlen($exif)) . 'eXIf' . $exif . pack('N', crc32('eXIf' . $exif));

        return substr($data, 0, $idat - 4) . $chunk . substr($data, $idat - 4);
    }

    /**
     * A lossless WebP in the extended format with an EXIF chunk after the image data.
     */
    public static function webp(int $width, int $height, string $exif): string
    {
        $imagick = new Imagick();
        $imagick->newPseudoImage($width, $height, 'gradient:red-blue');
        $imagick->setImageFormat('webp');
        $imagick->setOption('webp:lossless', 'true');
        $data = $imagick->getImageBlob();
        $imagick->clear();
        assert(substr($data, 12, 4) === 'VP8L');

        $exifChunk = 'EXIF' . pack('V', strlen($exif)) . $exif . (strlen($exif) % 2 === 1 ? "\0" : '');
        $vp8x = 'VP8X' . pack('V', 10) . "\x08\0\0\0"
            . substr(pack('V', $width - 1), 0, 3) . substr(pack('V', $height - 1), 0, 3);
        $body = 'WEBP' . $vp8x . substr($data, 12) . $exifChunk;

        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    public static function file(string $content, string $extension = 'jpg'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exif_photo_');
        assert(is_string($path));
        unlink($path);
        $path .= '.' . $extension;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * The TIFF structure of an EXIF block: IFD0 (with pointers to the Exif and GPS IFDs), the sub-IFDs and, when given,
     * IFD1 holding a JPEG thumbnail.
     *
     * @param array<int, array{int, int, string}> $ifd0 tag => [type, count, little-endian value bytes]
     * @param array<int, array{int, int, string}> $exif
     * @param array<int, array{int, int, string}> $gps
     */
    private static function tiff(array $ifd0, array $exif = [], array $gps = [], null|string $thumbnail = null, bool $bigEndian = false): string
    {
        // Sizes do not depend on the pointer values (each fits its 4 bytes): lay out with zeros, then with the offsets
        $ifd0[0x8769] = self::long(0);

        if ($gps !== []) {
            $ifd0[0x8825] = self::long(0);
        }

        [, $exifOffset] = self::ifd($ifd0, 8, $bigEndian, 0);
        [, $gpsOffset] = self::ifd($exif, $exifOffset, $bigEndian, 0);
        [, $ifd1Offset] = $gps !== [] ? self::ifd($gps, $gpsOffset, $bigEndian, 0) : ['', $gpsOffset];

        $ifd0[0x8769] = self::long($exifOffset);

        if ($gps !== []) {
            $ifd0[0x8825] = self::long($gpsOffset);
        }

        [$ifd0Bytes] = self::ifd($ifd0, 8, $bigEndian, $thumbnail !== null ? $ifd1Offset : 0);
        [$exifBytes] = self::ifd($exif, $exifOffset, $bigEndian, 0);
        [$gpsBytes] = $gps !== [] ? self::ifd($gps, $gpsOffset, $bigEndian, 0) : [''];
        $ifd1Bytes = '';

        if ($thumbnail !== null) {
            // IFD1: JPEGInterchangeFormat (offset of the thumbnail, right after this two-entry IFD) and its length
            $ifd1 = [
                0x0201 => self::long($ifd1Offset + 2 + 2 * 12 + 4),
                0x0202 => self::long(strlen($thumbnail)),
            ];
            [$ifd1Bytes] = self::ifd($ifd1, $ifd1Offset, $bigEndian, 0);
            $ifd1Bytes .= $thumbnail;
        }

        $header = $bigEndian ? "MM\0*" . pack('N', 8) : "II*\0" . pack('V', 8);

        return $header . $ifd0Bytes . $exifBytes . $gpsBytes . $ifd1Bytes;
    }

    /**
     * @param array<int, array{int, int, string}> $entries
     * @return array{string, int} the IFD's bytes and the offset right after it
     */
    private static function ifd(array $entries, int $offset, bool $bigEndian, int $next): array
    {
        ksort($entries);
        $count = count($entries);
        $dataOffset = $offset + 2 + $count * 12 + 4;
        $short = $bigEndian ? 'n' : 'v';
        $long = $bigEndian ? 'N' : 'V';
        $head = pack($short, $count);
        $data = '';

        foreach ($entries as $tag => [$type, $valueCount, $value]) {
            $value = self::byteOrder($type, $value, $bigEndian);
            $head .= pack($short, $tag) . pack($short, $type) . pack($long, $valueCount);

            if (strlen($value) <= 4) {
                $head .= str_pad($value, 4, "\0");

                continue;
            }

            $head .= pack($long, $dataOffset + strlen($data));
            $data .= $value . (strlen($value) % 2 === 1 ? "\0" : '');
        }

        $head .= pack($long, $next);

        return [$head . $data, $offset + strlen($head . $data)];
    }

    private static function byteOrder(int $type, string $value, bool $bigEndian): string
    {
        if ($bigEndian === false) {
            return $value;
        }

        if (in_array($type, [self::SHORT, self::LONG, self::RATIONAL], true) === false) {
            return $value;
        }

        [$from, $to, $size] = $type === self::SHORT ? ['v', 'n', 2] : ['V', 'N', 4];

        $swapped = '';

        foreach (str_split($value, $size) as $number) {
            /** @var array{1: int} $unpacked */
            $unpacked = unpack($from, $number);
            $swapped .= pack($to, $unpacked[1]);
        }

        return $swapped;
    }

    /**
     * @return array{int, int, string}
     */
    private static function ascii(string $value): array
    {
        return [self::ASCII, strlen($value) + 1, $value . "\0"];
    }

    /**
     * @return array{int, int, string}
     */
    private static function short(int $value): array
    {
        return [self::SHORT, 1, pack('v', $value)];
    }

    /**
     * @return array{int, int, string}
     */
    private static function long(int $value): array
    {
        return [self::LONG, 1, pack('V', $value)];
    }

    /**
     * @param list<array{int, int}> $values
     * @return array{int, int, string}
     */
    private static function rational(array $values): array
    {
        return [self::RATIONAL, count($values), implode('', array_map(static fn (array $v): string => pack('VV', $v[0], $v[1]), $values))];
    }

    /**
     * @return array{int, int, string}
     */
    private static function userComment(string $comment): array
    {
        $value = "ASCII\0\0\0" . $comment;

        return [self::UNDEFINED, strlen($value), $value];
    }

    private static function thumbnailJpeg(): string
    {
        $imagick = new Imagick();
        $imagick->newPseudoImage(8, 6, 'xc:orange');
        $imagick->setImageFormat('jpeg');
        $data = $imagick->getImageBlob();
        $imagick->clear();

        return $data;
    }
}
