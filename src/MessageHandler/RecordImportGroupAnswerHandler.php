<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordImportGroupAnswer;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ends a group answer (RecordImportGroupAnswer) - under the answer's lock, so of two turns that both saw its last
 * chunk only one ends it, and only that one starts the "Add". What each chunk did is written by the chunk itself
 * (AnswerImportGroupChunkHandler).
 */
#[AsMessageHandler]
readonly final class RecordImportGroupAnswerHandler
{
    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether this call ended the answer (or wrote down its "Add")
     */
    public function __invoke(RecordImportGroupAnswer $message): bool
    {
        if (Uuid::isValid($message->answerId) === false) {
            return false;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->answerId));
        $answer = $this->batchRepository->findGroupAnswer($message->answerId);
        $now = $this->clock->now();

        if ($answer === null) {
            return false;
        }

        // The "Add" its end started - written after the end, by the turn that ended it
        if ($message->added) {
            $answer->finished($message->batchId === null ? null : Uuid::fromString($message->batchId), $message->addStatus, $now);

            return true;
        }

        if ($answer->isRunning() === false) {
            return false;
        }

        $answer->finished(null, null, $now);

        return true;
    }
}
