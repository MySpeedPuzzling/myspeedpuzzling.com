<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\SnapshotPlayerInsights;
use SpeedPuzzling\Web\Services\Insights\InsightsSnapshotFactory;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SnapshotPlayerInsightsHandlerTest extends KernelTestCase
{
    public function testEveryActivePlayerGetsOneSnapshotPerMonth(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $connection = self::getContainer()->get(Connection::class);

        $bus->dispatch(new SnapshotPlayerInsights('2026-11'));

        /** @var list<array{player_id: string, month: string, data: string}> $rows */
        $rows = $connection->fetchAllAssociative("SELECT player_id::text AS player_id, month::text AS month, data::text AS data FROM player_insights_snapshot ORDER BY player_id");

        // PLAYER_REGULAR has 17 solo results in the last 12 months, Michael Johnson (PLAYER_WITH_FAVORITES) 8 - too few
        $players = array_column($rows, 'player_id');
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $players);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_FAVORITES, $players);

        $index = array_search(PlayerFixture::PLAYER_REGULAR, $players, true);
        self::assertIsInt($index);
        $regular = $rows[$index];
        self::assertSame('2026-11-01', $regular['month']);

        /** @var array<string, mixed> $data */
        $data = json_decode($regular['data'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(InsightsSnapshotFactory::VERSION, $data['version']);
        self::assertSame('12m', $data['period']);
        self::assertGreaterThanOrEqual(10, $data['results']);
        self::assertArrayHasKey('strengths', $data);
        self::assertArrayHasKey('weak_spots', $data);
        self::assertArrayHasKey('rating_500', $data);

        // A re-run of the month replaces its rows
        $bus->dispatch(new SnapshotPlayerInsights('2026-11'));
        $count = $connection->fetchOne('SELECT COUNT(*) FROM player_insights_snapshot');
        self::assertIsNumeric($count);
        self::assertCount((int) $count, $rows);
    }
}
