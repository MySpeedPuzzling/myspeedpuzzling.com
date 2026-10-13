<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavEntry;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * No orphaned pages (docs/features/navigation NAV-12, README D19): every route the old chrome linked - the topbar band,
 * the admin key, the account dropdown, the toolbar, the hamburger and the footer, frozen per role before the redesign in
 * delivery/baseline-chrome-routes.json (P0-02) - is still reachable for that role:
 * - linked from a navigation surface: the top bar with the desktop account menu, the tab bar / rail, the Menu, the
 *   footer, the section chips, the community landing or the Help sheet; or
 * - a search-only entry of the registry the role finds in the page search ("Pages & settings": nav-search.json for the
 *   page's data-viewer-audience, the role entries rendered into the page as #nav-search-private); or
 * - listed in NavigationRegistry as "not in Menu" with its reason (object pages, form endpoints, SEO hubs, …); or
 * - one of the few account pages the Menu now shows only to the viewers who can use them (rows hidden, never locked -
 *   README §4.12), still linked for everybody else from the owner's ⋯ menu in their player header (OWNER_MENU_ONLY).
 *
 * Removing the Menu row and the footer link of a destination nothing else links makes this fail. Runs again at every
 * later phase gate (implementation plan §2).
 */
final class NavigationOrphanTest extends WebTestCase
{
    private const string BASELINE_ROUTES = __DIR__ . '/../docs/features/navigation/delivery/baseline-chrome-routes.json';

    // The navigation surfaces (implementation plan NAV-12); the account menu is part of the top bar
    private const string SURFACES = '.site-header, #bottom-nav, #site-menu, footer.site-footer, .section-chips, .community-landing, [data-help-sheet]';

    // The pages the surfaces are read on: the chrome of an ordinary page, the Hub, the community landing and one page per
    // chip set (Community: leaderboards, COM-02; Puzzles: the catalogue, PUZ-01) - their chips count once they exist
    private const array PAGES = ['/en/faq', '/en/hub', '/en/community', '/en/ladder', '/en/puzzle'];

    // Routes the old account dropdown linked for every signed-in player whose Menu row README §4.12 gives a narrower
    // audience: for the others they stay linked from the ⋯ menu of their own player header
    // (templates/player/_header_actions_menu.html.twig) - checked on the own profile, never assumed. route => why
    private const array OWNER_MENU_ONLY = [
        'affiliate_dashboard' => 'Referral program: a Menu row for members and players in the programme only (README §4.12 Account)',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function roles(): iterable
    {
        foreach ([NavigationViewer::GUEST, NavigationViewer::PLAYER, NavigationViewer::MEMBER, NavigationViewer::ORGANISER, NavigationViewer::MODERATOR, NavigationViewer::ADMIN] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('roles')]
    public function testEveryRouteOfTheOldChromeIsStillReachable(string $role): void
    {
        $oldChrome = $this->baselineRoutes($role);
        self::assertNotSame([], $oldChrome, 'No frozen routes for ' . $role);

        $browser = self::createClient();
        $playerId = NavigationViewer::signIn($browser, $role);

        $linked = [];
        $audience = null;
        $privateSearch = [];

        foreach (self::PAGES as $page) {
            $crawler = $browser->request('GET', $page);
            self::assertResponseIsSuccessful($page);

            $linked += $this->linkedRoutes($crawler, $this->routeOf($page));

            if ($audience === null) {
                $audience = explode(' ', trim((string) $crawler->filter('body')->attr('data-viewer-audience')));
                $privateSearch = $this->privateSearchIds($crawler);
            }
        }

        // The Help sheet, opened on the FAQ
        $router = $this->router();
        $crawler = $browser->request('GET', $router->generate('help', ['_locale' => 'en', 'url' => '/en/faq']), server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful('help sheet');
        $linked += $this->linkedRoutes($crawler, 'faq');

        self::assertGreaterThan(20, count($linked), 'Hardly any navigation link found - did the surfaces change? Update SURFACES');

        $searchIds = [...$this->publicSearchIds($browser, $audience), ...$privateSearch];
        $registry = self::getContainer()->get(NavigationRegistry::class);
        $searchOnly = [];
        $notInMenu = [];

        foreach ($registry->all() as $entry) {
            foreach ($entry->target->routes() as $route) {
                if ($entry->isSearchOnly() && in_array($entry->id, $searchIds, true)) {
                    $searchOnly[$route] = $entry->id;
                }

                if ($entry->isNotInMenu()) {
                    $notInMenu[$route] = $entry->id;
                }
            }
        }

        $orphans = [];
        $ownerMenu = null;

        foreach ($oldChrome as $route => $surfaces) {
            if (isset($linked[$route]) || isset($searchOnly[$route]) || isset($notInMenu[$route])) {
                continue;
            }

            if (array_key_exists($route, self::OWNER_MENU_ONLY) && $playerId !== null) {
                // The registry gives its row a narrower audience than this viewer, and the owner's ⋯ menu links it
                self::assertNotSame([], array_filter($registry->byTargetRoute($route), static fn (NavEntry $entry): bool => $entry->isMenuRow()), $route . ': ' . self::OWNER_MENU_ONLY[$route]);
                $ownerMenu ??= $this->ownerMenuRoutes($browser, $playerId);

                if (isset($ownerMenu[$route])) {
                    continue;
                }
            }

            $orphans[] = sprintf('%s (the old %s)', $route, implode(', ', $surfaces));
        }

        self::assertSame([], $orphans, sprintf("%s: routes the old chrome linked that no navigation surface links, no search-only entry finds and no \"not in Menu\" entry explains:\n%s", $role, implode("\n", $orphans)));
    }

    /**
     * @return array<string, list<string>> route => the old surfaces that linked it
     */
    private function baselineRoutes(string $role): array
    {
        $baseline = json_decode((string) file_get_contents(self::BASELINE_ROUTES), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baseline);
        $byRole = $baseline['routes'] ?? null;
        self::assertIsArray($byRole);
        $surfaces = $byRole[$role] ?? null;
        self::assertIsArray($surfaces, 'No routes for ' . $role);
        $routes = [];

        foreach ($surfaces as $surface => $surfaceRoutes) {
            self::assertIsArray($surfaceRoutes);

            foreach ($surfaceRoutes as $route) {
                self::assertIsString($route);
                $routes[$route][] = (string) $surface;
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * The routes the navigation surfaces of a page link (route => true). A link to the page itself in another language is
     * the language switch, not a destination.
     *
     * @return array<string, true>
     */
    private function linkedRoutes(Crawler $crawler, string $pageRoute): array
    {
        $routes = [];

        foreach ($crawler->filter(self::SURFACES)->filter('a[href]') as $link) {
            assert($link instanceof \DOMElement);
            $match = $this->match($link->getAttribute('href'));

            if ($match === null || ($match[0] === $pageRoute && $match[1] !== 'en')) {
                continue;
            }

            $routes[$match[0]] = true;
        }

        return $routes;
    }

    /**
     * The routes the ⋯ menu of the viewer's own player header links (route => true).
     *
     * @return array<string, true>
     */
    private function ownerMenuRoutes(KernelBrowser $browser, string $playerId): array
    {
        $crawler = $browser->request('GET', $this->router()->generate('player_profile', ['_locale' => 'en', 'playerId' => $playerId]));
        self::assertResponseIsSuccessful('own profile');
        $routes = [];

        foreach ($crawler->filter('.more-menu .dropdown-menu a[href]') as $link) {
            assert($link instanceof \DOMElement);
            $match = $this->match($link->getAttribute('href'));

            if ($match !== null) {
                $routes[$match[0]] = true;
            }
        }

        self::assertNotSame([], $routes, 'No ⋯ menu on the own player header - did it move?');

        return $routes;
    }

    /**
     * The registry ids of the page search's public list the viewer gets: nav-search.json filtered by the page's audiences
     * (assets/navigation/nav_search.js does the same in the browser).
     *
     * @param list<string> $audience
     * @return list<string>
     */
    private function publicSearchIds(KernelBrowser $browser, array $audience): array
    {
        $browser->request('GET', '/en/nav-search.json');
        self::assertResponseIsSuccessful('nav-search.json');
        $list = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['entries'] ?? null);
        $ids = [];

        foreach ($list['entries'] as $item) {
            self::assertIsArray($item);

            if (in_array($item['a'] ?? null, $audience, true) && is_string($item['id'] ?? null)) {
                $ids[] = $item['id'];
            }
        }

        self::assertNotSame([], $ids, 'The page search lists nothing for this viewer');

        return $ids;
    }

    /**
     * The role entries rendered into the page for moderators, time verifiers and admins only (#nav-search-private).
     *
     * @return list<string>
     */
    private function privateSearchIds(Crawler $crawler): array
    {
        $script = $crawler->filter('#nav-search-private');

        if ($script->count() === 0) {
            return [];
        }

        $entries = json_decode($script->text(normalizeWhitespace: false), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($entries);

        return array_values(array_filter(array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['id'] ?? null) : null, $entries), 'is_string'));
    }

    private function routeOf(string $path): string
    {
        $match = $this->match($path);
        self::assertNotNull($match, $path);

        return $match[0];
    }

    /**
     * The canonical route and locale an internal href opens; null for links that are no destination (empty, "#",
     * mailto:, external hosts, nothing matched - NavigationRegistryTest fails on those).
     *
     * @return null|array{string, string}
     */
    private function match(string $href): null|array
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href) === 1) {
            return null;
        }

        $parts = parse_url($href);

        if (!is_array($parts) || (isset($parts['host']) && $parts['host'] !== 'localhost')) {
            return null;
        }

        try {
            $match = $this->router()->match(rawurldecode($parts['path'] ?? '/'));
        } catch (RoutingException) {
            return null;
        }

        $route = $match['_canonical_route'] ?? $match['_route'] ?? null;
        $locale = $match['_locale'] ?? 'en';

        return is_string($route) ? [$route, is_string($locale) ? $locale : 'en'] : null;
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get(RouterInterface::class);
    }
}
