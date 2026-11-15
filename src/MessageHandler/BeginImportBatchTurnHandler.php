<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\BeginImportBatchTurn;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Whether a turn of a batch may start: the batch runs, the job is the batch's current turn (a job of an older turn is
 * a second chain - void), and adding is not paused (the safety switch - a paused batch waits where it is; the sweeper
 * continues it once adding is on again). A batch an Undo stopped has nothing left to add, so the switch does not
 * hold up its end. The turn is noted on the batch, so the sweeper leaves a batch alone that is being worked on.
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
     * @return null|array{importId: string, playerId: string, settled: bool, settledUpTo: null|string, stopped: bool}
     */
    public function __invoke(BeginImportBatchTurn $message): null|array
    {
        $batch = $this->batchRepository->find($message->batchId);

        if ($batch === null || $batch->isRunning() === false || $batch->isTurn($message->turn) === false) {
            return null;
        }

        if ($batch->endedByUndo === false && $this->settingsRepository->isAddingPaused()) {
            return null;
        }

        $batch->workedOn($this->clock->now());

        return [
            'importId' => $batch->import->id->toString(),
            'playerId' => $batch->import->player->id->toString(),
            'settled' => $batch->settledAt !== null,
            'settledUpTo' => $batch->settledUpTo?->toString(),
            'stopped' => $batch->endedByUndo,
        ];
    }
}
