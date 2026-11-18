<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\UnfinishedResults;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultRoundNotIdentified;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Results\UnfinishedResultEvent;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SeriesEditionMatchKind;
use SpeedPuzzling\Web\Value\SolvingTimeRoundEvidence;

/**
 * The event and the round an unfinished result is recorded against (docs/features/competitions-management/
 * unfinished-results-plan.md, step 3) - settled before any entity exists or changes, by the same rules a save applies
 * afterwards: SolvingTimeRoundResolver::resolveNew() for the round (competition + puzzle + solo/pair/team, and - when
 * the puzzle is in several rounds of the category - the result's evidence or the round it names, shared-puzzles-and-
 * divisions.md §2.4 row 7) and SeriesEditionResolver::resolveFor() for a series pick's edition. Several candidates and
 * nothing that singles one out: UnfinishedResultRoundNotIdentified (an UnfinishedResultNeedsTimeLimit).
 *
 * Where the finished path saves the time without its link (a competition that disappeared, a series that is not public),
 * an unfinished result is refused: without a round it would have nothing to be unfinished against. A series pick counts
 * only when its edition is found **by its puzzle** (a revealed round puzzle of the result's category) - a match by the
 * day alone names no round; the round must then also have a time limit (UnfinishedResult checks the limit).
 */
readonly final class UnfinishedResultRound
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private SolvingTimeRoundResolver $roundResolver,
        private SeriesEditionResolver $seriesEditionResolver,
    ) {
    }

    /**
     * The add's event: a round (the API - its competition is derived, as AddPuzzleSolvingTimeHandler does), a one-time
     * event or edition, or a series pick - in this order, as the finished path reads them. A round given is the named
     * one (Add to my profile, the API): kept while it is a candidate, otherwise the rule decides. $evidence = what the
     * result will be saved with (null: the hint - no evidence).
     *
     * @throws UnfinishedResultNeedsTimeLimit
     * @throws CompetitionRoundNotFound
     */
    public function forNewResult(
        null|string $roundId,
        null|string $competitionId,
        null|string $seriesId,
        string $puzzleId,
        PuzzlingType $puzzlingType,
        DateTimeImmutable $solveDay,
        null|SolvingTimeRoundEvidence $evidence = null,
    ): UnfinishedResultEvent {
        if ($roundId !== null) {
            $namedRound = $this->competitionRoundRepository->get($roundId);
            $competition = $namedRound->competition;

            // A round of an event nobody may see does not exist for a result (as for a finished one)
            if ($this->isCompetitionPubliclyVisible->check($competition->id->toString()) === false) {
                throw new CompetitionRoundNotFound();
            }

            return $this->withRound($competition->id->toString(), null, null, $puzzleId, $puzzlingType, $namedRound, $evidence);
        }

        if ($competitionId !== null) {
            return $this->withRound($competitionId, null, null, $puzzleId, $puzzlingType, null, $evidence);
        }

        if ($seriesId !== null) {
            $series = $this->series($seriesId);

            if ($series->isPubliclyVisible() === false) {
                throw new UnfinishedResultNeedsTimeLimit();
            }

            return $this->ofSeriesPick($series, $puzzleId, $puzzlingType, $solveDay, null, $evidence);
        }

        throw new UnfinishedResultNeedsTimeLimit();
    }

    /**
     * The edit's event - a one-time event or edition, or a series pick: a publicly visible series, or the series the
     * result is in now (the edit form offers it even when it is no longer public, as EditPuzzleSolvingTimeHandler
     * accepts it). $solveDay null = the result's day is known only to its month or year. A round the player named for
     * the result (round_chosen) stays named while it is a candidate; $evidence = what the result is saved with now.
     *
     * @throws UnfinishedResultNeedsTimeLimit
     */
    public function forEditedResult(
        PuzzleSolvingTime $solvingTime,
        null|string $competitionId,
        null|string $seriesId,
        string $puzzleId,
        PuzzlingType $puzzlingType,
        null|DateTimeImmutable $solveDay,
        null|SolvingTimeRoundEvidence $evidence = null,
    ): UnfinishedResultEvent {
        $namedRound = $solvingTime->roundChosen ? $solvingTime->competitionRound : null;
        // A round the rule linked it to stays while the evidence names no other (A3b) - as the reconciler keeps it
        $currentRound = $solvingTime->roundChosen ? null : $solvingTime->competitionRound;

        if ($competitionId !== null) {
            return $this->withRound($competitionId, null, null, $puzzleId, $puzzlingType, $namedRound, $evidence, $currentRound);
        }

        if ($seriesId !== null) {
            $series = $this->series($seriesId);
            $currentSeries = $solvingTime->competitionSeries ?? $solvingTime->competition?->series;

            if ($series->isPubliclyVisible() === false && $currentSeries?->id->equals($series->id) !== true) {
                throw new UnfinishedResultNeedsTimeLimit();
            }

            return $this->ofSeriesPick($series, $puzzleId, $puzzlingType, $solveDay, $namedRound, $evidence, $currentRound);
        }

        throw new UnfinishedResultNeedsTimeLimit();
    }

    /**
     * @throws UnfinishedResultNeedsTimeLimit
     */
    private function ofSeriesPick(
        CompetitionSeries $series,
        string $puzzleId,
        PuzzlingType $puzzlingType,
        null|DateTimeImmutable $solveDay,
        null|CompetitionRound $namedRound,
        null|SolvingTimeRoundEvidence $evidence,
        null|CompetitionRound $currentRound = null,
    ): UnfinishedResultEvent {
        $resolution = $this->seriesEditionResolver->resolveFor($series->id->toString(), $puzzleId, $solveDay, $puzzlingType);

        if ($resolution->competitionId === null || $resolution->kind !== SeriesEditionMatchKind::Puzzle) {
            throw new UnfinishedResultNeedsTimeLimit();
        }

        return $this->withRound($resolution->competitionId, $series, $resolution->kind, $puzzleId, $puzzlingType, $namedRound, $evidence, $currentRound);
    }

    /**
     * @throws UnfinishedResultNeedsTimeLimit
     * @throws UnfinishedResultRoundNotIdentified
     */
    private function withRound(
        string $competitionId,
        null|CompetitionSeries $series,
        null|SeriesEditionMatchKind $seriesEditionMatch,
        string $puzzleId,
        PuzzlingType $puzzlingType,
        null|CompetitionRound $namedRound = null,
        null|SolvingTimeRoundEvidence $evidence = null,
        null|CompetitionRound $currentRound = null,
    ): UnfinishedResultEvent {
        try {
            $competition = $this->competitionRepository->get($competitionId);
        } catch (CompetitionNotFound) {
            throw new UnfinishedResultNeedsTimeLimit();
        }

        $resolution = $this->roundResolver->resolveNew($competition->id->toString(), $puzzleId, $puzzlingType, $namedRound, $evidence, $currentRound);
        $round = $resolution->round;

        // Several rounds of the category hold the puzzle and nothing singles one out - say so, not "no round"
        if ($round === null && $resolution->candidates > 1) {
            throw new UnfinishedResultRoundNotIdentified();
        }

        if ($round === null || $round->minutesLimit === null) {
            throw new UnfinishedResultNeedsTimeLimit();
        }

        return new UnfinishedResultEvent($competition, $round, $series, $seriesEditionMatch, $resolution->chosen);
    }

    /**
     * @throws UnfinishedResultNeedsTimeLimit
     */
    private function series(string $seriesId): CompetitionSeries
    {
        try {
            return $this->competitionSeriesRepository->get($seriesId);
        } catch (CompetitionSeriesNotFound) {
            throw new UnfinishedResultNeedsTimeLimit();
        }
    }
}
