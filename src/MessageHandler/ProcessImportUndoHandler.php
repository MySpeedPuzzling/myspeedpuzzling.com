<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ProcessImportUndo;
use SpeedPuzzling\Web\Message\QueueImportTurn;
use SpeedPuzzling\Web\Message\UndoImportRow;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A turn of an Undo (docs/features/data-import/adding.md "Undo"), the way ProcessImportBatchHandler takes a turn of
 * an "Add": outside a transaction, every row a message of its own in its own transaction (UndoImportRow), driven by
 * the rows' mark (`undo_id`) - delivered twice or started again it takes back no row twice. A row that throws is
 * given up (it stays added, free for another Undo) and counted; the first exception of a turn is logged once.
 */
#[AsMessageHandler]
readonly final class ProcessImportUndoHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private DataImportBatchRepository $batchRepository,
        private DataImportRowRepository $rowRepository,
        private EntityManagerInterface $entityManager,
        private ImportProgress $progress,
        private LoggerInterface $logger,
        /** Arguments only so that a test can end a turn early */
        private int $rowsPerTurn = DataImportLimits::ROWS_PER_TURN,
        private float $secondsPerTurn = DataImportLimits::SECONDS_PER_TURN,
    ) {
    }

    public function __invoke(ProcessImportUndo $message): void
    {
        $this->entityManager->clear();
        $undo = $this->batchRepository->findUndo($message->undoId);

        // A job of an older turn is a second chain (the sweeper queued a newer one while this waited): void
        if ($undo === null || $undo->isRunning() === false || $undo->isTurn($message->turn) === false) {
            return;
        }

        $importId = $undo->import->id->toString();
        $startedAt = hrtime(true);
        $rowsDone = 0;
        $progressed = 0;
        $failed = 0;
        $firstFailure = null;
        $over = function () use (&$rowsDone, $startedAt): bool {
            return $rowsDone >= $this->rowsPerTurn || (hrtime(true) - $startedAt) / 1_000_000_000 >= $this->secondsPerTurn;
        };

        while ($over() === false) {
            $rowIds = $this->rowRepository->idsOfUndo($message->undoId, min(50, $this->rowsPerTurn));

            if ($rowIds === []) {
                break;
            }

            $progressedBefore = $progressed;

            foreach ($rowIds as $rowId) {
                $rowsDone++;

                try {
                    $this->messageBus->dispatch(new UndoImportRow($message->undoId, $rowId));
                    $progressed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $firstFailure ??= $e;
                    $this->entityManager->clear();

                    try {
                        $this->messageBus->dispatch(new UndoImportRow($message->undoId, $rowId, failed: true));
                        $progressed++;
                    } catch (\Throwable) {
                        $this->entityManager->clear();
                    }
                }

                if ($over()) {
                    break;
                }
            }

            // Rows that could not even be given up: the sweeper's, not a loop's
            if ($progressed === $progressedBefore) {
                break;
            }
        }

        if ($firstFailure !== null) {
            $this->logger->error('Rows of an import\'s Undo failed - they stay added', [
                'undo_id' => $message->undoId,
                'import_id' => $importId,
                'rows_failed_in_turn' => $failed,
                'exception' => $firstFailure,
            ]);
        }

        if ($this->rowRepository->idsOfUndo($message->undoId, 1) === []) {
            try {
                $this->messageBus->dispatch(new UndoImportRow($message->undoId, null, finish: true));
            } catch (\Throwable $e) {
                $this->entityManager->clear();
                $this->logger->error('An import\'s Undo could not be finished - the sweeper tries again', [
                    'undo_id' => $message->undoId,
                    'exception' => $e,
                ]);
            }
        } elseif ($progressed > 0) {
            $this->messageBus->dispatch(QueueImportTurn::ofUndo($message->undoId));
        }

        $this->progress->publishAdding($importId);
    }
}
