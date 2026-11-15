<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Exceptions\ImportDownloadTimedOut;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
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
     * Streams the object into a local file - never into memory - and says what it is: for the worker that reads it.
     *
     * Bounded in time: the object-storage client bounds connecting and every wait for bytes itself
     * (ObjectStorageHttpClientFactory), the stream is told to give up a read after
     * DataImportLimits::DOWNLOAD_IDLE_SECONDS, and the whole download may take `$totalSeconds` - 25 MB inside the
     * datacentre are seconds.
     *
     * @return array{bytes: int, sha256: string}
     *
     * @throws FilesystemException object storage does not answer, or the object is not there
     * @throws ImportDownloadTimedOut
     * @throws \RuntimeException the local file can not be written
     */
    public function downloadTo(string $key, string $localPath, float $totalSeconds = DataImportLimits::DOWNLOAD_TOTAL_SECONDS): array
    {
        $started = hrtime(true);
        $source = $this->filesystem->readStream($key);
        $target = fopen($localPath, 'wb');

        try {
            if ($target === false) {
                throw new \RuntimeException('The file for a downloaded import can not be opened.');
            }

            // Where the stream is a socket (or wraps one that listens to it): a read that gets nothing gives up
            stream_set_timeout($source, DataImportLimits::DOWNLOAD_IDLE_SECONDS);
            $hash = hash_init('sha256');
            $bytes = 0;

            while (feof($source) === false) {
                if ((hrtime(true) - $started) / 1e9 > $totalSeconds) {
                    throw new ImportDownloadTimedOut(sprintf('Downloading an import\'s file took more than %.0f s (%d bytes arrived).', $totalSeconds, $bytes));
                }

                $chunk = fread($source, 1_048_576);

                if ($chunk === false) {
                    throw new \RuntimeException('An import\'s file can not be read from object storage.');
                }

                // A stream that is no socket (a file, memory) has no such key, whatever the stubs say
                if ((stream_get_meta_data($source)['timed_out'] ?? false) === true) { // @phpstan-ignore nullCoalesce.offset
                    throw new ImportDownloadTimedOut(sprintf('Object storage sent nothing of an import\'s file for %d s (%d bytes arrived).', DataImportLimits::DOWNLOAD_IDLE_SECONDS, $bytes));
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($hash, $chunk);
                $bytes += strlen($chunk);

                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('A downloaded import can not be written to the disk.');
                }
            }

            return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($target)) {
                fclose($target);
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
