<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\PruneApiUsage;
use SpeedPuzzling\Web\MessageHandler\PruneApiUsageHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PruneApiUsageHandlerTest extends KernelTestCase
{
    public function testDeletesRowsBeyondTheRetentionFromBothTables(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $handler = $container->get(PruneApiUsageHandler::class);
        $connection = $container->get(Connection::class);

        $old = new DateTimeImmutable('-25 months');
        $recent = new DateTimeImmutable('-1 month');

        foreach ([$old, $recent] as $day) {
            $connection->insert('api_usage_day', [
                'id' => Uuid::uuid7()->toString(),
                'day' => $day->format('Y-m-d'),
                'caller_key' => 'client:prune-test',
                'player_id' => PlayerFixture::PLAYER_REGULAR,
                'oauth2_client_identifier' => 'prune-test',
                'operation' => 'GET /api/v1/me',
                'status_class' => '2xx',
                'requests' => 1,
                'duration_ms_total' => 1,
            ]);
            $connection->insert('api_caller_day', [
                'id' => Uuid::uuid7()->toString(),
                'day' => $day->format('Y-m-d'),
                'caller_key' => 'client:prune-test',
                'oauth2_client_identifier' => 'prune-test',
                'peak_requests_per_minute' => 1,
                'last_request_at' => $day->format('Y-m-d H:i:sP'),
            ]);
        }

        $deleted = $handler(new PruneApiUsage(retentionMonths: 24));

        self::assertSame(2, $deleted);

        foreach (['api_usage_day', 'api_caller_day'] as $table) {
            /** @var list<string> $days */
            $days = $connection->fetchFirstColumn("SELECT day FROM {$table} WHERE caller_key = 'client:prune-test'");
            self::assertSame([$recent->format('Y-m-d')], $days, $table);
        }
    }
}
