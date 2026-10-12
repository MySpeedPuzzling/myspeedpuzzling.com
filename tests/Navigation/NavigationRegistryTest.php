<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\MenuGroup;
use SpeedPuzzling\Web\Navigation\MenuSurface;
use SpeedPuzzling\Web\Navigation\NavEntry;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Navigation\NavPlace;
use SpeedPuzzling\Web\Navigation\NavVisibility;
use SpeedPuzzling\Web\Navigation\RowKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The guard of the navigation registry (F-01, README §7 / D19):
 * - structural: ids, routes, placements, keys and labels are well-formed and point at things that exist;
 * - rendered: every link of the chrome, as each role sees it, opens a route the registry knows - a new link without
 *   its entry fails here;
 * - the frozen list of routes today's chrome links (delivery/baseline-chrome-routes.json, P0-02) is all registered.
 */
final class NavigationRegistryTest extends WebTestCase
{
    private const string BASELINE_ROUTES = __DIR__ . '/../../docs/features/navigation/delivery/baseline-chrome-routes.json';

    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    // README §4.12: never "rival", never "suspicious" - not even as a hidden synonym
    private const string FORBIDDEN_SYNONYMS = '/rival|suspicious|verdächtig|podezřel|sospechos|suspect|不審/iu';

    // Chrome containers of today's and the new navigation (header, footer, the Menu, its shell, the bars, the chips,
    // the community landing, the Help sheet). A bare header/footer counts only outside <main>.
    private const array CHROME_XPATHS = [
        '//header[not(ancestor::main)]',
        '//footer[not(ancestor::main)]',
        '//*[@id="site-menu"]',
        '//*[@id="menu-layer"]',
        '//*[@id="bottom-nav"]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " site-header ")]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " legacy-chrome ")]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " section-chips ")]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " community-landing ")]',
        '//*[@data-help-sheet]',
    ];

    public function testEntryIdsAreUniqueAndStable(): void
    {
        $ids = array_map(static fn (NavEntry $entry): string => $entry->id, $this->registry()->all());

        self::assertSame(array_values(array_unique($ids)), $ids, 'Duplicate registry ids: ' . implode(', ', array_diff_assoc($ids, array_unique($ids))));

        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $id);
        }
    }

    public function testEveryRouteExists(): void
    {
        foreach ($this->registry()->all() as $entry) {
            $routes = [...$entry->target->routes(), ...$entry->activeOn];

            if (!$entry->target->isCurrentPage()) {
                self::assertNotNull($entry->target->route, $entry->id);
            }

            foreach ($routes as $route) {
                self::assertTrue($this->routeExists($route), sprintf('Entry "%s" names the route "%s", which does not exist (register a route only when it exists)', $entry->id, $route));
            }

            foreach (array_keys($entry->target->dynamicParams) as $param) {
                $route = $this->router()->getRouteCollection()->get((string) $entry->target->route) ?? $this->router()->getRouteCollection()->get($entry->target->route . '.en');
                self::assertNotNull($route);
                self::assertContains($param, $route->compile()->getPathVariables(), sprintf('Entry "%s" fills "%s", which is not a parameter of its route', $entry->id, $param));
            }
        }
    }

    public function testEveryEntryHasAPlacementOnBothSurfaces(): void
    {
        $registry = $this->registry();
        $positions = [];

        foreach ($registry->all() as $entry) {
            foreach (MenuSurface::cases() as $surface) {
                $placement = $entry->placement($surface);
                $kinds = (int) $placement->isRow() + (int) $placement->isSearchOnly() + (int) $placement->isNotInMenu();
                self::assertSame(1, $kinds, sprintf('Entry "%s" on %s: exactly one of row / search-only / not in Menu', $entry->id, $surface->value));

                if ($placement->isNotInMenu()) {
                    self::assertNotSame('', trim((string) $placement->reason), sprintf('Entry "%s": "not in Menu" needs a reason', $entry->id));
                }

                if ($placement->isSearchOnly()) {
                    $parent = $registry->byId((string) $placement->parent);
                    self::assertNotNull($parent, sprintf('Search-only entry "%s": unknown parent "%s"', $entry->id, $placement->parent));
                    self::assertTrue($parent->isMenuRow(), sprintf('Search-only entry "%s": its parent "%s" is not a Menu row', $entry->id, $placement->parent));
                }

                if ($placement->isRow()) {
                    self::assertNotNull($placement->group?->order($surface), sprintf('Entry "%s": group "%s" does not exist on %s', $entry->id, $placement->group?->value, $surface->value));

                    // The Legal entries share one position: one row of three links
                    if ($entry->kind !== RowKind::Legal) {
                        $key = $surface->value . '/' . $placement->group->value . '/' . $placement->position;
                        self::assertArrayNotHasKey($key, $positions, sprintf('Entries "%s" and "%s" share %s', $entry->id, $positions[$key] ?? '', $key));
                        $positions[$key] = $entry->id;
                    }
                }
            }

            self::assertSame($entry->phone->isSearchOnly(), $entry->dock->isSearchOnly(), sprintf('Entry "%s": search-only on both surfaces or on none', $entry->id));
            self::assertNotSame('', trim($entry->reason), $entry->id);
            self::assertLessThanOrEqual(2, count($entry->secondary), sprintf('Entry "%s": at most 2 other entry points (README §7)', $entry->id));
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry->since, $entry->id);
            self::assertMatchesRegularExpression('/^bi-[a-z0-9-]+$/', $entry->icon, $entry->id);

            if ($entry->kind === RowKind::Place) {
                self::assertFalse($entry->phone->isRow(), sprintf('Place row "%s": the bars carry the places on phones', $entry->id));
            }

            if ($entry->count !== null) {
                self::assertTrue($entry->isMenuRow(), sprintf('Entry "%s" has a count but no row', $entry->id));
            }

            if ($entry->section !== null) {
                self::assertContains($entry->section, $entry->place->sections(), sprintf('Entry "%s": "%s" is not a section of %s', $entry->id, $entry->section, $entry->place->value));
            }
        }
    }

    public function testEveryKeyExistsAndEnglishOnlyIsForRoleRows(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $catalogue = $translator->getCatalogue('en');

        foreach ($this->registry()->all() as $entry) {
            if ($entry->englishOnly) {
                self::assertTrue($entry->visibility->isRole(), sprintf('Entry "%s": englishOnly is only for moderator and admin rows', $entry->id));
                self::assertNotSame('', trim($entry->label), $entry->id);

                if (!$catalogue->has($entry->label)) {
                    // Today's admin key menu hard-codes English labels; until nav_admin.* keys exist the label is the text
                    self::assertDoesNotMatchRegularExpression('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $entry->label, sprintf('Entry "%s": "%s" looks like a missing key', $entry->id, $entry->label));
                }
            } else {
                self::assertTrue($catalogue->has($entry->label), sprintf('Entry "%s": label key "%s" is not in the en catalogue', $entry->id, $entry->label));
            }

            foreach (array_filter([$entry->description, $entry->synonyms, ...$entry->legacyLabels]) as $key) {
                self::assertTrue($catalogue->has($key), sprintf('Entry "%s": key "%s" is not in the en catalogue', $entry->id, $key));
            }

            foreach (array_filter([$entry->synonyms, ...$entry->legacyLabels]) as $key) {
                foreach (self::LOCALES as $locale) {
                    $value = $translator->trans($key, [], null, $locale);
                    self::assertDoesNotMatchRegularExpression(self::FORBIDDEN_SYNONYMS, $value, sprintf('Entry "%s": synonym "%s" (%s) must never say that', $entry->id, $key, $locale));
                }
            }
        }
    }

    public function testGuestsHubLinkStaysFindable(): void
    {
        // R11: guests' Home is the homepage (D5); the old guest hamburger link to the Hub becomes a search-only entry
        $hubGuest = $this->registry()->byId('hub_guest');

        self::assertNotNull($hubGuest);
        self::assertTrue($hubGuest->isSearchOnly());
        self::assertSame(NavVisibility::GuestOnly, $hubGuest->visibility);
        self::assertSame('hub', $hubGuest->target->route);
        self::assertSame(NavPlace::Home, $hubGuest->place);
    }

    public function testMenuPathDescribesWhereAnEntryLives(): void
    {
        $registry = $this->registry();

        // Group headings fall back to their English names until MENU-01 adds the nav.group.* keys
        self::assertSame('☰ › Account › Export data', $registry->menuPath('your_data', 'en'));
        self::assertSame('☰ › Account › Edit profile › Password', $registry->menuPath('change_password', 'en'));
        self::assertSame('☰ › Notifications', $registry->menuPath('inbox', 'en'));
        self::assertSame('☰ › Home', $registry->menuPath('home', 'en'));
        self::assertSame('☰ › Your roles › Time verification', $registry->menuPath('mod_time_verification', 'cs'));
        self::assertNull($registry->menuPath('puzzle_page', 'en'));
        self::assertNull($registry->menuPath('no_such_entry', 'en'));
    }

    public function testEveryRouteOfTodaysChromeIsRegistered(): void
    {
        $baseline = json_decode((string) file_get_contents(self::BASELINE_ROUTES), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baseline);
        self::assertIsArray($baseline['routes'] ?? null);
        $registry = $this->registry();
        $checked = 0;

        foreach ($baseline['routes'] as $role => $surfaces) {
            self::assertIsArray($surfaces);

            foreach ($surfaces as $surface => $routes) {
                self::assertIsArray($routes);

                foreach ($routes as $route) {
                    self::assertIsString($route);
                    self::assertNotSame([], $registry->byTargetRoute($route), sprintf('Today\'s chrome links "%s" (%s, %s) but no registry entry targets it', $route, $role, $surface));
                    $checked++;
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideChromePages(): iterable
    {
        $roles = [
            NavigationViewer::GUEST,
            NavigationViewer::PLAYER,
            NavigationViewer::MEMBER,
            NavigationViewer::ORGANISER,
            NavigationViewer::MODERATOR,
            NavigationViewer::ADMIN,
        ];

        foreach ($roles as $role) {
            foreach (['/en/faq', '/en/hub'] as $page) {
                yield $role . ' ' . $page => [$role, $page];
            }
        }
    }

    #[DataProvider('provideChromePages')]
    public function testEveryChromeLinkIsRegistered(string $role, string $page): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        $crawler = $browser->request('GET', $page);
        self::assertResponseIsSuccessful();

        $links = $this->chromeLinks($crawler);
        $pageRoute = $this->canonicalRoute($this->router()->match($page));

        // The Help sheet frame, once its route exists (HELP-01a)
        if ($this->routeExists('help')) {
            $helpCrawler = $browser->request('GET', $this->router()->generate('help', ['_locale' => 'en', 'url' => $page]), server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
            self::assertResponseIsSuccessful();
            $links = [...$links, ...$this->hrefs($helpCrawler)];
        }

        self::assertNotSame([], $links, 'No chrome link found - did the chrome containers change? Update CHROME_XPATHS');

        $registry = $this->registry();
        $missing = [];

        foreach ($links as $href) {
            $route = $this->routeOfHref($href, $browser);

            if ($route === null) {
                continue;
            }

            [$name, $locale] = $route;

            // A link to the current page in another language is the language switch, not a destination
            if ($name === $pageRoute && $locale !== 'en') {
                continue;
            }

            if ($registry->byTargetRoute($name) === []) {
                $missing[$name] = sprintf('%s (href %s)', $name, $href);
            }
        }

        self::assertSame([], array_values($missing), sprintf("%s on %s: chrome links to routes no NavigationRegistry entry targets:\n%s", $role, $page, implode("\n", $missing)));
    }

    public function testFragmentTargetsExistOnTheirPage(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::MEMBER);

        foreach ($this->registry()->all() as $entry) {
            $fragment = $entry->target->fragment;

            if ($fragment === null || $entry->target->route === null) {
                continue;
            }

            $crawler = $browser->request('GET', $this->router()->generate($entry->target->route, ['_locale' => 'en', ...$entry->target->params]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('#' . $fragment), sprintf('Entry "%s": #%s is not on %s', $entry->id, $fragment, $entry->target->route));
        }
    }

    public function testRowsAreOrderedByGroupThenPosition(): void
    {
        foreach (MenuSurface::cases() as $surface) {
            $previous = null;

            foreach ($this->registry()->rows($surface) as $entry) {
                $placement = $entry->placement($surface);
                $current = [$placement->group?->order($surface), $placement->position];

                if ($previous !== null) {
                    self::assertGreaterThanOrEqual($previous, $current, $entry->id);
                }

                $previous = $current;
            }
        }

        $phoneGroups = array_values(array_unique(array_map(static fn (NavEntry $entry): string => (string) $entry->phone->group?->value, $this->registry()->rows(MenuSurface::Phone))));
        self::assertSame(MenuGroup::You->value, $phoneGroups[0]);
        self::assertSame(MenuGroup::LogOut->value, $phoneGroups[array_key_last($phoneGroups)]);
    }

    private function registry(): NavigationRegistry
    {
        return self::getContainer()->get(NavigationRegistry::class);
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get(RouterInterface::class);
    }

    private function routeExists(string $route): bool
    {
        $collection = $this->router()->getRouteCollection();

        return $collection->get($route) !== null || $collection->get($route . '.en') !== null;
    }

    /**
     * @return list<string>
     */
    private function chromeLinks(Crawler $crawler): array
    {
        $links = [];

        foreach (self::CHROME_XPATHS as $xpath) {
            $links = [...$links, ...$this->hrefs($crawler->filterXPath($xpath))];
        }

        return $links;
    }

    /**
     * @return list<string>
     */
    private function hrefs(Crawler $containers): array
    {
        $hrefs = [];

        foreach ($containers->filterXPath('descendant-or-self::a[@href]') as $node) {
            if ($node instanceof \DOMElement) {
                $hrefs[] = $node->getAttribute('href');
            }
        }

        return $hrefs;
    }

    /**
     * The route (canonical name, locale) an internal href opens, or null for links that are no destination
     * (empty, "#", mailto:, external hosts).
     *
     * @return null|array{string, string}
     */
    private function routeOfHref(string $href, KernelBrowser $browser): null|array
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href) === 1) {
            return null;
        }

        $parts = parse_url($href);
        self::assertIsArray($parts, 'Unparsable href ' . $href);

        if (isset($parts['host']) && $parts['host'] !== $browser->getServerParameter('HTTP_HOST', 'localhost') && $parts['host'] !== 'localhost') {
            return null;
        }

        $path = rawurldecode($parts['path'] ?? '/');

        try {
            $match = $this->router()->match($path);
        } catch (RoutingException $e) {
            self::fail(sprintf('The chrome links %s, which matches no route: %s', $href, $e->getMessage()));
        }

        $locale = $match['_locale'] ?? 'en';

        return [$this->canonicalRoute($match), is_string($locale) ? $locale : 'en'];
    }

    /**
     * @param array<mixed> $match
     */
    private function canonicalRoute(array $match): string
    {
        $route = $match['_canonical_route'] ?? $match['_route'] ?? null;
        self::assertIsString($route);

        return $route;
    }
}
