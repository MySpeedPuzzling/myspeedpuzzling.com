<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Doctrine;

use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\DatabaseNotReady;
use SpeedPuzzling\Web\Services\Doctrine\DatabaseReadiness;
use Symfony\Component\Clock\MockClock;

final class DatabaseReadinessTest extends TestCase
{
    public function testAnsweringDatabaseIsReadyOnTheFirstAttempt(): void
    {
        $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
        assert(is_string($databaseUrl));

        $clock = new MockClock('2026-09-03 16:26:15');

        self::assertSame(1, (new DatabaseReadiness($clock))->waitFor($databaseUrl, 60));
        self::assertEquals(new \DateTimeImmutable('2026-09-03 16:26:15'), $clock->now(), 'A ready database must not be waited for');
    }

    public function testUnreachableDatabaseIsRetriedUntilTheTimeout(): void
    {
        $clock = new MockClock('2026-09-03 16:26:15');
        $readiness = new DatabaseReadiness($clock, retryIntervalSeconds: 1.0);

        try {
            // Nothing listens on port 1: every attempt is refused straight away
            $readiness->waitFor('postgresql://app:secret@127.0.0.1:1/app?serverVersion=16&charset=utf8', 5);
            self::fail('An unreachable database must end in DatabaseNotReady');
        } catch (DatabaseNotReady $exception) {
            // t = 0, 1, 2, 3, 4, 5 s
            self::assertSame(6, $exception->attempts);
            self::assertInstanceOf(ConnectionException::class, $exception->getPrevious());
            self::assertStringContainsString('refused', $exception->getMessage());
        }

        self::assertEquals(new \DateTimeImmutable('2026-09-03 16:26:20'), $clock->now(), 'The wait must stay within its timeout');
    }
}
