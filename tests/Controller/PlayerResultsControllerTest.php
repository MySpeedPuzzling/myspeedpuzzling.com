<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PlayerResultsPage;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\PlayerResultsSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A player's Results (docs/features/navigation README §4.7): PROF-00 made the route, PROF-05a its content - rendered
 * from the URL. A player hidden from the viewer is a 404 (GetPlayerProfile::byId), a private profile the viewer may not
 * see goes to the profile (implementation plan decision 69). Every parameter of the URL grammar works, and the
 * members-only ones work for members only: for anybody else they neither filter nor show a chip.
 *
 * PROF-05b: 50 rows a page, `?page=N` renders rows 1…50·N; Show more is a real link and its `fragment=more` stream
 * appends the next page; Show all up to 300 rows, above that the player's own Results offer the export.
 */
final class PlayerResultsControllerTest extends WebTestCase
{
    use ComparisonSeeding;
    use PlayerResultsSeeding;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;

    // Signed in, no membership
    private const string NON_MEMBER = PlayerFixture::PLAYER_WITH_FAVORITES;

    private null|KernelBrowser $browser = null;

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [null];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'the player' => [self::PLAYER];
    }

    #[DataProvider('provideViewers')]
    public function testThePublicPlayersResults(null|string $viewerId): void
    {
        $browser = $this->browser($viewerId);

        $profile = $browser->request('GET', '/en/player-profile/' . self::PLAYER);
        self::assertResponseIsSuccessful();

        $crawler = $browser->request('GET', '/en/player-results/' . self::PLAYER);

        self::assertResponseIsSuccessful();
        // PROF-07: the list's own URL is indexable - the player has more results than the Overview shows (PlayerResultsSeoTest)
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));
        // The player header with the name as the page's H1, the search first, and the list - as many puzzles as the
        // profile's Overview counts solo (PROF-03: the Overview's numbers are the Results tabs' counts)
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $this->resultsList($crawler));
        self::assertCount(1, $crawler->filter('main form#player-results-form input[type="search"][name="q"]'));
        self::assertGreaterThan(0, $this->rowsCount($crawler));
        self::assertSame((int) $profile->filter('[data-overview-number="solo"] .pov-number-value')->text(), $this->rowsCount($crawler));

        if ($viewerId === null) {
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalePaths(): iterable
    {
        yield 'cs' => ['/vysledky-hrace/'];
        yield 'de' => ['/de/spieler-ergebnisse/'];
        yield 'es' => ['/es/resultados-jugador/'];
        yield 'fr' => ['/fr/resultats-joueur/'];
        yield 'ja' => ['/ja/プレイヤー結果/'];
    }

    #[DataProvider('provideLocalePaths')]
    public function testEveryLocaleHasThePage(string $path): void
    {
        $browser = $this->browser(null);

        $crawler = $browser->request('GET', $path . self::PLAYER . '?view=every&q=puzzle&pieces=500');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->resultsList($crawler));
        self::assertGreaterThan(0, $this->rowsCount($crawler));
    }

    public function testTheListAloneForItsFrame(): void
    {
        $browser = $this->browser(null);

        $browser->request('GET', '/en/player-results/' . self::PLAYER . '?sort=fastest', server: ['HTTP_TURBO_FRAME' => 'player-results']);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="player-results"', trim(preg_replace('/\{#.*?#\}/s', '', $content) ?? ''));
        self::assertStringNotContainsString('<html', $content);
        self::assertStringNotContainsString('player-results-form', $content, 'The search stays on the page');
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));
        self::assertFalse($browser->getResponse()->headers->hasCacheControlDirective('public'), 'A frame answer is never shared');
    }

    public function testAPlayerTheViewerBlockedDoesNotExistForThem(): void
    {
        $browser = $this->browser(null);
        $url = '/en/player-results/' . PlayerFixture::PLAYER_WITH_STRIPE;

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnknownPlayers(): iterable
    {
        yield 'unknown uuid' => ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'];
        yield 'not a uuid' => ['not-a-player'];
    }

    #[DataProvider('provideUnknownPlayers')]
    public function testAnUnknownPlayerIsNotFound(string $playerId): void
    {
        $browser = $this->browser(null);

        $browser->request('GET', '/en/player-results/' . $playerId);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAPrivateProfileHiddenFromTheViewerGoesToTheProfile(): void
    {
        $browser = $this->browser(null);
        $url = '/en/player-results/' . PlayerFixture::PLAYER_PRIVATE;
        $profile = '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE;

        // A guest and a signed-in stranger
        $browser->request('GET', $url);
        self::assertResponseRedirects($profile, 302);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', $url);
        self::assertResponseRedirects($profile, 302);

        // The owner, and the friend her allow list names (PrivateProfileViewerFixture), see the list
        // (PROF-07: robots follow her own setting, whoever looks - PlayerResultsSeoTest)
        foreach ([PlayerFixture::PLAYER_PRIVATE => 'noindex, nofollow', PlayerFixture::PLAYER_WITH_FAVORITES => 'noindex, nofollow'] as $viewerId => $robots) {
            TestingLogin::asPlayer($browser, $viewerId);
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $this->resultsList($crawler));
            self::assertSame($robots, $crawler->filter('meta[name="robots"]')->attr('content'));
        }
    }

    // ---------------------------------------------------------------------- the URL grammar

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideEveryViewer(): iterable
    {
        yield 'guest' => [null];
        yield 'signed in' => [self::NON_MEMBER];
        yield 'member' => [self::MEMBER];
    }

    #[DataProvider('provideEveryViewer')]
    public function testCategory(null|string $viewerId): void
    {
        $crawler = $this->results($viewerId, ['category' => 'duo']);

        self::assertSame('page', $crawler->filter('[data-results-category="duo"]')->attr('aria-current'));
        self::assertSame(2, $this->rowsCount($crawler));
        self::assertCount(2, $crawler->filter('#player-results .ps-members'), 'Pair rows name the pair');

        // Unknown = the default tab
        self::assertSame('page', $this->results($viewerId, ['category' => 'trio'])->filter('[data-results-category="solo"]')->attr('aria-current'));
    }

    #[DataProvider('provideEveryViewer')]
    public function testView(null|string $viewerId): void
    {
        $this->browser($viewerId);
        $solo = self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND puzzling_type = 'solo'",
            ['id' => self::PLAYER],
        );
        self::assertIsInt($solo);

        $crawler = $this->results($viewerId, ['view' => 'every']);

        self::assertSame('true', $crawler->filter('[data-results-view="every"]')->attr('aria-current'));
        self::assertSame($solo, $this->rowsCount($crawler));
        self::assertLessThan($solo, $this->rowsCount($this->results($viewerId, [])), 'By puzzle has fewer rows than results');
    }

    /**
     * @return iterable<string, array{null|string, string, string}>
     */
    public static function provideFreeSorts(): iterable
    {
        foreach (['guest' => null, 'signed in' => self::NON_MEMBER, 'member' => self::MEMBER] as $viewer => $viewerId) {
            foreach (['newest' => 'Newest', 'oldest' => 'Oldest', 'fastest' => 'Fastest', 'slowest' => 'Slowest', 'fastest_ppm' => 'Fastest PPM', 'slowest_ppm' => 'Slowest PPM', 'name' => 'Puzzle name', 'rank' => 'Best rank', 'attempts' => 'Most attempts'] as $sort => $label) {
                yield $viewer . ' ' . $sort => [$viewerId, $sort, $label];
            }
        }
    }

    #[DataProvider('provideFreeSorts')]
    public function testFreeSort(null|string $viewerId, string $sort, string $label): void
    {
        $crawler = $this->results($viewerId, ['sort' => $sort]);

        self::assertStringContainsString('Sort by: ' . $label, $crawler->filter('#player-results-sort')->text());
        self::assertSame('true', $crawler->filter('a[data-results-sort="' . $sort . '"]')->attr('aria-current'));
        self::assertSame($this->rowsCount($this->results($viewerId, [])), $this->rowsCount($crawler), 'A sort keeps every row');
    }

    #[DataProvider('provideEveryViewer')]
    public function testDifficultySorts(null|string $viewerId): void
    {
        foreach (['easiest' => 'Easiest first', 'hardest' => 'Hardest first'] as $sort => $label) {
            $crawler = $this->results($viewerId, ['sort' => $sort]);

            if ($viewerId === self::MEMBER) {
                self::assertStringContainsString('Sort by: ' . $label, $crawler->filter('#player-results-sort')->text());
                self::assertCount(1, $crawler->filter('a[data-results-sort="' . $sort . '"]'));
            } else {
                // Dropped: the default sort, the option locked
                self::assertStringContainsString('Sort by: Newest', $crawler->filter('#player-results-sort')->text());
                self::assertCount(1, $crawler->filter('[data-results-sort-locked="' . $sort . '"]'));
                self::assertSame($this->rowTimeIds($this->results($viewerId, [])), $this->rowTimeIds($crawler));
            }
        }
    }

    #[DataProvider('provideEveryViewer')]
    public function testSearch(null|string $viewerId): void
    {
        $crawler = $this->results($viewerId, ['q' => 'puzzle 7']);

        $this->assertChip($crawler, 'q', '“puzzle 7”');
        self::assertSame('puzzle 7', $crawler->filter('#player-results-q')->attr('value'));
        self::assertSame(1, $this->rowsCount($crawler));
        self::assertStringContainsString('Puzzle 7', $crawler->filter('#player-results .ps-puzzle-name')->text());
        self::assertSame('1 of 9', trim($crawler->filter('[data-testid="results-count"]')->text()));
    }

    #[DataProvider('provideEveryViewer')]
    public function testBrand(null|string $viewerId): void
    {
        $crawler = $this->results($viewerId, []);
        $brands = $crawler->filter('select[name="brand"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertGreaterThan(2, count($brands), 'All brands + at least two');
        $brand = $brands[1];

        $crawler = $this->results($viewerId, ['brand' => $brand]);

        $this->assertChip($crawler, 'brand', $brand);
        self::assertCount(1, $crawler->filter('select[name="brand"] option[selected][value="' . $brand . '"]'));
        self::assertGreaterThan(0, $this->rowsCount($crawler));
        self::assertLessThan($this->rowsCount($this->results($viewerId, [])), $this->rowsCount($crawler));

        foreach ($crawler->filter('#player-results .ps-puzzle-meta')->each(static fn (Crawler $meta): string => $meta->text()) as $meta) {
            self::assertStringContainsString($brand, $meta);
        }
    }

    #[DataProvider('provideEveryViewer')]
    public function testPieces(null|string $viewerId): void
    {
        $crawler = $this->results($viewerId, ['pieces' => '500']);

        $this->assertChip($crawler, 'pieces', '500 pieces');
        self::assertGreaterThan(0, $this->rowsCount($crawler));

        foreach ($crawler->filter('#player-results .ps-puzzle-meta')->each(static fn (Crawler $meta): string => $meta->text()) as $meta) {
            self::assertStringContainsString('500', $meta);
        }

        // The custom bounds of the form
        $crawler = $this->results($viewerId, ['pieces' => '', 'pieces_min' => '1001', 'pieces_max' => '', 'pieces_was' => '']);
        $this->assertChip($crawler, 'pieces', '1001+ pieces');
        self::assertSame('1001', $crawler->filter('input[name="pieces_min"]')->attr('value'));
        self::assertCount(0, $crawler->filter('input[name="pieces"][checked]'), 'A custom range is no chip');
    }

    #[DataProvider('provideEveryViewer')]
    public function testTeam(null|string $viewerId): void
    {
        $this->browser($viewerId);
        $pairId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($pairId);

        $crawler = $this->results($viewerId, ['team' => $pairId]);

        // A pair filter without a tab opens the Pairs tab
        self::assertSame('page', $crawler->filter('[data-results-category="duo"]')->attr('aria-current'));
        self::assertCount(1, $crawler->filter('a[data-results-chip="team"]'));
        // Both of the player's pair results are with this pair (PairsAndTeams cases in PlayerResultsBuilderTest narrow)
        self::assertSame(2, $this->rowsCount($crawler));
        self::assertCount(1, $crawler->filter('select[name="team"] option[selected][value="' . $pairId . '"]'));

        // "Clear all" keeps the tab it was on
        parse_str((string) parse_url((string) $crawler->filter('#player-results a[data-results-clear]')->attr('href'), PHP_URL_QUERY), $clear);
        self::assertSame(['category' => 'duo'], $clear);

        // Nonsense is no filter
        self::assertCount(0, $this->results($viewerId, ['team' => 'nonsense'])->filter('a[data-results-chip]'));
    }

    /**
     * PROF review F1: under a pair/team filter the Solo tab is reachable - from the team page's "Results with this
     * pair" (`?category=duo&team=…`, PROF-05e) and from `?team=…` alike - and every change of the Solo list (sort,
     * view, another chip, Show more, the filter form) stays on Solo; only the tab's own link drops the category, so
     * removing the filter never jumps to another tab either.
     */
    #[DataProvider('provideEveryViewer')]
    public function testTheSoloTabStaysReachableUnderAPairFilter(null|string $viewerId): void
    {
        $browser = $this->browser($viewerId);
        // 9 + 60 solo puzzles: Show more on the Solo tab
        $this->addSoloResults(self::PLAYER, 60);
        $pairId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($pairId);

        foreach ([['category' => 'duo', 'team' => $pairId], ['team' => $pairId]] as $entry) {
            $crawler = $this->results($viewerId, $entry);
            self::assertSame('page', $crawler->filter('[data-results-category="duo"]')->attr('aria-current'));
            $soloLink = (string) $crawler->filter('a[data-results-category="solo"]')->attr('href');
            self::assertSame(['category' => 'solo', 'team' => $pairId], $this->queryOf($soloLink), 'The Solo tab names itself under the pair filter');

            // Followed: Solo, the pair's chip still on
            $solo = $browser->request('GET', $soloLink);
            self::assertResponseIsSuccessful();
            self::assertSame('page', $solo->filter('[data-results-category="solo"]')->attr('aria-current'), http_build_query($entry));
            self::assertCount(1, $solo->filter('a[data-results-chip="team"]'));
            self::assertSame(50, $this->rowsCount($solo));
        }

        // Every change of ?category=solo&team=… stays on Solo
        $crawler = $this->results($viewerId, ['category' => 'solo', 'team' => $pairId]);
        $links = [
            'sort' => $crawler->filter('a[data-results-sort="fastest"]')->attr('href'),
            'view' => $crawler->filter('a[data-results-view="every"]')->attr('href'),
            'show more' => $crawler->filter('a[data-results-more]')->attr('href'),
        ];

        foreach ($links as $change => $link) {
            self::assertSame('solo', $this->queryOf((string) $link)['category'] ?? null, $change);
            $after = $browser->request('GET', (string) $link);
            self::assertResponseIsSuccessful();
            self::assertSame('page', $after->filter('[data-results-category="solo"]')->attr('aria-current'), $change);
            self::assertCount(1, $after->filter('a[data-results-chip="team"]'), $change . ': the filter stays');
        }

        // The sort changed on that list: still Solo, sorted
        $sorted = $browser->request('GET', (string) $links['sort']);
        self::assertSame(['category' => 'solo', 'sort' => 'fastest', 'team' => $pairId], $this->queryOf((string) $sorted->filter('[data-results-state]')->attr('data-url')));

        // The filter form keeps the tab: a submit (here with a search typed) stays on Solo
        self::assertSame('solo', $crawler->filter('#player-results-form > input[type="hidden"][name="category"]')->attr('value'));
        $form = $crawler->filter('#player-results-form')->form();
        $form['q'] = 'puzzle';
        $submitted = $browser->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSame('page', $submitted->filter('[data-results-category="solo"]')->attr('aria-current'));
        self::assertCount(1, $submitted->filter('a[data-results-chip="team"]'));
        self::assertCount(1, $submitted->filter('a[data-results-chip="q"]'));

        // Removing the filter (its chip, Clear all) keeps Solo - the history's default tab, so no category
        self::assertSame([], $this->queryOf((string) $crawler->filter('a[data-results-chip="team"]')->attr('href')));
        self::assertSame([], $this->queryOf((string) $crawler->filter('#player-results a[data-results-clear]')->attr('href')));
        // The shown tab's own link is the list as its address names it: a click on it changes nothing
        self::assertSame(['category' => 'solo', 'team' => $pairId], $this->queryOf((string) $crawler->filter('a[data-results-category="solo"]')->attr('href')));
    }

    #[DataProvider('provideEveryViewer')]
    public function testPage(null|string $viewerId): void
    {
        $this->browser($viewerId);
        // 9 + 160 solo puzzles
        $this->addSoloResults(self::PLAYER, 160);

        $crawler = $this->results($viewerId, []);
        self::assertSame(50, $this->rowsCount($crawler));
        self::assertSame(['page' => '2'], $this->queryOf((string) $crawler->filter('a[data-results-more]')->attr('href')));
        self::assertSame('Show 50 more', trim($crawler->filter('a[data-results-more]')->text()));
        self::assertSame('Showing 50 of 169', trim($crawler->filter('[data-testid="results-shown"]')->text()));

        // Rows 1…150, Show more continues with the fourth page
        $crawler = $this->results($viewerId, ['page' => '3']);
        self::assertSame(150, $this->rowsCount($crawler));
        self::assertSame(['page' => '4'], $this->queryOf((string) $crawler->filter('a[data-results-more]')->attr('href')));
        self::assertSame('Show 19 more', trim($crawler->filter('a[data-results-more]')->text()));
        // The first 100 are page 2's
        self::assertSame($this->rowIds($this->results($viewerId, ['page' => '2'])), array_slice($this->rowIds($crawler), 0, 100));

        // Beyond the end = the last page: every row, nothing more to show
        $crawler = $this->results($viewerId, ['page' => '999']);
        self::assertSame(169, $this->rowsCount($crawler));
        self::assertCount(0, $crawler->filter('a[data-results-more], a[data-results-all]'));
        self::assertCount(1, $crawler->filter('#player-results-more'), 'The footer stays (empty) for the stream to replace');

        self::assertSame(50, $this->rowsCount($this->results($viewerId, ['page' => 'x'])));
    }

    /**
     * Show more's stream: `?…&page=3&fragment=more` = rows 101-150 appended to the list, the footer replaced
     */
    #[DataProvider('provideEveryViewer')]
    public function testShowMoreAppendsTheNextPage(null|string $viewerId): void
    {
        $browser = $this->browser($viewerId);
        $this->addSoloResults(self::PLAYER, 160);
        $page = $this->results($viewerId, ['sort' => 'fastest', 'page' => '3']);

        $streams = $this->stream($browser, ['sort' => 'fastest', 'page' => '3']);

        self::assertSame(
            [['append', 'player-results-rows'], ['replace', 'player-results-more']],
            $streams->each(static fn (Crawler $stream): array => [(string) $stream->attr('action'), (string) $stream->attr('target')]),
        );
        $appended = $this->templateOf($streams->eq(0));
        self::assertSame(array_slice($this->rowIds($page), 100, 50), $this->rowIds($appended), 'Rows 101-150 of the list, nothing else');
        self::assertSame(array_slice($this->rankChips($page), 100, 50), $this->rankChips($appended), 'The same ranks as on the page (PROF-06)');
        self::assertGreaterThan(0, count(array_filter($this->rankChips($appended))));

        $footer = $this->templateOf($streams->eq(1));
        self::assertSame(['sort' => 'fastest', 'page' => '4'], $this->queryOf((string) $footer->filter('#player-results-more a[data-results-more]')->attr('href')));
        self::assertSame('Showing 150 of 169', trim($footer->filter('[data-testid="results-shown"]')->text()));
        self::assertSame('player-results#more', $footer->filter('a[data-results-more]')->attr('data-action'));

        // The last page: its rows, a footer without Show more
        $streams = $this->stream($browser, ['sort' => 'fastest', 'page' => '4']);
        self::assertSame(array_slice($this->rowIds($this->results($viewerId, ['sort' => 'fastest', 'page' => '4'])), 150), $this->rowIds($this->templateOf($streams->eq(0))));
        self::assertCount(0, $this->templateOf($streams->eq(1))->filter('a[data-results-more]'));

        // Beyond the end (the list got shorter meanwhile): nothing appended twice
        $streams = $this->stream($browser, ['sort' => 'fastest', 'page' => '5']);
        self::assertSame([], $this->rowIds($this->templateOf($streams->eq(0))));
    }

    public function testShowMoreUnderEveryResultNeverRepeatsAMonthHeading(): void
    {
        $browser = $this->browser(null);
        $this->addSoloResults(self::PLAYER, 160, PuzzleFixture::PUZZLE_1000_01);
        // Spread the seeded results over months: result n solved n days ago
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle_solving_time pst SET finished_at = NOW() - (p.name_number || ' days')::interval, tracked_at = NOW() - (p.name_number || ' days')::interval
             FROM (SELECT id, substring(name from 8) AS name_number FROM puzzle WHERE name LIKE 'Seeded %') p
             WHERE pst.puzzle_id = p.id",
        );

        $page = $this->results(null, ['view' => 'every', 'page' => '2']);
        $months = $this->monthHeadings($page);
        self::assertGreaterThan(2, count($months), 'The fixture spans several months');
        self::assertSame(array_values(array_unique($months)), $months);

        // A heading row of its own, scope left to HTML's auto state (PROF review F7: "colgroup" without a <colgroup>)
        foreach ($page->filter('tr.prs-month-row > th') as $heading) {
            self::assertInstanceOf(DOMElement::class, $heading);
            self::assertFalse($heading->hasAttribute('scope'), 'No scope on a month heading');
            self::assertSame('3', $heading->getAttribute('colspan'));
        }

        $first = $this->monthHeadings($this->results(null, ['view' => 'every']));
        $appended = $this->monthHeadings($this->templateOf($this->stream($browser, ['view' => 'every', 'page' => '2'])->eq(0)));

        self::assertSame($months, [...$first, ...$appended], 'The page\'s headings and the appended ones = page 2\'s headings');
    }

    public function testTheStreamIsNoPageOfItsOwn(): void
    {
        $browser = $this->browser(null);
        $browser->request('GET', '/en/player-results/' . self::PLAYER . '?fragment=more&page=2');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $browser->getResponse()->headers->get('Content-Type'));
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertFalse($browser->getResponse()->headers->hasCacheControlDirective('public'), 'Never shared');
        self::assertStringNotContainsString('<html', (string) $browser->getResponse()->getContent());

        // No link carries the parameter: Show more links the page
        $this->addSoloResults(self::PLAYER, 60);
        self::assertStringNotContainsString('fragment', (string) $this->results(null, [])->filter('#player-results')->html());
    }

    /**
     * @return iterable<string, array{int, null|string, bool, bool}>
     */
    public static function provideShowAllThreshold(): iterable
    {
        // [rows, viewer, Show all, export link] - the fixture player has 9 solo puzzles
        yield '299 rows, guest' => [PlayerResultsPage::SHOW_ALL_MAX - 1, null, true, false];
        yield '299 rows, the player' => [PlayerResultsPage::SHOW_ALL_MAX - 1, self::PLAYER, true, false];
        yield '301 rows, guest' => [PlayerResultsPage::SHOW_ALL_MAX + 1, null, false, false];
        yield '301 rows, a member' => [PlayerResultsPage::SHOW_ALL_MAX + 1, self::MEMBER, false, false];
        yield '301 rows, the player' => [PlayerResultsPage::SHOW_ALL_MAX + 1, self::PLAYER, false, true];
    }

    #[DataProvider('provideShowAllThreshold')]
    public function testShowAllUpTo300RowsAndAboveThatTheOwnersExport(int $rows, null|string $viewerId, bool $showAll, bool $export): void
    {
        $this->browser($viewerId);
        $this->addSoloResults(self::PLAYER, $rows - 9);

        $crawler = $this->results($viewerId, ['sort' => 'oldest']);

        self::assertSame(50, $this->rowsCount($crawler));
        self::assertCount(1, $crawler->filter('a[data-results-more]'), 'Show more always');
        self::assertCount($showAll ? 1 : 0, $crawler->filter('a[data-results-all]'));
        self::assertCount($export ? 1 : 0, $crawler->filter('a[data-results-export]'));

        if ($showAll) {
            $link = $crawler->filter('a[data-results-all]');
            self::assertSame('Show all (' . $rows . ')', trim($link->text()));
            self::assertSame(['sort' => 'oldest', 'page' => (string) ceil($rows / 50)], $this->queryOf((string) $link->attr('href')));
            self::assertSame('player-results#all', $link->attr('data-action'));

            // Every row
            self::assertSame($rows, $this->rowsCount($this->results($viewerId, ['sort' => 'oldest', 'page' => (string) ceil($rows / 50)])));
        }

        if ($export) {
            self::assertSame('/en/export-puzzler-data/' . self::PLAYER, $crawler->filter('a[data-results-export]')->attr('href'));
            self::assertStringContainsString('Export your results', $crawler->filter('a[data-results-export]')->text());
        }
    }

    /**
     * @return iterable<string, array{null|string, bool}>
     */
    public static function provideWindowViewers(): iterable
    {
        // [viewer, renders every row]
        yield 'guest' => [null, false];
        yield 'signed in' => [self::NON_MEMBER, false];
        yield 'member' => [self::MEMBER, false];
        yield 'the player' => [self::PLAYER, true];
    }

    /**
     * PROF review Q5: `?page=N` renders rows 1…50·N - beyond Show all's 300 rows the last 300 for anybody but the player,
     * with "Show from the start" above them (the page and its frame alike); the player keeps every row. Show more's
     * stream is unaffected, and Back finds its row: the saved row is one of the last ones the page showed.
     */
    #[DataProvider('provideWindowViewers')]
    public function testAPageBeyond300RowsRendersTheLast300ForAnybodyButThePlayer(null|string $viewerId, bool $everyRow): void
    {
        $browser = $this->browser($viewerId);
        // 9 + 500 solo puzzles: page 10 = rows 1…500
        $this->addSoloResults(self::PLAYER, 500);
        $player = $this->browser(self::PLAYER);
        $all = $this->rowIds($player->request('GET', '/en/player-results/' . self::PLAYER . '?page=11'));
        self::assertCount(509, $all);

        $this->browser($viewerId);
        $page = $this->results($viewerId, ['page' => '10']);
        $frame = $browser->request('GET', '/en/player-results/' . self::PLAYER . '?page=10', server: ['HTTP_TURBO_FRAME' => 'player-results']);
        self::assertResponseIsSuccessful();

        foreach (['page' => $page, 'frame' => $frame] as $answer => $crawler) {
            if ($everyRow) {
                self::assertSame(array_slice($all, 0, 500), $this->rowIds($crawler), $answer);
                self::assertCount(0, $crawler->filter('[data-testid="results-window"]'), $answer);
            } else {
                self::assertSame(array_slice($all, 200, 300), $this->rowIds($crawler), $answer . ': rows 201…500');
                $start = $crawler->filter('[data-testid="results-window"] a[data-results-start]');
                self::assertCount(1, $start, $answer);
                self::assertSame('/en/player-results/' . self::PLAYER, $start->attr('href'), $answer . ': the list from its first row');
                self::assertSame('Show from the start', trim($start->text()));
            }

            // The rows shown carry their ranks (one statement for them, PROF-06), Show more continues after row 500
            self::assertCount(1, $crawler->filter('tr[id^="result-"]')->last()->filter('.pr-chip-rank'), $answer);
            self::assertSame(['page' => '11'], $this->queryOf((string) $crawler->filter('a[data-results-more]')->attr('href')), $answer);
        }

        // Page 6 = 300 rows: whole for everybody
        self::assertSame(array_slice($all, 0, 300), $this->rowIds($this->results($viewerId, ['page' => '6'])));
        // Page 7: the last 300 (rows 51…350) - or all 350 for the player
        self::assertSame($everyRow ? array_slice($all, 0, 350) : array_slice($all, 50, 300), $this->rowIds($this->results($viewerId, ['page' => '7'])));

        // The stream appends the page asked for after the rows shown, whoever asks
        self::assertSame(array_slice($all, 500, 9), $this->rowIds($this->templateOf($this->stream($browser, ['page' => '11'])->eq(0))));
    }

    /**
     * PROF review Q3: a row shows the main title and, under it, puzzle/_name.html.twig - the name in the viewer's
     * language, and with a search "Matched: …" when another name or one of the puzzle's codes found it (nothing when
     * the title did; the title highlighted). The rows' names come with the results, the codes with the search
     * statement: the statement count stays (PlayerResultsQueryBudgetTest).
     */
    public function testRowsShowTheNameInTheViewersLanguageAndWhatTheSearchMatched(): void
    {
        $browser = $this->browser(null);

        // PUZZLE_1000_02 ("Puzzle 7", Czech "Kouzelná zahrada"): the Czech page shows the Czech name under the title
        $czech = $browser->request('GET', '/vysledky-hrace/' . self::PLAYER);
        self::assertResponseIsSuccessful();
        $row = $this->rowOf($czech, PuzzleFixture::PUZZLE_1000_02);
        self::assertSame('Kouzelná zahrada', trim($row->filter('.puzzle-name-local[lang="cs"]')->text()));
        self::assertCount(0, $czech->filter('.puzzle-name-matched'), 'Nothing matched without a search');
        // The English page: no second line for a guest
        self::assertCount(0, $this->rowOf($this->results(null, []), PuzzleFixture::PUZZLE_1000_02)->filter('.puzzle-name-local'));

        $matched = [
            // Another name: the German one
            'zauberhafter' => [PuzzleFixture::PUZZLE_1000_02, 'Matched: Zauberhafter Garten'],
            // The EAN, with leading zeros typed
            '00' . PuzzleFixture::EAN_PUZZLE_300 => [PuzzleFixture::PUZZLE_300, 'Matched: ' . PuzzleFixture::EAN_PUZZLE_300],
            // The whole brand code, and a part of it (5+ letters and digits)
            'RB-500-001' => [PuzzleFixture::PUZZLE_500_01, 'Matched: RB-500-001'],
            'rb-500' => [PuzzleFixture::PUZZLE_500_01, 'Matched: RB-500-001'],
        ];

        foreach ($matched as $query => [$puzzleId, $line]) {
            $crawler = $this->results(null, ['q' => $query]);
            self::assertSame(1, $this->rowsCount($crawler), $query);
            self::assertSame($line, trim($this->rowOf($crawler, $puzzleId)->filter('.puzzle-name-matched')->text()), $query);
        }

        // The title found it: highlighted, no "Matched"
        $crawler = $this->results(null, ['q' => 'puzzle 7']);
        $row = $this->rowOf($crawler, PuzzleFixture::PUZZLE_1000_02);
        self::assertCount(0, $row->filter('.puzzle-name-matched'));
        self::assertSame(['Puzzle', '7'], $row->filter('a.ps-puzzle-name .search-highlight')->each(static fn (Crawler $word): string => $word->text()), 'The title highlights the search');
        self::assertSame('Puzzle 7', trim($row->filter('a.ps-puzzle-name')->text()));
    }

    /**
     * PROF review Q4: the chips' "N of M" counts what the tab's label counts - puzzles By puzzle (a Pairs row is a puzzle
     * and a pair: two pairs on one puzzle are two rows and one puzzle), results on Every result; the footer's "Showing
     * N of M" counts rows (paging)
     */
    #[DataProvider('provideEveryViewer')]
    public function testTheChipsCountTheTabsUnit(null|string $viewerId): void
    {
        $this->browser($viewerId);
        $database = self::getContainer()->get(Connection::class);
        $pairPuzzle = $database->fetchOne('SELECT puzzle_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($pairPuzzle);
        $brand = $database->fetchOne('SELECT m.name FROM puzzle p JOIN manufacturer m ON m.id = p.manufacturer_id WHERE p.id = :id', ['id' => $pairPuzzle]);
        self::assertIsString($brand);
        // A second pair on one of the player's pair puzzles: a row more, not a puzzle more
        $this->seedSecondPair($pairPuzzle);

        $all = $this->results($viewerId, ['category' => 'duo']);
        $puzzles = $this->tabCount($all, 'duo');
        self::assertGreaterThan($puzzles, $this->rowsCount($all), 'Two pairs on one puzzle: more rows than puzzles');

        // By puzzle: puzzles of puzzles
        $crawler = $this->results($viewerId, ['category' => 'duo', 'brand' => $brand]);
        $shown = $this->tabCount($crawler, 'duo');
        self::assertGreaterThan(0, $shown);
        self::assertGreaterThan($shown, $this->rowsCount($crawler), 'The filtered tab still has the two pairs on one puzzle');
        self::assertSame($shown . ' of ' . $puzzles, trim($crawler->filter('[data-testid="results-count"]')->text()));

        // Every result: results of results
        $results = $this->tabCount($this->results($viewerId, ['category' => 'duo', 'view' => 'every']), 'duo');
        $crawler = $this->results($viewerId, ['category' => 'duo', 'view' => 'every', 'brand' => $brand]);
        self::assertSame($this->rowsCount($crawler), $this->tabCount($crawler, 'duo'));
        self::assertSame($this->rowsCount($crawler) . ' of ' . $results, trim($crawler->filter('[data-testid="results-count"]')->text()));
    }

    /**
     * @return iterable<string, array{null|string, array<string, string|list<string>>, string}>
     */
    public static function provideMembersOnlyFilters(): iterable
    {
        $filters = [
            'first tries' => [['first' => '1'], 'first'],
            'unboxed' => [['unboxed' => '1'], 'unboxed'],
            'relax' => [['relax' => '1'], 'relax'],
            'from' => [['from' => '2026-09-15'], 'from'],
            'to' => [['to' => '2026-09-15'], 'to'],
            'speed' => [['speed' => '20', 'speed_cmp' => 'slower', 'speed_unit' => 'ppm'], 'speed'],
            // The hardest tier: none of the fixture player's puzzles is in it
            'difficulty' => [['difficultyTiers' => ['6']], 'difficultyTiers:6'],
        ];

        foreach (['guest' => null, 'signed in' => self::NON_MEMBER, 'member' => self::MEMBER] as $viewer => $viewerId) {
            foreach ($filters as $name => [$query, $chip]) {
                yield $viewer . ' ' . $name => [$viewerId, $query, $chip];
            }
        }
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    #[DataProvider('provideMembersOnlyFilters')]
    public function testMembersOnlyFilter(null|string $viewerId, array $query, string $chip): void
    {
        $this->browser($viewerId);
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        $all = $this->rowTimeIds($this->results($viewerId, ['view' => 'every']));
        $crawler = $this->results($viewerId, ['view' => 'every', ...$query]);

        if ($viewerId !== self::MEMBER) {
            // Neither a filter nor a chip - and the form shows the filter locked
            self::assertCount(0, $crawler->filter('a[data-results-chip]'));
            self::assertSame($all, $this->rowTimeIds($crawler));
            self::assertCount(1, $crawler->filter('fieldset[disabled][data-testid="results-members-filters"]'));
            self::assertCount(1, $crawler->filter('[data-testid="results-members-lock"]'));

            return;
        }

        self::assertCount(1, $crawler->filter('a[data-results-chip="' . $chip . '"]'), $chip);
        self::assertCount(0, $crawler->filter('fieldset[disabled]'));
        self::assertLessThan(count($all), count($this->rowTimeIds($crawler)), 'The filter narrows the results');
        self::assertSame([], array_diff($this->rowTimeIds($crawler), $all));
        $this->assertMembersFilterHolds($crawler, $query);
    }

    /**
     * PROF-05c: the list tells the page's script what it shows ([data-results-state] inside the frame) - the address in
     * the server's form (defaults, empty values, a free viewer's members-only values dropped, the page clamped), what the
     * filter form keeps (tab, view, sort - a pair/team filter's tab named), the filters' count (the search not counted)
     * and Clear all; the form's own Clear all is there (hidden) without filters, for the script to show.
     */
    #[DataProvider('provideEveryViewer')]
    public function testTheFrameSaysWhatItShows(null|string $viewerId): void
    {
        $this->browser($viewerId);
        $base = '/en/player-results/' . self::PLAYER;

        $crawler = $this->results($viewerId, ['sort' => 'newest', 'view' => 'puzzles', 'page' => '999', 'brand' => '', 'q' => ' ', 'relax' => '1', 'speed_unit' => 'min']);
        $relax = $viewerId === self::MEMBER ? '?relax=1' : '';
        self::assertSame([
            'url' => $base . $relax,
            'category' => '',
            'view' => '',
            'sort' => '',
            'filters' => $relax === '' ? '0' : '1',
            'clear' => $relax === '' ? '' : $base,
        ], $this->frameState($crawler));
        self::assertSame($relax === '', $crawler->filter('#player-results-form a[data-results-form-clear]')->attr('hidden') !== null);

        // The pair filter's tab is named for the form (as its hidden field), the address keeps only what was asked
        $pairId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($pairId);
        $crawler = $this->results($viewerId, ['team' => $pairId, 'sort' => 'fastest', 'q' => 'puzzle', 'page' => '1']);
        self::assertSame([
            'url' => $base . '?sort=fastest&q=puzzle&team=' . $pairId,
            'category' => 'duo',
            'view' => '',
            'sort' => 'fastest',
            'filters' => '1',
            'clear' => $base . '?category=duo&sort=fastest',
        ], $this->frameState($crawler));
        self::assertSame('duo', $crawler->filter('#player-results-form > input[type="hidden"][name="category"]')->attr('value'));
        $clear = $crawler->filter('#player-results-form a[data-results-form-clear]');
        self::assertNull($clear->attr('hidden'));
        self::assertSame($base . '?category=duo&sort=fastest', $clear->attr('href'));

        // The frame alone says the same (the script reads it after each load)
        $this->browser($viewerId)->request('GET', $base . '?view=every&page=2', server: ['HTTP_TURBO_FRAME' => 'player-results']);
        self::assertResponseIsSuccessful();
        self::assertSame($base . '?view=every', $this->frameState(new Crawler((string) $this->browser?->getResponse()->getContent()))['url'], 'The page clamped to the last one (page 1)');
    }

    // --------------------------------------------------------------------------------- helpers

    /**
     * Another pair of the player on the puzzle: a new partner, their pair, a result together (ComparisonSeeding)
     */
    private function seedSecondPair(string $puzzleId): void
    {
        $pair = $this->seedTeam([self::PLAYER, $this->seedPlayer('Second Pair Partner')]);
        $this->seedTime(self::PLAYER, $puzzleId, 2500, new DateTimeImmutable('-3 hours'), teamId: $pair);
    }

    private function rowOf(Crawler $crawler, string $puzzleId): Crawler
    {
        $row = $crawler->filter('#player-results-rows > tr[id]')->reduce(static fn (Crawler $tr): bool => $tr->filter('a.ps-puzzle-name[href$="/' . $puzzleId . '"]')->count() > 0);
        self::assertCount(1, $row, 'One row of ' . $puzzleId);

        return $row;
    }

    private function tabCount(Crawler $crawler, string $category): int
    {
        $tab = $crawler->filter('[data-testid="results-categories"] [data-results-category="' . $category . '"]');
        self::assertCount(1, $tab, $category);
        self::assertSame(1, preg_match('/\((\d+)\)\s*$/', trim($tab->text()), $match), $tab->text());

        return (int) $match[1];
    }

    /**
     * @return array{url: null|string, category: null|string, view: null|string, sort: null|string, filters: null|string, clear: null|string}
     */
    private function frameState(Crawler $crawler): array
    {
        $state = $crawler->filter('turbo-frame#player-results > [data-results-state][hidden]');
        self::assertCount(1, $state);

        return [
            'url' => $state->attr('data-url'),
            'category' => $state->attr('data-category'),
            'view' => $state->attr('data-view'),
            'sort' => $state->attr('data-sort'),
            'filters' => $state->attr('data-filters'),
            'clear' => $state->attr('data-clear'),
        ];
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private function assertMembersFilterHolds(Crawler $crawler, array $query): void
    {
        $rows = $crawler->filter('#player-results .ps-table tbody tr')->reduce(static fn (Crawler $row): bool => $row->filter('td.ps-image')->count() > 0);

        foreach ($rows as $node) {
            $row = new Crawler($node);
            $date = $row->filter('time.ps-date')->count() > 0 ? (string) $row->filter('time.ps-date')->attr('datetime') : null;

            switch (array_key_first($query)) {
                case 'first':
                    self::assertCount(1, $row->filter('.badge.bg-info'));
                    break;
                case 'unboxed':
                    self::assertCount(1, $row->filter('.bi-eye-slash'));
                    break;
                case 'relax':
                    self::assertCount(1, $row->filter('.bi-cup-hot'));
                    break;
                case 'from':
                    self::assertGreaterThanOrEqual('2026-09-15', $date ?? '9999');
                    break;
                case 'to':
                    self::assertLessThanOrEqual('2026-09-15', $date ?? '0000');
                    break;
                case 'speed':
                    self::assertLessThan(20.0, (float) str_replace(',', '.', $row->filter('.ps-ppm .tabular-nums')->first()->text()));
                    break;
                default:
                    self::fail('No row passes this filter in the fixtures: ' . array_key_first($query));
            }
        }
    }

    private function assertChip(Crawler $crawler, string $id, string $label): void
    {
        $chip = $crawler->filter('a[data-results-chip="' . $id . '"]');
        self::assertCount(1, $chip, $id);
        self::assertSame($label, trim($chip->text()));
        self::assertSame('Remove filter: ' . $label, $chip->attr('aria-label'));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function results(null|string $viewerId, array $query): Crawler
    {
        $browser = $this->browser($viewerId);
        $crawler = $browser->request('GET', '/en/player-results/' . self::PLAYER . ($query !== [] ? '?' . http_build_query($query) : ''));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function browser(null|string $viewerId): KernelBrowser
    {
        $browser = $this->browser ??= self::createClient();
        $browser->getCookieJar()->clear();

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        return $browser;
    }

    private function resultsList(Crawler $crawler): Crawler
    {
        return $crawler->filter('main turbo-frame#player-results');
    }

    private function rowsCount(Crawler $crawler): int
    {
        return $this->resultsList($crawler)->filter('.ps-table tbody tr')->reduce(static fn (Crawler $row): bool => $row->filter('td.ps-image')->count() > 0)->count();
    }

    /**
     * @return list<string>
     */
    private function rowTimeIds(Crawler $crawler): array
    {
        return $crawler->filter('#player-results a.ps-time-value')->each(static fn (Crawler $link): string => basename((string) $link->attr('href')));
    }

    /**
     * Show more's answer as its two <turbo-stream> elements
     *
     * @param array<string, string> $query
     */
    private function stream(KernelBrowser $browser, array $query): Crawler
    {
        $browser->request('GET', '/en/player-results/' . self::PLAYER . '?' . http_build_query([...$query, 'fragment' => 'more']));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $browser->getResponse()->headers->get('Content-Type'));

        $streams = (new Crawler((string) $browser->getResponse()->getContent()))->filter('turbo-stream');
        self::assertCount(2, $streams);

        return $streams;
    }

    private function templateOf(Crawler $stream): Crawler
    {
        $template = $stream->filter('template');
        self::assertCount(1, $template);

        return new Crawler('<table><tbody id="stream">' . $template->html() . '</tbody></table>');
    }

    /**
     * The ids of the result rows, in order (month headings left out)
     *
     * @return list<string>
     */
    private function rowIds(Crawler $crawler): array
    {
        return array_values(array_filter($crawler->filter('tr[id^="result-"]')->each(static fn (Crawler $row): string => (string) $row->attr('id'))));
    }

    /**
     * Each result row's rank chip ('' without one), in order
     *
     * @return list<string>
     */
    private function rankChips(Crawler $crawler): array
    {
        return $crawler->filter('tr[id^="result-"]')->each(static fn (Crawler $row): string => $row->filter('.pr-chip-rank')->count() > 0 ? trim($row->filter('.pr-chip-rank')->text()) : '');
    }

    /**
     * @return list<string>
     */
    private function monthHeadings(Crawler $crawler): array
    {
        return $crawler->filter('tr.prs-month-row')->each(static fn (Crawler $row): string => (string) $row->attr('data-month'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
