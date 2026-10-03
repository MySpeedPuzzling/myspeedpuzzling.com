<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetSpotlightPeople;
use SpeedPuzzling\Web\Results\SpotlightPerson;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SpotlightSeeding;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/features/players-page/README.md - the spotlight's three lists. Fixture countries: PLAYER_REGULAR and
 * PLAYER_ADMIN cz, PLAYER_WITH_FAVORITES de, PLAYER_WITH_STRIPE gb, PLAYER_PRIVATE us (private).
 * UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE.
 */
final class GetSpotlightPeopleTest extends KernelTestCase
{
    use SpotlightSeeding;

    private GetSpotlightPeople $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetSpotlightPeople::class);
        $this->recalculateCommunityStats();
        $this->clearSpotlightNumbers();
    }

    public function testMostActiveByPuzzlesThisMonthThenPieces(): void
    {
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_REGULAR, ['solves_this_month' => 5, 'pieces_this_month' => 2500]);
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_ADMIN, ['solves_this_month' => 5, 'pieces_this_month' => 3000]);
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_WITH_FAVORITES, ['solves_this_month' => 7, 'pieces_this_month' => 3500]);
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_WITH_STRIPE, ['solves_this_month' => 5, 'pieces_this_month' => 3000]);

        $mostActive = $this->query->forScope(CommunityScope::world())->mostActive;

        self::assertSame(
            [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR],
            self::ids($mostActive),
        );
        // The same puzzles and pieces share a rank
        self::assertSame([1, 2, 2, 4], array_map(static fn (SpotlightPerson $person): int => $person->rank, $mostActive));
        self::assertSame(7, $mostActive[0]->solvesThisMonth);
    }

    public function testMostFollowedByFavoritesLeavesOutNobodysFavorites(): void
    {
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_REGULAR, ['favorites_count' => 3]);
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_ADMIN, ['favorites_count' => 1]);

        $mostFollowed = $this->query->forScope(CommunityScope::world())->mostFollowed;

        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN], self::ids($mostFollowed));
        self::assertSame(3, $mostFollowed[0]->favoritesCount);
    }

    public function testNewFacesJoinedInTheLastTwoWeeksAndLoggedAResult(): void
    {
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_REGULAR, ['solved_total' => 3], '-2 days');
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_WITH_STRIPE, ['solved_total' => 1], '-6 days');
        // Too long ago
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_ADMIN, ['solved_total' => 5], '-20 days');
        // No result yet
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_WITH_FAVORITES, ['solved_total' => 0], '-1 day');

        $newFaces = $this->query->forScope(CommunityScope::world())->newFaces;

        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE], self::ids($newFaces));
        self::assertSame(3, $newFaces[0]->solvedTotal);
    }

    public function testACountryListsOnlyItsOwnPlayers(): void
    {
        foreach ([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE] as $playerId) {
            $this->setSpotlightNumbers($playerId, ['solves_this_month' => 2, 'favorites_count' => 1, 'solved_total' => 2], '-3 days');
        }

        $czechia = $this->query->forScope(CommunityScope::country(CountryCode::cz));

        foreach ([$czechia->mostActive, $czechia->mostFollowed, $czechia->newFaces] as $list) {
            self::assertEqualsCanonicalizing([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN], self::ids($list));
        }

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], self::ids($this->query->forScope(CommunityScope::country(CountryCode::de))->mostActive));
        self::assertCount(4, $this->query->forScope(CommunityScope::world())->mostActive);
    }

    public function testEveryListStopsAtTen(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->seedSpotlightPlayer('Busy puzzler ' . $i, 'cz', solvesThisMonth: 20 + $i, favoritesCount: $i, solvedTotal: $i, registeredAt: sprintf('-%d hours', $i));
        }

        $people = $this->query->forScope(CommunityScope::country(CountryCode::cz));

        self::assertCount(GetSpotlightPeople::LIMIT, $people->mostActive);
        self::assertCount(GetSpotlightPeople::LIMIT, $people->mostFollowed);
        self::assertCount(GetSpotlightPeople::LIMIT, $people->newFaces);
        self::assertSame('Busy puzzler 12', $people->mostActive[0]->playerName);
        self::assertSame('Busy puzzler 1', $people->newFaces[0]->playerName);
    }

    public function testPrivateProfilesAreLeftOutForEverybody(): void
    {
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_PRIVATE, ['solves_this_month' => 99, 'favorites_count' => 9, 'solved_total' => 1], '-1 day');

        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $this->allListed(CommunityScope::world()));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $this->allListed(CommunityScope::world()));

        // Only the setting kept them out
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET is_private = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_PRIVATE],
        );
        $people = $this->query->forScope(CommunityScope::country(CountryCode::us));
        self::assertSame([PlayerFixture::PLAYER_PRIVATE], self::ids($people->mostActive));
        self::assertSame([PlayerFixture::PLAYER_PRIVATE], self::ids($people->mostFollowed));
        self::assertSame([PlayerFixture::PLAYER_PRIVATE], self::ids($people->newFaces));
    }

    public function testPlayersTheViewerHidesAreLeftOutAndTheRanksCloseUp(): void
    {
        // The fixture's blocked player is a private profile, which the lists leave out anyway
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET is_private = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_PRIVATE],
        );
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_PRIVATE, ['solves_this_month' => 9, 'favorites_count' => 9, 'solved_total' => 1], '-1 day');
        $this->setSpotlightNumbers(PlayerFixture::PLAYER_ADMIN, ['solves_this_month' => 4, 'favorites_count' => 4, 'solved_total' => 1], '-2 days');

        $guest = $this->query->forScope(CommunityScope::world());
        self::assertSame([PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN], self::ids($guest->mostActive));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        $blocker = $this->query->forScope(CommunityScope::world());

        foreach ([$blocker->mostActive, $blocker->mostFollowed, $blocker->newFaces] as $list) {
            self::assertSame([PlayerFixture::PLAYER_ADMIN], self::ids($list));
        }
        self::assertSame(1, $blocker->mostActive[0]->rank);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertSame([PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN], self::ids($this->query->forScope(CommunityScope::world())->mostActive));
    }

    /**
     * @return list<string>
     */
    private function allListed(CommunityScope $scope): array
    {
        $people = $this->query->forScope($scope);

        return [...self::ids($people->mostActive), ...self::ids($people->mostFollowed), ...self::ids($people->newFaces)];
    }

    /**
     * @param list<SpotlightPerson> $people
     * @return list<string>
     */
    private static function ids(array $people): array
    {
        return array_map(static fn (SpotlightPerson $person): string => $person->playerId, $people);
    }
}
