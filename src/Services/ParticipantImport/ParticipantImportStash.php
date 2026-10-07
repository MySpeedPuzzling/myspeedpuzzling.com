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
use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\Exceptions\StashedParticipantImportNotFound;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use SpeedPuzzling\Web\Value\ParticipantFileOptions;
use SpeedPuzzling\Web\Value\ParticipantFileSheetInfo;
use SpeedPuzzling\Web\Value\ParticipantSheet;
use SpeedPuzzling\Web\Value\StashedParticipantImport;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps an uploaded participant list between the upload, the preview and the confirm
 * (docs/features/competitions-management/participant-import-preview.md, D9). Object storage, not local /tmp: the
 * confirm may land on another container (blue-green) or another worker.
 *
 * Under `tmp-imports/<competitionId>/`: `<token>` (the file), `<token>.json` (meta), `<token>.sheets.json` (the sheet
 * list) and `<token>.sheet-<n>.<options>.json` (a parsed sheet) - a mapping change never downloads and parses the file
 * again. Everything of an event lives under its own prefix: a token only works for the event it was uploaded to.
 * The file holds personal data: kept at most KEEP_HOURS, gone after discard(), pruned together with the photo stash
 * (`myspeedpuzzling:prune-photo-stash`).
 */
final class ParticipantImportStash implements ResetInterface
{
    private const string PREFIX = 'tmp-imports';
    public const int KEEP_HOURS = 24;
    public const int MAX_PER_EVENT = 3;
    private const int MAX_FILE_NAME_LENGTH = 200;

    /**
     * Files uploaded in this request, by token - read from the local disk instead of downloading them again
     *
     * @var array<string, string>
     */
    private array $localFiles = [];

    public function __construct(
        readonly private Filesystem $filesystem,
        readonly private ClockInterface $clock,
        readonly private LoggerInterface $logger,
        readonly private ParticipantFileReader $reader,
    ) {
    }

    public function keep(UploadedFile $file, string $competitionId, string $playerId): null|StashedParticipantImport
    {
        $fileName = mb_substr($file->getClientOriginalName(), 0, self::MAX_FILE_NAME_LENGTH);
        $format = ParticipantFileFormat::fromFileName($fileName);

        if ($file->isValid() === false || $format === null || self::isUuid($competitionId) === false) {
            return null;
        }

        $this->makeRoom($competitionId);

        $token = bin2hex(random_bytes(16));
        $base = $this->base($competitionId, $token);
        $storedAt = $this->clock->now();
        $detectedOptions = null;

        if ($format === ParticipantFileFormat::Csv) {
            try {
                $detectedOptions = $this->reader->detectCsvOptions($file->getPathname());
            } catch (ParticipantFileUnreadable) {
                // sheets() tells the organiser
            }
        }

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
                'storedAt' => $storedAt->format(DATE_RFC3339_EXTENDED),
                'appliedAt' => null,
                'detected' => $detectedOptions === null ? null : [
                    'encoding' => $detectedOptions->encoding,
                    'separator' => $detectedOptions->separator,
                ],
            ]));
        } catch (FilesystemException $e) {
            $this->logger->warning('Could not keep an uploaded participant list', [
                'exception' => $e,
            ]);

            return null;
        }

        $this->localFiles[$token] = $file->getPathname();

        return new StashedParticipantImport(
            token: $token,
            fileName: $fileName,
            format: $format,
            storedAt: self::parseDate($storedAt->format(DATE_RFC3339_EXTENDED)) ?? $storedAt,
            appliedAt: null,
            uploadedByPlayerId: $playerId,
            detectedOptions: $detectedOptions,
        );
    }

    public function describe(string $token, string $competitionId): null|StashedParticipantImport
    {
        $meta = $this->meta($token, $competitionId);

        if ($meta === null) {
            return null;
        }

        return new StashedParticipantImport(
            token: $token,
            fileName: $meta['name'],
            format: $meta['format'],
            storedAt: $meta['storedAt'],
            appliedAt: $meta['appliedAt'],
            uploadedByPlayerId: $meta['playerId'],
            detectedOptions: $meta['detected'],
        );
    }

    /**
     * @return list<ParticipantFileSheetInfo>
     *
     * @throws StashedParticipantImportNotFound
     * @throws ParticipantFileUnreadable
     */
    public function sheets(string $token, string $competitionId): array
    {
        $meta = $this->meta($token, $competitionId) ?? throw new StashedParticipantImportNotFound();
        $cachePath = $this->base($competitionId, $token) . '.sheets.json';
        $cached = $this->readCache($cachePath);

        if (is_array($cached)) {
            $sheets = [];

            foreach ($cached as $item) {
                $sheet = ParticipantFileSheetInfo::fromArray($item);

                if ($sheet === null) {
                    $sheets = null;

                    break;
                }

                $sheets[] = $sheet;
            }

            if ($sheets !== null && $sheets !== []) {
                return $sheets;
            }
        }

        $sheets = $this->withLocalFile(
            $token,
            $competitionId,
            fn(string $path): array => $this->reader->sheets($path, $meta['format']),
        );

        $this->writeCache($cachePath, array_map(static fn(ParticipantFileSheetInfo $sheet): array => $sheet->toArray(), $sheets));

        return $sheets;
    }

    /**
     * @throws StashedParticipantImportNotFound
     * @throws ParticipantFileUnreadable
     */
    public function sheet(string $token, string $competitionId, int $sheet, ParticipantFileOptions $options): ParticipantSheet
    {
        $meta = $this->meta($token, $competitionId) ?? throw new StashedParticipantImportNotFound();

        if ($sheet < 0) {
            throw new ParticipantFileUnreadable(sprintf('no sheet %d', $sheet));
        }

        if ($meta['format'] === ParticipantFileFormat::Xlsx) {
            // Encoding and separator mean nothing to a workbook
            $options = new ParticipantFileOptions();
        }

        $cachePath = sprintf('%s.sheet-%d.%s.json', $this->base($competitionId, $token), $sheet, $options->key());
        $cached = self::sheetFromCache($this->readCache($cachePath));

        if ($cached !== null) {
            return $cached;
        }

        $parsed = $this->withLocalFile(
            $token,
            $competitionId,
            fn(string $path): ParticipantSheet => $this->reader->read($path, $meta['format'], $sheet, $options),
        );

        $rows = [];

        foreach ($parsed->rows as $number => $cells) {
            $rows[] = [$number, $cells];
        }

        $this->writeCache($cachePath, ['headers' => $parsed->headers, 'rows' => $rows]);

        return $parsed;
    }

    public function markApplied(string $token, string $competitionId): void
    {
        if (self::isToken($token) === false || self::isUuid($competitionId) === false) {
            return;
        }

        $path = $this->base($competitionId, $token) . '.json';

        try {
            $meta = Json::decode($this->filesystem->read($path), forceArrays: true);

            if (is_array($meta) === false) {
                return;
            }

            $meta['appliedAt'] = $this->clock->now()->format(DATE_RFC3339_EXTENDED);
            $this->filesystem->write($path, Json::encode($meta));
        } catch (FilesystemException | JsonException $e) {
            $this->logger->warning('Could not mark a kept participant list as imported', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Removes the file and everything parsed from it. The meta of an applied import stays (no personal data in it)
     * until the prune, so a second click on Confirm still answers "Already imported".
     */
    public function discard(string $token, string $competitionId): void
    {
        if (self::isToken($token) === false || self::isUuid($competitionId) === false) {
            return;
        }

        $base = $this->base($competitionId, $token);
        $keepMeta = ($this->meta($token, $competitionId)['appliedAt'] ?? null) !== null;

        // The known names go even when the listing of object storage lags behind
        $files = [$base, $base . '.json', $base . '.sheets.json', ...$this->filesOf($competitionId, $token)];

        foreach (array_unique($files) as $path) {
            if ($keepMeta && $path === $base . '.json') {
                continue;
            }

            try {
                $this->filesystem->delete($path);
            } catch (FilesystemException) {
                // The prune takes care of it
            }
        }

        unset($this->localFiles[$token]);
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
        $this->localFiles = [];
    }

    /**
     * @template T
     * @param callable(string): T $read
     * @return T
     */
    private function withLocalFile(string $token, string $competitionId, callable $read): mixed
    {
        $local = $this->localFiles[$token] ?? null;

        if ($local !== null && is_file($local)) {
            return $read($local);
        }

        $path = tempnam(sys_get_temp_dir(), 'participant-import-');

        if ($path === false) {
            throw new ParticipantFileUnreadable('no temporary file');
        }

        try {
            try {
                $source = $this->filesystem->readStream($this->base($competitionId, $token));
            } catch (FilesystemException) {
                // The file is gone (discarded, pruned) while its meta is still there
                throw new StashedParticipantImportNotFound();
            }

            $target = fopen($path, 'wb');

            if ($target === false) {
                throw new ParticipantFileUnreadable('no temporary file');
            }

            stream_copy_to_stream($source, $target);
            fclose($target);

            if (is_resource($source)) {
                fclose($source);
            }

            return $read($path);
        } finally {
            @unlink($path);
        }
    }

    private function readCache(string $path): mixed
    {
        try {
            return Json::decode($this->filesystem->read($path), forceArrays: true);
        } catch (FilesystemException | JsonException) {
            return null;
        }
    }

    private function writeCache(string $path, mixed $data): void
    {
        try {
            $this->filesystem->write($path, Json::encode($data));
        } catch (FilesystemException | JsonException $e) {
            // Parsed again next time
            $this->logger->info('Could not cache a parsed participant list', [
                'exception' => $e,
            ]);
        }
    }

    private static function sheetFromCache(mixed $data): null|ParticipantSheet
    {
        if (is_array($data) === false || is_array($data['headers'] ?? null) === false || is_array($data['rows'] ?? null) === false) {
            return null;
        }

        $headers = [];

        foreach ($data['headers'] as $header) {
            if (is_string($header) === false) {
                return null;
            }

            $headers[] = $header;
        }

        $rows = [];

        foreach ($data['rows'] as $row) {
            if (is_array($row) === false || is_int($row[0] ?? null) === false || is_array($row[1] ?? null) === false) {
                return null;
            }

            $cells = [];

            foreach ($row[1] as $cell) {
                if (is_string($cell) === false) {
                    return null;
                }

                $cells[] = $cell;
            }

            $rows[$row[0]] = $cells;
        }

        return new ParticipantSheet($headers, $rows);
    }

    /**
     * @return null|array{name: string, format: ParticipantFileFormat, playerId: string, storedAt: DateTimeImmutable, appliedAt: null|DateTimeImmutable, detected: null|ParticipantFileOptions}
     */
    private function meta(string $token, string $competitionId): null|array
    {
        if (self::isToken($token) === false || self::isUuid($competitionId) === false) {
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
            || is_string($meta['playerId'] ?? null) === false
            || is_string($meta['storedAt'] ?? null) === false
        ) {
            return null;
        }

        $format = ParticipantFileFormat::tryFrom($meta['format']);
        $storedAt = self::parseDate($meta['storedAt']);
        $appliedAt = is_string($meta['appliedAt'] ?? null) ? self::parseDate($meta['appliedAt']) : null;

        if ($format === null || $storedAt === null) {
            return null;
        }

        if ($storedAt < $this->clock->now()->modify('-' . self::KEEP_HOURS . ' hours')) {
            return null;
        }

        $detected = $meta['detected'] ?? null;

        return [
            'name' => $meta['name'],
            'format' => $format,
            'playerId' => $meta['playerId'],
            'storedAt' => $storedAt,
            'appliedAt' => $appliedAt,
            'detected' => is_array($detected) ? ParticipantFileOptions::fromQuery($detected['encoding'] ?? null, $detected['separator'] ?? null) : null,
        ];
    }

    /**
     * Only a few uploads per event are kept at a time - the oldest makes way.
     */
    private function makeRoom(string $competitionId): void
    {
        try {
            $kept = $this->filesystem->listContents($this->eventDirectory($competitionId), false)
                ->filter(static fn(StorageAttributes $attributes): bool => $attributes->isFile() && self::isToken(basename($attributes->path())))
                ->map(static fn(StorageAttributes $attributes): string => basename($attributes->path()))
                ->toArray();
        } catch (FilesystemException) {
            return;
        }

        if (count($kept) < self::MAX_PER_EVENT) {
            return;
        }

        $storedAt = [];

        foreach ($kept as $token) {
            $storedAt[$token] = $this->meta($token, $competitionId)['storedAt'] ?? null;
        }

        // Unreadable or expired meta first, then the oldest
        uasort($storedAt, static fn(null|DateTimeImmutable $a, null|DateTimeImmutable $b): int => $a <=> $b);

        foreach (array_slice(array_keys($storedAt), 0, count($kept) - self::MAX_PER_EVENT + 1) as $token) {
            $this->delete($competitionId, $token);
        }
    }

    /**
     * Everything of a token, meta included.
     */
    private function delete(string $competitionId, string $token): void
    {
        foreach ($this->filesOf($competitionId, $token) as $path) {
            try {
                $this->filesystem->delete($path);
            } catch (FilesystemException) {
                // The prune takes care of it
            }
        }

        unset($this->localFiles[$token]);
    }

    /**
     * @return list<string> the paths of every file of a token (the file, its meta, its caches)
     */
    private function filesOf(string $competitionId, string $token): array
    {
        try {
            return array_values($this->filesystem->listContents($this->eventDirectory($competitionId), false)
                ->filter(static fn(StorageAttributes $attributes): bool => $attributes->isFile() && str_starts_with(basename($attributes->path()), $token))
                ->map(static fn(StorageAttributes $attributes): string => $attributes->path())
                ->toArray());
        } catch (FilesystemException) {
            return [];
        }
    }

    private static function parseDate(string $value): null|DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * A plain UUID only - it becomes a part of the storage path.
     */
    private static function isUuid(string $competitionId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $competitionId) === 1;
    }

    private function eventDirectory(string $competitionId): string
    {
        return self::PREFIX . '/' . strtolower($competitionId);
    }

    private function base(string $competitionId, string $token): string
    {
        return $this->eventDirectory($competitionId) . '/' . $token;
    }
}
