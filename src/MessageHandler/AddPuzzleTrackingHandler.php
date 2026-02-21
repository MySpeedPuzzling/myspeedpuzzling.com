<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Liip\ImagineBundle\Message\WarmupCache;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class AddPuzzleTrackingHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private Filesystem $filesystem,
        private PuzzlersGrouping $puzzlersGrouping,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     */
    public function __invoke(AddPuzzleTracking $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $group = $this->puzzlersGrouping->assembleGroup($player, $message->groupPlayers);
        $trackingId = $message->trackingId;
        $finishedPuzzlePhotoPath = null;
        $trackedAt = $this->clock->now();
        $finishedAt = $message->finishedAt;

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $finishedPuzzlePhotoPath = "players/$player->id/$trackingId-$timestamp.$extension";

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
            id: $trackingId,
            secondsToSolve: null,
            player: $player,
            puzzle: $puzzle,
            trackedAt: $trackedAt,
            verified: false,
            team: $group,
            finishedAt: $finishedAt,
            comment: $message->comment,
            finishedPuzzlePhoto: $finishedPuzzlePhotoPath,
            firstAttempt: false,
            unboxed: false,
        );

        $this->entityManager->persist($solvingTime);
    }
}
