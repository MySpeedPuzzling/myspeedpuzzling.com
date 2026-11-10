<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PrunePhotoStash;
use SpeedPuzzling\Web\Repository\PhotoStashEntryRepository;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Photos kept for refused forms that nobody came back for (PhotoStash::KEEP_HOURS) - their objects and their
 * descriptions -, and uploaded participant lists nobody confirmed (ParticipantImportStash::KEEP_HOURS - they hold
 * personal data).
 */
#[AsMessageHandler]
readonly final class PrunePhotoStashHandler
{
    public function __construct(
        private PhotoStash $photoStash,
        private PhotoStashEntryRepository $photoStashEntryRepository,
        private ParticipantImportStash $participantImportStash,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int objects and descriptions removed
     */
    public function __invoke(PrunePhotoStash $message): int
    {
        $removed = $this->photoStash->prune() + $this->participantImportStash->prune();

        // Last: this handler runs in a transaction, and the row locks must not be held while object storage is walked
        return $removed + $this->photoStashEntryRepository->deleteStoredBefore(
            $this->clock->now()->modify('-' . PhotoStash::KEEP_HOURS . ' hours'),
        );
    }
}
