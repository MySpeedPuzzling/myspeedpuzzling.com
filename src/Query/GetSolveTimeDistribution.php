<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\SolveTimeDistribution;

/**
 * Solve-time distribution per pieces-count bucket, powering the public guides.
 *
 * Only plausible solo solves count towards the statistics:
 * - solo puzzling type, not flagged suspicious (same as the ladder),
 * - unboxed solves excluded (timer includes unboxing/sorting, not comparable),
 * - a per-bucket sanity floor of 0.6 s/piece filters out mis-entered times
 *   (e.g. 10 minutes for a 1000-piece puzzle).
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
    public function byPiecesCounts(array $piecesCounts): array
    {
        $query = <<<SQL
SELECT
    p.pieces_count,
    COUNT(*) AS solves_count,
    COUNT(DISTINCT pst.player_id) AS players_count,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS median_seconds,
    percentile_cont(0.25) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS p25_seconds,
    percentile_cont(0.75) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS p75_seconds,
    percentile_cont(0.9) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS p90_seconds,
    percentile_cont(0.1) WITHIN GROUP (ORDER BY pst.seconds_to_solve) AS p10_seconds,
    MIN(pst.seconds_to_solve) AS fastest_seconds,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true) AS first_attempt_median_seconds,
    COUNT(*) FILTER (WHERE pst.first_attempt = true) AS first_attempt_count
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.puzzling_type = 'solo'
  AND pst.suspicious = false
  AND pst.unboxed = false
  AND pst.seconds_to_solve IS NOT NULL
  AND pst.seconds_to_solve > p.pieces_count * 0.6
  AND p.pieces_count IN (:piecesCounts)
GROUP BY p.pieces_count
ORDER BY p.pieces_count
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'piecesCounts' => $piecesCounts,
            ], [
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
             * } $row
             */

            $distributions[$row['pieces_count']] = SolveTimeDistribution::fromDatabaseRow($row);
        }

        return $distributions;
    }
}
