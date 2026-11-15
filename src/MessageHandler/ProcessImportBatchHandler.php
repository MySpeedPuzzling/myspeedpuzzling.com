<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ApplyImportRow;
use SpeedPuzzling\Web\Message\BeginImportBatchTurn;
use SpeedPuzzling\Web\Message\FinishImportBatch;
use SpeedPuzzling\Web\Message\FollowUpImportBatch;
use SpeedPuzzling\Web\Message\ProcessImportBatch;
use SpeedPuzzling\Web\Message\QueueImportTurn;
use SpeedPuzzling\Web\Message\RecordImportBatchSettled;
use SpeedPuzzling\Web\Message\RecordImportRowFailed;
use SpeedPuzzling\Web\Message\WriteImportRowCustomValues;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A turn of an "Add" (docs/features/data-import/adding.md "The work"). Outside a transaction (ProcessImportBatch):
 * every row is a message of its own, handled at once in its own short transaction.
 *
 * 1. BeginImportBatchTurn: the batch runs, this job is its current turn, and adding is not paused - else the turn
 *    ends at once.
 * 2. **The first pass** (once per batch, its place kept on the batch): every queued row with a result is asked whether
 *    the player already has that result (ApplyImportRow, settle only) - before any row is added, so the answer does
 *    not depend on the rows' order.
 * 3. **Adding**: the batch's queued rows, one after the other (ApplyImportRow), then the values of the player's own
 *    fields of a row that was added (WriteImportRowCustomValues - after the row, never able to fail it).
 *    A row that throws is marked failed (RecordImportRowFailed) and the batch goes on.
 * 4. **The follow-up**, once no row is queued: what is owed for the results, FollowUpImportBatchHandler::RESULTS_PER_CHUNK
 *    at a time (FollowUpImportBatch), then FinishImportBatch.
 * 5. After DataImportLimits::ROWS_PER_TURN rows or SECONDS_PER_TURN the next turn is queued (QueueImportTurn).
 *
 * Everything is read from the rows' state, so a second delivery, a restart or a second worker repeats nothing: a row
 * leaves "queued" in the transaction that writes it, under the player's lock. **The safety switch is asked before
 * every row** (one indexed read): a paused batch stops within a row.
 *
 * Failures: the first exception of the turn in which a batch's first rows fail is logged once, as an error, with the
 * number of rows that failed in the turn - never one log line per row, never a cell's content (the message names ids
 * only).
 */
#[AsMessageHandler]
readonly final class ProcessImportBatchHandler
{
    /** Chunks of the follow-up one turn takes at most - each is up to a hundred results' statistics and awards */
    private const int FOLLOW_UP_CHUNKS_PER_TURN = 5;

    public function __construct(
        private MessageBusInterface $messageBus,
        private DataImportRowRepository $rowRepository,
        private DataImportSettingsRepository $settingsRepository,
        private EntityManagerInterface $entityManager,
        private ImportProgress $progress,
        private LoggerInterface $logger,
        /** Arguments only so that a test can end a turn early */
        private int $rowsPerTurn = DataImportLimits::ROWS_PER_TURN,
        private float $secondsPerTurn = DataImportLimits::SECONDS_PER_TURN,
    ) {
    }

    public function __invoke(ProcessImportBatch $message): void
    {
        $turn = $this->ask(new BeginImportBatchTurn($message->batchId, $message->turn));

        if (is_array($turn) === false) {
            return;
        }

        /** @var array{importId: string, playerId: string, settled: bool, settledUpTo: null|string, stopped: bool} $turn */
        $startedAt = hrtime(true);
        $rowsDone = 0;
        $failed = 0;
        $firstFailure = null;
        $over = function () use (&$rowsDone, $startedAt): bool {
            return $rowsDone >= $this->rowsPerTurn || (hrtime(true) - $startedAt) / 1_000_000_000 >= $this->secondsPerTurn;
        };
        // A batch an Undo stopped has no row left to add: the switch has nothing to hold
        $isPaused = fn (): bool => $turn['stopped'] === false && $this->settingsRepository->isAddingPaused();
        $paused = false;

        // --- The first pass: what the player already has ---
        $settled = $turn['settled'];
        $cursor = $turn['settledUpTo'];
        // Rows that left the queue, or a pass moving on: only then is there a reason for another turn
        $progressed = 0;

        while ($settled === false && $over() === false && $paused === false) {
            $rowIds = $this->rowRepository->idsQueuedInBatch($message->batchId, min(50, $this->rowsPerTurn), onlyResults: true, afterId: $cursor);

            foreach ($rowIds as $rowId) {
                if ($isPaused()) {
                    $paused = true;

                    break;
                }

                try {
                    $this->messageBus->dispatch(new ApplyImportRow($rowId, $message->batchId, $turn['playerId'], settleOnly: true));
                } catch (\Throwable $e) {
                    // Left queued: adding it fails the same way and marks it
                    $this->entityManager->clear();
                    $firstFailure ??= $e;
                }

                $cursor = $rowId;
                $rowsDone++;
                $progressed++;

                if ($over()) {
                    break;
                }
            }

            $settled = $rowIds === [];
            $this->messageBus->dispatch(new RecordImportBatchSettled($message->batchId, $cursor, $settled));
        }

        // --- Adding ---
        $cursor = null;

        while ($settled && $over() === false && $paused === false) {
            // Behind the turn's last row: a row that could not even be marked failed is not met twice in one turn
            $rowIds = $this->rowRepository->idsQueuedInBatch($message->batchId, min(50, $this->rowsPerTurn), afterId: $cursor);

            if ($rowIds === []) {
                break;
            }

            foreach ($rowIds as $rowId) {
                if ($isPaused()) {
                    $paused = true;

                    break;
                }

                $cursor = $rowId;
                $rowsDone++;

                try {
                    $outcome = $this->ask(new ApplyImportRow($rowId, $message->batchId, $turn['playerId']));
                    $progressed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $firstFailure ??= $e;
                    // The rolled-back row left its changes in the entity manager
                    $this->entityManager->clear();
                    $progressed += $this->recordFailed($rowId, $message->batchId) ? 1 : 0;

                    continue;
                }

                $customValuesOf = is_array($outcome) && is_array($outcome['customValuesOf'] ?? null) ? $outcome['customValuesOf'] : [];

                foreach ($customValuesOf as $addedRowId) {
                    if (is_string($addedRowId) === false) {
                        continue;
                    }

                    try {
                        $this->messageBus->dispatch(new WriteImportRowCustomValues($addedRowId, $turn['playerId']));
                    } catch (\Throwable $e) {
                        // The row is added; its own fields' values are left out. Never the values in a log: the
                        // exception may quote them, so only its class is named (docs/features/custom-fields.md)
                        $this->entityManager->clear();
                        $this->logger->warning('The values of a player\'s own fields of an imported row could not be saved', [
                            'batch_id' => $message->batchId,
                            'import_row_id' => $addedRowId,
                            'failure' => $e::class,
                        ]);
                    }
                }

                if ($over()) {
                    break;
                }
            }

            $this->progress->publishAdding($turn['importId'], always: false);
        }

        // Once per batch: the turn in which the batch's first rows failed. How many failed in all is on the batch,
        // and FinishImportBatch warns when it is more than a tenth
        if ($firstFailure !== null && $this->rowRepository->countsOfBatch($message->batchId)['failed'] <= $failed) {
            $this->logger->error('Rows of an import batch failed - they are marked failed, the batch goes on', [
                'batch_id' => $message->batchId,
                'import_id' => $turn['importId'],
                'rows_failed_in_turn' => $failed,
                'exception' => $firstFailure,
            ]);
        }

        if ($paused) {
            // The batch waits; myspeedpuzzling:continue-import-batches goes on once adding is switched on again
            $this->progress->publishAdding($turn['importId']);

            return;
        }

        if ($settled && $this->rowRepository->idsQueuedInBatch($message->batchId, 1) === []) {
            // --- The follow-up, then the end ---
            $followedUp = $this->followUp($message->batchId, $over);

            if ($followedUp === true) {
                $this->finish($message->batchId, $turn['importId']);

                return;
            }

            // More chunks to come: another turn. A chunk that failed is the sweeper's - not a loop's
            $progressed = $followedUp === false ? $progressed + 1 : 0;
        }

        if ($progressed > 0) {
            // The next turn - behind whatever else waits on the `imports` queue
            $this->messageBus->dispatch(QueueImportTurn::ofBatch($message->batchId));
        }

        $this->progress->publishAdding($turn['importId']);
    }

    /**
     * @param callable(): bool $over
     *
     * @return null|bool true: every result is followed up - the batch can end; false: more to do; null: a chunk failed
     */
    private function followUp(string $batchId, callable $over): null|bool
    {
        for ($chunk = 0; $chunk < self::FOLLOW_UP_CHUNKS_PER_TURN; $chunk++) {
            try {
                if ($this->ask(new FollowUpImportBatch($batchId)) === true) {
                    return true;
                }
            } catch (\Throwable $e) {
                // The chunk is rolled back and done again by the next turn
                $this->entityManager->clear();
                $this->logger->error('A chunk of an import batch\'s follow-up failed - the next turn tries again', [
                    'batch_id' => $batchId,
                    'exception' => $e,
                ]);

                return null;
            }

            if ($over()) {
                break;
            }
        }

        return false;
    }

    private function finish(string $batchId, string $importId): void
    {
        try {
            $this->messageBus->dispatch(new FinishImportBatch($batchId));
        } catch (\Throwable $e) {
            // The batch stays running; the sweeper tries again
            $this->entityManager->clear();
            $this->logger->error('An import batch could not be finished - the sweeper tries again', [
                'batch_id' => $batchId,
                'exception' => $e,
            ]);
        }

        $this->progress->publishAdding($importId);
    }

    private function recordFailed(string $rowId, string $batchId): bool
    {
        try {
            $this->messageBus->dispatch(new RecordImportRowFailed($rowId, $batchId));

            return true;
        } catch (\Throwable $e) {
            $this->entityManager->clear();
            $this->logger->error('A failed row of an import batch could not be marked - it stays queued for the sweeper', [
                'batch_id' => $batchId,
                'import_row_id' => $rowId,
                'exception' => $e,
            ]);

            return false;
        }
    }

    private function ask(object $message): mixed
    {
        return $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
