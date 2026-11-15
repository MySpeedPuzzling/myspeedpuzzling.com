<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\FinishImport;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportSlots;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportClosingOutcome;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "I'm done with this import" (docs/features/data-import/upload.md "Finished"). The import becomes `done`: every
 * engine message refuses it from then on (they ask for an import that is being set up), its pages are read-only,
 * Undo works as before. Nothing of it is deleted and nothing on the profile changes.
 *
 * Not while an "Add", an Undo or an answer for many rows of it runs - what they write belongs to an import that is
 * being set up.
 */
#[AsMessageHandler]
readonly final class FinishImportHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ImportSlots $importSlots,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     */
    public function __invoke(FinishImport $message): ImportClosingOutcome
    {
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);

        // The lock the player's answers and decisions take: none of them is half-way when the import is finished
        $this->idLock->lockUntilCommit($import->id);

        if ($import->status === DataImportStatus::Discarded) {
            throw new DataImportNotFound();
        }

        if ($import->isFinished()) {
            return ImportClosingOutcome::Finished;
        }

        if ($this->importSlots->standingOf($import->id->toString())?->working === true) {
            return ImportClosingOutcome::Busy;
        }

        return $import->finish($this->clock->now()) ? ImportClosingOutcome::Finished : ImportClosingOutcome::NotFinishable;
    }
}
