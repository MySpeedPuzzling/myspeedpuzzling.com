<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
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
        private Filesystem $filesystem,
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

        $solvingTimeId = Uuid::uuid7();
        $finishedPuzzlePhotoPath = null;

        if ($message->solvedPuzzlesPhoto !== null) {
            $extension = $message->solvedPuzzlesPhoto->guessExtension();
            $finishedPuzzlePhotoPath = "$player->id/$puzzle->id/$solvingTimeId.$extension";

            // Stream is better because it is memory safe
            $stream = fopen($message->solvedPuzzlesPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($finishedPuzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $solvingTime = new PuzzleSolvingTime(
            $solvingTimeId,
            SolvingTime::fromUserInput($message->time)->seconds,
            $message->playersCount,
            $player,
            $puzzle,
            new \DateTimeImmutable(),
            false,
            $message->comment,
            $groupName,
            $finishedPuzzlePhotoPath,
        );

        $this->entityManager->persist($solvingTime);
    }
}
