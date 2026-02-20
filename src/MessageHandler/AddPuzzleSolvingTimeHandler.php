<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Liip\ImagineBundle\Message\WarmupCache;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
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
        private CompetitionRepository $competitionRepository,
        private ImageOptimizer $imageOptimizer,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     * @throws SuspiciousPpm
     */
    public function __invoke(AddPuzzleSolvingTime $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $group = $this->puzzlersGrouping->assembleGroup($player, $message->groupPlayers);
        $solvingTimeId = $message->timeId;
        $finishedPuzzlePhotoPath = null;
        $trackedAt = $this->clock->now();
        $finishedAt = $message->finishedAt ?? $trackedAt;
        $solvingTime = SolvingTime::fromUserInput($message->time);
        $puzzlersCount = 1;
        $competition = null;

        if ($message->competitionId !== null) {
            try {
                $competition = $this->competitionRepository->get($message->competitionId);
            } catch (CompetitionNotFound) {
                // Already null ...
            }
        }

        if ($group !== null) {
            $puzzlersCount = count($group->puzzlers);
        }

        $ppm = $solvingTime->calculatePpm($puzzle->piecesCount, $puzzlersCount);

        if ($ppm >= 100) {
            throw new SuspiciousPpm($solvingTime, $ppm);
        }

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $finishedPuzzlePhotoPath = "players/$player->id/$solvingTimeId-$timestamp.$extension";

            $this->imageOptimizer->optimize($message->finishedPuzzlesPhoto->getPathname());

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
            $solvingTime->seconds,
            $player,
            $puzzle,
            $trackedAt,
            false,
            $group,
            $finishedAt,
            $message->comment,
            $finishedPuzzlePhotoPath,
            $message->firstAttempt,
            $message->unboxed,
            competition: $competition,
        );

        $this->entityManager->persist($solvingTime);
    }
}
