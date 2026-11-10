<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ForgetStashedPhoto;
use SpeedPuzzling\Web\Repository\PhotoStashEntryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ForgetStashedPhotoHandler
{
    public function __construct(
        private PhotoStashEntryRepository $photoStashEntryRepository,
    ) {
    }

    public function __invoke(ForgetStashedPhoto $message): void
    {
        $entry = $this->photoStashEntryRepository->find($message->token, $message->playerId);

        if ($entry !== null) {
            $this->photoStashEntryRepository->delete($entry);
        }
    }
}
