<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RoundResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Query\SolvingTimeRoundMatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RoundResolution;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * The round a solving time belongs to, by the one rule SolvingTimeRoundMatch (docs/features/competitions-management/
 * shared-puzzles-and-divisions.md §2.3 + A1-A4): the rounds of its competition that hold its puzzle in its category
 * (solo / duo / team) are its candidates - exactly one (every competition that never shares a puzzle between two
 * rounds of a category) is its round, no date condition, results are added from home and backfilled days later. With
 * several, a round the player named wins while it is a candidate, else the evidence (when it was saved, its day, the
 * people's entries and recorded results) must single out one round - or the time has none.
 *
 * Used by the time's own add/edit handlers, before the entity is flushed - and, through resolveFor(), by the unfinished
 * result's handlers before the entity even exists (an unfinished result needs its round's limit first). One statement
 * for both (+ the round's get()), so they can never disagree. Changes on the round side are reconciled in bulk by
 * RoundResultsReconciler over the same rule.
 */
readonly final class SolvingTimeRoundResolver
{
    /**
     * SQL twin of PuzzleSolvingTime::keepsItsRound(), negated, for a `pst` alias of puzzle_solving_time: whether the
     * time's round follows the rule above. An imported event result keeps the round its import gave it (a pair at a
     * teams event is ranked in the teams round - docs/features/event-results-import/README.md D10). IS DISTINCT FROM:
     * results saved before created_via existed have none and follow the rule.
     */
    public const string SQL_ROUND_IS_DERIVED = "(pst.created_via IS DISTINCT FROM 'event_import' OR pst.competition_round_id IS NULL)";

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    // One row: the time as the entity holds it before flush - the columns RoundResultsReconciler reads from the table
    private const string TIMES_SELECT = <<<'SQL'
SELECT CAST(:timeId AS UUID) AS time_id,
    CAST(:competitionId AS UUID) AS competition_id,
    CAST(:puzzleId AS UUID) AS puzzle_id,
    CAST(:category AS VARCHAR) AS category,
    CAST(:currentRoundId AS UUID) AS current_round_id,
    CAST(:chosen AS BOOLEAN) AS chosen,
    CAST(:ownerId AS UUID) AS owner_id,
    CAST(:team AS JSON) AS team_json,
    CAST(:seconds AS INT) AS seconds,
    CAST(:piecesPlaced AS INT) AS pieces_placed,
    CAST(:solveDay AS DATE) AS solve_day,
    CAST(:trackedAt AS TIMESTAMP) AS tracked_at,
    CAST(:createdVia AS VARCHAR) AS created_via
SQL;

    public function __construct(
        private Connection $database,
        private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    /**
     * $named: a round the player (or the API) named for this time - kept when it is a candidate (chosen), otherwise the
     * rule decides. Without it, the round the time already holds as chosen (roundChosen) is the named one.
     */
    public function resolve(PuzzleSolvingTime $solvingTime, null|CompetitionRound $named = null): RoundResolution
    {
        if ($solvingTime->keepsItsRound()) {
            return new RoundResolution($solvingTime->competitionRound, $solvingTime->roundChosen, 1);
        }

        if ($solvingTime->competition === null) {
            return RoundResolution::none();
        }

        // A named round that is not a candidate is ignored - the time's own state decides (a second statement, only then)
        if ($named !== null) {
            $resolution = $this->answer($this->timeOf($solvingTime, $named, true));

            if ($resolution->chosen) {
                return $resolution;
            }
        }

        return $this->answer($this->timeOf($solvingTime, $solvingTime->competitionRound, $solvingTime->roundChosen));
    }

    /**
     * What resolve() answers for a time that does not exist yet and carries no evidence (the unfinished result's
     * handlers, before they build the entity): the only candidate, or none when the puzzle is in several rounds of
     * the category.
     */
    public function resolveFor(string $competitionId, string $puzzleId, PuzzlingType $puzzlingType): null|CompetitionRound
    {
        return $this->answer([
            'timeId' => '00000000-0000-0000-0000-000000000000',
            'competitionId' => $competitionId,
            'puzzleId' => $puzzleId,
            'category' => $puzzlingType->value,
            'currentRoundId' => null,
            'chosen' => false,
            'ownerId' => null,
            'team' => null,
            'seconds' => null,
            'piecesPlaced' => null,
            'solveDay' => null,
            'trackedAt' => null,
            'createdVia' => null,
        ])->round;
    }

    /**
     * @return array{timeId: string, competitionId: string, puzzleId: string, category: string, currentRoundId: null|string, chosen: bool, ownerId: null|string, team: null|PuzzlersGroup, seconds: null|int, piecesPlaced: null|int, solveDay: null|string, trackedAt: null|string, createdVia: null|string}
     */
    private function timeOf(PuzzleSolvingTime $solvingTime, null|CompetitionRound $current, bool $chosen): array
    {
        $finishedAt = $solvingTime->finishedAt;

        // A named round counts only while it is a round of the time's competition and category (an edit that moved the
        // time to another event or category is no longer placed by the player) - SolvingTimeRoundMatch::sqlChosen()
        if (
            $current !== null
            && (
                $solvingTime->competition === null
                || $current->competition->id->equals($solvingTime->competition->id) === false
                || $current->category->value !== $solvingTime->puzzlingType->value
            )
        ) {
            $chosen = false;
        }

        return [
            'timeId' => $solvingTime->id->toString(),
            'competitionId' => $solvingTime->competition?->id->toString() ?? '',
            'puzzleId' => $solvingTime->puzzle->id->toString(),
            // RoundCategory and PuzzlingType share their values (solo / duo / team)
            'category' => $solvingTime->puzzlingType->value,
            'currentRoundId' => $current?->id->toString(),
            'chosen' => $chosen && $current !== null,
            'ownerId' => $solvingTime->player?->id->toString(),
            'team' => $solvingTime->team,
            'seconds' => $solvingTime->secondsToSolve,
            'piecesPlaced' => $solvingTime->piecesPlaced,
            // The same day SQL reads from finished_at when finished_at_precision IS NULL
            'solveDay' => $solvingTime->hasExactDay() && $finishedAt !== null ? $finishedAt->format('Y-m-d') : null,
            'trackedAt' => $solvingTime->trackedAt->format(self::TIMESTAMP_FORMAT),
            'createdVia' => $solvingTime->createdVia?->value,
        ];
    }

    /**
     * @param array{timeId: string, competitionId: string, puzzleId: string, category: string, currentRoundId: null|string, chosen: bool, ownerId: null|string, team: null|PuzzlersGroup, seconds: null|int, piecesPlaced: null|int, solveDay: null|string, trackedAt: null|string, createdVia: null|string} $time
     */
    private function answer(array $time): RoundResolution
    {
        $candidates = SolvingTimeRoundMatch::sqlCandidates(self::TIMES_SELECT);
        $answer = SolvingTimeRoundMatch::sqlAnswer();

        /** @var false|array{round_id: null|string, chosen: bool, candidates: int|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
WITH {$candidates}
SELECT answer.round_id, answer.chosen, answer.candidates
FROM ({$answer}) AS answer
SQL,
            $time,
            [
                'chosen' => 'boolean',
                'team' => PuzzlersGroupDoctrineType::NAME,
            ],
        );

        if ($row === false) {
            return RoundResolution::none();
        }

        return new RoundResolution(
            $row['round_id'] !== null ? $this->competitionRoundRepository->get($row['round_id']) : null,
            $row['chosen'],
            (int) $row['candidates'],
        );
    }
}
