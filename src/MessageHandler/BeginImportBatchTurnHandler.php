<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\BeginImportBatchTurn;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Whether a turn of a batch may start: the batch runs and adding is not paused (the safety switch - a paused batch
 * waits where it is; the sweeper continues it once adding is on again). The turn is noted on the batch, so the
 * sweeper leaves a batch alone that is being worked on.
 */
#[AsMessageHandler]
readonly final class BeginImportBatchTurnHandler
{
    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private DataImportSettingsRepository $settingsRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return null|array{importId: string, playerId: string, settled: bool, settledUpTo: null|string}
     */
    public function __invoke(BeginImportBatchTurn $message): null|array
    {
        $batch = $this->batchRepository->find($message->batchId);

        if ($batch === null || $batch->isRunning() === false || $this->settingsRepository->isAddingPaused()) {
            return null;
        }

        $batch->workedOn($this->clock->now());

        return [
            'importId' => $batch->import->id->toString(),
            'playerId' => $batch->import->player->id->toString(),
            'settled' => $batch->settledAt !== null,
            'settledUpTo' => $batch->settledUpTo?->toString(),
        ];
    }
}
