<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\ReturnPuzzle;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleBorrowingRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReturnPuzzleHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private PuzzleBorrowingRepository $borrowingRepository,
    ) {
    }

    public function __invoke(ReturnPuzzle $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $initiator = $this->playerRepository->get($message->initiatorId);

        $borrowing = $this->borrowingRepository->findActiveBorrowing($initiator, $puzzle);

        if ($borrowing !== null) {
            $borrowing->returnPuzzle($initiator);
            $this->entityManager->flush();
        }
    }
}
