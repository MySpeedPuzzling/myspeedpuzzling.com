<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\PrunePhotoStash;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Photos kept for refused forms that nobody came back for (PhotoStash::KEEP_HOURS), and uploaded participant lists
 * nobody confirmed (ParticipantImportStash::KEEP_HOURS - they hold personal data).
 */
#[AsMessageHandler]
readonly final class PrunePhotoStashHandler
{
    public function __construct(
        private PhotoStash $photoStash,
        private ParticipantImportStash $participantImportStash,
    ) {
    }

    public function __invoke(PrunePhotoStash $message): int
    {
        return $this->photoStash->prune() + $this->participantImportStash->prune();
    }
}
