<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

        // The greeting is for screen readers only; both tabs fit on one line (no <br>)
        self::assertCount(1, $crawler->filter('h1.visually-hidden'));
        $feedTabs = $crawler->filter('.nav-tabs')->first();
        self::assertSame(['Recent activity', 'Favorites'], $feedTabs->filter('.media-tab-title')->each(static fn ($tab): string => $tab->text()));
        self::assertCount(0, $feedTabs->filter('.media-tab-title br'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/hub');

        // All activity + the favourites tab's lazy placeholder, which gets its controller once it has rendered
        self::assertCount(2, $crawler->filter('.tab-content [data-controller~="live"]'));
        self::assertCount(1, $crawler->filter('[data-controller~="live-refresh"]'));
        self::assertCount(0, $crawler->filter('[data-poll]'));
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

    public function testHubStaysInTheNavigation(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/ladder');

        self::assertCount(1, $crawler->filter('.navbar-nav a[href="/en/hub"]'));
    }
}
