<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\ConfirmImportRows;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The player's "add it anyway" and "count each row on its own" (ConfirmImportRows), under the import's lock - the
 * lock every answer about its rows takes (AnswerImportRowsHandler). Everything is checked before anything is
 * written:
 *
 * - the import is the player's and rows can be added from it, every row is the import's (else "not found");
 * - only the four questions a player may wave through are taken (anything else: "not found" - a page never sends it);
 * - a row that an "Add" holds or that already made something is not what the page showed (ImportRowsChangedMeanwhile).
 *
 * @return int the rows whose answer changed
 */
#[AsMessageHandler]
readonly final class ConfirmImportRowsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     * @throws ImportRowsChangedMeanwhile
     */
    public function __invoke(ConfirmImportRows $message): int
    {
        if (Uuid::isValid($message->importId) === false) {
            throw new DataImportNotFound();
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);

        if ($import->isBeingSetUp() === false || $import->cellsWipedAt !== null) {
            throw new DataImportNotFound();
        }

        $codes = array_values(array_unique($message->addAnyway));

        if (array_diff($codes, GetImportAddScreen::WAVABLE) !== []) {
            throw new DataImportNotFound();
        }

        $rowIds = array_values(array_unique(array_map(strtolower(...), $message->rowIds)));

        if (count($rowIds) > ImportRowAnswers::MAX_ROWS || ($codes !== [] && $rowIds === [])) {
            throw new DataImportNotFound();
        }

        $rows = $this->dataImportRowRepository->ofImportByIds($import, $rowIds);

        if (count($rows) !== count($rowIds)) {
            throw new DataImportNotFound();
        }

        $equal = [];

        if ($message->separate !== null) {
            if ($message->equalGroupOf === null || $this->dataImportRowRepository->ofImportByIds($import, [$message->equalGroupOf]) === []) {
                throw new DataImportNotFound();
            }

            $equal = $this->dataImportRowRepository->equalRowsOf($import, $message->equalGroupOf);
        }

        foreach ([...array_values($rows), ...$equal] as $row) {
            // Only a row that waits takes a word: one an "Add" holds, or that made something, is past it
            if ($row->state !== DataImportRowState::Waiting || in_array($row->kind, [DataImportRowKind::Result, DataImportRowKind::Both], true) === false) {
                throw new ImportRowsChangedMeanwhile();
            }
        }

        // Checked - from here on nothing refuses
        $changed = 0;

        if ($codes !== []) {
            foreach ($rows as $row) {
                $row->addAnyway($codes);
                $changed++;
            }
        }

        foreach ($equal as $row) {
            if ($row->addAsSeparate !== $message->separate) {
                $row->countOnItsOwn($message->separate === true);
                $changed++;
            }
        }

        if ($changed > 0) {
            $import->workedOn($this->clock->now());
        }

        return $changed;
    }
}
