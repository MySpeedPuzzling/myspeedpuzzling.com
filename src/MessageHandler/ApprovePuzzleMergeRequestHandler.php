<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleMergeAudit;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Entity\Tag;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleMergeSnapshotBuilder;
use SpeedPuzzling\Web\Value\NotificationType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ApprovePuzzleMergeRequestHandler
{
    public function __construct(
        private PuzzleMergeRequestRepository $puzzleMergeRequestRepository,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private PuzzleMergeSnapshotBuilder $snapshotBuilder,
    ) {
    }

    /**
     * @throws PuzzleMergeRequestNotFound
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     * @throws ManufacturerNotFound
     */
    public function __invoke(ApprovePuzzleMergeRequest $message): void
    {
        $mergeRequest = $this->puzzleMergeRequestRepository->get($message->mergeRequestId);
        $reviewer = $this->playerRepository->get($message->reviewerId);
        $survivorPuzzle = $this->puzzleRepository->get($message->survivorPuzzleId);

        // Collect all puzzle IDs to merge (including source puzzle, excluding survivor)
        $allPuzzleIds = $mergeRequest->reportedDuplicatePuzzleIds;
        $puzzlesToMerge = [];

        foreach ($allPuzzleIds as $puzzleId) {
            if ($puzzleId === $message->survivorPuzzleId) {
                continue;
            }

            try {
                $puzzlesToMerge[] = $this->puzzleRepository->get($puzzleId);
            } catch (PuzzleNotFound) {
                $this->logger->debug('Puzzle {puzzleId} not found during merge, already deleted', [
                    'puzzleId' => $puzzleId,
                    'mergeRequestId' => $message->mergeRequestId,
                ]);
            }
        }

        // Snapshot everything the merge is about to rewrite or destroy, before it happens
        $snapshotBefore = [
            'survivorPuzzle' => $this->snapshotBuilder->puzzleToArray($survivorPuzzle),
            'mergedPuzzles' => array_map(
                fn(Puzzle $puzzle): array => $this->snapshotBuilder->puzzleToArray($puzzle),
                $puzzlesToMerge,
            ),
        ];

        // Update survivor puzzle with merged data
        $survivorPuzzle->name = $message->mergedName;
        $survivorPuzzle->piecesCount = $message->mergedPiecesCount;

        $survivorPuzzle->updateProductIdentifiers(
            ean: ($message->mergedEan !== null && $message->mergedEan !== '') ? $message->mergedEan : $survivorPuzzle->ean,
            identificationNumber: ($message->mergedIdentificationNumber !== null && $message->mergedIdentificationNumber !== '') ? $message->mergedIdentificationNumber : $survivorPuzzle->identificationNumber,
        );

        if ($message->mergedManufacturerId !== null) {
            $manufacturer = $this->manufacturerRepository->get($message->mergedManufacturerId);
            $survivorPuzzle->manufacturer = $manufacturer;
        }

        // Copy image from selected puzzle if different from survivor
        if ($message->selectedImagePuzzleId !== null && $message->selectedImagePuzzleId !== $message->survivorPuzzleId) {
            try {
                $imagePuzzle = $this->puzzleRepository->get($message->selectedImagePuzzleId);
                if ($imagePuzzle->image !== null) {
                    $survivorPuzzle->image = $imagePuzzle->image;
                    $survivorPuzzle->imageRatio = $imagePuzzle->imageRatio;
                }
            } catch (PuzzleNotFound) {
                $this->logger->debug('Image puzzle {puzzleId} not found, keeping survivor image', [
                    'puzzleId' => $message->selectedImagePuzzleId,
                    'survivorPuzzleId' => $message->survivorPuzzleId,
                ]);
            }
        }

        // A merged puzzle is deleted moments from now, so any product detail only it
        // carried would be gone for good. Carry those over wherever the survivor has
        // nothing of its own - this never overwrites a value the reviewer chose.
        $this->preserveDetailsFromMergedPuzzles($puzzlesToMerge, $survivorPuzzle);

        // Migrate all puzzle-related records from merged puzzles to survivor
        $migrationInventory = $this->migrateRecordsToSurvivor($puzzlesToMerge, $survivorPuzzle);

        // Mark merge request as approved (this records PuzzleMergeApproved event for puzzle deletion)
        $mergeRequest->approve(
            reviewedBy: $reviewer,
            reviewedAt: $this->clock->now(),
            survivorPuzzleId: $survivorPuzzle->id,
            mergedPuzzleIds: array_map(
                static fn($puzzle) => $puzzle->id->toString(),
                $puzzlesToMerge,
            ),
        );

        // Clear source puzzle reference if it will be deleted
        // This prevents stale entity references during event processing
        if ($mergeRequest->sourcePuzzle !== null) {
            foreach ($puzzlesToMerge as $puzzleToMerge) {
                if ($puzzleToMerge->id->equals($mergeRequest->sourcePuzzle->id)) {
                    $mergeRequest->clearSourcePuzzleReference();
                    break;
                }
            }
        }

        // Create notification for reporter (if reporter still exists)
        if ($mergeRequest->reporter !== null) {
            $notification = new Notification(
                id: Uuid::uuid7(),
                player: $mergeRequest->reporter,
                type: NotificationType::PuzzleMergeRequestApproved,
                notifiedAt: $this->clock->now(),
                targetMergeRequest: $mergeRequest,
            );
            $this->entityManager->persist($notification);
        }

        // Forensic record: what the puzzles looked like before, what moved where, and
        // what the survivor became. The merged puzzle rows are deleted right after this,
        // so this snapshot is the only remaining trace of them.
        $this->entityManager->persist(new PuzzleMergeAudit(
            id: Uuid::uuid7(),
            mergeRequestId: $mergeRequest->id,
            survivorPuzzleId: $survivorPuzzle->id,
            performedAt: $this->clock->now(),
            performedBy: $reviewer,
            decisionSource: $message->decisionSource,
            snapshotBefore: $snapshotBefore + ['migrated' => $migrationInventory],
            snapshotAfter: ['survivorPuzzle' => $this->snapshotBuilder->puzzleToArray($survivorPuzzle)],
            decisionNote: $message->decisionNote,
            decisionConfidence: $message->decisionConfidence,
        ));

        // Puzzle deletions are handled by PuzzleMergeApproved event (recorded in approve() method)
        // This ensures migrations are flushed first, then deletions happen in a separate transaction

        $this->logger->info('Puzzle merge approved: {mergedCount} puzzles will be merged into survivor', [
            'mergeRequestId' => $message->mergeRequestId,
            'survivorPuzzleId' => $message->survivorPuzzleId,
            'mergedCount' => count($puzzlesToMerge),
            'mergedPuzzleIds' => array_map(
                static fn($puzzle) => $puzzle->id->toString(),
                $puzzlesToMerge,
            ),
        ]);
    }

    /**
     * Fills gaps on the survivor from the puzzles about to be deleted.
     *
     * Only ever writes where the survivor holds nothing, so an explicit choice made
     * by the reviewer always wins. Without this, merging a bare duplicate into a
     * richer record silently discards whichever EAN, catalogue number, localised
     * name or cover image only the duplicate happened to have.
     *
     * @param array<Puzzle> $puzzlesToMerge
     */
    private function preserveDetailsFromMergedPuzzles(array $puzzlesToMerge, Puzzle $survivorPuzzle): void
    {
        foreach ($puzzlesToMerge as $puzzleToMerge) {
            $survivorPuzzle->updateProductIdentifiers(
                ean: self::unionIdentifiers($survivorPuzzle->ean, $puzzleToMerge->ean),
                identificationNumber: self::unionIdentifiers(
                    $survivorPuzzle->identificationNumber,
                    $puzzleToMerge->identificationNumber,
                ),
            );

            if (self::isBlank($survivorPuzzle->alternativeName) && self::isBlank($puzzleToMerge->alternativeName) === false) {
                $survivorPuzzle->alternativeName = $puzzleToMerge->alternativeName;
            }

            if ($survivorPuzzle->image === null && $puzzleToMerge->image !== null) {
                $survivorPuzzle->image = $puzzleToMerge->image;
                $survivorPuzzle->imageRatio = $puzzleToMerge->imageRatio;
            }

            if ($survivorPuzzle->manufacturer === null && $puzzleToMerge->manufacturer !== null) {
                $survivorPuzzle->manufacturer = $puzzleToMerge->manufacturer;
            }
        }
    }

    private static function isBlank(null|string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * Combines two product-code fields into one list.
     *
     * A single puzzle legitimately carries more than one EAN or catalogue number -
     * the same product gets its own code per edition or region - and those are held
     * as a comma-separated list. Merging two records therefore has to take the union:
     * picking one and discarding the other throws away a code that identifies a real
     * product, and the puzzle it belonged to is about to be deleted. Existing entries
     * keep their order, so the survivor's own codes stay first.
     */
    private static function unionIdentifiers(null|string $survivorValue, null|string $mergedValue): null|string
    {
        $codes = [];

        foreach ([$survivorValue, $mergedValue] as $list) {
            foreach (explode(',', $list ?? '') as $code) {
                $code = trim($code);

                if ($code !== '' && in_array($code, $codes, true) === false) {
                    $codes[] = $code;
                }
            }
        }

        return $codes === [] ? null : implode(', ', $codes);
    }

    /**
     * @param array<Puzzle> $puzzlesToMerge
     * @return array<string, mixed>
     */
    private function migrateRecordsToSurvivor(array $puzzlesToMerge, Puzzle $survivorPuzzle): array
    {
        $inventory = [
            'solvingTimes' => [],
            'collectionItems' => ['moved' => [], 'droppedAsDuplicate' => []],
            'wishListItems' => ['moved' => [], 'droppedAsDuplicate' => []],
            'sellSwapListItems' => ['moved' => [], 'droppedAsDuplicate' => []],
            'lentPuzzles' => ['moved' => [], 'droppedAsDuplicate' => []],
            'lentPuzzleTransfers' => [],
            'soldSwappedItems' => [],
            'competitionRoundPuzzles' => ['moved' => [], 'droppedAsDuplicate' => []],
            'conversations' => [],
            'stopwatches' => [],
            'tags' => [],
        ];

        foreach ($puzzlesToMerge as $puzzleToMerge) {
            // Migrate solving times (records PuzzleSolvingTimeModified event which triggers statistics recalculation)
            $solvingTimes = $this->entityManager->getRepository(PuzzleSolvingTime::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($solvingTimes as $solvingTime) {
                $solvingTime->migrateToPuzzle($survivorPuzzle);
                $inventory['solvingTimes'][] = $solvingTime->id->toString();
            }

            // Migrate collection items (unique on collection_id + player_id + puzzle_id)
            $collectionItems = $this->entityManager->getRepository(CollectionItem::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($collectionItems as $item) {
                // Check if player already has survivor puzzle in the same collection
                $existingItem = $this->entityManager->getRepository(CollectionItem::class)->findOneBy([
                    'collection' => $item->collection,
                    'player' => $item->player,
                    'puzzle' => $survivorPuzzle,
                ]);
                if ($existingItem !== null) {
                    $this->entityManager->remove($item);
                    $inventory['collectionItems']['droppedAsDuplicate'][] = $item->id->toString();
                } else {
                    $item->puzzle = $survivorPuzzle;
                    $inventory['collectionItems']['moved'][] = $item->id->toString();
                }
            }

            // Migrate wish list items (unique on player_id + puzzle_id)
            $wishListItems = $this->entityManager->getRepository(WishListItem::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($wishListItems as $item) {
                // Check if player already has survivor puzzle in their wish list
                $existingItem = $this->entityManager->getRepository(WishListItem::class)->findOneBy([
                    'player' => $item->player,
                    'puzzle' => $survivorPuzzle,
                ]);
                if ($existingItem !== null) {
                    $this->entityManager->remove($item);
                    $inventory['wishListItems']['droppedAsDuplicate'][] = $item->id->toString();
                } else {
                    $item->puzzle = $survivorPuzzle;
                    $inventory['wishListItems']['moved'][] = $item->id->toString();
                }
            }

            // Migrate sell/swap list items (unique on player_id + puzzle_id)
            $sellSwapItems = $this->entityManager->getRepository(SellSwapListItem::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($sellSwapItems as $item) {
                // Check if player already has survivor puzzle in their sell/swap list
                $existingItem = $this->entityManager->getRepository(SellSwapListItem::class)->findOneBy([
                    'player' => $item->player,
                    'puzzle' => $survivorPuzzle,
                ]);
                if ($existingItem !== null) {
                    $this->entityManager->remove($item);
                    $inventory['sellSwapListItems']['droppedAsDuplicate'][] = $item->id->toString();
                } else {
                    $item->puzzle = $survivorPuzzle;
                    $inventory['sellSwapListItems']['moved'][] = $item->id->toString();
                }
            }

            // Migrate lent puzzles (unique on owner_player_id + puzzle_id)
            $lentPuzzles = $this->entityManager->getRepository(LentPuzzle::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($lentPuzzles as $item) {
                // Check if owner already has survivor puzzle in lent puzzles
                $existingItem = $this->entityManager->getRepository(LentPuzzle::class)->findOneBy([
                    'ownerPlayer' => $item->ownerPlayer,
                    'puzzle' => $survivorPuzzle,
                ]);
                if ($existingItem !== null) {
                    $this->entityManager->remove($item);
                    $inventory['lentPuzzles']['droppedAsDuplicate'][] = $item->id->toString();
                } else {
                    $item->puzzle = $survivorPuzzle;
                    $inventory['lentPuzzles']['moved'][] = $item->id->toString();
                }
            }

            // Record which transfers move before the bulk update rewrites them - afterwards
            // they can no longer be told apart from the survivor's own transfers. Only the
            // ids are read: a loaded entity would keep pointing at the merged puzzle after
            // the bulk update, and the flush following its deletion would then fail.
            /** @var list<array{id: UuidInterface}> $transfers */
            $transfers = $this->entityManager->createQuery(
                'SELECT t.id FROM SpeedPuzzling\Web\Entity\LentPuzzleTransfer t WHERE t.puzzle = :merged'
            )->execute(['merged' => $puzzleToMerge]);

            foreach ($transfers as $transfer) {
                $inventory['lentPuzzleTransfers'][] = $transfer['id']->toString();
            }

            // Migrate lent puzzle transfer references to survivor puzzle
            $this->entityManager->createQuery(
                'UPDATE SpeedPuzzling\Web\Entity\LentPuzzleTransfer t SET t.puzzle = :survivor WHERE t.puzzle = :merged'
            )->execute(['survivor' => $survivorPuzzle, 'merged' => $puzzleToMerge]);

            // Migrate sold/swapped items (historical records - no unique constraint)
            $soldSwappedItems = $this->entityManager->getRepository(SoldSwappedItem::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($soldSwappedItems as $item) {
                $item->puzzle = $survivorPuzzle;
                $inventory['soldSwappedItems'][] = $item->id->toString();
            }

            // Competition rounds reference the puzzle with a blocking foreign key: leaving
            // these behind aborts the whole merge when the puzzle is deleted. A round must
            // not end up listing the survivor twice, so drop rather than move a row whose
            // round already uses it.
            $roundPuzzles = $this->entityManager->getRepository(CompetitionRoundPuzzle::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($roundPuzzles as $roundPuzzle) {
                $existing = $this->entityManager->getRepository(CompetitionRoundPuzzle::class)->findOneBy([
                    'round' => $roundPuzzle->round,
                    'puzzle' => $survivorPuzzle,
                ]);

                if ($existing !== null) {
                    $this->entityManager->remove($roundPuzzle);
                    $inventory['competitionRoundPuzzles']['droppedAsDuplicate'][] = $roundPuzzle->id->toString();
                } else {
                    $roundPuzzle->puzzle = $survivorPuzzle;
                    $inventory['competitionRoundPuzzles']['moved'][] = $roundPuzzle->id->toString();
                }
            }

            // Marketplace conversations are about a puzzle - also a blocking foreign key
            $conversations = $this->entityManager->getRepository(Conversation::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($conversations as $conversation) {
                $conversation->puzzle = $survivorPuzzle;
                $inventory['conversations'][] = $conversation->id->toString();
            }

            // Stopwatches cascade-delete with the puzzle: without this, merging silently
            // throws away a timer somebody is running right now.
            $stopwatches = $this->entityManager->getRepository(Stopwatch::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($stopwatches as $stopwatch) {
                $stopwatch->puzzle = $survivorPuzzle;
                $inventory['stopwatches'][] = $stopwatch->id->toString();
            }

            // Tags also cascade-delete through the join table
            $tags = $this->entityManager->getRepository(Tag::class)->createQueryBuilder('t')
                ->join('t.puzzles', 'p')
                ->where('p = :puzzle')
                ->setParameter('puzzle', $puzzleToMerge)
                ->getQuery()
                ->getResult();

            foreach ($tags as $tag) {
                $tag->puzzles->removeElement($puzzleToMerge);

                if ($tag->puzzles->contains($survivorPuzzle) === false) {
                    $tag->puzzles->add($survivorPuzzle);
                    $inventory['tags'][] = $tag->id->toString();
                }
            }
        }

        return $inventory;
    }
}
