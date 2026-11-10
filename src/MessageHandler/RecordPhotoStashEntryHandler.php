<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\PhotoStashEntry;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RecordPhotoStashEntry;
use SpeedPuzzling\Web\Repository\PhotoStashEntryRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only a few photos per player are kept at a time - the least recently used make way. Their objects stay in object
 * storage until the daily prune: the request that starts an upload never waits for object storage
 * (docs/features/photo-uploads/README.md).
 */
#[AsMessageHandler]
readonly final class RecordPhotoStashEntryHandler
{
    public function __construct(
        private PhotoStashEntryRepository $photoStashEntryRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(RecordPhotoStashEntry $message): void
    {
        // Recorded already (a backfill run again) - the first description stays
        if ($this->photoStashEntryRepository->find($message->token, $message->playerId) !== null) {
            return;
        }

        $player = $this->playerRepository->get($message->playerId);
        $existing = $this->photoStashEntryRepository->ofPlayer($message->playerId);

        $entry = new PhotoStashEntry(
            token: $message->token,
            player: $player,
            name: $message->name,
            mime: $message->mime,
            preview: $message->preview,
            bytes: $message->bytes,
            storedAt: $message->storedAt,
            touchedAt: $message->touchedAt,
            browserMetadata: $message->browserMetadata,
        );

        $this->photoStashEntryRepository->save($entry);

        // The newest first; the new one wins a tie (usort is stable, it goes first)
        $entries = [$entry, ...$existing];
        usort($entries, static fn(PhotoStashEntry $a, PhotoStashEntry $b): int => $b->touchedAt <=> $a->touchedAt);

        foreach (array_slice($entries, PhotoStash::MAX_PER_PLAYER) as $surplus) {
            $this->photoStashEntryRepository->delete($surplus);
        }
    }
}
