<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleSpeedPercentiles;

/**
 * Time percentiles of solo timed solves per puzzle — reference data for the XP speed bonus.
 */
readonly class GetPuzzleSpeedPercentiles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forPuzzle(string $puzzleId): PuzzleSpeedPercentiles
    {
        return $this->forPuzzles([$puzzleId])[$puzzleId] ?? PuzzleSpeedPercentiles::empty();
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, PuzzleSpeedPercentiles> keyed by puzzle id
     */
    public function forPuzzles(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $sql = <<<SQL
SELECT
    puzzle_id,
    COUNT(DISTINCT player_id) AS distinct_solvers,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY seconds_to_solve) AS median_seconds,
    PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY seconds_to_solve) AS p25_seconds,
    PERCENTILE_CONT(0.1) WITHIN GROUP (ORDER BY seconds_to_solve) AS p10_seconds
FROM puzzle_solving_time
WHERE puzzle_id IN (:puzzleIds)
  AND puzzling_type = 'solo'
  AND suspicious = false
  AND seconds_to_solve IS NOT NULL
GROUP BY puzzle_id
SQL;

        /** @var list<array{puzzle_id: string, distinct_solvers: int|string, median_seconds: null|float, p25_seconds: null|float, p10_seconds: null|float}> $rows */
        $rows = $this->database
            ->executeQuery($sql, ['puzzleIds' => $puzzleIds], ['puzzleIds' => ArrayParameterType::STRING])
            ->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $result[$row['puzzle_id']] = new PuzzleSpeedPercentiles(
                distinctSolvers: (int) $row['distinct_solvers'],
                medianSeconds: $row['median_seconds'],
                p25Seconds: $row['p25_seconds'],
                p10Seconds: $row['p10_seconds'],
            );
        }

        return $result;
    }
}
