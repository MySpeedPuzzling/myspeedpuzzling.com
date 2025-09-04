<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleBorrowing;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\BorrowPuzzleFrom;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleBorrowingRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BorrowPuzzleFromHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private PuzzleBorrowingRepository $borrowingRepository,
    ) {
    }

    public function __invoke(BorrowPuzzleFrom $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $borrower = $this->playerRepository->get($message->borrowerId);

        // Check if puzzle already has active borrowing where current player borrowed it
        $existingBorrowing = $this->borrowingRepository->findActiveByBorrowerAndPuzzle($borrower, $puzzle);
        if ($existingBorrowing !== null) {
            // Return the existing borrowing first
            $existingBorrowing->returnPuzzle($borrower);
            $this->entityManager->flush();
        }

        // Try to resolve owner - check if it's a valid UUID and player exists
        $owner = null;
        $nonRegisteredName = null;

        if ($message->ownerId !== null) {
            // Check if it's a valid UUID
            if (Uuid::isValid($message->ownerId)) {
                try {
                    $owner = $this->playerRepository->get($message->ownerId);
                } catch (PlayerNotFound) {
                    // UUID is valid but player not found, treat as non-registered name
                    $nonRegisteredName = $message->ownerId;
                }
            } else {
                // Not a UUID, treat as non-registered person name
                $nonRegisteredName = $message->ownerId;
            }
        } elseif ($message->nonRegisteredPersonName !== null) {
            $nonRegisteredName = $message->nonRegisteredPersonName;
        }

        $borrowing = new PuzzleBorrowing(
            $message->borrowingId,
            $puzzle,
            $borrower, // the borrower becomes the owner in this context
            $owner,     // the original owner becomes the borrower
            new DateTimeImmutable(),
            true, // borrowed from someone
        );

        if ($nonRegisteredName !== null) {
            $borrowing->setNonRegisteredPersonName($nonRegisteredName);
        }

        $this->entityManager->persist($borrowing);
        $this->entityManager->flush();
    }
}
