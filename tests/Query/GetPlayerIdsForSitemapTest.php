<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPlayerIdsForSitemap;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerIdsForSitemapTest extends KernelTestCase
{
    private GetPlayerIdsForSitemap $query;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPlayerIdsForSitemap::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testListsPublicNamedPlayersWithResults(): void
    {
        $playerIds = $this->query->publicWithResults();

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_WITH_FAVORITES, $playerIds);
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $playerIds);
    }

    public function testPlayerWithoutAnyResultIsLeftOut(): void
    {
        self::assertSame(0, $this->groupResultsOf(PlayerFixture::PLAYER_WITH_FAVORITES), 'Premise: Michael is in no pair or team result');

        // Hand all of Michael's own times to somebody else
        $this->moveOwnTimes(from: PlayerFixture::PLAYER_WITH_FAVORITES, to: PlayerFixture::PLAYER_ADMIN);

        $playerIds = $this->query->publicWithResults();

        self::assertNotContains(PlayerFixture::PLAYER_WITH_FAVORITES, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $playerIds);
    }

    public function testMemberOfAPairOrTeamHasResultsToo(): void
    {
        self::assertGreaterThan(0, $this->groupResultsOf(PlayerFixture::PLAYER_REGULAR), 'Premise: John is in a pair or team result');

        // John keeps only his memberships in pair / team results
        $this->moveOwnTimes(from: PlayerFixture::PLAYER_REGULAR, to: PlayerFixture::PLAYER_ADMIN);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->query->publicWithResults());
    }

    public function testPlayerWithoutANameIsLeftOut(): void
    {
        $this->database->executeStatement(
            'UPDATE player SET name = NULL WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->query->publicWithResults());
    }

    private function moveOwnTimes(string $from, string $to): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET player_id = :to WHERE player_id = :from',
            ['from' => $from, 'to' => $to],
        );
    }

    private function groupResultsOf(string $playerId): int
    {
        $count = $this->database->fetchOne(
            "SELECT COUNT(*) FROM puzzle_solving_time
             WHERE team IS NOT NULL
                AND (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))",
            ['playerId' => $playerId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
