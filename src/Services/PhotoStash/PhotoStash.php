<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PhotoStash;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DatabaseException;
use Imagick;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ForgetStashedPhoto;
use SpeedPuzzling\Web\Message\MarkStashedPhotoPreview;
use SpeedPuzzling\Web\Message\RecordPhotoStashEntry;
use SpeedPuzzling\Web\Query\GetPhotoStashEntry;
use SpeedPuzzling\Web\Value\StashedPhoto;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use SpeedPuzzling\Web\Value\UploadedImageMetadataSource;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps an uploaded photo while its form comes back with an error, so nobody has to pick it again
 * (docs/features/first-try-integrity.md, "Photos survive a refused form"). A browser never re-sends a file
 * input on its own, so the photo waits in object storage and the form carries an unguessable token.
 *
 * The photo and its preview JPEG live in object storage under the player's own prefix; what makes a token work is its
 * description, a `photo_stash_entry` row of that player (written through messages: RecordPhotoStashEntry,
 * MarkStashedPhotoPreview, ForgetStashedPhoto) - a token only works for the player who got it.
 * A preview JPEG is made right away, so even a HEIC shows up in every browser.
 *
 * A photo can also arrive without PHP (docs/features/photo-uploads/README.md): reserve() records the description
 * first and the browser uploads the photo itself straight to object storage under objectKey() (DirectPhotoUpload).
 * The form then carries only the token, and restore() reads the photo back like a kept one. Reserving, signing again
 * and letting go of a reservation make no object-storage call - the start of an upload never waits for it.
 *
 * What the browser read from a photo before it shrank it (BrowserImageSummary, docs/features/upload-image-metadata.md)
 * is kept in the description next to it, so it comes back with the photo - a refused form's photo returns as a token only.
 *
 * Nothing is deleted when the form saves: a resend of the same form (the answer got lost, an automatic retry) must
 * find its photos again to be answered as the saved result. Everything expires after KEEP_HOURS.
 */
final class PhotoStash implements ResetInterface
{
    private const string PREFIX = 'tmp-uploads';
    public const int KEEP_HOURS = 24;
    // Used photos stay until they expire (a resend must find them) - room for several forms and quick-adds in a row
    public const int MAX_PER_PLAYER = 20;
    private const int PREVIEW_SIZE = 600;

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function __construct(
        readonly private Filesystem $filesystem,
        readonly private ClockInterface $clock,
        readonly private LoggerInterface $logger,
        readonly private MessageBusInterface $messageBus,
        readonly private GetPhotoStashEntry $getPhotoStashEntry,
    ) {
    }

    public function keep(UploadedFile $file, string $playerId, null|UploadedImageMetadata $browserMetadata = null): null|StashedPhoto
    {
        if ($file->isValid() === false) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $base = $this->base($playerId, $token);
        $fileName = $file->getClientOriginalName() !== '' ? $file->getClientOriginalName() : 'photo';
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $now = $this->clock->now();

        try {
            $stream = fopen($file->getPathname(), 'rb');

            if ($stream === false) {
                return null;
            }

            $this->filesystem->writeStream($base, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $preview = $this->preview($file->getPathname());

            if ($preview !== null) {
                $this->filesystem->write($base . '.jpg', $preview);
            }
        } catch (FilesystemException $e) {
            // Then the player chooses the photo again - exactly what happened before this existed
            $this->logger->warning('Could not keep an uploaded photo for a refused form', [
                'exception' => $e,
            ]);

            return null;
        }

        try {
            $this->messageBus->dispatch(new RecordPhotoStashEntry(
                token: $token,
                playerId: $playerId,
                name: $fileName,
                mime: $mime,
                preview: $preview !== null,
                bytes: null,
                browserMetadata: $browserMetadata?->toArray(),
                storedAt: $now,
                touchedAt: $now,
            ));
        } catch (\Throwable $e) {
            // The photo waits in object storage for the prune
            $this->logger->warning('Could not keep an uploaded photo for a refused form', [
                'exception' => $e,
            ]);

            return null;
        }

        return new StashedPhoto($token, $fileName, $preview !== null);
    }

    /**
     * A place for a photo the browser uploads itself (DirectPhotoUpload): the description now, the photo under
     * objectKey() later. No object-storage call. Null when even the description cannot be recorded - the browser then
     * sends the photo with the form, as before.
     */
    public function reserve(string $playerId, string $fileName, string $mime, int $bytes, null|UploadedImageMetadata $browserMetadata = null): null|string
    {
        $token = bin2hex(random_bytes(16));
        $fileName = $fileName !== '' ? $fileName : 'photo';
        $now = $this->clock->now();

        try {
            $this->messageBus->dispatch(new RecordPhotoStashEntry(
                token: $token,
                playerId: $playerId,
                name: $fileName,
                mime: $mime,
                preview: false,
                bytes: $bytes,
                browserMetadata: $browserMetadata?->toArray(),
                storedAt: $now,
                touchedAt: $now,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Could not reserve a place for a directly uploaded photo', [
                'exception' => $e,
            ]);

            return null;
        }

        return $token;
    }

    /**
     * Another photo was picked for the same field: the earlier reservation stops working at once. Its photo, if the
     * browser got it uploaded, waits for the daily prune - no object-storage call here. A kept photo (no reservation)
     * is never let go this way.
     */
    public function release(string $token, string $playerId): void
    {
        $meta = $this->meta($token, $playerId);

        if ($meta === null || $meta['bytes'] === null) {
            return;
        }

        try {
            $this->messageBus->dispatch(new ForgetStashedPhoto($token, $playerId));
        } catch (\Throwable $e) {
            // It expires on its own
            $this->logger->warning('Could not let go of a reserved photo place', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * The size a reservation was made for - null for no reservation of this player (or a photo kept from a form)
     */
    public function reservedBytes(string $token, string $playerId): null|int
    {
        return $this->meta($token, $playerId)['bytes'] ?? null;
    }

    /**
     * What the browser read from the photo before it shrank it - kept with the reservation or the kept photo
     */
    public function browserMetadata(string $token, string $playerId): null|UploadedImageMetadata
    {
        $data = $this->meta($token, $playerId)['browserMetadata'] ?? null;

        if ($data === null) {
            return null;
        }

        try {
            // Written here from a summary BrowserImageSummary accepted - a description broken since reads as nothing
            $metadata = UploadedImageMetadata::fromArray($data);
        } catch (\Throwable) {
            return null;
        }

        return $metadata?->source === UploadedImageMetadataSource::Browser ? $metadata : null;
    }

    /**
     * Where the photo of a token lives in object storage - what a direct upload is signed for
     */
    public function objectKey(string $playerId, string $token): string
    {
        return $this->base($playerId, $token);
    }

    /**
     * A directly uploaded photo has no preview until a refused form shows it again - made then from the restored file
     */
    public function addPreview(string $token, string $playerId, UploadedFile $file, null|UploadedImageMetadata $browserMetadata = null): null|StashedPhoto
    {
        $meta = $this->meta($token, $playerId);

        if ($meta === null) {
            return null;
        }

        if ($meta['preview']) {
            return new StashedPhoto($token, $meta['name'], true);
        }

        $preview = $this->preview($file->getPathname());

        if ($preview === null) {
            return new StashedPhoto($token, $meta['name'], false);
        }

        $base = $this->base($playerId, $token);
        $now = $this->clock->now();

        try {
            $this->filesystem->write($base . '.jpg', $preview);
        } catch (FilesystemException) {
            return new StashedPhoto($token, $meta['name'], false);
        }

        try {
            $this->messageBus->dispatch(new MarkStashedPhotoPreview($token, $playerId, $browserMetadata?->toArray(), $now));
        } catch (\Throwable $e) {
            $this->logger->warning('Could not record the preview of a kept photo', [
                'exception' => $e,
            ]);

            return new StashedPhoto($token, $meta['name'], false);
        }

        return new StashedPhoto($token, $meta['name'], true);
    }

    public function describe(string $token, string $playerId): null|StashedPhoto
    {
        $meta = $this->meta($token, $playerId);

        if ($meta === null) {
            return null;
        }

        return new StashedPhoto($token, $meta['name'], $meta['preview']);
    }

    /**
     * The kept photo as if it had just been uploaded - the form validates it again.
     */
    public function restore(string $token, string $playerId): null|UploadedFile
    {
        $meta = $this->meta($token, $playerId);

        if ($meta === null) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'photo-stash-');

        if ($path === false) {
            return null;
        }

        $this->temporaryFiles[] = $path;

        try {
            $source = $this->filesystem->readStream($this->base($playerId, $token));
            $target = fopen($path, 'wb');

            if ($target === false) {
                return null;
            }

            stream_copy_to_stream($source, $target);
            fclose($target);

            if (is_resource($source)) {
                fclose($source);
            }
        } catch (FilesystemException $e) {
            $this->logger->warning('Could not restore a kept photo', [
                'exception' => $e,
            ]);

            return null;
        }

        return new UploadedFile($path, $meta['name'], $meta['mime'], UPLOAD_ERR_OK, test: true);
    }

    /**
     * @return null|resource
     */
    public function previewStream(string $token, string $playerId): mixed
    {
        $meta = $this->meta($token, $playerId);

        if ($meta === null || $meta['preview'] === false) {
            return null;
        }

        try {
            return $this->filesystem->readStream($this->base($playerId, $token) . '.jpg');
        } catch (FilesystemException) {
            return null;
        }
    }

    public function discard(string $token, string $playerId): void
    {
        if (self::isToken($token) === false) {
            return;
        }

        try {
            $this->messageBus->dispatch(new ForgetStashedPhoto($token, $playerId));
        } catch (\Throwable $e) {
            // It expires on its own
            $this->logger->warning('Could not forget a kept photo', [
                'exception' => $e,
            ]);
        }

        $base = $this->base($playerId, $token);

        foreach ([$base, $base . '.jpg'] as $path) {
            try {
                $this->filesystem->delete($path);
            } catch (FilesystemException) {
                // The prune takes care of it
            }
        }
    }

    /**
     * The objects only - the descriptions are pruned by PrunePhotoStashHandler. Objects of photos that made way or were
     * replaced (no description any more) go here too.
     *
     * @return int how many files were removed
     */
    public function prune(): int
    {
        $cutoff = $this->clock->now()->modify('-' . self::KEEP_HOURS . ' hours')->getTimestamp();
        $removed = 0;

        $listing = $this->filesystem->listContents(self::PREFIX, true)
            ->filter(static fn(StorageAttributes $attributes): bool => $attributes->isFile())
            ->filter(static fn(StorageAttributes $attributes): bool => ($attributes->lastModified() ?? 0) < $cutoff);

        foreach ($listing as $file) {
            $this->filesystem->delete($file->path());
            $removed++;
        }

        return $removed;
    }

    public static function isToken(string $token): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $token) === 1;
    }

    public function reset(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporaryFiles = [];
    }

    /**
     * @return null|array{name: string, mime: string, preview: bool, bytes: null|int, storedAt: DateTimeImmutable, browserMetadata: null|array<mixed>}
     */
    private function meta(string $token, string $playerId): null|array
    {
        if (self::isToken($token) === false) {
            return null;
        }

        try {
            $meta = $this->getPhotoStashEntry->of($token, $playerId);
        } catch (DatabaseException $e) {
            $this->logger->warning('Could not read a kept photo\'s description', [
                'exception' => $e,
            ]);

            return null;
        }

        if ($meta === null || $meta['storedAt'] < $this->clock->now()->modify('-' . self::KEEP_HOURS . ' hours')) {
            return null;
        }

        return $meta;
    }

    private function preview(string $path): null|string
    {
        $imagick = new Imagick();

        try {
            // Decodes a big JPEG at a fraction of its size
            $imagick->setOption('jpeg:size', (self::PREVIEW_SIZE * 2) . 'x' . (self::PREVIEW_SIZE * 2));
            $imagick->readImage($path . '[0]');
            $imagick->autoOrient();
            $imagick->thumbnailImage(self::PREVIEW_SIZE, self::PREVIEW_SIZE, true);
            $imagick->stripImage();
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(80);

            return $imagick->getImageBlob();
        } catch (\Throwable $e) {
            // No preview then - the file name is shown instead
            $this->logger->info('Could not make a preview of a kept photo', [
                'exception' => $e,
            ]);

            return null;
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    private function base(string $playerId, string $token): string
    {
        return self::PREFIX . '/' . $this->playerKey($playerId) . '/' . $token;
    }

    private function playerKey(string $playerId): string
    {
        return strtolower(preg_replace('/[^0-9a-fA-F-]/', '', $playerId) ?? '');
    }
}
