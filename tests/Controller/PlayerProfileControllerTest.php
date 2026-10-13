<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PlayerProfileControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testProfileFeedOnlyCountsItsLabelsUpWithoutRefreshing(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        self::assertCount(0, $crawler->filter('[data-controller~="live-refresh"]'));
        self::assertCount(0, $crawler->filter('[data-poll]'));
        self::assertCount(1, $crawler->filter('[data-controller~="relative-time"]'));
        self::assertGreaterThan(0, $crawler->filter('[data-controller~="relative-time"] tr[id^="activity-player-"] time[data-relative-time]')->count());

        // The Overview's latest results: 8 (PlayerOverview::LATEST_RESULTS, PROF-03 - was 20, 3 shown), all of them shown
        $feed = $crawler->filter('[data-testid="latest-results"] [data-live-name-value="RecentActivity"]');
        self::assertCount(1, $feed);
        $props = json_decode((string) $feed->attr('data-live-props-value'), true);
        self::assertIsArray($props);
        self::assertSame(8, $props['limit']);
        self::assertSame(0, $props['showLimit']);
        self::assertLessThanOrEqual(8, $feed->filter('tr[id^="activity-player-"]')->count());
        self::assertCount(0, $feed->filter('.ra-show-more, tr.ra-extra'));
    }

    /**
     * PROF-01 (README §4.7, D9): your own profile is the page others see plus Edit profile and Share in the header - no
     * owner panel, no tiles, no "See it as others do"; its first content is the header, the tabs and the Overview
     */
    public function testOwnProfileIsThePageOthersSeePlusTheOwnerButtons(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
        $this->assertResponseIsSuccessful();

        $actions = $crawler->filter('.player-head .player-head-actions');
        // The header's own buttons (the ⋯ menu repeats both)
        self::assertCount(1, $actions->filter('button.btn-primary[data-controller="share-link"]'));
        self::assertCount(1, $actions->filter('a.btn[href="/en/edit-profile"]'));
        self::assertNoOwnerPanel($crawler, (string) $browser->getResponse()->getContent());

        // Header with its tabs, then the Overview: bests, numbers, latest results
        $html = (string) $browser->getResponse()->getContent();
        $order = array_map(static fn (string $needle): int|false => strpos($html, $needle), [
            'class="player-head-tabs',
            'data-testid="personal-bests"',
            'data-testid="overview-numbers"',
            'data-testid="latest-results"',
        ]);
        self::assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'tabs < bests < numbers < latest results');

        // The same page for someone else - without the owner's buttons
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
        $actions = $crawler->filter('.player-head .player-head-actions');
        // (its ⋯ menu shares the profile too - for everybody)
        self::assertCount(0, $actions->filter('button.btn-primary[data-controller="share-link"]'));
        self::assertCount(0, $crawler->filter('main a[href="/en/edit-profile"]'));
        self::assertNoOwnerPanel($crawler, (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-testid="personal-bests"]'));
    }

    /**
     * PROF-01: the owner's own cards - Getting started and the "Review your results" banner - stay, below the bests
     */
    public function testTheOwnersCardsComeBelowThePersonalBests(): void
    {
        $browser = self::createClient();

        // A newcomer (PLAYER_REGULAR registered days ago): the Getting started card
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertCount(1, $crawler->filter('.getting-started'));
        $html = (string) $browser->getResponse()->getContent();
        self::assertNotFalse(strpos($html, 'data-testid="personal-bests"'));
        self::assertLessThan(strpos($html, 'class="getting-started'), strpos($html, 'data-testid="personal-bests"'));

        // A player with results to review: the banner
        TestingLogin::asPlayer($browser, DuplicateResultsFixture::PLAYER_TWINS);
        $crawler = $browser->request('GET', '/en/player-profile/' . DuplicateResultsFixture::PLAYER_TWINS);
        self::assertCount(1, $crawler->filter('[data-testid="review-results-banner"]'));
        $html = (string) $browser->getResponse()->getContent();
        self::assertNotFalse(strpos($html, 'data-testid="personal-bests"'));
        self::assertLessThan(strpos($html, 'data-testid="review-results-banner"'), strpos($html, 'data-testid="personal-bests"'));
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testNewcomerSeesGettingStartedOnTheirOwnProfileOnly(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertCount(1, $crawler->filter('.getting-started'));
        self::assertCount(5, $crawler->filter('.getting-started-step'));

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertCount(0, $crawler->filter('.getting-started'));
    }

    public function testAnonymousVisitorSeesNoGettingStartedCard(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        self::assertCount(0, $crawler->filter('.getting-started'));
    }

    private static function assertNoOwnerPanel(Crawler $crawler, string $html): void
    {
        self::assertCount(0, $crawler->filter('[data-testid="owner-panel"], .owner-panel, .profile-tiles, .profile-owner-tiles'));
        self::assertStringNotContainsString('See it as others do', $html);
    }

    public function testUnknownPlayerReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-profile/' . Uuid::uuid7()->toString());

        $this->assertResponseStatusCodeSame(404);
    }
}
