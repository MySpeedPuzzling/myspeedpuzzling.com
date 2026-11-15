<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportGroupAnswer;
use SpeedPuzzling\Web\Message\AddImportRows;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\ConfirmImportRows;
use SpeedPuzzling\Web\Message\ProcessImportGroupAnswer;
use SpeedPuzzling\Web\Message\RecordImportGroupAnswer;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportRowTimeCheck;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One turn of a group answer (docs/features/data-import/group-answers.md), outside a transaction - each step is a
 * message with a transaction of its own:
 *
 * 1. the next chunk of the group's rows after the answer's cursor: the rows that wait with the reason (of the
 *    "Add" named, or of the import) - or, before any "Add", the ready results with a time;
 * 2. the answer for the chunk: "add it anyway" through ConfirmImportRows (which takes it row by row, only for the
 *    question the row is really asked - before an "Add": only a time the verification finds unusual now - and
 *    stores it with the fingerprint of what the row says); "leave out" through the pairing screens' AnswerImportRows
 *    (a chunk it refuses - its sheet is being read again - is passed over and counted);
 * 3. what the turn did is written down (RecordImportGroupAnswer - void when another turn was there first);
 * 4. a full chunk: the job sends itself again, so other players' jobs take their turns in between. A short one
 *    ends the answer, and the turn that ended it starts an "Add" of **exactly the rows that took "add it anyway"**
 *    (AddImportRows by their ids - the daily guard and every rule of an "Add" apply; rows it leaves for tomorrow
 *    are ready like any other row, their question is answered).
 */
#[AsMessageHandler]
readonly final class ProcessImportGroupAnswerHandler
{
    public const int ROWS_PER_TURN = 200;
    /** Before an "Add" every row is shown to the time verification (several statements a row) */
    public const int ROWS_PER_TURN_BEFORE_ADD = 100;

    public function __construct(
        private MessageBusInterface $messageBus,
        private DataImportBatchRepository $batchRepository,
        private DataImportRowRepository $rowRepository,
        private ImportRowTimeCheck $timeCheck,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessImportGroupAnswer $message): void
    {
        $this->entityManager->clear();
        $answer = $this->batchRepository->findGroupAnswer($message->answerId);

        if ($answer === null || $answer->isRunning() === false) {
            return;
        }

        $import = $answer->import;
        $importId = $import->id->toString();
        $playerId = $import->player->id->toString();
        $from = $answer->lastRowId?->toString();
        $limit = $answer->beforeAdd ? self::ROWS_PER_TURN_BEFORE_ADD : self::ROWS_PER_TURN;
        $rowIds = $answer->beforeAdd
            ? $this->rowRepository->idsOfTimedReady($import, $from, $limit)
            : $this->rowRepository->idsRefusedWith($import, $answer->code, $answer->ofBatchId?->toString(), $from, $limit);
        $answered = [];

        if ($rowIds !== []) {
            try {
                if ($answer->action === DataImportGroupAnswer::ADD_ANYWAY) {
                    $confirmed = $this->messageBus->dispatch(new ConfirmImportRows($importId, $playerId, $rowIds, [$answer->code]))
                        ->last(HandledStamp::class)
                        ?->getResult();
                    $answered = is_array($confirmed) && is_array($confirmed['confirmedRowIds'] ?? null)
                        ? array_values(array_filter($confirmed['confirmedRowIds'], is_string(...)))
                        : [];
                } else {
                    // Before an "Add" only the times the verification finds unusual are left out
                    $leave = $answer->beforeAdd ? array_keys($this->timeCheck->unusualAmong($import, $rowIds)) : $rowIds;

                    if ($leave !== []) {
                        $this->messageBus->dispatch(new AnswerImportRows($importId, $playerId, $leave, leaveOut: true));
                        $answered = $leave;
                    }
                }
            } catch (\Throwable $e) {
                // The chunk did not take the answer (its sheet is being read again, rows changed under it): passed
                // over and counted - the rows wait as before and can be answered again
                $this->entityManager->clear();
                $this->logger->info('A chunk of an import\'s group answer was passed over', [
                    'answer_id' => $message->answerId,
                    'import_id' => $importId,
                    'exception' => $e,
                ]);
            }
        }

        $ends = count($rowIds) < $limit;
        $mine = $this->messageBus->dispatch(new RecordImportGroupAnswer(
            $message->answerId,
            from: $from,
            rows: count($rowIds),
            answeredRowIds: $answered,
            cursor: $rowIds === [] ? null : $rowIds[array_key_last($rowIds)],
            finish: $ends,
        ))->last(HandledStamp::class)?->getResult();

        // Another turn was there first: this one is void
        if ($mine !== true) {
            return;
        }

        if ($ends === false) {
            $this->messageBus->dispatch(new ProcessImportGroupAnswer($message->answerId));

            return;
        }

        $this->entityManager->clear();
        $ended = $this->batchRepository->findGroupAnswer($message->answerId);

        if ($ended === null || $ended->action !== DataImportGroupAnswer::ADD_ANYWAY || $ended->beforeAdd || $ended->answeredRowIds === []) {
            return;
        }

        // Exactly the rows that took the answer - and only once: the answer ended in this turn
        $outcome = $this->messageBus->dispatch(new AddImportRows(Uuid::uuid7()->toString(), $importId, $playerId, $ended->answeredRowIds))
            ->last(HandledStamp::class)
            ?->getResult();

        if ($outcome instanceof AddImportRowsOutcome) {
            $this->messageBus->dispatch(new RecordImportGroupAnswer(
                $message->answerId,
                added: true,
                batchId: $outcome->isStarted() ? $outcome->id : null,
                addStatus: $outcome->isStarted() ? null : $outcome->status,
            ));
        }
    }
}
