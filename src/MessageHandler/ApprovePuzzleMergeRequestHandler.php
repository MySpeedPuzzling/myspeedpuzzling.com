<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\NotificationType;
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
                // Puzzle might already be deleted, skip
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
                // Image puzzle not found, keep survivor's image
            }
        }

        // Migrate solving times from merged puzzles to survivor
        $solvingTimeRepository = $this->entityManager->getRepository(PuzzleSolvingTime::class);
        foreach ($puzzlesToMerge as $puzzleToMerge) {
            $solvingTimes = $solvingTimeRepository->findBy(['puzzle' => $puzzleToMerge]);
            foreach ($solvingTimes as $solvingTime) {
                $solvingTime->puzzle = $survivorPuzzle;
            }
        }

        // Flush to persist solving time migrations before deleting puzzles
        $this->entityManager->flush();

        // Delete merged puzzles (not the survivor)
        foreach ($puzzlesToMerge as $puzzleToMerge) {
            $this->entityManager->remove($puzzleToMerge);
        }

        // Mark merge request as approved
        $mergeRequest->approve(
            reviewedBy: $reviewer,
            reviewedAt: $this->clock->now(),
            survivorPuzzleId: $survivorPuzzle->id,
            mergedPuzzleIds: array_map(
                static fn($puzzle) => $puzzle->id->toString(),
                $puzzlesToMerge,
            ),
        );

        // Create notification for reporter
        $notification = new Notification(
            id: Uuid::uuid7(),
            player: $mergeRequest->reporter,
            type: NotificationType::PuzzleMergeRequestApproved,
            notifiedAt: $this->clock->now(),
            targetMergeRequest: $mergeRequest,
        );
        $this->entityManager->persist($notification);

        $this->entityManager->flush();
    }
}
