<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportGroupAnswer;
use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportGroupChunk;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\ConfirmImportRows;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportRowTimeCheck;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One chunk of a group answer, all or nothing (AnswerImportGroupChunk). In one transaction, under the answer's lock:
 *
 * 1. the rows after the answer's cursor - the rows that wait with the reason (of the "Add" named, or of the
 *    import), or, before any "Add", the ready results with a time;
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
        $rowIds = $answer->beforeAdd
            ? $this->rowRepository->idsOfTimedReady($import, $answer->lastRowId?->toString(), $limit)
            : $this->rowRepository->idsRefusedWith($import, $answer->code, $answer->ofBatchId?->toString(), $answer->lastRowId?->toString(), $limit);
        $answered = [];

        if ($rowIds !== []) {
            if ($answer->action === DataImportGroupAnswer::ADD_ANYWAY) {
                $answered = ($this->confirmImportRows)(new ConfirmImportRows($importId, $playerId, $rowIds, [$answer->code]))['confirmedRowIds'];
            } else {
                // Before an "Add" only the times the verification finds unusual are left out
                $leave = $answer->beforeAdd ? array_keys($this->timeCheck->unusualAmong($import, $rowIds)) : $rowIds;

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

        $answer->turnDone(count($rowIds), $answered, $rowIds === [] ? null : Uuid::fromString($rowIds[array_key_last($rowIds)]), $this->clock->now());

        return ['rows' => count($rowIds), 'ends' => count($rowIds) < $limit];
    }
}
