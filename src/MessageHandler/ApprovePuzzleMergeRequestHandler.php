<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzleStatistics;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
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
use SpeedPuzzling\Web\Repository\PuzzleStatisticsRepository;
use SpeedPuzzling\Web\Services\PuzzleStatisticsCalculator;
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
        private PuzzleStatisticsRepository $statisticsRepository,
        private PuzzleStatisticsCalculator $statisticsCalculator,
        private LoggerInterface $logger,
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

        // Update survivor puzzle with merged data
        $survivorPuzzle->name = $message->mergedName;
        $survivorPuzzle->piecesCount = $message->mergedPiecesCount;

        if ($message->mergedEan !== null && $message->mergedEan !== '') {
            $survivorPuzzle->ean = $message->mergedEan;
        }

        if ($message->mergedIdentificationNumber !== null && $message->mergedIdentificationNumber !== '') {
            $survivorPuzzle->identificationNumber = $message->mergedIdentificationNumber;
        }

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
                }
            } catch (PuzzleNotFound) {
                $this->logger->debug('Image puzzle {puzzleId} not found, keeping survivor image', [
                    'puzzleId' => $message->selectedImagePuzzleId,
                    'survivorPuzzleId' => $message->survivorPuzzleId,
                ]);
            }
        }

        // Migrate all puzzle-related records from merged puzzles to survivor
        $this->migrateRecordsToSurvivor($puzzlesToMerge, $survivorPuzzle);

        // Mark merge request as approved BEFORE deleting puzzles
        // (because sourcePuzzle has CASCADE delete which would delete the merge request)
        $mergeRequest->approve(
            reviewedBy: $reviewer,
            reviewedAt: $this->clock->now(),
            survivorPuzzleId: $survivorPuzzle->id,
            mergedPuzzleIds: array_map(
                static fn($puzzle) => $puzzle->id->toString(),
                $puzzlesToMerge,
            ),
        );

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

        // Flush to persist migrations, approval status, and notification before deleting puzzles
        $this->entityManager->flush();

        // Delete merged puzzles (not the survivor)
        // Note: PuzzleStatistics will be CASCADE deleted with the puzzle
        // Note: This may CASCADE delete the merge request if sourcePuzzle is among deleted puzzles
        foreach ($puzzlesToMerge as $puzzleToMerge) {
            $this->entityManager->remove($puzzleToMerge);
        }

        // Flush deletions before recalculating statistics
        $this->entityManager->flush();

        // Recalculate survivor's statistics (now includes migrated solving times)
        $this->recalculateSurvivorStatistics($survivorPuzzle);

        $this->logger->info('Puzzle merge completed: {mergedCount} puzzles merged into survivor', [
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
     * @param array<Puzzle> $puzzlesToMerge
     */
    private function migrateRecordsToSurvivor(array $puzzlesToMerge, Puzzle $survivorPuzzle): void
    {
        foreach ($puzzlesToMerge as $puzzleToMerge) {
            // Migrate solving times
            $solvingTimes = $this->entityManager->getRepository(PuzzleSolvingTime::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($solvingTimes as $solvingTime) {
                $solvingTime->puzzle = $survivorPuzzle;
            }

            // Migrate collection items
            $collectionItems = $this->entityManager->getRepository(CollectionItem::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($collectionItems as $item) {
                $item->puzzle = $survivorPuzzle;
            }

            // Migrate wish list items
            $wishListItems = $this->entityManager->getRepository(WishListItem::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($wishListItems as $item) {
                $item->puzzle = $survivorPuzzle;
            }

            // Migrate sell/swap list items
            $sellSwapItems = $this->entityManager->getRepository(SellSwapListItem::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($sellSwapItems as $item) {
                $item->puzzle = $survivorPuzzle;
            }

            // Migrate lent puzzles
            $lentPuzzles = $this->entityManager->getRepository(LentPuzzle::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($lentPuzzles as $item) {
                $item->puzzle = $survivorPuzzle;
            }

            // Migrate sold/swapped items (historical records)
            $soldSwappedItems = $this->entityManager->getRepository(SoldSwappedItem::class)->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($soldSwappedItems as $item) {
                $item->puzzle = $survivorPuzzle;
            }
        }
    }

    private function recalculateSurvivorStatistics(Puzzle $survivorPuzzle): void
    {
        $statistics = $this->statisticsRepository->findByPuzzleId($survivorPuzzle->id);

        if ($statistics === null) {
            $statistics = new PuzzleStatistics($survivorPuzzle);
            $this->statisticsRepository->save($statistics);
        }

        $data = $this->statisticsCalculator->calculateForPuzzle($survivorPuzzle->id);
        $statistics->update($data);
    }
}
