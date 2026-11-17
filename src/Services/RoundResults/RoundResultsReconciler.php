<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RoundResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Query\SolvingTimeRoundMatch;

/**
 * Brings puzzle_solving_time.competition_round_id (+ round_chosen) in line with the one rule SolvingTimeRoundMatch,
 * for one competition, for the editions of one series or for all of them. Two set-based updates: every time with a
 * candidate round gets the rule's answer (a round, or none when the evidence does not single one out - only times
 * whose puzzle is in several rounds of their category), every time without a candidate is unlinked. An imported event
 * result with a round is left alone (SolvingTimeRoundResolver::SQL_ROUND_IS_DERIVED).
 *
 * Must only run after the changes it reacts to are flushed - from a postFlush domain event handler or a
 * console command, never from the handler that persists the change.
 */
readonly final class RoundResultsReconciler
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{linked: int, unlinked: int}
     */
    public function reconcile(null|UuidInterface $competitionId = null): array
    {
        if ($competitionId === null) {
            return $this->run('', '', []);
        }

        // A time can point at a round of another competition after its competition was changed, so the
        // competition scope includes times linked to one of this competition's rounds
        return $this->run(
            'AND pst.competition_id = :competitionId',
            'AND (pst.competition_id = :competitionId OR pst.competition_round_id IN (SELECT id FROM competition_round WHERE competition_id = :competitionId))',
            ['competitionId' => $competitionId->toString()],
        );
    }

    /**
     * The times of every edition of the series - after its series picks were re-matched (SeriesEditionReconciler): a
     * pick that left an edition, or became series-level, may still point at a round of an edition of the series.
     *
     * @return array{linked: int, unlinked: int}
     */
    public function reconcileSeries(UuidInterface $seriesId): array
    {
        return $this->run(
            'AND pst.competition_id IN (SELECT id FROM competition WHERE series_id = :seriesId)',
            'AND (
        pst.competition_id IN (SELECT id FROM competition WHERE series_id = :seriesId)
        OR pst.competition_series_id = :seriesId
        OR pst.competition_round_id IN (
            SELECT cr.id FROM competition_round cr INNER JOIN competition c ON c.id = cr.competition_id WHERE c.series_id = :seriesId
        )
    )',
            ['seriesId' => $seriesId->toString()],
        );
    }

    /**
     * @param array<string, string> $parameters
     * @return array{linked: int, unlinked: int}
     */
    private function run(string $linkScope, string $unlinkScope, array $parameters): array
    {
        // An imported event result keeps the round its import gave it - neither re-linked nor unlinked (README D10)
        $derived = SolvingTimeRoundResolver::SQL_ROUND_IS_DERIVED;
        $chosen = SolvingTimeRoundMatch::sqlChosen('pst');
        $candidates = SolvingTimeRoundMatch::sqlCandidates(<<<SQL
    SELECT pst.id AS time_id,
        pst.competition_id,
        pst.puzzle_id,
        pst.puzzling_type AS category,
        pst.competition_round_id AS current_round_id,
        {$chosen} AS chosen,
        pst.player_id AS owner_id,
        pst.team AS team_json,
        pst.seconds_to_solve AS seconds,
        pst.pieces_placed,
        CASE WHEN pst.finished_at_precision IS NULL THEN CAST(pst.finished_at AS DATE) END AS solve_day,
        pst.tracked_at,
        pst.created_via
    FROM puzzle_solving_time pst
    WHERE pst.competition_id IS NOT NULL
        AND {$derived}
        {$linkScope}
SQL);
        $answer = SolvingTimeRoundMatch::sqlAnswer();

        // Every time with a candidate round gets the rule's answer - a round, or none when the evidence does not single
        // one out; a round the player named stays chosen while it is a candidate, else the flag goes with it
        /** @var array{linked: int|string, unlinked: int|string} $changed */
        $changed = $this->database->fetchAssociative(
            <<<SQL
WITH {$candidates},
changed AS (
    UPDATE puzzle_solving_time AS target
    SET competition_round_id = answer.round_id,
        round_chosen = answer.chosen
    FROM ({$answer}) AS answer
    WHERE target.id = answer.time_id
        AND (target.competition_round_id IS DISTINCT FROM answer.round_id OR target.round_chosen IS DISTINCT FROM answer.chosen)
    RETURNING target.competition_round_id
)
SELECT COUNT(*) FILTER (WHERE competition_round_id IS NOT NULL) AS linked,
    COUNT(*) FILTER (WHERE competition_round_id IS NULL) AS unlinked
FROM changed
SQL,
            $parameters,
        );

        // A time without any candidate any more (its competition, puzzle or category changed, the round lost the puzzle)
        $unlinked = $this->database->executeStatement(
            <<<SQL
UPDATE puzzle_solving_time AS pst
SET competition_round_id = NULL,
    round_chosen = false
WHERE pst.competition_round_id IS NOT NULL
    AND {$derived}
    {$unlinkScope}
    AND NOT EXISTS (
        SELECT 1
        FROM competition_round cr
        INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id
        WHERE cr.id = pst.competition_round_id
            AND cr.competition_id = pst.competition_id
            AND cr.category = pst.puzzling_type
            AND crp.puzzle_id = pst.puzzle_id
    )
SQL,
            $parameters,
        );

        return ['linked' => (int) $changed['linked'], 'unlinked' => (int) $changed['unlinked'] + (int) $unlinked];
    }
}
