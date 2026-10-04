<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCurrentPuzzleIds;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Services\OutdatedPuzzleRequests;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
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
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleImageStorage;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Services\PuzzleMergeSnapshotBuilder;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\MergeRequestPuzzles;
use SpeedPuzzling\Web\Value\NamedPuzzle;
use SpeedPuzzling\Web\Value\PuzzleMergeNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private SecretPuzzleHides $secretPuzzleHides,
        private IsPuzzleKeptSecret $isPuzzleKeptSecret,
        private PuzzleImageStorage $puzzleImageStorage,
        private GetCurrentPuzzleIds $getCurrentPuzzleIds,
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private OutdatedPuzzleRequests $outdatedPuzzleRequests,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PuzzleMergeRequestNotFound
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     * @throws ManufacturerNotFound
     * @throws PuzzleChangedMeanwhile
     * @throws InvalidPuzzleValues
     * @throws PuzzleIsStillSecret
     */
    public function __invoke(ApprovePuzzleMergeRequest $message): void
    {
        $mergeRequest = $this->puzzleMergeRequestRepository->get($message->mergeRequestId);
        $reviewer = $this->playerRepository->get($message->reviewerId);
        $survivorPuzzleId = strtolower($message->survivorPuzzleId);

        if ($mergeRequest->status !== PuzzleReportStatus::Pending) {
            throw new InvalidPuzzleValues('This merge request is not waiting for a decision any more.');
        }

        // The reported puzzles as they are now - one merged into another puzzle meanwhile is that puzzle
        // (MergeRequestPuzzles), one deleted without a merge is left out
        $currentPuzzleIds = MergeRequestPuzzles::resolve(
            $mergeRequest->reportedDuplicatePuzzleIds,
            $this->getCurrentPuzzleIds->of($mergeRequest->reportedDuplicatePuzzleIds),
        )->currentIds();

        if (count($currentPuzzleIds) < 2) {
            throw new InvalidPuzzleValues('Nothing left to merge - other merges already joined these puzzles, or they no longer exist.');
        }

        // Every puzzle of the request but the survivor is deleted - a survivor outside the request would be deleted itself
        if (in_array($survivorPuzzleId, $currentPuzzleIds, true) === false) {
            throw new InvalidPuzzleValues('The puzzle that stays must be one of the reported puzzles.');
        }

        // Every puzzle of the merge is locked (SELECT … FOR UPDATE) before the record versions are compared - the
        // message's lock covers the survivor only: an edit or an EAN link of a merged puzzle either committed before
        // (the check below refuses the merge) or waits until the merge commits
        $lockedPuzzles = [];

        foreach ($this->puzzleRepository->findByIdsForUpdate($currentPuzzleIds) as $puzzle) {
            $lockedPuzzles[$puzzle->id->toString()] = $puzzle;
        }

        $survivorPuzzle = $lockedPuzzles[$survivorPuzzleId] ?? throw new PuzzleNotFound();

        // Every puzzle of the request but the survivor
        $puzzlesToMerge = [];

        foreach ($currentPuzzleIds as $puzzleId) {
            $puzzle = $lockedPuzzles[$puzzleId] ?? null;

            if ($puzzle === $survivorPuzzle) {
                continue;
            }

            if ($puzzle === null) {
                $this->logger->debug('Puzzle {puzzleId} not found during merge, already deleted', [
                    'puzzleId' => $puzzleId,
                    'mergeRequestId' => $message->mergeRequestId,
                ]);

                continue;
            }

            $puzzlesToMerge[] = $puzzle;
        }

        // The review shows every puzzle as it was loaded - a save in between refuses the merge before anything changes
        $recordVersions = array_change_key_case($message->recordVersions, CASE_LOWER);

        foreach ([$survivorPuzzle, ...$puzzlesToMerge] as $puzzle) {
            PuzzleRecordVersion::assertUnchanged($puzzle, $recordVersions[$puzzle->id->toString()] ?? null);

            // A secret competition puzzle is merged neither way until it is revealed: its names, codes and picture
            // would land on a public puzzle, and its page would redirect to it
            if ($this->isPuzzleKeptSecret->byId($puzzle->id->toString())) {
                throw new PuzzleIsStillSecret($puzzle->id->toString());
            }
        }

        // Snapshot everything the merge is about to rewrite or destroy, before it happens
        $snapshotBefore = [
            'survivorPuzzle' => $this->snapshotBuilder->puzzleToArray($survivorPuzzle),
            'mergedPuzzles' => array_map(
                fn(Puzzle $puzzle): array => $this->snapshotBuilder->puzzleToArray($puzzle),
                $puzzlesToMerge,
            ),
            'reportedNameLanguages' => $mergeRequest->reportedNameLanguages,
        ];

        $mergeNames = new PuzzleMergeNames(
            NamedPuzzle::ofPuzzle($survivorPuzzle),
            array_map(NamedPuzzle::ofPuzzle(...), $puzzlesToMerge),
            $mergeRequest->reportedNameLanguages,
        );

        // Update survivor puzzle with merged data - first: an invalid name is refused before anything changes
        $nameLanguage = $message->mergedNameLanguage === false
            ? $mergeNames->nameLanguageOf($message->mergedName)
            : $message->mergedNameLanguage;

        if ($message->mergedAlternativeNames !== null) {
            // The reviewer's list - a merge may hold more names than a form may add, only a longer list is capped
            if ($message->mergedAlternativeNames->count() > $mergeNames->alternativeNames()->count()) {
                $message->mergedAlternativeNames->assertFormLimits();
            }

            $survivorPuzzle->changeNames($message->mergedName, $nameLanguage, $message->mergedAlternativeNames, $this->clock->now());
        } else {
            $survivorPuzzle->changeNames($message->mergedName, $nameLanguage, $mergeNames->alternativeNames(), $this->clock->now());
        }

        $survivorPuzzle->piecesCount = $message->mergedPiecesCount;

        $survivorPuzzle->updateProductIdentifiers(
            ($message->mergedEans !== null && $message->mergedEans->isEmpty() === false) ? $message->mergedEans : $survivorPuzzle->eans(),
            ($message->mergedBrandCodes !== null && $message->mergedBrandCodes->isEmpty() === false) ? $message->mergedBrandCodes : $survivorPuzzle->brandCodes(),
        );

        if ($message->mergedManufacturerId !== null) {
            $manufacturer = $this->manufacturerRepository->get($message->mergedManufacturerId);
            $survivorPuzzle->manufacturer = $manufacturer;
        }

        // The reviewer's photo wins over every reported image - stored after the fields above, its file name is built
        // from the final brand, name and pieces. Otherwise copy the image of the selected puzzle if not the survivor's
        if ($message->uploadedImage !== null) {
            $this->puzzleImageStorage->storeUploaded($message->uploadedImage, $survivorPuzzle);
        } elseif ($message->selectedImagePuzzleId !== null && strtolower($message->selectedImagePuzzleId) !== $survivorPuzzleId) {
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

        // The survivor stands for every merged record now. If any of them was already
        // approved, the merged puzzle is too - otherwise merging an approved puzzle into
        // a newer unapproved duplicate (it survives when it has more times) would pull
        // an approved puzzle back out of the catalogue.
        if ($survivorPuzzle->approved === false) {
            foreach ($puzzlesToMerge as $puzzleToMerge) {
                if ($puzzleToMerge->approved) {
                    $survivorPuzzle->approve($reviewer, $this->clock->now());
                    break;
                }
            }
        }

        // Migrate all puzzle-related records from merged puzzles to survivor
        $migrationInventory = $this->migrateRecordsToSurvivor($puzzlesToMerge, $survivorPuzzle);
        // Round puzzles moved onto the survivor keep what they promised - the survivor's hide follows them
        $this->secretPuzzleHides->resync($survivorPuzzle);

        // Two puzzles became one, so a player who solved both now has one occurrence chain instead of two (the
        // second "first solve" is a repeat now) - XP rebuilds the survivor's chain of everybody in a moved result
        // (docs/features/xp-levels/README.md)
        /** @var list<string> $migratedSolvingTimeIds */
        $migratedSolvingTimeIds = $migrationInventory['solvingTimes'];
        foreach ($migratedSolvingTimeIds as $migratedSolvingTimeId) {
            $this->messageBus->dispatch(new RecalculateXpChainForSolve($migratedSolvingTimeId));
        }

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

        // Other requests this merge leaves nothing to do for - before the merged puzzles are deleted
        $this->outdatedPuzzleRequests->afterMerge($mergeRequest, $survivorPuzzle, $puzzlesToMerge);

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

        // Create notification for reporter (if reporter still exists) - not when the
        // reviewer merged their own request, e.g. straight from the approval queue
        if ($mergeRequest->reporter !== null && $mergeRequest->reporter->id->equals($reviewer->id) === false) {
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

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::MergeRequestApproved,
            decidedBy: $reviewer,
            source: $message->decisionSource,
            puzzleId: $survivorPuzzle->id,
            puzzleName: $survivorPuzzle->name,
            mergeRequestId: $mergeRequest->id,
            note: $message->decisionNote,
            details: [
                'survivorPuzzleId' => $survivorPuzzle->id->toString(),
                'mergedPuzzleIds' => array_map(
                    static fn(Puzzle $puzzle): string => $puzzle->id->toString(),
                    $puzzlesToMerge,
                ),
                'mergedPuzzleNames' => array_map(
                    static fn(Puzzle $puzzle): string => $puzzle->name,
                    $puzzlesToMerge,
                ),
            ],
        );

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
     * richer record silently discards whichever EAN, catalogue number or cover image
     * only the duplicate happened to have (names: PuzzleMergeNames).
     *
     * @param array<Puzzle> $puzzlesToMerge
     */
    private function preserveDetailsFromMergedPuzzles(array $puzzlesToMerge, Puzzle $survivorPuzzle): void
    {
        foreach ($puzzlesToMerge as $puzzleToMerge) {
            // A puzzle legitimately carries several codes - one per edition or region. Merging two records takes the
            // union: the merged puzzle is about to be deleted, and a code dropped here identified a real product.
            // The survivor's codes stay first
            $survivorPuzzle->updateProductIdentifiers(
                $survivorPuzzle->eans()->union($puzzleToMerge->eans()),
                $survivorPuzzle->brandCodes()->union($puzzleToMerge->brandCodes()),
            );

            if ($survivorPuzzle->image === null && $puzzleToMerge->image !== null) {
                $survivorPuzzle->image = $puzzleToMerge->image;
                $survivorPuzzle->imageRatio = $puzzleToMerge->imageRatio;
            }

            if ($survivorPuzzle->manufacturer === null && $puzzleToMerge->manufacturer !== null) {
                $survivorPuzzle->manufacturer = $puzzleToMerge->manufacturer;
            }
        }
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
            'changeRequests' => [],
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
                    $roundPuzzle->moveToPuzzle($survivorPuzzle);
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

            // Change requests are about the same puzzle - the survivor now. Every one, decided or not: a pending one
            // stays reviewable, a decided one stays in the history. Without this the delete fails (no cascade)
            foreach ($this->puzzleChangeRequestRepository->findByPuzzle($puzzleToMerge) as $changeRequest) {
                $changeRequest->puzzleMergedInto($survivorPuzzle);
                $inventory['changeRequests'][] = $changeRequest->id->toString();
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
