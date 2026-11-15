<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportUndo;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\ProcessImportUndo;
use SpeedPuzzling\Web\Message\QueueImportTurn;
use SpeedPuzzling\Web\Message\UndoImportRows;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportWriteLock;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Undo is asked for (docs/features/data-import/adding.md "Undo"). Serialized per import, in one transaction:
 *
 * 1. the import is the player's - or anybody's for an admin, who gives a reason (the controller is admins-only);
 * 2. the same request id as before answers that Undo;
 * 3. **whatever the scope**, every "Add" of the import that still runs is stopped first: its rows that wait for their
 *    turn wait again, and its next turn ends it with what it added so far - telling the player nothing (so neither a
 *    running batch nor the pause switch stands in the way of taking something back);
 * 4. the rows of the selection that made something are marked for the Undo (one statement - a row is taken back by
 *    one Undo only) and the Undo goes to the `imports` worker. Nothing is deleted here.
 *
 * Undo is never paused by the safety switch and has no daily limit.
 */
#[AsMessageHandler]
readonly final class UndoImportRowsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportRowRepository $rowRepository,
        private DataImportBatchRepository $batchRepository,
        private ImportWriteLock $lock,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     */
    public function __invoke(UndoImportRows $message): AddImportRowsOutcome
    {
        if (Uuid::isValid($message->requestId) === false || Uuid::isValid($message->requestedByPlayerId) === false) {
            throw new DataImportNotFound();
        }

        if ($message->asAdmin && trim((string) $message->reason) === '') {
            throw new InvalidArgumentException('An admin gives a reason for an Undo.');
        }

        $import = $message->asAdmin
            ? ($this->dataImportRepository->find($message->importId) ?? throw new DataImportNotFound())
            : $this->dataImportRepository->getOwnedBy($message->importId, $message->requestedByPlayerId);

        // Rows of the player are written under this lock (ImportRowAdder): a row of a batch that is being stopped here is
        // either committed before the rows are marked below, or finds itself no longer queued
        $this->lock->lockUntilCommit($import->player->id->toString());

        $sameRequest = $this->batchRepository->findUndoByRequestId($message->requestId);

        if ($sameRequest !== null) {
            if ($sameRequest->import->id->equals($import->id) === false) {
                throw new DataImportNotFound();
            }

            return new AddImportRowsOutcome(AddImportRowsOutcome::SAME_REQUEST, $sameRequest->id->toString(), $sameRequest->rowsRequested);
        }

        $batchId = null;
        $rowIds = null;

        if ($message->scope === DataImportUndoScope::Batch) {
            $batch = $message->batchId === null ? null : $this->batchRepository->find($message->batchId);

            if ($batch === null || $batch->import->id->equals($import->id) === false) {
                throw new DataImportNotFound();
            }

            $batchId = $batch->id->toString();
        }

        // Whatever is taken back - rows, a batch, everything: an "Add" of the import that still runs is stopped
        // first. Its rows that wait for their turn wait again as before the "Add" (under the player's lock: none of
        // them is half-way), and one more turn follows up what it did add and ends it - without telling the player
        // "N added"
        foreach ($this->batchRepository->runningOfImport($import) as $running) {
            $this->rowRepository->releaseQueuedOfBatch($running->id->toString());
            $running->stoppedByUndo();
            $this->messageBus->dispatch(QueueImportTurn::ofBatch($running->id->toString()));
        }

        if ($message->scope === DataImportUndoScope::Rows) {
            $rowIds = array_values(array_unique(array_map(strtolower(...), array_filter($message->rowIds ?? [], Uuid::isValid(...)))));
        }

        $undoId = Uuid::uuid7();
        $rows = $this->rowRepository->claimForUndo($import, $undoId->toString(), $batchId, $rowIds);

        if ($rows === 0) {
            return new AddImportRowsOutcome(AddImportRowsOutcome::NOTHING);
        }

        $now = $this->clock->now();
        $this->batchRepository->saveUndo(new DataImportUndo(
            id: $undoId,
            import: $import,
            requestId: Uuid::fromString($message->requestId),
            scope: $message->scope,
            batchId: $batchId === null ? null : Uuid::fromString($batchId),
            rowsRequested: $rows,
            requestedByPlayerId: Uuid::fromString($message->requestedByPlayerId),
            byAdmin: $message->asAdmin,
            // Only the player's own word about rows they chose themselves
            includeEdited: $message->includeEdited && $message->asAdmin === false && $message->scope === DataImportUndoScope::Rows,
            reason: $message->reason === null || trim($message->reason) === '' ? null : mb_substr(trim($message->reason), 0, 1000),
            startedAt: $now,
        ));
        $import->workedOn($now);

        $this->messageBus->dispatch(new ProcessImportUndo($undoId->toString(), 1));

        return new AddImportRowsOutcome(AddImportRowsOutcome::STARTED, $undoId->toString(), $rows);
    }
}
