<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\SolveTimeDistribution;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * Solve-time distribution per pieces-count bucket, powering the public guides.
 *
 * Only plausible solves count towards the statistics:
 * - one puzzling type at a time (solo by default), not flagged suspicious (same as the ladder),
 * - unboxed solves excluded (timer includes unboxing/sorting, not comparable),
 * - a per-bucket sanity floor of 0.6 s/piece filters out mis-entered times
 *   (e.g. 10 minutes for a 1000-piece puzzle).
 *
 * A pair or team time is one row per group (recorded by whoever tracked it),
 * so solves count group results, and solvers count distinct pairs/teams
 * (the exact set of people, puzzling_team) rather than the trackers.
 */
readonly final class GetSolveTimeDistribution
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<int> $piecesCounts
     * @return array<int, SolveTimeDistribution> Indexed by pieces count; buckets without data are omitted.
     */
    public function byPiecesCounts(array $piecesCounts, PuzzlingType $puzzlingType = PuzzlingType::Solo): array
    {
        return $this->distributions($piecesCounts, $puzzlingType, null);
    }

    /**
     * Groups of exactly this many puzzlers - "team" covers every group of three
     * or more, this answers "with 4 people" precisely.
     *
     * @param list<int> $piecesCounts
     * @return array<int, SolveTimeDistribution> Indexed by pieces count; buckets without data are omitted.
     */
    public function byPiecesCountsForGroupSize(array $piecesCounts, int $puzzlersCount): array
    {
        return $this->distributions($piecesCounts, PuzzlingType::fromPuzzlersCount($puzzlersCount), $puzzlersCount);
    }

    /**
     * @param list<int> $piecesCounts
     * @return array<int, SolveTimeDistribution>
     */
    private function distributions(array $piecesCounts, PuzzlingType $puzzlingType, null|int $puzzlersCount): array
    {
        // A group time always carries its puzzling_team_id; the tracker is only a fallback.
        $solversExpression = $puzzlingType === PuzzlingType::Solo
            ? 'pst.player_id'
            : 'COALESCE(pst.puzzling_team_id, pst.player_id)';

        $groupSizeCondition = $puzzlersCount !== null
            ? 'AND pst.puzzlers_count = :puzzlersCount'
            : '';

        $query = <<<SQL
SELECT
    p.pieces_count,
    COUNT(*) AS solves_count,
    COUNT(DISTINCT {$solversExpression}) AS players_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS median_seconds,
    percentile_cont(0.25) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS p25_seconds,
    percentile_cont(0.75) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS p75_seconds,
    percentile_cont(0.9) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS p90_seconds,
    percentile_cont(0.1) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS p10_seconds,
    MIN(pst.seconds_to_solve) AS fastest_seconds,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true) AS first_attempt_median_seconds,
    COUNT(*) FILTER (WHERE pst.first_attempt = true) AS first_attempt_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = false) AS not_first_attempt_median_seconds,
    COUNT(*) FILTER (WHERE pst.first_attempt = false) AS not_first_attempt_count
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.puzzling_type = :puzzlingType
  {$groupSizeCondition}
  AND pst.suspicious = false
  AND pst.unboxed = false
  AND pst.seconds_to_solve IS NOT NULL
  AND pst.seconds_to_solve > p.pieces_count * 0.6
  AND p.pieces_count IN (:piecesCounts)
GROUP BY p.pieces_count
ORDER BY p.pieces_count
SQL;

        $parameters = [
            'puzzlingType' => $puzzlingType->value,
            'piecesCounts' => $piecesCounts,
        ];

        if ($puzzlersCount !== null) {
            $parameters['puzzlersCount'] = $puzzlersCount;
        }

        $data = $this->database
            ->executeQuery($query, $parameters, [
                'piecesCounts' => ArrayParameterType::INTEGER,
            ])
            ->fetchAllAssociative();

        $distributions = [];

        foreach ($data as $row) {
            /** @var array{
             *     pieces_count: int,
             *     solves_count: int,
             *     players_count: int,
             *     median_seconds: float,
             *     p25_seconds: float,
             *     p75_seconds: float,
             *     p90_seconds: float,
             *     p10_seconds: float,
             *     fastest_seconds: int,
             *     first_attempt_median_seconds: null|float,
             *     first_attempt_count: int,
             *     not_first_attempt_median_seconds: null|float,
             *     not_first_attempt_count: int,
             * } $row
             */

            $distributions[$row['pieces_count']] = SolveTimeDistribution::fromDatabaseRow($row);
        }

        return $distributions;
    }
}
