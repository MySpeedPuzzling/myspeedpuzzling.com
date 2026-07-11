<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class SolveTimeDistribution
{
    public function __construct(
        public int $piecesCount,
        public int $solvesCount,
        public int $playersCount,
        public int $medianSeconds,
        public int $p25Seconds,
        public int $p75Seconds,
        public int $p90Seconds,
        public int $p10Seconds,
        public int $fastestSeconds,
        public null|int $firstAttemptMedianSeconds,
        public int $firstAttemptCount,
    ) {
    }

    /**
     * @param array{
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
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            piecesCount: $row['pieces_count'],
            solvesCount: $row['solves_count'],
            playersCount: $row['players_count'],
            medianSeconds: (int) round($row['median_seconds']),
            p25Seconds: (int) round($row['p25_seconds']),
            p75Seconds: (int) round($row['p75_seconds']),
            p90Seconds: (int) round($row['p90_seconds']),
            p10Seconds: (int) round($row['p10_seconds']),
            fastestSeconds: $row['fastest_seconds'],
            firstAttemptMedianSeconds: $row['first_attempt_median_seconds'] !== null
                ? (int) round($row['first_attempt_median_seconds'])
                : null,
            firstAttemptCount: $row['first_attempt_count'],
        );
    }
}
