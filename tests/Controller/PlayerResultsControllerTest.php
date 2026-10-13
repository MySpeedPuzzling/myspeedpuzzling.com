<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A player's Results (docs/features/navigation README §4.7): PROF-00 made the route, PROF-05a its content - rendered
 * from the URL. A player hidden from the viewer is a 404 (GetPlayerProfile::byId), a private profile the viewer may not
 * see goes to the profile (implementation plan decision 69). Every parameter of the URL grammar works, and the
 * members-only ones work for members only: for anybody else they neither filter nor show a chip.
 */
final class PlayerResultsControllerTest extends WebTestCase
{
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
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));
        // The player header with the name as the page's H1, the search first, and the list - as many puzzles as the
        // profile lists (the same solo tab)
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $this->resultsList($crawler));
        self::assertCount(1, $crawler->filter('main form#player-results-form input[type="search"][name="q"]'));
        self::assertGreaterThan(0, $this->rowsCount($crawler));
        self::assertSame($profile->filter('[data-live-name-value="PlayerSolvedPuzzles"] .ps-table tbody tr')->count(), $this->rowsCount($crawler));

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
        // (the head follows her own setting, not the friend's view of her - as on the profile)
        foreach ([PlayerFixture::PLAYER_PRIVATE => 'noindex, follow', PlayerFixture::PLAYER_WITH_FAVORITES => 'noindex, nofollow'] as $viewerId => $robots) {
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

    #[DataProvider('provideEveryViewer')]
    public function testPage(null|string $viewerId): void
    {
        $this->browser($viewerId);
        $this->addSoloResults(60);

        self::assertSame(50, $this->rowsCount($this->results($viewerId, [])));
        self::assertSame(69, $this->rowsCount($this->results($viewerId, ['page' => '2'])));
        self::assertSame(69, $this->rowsCount($this->results($viewerId, ['page' => '999'])), 'Beyond the end = the last page');
        self::assertSame(50, $this->rowsCount($this->results($viewerId, ['page' => 'x'])));
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

    // --------------------------------------------------------------------------------- helpers

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
     * Copies of one of the player's solved puzzles, each with his solo time (rolled back with the test's transaction)
     */
    private function addSoloResults(int $copies): void
    {
        $database = self::getContainer()->get(Connection::class);
        $timeId = $database->fetchOne(
            "SELECT id FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId AND puzzling_type = 'solo' ORDER BY id LIMIT 1",
            ['playerId' => self::PLAYER, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        $database->executeStatement(
            'CREATE TEMP TABLE copy_puzzle ON COMMIT DROP AS SELECT puzzle.*, g AS copy FROM puzzle, generate_series(1, :copies) g WHERE puzzle.id = :puzzleId',
            ['copies' => $copies, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );
        $database->executeStatement("UPDATE copy_puzzle SET id = gen_random_uuid(), name = 'Copy ' || copy, identification_number = NULL, search_codes = ''");
        $database->executeStatement(
            'CREATE TEMP TABLE copy_time ON COMMIT DROP AS SELECT pst.*, cp.id AS copy_puzzle_id FROM puzzle_solving_time pst, copy_puzzle cp WHERE pst.id = :timeId',
            ['timeId' => $timeId],
        );
        $database->executeStatement('UPDATE copy_time SET id = gen_random_uuid(), puzzle_id = copy_puzzle_id');
        $database->executeStatement('ALTER TABLE copy_puzzle DROP COLUMN copy');
        $database->executeStatement('ALTER TABLE copy_time DROP COLUMN copy_puzzle_id');
        $database->executeStatement('INSERT INTO puzzle SELECT * FROM copy_puzzle');
        $database->executeStatement('INSERT INTO puzzle_solving_time SELECT * FROM copy_time');
    }
}
