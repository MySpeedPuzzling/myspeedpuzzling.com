<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Progression;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\Progression\PlayerProgressionLock;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The lock is held by the test's own (never committed) transaction; a second session plays the other consumer and
 * the user saving something meanwhile.
 */
final class PlayerProgressionLockTest extends KernelTestCase
{
    private Connection $otherSession;

    protected function setUp(): void
    {
        self::bootKernel();

        $databaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($databaseUrl);

        $this->otherSession = DriverManager::getConnection((new DsnParser([
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'pgsql' => 'pdo_pgsql',
        ]))->parse($databaseUrl));
    }

    protected function tearDown(): void
    {
        $this->otherSession->close();

        parent::tearDown();
    }

    public function testAnotherProgressionWriteOfTheLockedPlayerWaits(): void
    {
        self::getContainer()->get(PlayerProgressionLock::class)->lock([PlayerFixture::PLAYER_REGULAR]);

        self::assertFalse($this->otherSessionCanLockProgression(PlayerFixture::PLAYER_REGULAR));
        self::assertTrue($this->otherSessionCanLockProgression(PlayerFixture::PLAYER_PRIVATE));
    }

    public function testNothingElseWaitsForTheLockedPlayer(): void
    {
        self::getContainer()->get(PlayerProgressionLock::class)->lock([PlayerFixture::PLAYER_REGULAR]);

        $this->otherSession->beginTransaction();
        // Any wait fails the test instead of hanging it
        $this->otherSession->executeStatement("SET LOCAL lock_timeout = '2s'");

        // A save: its foreign key check takes FOR KEY SHARE on the player row - with the former row lock
        // (FOR UPDATE) it waited until the lock was released (10 s saves, 2026-10-26)
        $this->otherSession->executeStatement(
            'INSERT INTO player_activity_day (id, day, first_seen_at, player_id) VALUES (:id, CURRENT_DATE, NOW(), :playerId)
             ON CONFLICT (player_id, day) DO NOTHING',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        // Even a write of the player row itself (a profile edit) does not wait
        $this->otherSession->executeQuery(
            'SELECT id FROM player WHERE id = :playerId FOR UPDATE',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        $this->otherSession->rollBack();
        $this->addToAssertionCount(1);
    }

    public function testLockKeysAreSignedIntegersThatDoNotDependOnTheCase(): void
    {
        $key = PlayerProgressionLock::keyOf(PlayerFixture::PLAYER_REGULAR);

        self::assertGreaterThanOrEqual(-2_147_483_648, $key);
        self::assertLessThanOrEqual(2_147_483_647, $key);
        self::assertSame($key, PlayerProgressionLock::keyOf(strtoupper(PlayerFixture::PLAYER_REGULAR)));
        self::assertNotSame($key, PlayerProgressionLock::keyOf(PlayerFixture::PLAYER_PRIVATE));
    }

    private function otherSessionCanLockProgression(string $playerId): bool
    {
        // Autocommit: the lock, when granted, is released again right after the statement
        return (bool) $this->otherSession->fetchOne(
            'SELECT pg_try_advisory_xact_lock(CAST(:keySpace AS INTEGER), CAST(:key AS INTEGER))',
            ['keySpace' => PlayerProgressionLock::KEY_SPACE, 'key' => PlayerProgressionLock::keyOf($playerId)],
        );
    }
}
