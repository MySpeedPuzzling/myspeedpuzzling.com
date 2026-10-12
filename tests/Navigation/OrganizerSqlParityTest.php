<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\OrganizerSql;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One rule for "organises events" (MENU-01): the profile row's organizes_events (OrganizerSql::organizesAnything(),
 * whether the Menu shows "Events you organize") must say yes exactly when the events page's "You organize (n)" counts
 * something (EventsViewerData::organizedCount() > 0) - for every fixture player, and for a player whose only tie is a
 * maintained event, a maintained series, a created organization or a maintained organization.
 */
final class OrganizerSqlParityTest extends KernelTestCase
{
    public function testTheProfileRowAndYouOrganizeAgreeForEveryPlayer(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);

        $players = $database->fetchFirstColumn('SELECT id FROM player ORDER BY id');
        $organisers = 0;

        foreach ($players as $playerId) {
            self::assertIsString($playerId);
            $organisers += $this->assertParity($playerId) ? 1 : 0;
        }

        self::assertGreaterThan(0, $organisers, 'No canary: no fixture player organises anything');
        self::assertLessThan(count($players), $organisers, 'No canary: every fixture player organises something');
    }

    public function testTheNamedFixtures(): void
    {
        self::bootKernel();

        self::assertTrue($this->assertParity(PlayerFixture::PLAYER_REGULAR), 'The organiser fixture');
        // Maintains the Euro Jigsaw Jam series (and created it)
        self::assertTrue($this->assertParity(PlayerFixture::PLAYER_ADMIN), 'A series maintainer');
        self::assertFalse($this->assertParity(PlayerFixture::PLAYER_PRIVATE), 'A plain player');
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function provideSingleTies(): iterable
    {
        yield 'maintains an event' => ['competition_maintainer', 'competition_id', ['competition_id' => CompetitionFixture::COMPETITION_WJPC_2024]];
        yield 'maintains a series' => ['competition_series_maintainer', 'competition_series_id', ['competition_series_id' => CompetitionSeriesFixture::SERIES_EJJ]];
        yield 'maintains an organization' => ['organization_maintainer', 'organization_id', ['organization_id' => OrganizationFixture::ORGANIZATION_RIVERBEND]];
    }

    /**
     * @param array<string, string> $row
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideSingleTies')]
    public function testOneTieIsEnough(string $table, string $column, array $row): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);

        self::assertFalse($this->assertParity(PlayerFixture::PLAYER_PRIVATE), 'Starts as a plain player');

        $database->insert($table, [...$row, 'player_id' => PlayerFixture::PLAYER_PRIVATE]);

        self::assertTrue($this->assertParity(PlayerFixture::PLAYER_PRIVATE), $table . '.' . $column);
    }

    public function testCreatingAnOrganizationIsEnough(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);

        $database->executeStatement('UPDATE organization SET added_by_player_id = :player WHERE id = :id', [
            'player' => PlayerFixture::PLAYER_PRIVATE,
            'id' => OrganizationFixture::ORGANIZATION_RIVERBEND,
        ]);

        self::assertTrue($this->assertParity(PlayerFixture::PLAYER_PRIVATE));
    }

    /**
     * @return bool whether the player organises anything (both readers agreed)
     */
    private function assertParity(string $playerId): bool
    {
        $container = self::getContainer();
        $database = $container->get(Connection::class);

        $organises = (bool) $database->fetchOne('SELECT ' . OrganizerSql::organizesAnything(':playerId'), ['playerId' => $playerId]);
        $counted = $container->get(GetEventsViewerData::class)->forPlayer($playerId)->organizedCount() > 0;

        self::assertSame($counted, $organises, 'organizesAnything() and organizedCount() disagree for ' . $playerId);

        $userId = $database->fetchOne('SELECT user_id FROM player WHERE id = :id', ['id' => $playerId]);

        if (is_string($userId)) {
            self::assertSame($organises, $container->get(GetPlayerProfile::class)->byUserId($userId)->organizesEvents, 'The profile row disagrees for ' . $playerId);
        }

        return $organises;
    }
}
