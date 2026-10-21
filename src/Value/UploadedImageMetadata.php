<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The privacy-safe summary of what an uploaded picture's file carried, read before ImageMetadata::strip() destroys it
 * (UploadedImageSummariser, docs/features/upload-image-metadata.md). Stored as JSON next to the picture's path on the
 * row that owns it (puzzle.image_upload_metadata, puzzle_change_request.proposed_image_upload_metadata,
 * puzzle_solving_time.finished_puzzle_photo_upload_metadata), so it goes with the picture and with its owner.
 *
 * Never anything that identifies a person, a place or a device: no GPS, no serial numbers, no owner / artist /
 * copyright / user comment, no maker notes, no thumbnail, no camera model - only the brand.
 *
 * Read by the server from the file that arrived, or - for a photo the browser shrank before uploading (the canvas
 * re-encode drops all EXIF) - by the browser from the photo as picked (BrowserImageSummary). `source` tells them apart.
 */
readonly final class UploadedImageMetadata
{
    // Bump when what is read, or how, changes - a reader can tell old summaries apart
    public const int VERSION = 1;

    public function __construct(
        // The file as uploaded (as picked, for a browser summary), before any resize or re-encode
        public int $bytes,
        // ImageMagick's name of the format: JPEG, PNG, WEBP, HEIC, GIF, TIFF, ...
        public string $format,
        // Stored pixels, before the EXIF orientation is applied
        public int $width,
        public int $height,
        // Any EXIF block at all (a screenshot or an editor's export can have one without a camera)
        public bool $exif,
        // EXIF a camera writes when it takes a picture: the make or model, the capture time, exposure, aperture, ISO
        // or focal length
        public bool $cameraExif,
        // DateTimeOriginal as found - the camera's local wall-clock time, `Y-m-d\TH:i:s`, no zone guessed
        public null|string $capturedAt = null,
        // OffsetTimeOriginal as found (`+02:00`), only when the camera wrote one
        public null|string $capturedAtOffset = null,
        public null|ImageSoftwareFamily $software = null,
        // The camera brand, one of UploadedImageSummariser's known names or "Other"
        public null|string $make = null,
        // EXIF orientation 1-8
        public null|int $orientation = null,
        public int $version = self::VERSION,
        // Who read it - a summary stored without one (the first days, 2026-10-21) is the server's
        public UploadedImageMetadataSource $source = UploadedImageMetadataSource::Server,
    ) {
    }

    /**
     * What is stored for an upload: the server's own reading when the file that arrived still had EXIF, else what the
     * browser read from the photo before it shrank it, else the server's reading (no EXIF, or null when unreadable).
     * The browser's word never overrides EXIF the server saw - it is self-reported (so is EXIF, but in the file).
     */
    public static function preferring(null|self $server, null|self $browser): null|self
    {
        if ($server !== null && $server->exif) {
            return $server;
        }

        return $browser ?? $server;
    }

    /**
     * @return array{version: int, source: string, bytes: int, format: string, width: int, height: int, exif: bool, cameraExif: bool, capturedAt: null|string, capturedAtOffset: null|string, software: null|string, make: null|string, orientation: null|int}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'source' => $this->source->value,
            'bytes' => $this->bytes,
            'format' => $this->format,
            'width' => $this->width,
            'height' => $this->height,
            'exif' => $this->exif,
            'cameraExif' => $this->cameraExif,
            'capturedAt' => $this->capturedAt,
            'capturedAtOffset' => $this->capturedAtOffset,
            'software' => $this->software?->value,
            'make' => $this->make,
            'orientation' => $this->orientation,
        ];
    }

    /**
     * @param null|array<mixed> $data what toArray() stored
     */
    public static function fromArray(null|array $data): null|self
    {
        if ($data === null) {
            return null;
        }

        /** @var array{version: int, source?: string, bytes: int, format: string, width: int, height: int, exif: bool, cameraExif: bool, capturedAt?: null|string, capturedAtOffset?: null|string, software?: null|string, make?: null|string, orientation?: null|int} $data */

        return new self(
            bytes: $data['bytes'],
            format: $data['format'],
            width: $data['width'],
            height: $data['height'],
            exif: $data['exif'],
            cameraExif: $data['cameraExif'],
            capturedAt: $data['capturedAt'] ?? null,
            capturedAtOffset: $data['capturedAtOffset'] ?? null,
            software: isset($data['software']) ? ImageSoftwareFamily::tryFrom($data['software']) : null,
            make: $data['make'] ?? null,
            orientation: $data['orientation'] ?? null,
            version: $data['version'],
            source: UploadedImageMetadataSource::tryFrom($data['source'] ?? '') ?? UploadedImageMetadataSource::Server,
        );
    }
}
