<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * POST /-/nav-click (NavClickController, docs/features/navigation P0-03): the beacons of assets/nav_instrumentation.js
 * become one info line each on the `navigation` channel; anything the script cannot send is dropped, a secret in the
 * page's path is logged as {token}. Always 204, never a session or a cookie - guests send these too.
 */
final class NavClickControllerTest extends WebTestCase
{
    // A path parameter named like this carries a secret - unless it is listed in NOT_SECRET
    private const string SECRET_PARAMETER = '~token|secret|hash|signature|nonce|verifier|selector|session~i';
    private const array NOT_SECRET = [
        // revoke_personal_access_token: the UUID of the token's row, not the token (a POST, never a page)
        'tokenId',
    ];

    public function testAClickIsLoggedOnTheNavigationChannel(): void
    {
        [$browser, $log] = self::clientWithNavigationLog();

        self::beacon($browser, ['k' => 'click', 'nav' => 'toolbar/tools/notifications']);

        self::assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));

        $records = $log->getRecords();
        self::assertCount(1, $records);
        self::assertSame('navigation', $records[0]->channel);
        self::assertSame('INFO', $records[0]->level->getName());
        self::assertSame('Navigation click', $records[0]->message);
        self::assertSame([
            'kind' => 'click',
            'nav' => 'toolbar/tools/notifications',
            'surface' => 'toolbar',
            'place' => 'tools',
            'item' => 'notifications',
            'page' => '/en/faq',
            'layout' => 'phone',
            'webdriver' => false,
        ], $records[0]->context);
    }

    public function testTheViewportIsLogged(): void
    {
        [$browser, $log] = self::clientWithNavigationLog();

        self::beacon($browser, ['k' => 'viewport', 'bucket' => '1280_1439', 'pointer' => 'fine', 'standalone' => false, 'layout' => 'desktop', 'wd' => true]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([[
            'kind' => 'viewport',
            'bucket' => '1280_1439',
            'pointer' => 'fine',
            'standalone' => false,
            'page' => '/en/faq',
            'layout' => 'desktop',
            'webdriver' => true,
        ]], self::contexts($log));
    }

    public function testABackIsLogged(): void
    {
        [$browser, $log] = self::clientWithNavigationLog();

        self::beacon($browser, ['k' => 'history', 'via' => 'turbo_restore', 'dir' => 'back', 'uaTransition' => true]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([[
            'kind' => 'history',
            'via' => 'turbo_restore',
            'direction' => 'back',
            'ua_visual_transition' => true,
            'page' => '/en/faq',
            'layout' => 'phone',
            'webdriver' => false,
        ]], self::contexts($log));
    }

    /**
     * GEST-13: the watchdog of assets/confirm_dialog.js - a native confirm() an iPhone answered `false` at once.
     */
    public function testASuppressedDialogIsLogged(): void
    {
        [$browser, $log] = self::clientWithNavigationLog();

        self::beacon($browser, ['k' => 'dialog_suppressed', 'afterLayerBack' => true, 'afterRestore' => false]);
        // GEST-15 sends its restore kind: not known here yet, logged as null - the line stays
        self::beacon($browser, ['k' => 'dialog_suppressed', 'afterLayerBack' => 'yes', 'afterRestore' => 'snapshot', 'wd' => true]);

        self::assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
        self::assertSame('Navigation dialog suppressed', $log->getRecords()[0]->message);
        self::assertSame('INFO', $log->getRecords()[0]->level->getName());
        self::assertSame([
            [
                'kind' => 'dialog_suppressed',
                'after_layer_back' => true,
                'after_restore' => false,
                'page' => '/en/faq',
                'layout' => 'phone',
                'webdriver' => false,
            ],
            [
                'kind' => 'dialog_suppressed',
                'after_layer_back' => false,
                'after_restore' => null,
                'page' => '/en/faq',
                'layout' => 'phone',
                'webdriver' => true,
            ],
        ], self::contexts($log));
    }

    /**
     * MENU-02b / NAV-13: an open of the Menu - its surface and the page's place.
     */
    public function testAMenuOpenIsLogged(): void
    {
        [$browser, $log] = self::clientWithNavigationLog();

        self::beacon($browser, ['k' => 'menu_open', 'surface' => 'panel', 'place' => 'puzzles']);
        // A place the script cannot send is logged as unknown, the line stays
        self::beacon($browser, ['k' => 'menu_open', 'surface' => 'overlay', 'place' => 'kitchen', 'layout' => 'desktop']);

        self::assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
        self::assertSame('Navigation menu open', $log->getRecords()[0]->message);
        self::assertSame('INFO', $log->getRecords()[0]->level->getName());
        self::assertSame([
            ['kind' => 'menu_open', 'surface' => 'panel', 'place' => 'puzzles', 'page' => '/en/faq', 'layout' => 'phone', 'webdriver' => false],
            ['kind' => 'menu_open', 'surface' => 'overlay', 'place' => null, 'page' => '/en/faq', 'layout' => 'desktop', 'webdriver' => false],
        ], self::contexts($log));
    }

    /**
     * Optional fields the script could not fill are logged as unknown, the line stays.
     */
    public function testUnknownOptionalValuesAreNotTrusted(): void
    {
        [$browser, $log] = self::clientWithNavigationLog();

        self::beacon($browser, ['k' => 'history', 'via' => 'load', 'dir' => 'sideways', 'uaTransition' => 'yes', 'layout' => 'tv', 'wd' => 'true']);

        self::assertSame([[
            'kind' => 'history',
            'via' => 'load',
            'direction' => 'unknown',
            'ua_visual_transition' => null,
            'page' => '/en/faq',
            'layout' => null,
            'webdriver' => false,
        ]], self::contexts($log));
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('droppedBeacons')]
    public function testAMalformedBeaconIsDroppedSilently(array|string $body): void
    {
        [$browser, $log] = self::clientWithNavigationLog();

        $browser->request('POST', '/-/nav-click', content: is_string($body) ? $body : (string) json_encode($body));

        self::assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
        self::assertSame([], $log->getRecords());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function droppedBeacons(): iterable
    {
        $click = ['v' => 1, 'k' => 'click', 'nav' => 'footer/learn/faq', 'page' => '/en/faq', 'layout' => 'phone', 'wd' => false];

        yield 'not JSON' => ['not-json{{{'];
        yield 'empty body' => [''];
        yield 'a JSON list' => ['[1, 2, 3]'];
        yield 'unknown version' => [['v' => 2] + $click];
        yield 'no version' => [array_diff_key($click, ['v' => true])];
        yield 'unknown kind' => [['k' => 'scroll'] + $click];
        yield 'malformed data-nav' => [['nav' => 'Footer/learn/faq'] + $click];
        yield 'data-nav with two parts' => [['nav' => 'footer/faq'] + $click];
        yield 'data-nav with a dash' => [['nav' => 'footer/learn/getting-started'] + $click];
        // PCRE's $ would accept it, the script's pattern never does
        yield 'data-nav with a trailing newline' => [['nav' => "footer/learn/faq\n"] + $click];
        yield 'data-nav too long' => [['nav' => 'footer/learn/' . str_repeat('a', 100)] + $click];
        yield 'data-nav not a string' => [['nav' => ['footer', 'learn', 'faq']] + $click];
        yield 'page is a whole URL' => [['page' => 'https://evil.example/en/faq'] + $click];
        yield 'page with a query string' => [['page' => '/en/faq?email=someone@example.com'] + $click];
        yield 'page with a fragment' => [['page' => '/en/faq#top'] + $click];
        yield 'page missing' => [array_diff_key($click, ['page' => true])];
        yield 'page not a string' => [['page' => ['/en/faq']] + $click];
        yield 'page relative' => [['page' => 'en/faq'] + $click];
        // location.pathname is percent-encoded ASCII: nothing else is a page the script sends
        yield 'page with a trailing newline' => [['page' => "/en/faq\n"] + $click];
        yield 'page with markup' => [['page' => '/en/<script>alert(1)</script>'] + $click];
        yield 'page with a quote' => [['page' => '/en/"faq"'] + $click];
        yield 'page with a space' => [['page' => '/en/f aq'] + $click];
        yield 'page with a raw non-ASCII character' => [['page' => '/ja/リーダーボード'] + $click];
        yield 'page with text glued to a masked segment' => [['page' => '/password-reset/{token}x'] + $click];
        yield 'page too long' => [['page' => str_repeat('/faq', 76)] + $click];
        yield 'unknown viewport bucket' => [['v' => 1, 'k' => 'viewport', 'bucket' => '360', 'pointer' => 'coarse', 'page' => '/en/faq']];
        yield 'unknown history kind' => [['v' => 1, 'k' => 'history', 'via' => 'swipe', 'dir' => 'back', 'page' => '/en/faq']];
        yield 'menu open on an unknown surface' => [['v' => 1, 'k' => 'menu_open', 'surface' => 'drawer', 'place' => 'home', 'page' => '/en/faq']];
        yield 'menu open without a surface' => [['v' => 1, 'k' => 'menu_open', 'place' => 'home', 'page' => '/en/faq']];
        yield 'suppressed dialog with a whole URL' => [['v' => 1, 'k' => 'dialog_suppressed', 'afterLayerBack' => true, 'afterRestore' => false, 'page' => 'https://evil.example/en/faq']];
        yield 'over 1 KB' => [['padding' => str_repeat('x', 1024)] + $click];
    }

    public function testGetIsNotAllowed(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/nav-click');

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * Guests send these beacons too: the path must belong to the `stateless` firewall (config/packages/security.php),
     * or a session cookie on a beacon could start sessions - and guest pages would stop being shared-cacheable.
     */
    public function testTheBeaconIsServedByTheStatelessFirewall(): void
    {
        self::bootKernel();
        /** @var FirewallMap $firewallMap */
        $firewallMap = self::getContainer()->get('security.firewall.map');

        $firewall = $firewallMap->getFirewallConfig(Request::create('/-/nav-click', 'POST'));
        self::assertNotNull($firewall);
        self::assertSame('stateless', $firewall->getName());
        // security: false - no listener at all, so nothing ever loads, starts or renews a session
        self::assertFalse($firewall->isSecurityEnabled());

        // Only this path, not everything starting with it
        self::assertNotSame('stateless', $firewallMap->getFirewallConfig(Request::create('/-/nav-click-other', 'POST'))?->getName());
    }

    /**
     * A signed-in visitor's beacon carries their session cookie - nothing reads, starts or renews the session.
     */
    public function testASignedInVisitorsBeaconNeverTouchesTheSession(): void
    {
        $browser = self::createClient();
        // The page view and the beacon on one container, so the log handler pushed after the page view sees the beacon
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $log = self::navigationLog();
        self::beacon($browser, ['k' => 'click', 'nav' => 'footer/learn/faq']);

        self::assertResponseStatusCodeSame(204);
        $request = $browser->getRequest();
        // The beacon carried the session cookie of the signed-in visitor (the test environment's own cookie name)...
        self::assertTrue($request->hasPreviousSession(), 'The beacon did not carry the session cookie');
        // ...and nothing started (= read or wrote) the session, nor sent a cookie back
        self::assertFalse($request->getSession()->isStarted());
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
        self::assertCount(1, $log->getRecords());
    }

    /**
     * A secret in the page's path (a password-reset or account-deletion link, a credential claim...) never reaches the
     * log: the segment is logged as {token}. The script masks it before sending; this is the authoritative copy.
     *
     * @param array<string, string> $parameters
     */
    #[DataProvider('secretPaths')]
    public function testASecretInThePathIsLoggedAsToken(string $route, array $parameters, string $secret): void
    {
        [$browser, $log] = self::clientWithNavigationLog();
        $path = self::router()->generate($route, $parameters);
        self::assertStringContainsString('/' . $secret, $path, 'The route no longer carries the secret in its path');

        // The viewport line: sent on module start, before the visitor does anything, in a tab opened from an e-mail
        self::beacon($browser, ['k' => 'viewport', 'bucket' => 'lt768', 'pointer' => 'coarse', 'standalone' => false, 'page' => $path]);
        self::beacon($browser, ['k' => 'click', 'nav' => 'footer/learn/faq', 'page' => $path]);

        $expected = str_replace($secret, NavClickController::MASKED_SEGMENT, $path);
        self::assertSame([$expected, $expected], array_column(self::contexts($log), 'page'));
        self::assertStringNotContainsString($secret, (string) json_encode(self::contexts($log)));
    }

    /**
     * Every route with a secret in its path (testEveryRouteWithASecretInItsPathIsListed() keeps the list complete), with
     * the secret made the way the application makes it.
     *
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function secretPaths(): iterable
    {
        $newsletter = new NewsletterTokenSigner('nav-click-test-secret', new MockClock('2026-10-12 10:00:00'));
        $competitionId = Uuid::uuid7()->toString();

        $token = PasswordResetToken::generate()->toString();
        yield 'password reset' => ['password_reset', ['token' => $token], $token];

        $token = AccountDeletionToken::generate()->toString();
        yield 'account deletion' => ['confirm_account_deletion', ['token' => $token], $token];

        // ApproveOAuth2ClientRequestHandler / ResetOAuth2ClientCredentialsHandler
        $token = bin2hex(random_bytes(32));
        yield 'OAuth2 credential claim' => ['claim_oauth2_credentials', ['_locale' => 'en', 'claimToken' => $token], $token];
        yield 'OAuth2 credential claim, Czech' => ['claim_oauth2_credentials', ['_locale' => 'cs', 'claimToken' => $token], $token];

        // SocialLoginStateStore
        $token = bin2hex(random_bytes(16));
        yield 'social login finish' => ['social_link_finish', ['provider' => 'google', 'token' => $token], $token];

        $token = $newsletter->generateConfirmToken(NewsletterAudience::Guest, Uuid::uuid7()->toString(), 'someone@example.com');
        yield 'newsletter confirm' => ['newsletter_confirm', ['_locale' => 'en', 'token' => $token], $token];

        $token = $newsletter->generateUnsubscribeToken(NewsletterAudience::Player, Uuid::uuid7()->toString(), 'someone@example.com');
        yield 'newsletter unsubscribe' => ['newsletter_unsubscribe', ['_locale' => 'de', 'token' => $token], $token];
        yield 'newsletter unsubscribe confirm' => ['newsletter_unsubscribe_confirm', ['_locale' => 'en', 'token' => $token], $token];
        yield 'newsletter unsubscribed' => ['newsletter_unsubscribed', ['_locale' => 'en', 'token' => $token], $token];

        $token = $newsletter->generatePreferencesToken(NewsletterAudience::Player, Uuid::uuid7()->toString(), 'someone@example.com');
        yield 'e-mail preferences' => ['email_preferences', ['_locale' => 'en', 'token' => $token], $token];
        yield 'e-mail preferences save' => ['email_preferences_save', ['_locale' => 'fr', 'token' => $token], $token];

        // ParticipantImportStash - the competition's id stays readable
        $token = bin2hex(random_bytes(16));
        yield 'participant import preview' => ['participant_import_preview', ['_locale' => 'en', 'competitionId' => $competitionId, 'token' => $token], $token];
        yield 'participant import confirm' => ['participant_import_confirm', ['_locale' => 'en', 'competitionId' => $competitionId, 'token' => $token], $token];
        yield 'participant import discard' => ['participant_import_discard', ['_locale' => 'en', 'competitionId' => $competitionId, 'token' => $token], $token];

        // PhotoStash
        $token = bin2hex(random_bytes(16));
        yield 'photo stash' => ['photo_stash_preview', ['_locale' => 'en', 'token' => $token], $token];

        // A Stripe Checkout Session id: cs_live_ + base62
        $token = 'cs_live_b1' . strtr(base64_encode(random_bytes(42)), '+/', 'Aa');
        yield 'Stripe checkout success' => ['stripe_checkout_success', ['_locale' => 'en', 'sessionId' => $token], $token];
    }

    /**
     * A new route with a secret in its path must be added to secretPaths() - and masked, which that test then checks.
     */
    public function testEveryRouteWithASecretInItsPathIsListed(): void
    {
        self::bootKernel();

        $found = [];

        foreach (self::router()->getRouteCollection()->all() as $name => $route) {
            preg_match_all('~\{(\w+)\}~', $route->getPath(), $parameters);

            foreach ($parameters[1] as $parameter) {
                if (preg_match(self::SECRET_PARAMETER, $parameter) === 1 && !in_array($parameter, self::NOT_SECRET, true)) {
                    $canonical = $route->getDefault('_canonical_route');
                    $found[] = is_string($canonical) ? $canonical : $name;
                }
            }
        }

        $listed = array_values(array_unique(array_column(iterator_to_array(self::secretPaths()), 0)));
        $found = array_values(array_unique($found));
        sort($listed);
        sort($found);

        self::assertSame($found, $listed);
    }

    /**
     * Ids, slugs and percent-encoded words are no secrets: they stay readable, and so does a segment the script masked.
     */
    #[DataProvider('readablePaths')]
    public function testIdsAndSlugsStayReadable(string $page): void
    {
        [$browser, $log] = self::clientWithNavigationLog();

        self::beacon($browser, ['k' => 'click', 'nav' => 'footer/learn/faq', 'page' => $page]);

        self::assertSame([$page], array_column(self::contexts($log), 'page'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readablePaths(): iterable
    {
        yield 'the home page' => ['/'];
        yield 'a player (UUID)' => ['/en/player-profile/' . PlayerFixture::PLAYER_REGULAR];
        yield 'a puzzle (UUID v7)' => ['/en/puzzle/' . Uuid::uuid7()->toString()];
        yield 'an event (slug)' => ['/en/events/autumn-puzzle-cup-2026/results/final-round'];
        yield 'an EAN' => ['/en/puzzle-by-ean-search/4005556123456'];
        yield 'a percent-encoded Japanese path' => ['/ja/' . rawurlencode('リーダーボード') . '/' . rawurlencode('個人')];
        yield 'masked by the script' => ['/password-reset/{token}'];
    }

    /**
     * The script posts to this path - the two must not drift apart.
     */
    public function testTheScriptReportsToThisEndpoint(): void
    {
        self::assertStringContainsString(
            "export const BEACON_URL = '/-/nav-click';",
            (string) file_get_contents(__DIR__ . '/../../assets/nav_instrumentation.js'),
        );
    }

    /**
     * The production logger of the `navigation` channel with a TestHandler pushed onto it - every request of the
     * client runs on this very container (no reboot between requests).
     *
     * @return array{KernelBrowser, TestHandler}
     */
    private static function clientWithNavigationLog(): array
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return [$browser, self::navigationLog()];
    }

    /**
     * A TestHandler pushed onto the `navigation` logger of the current container.
     */
    private static function navigationLog(): TestHandler
    {
        /** @var Logger $logger */
        $logger = self::getContainer()->get('monolog.logger.navigation');
        self::assertSame('navigation', $logger->getName());

        $handler = new TestHandler();
        $handler->setSkipReset(true);
        $logger->pushHandler($handler);

        return $handler;
    }

    private static function router(): RouterInterface
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        return $router;
    }

    /**
     * What the script sends: its fields on top of the common ones.
     *
     * @param array<string, mixed> $fields
     */
    private static function beacon(KernelBrowser $browser, array $fields): void
    {
        $browser->request('POST', '/-/nav-click', content: (string) json_encode($fields + [
            'v' => 1,
            'page' => '/en/faq',
            'layout' => 'phone',
            'wd' => false,
        ]));
    }

    /**
     * @return list<array<mixed>>
     */
    private static function contexts(TestHandler $log): array
    {
        return array_values(array_map(static fn (LogRecord $record): array => $record->context, $log->getRecords()));
    }
}
