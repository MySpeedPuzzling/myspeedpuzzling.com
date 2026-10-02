<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadySaved;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdReused;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdTaken;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleTrackingHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private Filesystem $filesystem,
        private PuzzlersGrouping $puzzlersGrouping,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private IdLock $idLock,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     * @throws SolvingTimeAlreadySaved
     * @throws SolvingTimeIdTaken
     * @throws SolvingTimeIdReused
     */
    public function __invoke(AddPuzzleTracking $message): void
    {
        // First: a second request with the same id waits here until this one commits, then finds the row below
        $this->idLock->lockUntilCommit($message->trackingId);

        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $trackedAt = $this->clock->now();

        // The id travels in the form: a tracking with it means the same form arrived again, when it is the same
        // entry (docs/features/duplicate-results.md, Layer 1)
        $existingTracking = $this->puzzleSolvingTimeRepository->findById($message->trackingId);

        if ($existingTracking !== null) {
            if ($existingTracking->player->id->equals($player->id) === false) {
                throw new SolvingTimeIdTaken();
            }

            if ($existingTracking->isSameEntryAs($message->puzzleId, null, $message->finishedAt, $trackedAt) === false) {
                throw new SolvingTimeIdReused();
            }

            throw new SolvingTimeAlreadySaved($existingTracking->id->toString(), $existingTracking->puzzle->id->toString());
        }

        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $group = $this->puzzlersGrouping->assembleGroup($player, $message->groupPlayers);
        $trackingId = $message->trackingId;
        $finishedPuzzlePhotoPath = null;
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
            puzzlingTeam: $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group, usedByPlayerId: $player->id->toString()),
            createdVia: $message->createdVia,
        );

        // Only when a name was typed: touching the team otherwise would load it for nothing
        if (PuzzlingTeam::cleanName($message->teamName) !== null) {
            $puzzlingTeam?->nameIfUnnamed($player, $message->teamName, $trackedAt);
        }

        $this->entityManager->persist($solvingTime);
    }
}
