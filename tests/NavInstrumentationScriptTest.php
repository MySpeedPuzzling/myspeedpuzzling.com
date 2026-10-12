<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The navigation instrumentation (assets/nav_instrumentation.js, docs/features/navigation P0-03): a click on a chrome
 * link or button carrying data-nav becomes a `msp:nav` event and one beacon to /-/nav-click (a GA event once GA is
 * loaded); a click anywhere else sends nothing; the viewport goes out once per session; every Back/Forward
 * navigation is one line; a secret in the page's path is sent as {token}. Runs the real module under node in a fake
 * browser (tests/nav-instrumentation-harness.mjs).
 *
 * @phpstan-type Did array{beacons: list<array<string, mixed>>, events: list<array{type: string, bubbles: bool, detail: mixed, on: string}>, gtag: list<list<mixed>>}
 */
final class NavInstrumentationScriptTest extends TestCase
{
    private const array FAQ_LINK = [['tag' => 'SPAN'], ['tag' => 'A', 'attributes' => ['data-nav' => 'footer/learn/faq']]];

    public function testAClickOnANavLinkDispatchesTheEventAndSendsOneBeacon(): void
    {
        $did = $this->scenario(['steps' => [['do' => 'load'], ['do' => 'click', 'path' => self::FAQ_LINK]]]);

        self::assertSame([[
            'type' => 'msp:nav',
            'bubbles' => true,
            'detail' => ['surface' => 'footer', 'place' => 'learn', 'item' => 'faq'],
            'on' => 'A',
        ]], $did['events']);
        self::assertSame([[
            'url' => '/-/nav-click',
            'v' => 1,
            'k' => 'click',
            'nav' => 'footer/learn/faq',
            'page' => '/en/faq',
            'layout' => 'phone',
            'wd' => false,
        ]], self::beacons($did, 'click'));
        // GA is not loaded yet: nothing for it
        self::assertSame([], $did['gtag']);
    }

    public function testButtonsCountToo(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'width' => 1280, 'media' => ['(pointer: fine)' => true, '(min-width: 768px) and (min-height: 501px), (min-width: 768px) and (pointer: fine)' => true]],
            ['do' => 'click', 'path' => [['tag' => 'I'], ['tag' => 'BUTTON', 'attributes' => ['data-nav' => 'hamburger/toggle/open']]]],
        ]]);

        self::assertSame(['surface' => 'hamburger', 'place' => 'toggle', 'item' => 'open'], $did['events'][0]['detail'] ?? null);
        self::assertSame([['hamburger/toggle/open', 'desktop']], array_map(
            static fn (array $beacon): array => [$beacon['nav'], $beacon['layout']],
            self::beacons($did, 'click'),
        ));
    }

    public function testAClickElsewhereOnThePageSendsNothing(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load'],
            ['do' => 'click', 'path' => [['tag' => 'P'], ['tag' => 'SECTION'], ['tag' => 'MAIN']]],
            // A link without data-nav (page content)
            ['do' => 'click', 'path' => [['tag' => 'A', 'attributes' => ['href' => '/en/puzzle']], ['tag' => 'MAIN']]],
        ]]);

        self::assertSame([], $did['events']);
        self::assertSame([], self::beacons($did, 'click'));
    }

    /**
     * Only links and buttons: the <html> element carries data-nav-layout (never data-nav), other elements never count.
     */
    public function testOnlyLinksAndButtonsCount(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load'],
            ['do' => 'click', 'path' => [['tag' => 'DIV', 'attributes' => ['data-nav' => 'footer/learn/faq']]]],
            ['do' => 'click', 'path' => [['tag' => 'P'], ['tag' => 'HTML', 'attributes' => ['data-nav-layout' => 'rail']]]],
        ]]);

        self::assertSame([], $did['events']);
        self::assertSame([], self::beacons($did, 'click'));
    }

    #[DataProvider('malformedValues')]
    public function testAMalformedValueIsDropped(string $value): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'gaLoaded' => true],
            ['do' => 'click', 'path' => [['tag' => 'A', 'attributes' => ['data-nav' => $value]]]],
        ]]);

        self::assertSame([], $did['events']);
        self::assertSame([], self::beacons($did, 'click'));
        self::assertSame([], $did['gtag']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedValues(): iterable
    {
        yield 'empty' => [''];
        yield 'two parts' => ['footer/faq'];
        yield 'four parts' => ['footer/learn/faq/more'];
        yield 'upper case' => ['Footer/learn/faq'];
        yield 'dash' => ['footer/learn/getting-started'];
        yield 'digit in the surface' => ['footer2/learn/faq'];
        yield 'space' => ['footer/learn/faq '];
        yield 'trailing newline' => ["footer/learn/faq\n"];
        yield 'too long' => ['footer/learn/' . str_repeat('a', 100)];
    }

    /**
     * The script and NavClickController agree on what a well-formed value is.
     */
    public function testTheScriptAndTheServerAcceptTheSameValues(): void
    {
        $values = ['toolbar/tools/notifications', 'footer/popular_brands/cobble_hill', 'footer/popular_world_championship/wjpc_2026', 'admin_key/queues/oauth2_requests', ...array_column(iterator_to_array(self::malformedValues()), 0)];

        $parsed = $this->runInNode(['parse' => $values])['parsed'];

        foreach ($values as $index => $value) {
            $serverAccepts = strlen($value) <= 100 && preg_match(NavClickController::NAV_PATTERN, $value) === 1;
            self::assertSame($serverAccepts, $parsed[$index] !== null, $value);
        }
    }

    /**
     * A secret in the path (here a password-reset link opened from an e-mail: a new tab, so the viewport line goes out
     * on module start) never leaves the browser - the segment is sent as {token}.
     */
    public function testASecretInThePathNeverLeavesTheBrowser(): void
    {
        $token = PasswordResetToken::generate()->toString();

        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'page' => '/password-reset/' . $token],
            ['do' => 'click', 'path' => self::FAQ_LINK],
        ]]);

        self::assertSame(['viewport', 'click'], array_column($did['beacons'], 'k'));
        self::assertSame(['/password-reset/{token}', '/password-reset/{token}'], array_column($did['beacons'], 'page'));
        self::assertStringNotContainsString($token, (string) json_encode($did));
    }

    /**
     * A path the server would drop is not sent at all; the click still dispatches its event.
     */
    public function testAPageOutsideTheGrammarSendsNothing(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'page' => '/en/<script>'],
            ['do' => 'click', 'path' => self::FAQ_LINK],
        ]]);

        self::assertSame([], $did['beacons']);
        self::assertCount(1, $did['events']);
    }

    /**
     * The script and NavClickController log the same page for every path - the secret paths of every route family,
     * ids and slugs that stay, and paths that are dropped.
     */
    public function testTheScriptAndTheServerLogTheSamePage(): void
    {
        $pages = [];

        foreach (self::pagesWithTheirLoggedForm() as [$page, $logged]) {
            $pages[] = $page;
            self::assertSame($logged, NavClickController::loggablePage($page), $page);
        }

        self::assertSame(array_map(NavClickController::loggablePage(...), $pages), $this->runInNode(['pages' => $pages])['pages']);
    }

    /**
     * @return list<array{string, null|string}>
     */
    private static function pagesWithTheirLoggedForm(): array
    {
        $hex64 = bin2hex(random_bytes(32));
        $hex32 = bin2hex(random_bytes(16));
        $uuid = Uuid::uuid7()->toString();
        $newsletter = new NewsletterTokenSigner('nav-click-test-secret', new MockClock('2026-10-12 10:00:00'))
            ->generateUnsubscribeToken(NewsletterAudience::Guest, $uuid, 'someone@example.com');
        $stripe = 'cs_test_a1' . strtr(base64_encode(random_bytes(42)), '+/', 'Zz');

        return [
            ['/en/faq', '/en/faq'],
            ['/', '/'],
            ['/password-reset/' . PasswordResetToken::generate()->toString(), '/password-reset/{token}'],
            ['/delete-account/' . AccountDeletionToken::generate()->toString(), '/delete-account/{token}'],
            ['/en/claim-oauth2-credentials/' . $hex64, '/en/claim-oauth2-credentials/{token}'],
            ['/prevzit-oauth2-pristupove-udaje/' . $hex64, '/prevzit-oauth2-pristupove-udaje/{token}'],
            ['/connect/social/google/finish/' . $hex32, '/connect/social/google/finish/{token}'],
            ['/en/newsletter/unsubscribe/' . $newsletter . '/done', '/en/newsletter/unsubscribe/{token}/done'],
            ['/en/email-preferences/' . $newsletter, '/en/email-preferences/{token}'],
            ['/en/import-event-participants/' . $uuid . '/' . $hex32 . '/confirm', '/en/import-event-participants/' . $uuid . '/{token}/confirm'],
            ['/en/photo-stash/' . $hex32, '/en/photo-stash/{token}'],
            ['/en/membership-checkout-success/' . $stripe, '/en/membership-checkout-success/{token}'],
            ['/password-reset/{token}', '/password-reset/{token}'],
            ['/en/player-profile/' . strtoupper($uuid), '/en/player-profile/' . strtoupper($uuid)],
            ['/en/events/autumn-puzzle-cup-2026/results/final-round', '/en/events/autumn-puzzle-cup-2026/results/final-round'],
            ['/sitemap-feature-requests.xml', '/sitemap-feature-requests.xml'],
            ['/ja/' . rawurlencode('リーダーボード'), '/ja/' . rawurlencode('リーダーボード')],
            ["/en/faq\n", null],
            ['/en/faq?x=1', null],
            ['/en/faq#top', null],
            ['https://evil.example/en/faq', null],
            ['en/faq', null],
            ['', null],
            ['/en/<script>', null],
            ['/ja/リーダーボード', null],
            ['/password-reset/{token}x', null],
            ['/en/{tok}', null],
            [str_repeat('/faq', 75), str_repeat('/faq', 75)],
            [str_repeat('/faq', 76), null],
            // Long only before masking: logged
            ['/en/newsletter/unsubscribe/' . str_repeat('A', 400), '/en/newsletter/unsubscribe/{token}'],
        ];
    }

    public function testGoogleAnalyticsGetsTheClickOnceLoaded(): void
    {
        $did = $this->scenario(['steps' => [['do' => 'load', 'gaLoaded' => true], ['do' => 'click', 'path' => self::FAQ_LINK]]]);

        self::assertSame([['event', 'nav_click', [
            'nav_id' => 'footer/learn/faq',
            'nav_surface' => 'footer',
            'nav_place' => 'learn',
            'nav_item' => 'faq',
            'nav_layout' => 'phone',
        ]]], $did['gtag']);
        self::assertCount(1, self::beacons($did, 'click'));
    }

    public function testWebdriverClicksAreCountedAndMarked(): void
    {
        $did = $this->scenario(['steps' => [['do' => 'load', 'webdriver' => true], ['do' => 'click', 'path' => self::FAQ_LINK]]]);

        self::assertTrue(self::beacons($did, 'click')[0]['wd'] ?? null);
    }

    /**
     * The first page of a session sends its viewport; the next page views (each one starts the module again) do not.
     */
    public function testTheViewportIsSentOncePerSession(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'page' => '/en/faq'],
            ['do' => 'load', 'page' => '/en/puzzle'],
            ['do' => 'load', 'page' => '/en/faq'],
        ]]);

        self::assertSame([[
            'url' => '/-/nav-click',
            'v' => 1,
            'k' => 'viewport',
            'bucket' => 'lt768',
            'pointer' => 'coarse',
            'standalone' => false,
            'page' => '/en/faq',
            'layout' => 'phone',
            'wd' => false,
        ]], self::beacons($did, 'viewport'));
    }

    public function testTheViewportOfADesktopWithAMouse(): void
    {
        $did = $this->scenario([
            'media' => ['(pointer: coarse)' => false, '(pointer: fine)' => true, '(display-mode: standalone)' => true, '(min-width: 768px) and (min-height: 501px), (min-width: 768px) and (pointer: fine)' => true],
            'width' => 1440,
            'steps' => [['do' => 'load']],
        ]);

        $viewport = self::beacons($did, 'viewport')[0] ?? [];
        self::assertSame(['1440_1919', 'fine', true, 'desktop'], [$viewport['bucket'] ?? null, $viewport['pointer'] ?? null, $viewport['standalone'] ?? null, $viewport['layout'] ?? null]);
    }

    /**
     * Without session storage there is no way to keep it to one line per session - so nothing; clicks still count.
     */
    #[DataProvider('unavailableStorage')]
    public function testNoSessionStorageNoViewportLine(string $storage): void
    {
        $did = $this->scenario(['storage' => $storage, 'steps' => [['do' => 'load'], ['do' => 'load'], ['do' => 'click', 'path' => self::FAQ_LINK]]]);

        self::assertSame([], self::beacons($did, 'viewport'));
        self::assertCount(1, self::beacons($did, 'click'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unavailableStorage(): iterable
    {
        yield 'access throws' => ['throws'];
        yield 'no storage' => ['none'];
    }

    public function testViewportBuckets(): void
    {
        $widths = [320 => 'lt768', 767 => 'lt768', 768 => '768_1023', 1023 => '768_1023', 1024 => '1024_1279', 1279 => '1024_1279', 1280 => '1280_1439', 1439 => '1280_1439', 1440 => '1440_1919', 1919 => '1440_1919', 1920 => 'gte1920', 2560 => 'gte1920'];

        self::assertSame(array_values($widths), $this->runInNode(['widths' => array_keys($widths)])['buckets']);
    }

    /**
     * history.back() after two Turbo visits: Turbo restores, app.js counts it and reloads the page - that reload is a
     * replace navigation, so the Back is exactly one line.
     */
    public function testABackIsOneHistoryLine(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'index' => 0],
            ['do' => 'turbo:load', 'index' => 0],
            ['do' => 'turbo:load', 'index' => 1],
            ['do' => 'turbo:load', 'index' => 2],
            ['do' => 'navigate', 'index' => 1, 'uaTransition' => true],
            ['do' => 'turbo restore'],
            // location.href = the restored URL
            ['do' => 'load', 'index' => 1, 'page' => '/en/puzzle', 'activation' => ['navigationType' => 'replace', 'from' => ['index' => 1], 'entry' => ['index' => 1]]],
        ]]);

        self::assertSame([[
            'url' => '/-/nav-click',
            'v' => 1,
            'k' => 'history',
            'via' => 'turbo_restore',
            'dir' => 'back',
            'uaTransition' => true,
            'page' => '/en/faq',
            'layout' => 'phone',
            'wd' => false,
        ]], self::beacons($did, 'history'));
    }

    public function testAForwardIsOneHistoryLine(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'index' => 1],
            ['do' => 'turbo:load', 'index' => 1],
            ['do' => 'popstate', 'index' => 2, 'uaTransition' => false],
            ['do' => 'turbo restore'],
        ]]);

        self::assertSame([['turbo_restore', 'forward', false]], self::historyLines($did));
    }

    /**
     * Turbo's own history position where the browser has no Navigation API.
     */
    public function testATurboRestoreWithoutTheNavigationApi(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'navigation' => false, 'turboIndex' => 4],
            ['do' => 'popstate', 'index' => 3],
            ['do' => 'turbo restore'],
        ]]);

        self::assertSame([['turbo_restore', 'back', null]], self::historyLines($did));
    }

    public function testAPageLoadedByATraverseIsOneLine(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'index' => 2, 'activation' => ['navigationType' => 'traverse', 'from' => ['index' => 3], 'entry' => ['index' => 2]]],
            // Reloads, pushes and replaces are no Back/Forward
            ['do' => 'load', 'index' => 2, 'activation' => ['navigationType' => 'reload', 'from' => ['index' => 2], 'entry' => ['index' => 2]]],
            ['do' => 'load', 'index' => 3, 'activation' => ['navigationType' => 'push', 'from' => ['index' => 2], 'entry' => ['index' => 3]]],
        ]]);

        self::assertSame([['load', 'back', null]], self::historyLines($did));
    }

    public function testABackForwardLoadWithoutTheNavigationApi(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'navigation' => false, 'timingType' => 'back_forward'],
            ['do' => 'load', 'navigation' => false, 'timingType' => 'navigate'],
            ['do' => 'load', 'navigation' => false, 'timingType' => 'reload'],
        ]]);

        self::assertSame([['load', 'unknown', null]], self::historyLines($did));
    }

    public function testAPageShownFromTheBackForwardCacheIsOneLine(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'index' => 3],
            // The first pageshow of every page load is not a restore
            ['do' => 'pageshow', 'persisted' => false],
            ['do' => 'pageshow', 'persisted' => true, 'activation' => ['navigationType' => 'traverse', 'from' => ['index' => 4], 'entry' => ['index' => 3]]],
        ]]);

        self::assertSame([['bfcache', 'back', null]], self::historyLines($did));
    }

    /**
     * GEST-13: the confirm watchdog's report goes out on the same channel - what it knew, nothing else.
     */
    public function testASuppressedDialogIsOneLine(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'page' => '/en/live-results/0199c1a8-0000-7000-8000-000000000001'],
            ['do' => 'dialog suppressed', 'detail' => ['afterLayerBack' => true, 'afterRestore' => false]],
            ['do' => 'dialog suppressed', 'detail' => ['afterLayerBack' => 'yes', 'afterRestore' => ['kind' => 'snapshot']]],
            ['do' => 'dialog suppressed', 'detail' => ['afterRestore' => 'snapshot']],
        ]]);

        $lines = array_map(
            static fn (array $beacon): array => [$beacon['page'], $beacon['afterLayerBack'], $beacon['afterRestore']],
            self::beacons($did, 'dialog_suppressed'),
        );

        self::assertSame([
            ['/en/live-results/0199c1a8-0000-7000-8000-000000000001', true, false],
            ['/en/live-results/0199c1a8-0000-7000-8000-000000000001', false, null],
            ['/en/live-results/0199c1a8-0000-7000-8000-000000000001', false, 'snapshot'],
        ], $lines);
    }

    /**
     * app.js counts a restore right before it turns it into a full page load.
     */
    public function testAppJsCountsTheRestoreBeforeReloading(): void
    {
        $appJs = (string) file_get_contents(__DIR__ . '/../assets/app.js');

        $counted = strpos($appJs, 'navInstrumentation.turboRestore();');
        $reloaded = strpos($appJs, 'window.location.href = event.detail.url;');

        self::assertNotFalse($counted);
        self::assertNotFalse($reloaded);
        self::assertLessThan($reloaded, $counted);
    }

    /**
     * @param Did $did
     * @return list<array<string, mixed>>
     */
    private static function beacons(array $did, string $kind): array
    {
        return array_values(array_filter($did['beacons'], static fn (array $beacon): bool => ($beacon['k'] ?? null) === $kind));
    }

    /**
     * @param Did $did
     * @return list<array{mixed, mixed, mixed}>
     */
    private static function historyLines(array $did): array
    {
        return array_map(
            static fn (array $beacon): array => [$beacon['via'] ?? null, $beacon['dir'] ?? null, $beacon['uaTransition'] ?? null],
            self::beacons($did, 'history'),
        );
    }

    /**
     * @param array<string, mixed> $scenario
     * @return Did
     */
    private function scenario(array $scenario): array
    {
        $result = $this->runInNode(['scenarios' => [$scenario]]);
        self::assertSame('/-/nav-click', $result['beaconUrl']);
        self::assertCount(1, $result['scenarios']);

        return $result['scenarios'][0];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{beaconUrl: string, parsed: list<null|array<string, string>>, pages: list<null|string>, buckets: list<string>, scenarios: list<Did>}
     */
    private function runInNode(array $input): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/nav-instrumentation-harness.mjs']);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{beaconUrl: string, parsed: list<null|array<string, string>>, pages: list<null|string>, buckets: list<string>, scenarios: list<Did>} $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }
}
