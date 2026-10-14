<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Controller\Compete\CompetePageController;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * The participant's page of an online round (`compete_page`, docs/features/online-competitions/README.md §5.4, §5.5):
 * the token opens it - anonymous, session-free, never stored by a cache, never indexed - and a signed-in visitor sees
 * what their account has to do with the link before the page's own connect POST.
 */
final class CompetePageControllerTest extends WebTestCase
{
    private const string PAGE = '/en/compete/';

    public function testAnUnknownLinkGetsTheFriendly404(): void
    {
        $browser = self::createClient();

        // A token of the right shape that opens nothing, and junk
        foreach (['UnknownTestCupToken222', 'not-a-token'] as $token) {
            $browser->request('GET', self::PAGE . $token);

            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertSelectorTextContains('h1', 'This link does not open a competition');
            self::assertStringContainsString('ask them for your personal link', (string) $browser->getResponse()->getContent());
            self::assertPrivatePage($browser);
        }
    }

    public function testARemovedParticipantsLinkOpensNothing(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->update('competition_participant', [
            'deleted_at' => '2026-10-13 10:00:00',
        ], ['id' => OnlineRoundsFixture::PARTICIPANT_ANONYMOUS]);

        $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('h1', 'This link does not open a competition');
        self::assertPrivatePage($browser);
    }

    public function testAnAnonymousVisitRendersThePageWithoutStartingASession(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertResponseIsSuccessful();
        self::assertSame([], $browser->getResponse()->headers->getCookies(), 'an anonymous visit sets no cookie');
        self::assertFalse($browser->getRequest()->hasSession() && $browser->getRequest()->getSession()->isStarted(), 'an anonymous visit starts no session');

        $root = $crawler->filter('.compete');
        self::assertCount(1, $root);
        self::assertSame('compete', $root->attr('data-controller'));
        self::assertSame('welcome', $root->attr('data-screen'));
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, $root->attr('data-compete-participant-id-value'));
        self::assertStringContainsString('Arthur Anonymous', $crawler->filter('.compete-bar')->text());
        self::assertStringContainsString('Online Test Cup', $crawler->filter('.compete-bar')->text());

        // The first state is the participant state of the API (implementation-plan.md §3.1)
        $state = self::initialState($crawler);
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, self::at($state, 'participant', 'id'));
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, self::at($state, 'round', 'id'));
        self::assertSame('waiting', self::at($state, 'round', 'status'));
        self::assertIsArray($state['entry'] ?? null);
        self::assertIsInt($state['asOfMs'] ?? null);

        $connect = self::jsonValue($root, 'data-compete-connect-value');
        self::assertSame(CompetePageController::CONNECT_ANONYMOUS, $connect['status'] ?? null);
        self::assertNull($connect['csrfToken'] ?? null, 'no token for an anonymous visitor');
        self::assertNull($connect['url'] ?? null);

        // Sign in, and back to this very page
        $signIn = $crawler->filter('.compete-sign-in a');
        self::assertCount(2, $signIn, 'on the welcome and the after screen');
        self::assertSame('/login?return=' . self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS, $signIn->first()->attr('href'));

        $urls = self::jsonValue($root, 'data-compete-urls-value');
        self::assertSame('/api/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/state', $urls['state'] ?? null);
        self::assertSame('/api/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/heartbeat', $urls['heartbeat'] ?? null);
        self::assertSame('/api/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/events', $urls['events'] ?? null);
        self::assertSame('/api/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/media', $urls['media'] ?? null);
        self::assertSame('/-/time', $urls['time'] ?? null);

        // The JS texts are one JSON object (tests/CompeteTextsTest.php checks every key)
        self::assertSame('Waiting for the organiser', self::jsonValue($root, 'data-compete-texts-value')['waiting'] ?? null);
    }

    /**
     * Stream RP (the rehearsal of Test Cup #1): "Tap here once" carries both of its texts - the start before GO, time's
     * up while the round runs (the script shows one, compete_flow.js wakeTapText()); the practice is offered on the
     * after screen without "any time before the round"
     */
    public function testTheWakeBannerAndThePracticeSayWhatFitsTheRound(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS);
        self::assertResponseIsSuccessful();

        $banner = $crawler->filter('.compete-banners [data-compete-show="wakeTap"]');
        self::assertCount(1, $banner);
        self::assertSame('compete#wakeUp', $banner->attr('data-action'));
        self::assertSame('Tap here once - so you hear the start and the screen stays on', $banner->filter('[data-compete-show="wakeTapStart"]')->text());
        self::assertNull($banner->filter('[data-compete-show="wakeTapStart"]')->attr('hidden'));
        self::assertSame("Tap here once - so you hear time's up and the screen stays on", $banner->filter('[data-compete-show="wakeTapTimeUp"]')->text());
        self::assertNotNull($banner->filter('[data-compete-show="wakeTapTimeUp"]')->attr('hidden'));

        $hint = 'Try the set-up on your own any time before the round - nothing is sent.';

        foreach (['checklist', 'waiting'] as $screen) {
            $practice = $crawler->filter(sprintf('[data-compete-screen="%s"] .compete-practice-entry', $screen));
            self::assertCount(1, $practice, $screen);
            self::assertStringContainsString($hint, $practice->text(), $screen);
        }

        $after = $crawler->filter('[data-compete-screen="after"] .compete-practice-entry');
        self::assertCount(1, $after);
        self::assertSame('compete#startPractice', $after->filter('button')->attr('data-action'), 'the practice is still there after the round');
        self::assertStringNotContainsString($hint, $after->text());
        self::assertCount(0, $after->filter('p'));
    }

    public function testThePageIsNeverStoredIndexedOrPassedOnAndNeverNavigatesWithTurbo(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertResponseIsSuccessful();
        self::assertPrivatePage($browser);

        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        // Arriving from a Turbo page: a full load, so the camera never lives in a pushState'd document (README §5.5)
        self::assertSame('reload', $crawler->filter('meta[name="turbo-visit-control"]')->attr('content'));
        // The token never leaves in a canonical or language alternate link
        self::assertCount(0, $crawler->filter('link[rel="canonical"], link[rel="alternate"][hreflang]'));
        // No header, no footer - one screen
        self::assertCount(0, $crawler->filter('header.sticky-top, footer.site-footer'));
        // The page's own stylesheet entry
        self::assertStringContainsString('/build/compete', (string) $browser->getResponse()->getContent());

        $root = $crawler->filter('.compete');
        self::assertSame('false', $root->attr('data-turbo'));
        self::assertNotNull($root->attr('data-ptr-ignore'), 'no pull-to-refresh over the competition');

        $links = $root->filter('a');
        self::assertGreaterThan(0, $links->count());
        $links->each(static function (Crawler $link): void {
            self::assertSame('false', $link->attr('data-turbo'), 'every link: ' . $link->attr('href'));
        });
        self::assertGreaterThan(0, $root->filter('form')->count());
        $root->filter('form')->each(static function (Crawler $form): void {
            self::assertSame('false', $form->attr('data-turbo'), 'every form');
            self::assertNotNull($form->attr('data-action'), 'every form is handled by the page');
        });
    }

    public function testAConnectedParticipantSeesTheirOwnLinkConnected(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_CONNECTED);

        self::assertResponseIsSuccessful();
        self::assertPrivatePage($browser);

        $root = $crawler->filter('.compete');
        $connect = self::jsonValue($root, 'data-compete-connect-value');
        self::assertSame(CompetePageController::CONNECT_ALREADY_CONNECTED, $connect['status'] ?? null);
        self::assertNull($connect['url'] ?? null, 'nothing to connect');
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $connect['playerName'] ?? null);
        self::assertIsString($connect['csrfToken'] ?? null);

        $line = $crawler->filter('p.compete-connect')->first();
        self::assertNull($line->attr('hidden'));
        self::assertStringContainsString('Connected to the profile of ' . PlayerFixture::PLAYER_REGULAR_NAME, $line->text());
        self::assertNotNull($crawler->filter('.compete-sign-in')->first()->attr('hidden'), 'no "Sign in" for a signed-in visitor');

        $state = self::initialState($crawler);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, self::at($state, 'participant', 'connectedPlayer', 'id'));
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, self::at($state, 'round', 'id'));
    }

    public function testTheConnectStateOfOtherSignedInVisitors(): void
    {
        $browser = self::createClient();

        // Another account on a link already connected: still usable, nothing changes
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(CompetePageController::CONNECT_BELONGS_TO_OTHER, self::connectStatus($browser, OnlineRoundsFixture::TOKEN_CONNECTED));
        self::assertStringContainsString('This link belongs to another account', $browser->getCrawler()->filter('p.compete-connect')->first()->text());

        // The same account on a link nobody is connected to: the page connects it (its own POST)
        self::assertSame(CompetePageController::CONNECT_AUTO, self::connectStatus($browser, OnlineRoundsFixture::TOKEN_ANONYMOUS));

        // Already another participant of the competition (Olivia opening Arthur's link): no automatic connect
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertSame(CompetePageController::CONNECT_IS_OTHER_PARTICIPANT, self::connectStatus($browser, OnlineRoundsFixture::TOKEN_ANONYMOUS));

        // The organiser (here the admin who set it up) previews: "This is me" instead of a connect
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::assertSame(CompetePageController::CONNECT_ORGANISER, self::connectStatus($browser, OnlineRoundsFixture::TOKEN_ANONYMOUS));
        // The page shows the preview banner with its "This is me" (sending nothing until then)
        self::assertCount(1, $browser->getCrawler()->filter('.compete-banner--preview [data-action="compete#confirmConnect"]'));
    }

    public function testAParticipantNotEnteredInTheRound(): void
    {
        $browser = self::createClient();
        // Nora was added after the links were made and is in no round - she gets a link now
        self::getContainer()->get(Connection::class)->update('competition_participant', [
            'online_token' => 'NotEnteredTestCupToken',
            'online_token_created_at' => '2026-10-13 10:00:00',
        ], ['id' => OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]);

        $crawler = $browser->request('GET', self::PAGE . 'NotEnteredTestCupToken');

        self::assertResponseIsSuccessful();
        self::assertSame('not_entered', $crawler->filter('.compete')->attr('data-screen'));

        $state = self::initialState($crawler);
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, self::at($state, 'round', 'id'), 'the round is shown');
        self::assertNull($state['entry'], 'she is not entered in it');

        $screen = $crawler->filter('[data-compete-screen="not_entered"]');
        self::assertNull($screen->attr('hidden'));
        self::assertStringContainsString('Nora Notoken is not in Online Round', $screen->text());
        self::assertNotNull($crawler->filter('[data-compete-screen="welcome"]')->attr('hidden'));
    }

    public function testADraftCompetitionsLinkWorksForAnybody(): void
    {
        $browser = self::createClient();

        $isDraft = self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT is_draft FROM competition WHERE id = ?', [OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP]);
        self::assertTrue((bool) $isDraft, 'the Online Test Cup is a draft');

        // An anonymous visitor and a signed-in stranger alike - the token is the access right
        $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_CONNECTED);
        self::assertResponseIsSuccessful();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS);
        self::assertResponseIsSuccessful();
        self::assertSame('Online Test Cup', $crawler->filter('#compete-welcome-title')->text());

        // A draft has no public results page to link to
        self::assertCount(0, $crawler->filter('[data-compete-screen="after"] a.btn-outline-primary'));
    }

    public function testOnAComputerTheQrCodeOpensThisVeryPage(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertResponseIsSuccessful();

        $expected = self::getContainer()->get(NameTagQrCode::class)->svg('http://localhost' . self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS);
        $qr = $crawler->filter('.compete-desktop .compete-qr svg');
        self::assertCount(1, $qr);
        self::assertStringContainsString(trim($expected), (string) $browser->getResponse()->getContent(), 'the QR encodes the page\'s own absolute URL');
        self::assertStringContainsString('Continue on your phone', $crawler->filter('.compete-desktop')->text());
    }

    public function testInAnInAppBrowserThePageSaysToOpenTheRealBrowser(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS, server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36 Instagram 350.0.0.0',
        ]);

        self::assertResponseIsSuccessful();
        $notice = $crawler->filter('.compete-in-app');
        self::assertCount(1, $notice);
        self::assertStringContainsString('Open this page in your phone\'s browser', $notice->text());
        self::assertSame('http://localhost' . self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS, $notice->attr('data-copy-link-url-value'));
        self::assertStringStartsWith('intent://localhost' . self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS, (string) $notice->filter('a')->attr('href'));
        self::assertSame('true', $crawler->filter('.compete')->attr('data-compete-in-app-value'));

        // A real browser sees no notice
        $crawler = $browser->request('GET', self::PAGE . OnlineRoundsFixture::TOKEN_ANONYMOUS, server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        ]);
        self::assertCount(0, $crawler->filter('.compete-in-app'));
        self::assertSame('false', $crawler->filter('.compete')->attr('data-compete-in-app-value'));
    }

    public function testEveryLocaleOpensTheSamePage(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/soutezit/' . OnlineRoundsFixture::TOKEN_ANONYMOUS);
        self::assertResponseIsSuccessful();
        self::assertSame('cs', $crawler->filter('html')->attr('lang'));

        $languages = $crawler->filter('.compete-languages a');
        self::assertCount(5, $languages, 'the other five languages');
        $languages->each(static function (Crawler $link): void {
            self::assertStringEndsWith('/' . OnlineRoundsFixture::TOKEN_ANONYMOUS, (string) $link->attr('href'));
        });
    }

    private static function assertPrivatePage(KernelBrowser $browser): void
    {
        $headers = $browser->getResponse()->headers;

        self::assertTrue($headers->hasCacheControlDirective('no-store'), 'Cache-Control: no-store - ' . $headers->get('Cache-Control'));
        self::assertTrue($headers->hasCacheControlDirective('private'), 'Cache-Control: private - ' . $headers->get('Cache-Control'));
        self::assertFalse($headers->hasCacheControlDirective('public'));
        self::assertSame('same-origin', $headers->get('Referrer-Policy'));
        self::assertSame('noindex', $headers->get('X-Robots-Tag'));
    }

    private static function connectStatus(KernelBrowser $browser, string $token): null|string
    {
        $crawler = $browser->request('GET', self::PAGE . $token);
        self::assertResponseIsSuccessful();
        $status = self::jsonValue($crawler->filter('.compete'), 'data-compete-connect-value')['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * A value deep in a decoded JSON array - null when any step is missing.
     *
     * @param array<mixed> $data
     */
    private static function at(array $data, string ...$keys): mixed
    {
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function initialState(Crawler $crawler): array
    {
        $state = json_decode($crawler->filter('script[data-compete-target="initialState"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        /** @var array<string, mixed> $state */
        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonValue(Crawler $element, string $attribute): array
    {
        $value = json_decode((string) $element->attr($attribute), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
