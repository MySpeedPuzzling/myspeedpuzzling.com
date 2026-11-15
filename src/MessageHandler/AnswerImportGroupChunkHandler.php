<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportGroupAnswer;
use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportGroupChunk;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\ConfirmImportRows;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAdder;
use SpeedPuzzling\Web\Services\DataImport\ImportRowTimeCheck;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One chunk of a group answer, all or nothing (AnswerImportGroupChunk). In one transaction, under the answer's lock:
 *
 * 1. the rows after the answer's cursor - the rows that wait with the reason (of the "Add" named, or of the
 *    import), or, before any "Add", the ready results the question could be about (with a time; that the file says
 *    are on MySpeedPuzzling);
 * 2. the answer for them, by the very handlers a single answer uses, called here so that their writes are this
 *    transaction's: "add it anyway" through ConfirmImportRowsHandler (row by row, only for the question a row is
 *    really asked - before an "Add": only a time the verification finds unusual now - stored with the fingerprint
 *    of what the row says); "leave out" through the pairing screens' AnswerImportRowsHandler - which checks
 *    everything before it writes anything, so a chunk it refuses (its sheet is being read again) wrote nothing and
 *    is passed over;
 * 3. the cursor moves past the chunk and the rows that took the answer are written down with it.
 *
 * A second turn waits for the lock and then reads the moved cursor: no row is answered by two turns, and none is
 * confirmed without being on the answer's list.
 */
#[AsMessageHandler]
readonly final class AnswerImportGroupChunkHandler
{
    public const int ROWS = 200;
    /** Before an "Add" every row is shown to the time verification (several statements a row) */
    public const int ROWS_BEFORE_ADD = 100;

    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private DataImportRowRepository $rowRepository,
        private ConfirmImportRowsHandler $confirmImportRows,
        private AnswerImportRowsHandler $answerImportRows,
        private ImportRowTimeCheck $timeCheck,
        private ImportRowAdder $rowAdder,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{rows: int, ends: bool}
     */
    public function __invoke(AnswerImportGroupChunk $message): array
    {
        if (Uuid::isValid($message->answerId) === false) {
            return ['rows' => 0, 'ends' => true];
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->answerId));
        $answer = $this->batchRepository->findGroupAnswer($message->answerId);

        if ($answer === null || $answer->isRunning() === false) {
            return ['rows' => 0, 'ends' => true];
        }

        $import = $answer->import;
        $importId = $import->id->toString();
        $playerId = $import->player->id->toString();
        $limit = $answer->beforeAdd ? self::ROWS_BEFORE_ADD : self::ROWS;
        $beforeAddSaid = $answer->beforeAdd && $answer->code === ImportRowRefusal::SaidAlreadyOnProfile->value;
        $rowIds = match (true) {
            $beforeAddSaid => $this->rowRepository->idsOfSaidAlreadyReady($import, $answer->lastRowId?->toString(), $limit),
            $answer->beforeAdd => $this->rowRepository->idsOfTimedReady($import, $answer->lastRowId?->toString(), $limit),
            default => $this->rowRepository->idsRefusedWith($import, $answer->code, $answer->ofBatchId?->toString(), $answer->lastRowId?->toString(), $limit),
        };
        $answered = [];
        $notToAdd = [];
        $stillWaiting = 0;

        if ($rowIds !== []) {
            if ($answer->action === DataImportGroupAnswer::ADD_ANYWAY) {
                // The other question of the rows that wait with both, answered on the same screen: "add them too"
                // goes with this word; "don't import those" leaves exactly those rows out
                $also = $answer->also ?? [];
                $alsoAdd = array_keys($also, DataImportGroupAnswer::ADD_ANYWAY, true);
                $alsoLeave = array_keys($also, DataImportGroupAnswer::LEAVE_OUT, true);
                $confirm = $rowIds;

                if ($alsoLeave !== []) {
                    $leave = [];

                    foreach ($this->rowRepository->ofImportByIds($import, $rowIds) as $rowId => $row) {
                        if (array_intersect($alsoLeave, $row->openQuestions()) !== []) {
                            $leave[] = $rowId;
                        }
                    }

                    if ($leave !== []) {
                        try {
                            ($this->answerImportRows)(new AnswerImportRows($importId, $playerId, $leave, leaveOut: true));
                            $answered = $leave;
                            $notToAdd = $leave;
                            $confirm = array_values(array_diff($rowIds, $leave));
                        } catch (ImportRowsChangedMeanwhile | ImportRowAnswerRefused) {
                            // Refused before anything was written: those rows take this answer alone and wait for
                            // the other question
                        }
                    }
                }

                if ($confirm !== []) {
                    $confirmed = ($this->confirmImportRows)(new ConfirmImportRows($importId, $playerId, $confirm, [$answer->code, ...$alsoAdd]));
                    $answered = [...$answered, ...$confirmed['confirmedRowIds']];
                    // A row that still waits with another question is not named to the "Add" at the end
                    $notToAdd = [...$notToAdd, ...$confirmed['stillWaitingRowIds']];
                    $stillWaiting = count($confirmed['stillWaitingRowIds']);
                }
            } else {
                // Before an "Add" only the rows the question is really about are left out: a time the verification
                // finds unusual; a result the engine would send back with "the file says it is on MySpeedPuzzling"
                $leave = match (true) {
                    $beforeAddSaid => $this->saidAndNotFound($import, $rowIds),
                    $answer->beforeAdd => array_keys($this->timeCheck->unusualAmong($import, $rowIds)),
                    default => $rowIds,
                };

                if ($leave !== []) {
                    try {
                        ($this->answerImportRows)(new AnswerImportRows($importId, $playerId, $leave, leaveOut: true));
                        $answered = $leave;
                    } catch (ImportRowsChangedMeanwhile | ImportRowAnswerRefused) {
                        // Refused before anything was written: the chunk's rows wait as before
                    }
                }
            }
        }

        $answer->turnDone(count($rowIds), $answered, $rowIds === [] ? null : Uuid::fromString($rowIds[array_key_last($rowIds)]), $this->clock->now(), $notToAdd, $stillWaiting);

        return ['rows' => count($rowIds), 'ends' => count($rowIds) < $limit];
    }

    /**
     * @param list<string> $rowIds
     *
     * @return list<string>
     */
    private function saidAndNotFound(DataImport $import, array $rowIds): array
    {
        $found = [];

        foreach ($this->rowRepository->ofImportByIds($import, $rowIds) as $rowId => $row) {
            if (in_array(ImportRowRefusal::SaidAlreadyOnProfile->value, $this->rowAdder->openQuestionsOf($row, false), true)) {
                $found[] = $rowId;
            }
        }

        return $found;
    }
}
