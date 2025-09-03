<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleBorrowing;
use SpeedPuzzling\Web\Message\BorrowPuzzleTo;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BorrowPuzzleToHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    public function __invoke(BorrowPuzzleTo $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $owner = $this->playerRepository->get($message->ownerId);

        $borrower = null;
        if ($message->borrowerId !== null) {
            $borrower = $this->playerRepository->get($message->borrowerId);
        }

        $borrowing = new PuzzleBorrowing(
            $message->borrowingId,
            $puzzle,
            $owner,
            $borrower,
            new DateTimeImmutable(),
            false, // borrowed to someone
        );

        if ($message->nonRegisteredPersonName !== null) {
            $borrowing->setNonRegisteredPersonName($message->nonRegisteredPersonName);
        }

        $this->entityManager->persist($borrowing);
        $this->entityManager->flush();
    }
}
