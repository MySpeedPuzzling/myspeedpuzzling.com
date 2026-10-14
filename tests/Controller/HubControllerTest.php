<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HubControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/hub');

        $this->assertResponseIsSuccessful();
    }

    public function testFeedRefreshesItselfWithItsStatusOnTheShowMoreRow(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/hub');

        // A guest has no favourites tab: one feed, refreshing itself
        $feed = $crawler->filter('[data-controller~="live"][data-controller~="live-refresh"]');
        self::assertCount(1, $feed);

        // "Show more" on the left, the status line on the right - never morphed by a re-render
        $status = $feed->filter('.ra-footer .ra-show-more + .live-refresh-status[data-live-ignore][aria-pressed="false"][data-live-refresh-target="status"]');
        self::assertCount(1, $status);
        self::assertSame('Auto-update in 60 seconds', $status->filter('[data-live-refresh-target="text"]')->text());
        self::assertCount(2, $status->filter('[data-live-refresh-target="fill"]'));
        self::assertCount(1, $status->filter('.live-refresh-icon-pause'));

        // docs/features/live-activity-feed.md: the library's blind setInterval polled from tabs nobody looked at
        self::assertCount(0, $crawler->filter('[data-poll]'));

        // The greeting is for screen readers only; both tabs fit on one line (no <br>). Favorites | Everyone for everybody
        // (docs/features/navigation INB-04b) - a guest starts on Everyone
        self::assertCount(1, $crawler->filter('h1.visually-hidden'));
        $feedTabs = $crawler->filter('.nav-tabs')->first();
        self::assertSame(['Favorites', 'Everyone'], $feedTabs->filter('.media-tab-title')->each(static fn ($tab): string => $tab->text()));
        self::assertCount(0, $feedTabs->filter('.media-tab-title br'));
        self::assertSame('#recent-activity', $feedTabs->filter('.nav-link.active')->attr('href'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/hub');

        // All activity + the favourites tab's lazy placeholder, which gets its controller once it has rendered
        self::assertCount(2, $crawler->filter('.tab-content [data-controller~="live"]'));
        self::assertCount(1, $crawler->filter('[data-controller~="live-refresh"]'));
        self::assertCount(0, $crawler->filter('[data-poll]'));
    }

    /**
     * docs/features/navigation INB-04b: a player who follows somebody starts on Favorites - rendered with the page, its
     * "N new" on the tab - and Everyone loads when first shown; everybody else starts on Everyone as before.
     */
    public function testPlayerWithFavoritesStartsOnFavoritesAndEveryoneLoadsLater(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        $feedTabs = $crawler->filter('.nav-tabs')->first();
        self::assertSame(['Favorites', 'Everyone'], $feedTabs->filter('.media-tab-title')->each(static fn ($tab): string => $tab->text()));
        self::assertSame('#recent-favorites-activity', $feedTabs->filter('.nav-link.active')->attr('href'));
        self::assertSame('true', $feedTabs->filter('a[href="#recent-favorites-activity"]')->attr('aria-selected'));
        self::assertSame('false', $feedTabs->filter('a[href="#recent-activity"]')->attr('aria-selected'));

        // Favorites: shown and rendered with the page (a lazy placeholder has no live-refresh yet); Everyone: lazy
        $favorites = $crawler->filter('#recent-favorites-activity');
        self::assertStringContainsString('show active', (string) $favorites->attr('class'));
        self::assertCount(1, $favorites->filter('[data-controller~="live"][data-controller~="live-refresh"]'));
        self::assertCount(0, $favorites->filter('[data-live-name-value][loading="lazy"]'));
        self::assertGreaterThan(0, $favorites->filter('tr[id^="activity-favorites-"]')->count());

        $everyone = $crawler->filter('#recent-activity');
        self::assertStringNotContainsString('active', (string) $everyone->attr('class'));
        self::assertCount(1, $everyone->filter('[data-live-name-value="RecentActivity"][loading="lazy"]'));
        self::assertCount(0, $everyone->filter('[data-controller~="live-refresh"]'));

        // No last visit yet (INB-04a, decision 50): nothing is new, no count
        self::assertCount(0, $feedTabs->filter('.hub-feed-new'));
        self::assertCount(0, $favorites->filter('tr.ra-new'));
    }

    public function testTheFavoritesTabCountsTheNewRowsUpToTwentyPlus(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        // Long ago: every loaded row is new - the fixture favorites have more than 20 times
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE player SET favorites_seen_at = '2000-01-01 00:00:00' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        // The words, and the number alone that narrow phones show instead - hidden from screen readers, which keep the
        // words (checks/INB-04b.py measures the switch)
        $pill = $crawler->filter('.nav-tabs a[href="#recent-favorites-activity"] .hub-feed-new');
        self::assertSame('20+ new', $pill->filter('.hub-feed-new-full')->text());
        self::assertSame('20+', $pill->filter('.hub-feed-new-short[aria-hidden="true"]')->text());
        $rows = $crawler->filter('#recent-favorites-activity tr[id^="activity-favorites-"]');
        self::assertCount(20, $rows);
        self::assertCount(20, $rows->filter('.ra-new'));
        self::assertSame(array_fill(0, 20, 'New'), $rows->filter('.ps-info > .visually-hidden')->each(static fn ($label): string => $label->text()));
        // Everyone never marks anything new
        self::assertCount(0, $crawler->filter('#recent-activity .ra-new'));
    }

    public function testPlayerWithoutFavoritesStartsOnEveryoneWithoutACount(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        $feedTabs = $crawler->filter('.nav-tabs')->first();
        self::assertSame('#recent-activity', $feedTabs->filter('.nav-link.active')->attr('href'));
        self::assertCount(0, $feedTabs->filter('.hub-feed-new'));
        self::assertStringContainsString('show active', (string) $crawler->filter('#recent-activity')->attr('class'));
        self::assertCount(1, $crawler->filter('#recent-activity [data-controller~="live-refresh"]'));
        self::assertCount(1, $crawler->filter('#recent-favorites-activity [data-live-name-value="RecentActivity"][loading="lazy"]'));
        // Nobody to see: the Favorites pane marks nothing (INB-04a)
        self::assertNull($crawler->filter('#recent-favorites-activity')->attr('data-controller'));
    }

    public function testGuestHubStaysTheSameForEverybody(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'));

        self::assertSame('#recent-activity', $crawler->filter('.nav-tabs .nav-link.active')->attr('href'));
        self::assertCount(0, $crawler->filter('.hub-feed-new, .ra-new'));
        // The Favorites pane asks to sign in, nothing per visitor
        $favorites = $crawler->filter('#recent-favorites-activity');
        self::assertCount(1, $favorites->filter('.alert'));
        self::assertNull($favorites->attr('data-controller'));
        self::assertCount(0, $favorites->filter('[data-live-name-value]'));
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/hub');

        $this->assertResponseIsSuccessful();
    }

    public function testNewcomerSeesTheGettingStartedCardUntilTheyHideIt(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertCount(1, $crawler->filter('.getting-started'));
        // The regular fixture player has solving times: the core step is done, so the card is folded
        self::assertCount(1, $crawler->filter('.getting-started details'));
        self::assertCount(0, $crawler->filter('.getting-started details[open]'));

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'getting_started_checklist']);
        self::assertResponseStatusCodeSame(204);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertCount(0, $crawler->filter('.getting-started'));
    }

    public function testGettingStartedCountShowsNumbersInEveryLocale(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertMatchesRegularExpression('/^\d+ of \d+$/', trim($crawler->filter('.getting-started-count')->text()));

        // The free trial once rewrote this text with its own placeholders - the card showed "%logged% of %required%"
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (['cs', 'de', 'en', 'es', 'fr', 'ja'] as $locale) {
            $text = $translator->trans('onboarding.checklist.progress', ['%done%' => 3, '%total%' => 7], locale: $locale);

            self::assertStringNotContainsString('%', $text, $locale);
            self::assertStringContainsString('3', $text, $locale);
            self::assertStringContainsString('7', $text, $locale);
        }
    }

    public function testCardStaysOpenUntilTheFirstPuzzleIsLogged(): void
    {
        $browser = self::createClient();
        $browser->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 254)));

        $crawler = $browser->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form();
        $browser->submit($form, [
            $form->getName() . '[email]' => sprintf('hub.newcomer+%s@example.com', bin2hex(random_bytes(4))),
            $form->getName() . '[plainPassword]' => 'a-properly-long-passphrase',
            $form->getName() . '[termsAccepted]' => '1',
        ]);

        // Every visit, not just the first one
        foreach ([1, 2] as $visit) {
            $crawler = $browser->request('GET', '/en/hub');
            self::assertCount(1, $crawler->filter('.getting-started details[open]'), sprintf('Visit %d', $visit));
        }
    }

    public function testAnonymousVisitorGetsNoGettingStartedCard(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/hub');

        self::assertCount(0, $crawler->filter('.getting-started'));
    }

    public function testHubIsKeptOutOfTheIndexInEveryLocaleButItsLinksAreFollowed(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(UrlGeneratorInterface::class);

        foreach (['cs', 'en', 'es', 'ja', 'fr', 'de'] as $locale) {
            $crawler = $browser->request('GET', $router->generate('hub', ['_locale' => $locale]));

            $this->assertResponseIsSuccessful();
            self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'), $locale);
        }
    }

    public function testHubStaysNoindexForSignedInPlayers(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    /**
     * Home is the Hub for signed-in players (README D5, NAV-10): the top bar's logo and the bottom bar's Home tab link it
     */
    public function testHubIsHomeForSignedInPlayers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/ladder');

        self::assertCount(1, $crawler->filter('body > header.site-header a.site-header-logo[href="/en/hub"]'));
        self::assertCount(1, $crawler->filter('#bottom-nav a[data-nav-place="home"][href="/en/hub"]'));
    }

    /**
     * Guests' Home is the marketing homepage (D5, the change of NAV-10 logged in the delivery state) - the Hub stays
     * reachable for them: the page search finds it (the search-only entry hub_guest, R11)
     */
    public function testHubStaysFindableForGuests(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/ladder');

        self::assertCount(1, $crawler->filter('body > header.site-header a.site-header-logo[href="/"]'));
        self::assertCount(1, $crawler->filter('#bottom-nav a[data-nav-place="home"][href="/"]'));

        $browser->request('GET', '/en/nav-search.json');
        self::assertResponseIsSuccessful();

        /** @var array{entries: list<array{id: string, u: string, a: string}>} $index */
        $index = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $hub = array_values(array_filter($index['entries'], static fn (array $entry): bool => $entry['id'] === 'hub_guest'));

        self::assertCount(1, $hub);
        self::assertSame('/en/hub', $hub[0]['u']);
        self::assertSame('guest', $hub[0]['a']);

        // "Where did it go?" (LAUNCH-02, F24): the old main menu's Hub link still leads guests to the Hub
        $crawler = $browser->request('GET', '/en/where-did-it-go');
        self::assertResponseIsSuccessful();
        $hubRow = $crawler->filter('#wdig-h-hamburger + table .wdig-row')->reduce(static fn (Crawler $row): bool => trim($row->filter('.wdig-old a')->text()) === 'Hub');
        self::assertCount(1, $hubRow);
        self::assertSame('/en/hub', $hubRow->filter('.wdig-old a')->attr('href'));
    }
}
