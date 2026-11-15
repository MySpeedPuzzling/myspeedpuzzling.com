<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\GoOnWithImport;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportSlots;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportClosingOutcome;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Go on with this import" (docs/features/data-import/upload.md "Finished"): a finished import is being set up
 * again, exactly as it stood. It takes one of the player's places - counted under the lock every start of an
 * import takes, by the one rule (ImportSlots).
 */
#[AsMessageHandler]
readonly final class GoOnWithImportHandler
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
    public function __invoke(GoOnWithImport $message): ImportClosingOutcome
    {
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);

        // Two starts of one player at once must not both pass the count below (StartImportHandler)
        $this->idLock->lockUntilCommit($import->player->id);

        if ($import->status === DataImportStatus::Discarded) {
            throw new DataImportNotFound();
        }

        if ($import->isFinished() === false) {
            // Sent twice: it goes on already
            return in_array($import->status, [DataImportStatus::SettingUp, DataImportStatus::Reviewing], true)
                ? ImportClosingOutcome::GoesOn
                : ImportClosingOutcome::CanNotGoOn;
        }

        if ($import->canGoOn() === false) {
            return ImportClosingOutcome::CanNotGoOn;
        }

        if ($this->importSlots->isFullFor($import->player->id->toString())) {
            return ImportClosingOutcome::NoFreePlace;
        }

        $import->goOn($this->clock->now());

        return ImportClosingOutcome::GoesOn;
    }
}
