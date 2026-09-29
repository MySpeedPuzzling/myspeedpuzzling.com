<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\SolveTimeDistributionProvider;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SolveTimeDistributionProviderTest extends KernelTestCase
{
    private SolveTimeDistributionProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->provider = self::getContainer()->get(SolveTimeDistributionProvider::class);
    }

    public function testSnapshotRemembersWhenItWasComputed(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $snapshot = $this->provider->snapshot();
        $after = new DateTimeImmutable('+1 second');

        self::assertGreaterThanOrEqual($before, $snapshot->computedAt);
        self::assertLessThanOrEqual($after, $snapshot->computedAt);

        // Served from the cache afterwards - same moment, same numbers
        self::assertEquals($snapshot, $this->provider->snapshot());
    }

    public function testEachPuzzlingTypeHasItsOwnDistributions(): void
    {
        $solo = $this->provider->snapshot(PuzzlingType::Solo);
        $duo = $this->provider->snapshot(PuzzlingType::Duo);
        $team = $this->provider->snapshot(PuzzlingType::Team);

        self::assertSame([300, 500, 1000, 1500, 2000], array_keys($solo->distributions));
        self::assertSame([1000], array_keys($duo->distributions));
        self::assertSame(2, $duo->distributions[1000]->solvesCount);
        self::assertSame([], $team->distributions);

        // The FAQ and the older guides keep reading solo through the original method
        self::assertEquals($solo->distributions, $this->provider->forStandardPiecesBuckets());
        self::assertEquals($duo->distributions, $this->provider->forStandardPiecesBuckets(PuzzlingType::Duo));
    }

    public function testGroupSizeSnapshots(): void
    {
        $pairs = $this->provider->snapshotForGroupSize(2);

        self::assertEquals($this->provider->snapshot(PuzzlingType::Duo)->distributions, $pairs->distributions);
        self::assertSame([], $this->provider->snapshotForGroupSize(4)->distributions);
    }

    public function testCacheKeysNeverCollide(): void
    {
        $keys = [
            SolveTimeDistributionProvider::cacheKey(PuzzlingType::Solo),
            SolveTimeDistributionProvider::cacheKey(PuzzlingType::Duo),
            SolveTimeDistributionProvider::cacheKey(PuzzlingType::Team),
            SolveTimeDistributionProvider::cacheKey(PuzzlingType::Duo, 2),
            SolveTimeDistributionProvider::cacheKey(PuzzlingType::Team, 3),
            SolveTimeDistributionProvider::cacheKey(PuzzlingType::Team, 4),
        ];

        self::assertSame($keys, array_values(array_unique($keys)));
    }
}
