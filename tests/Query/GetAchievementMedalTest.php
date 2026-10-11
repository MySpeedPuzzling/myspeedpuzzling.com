<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetAchievementMedal;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The medal serial (docs/features/xp-levels/ux-polish/design-spec.md §2 + §8.1): 1 + the rows of the type and tier
 * ahead by (earned_at, id), the holders at launch included; none (and no date) for a tier held at launch. The holders
 * page numbers with ROW_NUMBER() OVER (PARTITION BY type, tier ORDER BY earned_at, id) - the two must agree.
 * The fixtures' launch marker is 2026-08-02 00:00 (XpSettingsFixture).
 */
final class GetAchievementMedalTest extends KernelTestCase
{
    private const BadgeType TYPE = BadgeType::WeekendPuzzler;

    private GetAchievementMedal $query;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetAchievementMedal::class);
        $this->connection = $container->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM badge WHERE type = :type', ['type' => self::TYPE->value]);
    }

    public function testTheSerialCountsEveryHolderAheadAndTiesGoByTheRowId(): void
    {
        $earlierId = Uuid::uuid7()->toString();
        $laterId = Uuid::uuid7()->toString();

        // Held at launch: counted ahead of everybody earning the tier later, but without a number of its own
        $this->earn(PlayerFixture::PLAYER_REGULAR, 3, '2026-08-01 12:00:00');
        // Two rows earned in the same second: the uuid7 id decides, not the insert order
        $this->earn(PlayerFixture::PLAYER_ADMIN, 3, '2026-09-01 10:00:00', $laterId);
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, 3, '2026-09-01 10:00:00', $earlierId);
        $this->earn(PlayerFixture::PLAYER_WITH_FAVORITES, 3, '2026-09-02 08:00:00');
        // Another tier is another count
        $this->earn(PlayerFixture::PLAYER_WITH_FAVORITES, 2, '2026-09-02 08:00:00');

        $launch = $this->query->forPlayerAndType(PlayerFixture::PLAYER_REGULAR, self::TYPE)[3];
        self::assertTrue($launch->heldAtLaunch);
        self::assertNull($launch->serial);

        self::assertSame(2, $this->serialOf(PlayerFixture::PLAYER_WITH_STRIPE, 3));
        self::assertSame(3, $this->serialOf(PlayerFixture::PLAYER_ADMIN, 3));
        self::assertSame(4, $this->serialOf(PlayerFixture::PLAYER_WITH_FAVORITES, 3));
        self::assertSame(1, $this->serialOf(PlayerFixture::PLAYER_WITH_FAVORITES, 2));

        $favorites = $this->query->forPlayerAndType(PlayerFixture::PLAYER_WITH_FAVORITES, self::TYPE);
        self::assertSame([2, 3], array_keys($favorites));
        self::assertSame(BadgeTier::Gold, $favorites[3]->tier);
        self::assertSame('2026-09-02 08:00:00', $favorites[3]->earnedAt->format('Y-m-d H:i:s'));
        self::assertFalse($favorites[3]->heldAtLaunch);
    }

    public function testTheSerialIsTheRowNumberOfTheHoldersPage(): void
    {
        $sameSecond = '2026-09-05 18:30:00';
        $this->earn(PlayerFixture::PLAYER_REGULAR, 4, '2026-08-01 00:00:00');
        $this->earn(PlayerFixture::PLAYER_PRIVATE, 4, $sameSecond);
        $this->earn(PlayerFixture::PLAYER_ADMIN, 4, $sameSecond);
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, 4, $sameSecond);
        $this->earn(PlayerFixture::PLAYER_WITH_FAVORITES, 4, '2026-09-04 07:00:00');

        /** @var list<array{player_id: string, position: int|string}> $rows */
        $rows = $this->connection->executeQuery(
            'SELECT player_id, ROW_NUMBER() OVER (PARTITION BY type, tier ORDER BY earned_at, id) AS position FROM badge WHERE type = :type AND tier = 4',
            ['type' => self::TYPE->value],
        )->fetchAllAssociative();

        self::assertCount(5, $rows);

        foreach ($rows as $row) {
            if ($row['player_id'] === PlayerFixture::PLAYER_REGULAR) {
                continue; // held at launch: no number shown
            }

            self::assertSame((int) $row['position'], $this->serialOf($row['player_id'], 4), 'Serial of ' . $row['player_id']);
        }
    }

    public function testATierDatedFromHistoryBeforeTheLaunchHasItsDateAndSerial(): void
    {
        // Still stamped by the launch backfill (inside deploy 2026-08-01 00:00 .. switch 2026-08-02 00:00)
        $this->earn(PlayerFixture::PLAYER_REGULAR, 2, '2026-08-01 12:00:00');
        // Dated from the history: the records met it long before the launch
        $this->earn(PlayerFixture::PLAYER_ADMIN, 2, '2025-03-14 18:20:00');

        $dated = $this->query->forPlayerAndType(PlayerFixture::PLAYER_ADMIN, self::TYPE)[2];
        self::assertFalse($dated->heldAtLaunch);
        self::assertSame(1, $dated->serial, 'First to earn it - the stamped one comes after it');
        self::assertSame('2025-03-14 18:20:00', $dated->earnedAt->format('Y-m-d H:i:s'));
        self::assertTrue($this->query->forPlayerAndType(PlayerFixture::PLAYER_REGULAR, self::TYPE)[2]->heldAtLaunch);
    }

    public function testWhileTheLaunchSwitchIsOffEveryTierIsHeldAtLaunch(): void
    {
        FixedXpLaunchReadiness::$ready = false;
        $this->earn(PlayerFixture::PLAYER_ADMIN, 1, '2026-09-01 10:00:00');

        $medal = $this->query->forPlayerAndType(PlayerFixture::PLAYER_ADMIN, self::TYPE)[1];

        self::assertTrue($medal->heldAtLaunch);
        self::assertNull($medal->serial);
    }

    public function testTheTotalNeverReadsLessThanTheSerial(): void
    {
        $this->earn(PlayerFixture::PLAYER_ADMIN, 1, '2026-09-01 10:00:00');
        $medal = $this->query->forPlayerAndType(PlayerFixture::PLAYER_ADMIN, self::TYPE)[1];

        self::assertSame(1, $medal->totalOf(0), 'A cached count from before the tier was earned');
        self::assertSame(40, $medal->totalOf(40));
    }

    private function serialOf(string $playerId, int $tier): null|int
    {
        return $this->query->forPlayerAndType($playerId, self::TYPE)[$tier]->serial ?? null;
    }

    private function earn(string $playerId, int $tier, string $earnedAt, null|string $id = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, :type, :earnedAt, :tier, :earnedAt)',
            ['id' => $id ?? Uuid::uuid7()->toString(), 'player' => $playerId, 'type' => self::TYPE->value, 'earnedAt' => $earnedAt, 'tier' => $tier],
        );
    }
}
