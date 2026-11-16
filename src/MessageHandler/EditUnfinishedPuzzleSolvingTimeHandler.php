<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\Message\EditUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\SolvingTimePredictor;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\SuspiciousTimes\MarkedTimeEditRecheck;
use SpeedPuzzling\Web\Services\UnfinishedResults\UnfinishedResultRound;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\UnfinishedResult;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Saves a result as unfinished (docs/features/competitions-management/unfinished-results-plan.md, step 3): an unfinished
 * result edited, or a finished / relax one that turns out unfinished. Beside EditPuzzleSolvingTimeHandler, which stays
 * as it is apart from refusing an unfinished result (UnfinishedResultNeedsItsOwnEdit) and its way back
 * (EditPuzzleSolvingTime::$endsUnfinishedResult). The same steps in the same order with the same services - except that
 * the event, its round and the UnfinishedResult are settled first, on the values the result will have, before the photo
 * is written and before the entity changes. An edit is semantically delete + re-add for XP and achievements, so a
 * finished result turning unfinished loses what it earned.
 */
#[AsMessageHandler]
readonly final class EditUnfinishedPuzzleSolvingTimeHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PuzzlersGrouping $puzzlersGrouping,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private MistypedYearNormalizer $mistypedYearNormalizer,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private SolvingTimePredictor $solvingTimePredictor,
        private FirstTryAssessor $firstTryAssessor,
        private PuzzleRepository $puzzleRepository,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private MarkedTimeEditRecheck $markedTimeEditRecheck,
        private UnfinishedResultRound $unfinishedResultRound,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws CanNotAssembleEmptyGroup
     * @throws UnfinishedResultNeedsTimeLimit
     * @throws InvalidPiecesPlaced
     * @throws UnfinishedTotalTimeWithinLimit
     * @throws SuspiciousPpm
     * @throws FirstTryAlreadyTaken
     * @throws PuzzleNotFound
     * @throws PuzzleNotRevealedYet
     */
    public function __invoke(EditUnfinishedPuzzleSolvingTime $message): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($message->puzzleSolvingTimeId);
        $currentPlayer = $this->playerRepository->getByUserIdCreateIfNotExists($message->currentUserId);

        // Never an imported event result - the organizer's sheet is its only writer
        if ($solvingTime->canBeModifiedBy($currentPlayer) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        $puzzle = $solvingTime->puzzle;

        if ($message->puzzleId !== null && strtolower($message->puzzleId) !== $puzzle->id->toString()) {
            if ($solvingTime->isTrackedBy($currentPlayer) === false) {
                throw new CanNotModifyOtherPlayersTime();
            }

            $puzzle = $this->puzzleRepository->get($message->puzzleId);

            $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $currentPlayer->id->toString());
        }

        $puzzleChanges = $puzzle->id->equals($solvingTime->puzzle->id) === false;

        // Always around whoever tracked the result, never around the editor
        $group = $this->puzzlersGrouping->assembleGroup($solvingTime->tracker(), $message->groupPlayers);
        $puzzlersCount = $group !== null ? count($group->puzzlers) : 1;

        $finishedAt = $this->mistypedYearNormalizer->normalizeFinishedAt($message->finishedAt);

        // The day as the result will know it: modify() makes a changed day exact (date-precision.md)
        $dayChanges = $solvingTime->finishedAt?->format('Y-m-d') !== $finishedAt?->format('Y-m-d');
        $solveDay = $solvingTime->hasExactDay() || $dayChanges ? $finishedAt ?? $solvingTime->trackedAt : null;

        $event = $this->unfinishedResultRound->forEditedResult(
            solvingTime: $solvingTime,
            competitionId: $message->competitionId,
            seriesId: $message->competitionId === null ? $message->seriesId : null,
            puzzleId: $puzzle->id->toString(),
            puzzlingType: PuzzlingType::fromPuzzlersCount($puzzlersCount),
            solveDay: $solveDay,
        );

        $totalTime = $message->totalTime !== null ? SolvingTime::fromUserInput($message->totalTime) : null;

        $unfinished = UnfinishedResult::fromInput(
            piecesPlaced: $message->piecesPlaced,
            totalSeconds: $totalTime?->seconds,
            puzzlePiecesCount: $puzzle->piecesCount,
            roundMinutesLimit: $event->round->minutesLimit,
        );

        if ($totalTime !== null) {
            $ppm = $totalTime->calculatePpm($puzzle->piecesCount, $puzzlersCount);

            if ($ppm >= 100) {
                throw new SuspiciousPpm($totalTime, $ppm);
            }
        }

        $unmarkFirstTryOf = [];

        if ($message->firstAttempt) {
            $firstTry = $this->firstTryAssessor->assess(new FirstTryEntry(
                actorPlayerId: $currentPlayer->id->toString(),
                puzzleId: $puzzle->id->toString(),
                memberPlayerIds: FirstTryEntry::memberIdsOf($solvingTime->tracker()->id->toString(), $group),
                solvedAt: $finishedAt ?? $solvingTime->trackedAt,
                editedTimeId: $solvingTime->id->toString(),
                previouslyFirstAttempt: $puzzleChanges ? false : $solvingTime->firstAttempt,
                previousMemberPlayerIds: $puzzleChanges ? null : $solvingTime->memberPlayerIds(),
            ));

            if ($firstTry->blocks($message->firstTryResolution)) {
                throw new FirstTryAlreadyTaken($firstTry);
            }

            $unmarkFirstTryOf = $firstTry->timeIdsToUnmark($message->firstTryResolution);
        }

        $finishedPuzzlePhotoPath = $solvingTime->finishedPuzzlePhoto;
        $finishedPuzzlePhotoUploadMetadata = null;

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $finishedPuzzlePhotoPath = "players/{$solvingTime->tracker()->id->toString()}/{$message->puzzleSolvingTimeId}-$timestamp.$extension";

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

        // Everything is checked - from here on the result changes
        $membersBeforeEdit = $solvingTime->memberPlayerIds();
        $puzzleIdBeforeEdit = $solvingTime->puzzle->id->toString();
        $markedEntryBeforeEdit = $this->markedTimeEditRecheck->entryBeforeEdit($solvingTime);

        $solvingTime->moveToPuzzle($puzzle);

        $solvingTime->modify(
            null,
            $message->comment,
            $group,
            $finishedAt,
            $finishedPuzzlePhotoPath,
            $message->firstAttempt,
            $message->unboxed,
            competition: $event->series === null ? $event->competition : null,
            puzzlingTeam: $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group, usedByPlayerId: $currentPlayer->id->toString()),
            competitionSeries: $event->series,
            finishedPuzzlePhotoUploaded: $message->finishedPuzzlesPhoto !== null,
            finishedPuzzlePhotoUploadMetadata: $finishedPuzzlePhotoUploadMetadata,
        );

        $solvingTime->unfinishedWith($unfinished);

        if (PuzzlingTeam::cleanName($message->teamName) !== null) {
            $puzzlingTeam?->nameIfUnnamed($currentPlayer, $message->teamName, $this->clock->now());
        }

        if (count($membersBeforeEdit) > 1 || $solvingTime->team !== null) {
            $solvingTime->recordGroupEdit($currentPlayer, $membersBeforeEdit);
        }

        foreach ($unmarkFirstTryOf as $timeId) {
            $this->puzzleSolvingTimeRepository->get($timeId)->unmarkFirstAttempt($currentPlayer);
        }

        if ($event->series !== null) {
            $solvingTime->seriesEditionResolved($event->competition, $event->seriesEditionMatch);
        }

        $solvingTime->changeCompetitionRound($event->round);

        $this->solvingTimePredictor->reconstructIfPending($solvingTime);

        // A marked time that became unfinished is judged again by the scan (it has no time to judge any more)
        $this->markedTimeEditRecheck->afterEdit($solvingTime, $markedEntryBeforeEdit, $currentPlayer);

        $affectedPlayerIds = array_values(array_unique([...$membersBeforeEdit, ...$solvingTime->memberPlayerIds()]));

        foreach ($affectedPlayerIds as $affectedPlayerId) {
            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($affectedPlayerId));
        }

        if ($solvingTime->puzzle->id->toString() === $puzzleIdBeforeEdit) {
            $this->commandBus->dispatch(new RecalculateXpChainForSolve($message->puzzleSolvingTimeId, $membersBeforeEdit));
        } else {
            foreach ($affectedPlayerIds as $affectedPlayerId) {
                $this->commandBus->dispatch(new RecalculateXpForPlayer($affectedPlayerId));
            }
        }
    }
}
