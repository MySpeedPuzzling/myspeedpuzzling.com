<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportGroupAnswer;
use SpeedPuzzling\Web\Message\AddImportRows;
use SpeedPuzzling\Web\Message\AnswerImportGroupChunk;
use SpeedPuzzling\Web\Message\ProcessImportGroupAnswer;
use SpeedPuzzling\Web\Message\RecordImportGroupAnswer;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One turn of a group answer (docs/features/data-import/group-answers.md), outside a transaction - each step is a
 * message with a transaction of its own:
 *
 * 1. **one chunk, claimed and answered in one transaction** (AnswerImportGroupChunk): two turns never take the
 *    same rows, and no row is confirmed without being on the answer's list;
 * 2. a chunk that fails is tried again - ATTEMPTS times; then the answer **ends as failed** with what it did
 *    confirm, logged once: its reason is free again (an answer that stands still without a sign of life is ended
 *    the same way by the sweeper - ContinueImportBatchesHandler);
 * 3. a full chunk: the job sends itself again, so other players' jobs take their turns in between. A short one was
 *    the last: the answer is ended (RecordImportGroupAnswer - once), and the turn that ended it starts an "Add" of
 *    **exactly the rows that took "add it anyway"** (AddImportRows by their ids - the daily guard and every rule
 *    of an "Add" apply; rows it leaves for tomorrow are ready like any other row, their question is answered).
 */
#[AsMessageHandler]
readonly final class ProcessImportGroupAnswerHandler
{
    public const int ATTEMPTS = 3;

    public function __construct(
        private MessageBusInterface $messageBus,
        private DataImportBatchRepository $batchRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessImportGroupAnswer $message): void
    {
        $this->entityManager->clear();

        $chunk = null;
        $failure = null;

        // A chunk is rolled back whole when it fails, so it can be tried again as it was: a few times, then the
        // answer ends as failed - it must not hold its reason for ever (one answer at a time per reason)
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $chunk = $this->messageBus->dispatch(new AnswerImportGroupChunk($message->answerId))
                    ->last(HandledStamp::class)
                    ?->getResult();
                $failure = null;

                break;
            } catch (\Throwable $e) {
                $this->entityManager->clear();
                $failure = $e;
            }
        }

        if ($failure !== null) {
            // What it did confirm stays confirmed (those rows are ready); the rest waits as before and can be answered
            // again. Said once, with what went wrong
            $this->messageBus->dispatch(new RecordImportGroupAnswer($message->answerId, failed: true));
            $this->logger->warning('An import\'s group answer ended as failed: its chunk failed every time', [
                'answer_id' => $message->answerId,
                'attempts' => self::ATTEMPTS,
                'exception' => $failure,
            ]);

            return;
        }

        if (is_array($chunk) === false || ($chunk['ends'] ?? true) === false) {
            // A full chunk: the next turn - other players' jobs take theirs in between
            $this->messageBus->dispatch(new ProcessImportGroupAnswer($message->answerId));

            return;
        }

        // The last chunk. Of the turns that saw it, one ends the answer - and only that one starts the "Add"
        $ended = $this->messageBus->dispatch(new RecordImportGroupAnswer($message->answerId))->last(HandledStamp::class)?->getResult();

        if ($ended !== true) {
            return;
        }

        $this->entityManager->clear();
        $answer = $this->batchRepository->findGroupAnswer($message->answerId);

        if ($answer === null || $answer->action !== DataImportGroupAnswer::ADD_ANYWAY || $answer->beforeAdd || $answer->answeredRowIds === []) {
            return;
        }

        // Exactly the rows that took the answer
        $outcome = $this->messageBus->dispatch(new AddImportRows(Uuid::uuid7()->toString(), $answer->import->id->toString(), $answer->import->player->id->toString(), $answer->answeredRowIds))
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
