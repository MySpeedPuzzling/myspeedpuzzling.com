<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\MenuSurface;
use SpeedPuzzling\Web\Navigation\NavEntry;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Navigation\NavVisibility;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Menu is complete (docs/features/navigation MENU-04, README §7 / D19 "every destination a Menu row or a search-only
 * entry"):
 * - every registry destination is a Menu row (phone panel or docked column), a search-only entry found under an
 *   existing row, or "not in Menu" with its reason;
 * - whatever the other navigation surfaces link for a viewer - the top bar with the account menu, the tab bar / rail,
 *   the footer, the Help sheet - is in that viewer's Menu: a row they get, a search-only entry their page search lists,
 *   or a destination the registry keeps out of the Menu on purpose (its reason); the only exceptions are the guests'
 *   footer links to signed-in rows (GUEST_FOOTER_ONLY);
 * - every row the viewer gets and every entry their page search lists opens for them (the viewer's own parameters
 *   filled, nav_go and every redirect followed): 200, never 403/404, never the sign-in page for a signed-in viewer,
 *   and for a guest only from the guest card's Sign in / Join free (a guest row that needs signing in would be a
 *   locked row - D13: hidden, never locked);
 * - the viewers between them are in every audience a row or a search-only entry uses (VIEWERS), and each one reaches
 *   rows of the audiences it is listed for - so no audience's rows go unopened.
 *
 * Removing a row and its search-only entry makes it fail. Runs after NAV-06 and again in QA.
 */
final class NavigationMenuCompletenessTest extends WebTestCase
{
    // The navigation surfaces besides the Menu (the account menu is part of the top bar); the Help sheet is read from
    // its own frame
    private const string OTHER_SURFACES = '.site-header, #bottom-nav, footer.site-footer';

    // The Menu as the page renders it: the docked-column slot holds every row of both surfaces (MENU-01)
    private const string MENU = '#menu-slot #site-menu';

    private const string PAGE = '/en/faq';

    // Today's footer links these for guests too, while their Menu rows are for signed-in players (README §4.12: the
    // personal groups are hidden for guests, rows are hidden - never locked - where the viewer cannot use them). FOOT-01
    // rebuilds the footer from the registry and decides what a guest's footer links: an entry here fails as stale once
    // a guest no longer gets the link or gets the row. route => the row
    private const array GUEST_FOOTER_ONLY = [
        'membership' => 'Account › Membership (its registry reason: guests reach it from the footer)',
        'comparison' => 'Your puzzling › Compare',
        'pairs_and_teams' => 'Your puzzling › Pairs & teams',
        'puzzle_picker' => 'Your puzzles › What next?',
        'stopwatch' => 'Add & track › Stopwatch',
        'multiscan' => 'Add & track › Scan several boxes',
    ];

    // PLAYER with karma set in the test (no fixture has any; DAMA rolls it back), as KarmaPagesTest does
    private const string KARMA_HOLDER = 'karma holder';

    /**
     * The viewers, each with the audiences (NavVisibility) whose rows it is here to open - between them every audience
     * a row or a search-only entry uses (testTheViewersCoverEveryAudience), each reached by its viewer
     * (testEveryRowAndSearchEntryOfTheViewerOpens). The member is also an event owner; the admin is the moderator and
     * the time verifier too; MEMBER_ONLY (PLAYER_PRIVATE with a membership) is the private member.
     *
     * @var array<string, list<NavVisibility>>
     */
    private const array VIEWERS = [
        NavigationViewer::GUEST => [NavVisibility::Everyone, NavVisibility::GuestOnly, NavVisibility::XpVisible],
        NavigationViewer::PLAYER => [NavVisibility::SignedIn, NavVisibility::XpPlayer, NavVisibility::XpParticipant, NavVisibility::AchievementsShown],
        self::KARMA_HOLDER => [NavVisibility::KarmaHolder],
        NavigationViewer::MEMBER => [NavVisibility::Member, NavVisibility::MemberOrReferral, NavVisibility::Organiser],
        NavigationViewer::MEMBER_ONLY => [NavVisibility::PrivateMember],
        NavigationViewer::ADMIN => [NavVisibility::Moderator, NavVisibility::TimeVerifier, NavVisibility::Admin],
    ];

    // The only links a guest's Menu may send to the sign-in or sign-up page: the guest card's Sign in and Join free
    // (data-nav) and its page-search entry (id). Exact: an entry fails as stale once it no longer gets there
    private const array GUEST_AUTH_LINKS = ['row menu/you/sign_in', 'row menu/you/join', 'search entry guest_card'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewers(): iterable
    {
        foreach (array_keys(self::VIEWERS) as $viewer) {
            yield $viewer => [$viewer];
        }
    }

    public function testTheViewersCoverEveryAudience(): void
    {
        $used = [];

        foreach ($this->registry()->all() as $entry) {
            if ($entry->isMenuRow() || $entry->isSearchOnly()) {
                $used[$entry->visibility->value] = true;
            }
        }

        $covered = [];

        foreach (self::VIEWERS as $audiences) {
            foreach ($audiences as $audience) {
                $covered[$audience->value] = true;
            }
        }

        $missing = array_keys(array_diff_key($used, $covered));
        self::assertSame([], $missing, 'Rows of these audiences are opened by no viewer: add a viewer in them to VIEWERS');
    }

    public function testEveryDestinationIsARowASearchEntryOrSaysWhyNot(): void
    {
        $registry = $this->registry();
        $rows = 0;
        $searchOnly = 0;

        foreach ($registry->all() as $entry) {
            if ($entry->isSearchOnly()) {
                $searchOnly++;
                $parent = $registry->byId((string) $entry->phone->parent);
                self::assertNotNull($parent, sprintf('Search-only entry "%s": its row "%s" does not exist', $entry->id, $entry->phone->parent));
                self::assertTrue($parent->isMenuRow(), sprintf('Search-only entry "%s": "%s" is no Menu row', $entry->id, $parent->id));
                self::assertSame($entry->phone->parent, $entry->dock->parent, sprintf('Search-only entry "%s": one row on both surfaces', $entry->id));
                self::assertNotNull($entry->target->route, sprintf('Search-only entry "%s" opens nothing', $entry->id));

                continue;
            }

            if ($entry->isMenuRow()) {
                $rows++;

                foreach (MenuSurface::cases() as $surface) {
                    $placement = $entry->placement($surface);
                    self::assertTrue($placement->isRow() || ($placement->isNotInMenu() && trim((string) $placement->reason) !== ''), sprintf('Row "%s" on %s: a row there too, or the reason it is not', $entry->id, $surface->value));
                }

                continue;
            }

            self::assertTrue($entry->isNotInMenu(), sprintf('Entry "%s" is no Menu row, no search-only entry and not "not in Menu"', $entry->id));
            self::assertNotSame('', trim((string) $entry->phone->reason), sprintf('Entry "%s": "not in Menu" needs its reason', $entry->id));
            self::assertNotSame('', trim((string) $entry->dock->reason), sprintf('Entry "%s": "not in Menu" needs its reason', $entry->id));
        }

        self::assertGreaterThan(70, $rows);
        self::assertGreaterThan(25, $searchOnly);
    }

    #[DataProvider('viewers')]
    public function testWhatTheOtherSurfacesLinkIsInTheViewersMenu(string $role): void
    {
        $browser = self::createClient();
        $this->signIn($browser, $role);

        $crawler = $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        $menuIds = $this->menuIds($crawler);
        $searchIds = [...$this->publicSearch($browser, $this->audience($crawler)), ...$this->privateSearch($crawler)];
        $linked = $this->linkedRoutes($crawler->filter(self::OTHER_SURFACES), 'faq');

        $crawler = $browser->request('GET', $this->router()->generate('help', ['_locale' => 'en', 'url' => self::PAGE]), server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful();
        $linked += $this->linkedRoutes($crawler->filter('[data-help-sheet]'), 'faq');

        self::assertGreaterThan(15, count($linked), 'Hardly any link on the other surfaces - did they change? Update OTHER_SURFACES');

        $registry = $this->registry();
        $missing = [];
        $footerOnly = [];

        foreach (array_keys($linked) as $route) {
            $entries = $registry->byTargetRoute($route);
            $inMenu = array_filter($entries, static fn (NavEntry $entry): bool => isset($menuIds[$entry->id])
                || ($entry->isSearchOnly() && isset($searchIds[$entry->id]))
                || $entry->isNotInMenu());

            if ($inMenu !== []) {
                continue;
            }

            if ($role === NavigationViewer::GUEST && array_key_exists($route, self::GUEST_FOOTER_ONLY)) {
                $footerOnly[] = $route;

                continue;
            }

            $missing[] = sprintf('%s (entries: %s)', $route, $entries === [] ? 'none' : implode(', ', array_map(static fn (NavEntry $entry): string => $entry->id, $entries)));
        }

        if ($role === NavigationViewer::GUEST) {
            sort($footerOnly);
            $expected = array_keys(self::GUEST_FOOTER_ONLY);
            sort($expected);
            self::assertSame($expected, $footerOnly, 'Stale GUEST_FOOTER_ONLY: a guest no longer gets one of these footer links, or now gets its row');
        }

        self::assertSame([], $missing, sprintf("%s: linked by the bars, the footer or the Help sheet but neither a row of their Menu, nor in their page search, nor kept out of the Menu with a reason:\n%s", $role, implode("\n", $missing)));
    }

    #[DataProvider('viewers')]
    public function testEveryRowAndSearchEntryOfTheViewerOpens(string $role): void
    {
        $browser = self::createClient();
        $playerId = $this->signIn($browser, $role);

        $crawler = $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        // The registry entries the viewer gets: the rows of their Menu, the entries of their page search
        $reached = $this->menuIds($crawler);

        // The rows as the viewer gets them, the Log out row aside (it ends the session)
        $targets = [];

        foreach ($crawler->filter(self::MENU)->filter('a[href]') as $link) {
            assert($link instanceof \DOMElement);

            if ($link->getAttribute('data-turbo') === 'false' && str_contains($link->getAttribute('data-nav'), 'logout')) {
                continue;
            }

            $targets[$this->withoutFragment($link->getAttribute('href'))] = 'row ' . $link->getAttribute('data-nav');
        }

        $rows = count($targets);
        self::assertGreaterThan(20, $rows, 'Hardly any Menu row');

        foreach ([...$this->publicSearchEntries($browser, $this->audience($crawler)), ...$this->privateSearchEntries($crawler)] as $item) {
            if ($item['x'] === 'logout') {
                continue;
            }

            $targets[$this->withoutFragment($item['u'])] ??= 'search entry ' . $item['id'];
            $reached[$item['id']] = true;
        }

        self::assertGreaterThan($rows, count($targets), 'The page search adds no destination of its own');

        // The viewer is here for these audiences: rows (or search entries) of each are among what they open
        $registry = $this->registry();
        $reachedAudiences = [];

        foreach (array_keys($reached) as $id) {
            $entry = $registry->byId((string) $id);

            if ($entry !== null) {
                $reachedAudiences[$entry->visibility->value] = true;
            }
        }

        foreach (self::VIEWERS[$role] as $audience) {
            self::assertArrayHasKey($audience->value, $reachedAudiences, sprintf('%s: no row of the audience "%s" in their Menu or page search', $role, $audience->value));
        }

        $login = $this->router()->generate('login');
        $signInPages = [$login, $this->router()->generate('register')];
        $failures = [];
        $guestAuthLinks = [];

        foreach ($targets as $url => $source) {
            if ($url === '' || !str_starts_with($url, '/')) {
                continue;
            }

            [$status, $finalPath] = $this->open($browser, $url);

            if ($status !== 200) {
                $failures[] = sprintf('%s → %d (%s)', $url, $status, $source);
            } elseif ($playerId !== null && $finalPath === $login) {
                $failures[] = sprintf('%s → the sign-in page (%s)', $url, $source);
            } elseif ($playerId === null && in_array($finalPath, $signInPages, true)) {
                if (in_array($source, self::GUEST_AUTH_LINKS, true)) {
                    $guestAuthLinks[] = $source;
                } else {
                    $failures[] = sprintf('%s → %s: a guest row that needs signing in is a locked row (%s)', $url, $finalPath, $source);
                }
            }
        }

        if ($playerId === null) {
            sort($guestAuthLinks);
            $expected = self::GUEST_AUTH_LINKS;
            sort($expected);
            self::assertSame($expected, $guestAuthLinks, 'Stale GUEST_AUTH_LINKS: the guest card\'s links no longer reach the sign-in or sign-up page');
        }

        self::assertSame([], $failures, sprintf("%s: Menu rows and page-search entries that do not open:\n%s", $role, implode("\n", $failures)));
    }

    /**
     * NavigationViewer::signIn(), and the karma holder: PLAYER with karma (XP is launched in the tests)
     */
    private function signIn(KernelBrowser $browser, string $viewer): null|string
    {
        if ($viewer !== self::KARMA_HOLDER) {
            return NavigationViewer::signIn($browser, $viewer);
        }

        $playerId = NavigationViewer::signIn($browser, NavigationViewer::PLAYER);
        self::assertIsString($playerId);
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE player SET karma = 7 WHERE id = :id', ['id' => $playerId]);

        return $playerId;
    }

    /**
     * GET the URL as the signed-in browser, following redirects (nav_go, a guest's way to sign in, legacy URLs).
     *
     * @return array{int, string} the final status and path
     */
    private function open(KernelBrowser $browser, string $url): array
    {
        $browser->followRedirects(false);
        $browser->request('GET', $url);

        for ($hop = 0; $hop < 5 && $browser->getResponse()->isRedirection(); $hop++) {
            $browser->followRedirect();
        }

        $path = (string) parse_url($browser->getRequest()->getUri(), PHP_URL_PATH);

        return [$browser->getResponse()->getStatusCode(), $path];
    }

    /**
     * The registry ids of the viewer's Menu as the page renders it: each row's anchor (menu-row-<id>) and each link's
     * data-nav item (the Legal row's three links).
     *
     * @return array<string, true>
     */
    private function menuIds(Crawler $crawler): array
    {
        $menu = $crawler->filter(self::MENU);
        self::assertCount(1, $menu);
        $ids = [];

        foreach ($menu->filter('li[id^="menu-row-"]') as $row) {
            assert($row instanceof \DOMElement);
            $ids[substr($row->getAttribute('id'), strlen('menu-row-'))] = true;
        }

        foreach ($menu->filter('[data-nav]') as $element) {
            assert($element instanceof \DOMElement);
            $parts = explode('/', $element->getAttribute('data-nav'));
            $ids[(string) end($parts)] = true;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function audience(Crawler $crawler): array
    {
        return explode(' ', trim((string) $crawler->filter('body')->attr('data-viewer-audience')));
    }

    /**
     * @param list<string> $audience
     * @return array<string, true>
     */
    private function publicSearch(KernelBrowser $browser, array $audience): array
    {
        return array_fill_keys(array_map(static fn (array $item): string => $item['id'], $this->publicSearchEntries($browser, $audience)), true);
    }

    /**
     * @return array<string, true>
     */
    private function privateSearch(Crawler $crawler): array
    {
        return array_fill_keys(array_map(static fn (array $item): string => $item['id'], $this->privateSearchEntries($crawler)), true);
    }

    /**
     * The page search's public list as the viewer gets it: nav-search.json filtered by the page's audiences
     * (assets/navigation/nav_search.js does the same in the browser).
     *
     * @param list<string> $audience
     * @return list<array{id: string, u: string, x: null|string}>
     */
    private function publicSearchEntries(KernelBrowser $browser, array $audience): array
    {
        $browser->request('GET', '/en/nav-search.json');
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['entries'] ?? null);

        return $this->entries(array_filter($list['entries'], static fn (mixed $item): bool => is_array($item) && in_array($item['a'] ?? null, $audience, true)));
    }

    /**
     * The role entries rendered into the page for moderators, time verifiers and admins only (#nav-search-private).
     *
     * @return list<array{id: string, u: string, x: null|string}>
     */
    private function privateSearchEntries(Crawler $crawler): array
    {
        $script = $crawler->filter('#nav-search-private');

        if ($script->count() === 0) {
            return [];
        }

        $entries = json_decode($script->text(normalizeWhitespace: false), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($entries);

        return $this->entries($entries);
    }

    /**
     * The id, the link and the extra kind (help, language, logout) of each page-search entry.
     *
     * @param array<mixed> $items
     * @return list<array{id: string, u: string, x: null|string}>
     */
    private function entries(array $items): array
    {
        $entries = [];

        foreach ($items as $item) {
            self::assertIsArray($item);
            $id = $item['id'] ?? null;
            $url = $item['u'] ?? null;
            $extra = $item['x'] ?? null;
            self::assertIsString($id);
            self::assertIsString($url);
            self::assertTrue($extra === null || is_string($extra));
            $entries[] = ['id' => $id, 'u' => $url, 'x' => $extra];
        }

        return $entries;
    }

    /**
     * The routes the links inside the containers open (route => true); a link to the page itself in another language is
     * the language switch, not a destination.
     *
     * @return array<string, true>
     */
    private function linkedRoutes(Crawler $containers, string $pageRoute): array
    {
        $routes = [];

        foreach ($containers->filter('a[href]') as $link) {
            assert($link instanceof \DOMElement);
            $href = trim($link->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href) === 1) {
                continue;
            }

            $parts = parse_url($href);

            if (!is_array($parts) || (isset($parts['host']) && $parts['host'] !== 'localhost')) {
                continue;
            }

            try {
                $match = $this->router()->match(rawurldecode($parts['path'] ?? '/'));
            } catch (RoutingException) {
                continue;
            }

            $route = $match['_canonical_route'] ?? $match['_route'] ?? null;
            $locale = $match['_locale'] ?? 'en';

            if (!is_string($route) || ($route === $pageRoute && $locale !== 'en')) {
                continue;
            }

            $routes[$route] = true;
        }

        return $routes;
    }

    private function withoutFragment(string $url): string
    {
        $hash = strpos($url, '#');

        return $hash === false ? $url : substr($url, 0, $hash);
    }

    private function registry(): NavigationRegistry
    {
        return self::getContainer()->get(NavigationRegistry::class);
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get(RouterInterface::class);
    }
}
