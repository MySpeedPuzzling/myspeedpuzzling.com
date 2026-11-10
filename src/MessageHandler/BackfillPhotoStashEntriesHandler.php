<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\BackfillPhotoStashEntries;
use SpeedPuzzling\Web\Message\RecordPhotoStashEntry;
use SpeedPuzzling\Web\Query\GetPhotoStashEntry;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Value\PhotoStashBackfillResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Release 1 of 2: copies the old-format PhotoStash descriptions into photo_stash_entry (see BackfillPhotoStashEntries).
 * Oldest first, so the cap of a few photos per player keeps the most recent ones, as the previous release did.
 */
#[AsMessageHandler]
readonly final class BackfillPhotoStashEntriesHandler
{
    public function __construct(
        private Filesystem $filesystem,
        private GetPhotoStashEntry $getPhotoStashEntry,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws FilesystemException when object storage cannot be listed - nothing was written then
     */
    public function __invoke(BackfillPhotoStashEntries $message): PhotoStashBackfillResult
    {
        $result = new PhotoStashBackfillResult();
        $expiredBefore = $this->clock->now()->modify('-' . PhotoStash::KEEP_HOURS . ' hours');
        $candidates = [];

        /** @var list<StorageAttributes> $listing */
        $listing = $this->filesystem->listContents('tmp-uploads', true)
            ->filter(static fn(StorageAttributes $attributes): bool => $attributes->isFile() && str_ends_with($attributes->path(), '.json'))
            ->toArray();

        foreach ($listing as $file) {
            if (preg_match('~^tmp-uploads/([0-9a-f-]{36})/([0-9a-f]{32})\.json$~', $file->path(), $match) !== 1 || Uuid::isValid($match[1]) === false) {
                continue;
            }

            [, $playerId, $token] = $match;
            $result->descriptions++;

            try {
                $data = Json::decode($this->filesystem->read($file->path()), forceArrays: true);
            } catch (FilesystemException | JsonException) {
                $result->broken++;

                continue;
            }

            if (is_array($data) && ($data['mirror'] ?? false) === true) {
                $result->mirrors++;

                continue;
            }

            $description = PhotoStash::parseLegacyDescription($data);

            if ($description === null) {
                $result->broken++;

                continue;
            }

            if ($description['storedAt'] < $expiredBefore) {
                $result->expired++;

                continue;
            }

            if ($this->getPhotoStashEntry->of($token, $playerId) !== null) {
                $result->alreadyRecorded++;

                continue;
            }

            $result->toRecord++;
            $candidates[] = [
                'token' => $token,
                'playerId' => $playerId,
                'description' => $description,
                // The previous release's recency: when the description was last written (a preview rewrote it)
                'touchedAt' => new DateTimeImmutable('@' . ($file->lastModified() ?? $description['storedAt']->getTimestamp()))
                    ->setTimezone($description['storedAt']->getTimezone()),
            ];
        }

        if ($message->write === false) {
            return $result;
        }

        usort($candidates, static fn(array $a, array $b): int => $a['touchedAt'] <=> $b['touchedAt']);

        foreach ($candidates as $candidate) {
            $description = $candidate['description'];

            try {
                $this->messageBus->dispatch(new RecordPhotoStashEntry(
                    token: $candidate['token'],
                    playerId: $candidate['playerId'],
                    name: $description['name'],
                    mime: $description['mime'],
                    preview: $description['preview'],
                    bytes: $description['bytes'],
                    browserMetadata: $description['browserMetadata'],
                    storedAt: $description['storedAt'],
                    touchedAt: $candidate['touchedAt'],
                ));
                $result->recorded++;
            } catch (\Throwable $e) {
                $this->logger->info('Could not backfill a PhotoStash description', [
                    'exception' => $e,
                    'token' => $candidate['token'],
                ]);
                $result->failed++;
            }
        }

        return $result;
    }
}
