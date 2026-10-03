<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The add sheet of the compare page (docs/features/player-comparison.md "Add sheet"): its lazy JSON - every favorite +
 * a few co-puzzlers (comparison_people), the player search (comparison_player_search) - and what the page renders for
 * it. PLAYER_WITH_STRIPE is a member, PLAYER_REGULAR is not (and blocks PLAYER_PRIVATE).
 *
 * @phpstan-type Person array{ref: string, id: string, label: string, code: string, country: null|string, countryName: null|string, avatar: null|string, favorite: bool, tier?: string}
 */
final class ComparisonAddSheetTest extends WebTestCase
{
    use ComparisonSeeding;
    use QueryCountAssertions;

    public function testEveryFavoriteIsListedByNameAndThePeopleYouPuzzleWithApart(): void
    {
        $browser = self::createClient();
        $viewer = PlayerFixture::PLAYER_WITH_STRIPE;
        $puzzle = $this->seedPuzzle(500);
        $day = new DateTimeImmutable('-3 days');

        $favorites = [];

        for ($i = 10; $i >= 1; $i--) {
            $favorites[] = $this->seedPlayer(sprintf('Fav %02d', $i), country: $i === 1 ? 'cz' : null);
        }

        // Revealed by the allow list: listed like anybody else; hidden private and blocked: never offered
        $revealed = $this->seedPlayer('Revealed fav', private: true);
        $this->seedAllowList($revealed, $viewer);
        $hidden = $this->seedPlayer('Hidden fav', private: true);
        $blocked = $this->seedPlayer('Blocked fav');
        $this->seedBlock($viewer, $blocked);
        $this->setFavorites($viewer, [...$favorites, $revealed, $hidden, $blocked]);

        // Fav 10 is a co-puzzler too - listed once, with the favorites
        $this->seedTime($viewer, $puzzle, 3000, $day, teamId: $this->seedTeam([$viewer, $favorites[0]]));

        $often = $this->seedPlayer('Often together');
        $team = $this->seedTeam([$viewer, $often], ['Guest Friend']);

        foreach ([1, 2, 3] as $n) {
            $this->seedTime($viewer, $puzzle, 3000 + $n, $day->modify("-{$n} days"), teamId: $team);
        }

        $once = $this->seedPlayer('Once together');
        $this->seedTime($viewer, $puzzle, 3500, $day, teamId: $this->seedTeam([$viewer, $once]));

        // A pair the viewer archived stays out of their shortcuts
        $archived = $this->seedPlayer('Archived partner');
        $archivedTeam = $this->seedTeam([$viewer, $archived]);
        $this->seedTime($viewer, $puzzle, 3600, $day, teamId: $archivedTeam);
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO puzzling_team_archive (id, team_id, player_id, archived_at) VALUES (:id, :team, :player, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'team' => $archivedTeam, 'player' => $viewer],
        );

        TestingLogin::asPlayer($browser, $viewer);
        $browser->request('GET', '/en/compare/people.json');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $people = self::people($browser);

        self::assertSame(
            ['Fav 01', 'Fav 02', 'Fav 03', 'Fav 04', 'Fav 05', 'Fav 06', 'Fav 07', 'Fav 08', 'Fav 09', 'Fav 10', 'Revealed fav'],
            array_column($people['favorites'], 'label'),
        );
        self::assertSame([true], array_values(array_unique(array_column($people['favorites'], 'favorite'))));
        self::assertSame(['Often together', 'Once together'], array_column($people['coPuzzlers'], 'label'));
        self::assertSame([false], array_values(array_unique(array_column($people['coPuzzlers'], 'favorite'))));

        $first = $people['favorites'][0];
        self::assertSame('p-' . $first['id'], $first['ref']);
        self::assertSame('cz', $first['country']);
        self::assertSame('Czechia', $first['countryName']);
        self::assertNull($first['avatar']);
        self::assertSame(['ref', 'id', 'label', 'code', 'country', 'countryName', 'avatar', 'favorite', 'tier'], array_keys($first));
    }

    public function testMembersGetTheTierOfTheLeaderboardsEverybodyElseNoTierAtAll(): void
    {
        $browser = self::createClient();
        $expert = $this->seedPlayer('Expert');
        $this->seedSkill($expert, 90.0, 5);
        $optedOut = $this->seedPlayer('Opted out', rankingOptedOut: true);
        $this->seedSkill($optedOut, 99.5, 7);
        $newcomer = $this->seedPlayer('Newcomer');
        // Skill is computed for 500 pieces only - another piece count is no tier
        $this->seedSkill($newcomer, 99.5, 7, pieces: 1000);

        $this->setFavorites(PlayerFixture::PLAYER_WITH_STRIPE, [$expert, $optedOut, $newcomer]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/compare/people.json');

        self::assertSame(
            ['Expert' => 'expert', 'Newcomer' => 'unknown', 'Opted out' => 'locked'],
            array_column(self::people($browser)['favorites'], 'tier', 'label'),
        );

        $browser->request('GET', '/en/compare/players.json?query=Expert');
        $results = self::results($browser);
        self::assertSame('expert', self::byLabel($results, 'Expert')['tier'] ?? null);

        $this->setFavorites(PlayerFixture::PLAYER_REGULAR, [$expert, $optedOut, $newcomer]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/compare/people.json');
        $favorites = self::people($browser)['favorites'];
        self::assertCount(3, $favorites);

        foreach ($favorites as $favorite) {
            self::assertArrayNotHasKey('tier', $favorite);
        }

        $browser->request('GET', '/en/compare/players.json?query=Expert');
        self::assertArrayNotHasKey('tier', self::byLabel(self::results($browser), 'Expert'));
    }

    public function testSearchStarsFavoritesAndNeverOffersAPrivatePlayerHiddenFromTheViewer(): void
    {
        $browser = self::createClient();
        $favorite = $this->seedPlayer('Zebulon Favorite', code: 'zebfav');
        $other = $this->seedPlayer('Zebulon Other', code: 'zebother');
        $this->seedPlayer('Zebulon Hidden', private: true, code: 'zebhidden');
        $revealed = $this->seedPlayer('Zebulon Revealed', private: true, code: 'zebrevealed');
        $this->seedAllowList($revealed, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->setFavorites(PlayerFixture::PLAYER_WITH_STRIPE, [$favorite]);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/compare/players.json?query=Zebulon');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $results = self::results($browser);
        $labels = array_column($results, 'label');
        sort($labels);
        self::assertSame(['Zebulon Favorite', 'Zebulon Other', 'Zebulon Revealed'], $labels);
        self::assertTrue(self::byLabel($results, 'Zebulon Favorite')['favorite']);
        self::assertFalse(self::byLabel($results, 'Zebulon Other')['favorite']);
        self::assertSame('p-' . $other, self::byLabel($results, 'Zebulon Other')['ref']);

        // Not even by the exact code: that would tie the code to the person, and the add would be refused
        $browser->request('GET', '/en/compare/players.json?query=%23ZEBHIDDEN');
        $byHiddenCode = self::results($browser);
        self::assertSame([], $byHiddenCode);

        // "#code" is a code
        $browser->request('GET', '/en/compare/players.json?query=%23zebother');
        $byCode = self::results($browser);
        self::assertSame(['Zebulon Other'], array_column($byCode, 'label'));

        $browser->request('GET', '/en/compare/players.json?query=z');
        $tooShort = self::results($browser);
        self::assertSame([], $tooShort);
    }

    public function testTheListsCostTheSameForThreeFavoritesAndForForty(): void
    {
        $browser = self::createClient();
        $viewer = PlayerFixture::PLAYER_WITH_STRIPE;
        $favorites = [];

        for ($i = 1; $i <= 40; $i++) {
            $favorites[] = $this->seedPlayer('Budget ' . $i);
        }

        $this->seedSkill($favorites[0], 90.0, 5);
        $this->setFavorites($viewer, array_slice($favorites, 0, 3));
        TestingLogin::asPlayer($browser, $viewer);

        // The first request of the day also does one-off work (sign-in bookkeeping, activity)
        $browser->request('GET', '/en/compare/people.json');

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/compare/people.json');
        self::assertCount(3, self::people($browser)['favorites']);
        $few = $this->queryCount($browser);

        $this->setFavorites($viewer, $favorites);
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/compare/people.json');
        self::assertCount(40, self::people($browser)['favorites']);

        self::assertSame($few, $this->queryCount($browser), 'Every favorite costs nothing extra - identities and tiers are one statement each');
    }

    public function testThePageRendersTheSheetButFetchesNothingForIt(): void
    {
        $browser = self::createClient();
        $this->setFavorites(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_ADMIN]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/compare?kind=solo');

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/compare?kind=solo');
        self::assertResponseIsSuccessful();

        $sheet = $crawler->filter('[data-testid="comparison-add-sheet"]');
        self::assertSame('/en/compare/people.json', $sheet->attr('data-comparison-add-suggestions-url-value'));
        self::assertSame('/en/compare/players.json', $sheet->attr('data-comparison-add-search-url-value'));
        // Placeholder rows hold the space until the lists arrive
        self::assertCount(3, $crawler->filter('[data-testid="comparison-add-lists"] .cmp-option--skeleton'));

        // A member's sheet carries every tier icon of the leaderboards, ready to be cloned
        $tiers = $sheet->filter('template[data-comparison-add-target="tierIcon"]');
        self::assertSame(
            ['legend', 'master', 'expert', 'advanced', 'proficient', 'apprentice', 'enthusiast', 'unknown', 'locked'],
            $tiers->each(static fn ($template): string => (string) $template->attr('data-tier')),
        );
        self::assertStringContainsString('<use href="/rank-icons-sprite.svg#rank-expert"/>', (string) $browser->getResponse()->getContent());

        $texts = json_decode((string) $sheet->attr('data-comparison-add-texts-value'), true);
        self::assertIsArray($texts);
        self::assertSame(['message' => 'Your favorites (%count%)', 'locale' => 'en'], $texts['favoritesHeading']);
        self::assertSame('People you puzzle with', $texts['coPuzzlersHeading']);

        // Lazy: nothing of the lists is read by the page itself
        foreach ($this->executedSql($browser) as $sql) {
            self::assertStringNotContainsString('puzzling_team_archive', $sql);
            self::assertStringNotContainsString('player_skill skill', $sql);
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/compare?kind=solo');
        // Everybody else gets the lock the leaderboards show them
        self::assertSame(
            ['locked'],
            $crawler->filter('[data-testid="comparison-add-sheet"] template[data-comparison-add-target="tierIcon"]')
                ->each(static fn ($template): string => (string) $template->attr('data-tier')),
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/compare?kind=pairs');
        $sheet = $crawler->filter('[data-testid="comparison-add-sheet"]');
        self::assertSame('/en/compare/teams.json?kind=pairs', $sheet->attr('data-comparison-add-suggestions-url-value'));
        self::assertCount(0, $sheet->filter('template'));
    }

    /**
     * @param list<string> $playerIds
     */
    private function setFavorites(string $playerId, array $playerIds): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET favorite_players = :favorites WHERE id = :id',
            ['favorites' => json_encode($playerIds, JSON_THROW_ON_ERROR), 'id' => $playerId],
        );
    }

    /**
     * @return array{favorites: list<Person>, coPuzzlers: list<Person>}
     */
    private static function people(KernelBrowser $browser): array
    {
        /** @var array{favorites: list<Person>, coPuzzlers: list<Person>} $people */
        $people = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $people;
    }

    /**
     * @return list<Person>
     */
    private static function results(KernelBrowser $browser): array
    {
        /** @var list<Person> $results */
        $results = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }

    /**
     * @param list<Person> $people
     * @return Person
     */
    private static function byLabel(array $people, string $label): array
    {
        foreach ($people as $person) {
            if ($person['label'] === $label) {
                return $person;
            }
        }

        self::fail(sprintf('"%s" is not in the list.', $label));
    }
}
