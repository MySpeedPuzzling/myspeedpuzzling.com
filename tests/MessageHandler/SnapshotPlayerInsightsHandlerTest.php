<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InsightsSnapshotMonthNotAllowed;
use SpeedPuzzling\Web\Message\SnapshotPlayerInsights;
use SpeedPuzzling\Web\Services\Insights\InsightsSnapshotCriteria;
use SpeedPuzzling\Web\Services\Insights\InsightsSnapshotFactory;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class SnapshotPlayerInsightsHandlerTest extends KernelTestCase
{
    public function testEveryActivePlayerGetsOneSnapshotPerMonthAndAReRunReplacesTheMonth(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $connection = self::getContainer()->get(Connection::class);
        $month = self::getContainer()->get(ClockInterface::class)->now()->format('Y-m');

        $bus->dispatch(new SnapshotPlayerInsights($month));

        $rows = $this->rows($connection);

        // PLAYER_REGULAR has 17 solo results in the last 12 months, Michael Johnson (PLAYER_WITH_FAVORITES) 8 - too few
        $players = array_keys($rows);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $players);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_FAVORITES, $players);

        $regular = $rows[PlayerFixture::PLAYER_REGULAR];
        self::assertSame($month . '-01', $regular['month']);
        self::assertSame(InsightsSnapshotFactory::VERSION, $regular['data']['version']);
        self::assertSame('12m', $regular['data']['period']);
        self::assertIsInt($regular['data']['results']);
        self::assertGreaterThanOrEqual(10, $regular['data']['results']);
        self::assertArrayHasKey('strengths', $regular['data']);
        self::assertArrayHasKey('weak_spots', $regular['data']);
        self::assertArrayHasKey('rating_500', $regular['data']);

        // Tamper with the month: a stale row of an inactive player and changed data of an active one
        $connection->executeStatement(
            "INSERT INTO player_insights_snapshot (id, player_id, month, data, computed_at) VALUES (:id, :player, :month, '{\"version\": 0}', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'month' => $month . '-01'],
        );
        $connection->executeStatement(
            "UPDATE player_insights_snapshot SET data = '{\"version\": 0}' WHERE player_id = :player",
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );

        $bus->dispatch(new SnapshotPlayerInsights($month));

        $again = $this->rows($connection);
        self::assertSame($players, array_keys($again), 'the inactive player\'s row is gone, nobody else changed');
        self::assertEquals($regular['data'], $again[PlayerFixture::PLAYER_REGULAR]['data'], 'the data is replaced');
    }

    public function testAPastMonthIsNeverLabelledWithTodaysData(): void
    {
        self::bootKernel();
        $past = self::getContainer()->get(ClockInterface::class)->now()->modify('-3 months')->format('Y-m');

        try {
            self::getContainer()->get(MessageBusInterface::class)->dispatch(new SnapshotPlayerInsights($past));
            self::fail('A past month was stored.');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InsightsSnapshotMonthNotAllowed::class, $exception->getPrevious());
        }

        self::assertSame(0, $this->rowCount());
    }

    public function testWhichMonthARunMayStore(): void
    {
        $utc = new DateTimeZone('UTC');
        $secondOfNovember = new DateTimeImmutable('2026-11-02 05:15:00', $utc);
        $fifthOfNovember = new DateTimeImmutable('2026-11-05 05:15:00', $utc);

        self::assertSame('2026-11-01', InsightsSnapshotCriteria::month(null, $secondOfNovember)->format('Y-m-d'));
        self::assertSame('2026-11-01', InsightsSnapshotCriteria::month('2026-11', $fifthOfNovember)->format('Y-m-d'));
        self::assertSame('2026-10-01', InsightsSnapshotCriteria::month('2026-10', $secondOfNovember)->format('Y-m-d'), 'a late run within the grace days');

        foreach ([['2026-10', $fifthOfNovember], ['2026-09', $secondOfNovember], ['2026-12', $secondOfNovember]] as [$month, $now]) {
            $refused = false;

            try {
                InsightsSnapshotCriteria::month($month, $now);
            } catch (InsightsSnapshotMonthNotAllowed) {
                $refused = true;
            }

            self::assertTrue($refused, "{$month} was accepted on {$now->format('Y-m-d')}");
        }
    }

    private function rowCount(): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM player_insights_snapshot');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return array<string, array{month: string, data: array<string, mixed>}>
     */
    private function rows(Connection $connection): array
    {
        $rows = [];

        foreach ($connection->fetchAllAssociative('SELECT player_id::text AS player_id, month::text AS month, data::text AS data FROM player_insights_snapshot ORDER BY player_id') as $row) {
            assert(is_string($row['player_id']) && is_string($row['month']) && is_string($row['data']));
            /** @var array<string, mixed> $data */
            $data = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
            $rows[$row['player_id']] = ['month' => $row['month'], 'data' => $data];
        }

        return $rows;
    }
}
