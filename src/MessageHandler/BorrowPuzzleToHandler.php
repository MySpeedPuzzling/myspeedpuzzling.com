<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleBorrowing;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\BorrowPuzzleTo;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleBorrowingRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BorrowPuzzleToHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private PuzzleBorrowingRepository $borrowingRepository,
    ) {
    }

    public function __invoke(BorrowPuzzleTo $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $owner = $this->playerRepository->get($message->ownerId);

        // Check if puzzle already has active borrowing for this owner
        $existingBorrowing = $this->borrowingRepository->findActiveByOwnerAndPuzzle($owner, $puzzle);
        if ($existingBorrowing !== null) {
            // Return the existing borrowing first
            $existingBorrowing->returnPuzzle($owner);
            $this->entityManager->flush();
        }

        // Try to resolve borrower - check if it's a valid UUID and player exists
        $borrower = null;
        $nonRegisteredName = null;

        if ($message->borrowerId !== null) {
            // Check if it's a valid UUID
            if (Uuid::isValid($message->borrowerId)) {
                try {
                    $borrower = $this->playerRepository->get($message->borrowerId);
                } catch (PlayerNotFound) {
                    // UUID is valid but player not found, treat as non-registered name
                    $nonRegisteredName = $message->borrowerId;
                }
            } else {
                // Not a UUID, treat as non-registered person name
                $nonRegisteredName = $message->borrowerId;
            }
        } elseif ($message->nonRegisteredPersonName !== null) {
            $nonRegisteredName = $message->nonRegisteredPersonName;
        }

        $borrowing = new PuzzleBorrowing(
            $message->borrowingId,
            $puzzle,
            $owner,
            $borrower,
            new DateTimeImmutable(),
            false, // borrowed to someone
        );

        if ($nonRegisteredName !== null) {
            $borrowing->setNonRegisteredPersonName($nonRegisteredName);
        }

        $this->entityManager->persist($borrowing);
        $this->entityManager->flush();
    }
}
