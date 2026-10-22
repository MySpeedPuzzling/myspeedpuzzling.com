<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\Insights\RatingOverTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Your rating over time" from player_rating_snapshot (docs/features/insights/computation.md §4c.6).
 */
final class RatingOverTimeTest extends KernelTestCase
{
    public function testDailyChangesAndSummaries(): void
    {
        $day = static fn (string $date, float $rating): array => ['pieces_count' => 500, 'date' => new DateTimeImmutable($date), 'rating' => $rating, 'place' => 10];

        $result = RatingOverTime::fromRows([
            $day('2026-06-01', 0.800),
            $day('2026-06-02', 0.805),
            $day('2026-06-05', 0.790),
            $day('2026-08-01', 0.812),
            $day('2026-09-15', 0.820),
        ]);

        $series = $result->series[0];
        self::assertSame(500, $series->piecesCount);
        self::assertCount(5, $series->days);
        self::assertNull($series->days[0]->change);
        self::assertEqualsWithDelta(5.0, $series->days[1]->change, 1e-9);
        self::assertSame(3, $series->days[2]->daysSincePrevious);
        self::assertNotNull($series->biggestRise);
        self::assertEqualsWithDelta(22.0, $series->biggestRise->change, 1e-9);
        self::assertNotNull($series->biggestDrop);
        self::assertEqualsWithDelta(-15.0, $series->biggestDrop->change, 1e-9);
        // 30 days before 15 Sep: the last day at or before 16 Aug is 1 Aug
        self::assertEqualsWithDelta(8.0, $series->changeOver30Days, 1e-9);
        self::assertEqualsWithDelta(30.0, $series->changeOver90Days, 1e-9);
    }

    public function testOneStatementOverTheSnapshots(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        foreach (['2026-09-01' => 0.7, '2026-09-02' => 0.71] as $date => $rating) {
            $connection->executeStatement(
                'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, elo_rank, computed_at) VALUES (gen_random_uuid(), :player, 500, :date, :rating, 3, ' . TestClock::SQL_NOW . ')',
                ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'date' => $date, 'rating' => $rating],
            );
        }

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        /** @var RatingOverTime $builder */
        $builder = self::getContainer()->get(RatingOverTime::class);
        $result = $builder->build(PlayerFixture::PLAYER_WITH_STRIPE, from: new DateTimeImmutable('2026-09-02'));

        $queries = $holder->getData()['default'] ?? [];
        self::assertCount(1, is_array($queries) ? $queries : []);
        self::assertCount(1, $result->series[0]->days);
        self::assertEqualsWithDelta(710.0, $result->series[0]->days[0]->rating, 1e-9);
    }
}
