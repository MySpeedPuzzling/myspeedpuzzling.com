<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ContinueImportBatches;
use SpeedPuzzling\Web\Message\ProcessImportBatch;
use SpeedPuzzling\Web\Message\ProcessImportUndo;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The sweeper (docs/features/data-import/adding.md "The work"): a running "Add" or Undo nobody worked on for
 * DataImportLimits::BATCH_STANDING_AFTER_SECONDS is handed to the `imports` worker again. A job sent once too often
 * finds nothing to do - everything is driven by the rows. While adding is paused only Undos are continued.
 */
#[AsMessageHandler]
readonly final class ContinueImportBatchesHandler
{
    private const int AT_MOST = 100;

    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private DataImportSettingsRepository $settingsRepository,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{batches: int, undos: int}
     */
    public function __invoke(ContinueImportBatches $message): array
    {
        $since = $this->clock->now()->modify(sprintf('-%d seconds', DataImportLimits::BATCH_STANDING_AFTER_SECONDS));
        $batchIds = $this->settingsRepository->isAddingPaused() ? [] : $this->batchRepository->idsRunningStandingSince($since, self::AT_MOST);
        $undoIds = $this->batchRepository->undoIdsRunningStandingSince($since, self::AT_MOST);

        foreach ($batchIds as $batchId) {
            $this->messageBus->dispatch(new ProcessImportBatch($batchId));
        }

        foreach ($undoIds as $undoId) {
            $this->messageBus->dispatch(new ProcessImportUndo($undoId));
        }

        return ['batches' => count($batchIds), 'undos' => count($undoIds)];
    }
}
