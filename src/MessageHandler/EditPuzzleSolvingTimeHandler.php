<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Entity\ResultDuplicatePrevention;
use SpeedPuzzling\Web\Entity\SuspiciousTimeConfirmation;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicatePreventionRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeConfirmationRepository;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\SolvingTimePredictor;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;
use SpeedPuzzling\Web\Services\SuspiciousTimes\MarkedTimeEditRecheck;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class EditPuzzleSolvingTimeHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PuzzlersGrouping $puzzlersGrouping,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private CompetitionRepository $competitionRepository,
        private ImageOptimizer $imageOptimizer,
        private MistypedYearNormalizer $mistypedYearNormalizer,
        private LoggerInterface $logger,
        private SolvingTimeRoundResolver $roundResolver,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
        private SolvingTimePredictor $solvingTimePredictor,
        private FirstTryAssessor $firstTryAssessor,
        private ResultDuplicatePreventionRepository $resultDuplicatePreventionRepository,
        private PuzzleRepository $puzzleRepository,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private MarkedTimeEditRecheck $markedTimeEditRecheck,
        private SuspiciousTimeConfirmationRepository $suspiciousTimeConfirmationRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private SeriesEditionResolver $seriesEditionResolver,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws CouldNotGenerateUniqueCode
     * @throws CanNotAssembleEmptyGroup
     * @throws SuspiciousPpm
     * @throws FirstTryAlreadyTaken
     * @throws PuzzleNotFound
     * @throws PuzzleNotRevealedYet
     */
    public function __invoke(EditPuzzleSolvingTime $message): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($message->puzzleSolvingTimeId);
        $currentPlayer = $this->playerRepository->getByUserIdCreateIfNotExists($message->currentUserId);

        // Checked against the group as stored, before the edit: a member removing themselves
        // finishes this edit and only then loses access
        if ($solvingTime->canBeModifiedBy($currentPlayer) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        // Another puzzle (docs/features/duplicate-results.md, Layer 4): only whoever tracked the result moves it -
        // for everybody else in the group it is the tracker's result
        $puzzle = $solvingTime->puzzle;

        if ($message->puzzleId !== null && strtolower($message->puzzleId) !== $puzzle->id->toString()) {
            if ($solvingTime->player->id->equals($currentPlayer->id) === false) {
                throw new CanNotModifyOtherPlayersTime();
            }

            $puzzle = $this->puzzleRepository->get($message->puzzleId);

            // Onto a secret competition puzzle: nothing personal before its reveal - from anybody (SecretPuzzleAccess)
            $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $currentPlayer->id->toString());
        }

        $puzzleChanges = $puzzle->id->equals($solvingTime->puzzle->id) === false;

        // Always assembled around whoever tracked the time, never around the editor - the row stays
        // theirs (first puzzler, not removable) no matter which group member edits it
        $group = $this->puzzlersGrouping->assembleGroup($solvingTime->player, $message->groupPlayers);

        $competition = null;
        $competitionSeries = null;

        if ($message->competitionId !== null) {
            try {
                $competition = $this->competitionRepository->get($message->competitionId);
            } catch (CompetitionNotFound $e) {
                // The form validates the id against the picker's set, so this is only reachable when the
                // competition disappeared between render and submit — saving the time without the link
                // beats failing the edit, but it must not pass silently
                $this->logger->warning('Solving time saved without competition: submitted competition id does not exist', [
                    'timeId' => $message->puzzleSolvingTimeId,
                    'competitionId' => $message->competitionId,
                    'userId' => $message->currentUserId,
                    'exception' => $e,
                ]);
            }
        } elseif ($message->seriesId !== null) {
            // A series pick (docs/features/events-page/high-frequency-series.md) - its edition is found again below
            $competitionSeries = $this->seriesForEdit($message, $solvingTime);
        }

        $finishedAt = $this->mistypedYearNormalizer->normalizeFinishedAt($message->finishedAt);

        // Before anything is written: the form checks the same, this catches races and the API
        $unmarkFirstTryOf = [];

        if ($message->firstAttempt) {
            // On another puzzle the result is new there - nothing about it is an old, tolerated duplicate
            $firstTry = $this->firstTryAssessor->assess(new FirstTryEntry(
                actorPlayerId: $currentPlayer->id->toString(),
                puzzleId: $puzzle->id->toString(),
                memberPlayerIds: FirstTryEntry::memberIdsOf($solvingTime->player->id->toString(), $group),
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

        $seconds = null;
        if ($message->time !== null) {
            $solvingTimeValue = SolvingTime::fromUserInput($message->time);
            $seconds = $solvingTimeValue->seconds;

            $puzzlersCount = 1;
            if ($group !== null) {
                $puzzlersCount = count($group->puzzlers);
            }

            $ppm = $solvingTimeValue->calculatePpm($puzzle->piecesCount, $puzzlersCount);

            if ($ppm >= 100) {
                throw new SuspiciousPpm($solvingTimeValue, $ppm);
            }
        }

        $finishedPuzzlePhotoPath = $solvingTime->finishedPuzzlePhoto;

        if ($message->finishedPuzzlesPhoto !== null) {
            $extension = $message->finishedPuzzlesPhoto->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $finishedPuzzlePhotoPath = "players/{$solvingTime->player->id->toString()}/{$message->puzzleSolvingTimeId}-$timestamp.$extension";

            $this->imageOptimizer->optimize($message->finishedPuzzlesPhoto->getPathname());

            // Stream is better because it is memory safe
            $stream = fopen($message->finishedPuzzlesPhoto->getPathname(), 'rb');
            $this->filesystem->writeStream($finishedPuzzlePhotoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $membersBeforeEdit = $solvingTime->memberPlayerIds();

        // Time verification: the entry a mark is about, before the edit changes it (null unless flagged)
        $markedEntryBeforeEdit = $this->markedTimeEditRecheck->entryBeforeEdit($solvingTime);

        // Before modify(): its PuzzleSolvingTimeModified is then about the new puzzle, the old one is told by this
        $solvingTime->moveToPuzzle($puzzle);

        $solvingTime->modify(
            $seconds,
            $message->comment,
            $group,
            $finishedAt,
            $finishedPuzzlePhotoPath,
            $message->firstAttempt,
            $message->unboxed,
            competition: $competition,
            puzzlingTeam: $puzzlingTeam = $this->puzzlingTeamResolver->resolve($group, usedByPlayerId: $currentPlayer->id->toString()),
            competitionSeries: $competitionSeries,
        );

        // Only when a name was typed: touching the team otherwise would load it for nothing
        if (PuzzlingTeam::cleanName($message->teamName) !== null) {
            $puzzlingTeam?->nameIfUnnamed($currentPlayer, $message->teamName, $this->clock->now());
        }

        // Several people can now change one result, so the others get told who did
        if (count($membersBeforeEdit) > 1 || $solvingTime->team !== null) {
            $solvingTime->recordGroupEdit($currentPlayer, $membersBeforeEdit);
        }

        // The first try moved here
        foreach ($unmarkFirstTryOf as $timeId) {
            $this->puzzleSolvingTimeRepository->get($timeId)->unmarkFirstAttempt($currentPlayer);
        }

        // After modify(): a series pick's edition depends on the puzzle, solo/duo/team and the day - always the rule's
        // current answer, an edit is a fresh evaluation
        if ($competitionSeries !== null) {
            $resolution = $this->seriesEditionResolver->resolve($solvingTime);
            $solvingTime->seriesEditionResolved(
                $resolution->competitionId !== null ? $this->competitionRepository->get($resolution->competitionId) : null,
                $resolution->kind,
            );
        }

        // After modify(): the round depends on the competition and on solo/duo/team, both final only now
        $solvingTime->changeCompetitionRound($this->roundResolver->resolve($solvingTime));

        // modify() forgets the prediction when the date, solo/group or the presence of a time changed, moveToPuzzle()
        // always
        $this->solvingTimePredictor->reconstructIfPending($solvingTime);

        // Time verification (docs/features/suspicious-time-review.md, "When the player edits a marked time"): a marked
        // time whose time, puzzle or group changed is judged again - unmarked when the fix passes a detector mark,
        // otherwise back to the moderators. Never fails the edit.
        $this->markedTimeEditRecheck->afterEdit($solvingTime, $markedEntryBeforeEdit, $currentPlayer);

        // In the same transaction: the pair is a confirmed real second solve only if the edit is saved
        if ($message->duplicateConfirmed) {
            $this->resultDuplicatePreventionRepository->save(new ResultDuplicatePrevention(
                id: Uuid::uuid7(),
                player: $currentPlayer,
                kind: DuplicatePreventionKind::SavedAnyway,
                timeId: $solvingTime->id,
                puzzleId: $solvingTime->puzzle->id,
                createdAt: $this->clock->now(),
                via: SolvingTimeSource::Form,
            ));
        }

        // Time verification, the form's question (docs/features/suspicious-time-review.md, "Catch it while typing"):
        // the edit's time was far off the tracker's own times and the editor said it is right - the scan tells the
        // moderator
        if ($message->paceConfirmedExpectedSeconds !== null) {
            $this->suspiciousTimeConfirmationRepository->save(new SuspiciousTimeConfirmation(
                id: Uuid::uuid7(),
                time: $solvingTime,
                player: $currentPlayer,
                expectedSeconds: $message->paceConfirmedExpectedSeconds,
                confirmedAt: $this->clock->now(),
            ));
        }

        $this->commandBus->dispatch(new RecalculateBadgesForPlayer($currentPlayer->id->toString()));
        // Edit is semantically delete+re-add for XP — rebuild the affected chains.
        $this->commandBus->dispatch(new RecalculateXpChainForSolve($message->puzzleSolvingTimeId));
    }

    /**
     * A series pick needs a publicly visible series - or the series the time is in now (its series pick, or the series
     * of the edition it is linked to): the edit form offers it even when it is no longer public (include-current).
     * Anything else is reachable only when the series changed between render and submit: saved without the link, not
     * silently.
     */
    private function seriesForEdit(EditPuzzleSolvingTime $message, PuzzleSolvingTime $solvingTime): null|CompetitionSeries
    {
        $exception = null;

        try {
            $series = $this->competitionSeriesRepository->get((string) $message->seriesId);
            $currentSeries = $solvingTime->competitionSeries ?? $solvingTime->competition?->series;

            if ($series->isPubliclyVisible() || $currentSeries?->id->equals($series->id) === true) {
                return $series;
            }
        } catch (CompetitionSeriesNotFound $e) {
            $exception = $e;
        }

        $context = [
            'timeId' => $message->puzzleSolvingTimeId,
            'seriesId' => $message->seriesId,
            'userId' => $message->currentUserId,
        ];

        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        $this->logger->warning('Solving time saved without series: the submitted series does not exist or is not publicly visible', $context);

        return null;
    }
}
