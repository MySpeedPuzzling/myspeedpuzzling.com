<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RoundResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * Brings puzzle_solving_time.competition_round_id in line with the rule in SolvingTimeRoundResolver, for one
 * competition, for the editions of one series or for all of them. Two idempotent set-based updates: link or re-link
 * every time that matches a round, unlink every time that no longer does.
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
        $linked = $this->database->executeStatement(
            <<<SQL
UPDATE puzzle_solving_time AS target
SET competition_round_id = matched.round_id
FROM (
    SELECT DISTINCT ON (pst.id) pst.id AS time_id, cr.id AS round_id
    FROM puzzle_solving_time pst
    INNER JOIN competition_round cr ON cr.competition_id = pst.competition_id AND cr.category = pst.puzzling_type
    INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id AND crp.puzzle_id = pst.puzzle_id
    WHERE pst.competition_id IS NOT NULL
        {$linkScope}
    ORDER BY pst.id, cr.starts_at
) AS matched
WHERE target.id = matched.time_id
    AND target.competition_round_id IS DISTINCT FROM matched.round_id
SQL,
            $parameters,
        );

        $unlinked = $this->database->executeStatement(
            <<<SQL
UPDATE puzzle_solving_time AS pst
SET competition_round_id = NULL
WHERE pst.competition_round_id IS NOT NULL
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

        return ['linked' => (int) $linked, 'unlinked' => (int) $unlinked];
    }
}
