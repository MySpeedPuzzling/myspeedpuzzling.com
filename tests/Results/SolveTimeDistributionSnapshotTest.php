<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\SolveTimeDistribution;
use SpeedPuzzling\Web\Results\SolveTimeDistributionSnapshot;

final class SolveTimeDistributionSnapshotTest extends TestCase
{
    public function testWithAtLeastHidesThinBuckets(): void
    {
        $snapshot = self::snapshot([500 => 300, 1000 => 299]);

        self::assertNotNull($snapshot->withAtLeast(500, 300));
        self::assertNull($snapshot->withAtLeast(1000, 300));
        self::assertNull($snapshot->withAtLeast(2000, 1));
        self::assertSame(599, $snapshot->totalSolves());
    }

    public function testNeighboursSkipBucketsWithTooFewSolves(): void
    {
        // Deliberately out of order - neighbours follow the piece counts, not the array order
        $snapshot = self::snapshot([1000 => 500, 300 => 500, 500 => 500, 1500 => 20, 2000 => 400]);

        [$smaller, $larger] = $snapshot->neighboursOf(1000, 300);
        self::assertSame(500, $smaller?->piecesCount);
        self::assertSame(2000, $larger?->piecesCount);

        [$smaller, $larger] = $snapshot->neighboursOf(300, 300);
        self::assertNull($smaller);
        self::assertSame(500, $larger?->piecesCount);

        [$smaller, $larger] = $snapshot->neighboursOf(2000, 300);
        self::assertSame(1000, $smaller?->piecesCount);
        self::assertNull($larger);
    }

    public function testLatestComputedAt(): void
    {
        $older = self::snapshot([], '2026-09-28 10:00');
        $newer = self::snapshot([], '2026-09-29 08:00');

        self::assertEquals(new DateTimeImmutable('2026-09-29 08:00'), SolveTimeDistributionSnapshot::latestComputedAt($older, $newer));
        self::assertEquals(new DateTimeImmutable('2026-09-29 08:00'), SolveTimeDistributionSnapshot::latestComputedAt($newer, $older));
        self::assertEquals(new DateTimeImmutable('2026-09-28 10:00'), SolveTimeDistributionSnapshot::latestComputedAt($older));
    }

    public function testDerivedPaceAndSpeedUp(): void
    {
        $solo = self::distribution(1000, 100, 11742);
        $pair = self::distribution(1000, 100, 6342);

        self::assertEqualsWithDelta(11.742, $solo->medianSecondsPerPiece(), 0.0001);
        self::assertSame(1.9, $pair->speedUpOver($solo));
    }

    /**
     * @param array<int, int> $solvesPerPieces
     */
    private static function snapshot(array $solvesPerPieces, string $computedAt = '2026-09-28 10:00'): SolveTimeDistributionSnapshot
    {
        $distributions = [];

        foreach ($solvesPerPieces as $pieces => $solves) {
            $distributions[$pieces] = self::distribution($pieces, $solves, $pieces * 8);
        }

        return new SolveTimeDistributionSnapshot($distributions, new DateTimeImmutable($computedAt));
    }

    private static function distribution(int $pieces, int $solves, int $medianSeconds): SolveTimeDistribution
    {
        return new SolveTimeDistribution(
            piecesCount: $pieces,
            solvesCount: $solves,
            playersCount: $solves,
            medianSeconds: $medianSeconds,
            p25Seconds: $medianSeconds,
            p75Seconds: $medianSeconds,
            p90Seconds: $medianSeconds,
            p10Seconds: $medianSeconds,
            fastestSeconds: $medianSeconds,
            firstAttemptMedianSeconds: null,
            firstAttemptCount: 0,
            notFirstAttemptMedianSeconds: $medianSeconds,
            notFirstAttemptCount: $solves,
        );
    }
}
