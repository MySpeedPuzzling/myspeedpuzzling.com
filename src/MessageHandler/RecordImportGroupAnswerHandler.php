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
 * Writes down what a turn of a group answer did (RecordImportGroupAnswer) - under the answer's lock, so of two turns
 * that ran side by side (a job delivered twice, a second chain) only the one that began where the answer stands
 * counts, and the answer ends once: only the turn that ended it starts the "Add".
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
     * @return bool whether the write was this turn's to make
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

        if ($answer->isRunning() === false || $answer->lastRowId?->toString() !== ($message->from === null ? null : strtolower($message->from))) {
            return false;
        }

        $answer->turnDone($message->rows, $message->answeredRowIds, $message->cursor === null ? null : Uuid::fromString($message->cursor), $now);

        if ($message->finish) {
            $answer->finished(null, null, $now);
        }

        return true;
    }
}
