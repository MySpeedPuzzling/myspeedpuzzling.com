<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadySaved;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdReused;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdTaken;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeFinished;
use SpeedPuzzling\Web\Exceptions\StopwatchNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\StopwatchRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\SolvingTimePredictor;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\UnfinishedResults\UnfinishedResultRound;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use SpeedPuzzling\Web\Value\UnfinishedResult;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An unfinished competition result (docs/features/competitions-management/unfinished-results-plan.md, step 3) - beside
 * AddPuzzleSolvingTimeHandler, which stays as it is (the add form must never break, Jan 2026-11-14). The same steps in
 * the same order with the same services (UnfinishedWritePathParityTest compares what both store), except that the
 * event, its round and the UnfinishedResult are settled first - everything is checked before the photo is written and
 * before any entity exists. Not a solve: no wish-list removal, no XP, no achievement progress (the readers of step 1);
 * favourites are told (labelled).
 */
#[AsMessageHandler]
readonly final class AddUnfinishedPuzzleSolvingTimeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private Filesystem $filesystem,
        private PuzzlersGrouping $puzzlersGrouping,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private MistypedYearNormalizer $mistypedYearNormalizer,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private SolvingTimePredictor $solvingTimePredictor,
        private FirstTryAssessor $firstTryAssessor,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private StopwatchRepository $stopwatchRepository,
        private IdLock $idLock,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private UnfinishedResultRound $unfinishedResultRound,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws UnfinishedResultNeedsTimeLimit
     * @throws InvalidPiecesPlaced
     * @throws UnfinishedTotalTimeWithinLimit
     * @throws CanNotAssembleEmptyGroup
     * @throws SuspiciousPpm
     * @throws FirstTryAlreadyTaken
     * @throws SolvingTimeAlreadySaved
     * @throws SolvingTimeIdTaken
     * @throws SolvingTimeIdReused
     * @throws StopwatchNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws StopwatchCouldNotBeFinished
     * @throws CompetitionRoundNotFound
     */
    public function __invoke(AddUnfinishedPuzzleSolvingTime $message): void
    {
        // First: a second request with the same id waits here until this one commits, then finds the row below
        $this->idLock->lockUntilCommit($message->timeId);

        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $trackedAt = $this->clock->now();
        $finishedAt = $this->mistypedYearNormalizer->normalizeFinishedAt($message->finishedAt);
        $totalTime = $message->totalTime !== null ? SolvingTime::fromUserInput($message->totalTime) : null;

        // The same save arrived again (docs/features/duplicate-results.md, Layer 1) - the same puzzle, pieces and day
        $existingTime = $this->puzzleSolvingTimeRepository->findById($message->timeId);

        if ($existingTime !== null) {
            if ($existingTime->isTrackedBy($player) === false) {
                throw new SolvingTimeIdTaken();
            }

            if (
                $existingTime->piecesPlaced !== $message->piecesPlaced
                || $existingTime->isSameEntryAs($message->puzzleId, null, $finishedAt, $trackedAt) === false
            ) {
                throw new SolvingTimeIdReused();
            }

            throw new SolvingTimeAlreadySaved($existingTime->id->toString(), $existingTime->puzzle->id->toString());
        }

        $stopwatch = null;

        if ($message->stopwatchId !== null) {
            $stopwatch = $this->stopwatchRepository->get($message->stopwatchId);

            if ($stopwatch->player->id->equals($player->id) === false) {
                throw new CanNotModifyOtherPlayersTime();
            }

            if ($stopwatch->status === StopwatchStatus::NotStarted) {
                throw new StopwatchCouldNotBeFinished();
            }
        }

        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        // A secret competition puzzle takes nothing personal before its reveal - from anybody (SecretPuzzleAccess)
        $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $player->id->toString());

        $group = $this->puzzlersGrouping->assembleGroup($player, $message->groupPlayers);
        $puzzlersCount = $group !== null ? count($group->puzzlers) : 1;

        // The event, its round and the round's limit - an unfinished result exists only against one
        $event = $this->unfinishedResultRound->forNewResult(
            roundId: $message->roundId,
            competitionId: $message->competitionId,
            seriesId: $message->seriesId,
            puzzleId: $puzzle->id->toString(),
            puzzlingType: PuzzlingType::fromPuzzlersCount($puzzlersCount),
            solveDay: $finishedAt ?? $trackedAt,
        );

        $unfinished = UnfinishedResult::fromInput(
            piecesPlaced: $message->piecesPlaced,
            totalSeconds: $totalTime?->seconds,
            puzzlePiecesCount: $puzzle->piecesCount,
            roundMinutesLimit: $event->round->minutesLimit,
        );

        // The total is a real time - the same guard as any time typed into the form
        if ($totalTime !== null) {
            $ppm = $totalTime->calculatePpm($puzzle->piecesCount, $puzzlersCount);

            if ($ppm >= 100) {
                throw new SuspiciousPpm($totalTime, $ppm);
            }
        }

        $unmarkFirstTryOf = [];

        if ($message->firstAttempt) {
            $firstTry = $this->firstTryAssessor->assess(new FirstTryEntry(
                actorPlayerId: $player->id->toString(),
                puzzleId: $message->puzzleId,
                memberPlayerIds: FirstTryEntry::memberIdsOf($player->id->toString(), $group),
                solvedAt: $finishedAt,
            ));

            if ($firstTry->blocks($message->firstTryResolution)) {
                throw new FirstTryAlreadyTaken($firstTry);
            }

            $unmarkFirstTryOf = $firstTry->timeIdsToUnmark($message->firstTryResolution);
        }

        $finishedPuzzlePhotoPath = null;
        $finishedPuzzlePhotoUploadMetadata = null;

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $finishedPuzzlePhotoPath = "players/$player->id/{$message->timeId}-$timestamp.$extension";

            $finishedPuzzlePhotoUploadMetadata = UploadedImageMetadata::preferring(
                $this->imageOptimizer->optimize($message->finishedPuzzlesPhoto->getPathname()),
                $message->finishedPuzzlesPhotoBrowserMetadata,
            );

            $stream = fopen($message->finishedPuzzlesPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($finishedPuzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $solvingTime = new PuzzleSolvingTime(
            $message->timeId,
            null,
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
            competitionRound: $event->round,
            // A series pick starts without its edition - seriesEditionResolved() below links the one found above
            competition: $event->series === null ? $event->competition : null,
            piecesPlaced: $unfinished->piecesPlaced,
            finishedLaterSeconds: $unfinished->finishedLaterSeconds,
            // Resolved last, once nothing above can refuse the result any more - the team is created outside the
            // unit of work
            puzzlingTeam: $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group, usedByPlayerId: $player->id->toString()),
            createdVia: $message->createdVia,
            competitionSeries: $event->series,
            finishedPuzzlePhotoUploadMetadata: $finishedPuzzlePhotoUploadMetadata,
            secondsSpent: $unfinished->secondsSpent,
        );

        if (PuzzlingTeam::cleanName($message->teamName) !== null) {
            $puzzlingTeam?->nameIfUnnamed($player, $message->teamName, $trackedAt);
        }

        if ($event->series !== null) {
            $solvingTime->seriesEditionResolved($event->competition, $event->seriesEditionMatch);
        }

        // Nothing to predict without a time - kept for the same order as the finished path (a no-op)
        $this->solvingTimePredictor->predictAddedTime($solvingTime);

        $this->entityManager->persist($solvingTime);

        foreach ($unmarkFirstTryOf as $timeId) {
            $this->puzzleSolvingTimeRepository->get($timeId)->unmarkFirstAttempt($player);
        }

        if ($stopwatch !== null) {
            $this->finishStopwatch($stopwatch, $puzzle, $trackedAt);
        }

        // The same follow-ups as a finished result: both find nothing to award for an unfinished one (CountsAsSolve)
        foreach ($solvingTime->memberPlayerIds() as $memberPlayerId) {
            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($memberPlayerId));
        }

        $this->commandBus->dispatch(new AwardXpForSolvingTime($message->timeId->toString()));
    }

    /**
     * @throws StopwatchCouldNotBeFinished
     */
    private function finishStopwatch(Stopwatch $stopwatch, Puzzle $puzzle, DateTimeImmutable $now): void
    {
        if ($stopwatch->status === StopwatchStatus::Finished) {
            return;
        }

        if ($stopwatch->status === StopwatchStatus::Running) {
            $stopwatch->pause($now);
        }

        $stopwatch->finish($puzzle);
    }
}
