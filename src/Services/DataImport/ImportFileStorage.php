<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\PresignedImportUpload;

/**
 * Where an import's file lives in object storage (docs/features/data-import/upload.md): privately under
 * `tmp-imports/player-<playerId>/<importId>/file.<kind>`, never served. The key is the server's - nothing of the
 * file's own name is in it.
 *
 * The browser puts the file there itself by a presigned `PUT` (the photo uploads' presigner and bucket CORS rule -
 * docs/features/photo-uploads/README.md), signed for the server-chosen content type and the exact length, so the
 * store refuses any other upload. Signing is pure computation - no object-storage call.
 */
final readonly class ImportFileStorage
{
    public const string PREFIX = 'tmp-imports';
    // 25 MB from a phone on a slow connection takes minutes; the browser asks again when it ran out
    public const int PUT_LIFETIME_SECONDS = 1800;

    public function __construct(
        private Filesystem $filesystem,
        private OnlineMediaStores $stores,
        private S3QueryPresigner $presigner,
        private ClockInterface $clock,
    ) {
    }

    public static function objectKey(string $playerId, string $importId, DataImportFileKind $kind): string
    {
        return self::PREFIX . '/player-' . strtolower($playerId) . '/' . strtolower($importId) . '/file.' . $kind->value;
    }

    public function presignedUpload(DataImport $import): null|PresignedImportUpload
    {
        if ($import->fileKey === null || $import->fileKind === null || $import->fileSize === null) {
            return null;
        }

        $now = $this->clock->now();
        $headers = [
            'Content-Type' => $import->fileKind->contentType(),
            'Content-Length' => (string) $import->fileSize,
        ];

        return new PresignedImportUpload(
            url: $this->presigner->presign(
                $this->stores->publicLocation(OnlineMediaStore::Primary),
                'PUT',
                $import->fileKey,
                $headers,
                $now,
                self::PUT_LIFETIME_SECONDS,
            ),
            headers: $headers,
            expiresAtMs: ($now->getTimestamp() + self::PUT_LIFETIME_SECONDS) * 1000,
        );
    }

    /**
     * How many bytes are stored under the key - null when nothing is there.
     *
     * @throws FilesystemException object storage does not answer
     */
    public function storedBytes(string $key): null|int
    {
        if ($this->filesystem->fileExists($key) === false) {
            return null;
        }

        return $this->filesystem->fileSize($key);
    }

    /**
     * The fallback: a file that came with the plain form.
     *
     * @throws FilesystemException
     */
    public function store(string $key, string $localPath, DataImportFileKind $kind): void
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('The uploaded file can not be read.');
        }

        try {
            $this->filesystem->writeStream($key, $stream, ['ContentType' => $kind->contentType()]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * A missing object counts as deleted.
     *
     * @throws FilesystemException
     */
    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }
}
