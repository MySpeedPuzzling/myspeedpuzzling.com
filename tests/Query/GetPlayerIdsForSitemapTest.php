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
        $playerIds = $this->playerIds();

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_WITH_FAVORITES, $playerIds);
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $playerIds);
    }

    public function testPlayerWithoutAnyResultIsLeftOut(): void
    {
        self::assertSame(0, $this->groupResultsOf(PlayerFixture::PLAYER_WITH_FAVORITES), 'Premise: Michael is in no pair or team result');

        // Hand all of Michael's own times to somebody else
        $this->moveOwnTimes(from: PlayerFixture::PLAYER_WITH_FAVORITES, to: PlayerFixture::PLAYER_ADMIN);

        $playerIds = $this->playerIds();

        self::assertNotContains(PlayerFixture::PLAYER_WITH_FAVORITES, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $playerIds);
    }

    public function testMemberOfAPairOrTeamHasResultsToo(): void
    {
        self::assertGreaterThan(0, $this->groupResultsOf(PlayerFixture::PLAYER_REGULAR), 'Premise: John is in a pair or team result');

        // John keeps only his memberships in pair / team results
        $this->moveOwnTimes(from: PlayerFixture::PLAYER_REGULAR, to: PlayerFixture::PLAYER_ADMIN);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->playerIds());
    }

    public function testPlayerWithoutANameIsLeftOut(): void
    {
        $this->database->executeStatement(
            'UPDATE player SET name = NULL WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->playerIds());
    }

    public function testCountMatchesTheListedPlayers(): void
    {
        self::assertSame(count($this->playerIds()), $this->query->countPublicWithResults());
    }

    public function testPagesSplitTheListInOrder(): void
    {
        $all = $this->playerIds();
        self::assertGreaterThan(2, count($all), 'Premise: enough players to split');

        $firstPage = array_column($this->query->publicWithResultsPage(limit: 2, offset: 0), 'id');
        $secondPage = array_column($this->query->publicWithResultsPage(limit: 2, offset: 2), 'id');

        self::assertSame(array_slice($all, 0, 2), $firstPage);
        self::assertSame(array_slice($all, 2, 2), $secondPage);
    }

    public function testLastmodIsTheDayOfTheLatestResult(): void
    {
        $latest = $this->database->fetchOne(
            "SELECT to_char(MAX(tracked_at), 'YYYY-MM-DD') FROM puzzle_solving_time WHERE player_id = :id",
            ['id' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        $rows = $this->query->publicWithResultsPage(limit: 10_000, offset: 0);
        $lastmods = array_column($rows, 'lastmod', 'id');

        self::assertSame($latest, $lastmods[PlayerFixture::PLAYER_WITH_FAVORITES]);
    }

    /**
     * @return list<string>
     */
    private function playerIds(): array
    {
        return array_column($this->query->publicWithResultsPage(limit: 10_000, offset: 0), 'id');
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
