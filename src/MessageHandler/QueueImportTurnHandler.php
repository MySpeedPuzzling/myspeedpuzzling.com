<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ProcessImportBatch;
use SpeedPuzzling\Web\Message\ProcessImportUndo;
use SpeedPuzzling\Web\Message\QueueImportTurn;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Under the batch's (or the Undo's) lock, in one transaction: the turn's number is raised and the job with the new
 * number is queued - with the Doctrine transport in this very transaction. Two askers at once get two numbers, and
 * only the higher one's job runs.
 */
#[AsMessageHandler]
readonly final class QueueImportTurnHandler
{
    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(QueueImportTurn $message): void
    {
        $id = $message->batchId ?? $message->undoId;

        if ($id === null || Uuid::isValid($id) === false) {
            return;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($id));
        $now = $this->clock->now();

        if ($message->batchId !== null) {
            $batch = $this->batchRepository->find($message->batchId);

            if ($batch !== null && $batch->isRunning()) {
                $this->messageBus->dispatch(new ProcessImportBatch($message->batchId, $batch->nextTurn($now)));
            }

            return;
        }

        $undo = $this->batchRepository->findUndo($id);

        if ($undo !== null && $undo->isRunning()) {
            $this->messageBus->dispatch(new ProcessImportUndo($id, $undo->nextTurn($now)));
        }
    }
}
