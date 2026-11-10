<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\MarkStashedPhotoPreview;
use SpeedPuzzling\Web\Repository\PhotoStashEntryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkStashedPhotoPreviewHandler
{
    public function __construct(
        private PhotoStashEntryRepository $photoStashEntryRepository,
    ) {
    }

    public function __invoke(MarkStashedPhotoPreview $message): void
    {
        // Gone meanwhile (made way, expired and pruned) - nothing to mark
        $this->photoStashEntryRepository
            ->find($message->token, $message->playerId)
            ?->previewMade($message->at, $message->browserMetadata);
    }
}
