<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\UnfinishedResults;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Results\UnfinishedResultEvent;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SeriesEditionMatchKind;

/**
 * The event and the round an unfinished result is recorded against (docs/features/competitions-management/
 * unfinished-results-plan.md, step 3) - settled before any entity exists or changes, by the same rules a save applies
 * afterwards: SolvingTimeRoundResolver::resolveFor() for the round (competition + puzzle + solo/pair/team) and
 * SeriesEditionResolver::resolveFor() for a series pick's edition.
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
     * event or edition, or a series pick - in this order, as the finished path reads them.
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
    ): UnfinishedResultEvent {
        if ($roundId !== null) {
            $competition = $this->competitionRoundRepository->get($roundId)->competition;

            // A round of an event nobody may see does not exist for a result (as for a finished one)
            if ($this->isCompetitionPubliclyVisible->check($competition->id->toString()) === false) {
                throw new CompetitionRoundNotFound();
            }

            return $this->withRound($competition->id->toString(), null, null, $puzzleId, $puzzlingType);
        }

        if ($competitionId !== null) {
            return $this->withRound($competitionId, null, null, $puzzleId, $puzzlingType);
        }

        if ($seriesId !== null) {
            $series = $this->series($seriesId);

            if ($series->isPubliclyVisible() === false) {
                throw new UnfinishedResultNeedsTimeLimit();
            }

            return $this->ofSeriesPick($series, $puzzleId, $puzzlingType, $solveDay);
        }

        throw new UnfinishedResultNeedsTimeLimit();
    }

    /**
     * The edit's event - a one-time event or edition, or a series pick: a publicly visible series, or the series the
     * result is in now (the edit form offers it even when it is no longer public, as EditPuzzleSolvingTimeHandler
     * accepts it). $solveDay null = the result's day is known only to its month or year.
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
    ): UnfinishedResultEvent {
        if ($competitionId !== null) {
            return $this->withRound($competitionId, null, null, $puzzleId, $puzzlingType);
        }

        if ($seriesId !== null) {
            $series = $this->series($seriesId);
            $currentSeries = $solvingTime->competitionSeries ?? $solvingTime->competition?->series;

            if ($series->isPubliclyVisible() === false && $currentSeries?->id->equals($series->id) !== true) {
                throw new UnfinishedResultNeedsTimeLimit();
            }

            return $this->ofSeriesPick($series, $puzzleId, $puzzlingType, $solveDay);
        }

        throw new UnfinishedResultNeedsTimeLimit();
    }

    /**
     * @throws UnfinishedResultNeedsTimeLimit
     */
    private function ofSeriesPick(CompetitionSeries $series, string $puzzleId, PuzzlingType $puzzlingType, null|DateTimeImmutable $solveDay): UnfinishedResultEvent
    {
        $resolution = $this->seriesEditionResolver->resolveFor($series->id->toString(), $puzzleId, $solveDay, $puzzlingType);

        if ($resolution->competitionId === null || $resolution->kind !== SeriesEditionMatchKind::Puzzle) {
            throw new UnfinishedResultNeedsTimeLimit();
        }

        return $this->withRound($resolution->competitionId, $series, $resolution->kind, $puzzleId, $puzzlingType);
    }

    /**
     * @throws UnfinishedResultNeedsTimeLimit
     */
    private function withRound(
        string $competitionId,
        null|CompetitionSeries $series,
        null|SeriesEditionMatchKind $seriesEditionMatch,
        string $puzzleId,
        PuzzlingType $puzzlingType,
    ): UnfinishedResultEvent {
        try {
            $competition = $this->competitionRepository->get($competitionId);
        } catch (CompetitionNotFound) {
            throw new UnfinishedResultNeedsTimeLimit();
        }

        $round = $this->roundResolver->resolveFor($competition->id->toString(), $puzzleId, $puzzlingType);

        if ($round === null || $round->minutesLimit === null) {
            throw new UnfinishedResultNeedsTimeLimit();
        }

        return new UnfinishedResultEvent($competition, $round, $series, $seriesEditionMatch);
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
