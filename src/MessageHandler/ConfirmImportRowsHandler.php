<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\ConfirmImportRows;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\DataImport\ImportRowTimeCheck;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The player's "add it anyway" and "count each row on its own" (ConfirmImportRows), under the import's lock - the
 * lock every answer about its rows takes (AnswerImportRowsHandler).
 *
 * Refused whole ("not found") only for what a page never sends: somebody else's import, an import rows can not be
 * added from, a row that is not the import's, a code that may not be waved through. Otherwise **each row on its
 * own**: the word is written where it still makes sense and the other rows are passed over and counted -
 *
 * - a row that does not wait any more (an "Add" holds it, it made something, it was left out) is past its words;
 * - "add it anyway" is taken only for the question the row was really asked: the reason it waits with
 *   (`failure`) - or, for an unusual time before any "Add", the time verification's own judgement of the row now
 *   (ImportRowTimeCheck). A form can not wave a question through on rows nobody asked it about;
 * - the word is given for the row as it reads now and the puzzle it is paired with (DataImportRow::addAnyway()).
 *
 * @return array{confirmed: int, skipped: int, confirmedRowIds: list<string>, lookedAt: int, lastRowId: null|string}
 */
#[AsMessageHandler]
readonly final class ConfirmImportRowsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private ImportRowTimeCheck $timeCheck,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{confirmed: int, skipped: int, confirmedRowIds: list<string>, lookedAt: int, lastRowId: null|string}
     *
     * @throws DataImportNotFound
     */
    public function __invoke(ConfirmImportRows $message): array
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

        // A whole group: the next chunk of the rows that wait with the reason - worked out here, never sent
        if ($message->refusedWith !== null) {
            if ($codes !== [$message->refusedWith]) {
                throw new DataImportNotFound();
            }

            $rowIds = $this->dataImportRowRepository->idsRefusedWith($import, $message->refusedWith, $message->ofBatchId, $message->afterRowId, max(1, min($message->limit, ImportRowAnswers::MAX_ROWS)));

            if ($rowIds === []) {
                return ['confirmed' => 0, 'skipped' => 0, 'confirmedRowIds' => [], 'lookedAt' => 0, 'lastRowId' => null];
            }
        }

        if (count($rowIds) > ImportRowAnswers::MAX_ROWS || ($codes !== [] && $rowIds === [])) {
            throw new DataImportNotFound();
        }

        $rows = $this->dataImportRowRepository->ofImportByIds($import, $rowIds);

        if (count($rows) !== count($rowIds)) {
            throw new DataImportNotFound();
        }

        $confirmed = 0;
        $skipped = 0;
        $confirmedRowIds = [];

        if ($codes !== []) {
            $puzzleIds = $this->dataImportRowRepository->cataloguePuzzleIdsOf($import, $rowIds);
            $asked = [];
            $notAskedYet = [];

            foreach ($rows as $rowId => $row) {
                if ($row->state !== DataImportRowState::Waiting || in_array($row->kind, [DataImportRowKind::Result, DataImportRowKind::Both], true) === false || isset($puzzleIds[$rowId]) === false) {
                    continue;
                }

                if ($row->failure !== null) {
                    // The question the row waits with - and no other
                    $asked[$rowId] = array_values(array_intersect($codes, [$row->failure]));
                } elseif (in_array(ImportRowRefusal::UnusualTime->value, $codes, true)) {
                    $notAskedYet[] = $rowId;
                }
            }

            // Before any "Add": only a time the verification itself finds unusual now is anything to confirm
            foreach (array_keys($this->timeCheck->unusualAmong($import, $notAskedYet)) as $rowId) {
                $asked[$rowId] = [ImportRowRefusal::UnusualTime->value];
            }

            foreach ($rows as $rowId => $row) {
                if (($asked[$rowId] ?? []) === []) {
                    $skipped++;

                    continue;
                }

                $row->addAnyway($asked[$rowId], $puzzleIds[$rowId]);
                $confirmedRowIds[] = $rowId;
                $confirmed++;
            }
        }

        if ($message->separate !== null) {
            if ($message->equalGroupOf === null || $this->dataImportRowRepository->ofImportByIds($import, [$message->equalGroupOf]) === []) {
                throw new DataImportNotFound();
            }

            // Only the rows that still wait (equalRowsOf()): the others are past the question
            foreach ($this->dataImportRowRepository->equalRowsOf($import, $message->equalGroupOf) as $row) {
                if ($row->addAsSeparate !== $message->separate) {
                    $row->countOnItsOwn($message->separate);
                    $confirmed++;
                }
            }
        }

        if ($confirmed > 0) {
            $import->workedOn($this->clock->now());
        }

        return [
            'confirmed' => $confirmed,
            'skipped' => $skipped,
            'confirmedRowIds' => $confirmedRowIds,
            'lookedAt' => count($rowIds),
            'lastRowId' => $rowIds === [] ? null : $rowIds[array_key_last($rowIds)],
        ];
    }
}
