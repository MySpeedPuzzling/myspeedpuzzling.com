<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteCompetitionDisplay;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Its link stops working at once. The design's uploaded images are cleaned up by D7 (only paths no other display
 * references).
 */
#[AsMessageHandler]
readonly final class DeleteCompetitionDisplayHandler
{
    public function __construct(
        private CompetitionDisplayRepository $displayRepository,
    ) {
    }

    public function __invoke(DeleteCompetitionDisplay $message): void
    {
        $this->displayRepository->delete($this->displayRepository->get($message->displayId));
    }
}
