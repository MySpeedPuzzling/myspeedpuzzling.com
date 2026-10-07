<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use SpeedPuzzling\Web\Value\StashedParticipantImport;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps an uploaded participant list between the upload, the preview and the confirm
 * (docs/features/competitions-management/participant-import-preview.md, D9). Object storage, not local /tmp: the
 * confirm may land on another container (blue-green) or another worker.
 *
 * Everything of an event lives under its own prefix - a token only works for the event it was uploaded to.
 * Pruned together with the photo stash (`myspeedpuzzling:prune-photo-stash`).
 */
final class ParticipantImportStash implements ResetInterface
{
    private const string PREFIX = 'tmp-imports';
    public const int KEEP_HOURS = 24;
    private const int MAX_FILE_NAME_LENGTH = 200;

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function __construct(
        readonly private Filesystem $filesystem,
        readonly private ClockInterface $clock,
        readonly private LoggerInterface $logger,
    ) {
    }

    public function keep(UploadedFile $file, string $competitionId, string $playerId): null|StashedParticipantImport
    {
        $fileName = mb_substr($file->getClientOriginalName(), 0, self::MAX_FILE_NAME_LENGTH);
        $format = ParticipantFileFormat::fromFileName($fileName);

        if ($file->isValid() === false || $format === null || self::isCompetitionId($competitionId) === false) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $base = $this->base($competitionId, $token);
        $storedAt = $this->clock->now();

        try {
            $stream = fopen($file->getPathname(), 'rb');

            if ($stream === false) {
                return null;
            }

            try {
                $this->filesystem->writeStream($base, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $this->filesystem->write($base . '.json', Json::encode([
                'name' => $fileName,
                'format' => $format->value,
                'playerId' => $playerId,
                'storedAt' => $storedAt->format(DATE_ATOM),
            ]));
        } catch (FilesystemException $e) {
            $this->logger->warning('Could not keep an uploaded participant list', [
                'exception' => $e,
            ]);

            return null;
        }

        return new StashedParticipantImport($token, $fileName, $format, new DateTimeImmutable($storedAt->format(DATE_ATOM)));
    }

    public function describe(string $token, string $competitionId): null|StashedParticipantImport
    {
        if (self::isToken($token) === false || self::isCompetitionId($competitionId) === false) {
            return null;
        }

        try {
            $meta = Json::decode($this->filesystem->read($this->base($competitionId, $token) . '.json'), forceArrays: true);
        } catch (FilesystemException | JsonException) {
            return null;
        }

        if (
            is_array($meta) === false
            || is_string($meta['name'] ?? null) === false
            || is_string($meta['format'] ?? null) === false
            || is_string($meta['storedAt'] ?? null) === false
        ) {
            return null;
        }

        $format = ParticipantFileFormat::tryFrom($meta['format']);

        try {
            $storedAt = new DateTimeImmutable($meta['storedAt']);
        } catch (\Exception) {
            return null;
        }

        if ($format === null || $storedAt < $this->clock->now()->modify('-' . self::KEEP_HOURS . ' hours')) {
            return null;
        }

        return new StashedParticipantImport($token, $meta['name'], $format, $storedAt);
    }

    /**
     * The kept file on the local disk for the reader - removed again at the end of the request (reset()).
     */
    public function localCopy(string $token, string $competitionId): null|string
    {
        $stashed = $this->describe($token, $competitionId);

        if ($stashed === null) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'participant-import-');

        if ($path === false) {
            return null;
        }

        $this->temporaryFiles[] = $path;

        try {
            $source = $this->filesystem->readStream($this->base($competitionId, $token));
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
            $this->logger->warning('Could not read a kept participant list', [
                'exception' => $e,
            ]);

            return null;
        }

        return $path;
    }

    public function discard(string $token, string $competitionId): void
    {
        if (self::isToken($token) === false || self::isCompetitionId($competitionId) === false) {
            return;
        }

        $base = $this->base($competitionId, $token);

        foreach ([$base, $base . '.json'] as $path) {
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

    /**
     * A plain UUID only - it becomes a part of the storage path.
     */
    private static function isCompetitionId(string $competitionId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $competitionId) === 1;
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

    private function base(string $competitionId, string $token): string
    {
        return self::PREFIX . '/' . strtolower($competitionId) . '/' . $token;
    }
}
