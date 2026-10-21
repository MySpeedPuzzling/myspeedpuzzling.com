<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Imagick;
use RuntimeException;
use SpeedPuzzling\Web\Value\ImageSoftwareFamily;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;

/**
 * Reads the privacy-safe summary of an uploaded image file (UploadedImageMetadata) - called by ImageOptimizer::optimize()
 * on the file as uploaded, before anything is resized or stripped (docs/features/upload-image-metadata.md).
 *
 * The EXIF block is read whole by PHP's exif extension (from the file's EXIF profile as ImageMagick finds it, a PNG's
 * eXIf chunk, or - for HEIC and TIFF - the file itself) and only a short allow-list is taken from it: whether it is
 * there, the capture time with its offset, the Software tag reduced to a family, the camera brand and the orientation.
 * Everything else in it - GPS, serial numbers, owner, artist, copyright, user comment, maker notes, the thumbnail, the
 * camera model - is read past and never leaves this class (the user comment only tells a screenshot apart).
 */
final readonly class UploadedImageSummariser
{
    public const string OTHER_MAKE = 'Other';

    /**
     * Lower-case prefix of the EXIF Make => the brand stored. Anything else is stored as "Other": the Make tag is free
     * text, a known list keeps it to brands.
     *
     * @var array<string, string>
     */
    private const array PHONE_MAKES = [
        'apple' => 'Apple',
        'samsung' => 'Samsung',
        'google' => 'Google',
        'xiaomi' => 'Xiaomi',
        'redmi' => 'Xiaomi',
        'poco' => 'Xiaomi',
        'huawei' => 'Huawei',
        'honor' => 'Honor',
        'oneplus' => 'OnePlus',
        'oppo' => 'OPPO',
        'vivo' => 'vivo',
        'realme' => 'realme',
        'motorola' => 'Motorola',
        'nokia' => 'Nokia',
        'hmd' => 'Nokia',
        'lge' => 'LG',
        'lg electronics' => 'LG',
        'asus' => 'ASUS',
        'nothing' => 'Nothing',
        'fairphone' => 'Fairphone',
        'zte' => 'ZTE',
        'tcl' => 'TCL',
    ];

    /**
     * @var array<string, string>
     */
    private const array CAMERA_MAKES = [
        'canon' => 'Canon',
        'nikon' => 'Nikon',
        'sony' => 'Sony',
        'fujifilm' => 'Fujifilm',
        'olympus' => 'OM System',
        'om digital' => 'OM System',
        'panasonic' => 'Panasonic',
        'pentax' => 'Ricoh',
        'ricoh' => 'Ricoh',
        'leica' => 'Leica',
        'gopro' => 'GoPro',
        'dji' => 'DJI',
        'hasselblad' => 'Hasselblad',
        'sigma' => 'Sigma',
        'kodak' => 'Kodak',
        'casio' => 'Casio',
    ];

    private const string SCREENSHOT_PATTERN = '/screen ?shot|snipping|greenshot|lightshot|sharex|flameshot|spectacle/i';

    private const string EDITOR_PATTERN = '/photoshop|lightroom|adobe|gimp|snapseed|picsart|pixelmator|affinity|paint\.net|paintshop|corel|acdsee|capture one|darktable|rawtherapee|luminar|canva|photoscape|fotor|irfanview|xnview|faststone|polarr|vsco|facetune|picasa|photo ?editor|windows photo|imagemagick|graphicsmagick|digikam|inkscape|krita|figma|meitu|instagram|^photos? \d|^preview\b/i';

    // Tags a camera writes when it takes a picture (IFD0 / Exif IFD names as PHP's exif extension reports them) - and a
    // real DateTimeOriginal (an editor's "0000:00:00 00:00:00" is none)
    private const array CAPTURE_TAGS = ['Make', 'Model', 'ExposureTime', 'FNumber', 'ISOSpeedRatings', 'PhotographicSensitivity', 'FocalLength'];

    /**
     * @throws RuntimeException|\ImagickException when the file is not an image ImageMagick can read
     */
    public function summarise(string $filePath): UploadedImageMetadata
    {
        $bytes = filesize($filePath);

        if ($bytes === false) {
            throw new RuntimeException('The uploaded file cannot be read.');
        }

        $imagick = new Imagick();

        try {
            // Ping reads the header and the profiles without decoding pixel data
            $imagick->pingImage($filePath);
            $format = strtoupper($imagick->getImageFormat());
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $tiff = self::exifProfile($imagick);
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }

        $tiff ??= $format === 'PNG' ? self::pngExifChunk($filePath) : null;
        $exif = $tiff !== null ? self::readTiff($tiff) : null;

        // ImageMagick 6 shows no EXIF profile of a HEIC; PHP's exif extension reads HEIF and TIFF files itself
        if ($exif === null && in_array($format, ['HEIC', 'TIFF'], true)) {
            $exif = self::readFile($filePath);
        }

        if ($exif === null) {
            return new UploadedImageMetadata(
                bytes: $bytes,
                format: $format,
                width: $width,
                height: $height,
                exif: false,
                cameraExif: false,
            );
        }

        $ifd0 = self::section($exif, 'IFD0');
        $exifIfd = self::section($exif, 'EXIF');
        $computed = self::section($exif, 'COMPUTED');
        [$capturedAt, $capturedAtOffset] = self::captureTime($exifIfd);
        $make = self::make(self::text($ifd0, 'Make'));

        return new UploadedImageMetadata(
            bytes: $bytes,
            format: $format,
            width: $width,
            height: $height,
            exif: true,
            cameraExif: $capturedAt !== null || self::hasCaptureTags([...$ifd0, ...$exifIfd]),
            capturedAt: $capturedAt,
            capturedAtOffset: $capturedAtOffset,
            software: self::softwareFamily(self::text($ifd0, 'Software'), $make, self::text($computed, 'UserComment')),
            make: $make,
            orientation: self::orientation($ifd0['Orientation'] ?? null),
        );
    }

    /**
     * The family of a Software tag (with the make it came with, and the EXIF user comment iOS and macOS label their
     * screenshots with). Null when there is neither a Software tag nor a screenshot label.
     */
    public static function softwareFamily(null|string $software, null|string $make, null|string $userComment = null): null|ImageSoftwareFamily
    {
        if (
            ($userComment !== null && preg_match(self::SCREENSHOT_PATTERN, $userComment) === 1)
            || ($software !== null && preg_match(self::SCREENSHOT_PATTERN, $software) === 1)
        ) {
            return ImageSoftwareFamily::Screenshot;
        }

        if ($software === null) {
            return null;
        }

        if (preg_match(self::EDITOR_PATTERN, $software) === 1) {
            return ImageSoftwareFamily::Editor;
        }

        if ($make !== null && in_array($make, self::PHONE_MAKES, true)) {
            return ImageSoftwareFamily::Phone;
        }

        if ($make !== null && in_array($make, self::CAMERA_MAKES, true)) {
            return ImageSoftwareFamily::Camera;
        }

        return ImageSoftwareFamily::Unknown;
    }

    /**
     * The brand of an EXIF Make tag - a known name, "Other" for anything else, null without one.
     */
    public static function make(null|string $make): null|string
    {
        if ($make === null) {
            return null;
        }

        $lower = strtolower($make);

        foreach ([...self::PHONE_MAKES, ...self::CAMERA_MAKES] as $prefix => $brand) {
            if (str_starts_with($lower, $prefix)) {
                return $brand;
            }
        }

        return self::OTHER_MAKE;
    }

    /**
     * The raw EXIF (TIFF structure) of the file's EXIF profile - a JPEG's APP1 segment or a WebP's EXIF chunk.
     */
    private static function exifProfile(Imagick $imagick): null|string
    {
        $profile = $imagick->getImageProfiles('exif', true)['exif'] ?? null;

        return is_string($profile) ? self::withoutExifHeader($profile) : null;
    }

    /**
     * ImageMagick 6 does not read a PNG's eXIf chunk - walked here, chunk by chunk, up to the image data.
     */
    private static function pngExifChunk(string $filePath): null|string
    {
        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            fseek($handle, 8);

            while (($header = fread($handle, 8)) !== false && strlen($header) === 8) {
                /** @var array{length: int, type: string} $chunk */
                $chunk = unpack('Nlength/a4type', $header);

                if ($chunk['type'] === 'eXIf') {
                    $data = $chunk['length'] > 0 ? fread($handle, $chunk['length']) : '';

                    return is_string($data) && $data !== '' ? self::withoutExifHeader($data) : null;
                }

                if (in_array($chunk['type'], ['IDAT', 'IEND'], true) || fseek($handle, $chunk['length'] + 4, SEEK_CUR) !== 0) {
                    return null;
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    private static function withoutExifHeader(string $data): null|string
    {
        if (str_starts_with($data, "Exif\0\0")) {
            $data = substr($data, 6);
        }

        return str_starts_with($data, "II*\0") || str_starts_with($data, "MM\0*") ? $data : null;
    }

    /**
     * @return null|array<mixed>
     */
    private static function readTiff(string $tiff): null|array
    {
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            return null;
        }

        try {
            fwrite($stream, $tiff);
            rewind($stream);

            // Camera EXIF is often a little broken (a wrong count, an offset past the end): what reads, is used
            $exif = @exif_read_data($stream, null, true, false);

            return is_array($exif) ? $exif : null;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return null|array<mixed>
     */
    private static function readFile(string $filePath): null|array
    {
        $exif = @exif_read_data($filePath, null, true, false);

        if (is_array($exif) === false) {
            return null;
        }

        $sections = self::section($exif, 'FILE')['SectionsFound'] ?? '';

        // Without IFD0 or EXIF the file only had what PHP computes itself
        return is_string($sections) && preg_match('/\b(IFD0|EXIF)\b/', $sections) === 1 ? $exif : null;
    }

    /**
     * @param array<mixed> $exif
     * @return array<mixed>
     */
    private static function section(array $exif, string $name): array
    {
        $section = $exif[$name] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param array<mixed> $section
     */
    private static function text(array $section, string $tag): null|string
    {
        $value = $section[$tag] ?? null;

        if (is_string($value) === false) {
            return null;
        }

        $value = trim(str_replace("\0", '', $value));

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $tags
     */
    private static function hasCaptureTags(array $tags): bool
    {
        foreach (self::CAPTURE_TAGS as $tag) {
            if (isset($tags[$tag]) && $tags[$tag] !== '' && $tags[$tag] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * DateTimeOriginal as `Y-m-d\TH:i:s` (no zone guessed) and OffsetTimeOriginal, each null when missing or not a
     * real value (cameras without a set clock write "0000:00:00 00:00:00").
     *
     * @param array<mixed> $exifIfd
     * @return array{null|string, null|string}
     */
    private static function captureTime(array $exifIfd): array
    {
        $original = self::text($exifIfd, 'DateTimeOriginal');

        if ($original === null || preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $original, $matches) !== 1) {
            return [null, null];
        }

        [, $year, $month, $day, $hour, $minute, $second] = array_map(intval(...), $matches);

        if ($year < 1900 || checkdate($month, $day, $year) === false || $hour > 23 || $minute > 59 || $second > 59) {
            return [null, null];
        }

        $capturedAt = sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        $offset = self::text($exifIfd, 'OffsetTimeOriginal');

        if ($offset === null || preg_match('/^[+-](\d{2}):(\d{2})$/', $offset, $offsetParts) !== 1 || (int) $offsetParts[1] > 14 || (int) $offsetParts[2] > 59) {
            $offset = null;
        }

        return [$capturedAt, $offset];
    }

    private static function orientation(mixed $value): null|int
    {
        if (is_int($value) && $value >= 1 && $value <= 8) {
            return $value;
        }

        return null;
    }
}
