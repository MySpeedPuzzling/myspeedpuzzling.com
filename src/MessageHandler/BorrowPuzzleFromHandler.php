<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleBorrowing;
use SpeedPuzzling\Web\Message\BorrowPuzzleFrom;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BorrowPuzzleFromHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    public function __invoke(BorrowPuzzleFrom $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $borrower = $this->playerRepository->get($message->borrowerId);

        $owner = null;
        if ($message->ownerId !== null) {
            $owner = $this->playerRepository->get($message->ownerId);
        }

        $borrowing = new PuzzleBorrowing(
            $message->borrowingId,
            $puzzle,
            $borrower, // the borrower becomes the owner in this context
            $owner,     // the original owner becomes the borrower
            new DateTimeImmutable(),
            true, // borrowed from someone
        );

        if ($message->nonRegisteredPersonName !== null) {
            $borrowing->setNonRegisteredPersonName($message->nonRegisteredPersonName);
        }

        $this->entityManager->persist($borrowing);
        $this->entityManager->flush();
    }
}