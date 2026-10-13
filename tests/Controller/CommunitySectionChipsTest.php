<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Navigation\NavigationPlaceResolver;
use SpeedPuzzling\Web\Navigation\NavPlace;
use SpeedPuzzling\Web\Navigation\SectionChip;
use SpeedPuzzling\Web\Navigation\SectionChipsBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\RouterInterface;

/**
 * Community's section chips (docs/features/navigation README §4.9, D8; COM-02, decision 75): Leaderboards · Puzzlers ·
 * Events at the top of every page the resolver puts in one of them - every ladder, the players pages, Compare, the
 * events agenda and archive, organizations, series, editions, events and their round results - with the current
 * section marked ("page" on its own start page, "true" deeper in). The landing, others' player pages and team pages
 * have none; neither have the bar-free pages (BarFreePagesTest). Rendered once, in base.html.twig.
 */
final class CommunitySectionChipsTest extends WebTestCase
{
    private const array HREFS = [
        'chips/community/leaderboards' => '/en/ladder',
        'chips/community/puzzlers' => '/en/puzzlers',
        'chips/community/events' => '/en/events',
    ];

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3?: bool}>
     */
    public static function provideSectionPages(): iterable
    {
        yield 'ladder' => ['/en/ladder', 'leaderboards', 'page'];
        yield 'ladder solo 500' => ['/en/ladder/solo/500-pieces', 'leaderboards', 'true'];
        yield 'MSP rating' => ['/en/msp-rating', 'leaderboards', 'true'];
        yield 'achievements overview' => ['/en/achievements', 'leaderboards', 'true'];
        yield 'puzzlers' => ['/en/puzzlers', 'puzzlers', 'page'];
        yield 'puzzlers directory' => ['/en/puzzlers/all', 'puzzlers', 'true'];
        yield 'puzzlers of a country' => ['/en/players-from-country/cz', 'puzzlers', 'true'];
        // Signed in only (guests are sent to sign in)
        yield 'compare' => ['/en/compare', 'puzzlers', 'true', false];
        yield 'events' => ['/en/events', 'events', 'page'];
        yield 'events archive' => ['/en/events/archive/{lastYear}', 'events', 'true'];
        yield 'event detail' => ['/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG, 'events', 'true'];
        yield 'series' => ['/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, 'events', 'true'];
        yield 'edition' => ['/en/series/puzzle-meetup-prague/puzzle-meetup-1', 'events', 'true'];
        yield 'round results' => ['/en/events/results-cup/results/group-a', 'events', 'true'];
        yield 'organizations' => ['/en/organizations', 'events', 'true'];
        yield 'organization' => ['/en/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, 'events', 'true'];
        yield 'world championship' => ['/en/world-jigsaw-puzzle-championship', 'events', 'true'];
    }

    #[DataProvider('provideSectionPages')]
    public function testThePageHasTheThreeChips(string $url, string $section, string $current, bool $forGuests = true): void
    {
        $browser = self::createClient();

        foreach ($forGuests ? [null, PlayerFixture::PLAYER_WITH_STRIPE] : [PlayerFixture::PLAYER_WITH_STRIPE] as $playerId) {
            if ($playerId !== null) {
                TestingLogin::asPlayer($browser, $playerId);
            }

            $crawler = $this->get($browser, $url);
            $this->assertTheChips($crawler, $url, $section, $current);

            if ($playerId === null) {
                // Guest HTML stays shared-cacheable: the chips depend on the URL only
                self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), $url . ': ' . $browser->getResponse()->headers->get('Cache-Control'));
            }
        }
    }

    /**
     * Decision 75: the landing (the place itself), others' player pages and team pages belong to Community without a
     * section; the own profile and pages of other places have none either
     *
     * @return iterable<string, array{string, null|string}>
     */
    public static function providePagesWithoutChips(): iterable
    {
        yield 'community landing' => ['/en/community', null];
        yield "somebody else's profile" => ['/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE];
        yield "somebody else's results" => ['/en/player-results/' . PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE];
        yield "somebody else's library" => ['/en/puzzle-library/' . PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'own profile' => ['/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'team page' => ['/en/teams/{team}', null];
        yield 'hub' => ['/en/hub', PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'faq' => ['/en/faq', null];
    }

    #[DataProvider('providePagesWithoutChips')]
    public function testThePageHasNoChips(string $url, null|string $playerId): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $this->get($browser, $url);
        self::assertCount(0, $crawler->filter('.section-chips'), $url);
    }

    /**
     * "Every route the resolver maps to a Community section" by construction: the chips come from the resolver's section
     * and base.html.twig renders them for every page - this checks the resolver's every Community-section route gets
     * the three chips with its own section current, and that no template takes the block over
     */
    public function testEveryCommunitySectionRouteGetsTheChips(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $resolver = $container->get(NavigationPlaceResolver::class);
        $builder = $container->get(SectionChipsBuilder::class);
        $checked = [];

        foreach (array_keys($container->get(RouterInterface::class)->getRouteCollection()->all()) as $name) {
            $route = (string) preg_replace('/\.(cs|en|es|ja|fr|de)$/', '', $name);
            $location = $resolver->resolve($route, [], null);

            if ($location->place !== NavPlace::Community || $location->section === null || isset($checked[$route])) {
                continue;
            }

            $chips = $builder->build($location, null);
            self::assertNotNull($chips, $route);
            self::assertSame(['leaderboards', 'puzzlers', 'events'], array_map(static fn (SectionChip $chip): string => $chip->section, $chips->chips), $route);
            self::assertSame([$location->section], array_values(array_map(
                static fn (SectionChip $chip): string => $chip->section,
                array_filter($chips->chips, static fn (SectionChip $chip): bool => $chip->current !== null),
            )), $route);
            $checked[$route] = $location->section;
        }

        // Every section has its routes - and the ones the requirement names are among them
        $sections = array_values(array_unique(array_values($checked)));
        sort($sections);
        self::assertSame(['events', 'leaderboards', 'puzzlers'], $sections);

        foreach (['ladder', 'ladder_solo_500_pieces', 'ladder_country', 'msp_rating_ladder', 'level_leaderboard', 'players', 'players_directory', 'players_per_country', 'comparison', 'events', 'events_archive', 'organizations', 'organization_detail', 'competition_series_detail', 'edition_detail', 'event_detail', 'event_round_results'] as $route) {
            self::assertArrayHasKey($route, $checked, $route . ' is a Community section page');
        }

        $overrides = [];

        foreach (new Finder()->files()->in(__DIR__ . '/../../templates')->name('*.twig') as $template) {
            if ($template->getRelativePathname() !== 'base.html.twig' && preg_match('/block\s+section_chips\b/', $template->getContents()) === 1) {
                $overrides[] = $template->getRelativePathname();
            }
        }

        self::assertSame([], $overrides, 'Only base.html.twig renders the section chips');
    }

    private function get(KernelBrowser $browser, string $url): Crawler
    {
        if (str_contains($url, '{lastYear}')) {
            $url = str_replace('{lastYear}', (string) ((int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') - 1), $url);
        }

        if (str_contains($url, '{team}')) {
            $teamId = self::getContainer()->get(Connection::class)->fetchOne(
                'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
                ['id' => PuzzleSolvingTimeFixture::TIME_12],
            );
            self::assertIsString($teamId);
            $url = str_replace('{team}', $teamId, $url);
        }

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }

    private function assertTheChips(Crawler $crawler, string $url, string $section, string $current): void
    {
        $nav = $crawler->filter('main > nav.section-chips');
        self::assertCount(1, $nav, $url);
        self::assertSame('Community sections', $nav->attr('aria-label'), $url);
        self::assertNotNull($nav->attr('data-ptr-ignore'), $url);

        $chips = $nav->filter('a.section-chip');
        $hrefs = [];
        $currents = [];

        foreach ($chips as $chip) {
            assert($chip instanceof \DOMElement);
            $hrefs[$chip->getAttribute('data-nav')] = $chip->getAttribute('href');

            if ($chip->hasAttribute('aria-current')) {
                $currents[$chip->getAttribute('data-nav')] = $chip->getAttribute('aria-current');
            }

            self::assertTrue($chip->hasAttribute('data-press'), $url);
        }

        self::assertSame(self::HREFS, $hrefs, $url);
        self::assertSame(['chips/community/' . $section => $current], $currents, $url);
        // Labels are the registry's (the Menu rows' names)
        self::assertSame(['Leaderboards', 'Puzzlers', 'Events'], $chips->each(static fn (Crawler $chip): string => trim($chip->text())), $url);
    }
}
