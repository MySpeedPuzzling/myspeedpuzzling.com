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
 * 2. a full chunk: the job sends itself again, so other players' jobs take their turns in between. A short one was
 *    the last: the answer is ended (RecordImportGroupAnswer - once), and the turn that ended it starts an "Add" of
 *    **exactly the rows that took "add it anyway"** (AddImportRows by their ids - the daily guard and every rule
 *    of an "Add" apply; rows it leaves for tomorrow are ready like any other row, their question is answered).
 */
#[AsMessageHandler]
readonly final class ProcessImportGroupAnswerHandler
{
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

        try {
            $chunk = $this->messageBus->dispatch(new AnswerImportGroupChunk($message->answerId))
                ->last(HandledStamp::class)
                ?->getResult();
        } catch (\Throwable $e) {
            // The chunk was rolled back whole - its rows wait as before, the answer stands where it stood. Its page
            // offers "Go on" after a minute
            $this->entityManager->clear();
            $this->logger->warning('A chunk of an import\'s group answer failed - the answer stands still', [
                'answer_id' => $message->answerId,
                'exception' => $e,
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
