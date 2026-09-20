<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use SpeedPuzzling\Web\Services\Doctrine\MigrationLock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MigrationLockTest extends KernelTestCase
{
    private string $databaseUrl;

    protected function setUp(): void
    {
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($databaseUrl);
        $this->databaseUrl = $databaseUrl;
    }

    public function testWorkRunsWithTheLockHeldAndTheLockIsGivenBackAfterwards(): void
    {
        $other = $this->anotherSession();

        // What the work returns is what the caller gets: here, whether somebody else could take the lock meanwhile
        $takenByOther = (new MigrationLock())->runExclusively($this->databaseUrl, 5, function (bool $locked) use ($other): bool {
            self::assertTrue($locked);

            return (bool) $other->fetchOne('SELECT pg_try_advisory_lock(:key)', ['key' => MigrationLock::LOCK_KEY]);
        });

        self::assertFalse($takenByOther);
        self::assertTrue($other->fetchOne('SELECT pg_try_advisory_lock(:key)', ['key' => MigrationLock::LOCK_KEY]));

        $other->close();
    }

    public function testLockIsGivenBackWhenTheWorkFails(): void
    {
        $work = static function (bool $locked): bool {
            if ($locked) {
                throw new \RuntimeException('migration failed');
            }

            return false;
        };

        try {
            (new MigrationLock())->runExclusively($this->databaseUrl, 5, $work);
            self::fail('The failure of the work must reach the caller');
        } catch (\RuntimeException $exception) {
            self::assertSame('migration failed', $exception->getMessage());
        }

        $other = $this->anotherSession();
        self::assertTrue($other->fetchOne('SELECT pg_try_advisory_lock(:key)', ['key' => MigrationLock::LOCK_KEY]));
        $other->close();
    }

    public function testFailsOpenWhenTheLockCannotBeHadInTime(): void
    {
        $holder = $this->anotherSession();
        $holder->executeQuery('SELECT pg_advisory_lock(:key)', ['key' => MigrationLock::LOCK_KEY]);

        $startedAt = microtime(true);
        $locked = (new MigrationLock())->runExclusively($this->databaseUrl, 0.3, static fn(bool $locked): bool => $locked);

        self::assertFalse($locked, 'The work must still run - a stuck lock must never keep the site from starting');
        self::assertLessThan(3, microtime(true) - $startedAt);

        $holder->close();
    }

    private function anotherSession(): Connection
    {
        return DriverManager::getConnection((new DsnParser([
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'pgsql' => 'pdo_pgsql',
        ]))->parse($this->databaseUrl));
    }
}
