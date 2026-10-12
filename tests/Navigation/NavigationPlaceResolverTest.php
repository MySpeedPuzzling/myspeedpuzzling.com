<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavigationPlaceMap;
use SpeedPuzzling\Web\Navigation\NavigationPlaceResolver;
use SpeedPuzzling\Web\Navigation\NavLocation;
use SpeedPuzzling\Web\Navigation\NavPlace;
use SpeedPuzzling\Web\Navigation\PlaceRule;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Twig\NavigationTwigExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * The place resolver (F-02): every route has a place or an explicit "none", and a player's pages belong to Profile
 * (your own lists to Puzzles) when they are the viewer's, to Community when they are somebody else's (README §3).
 */
final class NavigationPlaceResolverTest extends KernelTestCase
{
    private const string VIEWER = PlayerFixture::PLAYER_REGULAR;
    private const string OTHER = PlayerFixture::PLAYER_WITH_STRIPE;

    // Admin pages, the APIs, framework and sitemap routes have no chrome of the redesign (requirement F-02)
    private const string EXCLUDED = '/^(admin_|api_|internal_api_|_|sitemap)/';

    public function testEveryRouteIsClassifiedByExactlyOneRule(): void
    {
        $unclassified = [];
        $ambiguous = [];

        foreach ($this->routeNames() as $route) {
            if (preg_match(self::EXCLUDED, $route) === 1) {
                continue;
            }

            $notations = NavigationPlaceMap::notationsFor($route);

            if ($notations === []) {
                $unclassified[] = $route;
            } elseif (count($notations) > 1) {
                $ambiguous[] = $route . ' (' . implode(', ', $notations) . ')';
            }
        }

        self::assertSame([], $unclassified, 'Routes without a place - add each to NavigationPlaceMap (a place or "none")');
        self::assertSame([], $ambiguous, 'Routes matched by more than one rule of NavigationPlaceMap');
    }

    public function testTheMapNamesOnlyExistingRoutesAndValidRules(): void
    {
        $routes = $this->routeNames();

        foreach (NavigationPlaceMap::ROUTES as $route => $notation) {
            self::assertContains($route, $routes, sprintf('NavigationPlaceMap names "%s", which is not a route', $route));
            PlaceRule::parse($notation);
        }

        foreach (NavigationPlaceMap::PREFIXES as $prefix => $notation) {
            $matching = array_filter($routes, static fn (string $route): bool => str_starts_with($route, $prefix));
            self::assertNotSame([], $matching, sprintf('The prefix "%s" matches no route', $prefix));
            PlaceRule::parse($notation);
        }
    }

    public function testInvalidRulesAreRefused(): void
    {
        $refused = [];

        foreach (['nowhere', 'home/library', 'community/all', 'profile|community|home'] as $notation) {
            try {
                PlaceRule::parse($notation);
            } catch (\InvalidArgumentException) {
                $refused[] = $notation;
            }
        }

        self::assertSame(['nowhere', 'home/library', 'community/all', 'profile|community|home'], $refused);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, null|string, NavPlace, null|string}>
     */
    public static function provideOwnAndOtherPages(): iterable
    {
        $own = ['playerId' => self::VIEWER];
        $other = ['playerId' => self::OTHER];

        yield 'own profile' => ['player_profile', $own, self::VIEWER, NavPlace::Profile, null];
        yield "somebody else's profile" => ['player_profile', $other, self::VIEWER, NavPlace::Community, null];
        yield 'a profile seen by a guest' => ['player_profile', $own, null, NavPlace::Community, null];
        yield 'own profile, id in another case' => ['player_profile', ['playerId' => strtoupper(self::VIEWER)], self::VIEWER, NavPlace::Profile, null];
        yield 'own statistics' => ['player_statistics', $own, self::VIEWER, NavPlace::Profile, null];
        yield "somebody else's statistics" => ['player_statistics', $other, self::VIEWER, NavPlace::Community, null];
        yield 'own calendar' => ['activity_calendar', $own, self::VIEWER, NavPlace::Profile, null];
        yield "somebody else's achievements" => ['player_achievements', $other, self::VIEWER, NavPlace::Community, null];
        yield 'own library' => ['puzzle_library', $own, self::VIEWER, NavPlace::Puzzles, 'library'];
        yield "somebody else's library" => ['puzzle_library', $other, self::VIEWER, NavPlace::Community, null];

        foreach (['system_collection_detail', 'unsolved_puzzles_detail', 'solved_puzzles_detail', 'sell_swap_list_detail', 'lend_borrow_list_detail', 'wish_list_detail', 'sold_swapped_history', 'lend_borrow_history'] as $list) {
            yield 'own ' . $list => [$list, $own, self::VIEWER, NavPlace::Puzzles, 'library'];
            yield "somebody else's " . $list => [$list, $other, self::VIEWER, NavPlace::Community, null];
            yield $list . ' seen by a guest' => [$list, $own, null, NavPlace::Community, null];
        }

        yield 'team page' => ['puzzling_team_detail', ['teamId' => '018d0000-0000-0000-0000-000000000001'], self::VIEWER, NavPlace::Community, null];
        yield 'team page seen by a guest' => ['puzzling_team_detail', ['teamId' => '018d0000-0000-0000-0000-000000000001'], null, NavPlace::Community, null];
        yield 'puzzle page' => ['puzzle_detail', ['puzzleId' => '018d0000-0000-0000-0000-000000000002'], self::VIEWER, NavPlace::Puzzles, null];
        yield 'add page' => ['puzzle_add', [], self::VIEWER, NavPlace::Add, null];
        yield 'add page with a puzzle' => ['puzzle_add', ['puzzleId' => '018d0000-0000-0000-0000-000000000002'], self::VIEWER, NavPlace::Add, null];
        yield 'edit time (decision 40)' => ['edit_time', ['timeId' => '018d0000-0000-0000-0000-000000000003'], self::VIEWER, NavPlace::Add, null];
        yield 'stopwatch' => ['stopwatch', [], self::VIEWER, NavPlace::Add, null];
        yield 'hub' => ['hub', [], self::VIEWER, NavPlace::Home, null];
        yield 'homepage (guests)' => ['homepage', [], null, NavPlace::Home, null];
        yield 'notifications' => ['notifications', [], self::VIEWER, NavPlace::None, null];
        yield 'messages' => ['conversations_list', [], self::VIEWER, NavPlace::None, null];
        yield 'leaderboards' => ['ladder', [], null, NavPlace::Community, 'leaderboards'];

        foreach (['solo', 'pairs', 'groups'] as $category) {
            foreach (['500', '1000'] as $pieces) {
                yield 'ladder ' . $category . ' ' . $pieces => ['ladder_' . $category . '_' . $pieces . '_pieces', [], null, NavPlace::Community, 'leaderboards'];
                yield 'ladder ' . $category . ' ' . $pieces . ' country' => ['ladder_' . $category . '_' . $pieces . '_pieces_country', ['countryCode' => 'cz'], null, NavPlace::Community, 'leaderboards'];
            }
        }

        yield 'ladder per country' => ['ladder_country', ['countryCode' => 'cz'], null, NavPlace::Community, 'leaderboards'];
        yield 'MSP rating' => ['msp_rating_ladder', [], null, NavPlace::Community, 'leaderboards'];
        yield 'level board' => ['level_leaderboard', [], null, NavPlace::Community, 'leaderboards'];
        yield 'players' => ['players', [], null, NavPlace::Community, 'puzzlers'];
        yield 'all puzzlers' => ['players_directory', [], null, NavPlace::Community, 'puzzlers'];
        yield 'comparison' => ['comparison', [], self::VIEWER, NavPlace::Community, 'puzzlers'];
        yield 'events' => ['events', [], null, NavPlace::Community, 'events'];
        yield 'events archive' => ['events_archive', ['year' => '2025'], null, NavPlace::Community, 'events'];
        yield 'organization detail' => ['organization_detail', ['slug' => 'some-organization'], null, NavPlace::Community, 'events'];
        yield 'series detail' => ['competition_series_detail', ['slug' => 'some-series'], null, NavPlace::Community, 'events'];
        yield 'edition detail' => ['edition_detail', ['seriesSlug' => 'some-series', 'editionSlug' => 'some-edition'], null, NavPlace::Community, 'events'];
        yield 'event detail' => ['event_detail', ['slug' => 'some-event'], null, NavPlace::Community, 'events'];
        yield 'round results' => ['event_round_results', ['slug' => 'some-event', 'roundSlug' => 'final'], null, NavPlace::Community, 'events'];
        yield 'catalogue' => ['puzzles', [], null, NavPlace::Puzzles, 'all'];
        yield 'marketplace' => ['marketplace', [], null, NavPlace::Puzzles, 'marketplace'];
        yield 'what next' => ['puzzle_picker', [], self::VIEWER, NavPlace::Puzzles, 'next'];
        yield 'brand page' => ['brand_puzzles', ['slug' => 'some-brand'], null, NavPlace::Puzzles, null];
        yield 'own settings' => ['edit_profile', [], self::VIEWER, NavPlace::Profile, null];
        yield 'review results' => ['review_results', [], self::VIEWER, NavPlace::Profile, null];
        yield 'nav click beacon' => ['nav_click', [], null, NavPlace::None, null];
        yield 'faq' => ['faq', [], null, NavPlace::None, null];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('provideOwnAndOtherPages')]
    public function testPlaceOfOwnAndOtherPages(string $route, array $params, null|string $viewer, NavPlace $place, null|string $section): void
    {
        $location = $this->resolver()->resolve($route, $params, $viewer);

        self::assertSame($place, $location->place);
        self::assertSame($section, $location->section);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, null|string, null|string, bool}>
     */
    public static function provideMenuRows(): iterable
    {
        $own = ['playerId' => self::VIEWER];
        $other = ['playerId' => self::OTHER];

        yield 'own statistics' => ['player_statistics', $own, self::VIEWER, 'statistics', false];
        yield "somebody else's statistics highlight nothing" => ['player_statistics', $other, self::VIEWER, null, false];
        yield 'own profile = the You row of the column' => ['player_profile', $own, self::VIEWER, 'profile', true];
        yield "somebody else's profile" => ['player_profile', $other, self::VIEWER, null, false];
        yield 'own library' => ['puzzle_library', $own, self::VIEWER, 'library', true];
        yield 'own wishlist' => ['wish_list_detail', $own, self::VIEWER, 'wishlist', false];
        yield 'the catalogue = All puzzles, not the place row' => ['puzzles', [], null, 'all_puzzles', true];
        yield 'hub' => ['hub', [], self::VIEWER, 'home', true];
        yield 'notifications = the Inbox row' => ['notifications', [], self::VIEWER, 'inbox', false];
        yield 'a ladder variant' => ['ladder_solo_500_pieces', [], null, 'leaderboards', false];
        yield 'old first-try page' => ['first_try_conflicts', [], self::VIEWER, 'review_results', false];
        yield 'event detail' => ['event_detail', ['slug' => 'some-event'], null, 'events', false];
        yield 'puzzle page has no row' => ['puzzle_detail', ['puzzleId' => '018d0000-0000-0000-0000-000000000002'], null, null, false];
        yield 'add page' => ['puzzle_add', [], self::VIEWER, 'add', true];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('provideMenuRows')]
    public function testMenuRowAndStartPage(string $route, array $params, null|string $viewer, null|string $menuRowId, bool $isStartPage): void
    {
        $location = $this->resolver()->resolve($route, $params, $viewer);

        self::assertSame($menuRowId, $location->menuRowId);
        self::assertSame($isStartPage, $location->isStartPage);
    }

    public function testUnknownRouteHasNoPlace(): void
    {
        self::assertEquals(NavLocation::none(), $this->resolver()->resolve(null, [], null));
        self::assertEquals(NavLocation::none(), $this->resolver()->resolve('no_such_route', [], null));
    }

    public function testTheLocationIsResolvedOncePerRequestAndKeptOnTheRequest(): void
    {
        $request = Request::create('/en/hub');
        $request->attributes->set('_route', 'hub.en');
        $request->attributes->set('_canonical_route', 'hub');
        $request->attributes->set('_route_params', ['_locale' => 'en']);

        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);

        try {
            $extension = self::getContainer()->get(NavigationTwigExtension::class);
            $location = $extension->navLocation();

            self::assertSame(NavPlace::Home, $location->place);
            self::assertSame($location, $request->attributes->get(NavigationTwigExtension::REQUEST_ATTRIBUTE));
            self::assertSame($location, $extension->navLocation());
        } finally {
            $requestStack->pop();
        }
    }

    private function resolver(): NavigationPlaceResolver
    {
        return self::getContainer()->get(NavigationPlaceResolver::class);
    }

    /**
     * Canonical route names (localized routes once, without their locale suffix).
     *
     * @return list<string>
     */
    private function routeNames(): array
    {
        $names = [];

        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            $canonical = $route->getDefault('_canonical_route');
            $names[is_string($canonical) ? $canonical : $name] = true;
        }

        return array_keys($names);
    }
}
