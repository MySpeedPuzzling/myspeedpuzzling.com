<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RemoveUnimportedResultsSourceItem;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Repository\EventResultsSheetTabRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveUnimportedResultsSourceItemHandler
{
    public function __construct(
        private EventResultsSheetRepository $sourceRepository,
        private EventResultsSheetTabRepository $tabRepository,
    ) {
    }

    public function __invoke(RemoveUnimportedResultsSourceItem $message): void
    {
        $tab = $this->tabRepository->findOfSheet($this->sourceRepository->get($message->sourceId), $message->key);

        // Gone already, or an imported event (or one whose event was deleted) - kept
        if ($tab === null || $tab->competition !== null || $tab->firstImportedAt !== null) {
            return;
        }

        $this->tabRepository->delete($tab);
    }
}
