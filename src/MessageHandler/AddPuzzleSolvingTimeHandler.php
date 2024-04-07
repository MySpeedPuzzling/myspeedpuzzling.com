<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Liip\ImagineBundle\Message\WarmupCache;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class AddPuzzleSolvingTimeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private Filesystem $filesystem,
        private PuzzlersGrouping $puzzlersGrouping,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     */
    public function __invoke(AddPuzzleSolvingTime $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $group = $this->puzzlersGrouping->assembleGroup($player, $message->groupPlayers);

        $solvingTimeId = Uuid::uuid7();
        $finishedPuzzlePhotoPath = null;
        $trackedAt = $this->clock->now();
        $finishedAt = $message->finishedAt ?? $trackedAt;

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $finishedPuzzlePhotoPath = "players/$player->id/$solvingTimeId-$timestamp.$extension";

            // Stream is better because it is memory safe
            $stream = fopen($message->finishedPuzzlesPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($finishedPuzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->messageBus->dispatch(
                new WarmupCache($finishedPuzzlePhotoPath),
            );
        }

        $solvingTime = new PuzzleSolvingTime(
            $solvingTimeId,
            SolvingTime::fromUserInput($message->time)->seconds,
            $player,
            $puzzle,
            $trackedAt,
            false,
            $group,
            $finishedAt,
            $message->comment,
            $finishedPuzzlePhotoPath,
            $message->firstAttempt,
        );

        $this->entityManager->persist($solvingTime);
    }
}
