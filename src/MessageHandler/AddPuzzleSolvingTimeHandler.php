<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleSolvingTimeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    public function __invoke(AddPuzzleSolvingTime $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);

        $groupName = null;
        if ($message->playersCount > 1) {
            $groupName = $message->comment;
        }

        $solvingTime = new PuzzleSolvingTime(
            Uuid::uuid7(),
            SolvingTime::fromUserInput($message->time)->seconds,
            $message->playersCount,
            $player,
            $puzzle,
            new \DateTimeImmutable(),
            false,
            $message->comment,
            $groupName,
        );

        $this->entityManager->persist($solvingTime);
    }
}
