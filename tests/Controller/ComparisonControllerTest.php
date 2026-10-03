<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The compare page (docs/features/player-comparison.md). Fixture line-ups (ComparisonSubjectFixture):
 * PLAYER_REGULAR (free) - Solo: himself + PLAYER_WITH_STRIPE, at the free cap;
 * PLAYER_WITH_STRIPE (member) - Solo: herself + PLAYER_ADMIN + PLAYER_REGULAR, Pairs: the PLAYER_REGULAR & PLAYER_PRIVATE
 * pair (added last).
 */
final class ComparisonControllerTest extends WebTestCase
{
    use ComparisonSeeding;
    use QueryCountAssertions;

    private const string STRIPE_REF = 'p-' . PlayerFixture::PLAYER_WITH_STRIPE;
    private const string ADMIN_REF = 'p-' . PlayerFixture::PLAYER_ADMIN;
    private const string REGULAR_REF = 'p-' . PlayerFixture::PLAYER_REGULAR;

    public function testAnonymousVisitorIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/compare');

        self::assertResponseRedirects();
    }

    public function testMemberComparesTheirSoloLineUpInALeagueTable(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/compare?kind=solo');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');

        self::assertSame(['You', 'Admin User', 'John Doe'], self::chipNames($crawler));
        self::assertSame('3 / 10', trim($crawler->filter('[data-testid="comparison-cap"]')->text()));
        self::assertCount(3, $crawler->filter('[data-testid="comparison-league-row"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-head-to-head"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-view-switch"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-highlight"]'));
        // Members remove themselves too
        self::assertCount(3, $crawler->filter('[data-testid="comparison-chip-remove"]'));
        self::assertGreaterThan(0, $crawler->filter('[data-testid="comparison-row"]')->count());
        // The time opens the result detail (all attempts) in the modal
        self::assertStringStartsWith('/en/result/', (string) $crawler->filter('[data-testid="comparison-row"] .cmp-time')->first()->attr('href'));
        self::assertSame('modal-frame', $crawler->filter('[data-testid="comparison-row"] .cmp-time')->first()->attr('data-turbo-frame'));
    }

    public function testTheLineUpAddedToLastOpensAndTheSwitchCountsEveryKind(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/compare');

        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('[data-testid="comparison-kind-pairs"]')->attr('aria-pressed'));
        self::assertSame('false', $crawler->filter('[data-testid="comparison-kind-solo"]')->attr('aria-pressed'));
        self::assertStringContainsString('3', $crawler->filter('[data-testid="comparison-kind-solo"]')->text());
        self::assertStringContainsString('1', $crawler->filter('[data-testid="comparison-kind-pairs"]')->text());
        self::assertStringContainsString('0', $crawler->filter('[data-testid="comparison-kind-teams"]')->text());

        // A private member of a pair is masked for a viewer she did not allow
        self::assertSame(['John Doe & Hidden Puzzler'], self::chipNames($crawler));
        self::assertStringNotContainsString('Jane Smith', (string) $browser->getResponse()->getContent());
        // One pair is nothing to compare yet
        self::assertCount(1, $crawler->filter('[data-testid="comparison-empty-line-up"]'));
        self::assertSame('/en/compare?kind=pairs', $crawler->filter('[data-testid="comparison"]')->attr('data-comparison-page-url-value'));
    }

    public function testFreePlayerGetsTheHeadToHeadAndOneQuietLineAboutMembership(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/compare');

        self::assertResponseIsSuccessful();
        self::assertSame(['You', 'Sarah Williams'], self::chipNames($crawler));
        self::assertSame('2 / 2', trim($crawler->filter('[data-testid="comparison-cap"]')->text()));
        // Without a membership you stay in your own line-up
        self::assertCount(1, $crawler->filter('[data-testid="comparison-chip-remove"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-head-to-head"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-league"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-view-switch"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-members-line"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-first-tries-locked"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-similar-locked"]'));
        // Exactly two subjects: always duel rows
        self::assertCount(1, $crawler->filter('[data-testid="comparison-duel-rows"]'));
    }

    public function testMembersOnlyValuesInTheUrlAreDroppedForFreePlayers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/compare?kind=solo&times=first&period=custom&from=2020-01-01&to=2026-01-01&difficulty[]=3&sort=difficulty&brands[]=' . Uuid::uuid7()->toString() . '&pieces=500');

        self::assertResponseIsSuccessful();
        // Applied: the free filter (pieces) only - the URL says so
        self::assertSame('/en/compare?kind=solo&pieces=500', $crawler->filter('[data-testid="comparison"]')->attr('data-comparison-page-url-value'));
        self::assertStringContainsString('Best times', $crawler->filter('[data-testid="comparison-head-to-head"]')->text());
    }

    public function testMembersFilterByFirstTries(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/compare?kind=solo&times=first');

        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('[data-testid="comparison-first-tries"]')->attr('aria-pressed'));
        self::assertStringContainsString('First tries', $crawler->filter('[data-testid="comparison-league"]')->text());
        self::assertStringContainsString('times=first', (string) $crawler->filter('[data-testid="comparison"]')->attr('data-comparison-page-url-value'));
    }

    public function testShareLinkCarriesTheSubjectsAndTheFilters(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/compare?kind=solo&period=12m');

        $shareUrl = (string) $crawler->filter('[data-testid="comparison-share"]')->attr('data-share-link-url-value');
        self::assertStringStartsWith('http', $shareUrl);
        parse_str((string) parse_url($shareUrl, PHP_URL_QUERY), $query);
        self::assertSame('solo', $query['kind'] ?? null);
        self::assertSame('12m', $query['period'] ?? null);
        $with = $query['with'] ?? null;
        self::assertIsString($with);
        self::assertSame([self::STRIPE_REF, self::ADMIN_REF, self::REGULAR_REF], explode(',', $with));
    }

    public function testSharedComparisonIsAPreviewThatWritesNothing(): void
    {
        $browser = self::createClient();
        // A member with an empty line-up
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/compare?with=' . self::STRIPE_REF . ',' . self::REGULAR_REF);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="comparison-preview"]'));
        self::assertSame(['Sarah Williams', 'John Doe'], self::chipNames($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-chip-remove"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-add-sheet"]'));
        self::assertSame(0, $this->lineUpSize(PlayerFixture::PLAYER_ADMIN));
    }

    public function testFreePreviewIsYouAndTheFirstOther(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/compare?with=' . self::ADMIN_REF . ',' . self::STRIPE_REF);

        self::assertResponseIsSuccessful();
        self::assertSame(['You', 'Admin User'], self::chipNames($crawler));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-chip-locked"]'));
        self::assertSame(0, $this->lineUpSize(PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    public function testOldComparePageRedirectsToThePreviewWithoutWriting(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/compare-with-puzzler/' . PlayerFixture::PLAYER_ADMIN . '/');

        self::assertResponseRedirects('/en/compare?kind=solo&with=p-' . PlayerFixture::PLAYER_WITH_FAVORITES . ',p-' . PlayerFixture::PLAYER_ADMIN, 302);
        self::assertSame(0, $this->lineUpSize(PlayerFixture::PLAYER_WITH_FAVORITES));

        $browser->request('GET', '/porovnat-s-puzzlerem/' . PlayerFixture::PLAYER_ADMIN . '/');
        self::assertResponseRedirects();

        $browser->request('GET', '/compare-with-puzzler/' . Uuid::uuid7()->toString() . '/');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/compare-with-puzzler/not-a-player/');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOldComparePageSendsAnonymousVisitorsToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/compare-with-puzzler/' . PlayerFixture::PLAYER_ADMIN . '/');

        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testFullFreeLineUpOffersTheSwap(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/compare?swap=' . self::ADMIN_REF);

        self::assertResponseIsSuccessful();
        $swap = $crawler->filter('[data-testid="comparison-swap"]');
        self::assertSame('true', $swap->attr('data-comparison-sheet-open-value'));
        self::assertStringContainsString('Compare with Admin User instead?', $swap->text());
        $confirm = $crawler->filter('[data-testid="comparison-swap-confirm"]');
        self::assertCount(1, $confirm);
        self::assertSame(ComparisonSubjectFixture::REGULAR_STRIPE, $confirm->attr('data-live-replace-row-id-param'));
        self::assertStringContainsString('Keep Sarah Williams', $crawler->filter('[data-testid="comparison-swap-keep"]')->text());
    }

    public function testSwapOfSomebodyAlreadyInTheLineUpIsIgnored(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/compare?swap=' . self::STRIPE_REF);

        self::assertResponseIsSuccessful();
        self::assertSame('false', $crawler->filter('[data-testid="comparison-swap"]')->attr('data-comparison-sheet-open-value'));
        self::assertSame('/en/compare?kind=solo', $crawler->filter('[data-testid="comparison"]')->attr('data-comparison-page-url-value'));
    }

    public function testTeamSearchFindsVisiblePairsOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/compare/teams.json?kind=pairs&query=John');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        /** @var list<array{ref: string, label: string, mine: bool}> $teams */
        $teams = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertCount(1, $teams);
        self::assertSame('John Doe & Hidden Puzzler', $teams[0]['label']);
        self::assertFalse($teams[0]['mine']);
        self::assertStringStartsWith('t-', $teams[0]['ref']);

        // Not in any pair herself
        $browser->request('GET', '/en/compare/teams.json?kind=pairs');
        self::assertSame('[]', $browser->getResponse()->getContent());

        // PLAYER_REGULAR blocks PLAYER_PRIVATE: their pair is hidden from him, although he is in it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/compare/teams.json?kind=pairs');
        self::assertSame('[]', $browser->getResponse()->getContent());
    }

    public function testPlayerSearchMarksPrivatePlayersHiddenFromTheViewer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $code = self::getContainer()->get(Connection::class)->fetchOne('SELECT code FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);
        self::assertIsString($code);

        $browser->request('GET', '/en/player-search-autocomplete/?format=co-puzzler&query=' . $code);

        /** @var list<array{key: string, hidden: bool}> $people */
        $people = json_decode((string) $browser->getResponse()->getContent(), true);
        $private = array_values(array_filter($people, static fn (array $person): bool => $person['key'] === PlayerFixture::PLAYER_PRIVATE));
        self::assertCount(1, $private);
        self::assertTrue($private[0]['hidden']);
    }

    /**
     * The Duel view of 3+ subjects is the highlighted pair's head to head: only the puzzles both of them solved, never
     * a "Not solved" side - while the league table still describes the whole line-up
     */
    public function testDuelViewOfALineUpListsOnlyWhatTheHighlightedPairBothSolved(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $day = new DateTimeImmutable('-1 minute');

        // Solved by her and PLAYER_ADMIN only: a card, but not a row of her duel with PLAYER_REGULAR
        $withoutJohn = $this->seedPuzzle(500, 'Without John');
        $this->seedTime(PlayerFixture::PLAYER_WITH_STRIPE, $withoutJohn, 1800, $day);
        $this->seedTime(PlayerFixture::PLAYER_ADMIN, $withoutJohn, 2000, $day);
        $withJohn = $this->seedPuzzle(500, 'With John');
        $this->seedTime(PlayerFixture::PLAYER_WITH_STRIPE, $withJohn, 1800, $day);
        $this->seedTime(PlayerFixture::PLAYER_REGULAR, $withJohn, 1900, $day);

        $url = '/en/compare?kind=solo&a=' . self::STRIPE_REF . '&b=' . self::REGULAR_REF;

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Without John', $crawler->filter('[data-testid="comparison-cards"]')->text());
        $league = $crawler->filter('[data-testid="comparison-league-row"]')->count();

        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE player SET comparison_view = 'duel' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-testid="comparison-duel-rows"] [data-testid="comparison-row"]');
        self::assertGreaterThan(0, $rows->count());
        self::assertStringContainsString('With John', $rows->text());
        self::assertStringNotContainsString('Without John', $crawler->filter('[data-testid="comparison-duel-rows"]')->text());
        self::assertCount(0, $rows->filter('.cmp-time--none'), 'Both of the pair solved every listed puzzle');
        self::assertCount(0, $crawler->filter('[data-testid="comparison-show-more"]'));
        self::assertSame(
            $rows->count() === 1 ? '1 puzzle you both solved' : $rows->count() . ' puzzles you both solved',
            trim($crawler->filter('[data-testid="comparison-total"]')->text()),
        );
        self::assertCount($league, $crawler->filter('[data-testid="comparison-league-row"]'));
    }

    /**
     * docs/features/player-comparison.md "Performance budgets": the page costs the same at 2 and at 10 subjects
     */
    public function testQueryCountDoesNotGrowWithTheLineUp(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $database = self::getContainer()->get(Connection::class);

        // Two subjects: herself + PLAYER_ADMIN
        $database->executeStatement('DELETE FROM comparison_subject WHERE id = :id', ['id' => ComparisonSubjectFixture::STRIPE_REGULAR]);

        // The first page view of the day also does one-off work (sign-in bookkeeping, activity) - not what is compared
        $browser->request('GET', '/en/compare?kind=solo');

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/compare?kind=solo');
        self::assertResponseIsSuccessful();
        self::assertCount(2, self::chipNames($crawler));
        self::assertGreaterThan(0, $crawler->filter('[data-testid="comparison-row"]')->count());
        $twoSubjects = $this->queryCount($browser);

        $others = [PlayerFixture::PLAYER_REGULAR];

        for ($i = 1; $i <= 7; $i++) {
            $others[] = $this->seedPlayer('Compared ' . $i);
        }

        foreach ($others as $playerId) {
            $database->executeStatement(
                'INSERT INTO comparison_subject (id, player_id, subject_player_id, added_at) VALUES (:id, :owner, :subject, NOW())',
                ['id' => Uuid::uuid7()->toString(), 'owner' => PlayerFixture::PLAYER_WITH_STRIPE, 'subject' => $playerId],
            );
        }

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/compare?kind=solo');
        self::assertResponseIsSuccessful();
        self::assertCount(10, self::chipNames($crawler));
        self::assertGreaterThan(0, $crawler->filter('[data-testid="comparison-row"]')->count());

        self::assertSame($twoSubjects, $this->queryCount($browser), 'The compare page must cost the same at 2 and 10 subjects');
    }

    /**
     * @return list<string>
     */
    private static function chipNames(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="comparison-chip"] .cmp-chip__name')->each(
            static fn (Crawler $name): string => trim($name->text()),
        );
    }

    private function lineUpSize(string $playerId): int
    {
        $count = self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM comparison_subject WHERE player_id = :id', ['id' => $playerId]);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
