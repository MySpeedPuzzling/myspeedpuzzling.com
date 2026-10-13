<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * - each result says where it lives in the Menu: "☰ Menu › Account › Settings" (the registry's Menu path);
 * - a guest gets the pages of any signed-in player as "Sign in to see …" linking the page (through sign-in) - never
 *   Log out, never twice; a member's page is hidden from everybody else;
 * - five queries per locale find their page first (as the Menu's search does, NavigationSearchScriptTest).
 * The list is one static JSON for everybody: a query that finds only pages costs what every query costs
 * (Component\GlobalSearchTest::testAPagesOnlyQueryCostsWhatEveryQueryCosts).
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
            self::assertSame($this->menuPathLine((string) $row['id'], $locale), $row['where'], $row['id'] . ': the line under it is its Menu path');
            self::assertSame('topbar/search/' . $row['id'], $row['nav']);
        }

        self::assertStringStartsWith('☰ ' . $this->trans('nav.menu.title', $locale), (string) $rows[0]['where']);
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
            self::assertSame($this->trans('nav.search.sign_in_to', $locale, ['%label%' => $this->label($id, $locale)]), $row['label']);
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
     * @param array<string, mixed> $facts
     * @return list<array{id: string, label: string, where: null|string, signIn: bool, href: string, nav: string}>
     */
    private function rows(array $facts): array
    {
        $rows = $facts['rows'] ?? null;
        self::assertIsArray($rows);

        /** @var list<array{id: string, label: string, where: null|string, signIn: bool, href: string, nav: string}> $rows */
        return $rows;
    }

    /**
     * Every row the query finds: the case with "Show N more" opened (its rows when there was none).
     *
     * @param array<string, mixed> $facts
     * @return list<array{id: string, label: string, where: null|string, signIn: bool, href: string, nav: string}>
     */
    private function allRows(array $facts): array
    {
        $rows = $facts['expanded'] ?? $facts['rows'] ?? null;
        self::assertIsArray($rows);

        /** @var list<array{id: string, label: string, where: null|string, signIn: bool, href: string, nav: string}> $rows */
        return $rows;
    }

    /**
     * The line under a top-bar result: its Menu path ("☰ › Account › Settings › Password") without the entry, the ☰
     * followed by the Menu's name
     */
    private function menuPathLine(string $id, string $locale): string
    {
        $separator = $this->trans('nav.search.path_separator', $locale);
        self::assertNotSame('', $separator);
        $path = self::getContainer()->get(NavigationRegistry::class)->menuPath($id, $locale, $separator);
        self::assertIsString($path, $id);
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
    private function case(string $viewer, string $locale, string $query, bool $expand = false): array
    {
        $facts = $this->results()[self::caseName($viewer, $locale, $query, $expand)] ?? null;
        self::assertIsArray($facts, sprintf('No case for %s %s "%s"', $viewer, $locale, $query));
        self::assertArrayNotHasKey('threw', $facts, is_string($facts['threw'] ?? null) ? $facts['threw'] : '');

        /** @var array<string, mixed> $facts */
        return $facts;
    }

    private static function caseName(string $viewer, string $locale, string $query, bool $expand): string
    {
        return sprintf('%s|%s|%s%s', $viewer, $locale, $query, $expand ? '|more' : '');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

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
