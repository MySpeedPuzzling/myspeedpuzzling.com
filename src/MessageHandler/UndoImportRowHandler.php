<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportUndo;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\UndoImportRow;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PuzzleRedirectRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAdder;
use SpeedPuzzling\Web\Services\DataImport\ImportWriteLock;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedRowWriter;
use SpeedPuzzling\Web\Value\DataImportRowState;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One row of an Undo, in its own transaction, under the player's import lock
 * (docs/features/data-import/adding.md "Undo" - what it restores and what it keeps). Exactly what the row recorded
 * that it made, nothing found by looking around:
 *
 * - **the result** the row made is deleted through PuzzleSolvingTimeRepository::delete() - the one way a result is
 *   deleted: graveyard, tombstones for API clients, the statistics of its puzzle - and then what the normal delete
 *   does (DeletePuzzleSolvingTimeHandler): achievements again for its people, XP through CompensateXpForDeletedSolve.
 *   **Not when somebody changed it since the import** (its time, day, puzzle or people are not what the row wrote):
 *   then the whole row is kept as it is and counted - unless the player asked for chosen rows "also the changed
 *   ones". A result that is gone already is nothing to take back;
 * - **the collection item** the row created is removed - unless it was moved to another collection since (kept);
 * - **the wish list item** the row created is removed;
 * - **wish list items the row removed** by the player's own setting ("remove when solved / when added to my
 *   collection") come back, with their id and date - unless the puzzle is on the list again or gone;
 * - the values of the player's own fields go with the result and the item they were written on;
 * - the row waits again and can be added again.
 *
 * Not taken back, on purpose: a ready-made field the import added for the player, and empty pairs/teams (the
 * existing cleanup removes them).
 */
#[AsMessageHandler]
readonly final class UndoImportRowHandler
{
    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private DataImportRowRepository $rowRepository,
        private PuzzleSolvingTimeRepository $solvingTimeRepository,
        private CollectionItemRepository $collectionItemRepository,
        private WishListItemRepository $wishListItemRepository,
        private PuzzleRedirectRepository $puzzleRedirectRepository,
        private NotificationRepository $notificationRepository,
        private EntityManagerInterface $entityManager,
        private ImportWriteLock $lock,
        private UnlistedRowWriter $unlistedRows,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UndoImportRow $message): void
    {
        $undo = $this->batchRepository->findUndo($message->undoId);

        if ($undo === null) {
            return;
        }

        // The player's lock first, then the Undo as the database has it now: two chains of turns (a job delivered
        // again) take turns here, and neither counts on top of a number the other has not committed yet
        $player = $undo->import->player;
        $this->lock->lockUntilCommit($player->id->toString());
        $this->entityManager->refresh($undo);

        if ($undo->isRunning() === false) {
            return;
        }

        $now = $this->clock->now();

        if ($message->finish) {
            $this->finish($undo, $now);

            return;
        }

        if ($message->rowId === null) {
            return;
        }

        if ($message->failed) {
            $this->rowRepository->releaseFromUndo($message->rowId, $message->undoId);
            $undo->rowFailed($now);

            return;
        }

        $row = $this->rowRepository->find($message->rowId);

        if ($row === null || $row->undoId === null || $row->undoId->equals($undo->id) === false || $row->state !== DataImportRowState::Added) {
            return;
        }

        $outcome = [
            'undone' => false,
            'resultDeleted' => false,
            'resultKeptEdited' => false,
            'resultWasEdited' => false,
            'collectionItemRemoved' => false,
            'collectionItemKept' => false,
            'wishListItemRemoved' => false,
            'wishListItemsRestored' => 0,
        ];

        // A row whose puzzle is kept as an unlisted puzzle made unlisted things only: they go - unless the puzzle was
        // paired since and they are real ones now. Then the row stays as it is: the pairing's own Undo comes first
        if (isset($row->made['unlisted'])) {
            if ($this->unlistedRows->takeBack($row, $player)) {
                $outcome['undone'] = true;
                $row->undone();
            } else {
                $outcome['resultKeptEdited'] = true;
                $row->keptByUndo($undo->id);
            }

            $undo->rowDone($outcome, $now);

            return;
        }

        $solvingTime = $row->resultMade && $row->solvingTimeId !== null
            ? $this->entityManager->find(PuzzleSolvingTime::class, $row->solvingTimeId)
            : null;

        if ($solvingTime !== null) {
            $outcome['resultWasEdited'] = $this->isChangedSince($solvingTime, $row->made['result'] ?? null);

            if ($outcome['resultWasEdited'] && $undo->includeEdited === false) {
                // Changed since the import: the row and all it made stay as they are
                $outcome['resultKeptEdited'] = true;
                $row->keptByUndo($undo->id);
                $undo->rowDone($outcome, $now);

                return;
            }

            $this->deleteResult($solvingTime, $player);
            $outcome['resultDeleted'] = true;
        }

        if ($row->collectionItemId !== null) {
            $item = $this->entityManager->find(CollectionItem::class, $row->collectionItemId);

            if ($item !== null && $item->player->id->equals($player->id)) {
                if ($item->collection === null) {
                    $this->collectionItemRepository->delete($item);
                    $outcome['collectionItemRemoved'] = true;
                } else {
                    $outcome['collectionItemKept'] = true;
                }
            }
        }

        if ($row->wishListItemId !== null) {
            $wish = $this->entityManager->find(WishListItem::class, $row->wishListItemId);

            if ($wish !== null && $wish->player->id->equals($player->id)) {
                $this->wishListItemRepository->delete($wish);
                $outcome['wishListItemRemoved'] = true;
            }
        }

        $outcome['wishListItemsRestored'] = $this->restoreWishListItems($row, $player);
        $outcome['undone'] = true;

        $row->undone();
        $undo->rowDone($outcome, $now);
    }

    /**
     * Whether somebody changed the result since the row wrote it: its time, day, people - or its puzzle, **unless the
     * puzzle the row wrote was merged into the one the result is on now** (`puzzle_redirect`): a merge moves the
     * result, the player changed nothing.
     */
    private function isChangedSince(PuzzleSolvingTime $solvingTime, mixed $written): bool
    {
        $now = ImportRowAdder::resultFacts($solvingTime);

        if (is_array($written) === false) {
            return true;
        }

        $writtenPuzzleId = $written['puzzleId'] ?? null;

        if (is_string($writtenPuzzleId) && $writtenPuzzleId !== $now['puzzleId']) {
            $mergedInto = $this->puzzleRedirectRepository->findByOldPuzzleId($writtenPuzzleId)?->survivorPuzzleId->toString();

            if ($mergedInto !== $now['puzzleId']) {
                return true;
            }
        }

        return ($written['seconds'] ?? null) !== $now['seconds']
            || ($written['day'] ?? null) !== $now['day']
            || ($written['teamId'] ?? null) !== $now['teamId'];
    }

    /**
     * What DeletePuzzleSolvingTimeHandler does around the one delete - without its "may this player delete it?":
     * the result is the import's own.
     */
    private function deleteResult(PuzzleSolvingTime $solvingTime, Player $owner): void
    {
        $puzzleId = $solvingTime->puzzle->id->toString();
        $solvingTimeId = $solvingTime->id->toString();
        $memberPlayerIds = $solvingTime->memberPlayerIds();

        $this->solvingTimeRepository->delete($solvingTime, SolvingTimeDeletedVia::ImportUndo);

        // The owner's achievements once, when the Undo is through (finish()) - a registered partner's here
        foreach ($memberPlayerIds as $memberPlayerId) {
            if ($memberPlayerId !== $owner->id->toString()) {
                $this->messageBus->dispatch(new RecalculateBadgesForPlayer($memberPlayerId));
            }
        }

        $this->messageBus->dispatch(new CompensateXpForDeletedSolve($solvingTimeId, $puzzleId, $memberPlayerIds));
    }

    private function restoreWishListItems(DataImportRow $row, Player $player): int
    {
        $removed = $row->made['removedWishListItems'] ?? null;
        $restored = 0;

        foreach (is_array($removed) ? $removed : [] as $item) {
            if (is_array($item) === false || is_string($item['id'] ?? null) === false || is_string($item['puzzleId'] ?? null) === false) {
                continue;
            }

            $puzzle = $this->entityManager->find(Puzzle::class, $item['puzzleId']);

            // The puzzle is gone, or it is on the list again (one item per player and puzzle)
            if ($puzzle === null || $this->wishListItemRepository->findByPlayerAndPuzzle($player, $puzzle) !== null) {
                continue;
            }

            $addedAt = is_string($item['addedAt'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $item['addedAt']) : false;
            $id = Uuid::isValid($item['id']) && $this->entityManager->find(WishListItem::class, $item['id']) === null
                ? Uuid::fromString($item['id'])
                : Uuid::uuid7();

            $this->wishListItemRepository->save(new WishListItem(
                $id,
                $player,
                $puzzle,
                ($item['removeOnCollectionAdd'] ?? true) === true,
                $addedAt === false ? $this->clock->now() : $addedAt,
            ));
            $restored++;
        }

        return $restored;
    }

    private function finish(DataImportUndo $undo, DateTimeImmutable $now): void
    {
        if ($this->rowRepository->idsOfUndo($undo->id->toString(), 1) !== []) {
            return;
        }

        $undo->finished($now);
        $undo->import->workedOn($now);

        if ($undo->scope === DataImportUndoScope::Batch && $undo->batchId !== null) {
            $this->batchRepository->find($undo->batchId->toString())?->undone($now);
        }

        if ($undo->resultsDeleted > 0) {
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer($undo->import->player->id->toString()));
        }

        // An admin took something back: the player is told once, in the app, with the reason - no e-mail. The player's
        // own Undo tells nothing: they pressed the button
        if ($undo->byAdmin && $undo->rowsUndone > 0) {
            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $undo->import->player,
                NotificationType::ImportRowsTakenBack,
                $now,
                targetDataImportUndo: $undo,
            ));
        }
    }
}
