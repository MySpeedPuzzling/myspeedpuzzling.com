<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Entity\ResultDuplicatePrevention;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadySaved;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdReused;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdTaken;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeFinished;
use SpeedPuzzling\Web\Exceptions\StopwatchNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetRecentIdenticalSolvingTime;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicatePreventionRepository;
use SpeedPuzzling\Web\Repository\StopwatchRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\SolvingTimePredictor;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;

#[AsMessageHandler]
readonly final class AddPuzzleSolvingTimeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private Filesystem $filesystem,
        private PuzzlersGrouping $puzzlersGrouping,
        private ClockInterface $clock,
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $competitionRoundRepository,
        private ImageOptimizer $imageOptimizer,
        private MistypedYearNormalizer $mistypedYearNormalizer,
        private LoggerInterface $logger,
        private SolvingTimeRoundResolver $roundResolver,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private SolvingTimePredictor $solvingTimePredictor,
        private FirstTryAssessor $firstTryAssessor,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private StopwatchRepository $stopwatchRepository,
        private GetRecentIdenticalSolvingTime $getRecentIdenticalSolvingTime,
        private ResultDuplicatePreventionRepository $resultDuplicatePreventionRepository,
        private IdLock $idLock,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     * @throws SuspiciousPpm
     * @throws FirstTryAlreadyTaken
     * @throws SolvingTimeAlreadySaved
     * @throws SolvingTimeIdTaken
     * @throws SolvingTimeIdReused
     * @throws StopwatchNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws StopwatchCouldNotBeFinished
     */
    public function __invoke(AddPuzzleSolvingTime $message): void
    {
        // First: a second request with the same id waits here until this one commits, then finds the row below
        $this->idLock->lockUntilCommit($message->timeId);

        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $trackedAt = $this->clock->now();
        $finishedAt = $this->mistypedYearNormalizer->normalizeFinishedAt($message->finishedAt);
        $solvingTime = SolvingTime::fromUserInput($message->time);

        // The id travels in the form (and is derived from the API's Idempotency-Key): a result with it means the
        // same save arrived again - when it is the same entry (docs/features/duplicate-results.md, Layer 1)
        $existingTime = $this->puzzleSolvingTimeRepository->findById($message->timeId);

        if ($existingTime !== null) {
            if ($existingTime->player->id->equals($player->id) === false) {
                throw new SolvingTimeIdTaken();
            }

            if ($existingTime->isSameEntryAs($message->puzzleId, $solvingTime->seconds, $finishedAt, $trackedAt) === false) {
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

            // Refused before anything is created: changes of a refused handler stay in the entity manager and a
            // later flush in the same request would write them after all
            if ($stopwatch->status === StopwatchStatus::NotStarted) {
                throw new StopwatchCouldNotBeFinished();
            }
        }

        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $group = $this->puzzlersGrouping->assembleGroup($player, $message->groupPlayers);
        $solvingTimeId = $message->timeId;
        $finishedPuzzlePhotoPath = null;
        $puzzlersCount = 1;
        $competitionRound = null;
        $competition = null;

        if ($message->roundId !== null) {
            // The round's competition is derived; both are written together so they cannot disagree.
            $competitionRound = $this->competitionRoundRepository->get($message->roundId);
            $competition = $competitionRound->competition;
        } elseif ($message->competitionId !== null) {
            try {
                $competition = $this->competitionRepository->get($message->competitionId);
            } catch (CompetitionNotFound $e) {
                // The form validates the id against the picker's set, so this is only reachable when the
                // competition disappeared between render and submit — saving the time without the link
                // beats losing the upload, but it must not pass silently
                $this->logger->warning('Solving time saved without competition: submitted competition id does not exist', [
                    'timeId' => $solvingTimeId->toString(),
                    'puzzleId' => $message->puzzleId,
                    'competitionId' => $message->competitionId,
                    'userId' => $message->userId,
                    'exception' => $e,
                ]);
            }
        }

        if ($group !== null) {
            $puzzlersCount = count($group->puzzlers);
        }

        // Safety net for a save sent again with a new id (no JavaScript, an old open form, the API without a key).
        // Before the first-try check: the copy would otherwise be refused as a second first try
        $recentTwinId = $solvingTime->seconds === null ? null : $this->getRecentIdenticalSolvingTime->savedBy(
            playerId: $player->id->toString(),
            puzzleId: $puzzle->id->toString(),
            secondsToSolve: $solvingTime->seconds,
            finishedAt: $finishedAt,
            teamCompositionKey: $group !== null ? TeamComposition::fromGroup($group)->key : null,
            competitionId: $competition?->id->toString(),
            roundId: $competitionRound?->id->toString(),
            firstAttempt: $message->firstAttempt,
            unboxed: $message->unboxed,
            comment: $message->comment,
            hasPhoto: $message->finishedPuzzlesPhoto !== null,
        );

        if ($recentTwinId !== null) {
            throw new SolvingTimeAlreadySaved($recentTwinId, $puzzle->id->toString());
        }

        // Before anything is written: the form checks the same, this catches races and the API
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
            competitionRound: $competitionRound,
            competition: $competition,
            // Resolved last, once nothing above can refuse the time any more - the team is created outside
            // the unit of work
            puzzlingTeam: $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group, usedByPlayerId: $player->id->toString()),
            createdVia: $message->createdVia,
        );

        // Only when a name was typed: touching the team otherwise would load it for nothing
        if (PuzzlingTeam::cleanName($message->teamName) !== null) {
            $puzzlingTeam?->nameIfUnnamed($player, $message->teamName, $trackedAt);
        }

        $solvingTime->changeCompetitionRound($this->roundResolver->resolve($solvingTime));

        // Before persist: the insights tables do not know this solve yet
        $this->solvingTimePredictor->predictAddedTime($solvingTime);

        $this->entityManager->persist($solvingTime);

        // The first try moved here
        foreach ($unmarkFirstTryOf as $timeId) {
            $this->puzzleSolvingTimeRepository->get($timeId)->unmarkFirstAttempt($player);
        }

        if ($stopwatch !== null) {
            $this->finishStopwatch($stopwatch, $puzzle, $trackedAt);
        }

        // In the same transaction: the pair is a confirmed real second solve only if the result exists
        if ($message->duplicateConfirmed) {
            $this->resultDuplicatePreventionRepository->save(new ResultDuplicatePrevention(
                id: Uuid::uuid7(),
                player: $player,
                kind: DuplicatePreventionKind::SavedAnyway,
                timeId: $solvingTimeId,
                puzzleId: $puzzle->id,
                createdAt: $trackedAt,
                via: $message->createdVia ?? SolvingTimeSource::Form,
            ));
        }
    }

    /**
     * In the same transaction as the result: a stopwatch left unfinished after its result was saved invites
     * saving it again. Already finished (a second tab saved it meanwhile) is fine - the result is what counts.
     *
     * @throws StopwatchCouldNotBeFinished
     */
    private function finishStopwatch(Stopwatch $stopwatch, Puzzle $puzzle, DateTimeImmutable $now): void
    {
        if ($stopwatch->status === StopwatchStatus::Finished) {
            return;
        }

        // Resumed after the save page opened - the time typed in the form is the one saved, so it stops here
        if ($stopwatch->status === StopwatchStatus::Running) {
            $stopwatch->pause($now);
        }

        $stopwatch->finish($puzzle);
    }
}
