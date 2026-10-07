<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Query\GetPlayersDirectory;
use SpeedPuzzling\Web\Results\PlayersDirectoryCard;
use SpeedPuzzling\Web\Results\PlayersDirectoryPage;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\PlayersDirectoryCriteria;
use SpeedPuzzling\Web\Value\PlayersDirectorySort;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Public fixture players: John Doe (REGULAR, cz), Admin User (ADMIN, cz), Michael Johnson (WITH_FAVORITES, de), Sarah
 * Williams (WITH_STRIPE, gb), Dana Twin + Tom Twin (DuplicateResultsFixture, no country). Jane Smith (PRIVATE, us) is
 * private, and PLAYER_REGULAR blocks her (UserBlockFixture).
 */
final class GetPlayersDirectoryTest extends KernelTestCase
{
    private const array PUBLIC_PLAYERS = [
        PlayerFixture::PLAYER_REGULAR,
        PlayerFixture::PLAYER_ADMIN,
        PlayerFixture::PLAYER_WITH_FAVORITES,
        PlayerFixture::PLAYER_WITH_STRIPE,
        DuplicateResultsFixture::PLAYER_TWINS,
        DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE,
        SuspiciousTimesFixture::PLAYER_STEADY,
        SuspiciousTimesFixture::PLAYER_EDITION,
        SuspiciousTimesFixture::PLAYER_GROUP,
        SuspiciousTimesFixture::PLAYER_MARKED,
        SuspiciousTimesFixture::PLAYER_FLAGGED,
        SuspiciousTimesFixture::PLAYER_PARTNER,
    ];

    private GetPlayersDirectory $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPlayersDirectory::class);
        $this->database = self::getContainer()->get(Connection::class);
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
    }

    public function testTheWorldListsEveryPublicPlayerAndNobodyPrivate(): void
    {
        $page = $this->search(CommunityScope::world());

        self::assertEqualsCanonicalizing(self::PUBLIC_PLAYERS, $this->ids($page));
        self::assertSame(count(self::PUBLIC_PLAYERS), $page->total);
        self::assertFalse($page->hasMore());
    }

    public function testACountryListsOnlyItsPlayers(): void
    {
        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN],
            $this->ids($this->search(CommunityScope::country(CountryCode::cz))),
        );

        // Jane Smith is the only player from the US, and private
        $unitedStates = $this->search(CommunityScope::country(CountryCode::us));
        self::assertSame([], $unitedStates->cards);
        self::assertSame(0, $unitedStates->total);
    }

    public function testThePlayerTheViewerBlocksIsLeftOut(): void
    {
        // The fixture's blocked player is a private profile, which the directory leaves out anyway
        $this->database->executeStatement('UPDATE player SET is_private = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);

        self::assertSame([PlayerFixture::PLAYER_PRIVATE], $this->ids($this->search(CommunityScope::country(CountryCode::us))));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertSame([], $this->ids($this->search(CommunityScope::country(CountryCode::us))));
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $this->ids($this->search(CommunityScope::world())));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertSame([PlayerFixture::PLAYER_PRIVATE], $this->ids($this->search(CommunityScope::country(CountryCode::us))));
    }

    public function testActiveThisMonth(): void
    {
        $this->database->executeStatement('UPDATE community_player_stats SET solves_this_month = 0');
        $this->database->executeStatement(
            'UPDATE community_player_stats SET solves_this_month = 3 WHERE player_id IN (:ids)',
            ['ids' => [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE]],
            ['ids' => ArrayParameterType::STRING],
        );

        $page = $this->search(CommunityScope::world(), activeThisMonth: true);

        self::assertSame([PlayerFixture::PLAYER_ADMIN], $this->ids($page));
        self::assertSame(3, $page->cards[0]->solvesThisMonth);
    }

    public function testCompetesInEventsMeansAParticipantOfAPublicEvent(): void
    {
        // Connected WJPC 2024 participants include John Doe, Michael Johnson and the private Jane Smith (other fixtures
        // may add more - the definition is what counts, not the exact set)
        $competing = $this->ids($this->search(CommunityScope::world(), competesInEvents: true));
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $competing);
        self::assertContains(PlayerFixture::PLAYER_WITH_FAVORITES, $competing);
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $competing);

        // A participant of an event still waiting for approval does not count
        $this->database->executeStatement('DELETE FROM competition_participant WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_WITH_STRIPE]);
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, competition_id, player_id, source) VALUES (:id, 'Sarah', :competition, :player, 'imported')",
            ['id' => Uuid::uuid7()->toString(), 'competition' => CompetitionFixture::COMPETITION_UNAPPROVED, 'player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        self::assertNotContains(PlayerFixture::PLAYER_WITH_STRIPE, $this->ids($this->search(CommunityScope::world(), competesInEvents: true)));

        $cards = $this->cardsById($this->search(CommunityScope::world()));
        self::assertTrue($cards[PlayerFixture::PLAYER_REGULAR]->competesInEvents);
        self::assertFalse($cards[PlayerFixture::PLAYER_WITH_STRIPE]->competesInEvents);
    }

    public function testSwapsPuzzles(): void
    {
        $page = $this->search(CommunityScope::world(), swapsPuzzles: true);

        self::assertEqualsCanonicalizing([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE], $this->ids($page));
        self::assertTrue($page->cards[0]->swapsPuzzles);
        self::assertFalse($this->cardsById($this->search(CommunityScope::world()))[PlayerFixture::PLAYER_REGULAR]->swapsPuzzles);
    }

    public function testOnInstagram(): void
    {
        $this->database->executeStatement("UPDATE player SET instagram = 'michael.puzzles' WHERE id = :id", ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]);
        $this->database->executeStatement("UPDATE player SET instagram = '  ' WHERE id = :id", ['id' => PlayerFixture::PLAYER_REGULAR]);

        $page = $this->search(CommunityScope::world(), onInstagram: true);

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $this->ids($page));
        self::assertTrue($page->cards[0]->onInstagram);
    }

    public function testFiltersCombine(): void
    {
        // Swaps puzzles: Admin User (cz) and Sarah Williams (gb)
        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN],
            $this->ids($this->search(CommunityScope::country(CountryCode::cz), swapsPuzzles: true)),
        );

        // Every filter narrows: two of them together are exactly the players both keep
        $competing = $this->ids($this->search(CommunityScope::world(), competesInEvents: true));
        $swapping = $this->ids($this->search(CommunityScope::world(), swapsPuzzles: true));
        self::assertEqualsCanonicalizing(
            array_values(array_intersect($competing, $swapping)),
            $this->ids($this->search(CommunityScope::world(), competesInEvents: true, swapsPuzzles: true)),
        );
    }

    public function testMostActiveIsTheDefaultWithPiecesBreakingTies(): void
    {
        $this->setStats(PlayerFixture::PLAYER_REGULAR, ['solves_this_month' => 5, 'pieces_this_month' => 2500]);
        $this->setStats(PlayerFixture::PLAYER_ADMIN, ['solves_this_month' => 5, 'pieces_this_month' => 5000]);
        $this->setStats(PlayerFixture::PLAYER_WITH_STRIPE, ['solves_this_month' => 9, 'pieces_this_month' => 4500]);

        self::assertSame(
            [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR],
            array_slice($this->ids($this->search(CommunityScope::world())), 0, 3),
        );
    }

    public function testRecentlyActiveLeavesPlayersWithoutAResultLast(): void
    {
        $this->database->executeStatement('UPDATE community_player_stats SET last_solved_at = NULL');
        $this->setStats(PlayerFixture::PLAYER_WITH_FAVORITES, ['last_solved_at' => '2026-09-01 10:00:00']);
        $this->setStats(PlayerFixture::PLAYER_ADMIN, ['last_solved_at' => '2026-09-20 10:00:00']);

        $ids = $this->ids($this->search(CommunityScope::world(), sort: PlayersDirectorySort::Recent));

        self::assertSame([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES], array_slice($ids, 0, 2));
        self::assertCount(count(self::PUBLIC_PLAYERS), $ids);
    }

    public function testNewest(): void
    {
        $this->database->executeStatement("UPDATE player SET registered_at = '2024-01-01 00:00:00'");
        $this->database->executeStatement("UPDATE player SET registered_at = '2026-10-01 00:00:00' WHERE id = :id", ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        $this->database->executeStatement("UPDATE player SET registered_at = '2026-09-01 00:00:00' WHERE id = :id", ['id' => DuplicateResultsFixture::PLAYER_TWINS]);

        self::assertSame(
            [PlayerFixture::PLAYER_WITH_STRIPE, DuplicateResultsFixture::PLAYER_TWINS],
            array_slice($this->ids($this->search(CommunityScope::world(), sort: PlayersDirectorySort::Newest)), 0, 2),
        );
    }

    public function testMostFollowed(): void
    {
        // Michael Johnson has John Doe and Admin User in his favorites
        $page = $this->search(CommunityScope::world(), sort: PlayersDirectorySort::Followed);

        self::assertEqualsCanonicalizing([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN], array_slice($this->ids($page), 0, 2));
        self::assertSame(1, $page->cards[0]->favoritesCount);

        $this->setStats(PlayerFixture::PLAYER_WITH_STRIPE, ['favorites_count' => 7]);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $this->ids($this->search(CommunityScope::world(), sort: PlayersDirectorySort::Followed))[0]);
    }

    public function testAToZ(): void
    {
        self::assertSame(
            ['Admin User', 'Dana Twin', 'Eda Edition', 'Fay Flagged', 'Gina Group', 'John Doe', 'Mia Marked', 'Michael Johnson', 'Pat Partner', 'Sam Steady', 'Sarah Williams', 'Tom Twin'],
            array_map(
                static fn (PlayersDirectoryCard $card): null|string => $card->playerName,
                $this->search(CommunityScope::world(), sort: PlayersDirectorySort::Name)->cards,
            ),
        );
    }

    public function testAShorterPageKnowsHowManyMatchInAll(): void
    {
        $page = $this->query->search(new PlayersDirectoryCriteria(CommunityScope::world(), sort: PlayersDirectorySort::Name, limit: 2));

        self::assertSame([PlayerFixture::PLAYER_ADMIN, DuplicateResultsFixture::PLAYER_TWINS], $this->ids($page));
        self::assertSame(count(self::PUBLIC_PLAYERS), $page->total);
        self::assertTrue($page->hasMore());
    }

    public function testAPlayerWithoutComputedNumbersYetIsListedWithZeros(): void
    {
        $this->database->executeStatement('DELETE FROM community_player_stats WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);

        $card = $this->cardsById($this->search(CommunityScope::world()))[PlayerFixture::PLAYER_WITH_STRIPE];

        self::assertSame(0, $card->solvesThisMonth);
        self::assertSame(0, $card->favoritesCount);
        self::assertNull($card->best500Seconds);
        self::assertSame(CountryCode::gb, $card->playerCountry);
    }

    private function search(
        CommunityScope $scope,
        PlayersDirectorySort $sort = PlayersDirectorySort::Active,
        bool $activeThisMonth = false,
        bool $competesInEvents = false,
        bool $swapsPuzzles = false,
        bool $onInstagram = false,
    ): PlayersDirectoryPage {
        return $this->query->search(new PlayersDirectoryCriteria(
            scope: $scope,
            sort: $sort,
            activeThisMonth: $activeThisMonth,
            competesInEvents: $competesInEvents,
            swapsPuzzles: $swapsPuzzles,
            onInstagram: $onInstagram,
        ));
    }

    /**
     * @param array<string, int|string> $values
     */
    private function setStats(string $playerId, array $values): void
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", array_keys($values)));

        $this->database->executeStatement(
            "UPDATE community_player_stats SET {$assignments} WHERE player_id = :playerId",
            $values + ['playerId' => $playerId],
        );
    }

    /**
     * @return list<string>
     */
    private function ids(PlayersDirectoryPage $page): array
    {
        return array_map(static fn (PlayersDirectoryCard $card): string => $card->playerId, $page->cards);
    }

    /**
     * @return array<string, PlayersDirectoryCard>
     */
    private function cardsById(PlayersDirectoryPage $page): array
    {
        $cards = [];

        foreach ($page->cards as $card) {
            $cards[$card->playerId] = $card;
        }

        return $cards;
    }
}
