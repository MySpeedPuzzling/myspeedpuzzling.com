<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class SolveTimeDistribution
{
    /**
     * @param int $playersCount Distinct solvers: players for solo, pairs/teams (the exact set of people) for group types
     * @param null|int $notFirstAttemptMedianSeconds Solves not marked as a first attempt: repeats, plus first attempts nobody marked
     */
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
        public null|int $notFirstAttemptMedianSeconds,
        public int $notFirstAttemptCount,
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
     *     not_first_attempt_median_seconds: null|float,
     *     not_first_attempt_count: int,
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
            notFirstAttemptMedianSeconds: $row['not_first_attempt_median_seconds'] !== null
                ? (int) round($row['not_first_attempt_median_seconds'])
                : null,
            notFirstAttemptCount: $row['not_first_attempt_count'],
        );
    }

    /**
     * Median active puzzling time per piece, e.g. 7.8 for a 500-piece median of 1h 5min.
     */
    public function medianSecondsPerPiece(): float
    {
        return $this->medianSeconds / max(1, $this->piecesCount);
    }

    /**
     * How many times faster this median is than the other one (e.g. a pair vs. solo), 1 decimal.
     */
    public function speedUpOver(self $slower): float
    {
        return round($slower->medianSeconds / max(1, $this->medianSeconds), 1);
    }
}
