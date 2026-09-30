<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PhotoStash;

use DateTimeImmutable;
use Imagick;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Value\StashedPhoto;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps an uploaded photo while its form comes back with an error, so nobody has to pick it again
 * (docs/features/first-try-integrity.md, "Photos survive a refused form"). A browser never re-sends a file
 * input on its own, so the photo waits in object storage and the form carries an unguessable token.
 *
 * Everything of a player lives under their own prefix - a token only works for the player who got it.
 * A preview JPEG is made right away, so even a HEIC shows up in every browser.
 */
final class PhotoStash implements ResetInterface
{
    private const string PREFIX = 'tmp-uploads';
    public const int KEEP_HOURS = 24;
    private const int MAX_PER_PLAYER = 6;
    private const int PREVIEW_SIZE = 600;

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function __construct(
        readonly private Filesystem $filesystem,
        readonly private ClockInterface $clock,
        readonly private LoggerInterface $logger,
    ) {
    }

    public function keep(UploadedFile $file, string $playerId): null|StashedPhoto
    {
        if ($file->isValid() === false) {
            return null;
        }

        $this->makeRoom($playerId);

        $token = bin2hex(random_bytes(16));
        $base = $this->base($playerId, $token);
        $fileName = $file->getClientOriginalName() !== '' ? $file->getClientOriginalName() : 'photo';

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

            $this->filesystem->write($base . '.json', Json::encode([
                'name' => $fileName,
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'preview' => $preview !== null,
                'storedAt' => $this->clock->now()->format(DATE_ATOM),
            ]));
        } catch (FilesystemException $e) {
            // Then the player chooses the photo again - exactly what happened before this existed
            $this->logger->warning('Could not keep an uploaded photo for a refused form', [
                'exception' => $e,
            ]);

            return null;
        }

        return new StashedPhoto($token, $fileName, $preview !== null);
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

        $base = $this->base($playerId, $token);

        foreach ([$base, $base . '.jpg', $base . '.json'] as $path) {
            try {
                $this->filesystem->delete($path);
            } catch (FilesystemException) {
                // The prune takes care of it
            }
        }
    }

    /**
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
     * @return null|array{name: string, mime: string, preview: bool, storedAt: DateTimeImmutable}
     */
    private function meta(string $token, string $playerId): null|array
    {
        if (self::isToken($token) === false) {
            return null;
        }

        try {
            $meta = Json::decode($this->filesystem->read($this->base($playerId, $token) . '.json'), forceArrays: true);
        } catch (FilesystemException | JsonException) {
            return null;
        }

        if (
            is_array($meta) === false
            || is_string($meta['name'] ?? null) === false
            || is_string($meta['mime'] ?? null) === false
            || is_string($meta['storedAt'] ?? null) === false
        ) {
            return null;
        }

        $storedAt = new DateTimeImmutable($meta['storedAt']);

        if ($storedAt < $this->clock->now()->modify('-' . self::KEEP_HOURS . ' hours')) {
            return null;
        }

        return [
            'name' => $meta['name'],
            'mime' => $meta['mime'],
            'preview' => ($meta['preview'] ?? false) === true,
            'storedAt' => $storedAt,
        ];
    }

    /**
     * Only a few photos per player are kept at a time - the oldest makes way.
     */
    private function makeRoom(string $playerId): void
    {
        try {
            $kept = $this->filesystem->listContents(self::PREFIX . '/' . $this->playerKey($playerId), false)
                ->filter(static fn(StorageAttributes $attributes): bool => $attributes->isFile() && str_ends_with($attributes->path(), '.json'))
                ->sortByPath()
                ->toArray();
        } catch (FilesystemException) {
            return;
        }

        if (count($kept) < self::MAX_PER_PLAYER) {
            return;
        }

        usort($kept, static fn(StorageAttributes $a, StorageAttributes $b): int => ($a->lastModified() ?? 0) <=> ($b->lastModified() ?? 0));

        foreach (array_slice($kept, 0, count($kept) - self::MAX_PER_PLAYER + 1) as $oldest) {
            $this->discard(basename($oldest->path(), '.json'), $playerId);
        }
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
