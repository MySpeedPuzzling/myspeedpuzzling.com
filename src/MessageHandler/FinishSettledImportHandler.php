<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\FinishSettledImport;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportSlots;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The daily sweep finishes an import nothing is left to add from (docs/features/data-import/upload.md "Finished"):
 * it took rows, nothing runs, no row is ready - and either nothing waits for the player, or what waits has waited
 * for DataImportLimits::FINISHED_AFTER_WAITING_DAYS days without anything happening to the import
 * (ImportStanding::isFinishedBySweep()). Looked at again here, under the locks an "Add" and the player's answers
 * take. The player can go on with it whenever they like.
 */
#[AsMessageHandler]
readonly final class FinishSettledImportHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ImportSlots $importSlots,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(FinishSettledImport $message): bool
    {
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isOwnedBy($message->playerId) === false) {
            return false;
        }

        $this->idLock->lockUntilCommit($import->id);
        $now = $this->clock->now();

        if ($this->importSlots->standingOf($import->id->toString())?->isFinishedBySweep($now) !== true) {
            return false;
        }

        return $import->finish($now);
    }
}
