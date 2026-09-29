<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * The cached solve-time distributions of one puzzling type (or group size), together with
 * the moment they were computed - that moment is what the guides publish as their dateModified.
 */
readonly final class SolveTimeDistributionSnapshot
{
    /**
     * @param array<int, SolveTimeDistribution> $distributions Indexed by pieces count; buckets without data are omitted
     */
    public function __construct(
        public array $distributions,
        public DateTimeImmutable $computedAt,
    ) {
    }

    public function forPieces(int $piecesCount): null|SolveTimeDistribution
    {
        return $this->distributions[$piecesCount] ?? null;
    }

    /**
     * The bucket, but only when enough solves back it up to publish its numbers.
     */
    public function withAtLeast(int $piecesCount, int $minimumSolves): null|SolveTimeDistribution
    {
        $distribution = $this->forPieces($piecesCount);

        if ($distribution === null || $distribution->solvesCount < $minimumSolves) {
            return null;
        }

        return $distribution;
    }

    /**
     * The nearest smaller and larger buckets with enough solves to compare against.
     *
     * @return array{0: null|SolveTimeDistribution, 1: null|SolveTimeDistribution}
     */
    public function neighboursOf(int $piecesCount, int $minimumSolves): array
    {
        $smaller = null;
        $larger = null;

        $distributions = $this->distributions;
        ksort($distributions);

        foreach ($distributions as $pieces => $distribution) {
            if ($distribution->solvesCount < $minimumSolves) {
                continue;
            }

            if ($pieces < $piecesCount) {
                $smaller = $distribution;
            }

            if ($pieces > $piecesCount && $larger === null) {
                $larger = $distribution;
            }
        }

        return [$smaller, $larger];
    }

    public function totalSolves(): int
    {
        return array_sum(array_map(
            static fn (SolveTimeDistribution $distribution): int => $distribution->solvesCount,
            $this->distributions,
        ));
    }

    /**
     * The freshest of the snapshots a page shows - the date its numbers were last refreshed.
     */
    public static function latestComputedAt(self $snapshot, self ...$others): DateTimeImmutable
    {
        $latest = $snapshot->computedAt;

        foreach ($others as $other) {
            if ($other->computedAt > $latest) {
                $latest = $other->computedAt;
            }
        }

        return $latest;
    }
}
