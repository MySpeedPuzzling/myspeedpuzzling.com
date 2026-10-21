<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use SpeedPuzzling\Web\Value\UploadedImageMetadataSource;

/**
 * What the browser read from a photo before it shrank it (assets/photo_metadata.js, docs/features/upload-image-metadata.md
 * "Read in the browser") - a canvas re-encode drops all EXIF, so without this most phone photos of the add/edit-time
 * form and multiscan would arrive with no capture time.
 *
 * It comes from the page, so it is checked strictly here: only the allow-listed keys are read (anything else is dropped),
 * each with its type, length and range; a value that breaks a rule rejects the whole summary (a page of ours never sends
 * one). The tags come raw and are reduced exactly like the server's own reading (UploadedImageSummariser::make(),
 * softwareFamily(), captureTime()) - the Software and Make strings never go further than this class.
 *
 * Stored only when the file that arrived has no EXIF of its own (UploadedImageMetadata::preferring()), marked
 * `source: browser`.
 */
final readonly class BrowserImageSummary
{
    public const int MAX_JSON_BYTES = 2048;

    // What assets/photo_metadata.js reads - JPEG, and the HEIF family (HEIC, AVIF) through its Exif item
    private const array FORMATS = ['JPEG', 'HEIC', 'AVIF'];
    // An original photo before shrinking - far above any phone's, below anything absurd
    private const int MAX_BYTES = 500_000_000;
    private const int MAX_PIXELS_PER_SIDE = 100_000;
    private const int MAX_TAG_LENGTH = 128;
    private const int MAX_TIME_LENGTH = 32;

    // Only with an EXIF block - a summary saying "no EXIF" carries none of them
    private const array EXIF_KEYS = ['dateTimeOriginal', 'offsetTimeOriginal', 'software', 'make', 'orientation'];

    public static function fromJson(null|string $json): null|UploadedImageMetadata
    {
        if ($json === null || $json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            return null;
        }

        try {
            return self::fromInput(json_decode($json, true, 2, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    public static function fromInput(mixed $input): null|UploadedImageMetadata
    {
        if (!is_array($input) || array_is_list($input)) {
            return null;
        }

        $bytes = self::intBetween($input['bytes'] ?? null, 1, self::MAX_BYTES);
        $format = $input['format'] ?? null;
        $width = self::intBetween($input['width'] ?? null, 1, self::MAX_PIXELS_PER_SIDE);
        $height = self::intBetween($input['height'] ?? null, 1, self::MAX_PIXELS_PER_SIDE);
        $exif = $input['exif'] ?? null;
        $cameraExif = $input['cameraExif'] ?? null;
        $screenshot = $input['screenshot'] ?? false;

        if (
            $bytes === null
            || !is_string($format) || !in_array($format, self::FORMATS, true)
            || $width === null
            || $height === null
            || !is_bool($exif)
            || !is_bool($cameraExif)
            || !is_bool($screenshot)
        ) {
            return null;
        }

        if ($exif === false) {
            foreach (self::EXIF_KEYS as $key) {
                if (($input[$key] ?? null) !== null) {
                    return null;
                }
            }

            if ($cameraExif || $screenshot) {
                return null;
            }

            return new UploadedImageMetadata(
                bytes: $bytes,
                format: $format,
                width: $width,
                height: $height,
                exif: false,
                cameraExif: false,
                source: UploadedImageMetadataSource::Browser,
            );
        }

        $dateTimeOriginal = self::optionalText($input['dateTimeOriginal'] ?? null, self::MAX_TIME_LENGTH);
        $offsetTimeOriginal = self::optionalText($input['offsetTimeOriginal'] ?? null, self::MAX_TIME_LENGTH);
        $software = self::optionalText($input['software'] ?? null, self::MAX_TAG_LENGTH);
        $make = self::optionalText($input['make'] ?? null, self::MAX_TAG_LENGTH);
        $orientationInput = $input['orientation'] ?? null;
        $orientation = self::intBetween($orientationInput, 1, 8);

        if (
            $dateTimeOriginal === false
            || $offsetTimeOriginal === false
            || $software === false
            || $make === false
            || ($orientationInput !== null && $orientation === null)
        ) {
            return null;
        }

        [$capturedAt, $capturedAtOffset] = UploadedImageSummariser::captureTime($dateTimeOriginal, $offsetTimeOriginal);
        $brand = UploadedImageSummariser::make($make);

        return new UploadedImageMetadata(
            bytes: $bytes,
            format: $format,
            width: $width,
            height: $height,
            exif: true,
            cameraExif: $cameraExif || $capturedAt !== null,
            capturedAt: $capturedAt,
            capturedAtOffset: $capturedAtOffset,
            // The browser only says whether the user comment labels a screenshot - never the comment itself
            software: UploadedImageSummariser::softwareFamily($software, $brand, $screenshot ? 'Screenshot' : null),
            make: $brand,
            orientation: $orientation,
            source: UploadedImageMetadataSource::Browser,
        );
    }

    private static function intBetween(mixed $value, int $min, int $max): null|int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }

    /**
     * Null when absent, the string when it is non-empty, printable, trimmed and no longer than $maxLength characters,
     * false for anything else
     */
    private static function optionalText(mixed $value, int $maxLength): null|false|string
    {
        if ($value === null) {
            return null;
        }

        if (
            !is_string($value)
            || $value === ''
            || mb_strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || trim($value) !== $value
        ) {
            return false;
        }

        return $value;
    }
}
