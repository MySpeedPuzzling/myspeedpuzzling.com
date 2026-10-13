<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\MenuSurface;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Pages & settings" in the top bar's search - the search of every page (docs/features/navigation SRCH-01, README
 * §4.12 "Search"): the real assets/controllers/nav_search_controller.js runs under Stimulus in jsdom on /faq as each
 * viewer gets it in each locale, with that locale's real nav-search.json (tests/top-bar-search-harness.mjs):
 * - the group is the first thing in the search panel (the server's players and puzzles follow it), 3 rows, then
 *   "Show N more";
 * - each result says where it lives in this screen's Menu: "☰ Menu › Account › Settings" (the registry's path on the
 *   phone panel or the desktop Menu) - nothing for a page a bar offers right there (SRCH+FOOT review F3); the ☰ and
 *   the › are aria-hidden, the words read (Q4);
 * - a guest gets the pages that ask them to sign in as the page's name with "Sign in to see" under it (Q3), linking the
 *   page (through sign-in) - never Log out, never twice, never a page that opens for guests (F1: What next? is their
 *   plain row, Where did it go? is not for them, Send feedback is the Help sheet's plain row - opened like every other
 *   opener of the page, F2); the XP pages of a player while XP is visible to guests (Q6); a member's page is hidden
 *   from everybody else;
 * - the group never moves the server's players and puzzles: a list that arrives after them waits for the next
 *   keystroke (F4);
 * - five queries per locale find their page first (as the Menu's search does, NavigationSearchScriptTest).
 * The list is one static JSON for everybody: a query that finds only pages costs what every query costs
 * (Component\GlobalSearchTest::testAPagesOnlyQueryCostsWhatEveryQueryCosts).
 *
 * @phpstan-type Row array{id: string, label: string, where: null|string, whereSpoken: null|string, whereHidden: list<string>, signInNote: bool, signIn: bool, href: string, nav: string, helpOpener: null|string, turboFrame: null|string, scrim: null|string, rel: null|string, name: string}
 */
final class TopBarPageSearchTest extends WebTestCase
{
    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    private const array VIEWERS = [NavigationViewer::GUEST, NavigationViewer::PLAYER, NavigationViewer::MEMBER];

    /**
     * locale => query => the entry it finds first (the first five queries of NavigationSearchScriptTest per locale)
     */
    private const array QUERIES = [
        'en' => ['export' => 'your_data', 'password' => 'change_password', 'ranking' => 'leaderboards', 'my results' => 'results', 'premium' => 'membership'],
        'cs' => ['export' => 'your_data', 'heslo' => 'change_password', 'pořadí' => 'leaderboards', 'moje výsledky' => 'results', 'prémium' => 'membership'],
        'de' => ['export' => 'your_data', 'passwort' => 'change_password', 'rangliste' => 'leaderboards', 'meine ergebnisse' => 'results', 'premium' => 'membership'],
        'es' => ['exportar' => 'your_data', 'contraseña' => 'change_password', 'clasificación' => 'leaderboards', 'mis resultados' => 'results', 'premium' => 'membership'],
        'fr' => ['exporter' => 'your_data', 'mot de passe' => 'change_password', 'classement' => 'leaderboards', 'mes résultats' => 'results', 'premium' => 'membership'],
        'ja' => ['エクスポート' => 'your_data', 'パスワード' => 'change_password', 'ランキング' => 'leaderboards', '記録' => 'results', 'プレミアム' => 'membership'],
    ];

    // A page for members only (Menu › Your puzzles › Library), an entry for any signed-in player that a guest is offered
    // through sign-in, one never offered, one guests have a link of their own for
    private const string MEMBER_ONLY = 'new_collection';
    private const string LOG_OUT = 'logout';
    private const string HOME = 'home';

    private const int BAR_LIMIT = 3;

    // A private link page a guest opens (a newsletter link the server does not know: its own page on the base layout)
    private const string PRIVATE_PAGE = '/en/newsletter/confirm/S3cretT0kenValue';

    // Queries of the extra pages (en): the entries of the review's findings, typed by their names
    private const array GUEST_QUERIES = ['what next', 'what should I solve next', 'where did it go', 'feedback'];
    // ... and these entries typed by their labels (Q6: the XP pages of a player; karma and the referral programme never)
    private const array GUEST_LABEL_QUERIES = ['level_xp', 'achievements', 'your_week', 'karma', 'referral'];
    private const array SCREEN_QUERIES = ['home', 'inbox', 'password', 'settings', 'log out', 'puzzles'];

    /** @var null|array<string, array<string, mixed>> */
    private static null|array $results = null;

    /** @var array<string, array<string, array<string, mixed>>> locale => id => the list's entry */
    private static array $entries = [];

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function memberQueries(): iterable
    {
        foreach (self::QUERIES as $locale => $queries) {
            foreach ($queries as $query => $id) {
                yield $locale . ' ' . $query => [$locale, $query, $id];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    public function testNoCaseThrewOrLoggedAnError(): void
    {
        foreach ($this->results() as $name => $facts) {
            self::assertArrayNotHasKey('threw', $facts, $name . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
            self::assertSame([], $facts['errors'] ?? null, $name);
        }
    }

    /**
     * Five queries per locale from the top bar: the expected page first, at most three rows, the group first in the
     * search panel under its title, each row with its Menu path.
     */
    #[DataProvider('memberQueries')]
    public function testTheTopBarFindsThePageAndSaysWhereItIsInTheMenu(string $locale, string $query, string $id): void
    {
        $facts = $this->case(NavigationViewer::MEMBER, $locale, $query);
        $rows = $this->rows($facts);

        self::assertSame($id, $rows[0]['id'] ?? null, sprintf('%s "%s": %s', $locale, $query, implode(', ', array_column($rows, 'id'))));
        self::assertLessThanOrEqual(self::BAR_LIMIT, count($rows));
        self::assertTrue($facts['firstInPanel'], 'The group comes first in the search panel');
        self::assertFalse($facts['inMenu']);
        self::assertFalse($facts['hidden']);
        self::assertSame($this->trans('nav.search.pages_settings', $locale), $facts['title']);

        foreach ($rows as $row) {
            self::assertFalse($row['signIn'], $row['id'] . ': a member is never asked to sign in');
            self::assertSame($this->lineUnder((string) $row['id'], $locale, phone: true, signedIn: true), $row['where'], $row['id'] . ': the line under it is its phone Menu path');
            self::assertSame('topbar/search/' . $row['id'], $row['nav']);
        }

        self::assertStringStartsWith('☰ ' . $this->trans('nav.menu.title', $locale), (string) $rows[0]['where']);
        // Q4: the symbols are hidden from screen readers, the words read
        $top = $rows[0];
        self::assertSame('☰', $top['whereHidden'][0] ?? null);
        self::assertSame(array_fill(0, substr_count((string) $top['where'], '›'), '›'), array_slice($top['whereHidden'], 1));
        self::assertSame(trim((string) preg_replace('/\s+/u', ' ', str_replace(['☰', '›'], '', (string) $top['where']))), $top['whereSpoken']);
        self::assertStringNotContainsString('☰', $top['name']);
        self::assertStringNotContainsString('›', $top['name']);
    }

    /**
     * A guest is offered a signed-in player's page as "Sign in to see …", linking the page itself (sign-in comes first,
     * then the page - LoginEntryPoint's ?return=; a page of the viewer's own goes through nav_go).
     */
    #[DataProvider('locales')]
    public function testAGuestIsOfferedSignedInPagesThroughSignIn(string $locale): void
    {
        foreach (['change_password' => 1, 'results' => 3] as $id => $queryIndex) {
            $query = array_keys(self::QUERIES[$locale])[$queryIndex];
            $rows = $this->rows($this->case(NavigationViewer::GUEST, $locale, $query));
            $row = $rows[0] ?? null;

            self::assertIsArray($row, sprintf('%s "%s": nothing found for a guest', $locale, $query));
            self::assertSame($id, $row['id']);
            self::assertTrue($row['signIn'], $id . ' is offered through sign-in');
            // Q3: its own name, "Sign in to see" under it - no sentence built around a label
            self::assertSame($this->label($id, $locale), $row['label']);
            self::assertSame($this->trans('nav.search.sign_in_to_see', $locale), $row['where']);
            self::assertTrue($row['signInNote']);
            self::assertSame($this->entry($locale, $id)['u'], $row['href'], 'It links the page; signing in comes first');
        }

        $results = $this->entry($locale, 'results')['u'] ?? null;
        self::assertIsString($results);
        self::assertStringContainsString('/go/results', $results, 'Your own results: nav_go names you after the sign-in');

        // A page for everybody stays a plain result
        $rows = $this->rows($this->case(NavigationViewer::GUEST, $locale, array_keys(self::QUERIES[$locale])[2]));
        self::assertSame('leaderboards', $rows[0]['id'] ?? null);
        self::assertFalse($rows[0]['signIn']);
    }

    /**
     * Never "Sign in to see Log out"; never the signed-in half of an entry a guest has a link of their own for (Home is
     * the homepage for them); a member's page is hidden from guests and non-members alike - shown to members.
     */
    #[DataProvider('locales')]
    public function testWhatAGuestAndANonMemberAreNotOffered(string $locale): void
    {
        $logOut = $this->allRows($this->case(NavigationViewer::GUEST, $locale, $this->label(self::LOG_OUT, $locale), expand: true));
        self::assertNotContains(self::LOG_OUT, array_column($logOut, 'id'), 'Never "Sign in to see Log out"');
        self::assertContains(self::LOG_OUT, array_column($this->allRows($this->case(NavigationViewer::MEMBER, $locale, $this->label(self::LOG_OUT, $locale), expand: true)), 'id'));

        $home = $this->allRows($this->case(NavigationViewer::GUEST, $locale, $this->label(self::HOME, $locale), expand: true));
        $homes = array_values(array_filter($home, static fn (array $row): bool => $row['id'] === self::HOME));
        self::assertCount(1, $homes, 'One Home for a guest');
        self::assertFalse($homes[0]['signIn']);

        $label = $this->label(self::MEMBER_ONLY, $locale);

        foreach ([NavigationViewer::GUEST, NavigationViewer::PLAYER] as $viewer) {
            self::assertNotContains(self::MEMBER_ONLY, array_column($this->allRows($this->case($viewer, $locale, $label, expand: true)), 'id'), $viewer . ': a member\'s page stays hidden');
        }

        $member = $this->allRows($this->case(NavigationViewer::MEMBER, $locale, $label, expand: true));
        self::assertContains(self::MEMBER_ONLY, array_column($member, 'id'));

        // The non-member's own pages are plain results
        $password = $this->rows($this->case(NavigationViewer::PLAYER, $locale, array_keys(self::QUERIES[$locale])[1]));
        self::assertSame('change_password', $password[0]['id'] ?? null);
        self::assertFalse($password[0]['signIn']);
    }

    /**
     * More than three pages: "Show N more" (decision 38); it shows them all and focuses the first one it brought.
     */
    #[DataProvider('locales')]
    public function testShowMore(string $locale): void
    {
        $facts = $this->case(NavigationViewer::GUEST, $locale, array_keys(self::QUERIES[$locale])[2], expand: true);
        $rows = $this->rows($facts);
        $expanded = $facts['expanded'] ?? null;
        self::assertIsArray($expanded);

        self::assertCount(self::BAR_LIMIT, $rows);
        self::assertGreaterThan(self::BAR_LIMIT, count($expanded));
        self::assertSame($this->trans('nav.search.more', $locale, ['%count%' => count($expanded) - self::BAR_LIMIT]), $facts['more']);
        self::assertSame('topbar/search/more', $facts['moreNav']);
        self::assertFalse($facts['moreAfter']);
        $firstBrought = $expanded[self::BAR_LIMIT] ?? null;
        self::assertIsArray($firstBrought);
        self::assertSame($firstBrought['id'] ?? null, $facts['focusedAfter']);
        self::assertSame(array_column($rows, 'id'), array_slice(array_column($expanded, 'id'), 0, self::BAR_LIMIT));
    }

    /**
     * F1/F2/Q6: a guest's sign-in rows are the pages that ask them to sign in - What next? is the landing they open
     * (their plain row), Where did it go? is not for guests (R2), Send feedback is the Help sheet's plain row opened like
     * the top bar's ?; the XP pages of a player follow the XP gate; karma and the referral programme never.
     */
    public function testAGuestIsOfferedOnlyWhatReallyAsksThemToSignIn(): void
    {
        $guest = fn (string $query): array => $this->allRows($this->case(NavigationViewer::GUEST, 'en', $query, expand: true, page: 'extra'));
        $first = $this->rowOf(...);

        foreach (['what next', 'what should I solve next'] as $query) {
            $rows = $guest($query);
            self::assertSame('what_next', $rows[0]['id'] ?? null, $query);
            self::assertFalse($rows[0]['signIn'], 'What next? opens for guests: a plain row');
            self::assertSame('/en/what-to-solve-next', $rows[0]['href']);
            self::assertCount(1, array_filter($rows, static fn (array $row): bool => $row['id'] === 'what_next'), 'once');
        }

        self::assertNull($first($guest('where did it go'), 'where_did_it_go'), 'Where did it go? is not offered to guests (R2)');

        $feedback = $first($guest('feedback'), 'send_feedback');
        self::assertIsArray($feedback);
        self::assertFalse($feedback['signIn'], 'the Help sheet asks inside');
        $bar = $this->case(NavigationViewer::GUEST, 'en', 'feedback', expand: true, page: 'extra');
        self::assertSame($bar['barHelpHref'], $feedback['href'], 'F2: the top bar ?\'s own help path');
        self::assertSame(['page', 'modal-frame', 'own', 'nofollow'], [$feedback['helpOpener'], $feedback['turboFrame'], $feedback['scrim'], $feedback['rel']]);

        // Q6: the XP pages of a player, while XP is visible to guests (the test environment's gate is open)
        $audience = $bar['audience'] ?? null;
        self::assertIsString($audience);
        self::assertContains('xp_visible', explode(' ', $audience));

        foreach (['level_xp', 'achievements', 'your_week'] as $id) {
            $row = $first($guest($this->label($id, 'en')), $id);
            self::assertIsArray($row, $id . ' is offered to a guest');
            self::assertTrue($row['signIn'], $id . ' through sign-in');
        }

        foreach (['karma', 'referral'] as $id) {
            self::assertNull($first($guest($this->label($id, 'en')), $id), $id . ' is never offered to a guest');
        }
    }

    /**
     * F2: on a private link page the guest's Send feedback row attaches the token-free path, never the token; on the
     * help page it is an opener, so assets/help_openers.js leads to the page's own box instead of a second sheet.
     */
    public function testTheGuestsSendFeedbackRowIsTheHelpOpenerOfThePage(): void
    {
        $private = $this->case(NavigationViewer::GUEST, 'en', 'feedback', page: 'private');
        $row = $this->rowOf($this->rows($private), 'send_feedback');
        self::assertIsArray($row);
        self::assertStringNotContainsString('S3cretT0kenValue', $row['href']);
        self::assertSame($private['barHelpHref'], $row['href']);
        self::assertStringContainsString('url=/en/newsletter/confirm/%7Btoken%7D', str_replace('{token}', '%7Btoken%7D', $row['href']));
        self::assertSame('private', $row['helpOpener']);

        $help = $this->case(NavigationViewer::GUEST, 'en', 'feedback', page: 'help');
        $row = $this->rowOf($this->rows($help), 'send_feedback');
        self::assertIsArray($row);
        self::assertNotNull($row['helpOpener'], 'on the help page an opener leads to its own box');
    }

    /**
     * F3: the line under a result names this screen's Menu - nothing for an entry a bar offers right there: on a phone
     * the tabs (Home, Puzzles) and the Inbox tool, on desktop the Inbox tool and the account menu (Settings, Log out).
     */
    public function testThePathLineFollowsTheScreen(): void
    {
        foreach ([true, false] as $phone) {
            $page = $phone ? 'extra' : 'desktop';

            foreach (['home' => 'home', 'puzzles' => 'puzzles_place', 'inbox' => 'inbox', 'password' => 'change_password', 'settings' => 'settings', 'log out' => 'logout'] as $query => $id) {
                $row = $this->rowOf($this->allRows($this->case(NavigationViewer::MEMBER, 'en', $query, expand: true, page: $page)), $id);
                self::assertIsArray($row, sprintf('%s "%s"', $page, $query));
                self::assertSame($this->lineUnder($id, 'en', $phone, signedIn: true), $row['where'], sprintf('%s: %s', $page, $id));
            }
        }

        // The review's rows, literally: Home at 360 and Inbox at 1280 say no "☰ Menu"; Password says where it is on both
        foreach ([['extra', 'home', 'home'], ['desktop', 'inbox', 'inbox']] as [$page, $query, $id]) {
            $row = $this->rowOf($this->allRows($this->case(NavigationViewer::MEMBER, 'en', $query, expand: true, page: $page)), $id);
            self::assertIsArray($row);
            self::assertStringNotContainsString('☰', (string) $row['where'], $page . ' ' . $id);
        }

        foreach (['extra', 'desktop'] as $page) {
            $rows = $this->rows($this->case(NavigationViewer::MEMBER, 'en', 'password', page: $page));
            self::assertSame('☰ Menu › Account › Settings', $rows[0]['where'] ?? null, $page);
        }
    }

    /**
     * F4: the list arrives after the server's players and puzzles are on screen - the group is not inserted above them
     * (nothing moves); the next keystroke shows it.
     */
    public function testALateListNeverMovesTheServerResults(): void
    {
        $late = $this->case(NavigationViewer::MEMBER, 'en', 'puzzle', page: 'held');
        self::assertSame(['hidden' => true, 'rows' => 0], $late['groupBeforeList']);
        self::assertTrue($late['hidden'], 'the list came: still nothing above the players and puzzles');
        self::assertSame([], $late['rows']);

        $next = $this->case(NavigationViewer::MEMBER, 'en', 'puzzles', page: 'held');
        self::assertFalse($next['hidden'], 'the next keystroke shows the group');
        self::assertNotSame([], $next['rows']);
        self::assertTrue($next['firstInPanel']);
    }

    /**
     * @param list<Row> $rows
     * @return null|Row
     */
    private function rowOf(array $rows, string $id): null|array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<Row>
     */
    private function rows(array $facts): array
    {
        $rows = $facts['rows'] ?? null;
        self::assertIsArray($rows);

        /** @var list<Row> $rows */
        return $rows;
    }

    /**
     * Every row the query finds: the case with "Show N more" opened (its rows when there was none).
     *
     * @param array<string, mixed> $facts
     * @return list<Row>
     */
    private function allRows(array $facts): array
    {
        $rows = $facts['expanded'] ?? $facts['rows'] ?? null;
        self::assertIsArray($rows);

        /** @var list<Row> $rows */
        return $rows;
    }

    /**
     * The line under a top-bar result (F3): on a screen whose bars offer the entry, its description (or nothing);
     * otherwise its path in that screen's Menu ("☰ › Account › Settings › Password") without the entry, the ☰ followed
     * by the Menu's name; its description when that Menu has no row for it
     */
    private function lineUnder(string $id, string $locale, bool $phone, bool $signedIn): null|string
    {
        $registry = self::getContainer()->get(NavigationRegistry::class);
        $bars = NavigationRegistry::ON_BARS[$id] ?? [];
        $onBar = $phone
            ? in_array(NavigationRegistry::BAR_PHONE, $bars, true)
            : in_array(NavigationRegistry::BAR_DESKTOP, $bars, true) || ($signedIn && in_array(NavigationRegistry::BAR_ACCOUNT, $bars, true));
        $separator = $this->trans('nav.search.path_separator', $locale);
        self::assertNotSame('', $separator);
        $path = $onBar ? null : $registry->menuPathOn($phone ? MenuSurface::Phone : MenuSurface::Dock, $id, $locale, $separator);

        if ($path === null) {
            $entry = $registry->byId($id);
            self::assertNotNull($entry, $id);

            return $entry->description !== null ? $this->trans($entry->description, $locale) : null;
        }

        $parts = explode($separator, $path);
        array_pop($parts);
        self::assertSame('☰', $parts[0] ?? null);
        $parts[0] = '☰ ' . $this->trans('nav.menu.title', $locale);

        return implode($separator, $parts);
    }

    private function label(string $id, string $locale): string
    {
        $registry = self::getContainer()->get(NavigationRegistry::class);
        $entry = $registry->byId($id);
        self::assertNotNull($entry, $id);

        return $registry->label($entry, $locale);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, string $locale, array $parameters = []): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, $parameters, null, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $locale, string $id): array
    {
        $this->results();
        $entry = self::$entries[$locale][$id] ?? null;
        self::assertIsArray($entry, $locale . ' ' . $id);

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function case(string $viewer, string $locale, string $query, bool $expand = false, string $page = 'faq'): array
    {
        $facts = $this->results()[self::caseName($viewer, $locale, $query, $expand, $page)] ?? null;
        self::assertIsArray($facts, sprintf('No case for %s %s "%s" on %s', $viewer, $locale, $query, $page));
        self::assertArrayNotHasKey('threw', $facts, is_string($facts['threw'] ?? null) ? $facts['threw'] : '');

        /** @var array<string, mixed> $facts */
        return $facts;
    }

    private static function caseName(string $viewer, string $locale, string $query, bool $expand, string $page = 'faq'): string
    {
        return sprintf('%s|%s|%s|%s%s', $page, $viewer, $locale, $query, $expand ? '|more' : '');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        // F5: whatever ran first (a label read from the container) may have booted the kernel - createClient() refuses
        // a booted one
        self::ensureKernelShutdown();
        $browser = self::createClient();
        $browser->disableReboot();
        $pages = [];
        $lists = [];
        $cases = [];

        foreach (self::VIEWERS as $viewer) {
            NavigationViewer::signIn($browser, $viewer);

            foreach (self::LOCALES as $locale) {
                $crawler = $browser->request('GET', $this->router()->generate('faq', ['_locale' => $locale]));
                self::assertResponseIsSuccessful($viewer . ' ' . $locale);
                $name = $viewer . '|' . $locale;
                $pages[$name] = (string) $browser->getResponse()->getContent();
                $url = $this->listUrl($crawler);

                if (!isset($lists[$url])) {
                    $browser->request('GET', $url);
                    self::assertResponseIsSuccessful($url);
                    $list = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                    self::assertIsArray($list);
                    $lists[$url] = $list;
                    $entries = $list['entries'] ?? null;
                    self::assertIsArray($entries);

                    foreach ($entries as $entry) {
                        self::assertIsArray($entry);
                        $id = $entry['id'] ?? null;
                        self::assertIsString($id);
                        // An entry with a guests' route is two entries: keep the signed-in one (the first)
                        self::$entries[$locale][$id] ??= array_filter($entry, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
                    }
                }

                $queries = [];
                $texts = [...array_keys(self::QUERIES[$locale]), $this->label(self::LOG_OUT, $locale), $this->label(self::HOME, $locale), $this->label(self::MEMBER_ONLY, $locale)];

                foreach (array_unique($texts) as $query) {
                    $queries[] = ['name' => self::caseName($viewer, $locale, (string) $query, false), 'query' => (string) $query, 'expand' => false];
                    $queries[] = ['name' => self::caseName($viewer, $locale, (string) $query, true), 'query' => (string) $query, 'expand' => true];
                }

                $cases[] = ['page' => $name, 'queries' => $queries];
            }

            // The extra pages of the review's findings (en): the guest's queries; a member's screen queries on a phone
            // (no matchMedia = the phone) and at 1280 (desktop); a guest on a private link page and on the help page; a
            // member whose list arrives late (F4)
            $extra = self::SCREEN_QUERIES;

            if ($viewer === NavigationViewer::GUEST) {
                $extra = self::GUEST_QUERIES;

                foreach (self::GUEST_LABEL_QUERIES as $id) {
                    $extra[] = $this->label($id, 'en');
                }
            }

            $extraQueries = static function (string $page, string ...$texts) use ($viewer): array {
                $queries = [];

                foreach ($texts as $text) {
                    $queries[] = ['name' => self::caseName($viewer, 'en', $text, false, $page), 'query' => $text, 'expand' => false];
                    $queries[] = ['name' => self::caseName($viewer, 'en', $text, true, $page), 'query' => $text, 'expand' => true];
                }

                return $queries;
            };
            $cases[] = ['page' => $viewer . '|en', 'queries' => $extraQueries('extra', ...$extra)];

            if ($viewer === NavigationViewer::MEMBER) {
                $cases[] = ['page' => $viewer . '|en', 'phone' => false, 'queries' => $extraQueries('desktop', ...$extra)];
                $cases[] = ['page' => $viewer . '|en', 'delayList' => true, 'queries' => [
                    ['name' => self::caseName($viewer, 'en', 'puzzle', false, 'held'), 'query' => 'puzzle', 'serverResults' => true],
                    ['name' => self::caseName($viewer, 'en', 'puzzles', false, 'held'), 'query' => 'puzzles'],
                ]];
            }

            if ($viewer === NavigationViewer::GUEST) {
                foreach (['private' => self::PRIVATE_PAGE, 'help' => $this->router()->generate('help', ['_locale' => 'en'])] as $page => $url) {
                    $browser->request('GET', $url);
                    $pages[$viewer . '|' . $page] = (string) $browser->getResponse()->getContent();
                    $cases[] = ['page' => $viewer . '|' . $page, 'url' => $url, 'queries' => $extraQueries($page, 'feedback')];
                }
            }
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the controller - it is part of the base image');

        $process = new Process([$node, '--no-warnings', __DIR__ . '/top-bar-search-harness.mjs']);
        $process->setInput(json_encode(['pages' => $pages, 'lists' => $lists, 'cases' => $cases], JSON_THROW_ON_ERROR));
        $process->setTimeout(300);
        $process->mustRun();

        /** @var array<string, array<string, mixed>> $decoded */
        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $decoded;
    }

    private function listUrl(Crawler $crawler): string
    {
        $url = $crawler->filter('.global-search-bar[data-nav-search-url-value]')->attr('data-nav-search-url-value');
        self::assertIsString($url);
        self::assertStringStartsWith('/', $url);

        return $url;
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get(RouterInterface::class);
    }
}
