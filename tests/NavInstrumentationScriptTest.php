<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Navigation\NavPlace;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use SpeedPuzzling\Web\Value\MembersPlacement;
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
            // NAV-13: no desktop Menu on a phone
            'dock' => 'none',
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
     * NAV-13 (README §4.3 / D16 "if more than half of desktop players fold the column…"): the viewport line says which
     * desktop Menu the session started with - from the column's width on <body> (--nav-left, set by CSS alone) - and each
     * dock / fold of the ☰ is one `layout` line with the new state and the width bucket
     */
    public function testTheDesktopMenusStateAndItsSwitches(): void
    {
        $desktop = ['(pointer: coarse)' => false, '(pointer: fine)' => true, '(min-width: 768px) and (min-height: 501px), (min-width: 768px) and (pointer: fine)' => true];
        $states = [];

        foreach (['256px' => 1440, '88px' => 1280, '0px' => 820] as $navLeft => $width) {
            $did = $this->scenario(['media' => $desktop, 'width' => $width, 'navLeft' => $navLeft, 'steps' => [['do' => 'load']]]);
            $states[$navLeft] = self::beacons($did, 'viewport')[0]['dock'] ?? null;
        }

        self::assertSame(['256px' => 'docked', '88px' => 'rail', '0px' => 'none'], $states);

        $did = $this->scenario(['media' => $desktop, 'width' => 1440, 'navLeft' => '256px', 'steps' => [
            ['do' => 'load', 'page' => '/en/hub'],
            ['do' => 'nav layout', 'detail' => ['layout' => 'rail', 'width' => 1440]],
            ['do' => 'nav layout', 'detail' => ['layout' => 'docked', 'width' => 1300]],
            // Not a state: nothing
            ['do' => 'nav layout', 'detail' => ['layout' => 'sideways', 'width' => 1300]],
            ['do' => 'nav layout', 'detail' => []],
        ]]);

        self::assertSame([
            ['url' => '/-/nav-click', 'v' => 1, 'k' => 'layout', 'state' => 'rail', 'bucket' => '1440_1919', 'page' => '/en/hub', 'layout' => 'desktop', 'wd' => false],
            ['url' => '/-/nav-click', 'v' => 1, 'k' => 'layout', 'state' => 'docked', 'bucket' => '1280_1439', 'page' => '/en/hub', 'layout' => 'desktop', 'wd' => false],
        ], self::beacons($did, 'layout'));
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
     * MENU-02b: every open of the Menu is one line - where it was opened and on which place's page. An unknown surface
     * sends nothing, an unknown place is sent as none.
     */
    public function testEachMenuOpenIsOneLine(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'page' => '/en/puzzle'],
            ['do' => 'menu open', 'detail' => ['surface' => 'panel', 'place' => 'puzzles']],
            ['do' => 'menu open', 'detail' => ['surface' => 'overlay', 'place' => 'secret']],
            ['do' => 'menu open', 'detail' => ['surface' => 'drawer', 'place' => 'home']],
            ['do' => 'menu open', 'detail' => null],
        ]]);

        $lines = array_map(
            static fn (array $beacon): array => [$beacon['page'], $beacon['surface'], $beacon['place'], $beacon['layout']],
            self::beacons($did, 'menu_open'),
        );

        self::assertSame([
            ['/en/puzzle', 'panel', 'puzzles', 'phone'],
            ['/en/puzzle', 'overlay', 'none', 'phone'],
        ], $lines);
    }

    /**
     * MENU-03: a page search without any result is one line - which field and the query (as the search sends it,
     * folded), cut to 40 characters. An unknown field or an empty query sends nothing.
     */
    public function testEachSearchWithoutResultsIsOneLine(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'page' => '/en/faq'],
            ['do' => 'search zero', 'detail' => ['surface' => 'menu', 'q' => 'xyzzy']],
            ['do' => 'search zero', 'detail' => ['surface' => 'topbar', 'q' => '  ' . str_repeat('ab', 30) . '  ']],
            // The filter of "Where did it go?" (LAUNCH review Q3)
            ['do' => 'search zero', 'detail' => ['surface' => 'wdig', 'q' => 'bell']],
            ['do' => 'search zero', 'detail' => ['surface' => 'drawer', 'q' => 'xyzzy']],
            ['do' => 'search zero', 'detail' => ['surface' => 'menu', 'q' => '   ']],
            ['do' => 'search zero', 'detail' => ['surface' => 'menu', 'q' => 42]],
            ['do' => 'search zero', 'detail' => null],
        ]]);

        $lines = array_map(
            static fn (array $beacon): array => [$beacon['page'], $beacon['surface'], $beacon['q']],
            self::beacons($did, 'search_zero'),
        );

        self::assertSame([
            ['/en/faq', 'menu', 'xyzzy'],
            ['/en/faq', 'topbar', str_repeat('ab', 20)],
            ['/en/faq', 'wdig', 'bell'],
        ], $lines);
    }

    /**
     * CARD-07: the card's open, its outcome and a bypass are one line each - the context kind and the input (and the
     * outcome with its ms); anything outside the lists sends nothing, a time that is no sane ms is sent as null
     */
    public function testThePlayerCardsEventsAreOneLineEach(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'page' => '/en/puzzle/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'],
            ['do' => 'event', 'name' => 'msp:card-open', 'detail' => ['context' => 'puzzle', 'input' => 'touch']],
            ['do' => 'event', 'name' => 'msp:card-outcome', 'detail' => ['context' => 'puzzle', 'input' => 'touch', 'outcome' => 'deep', 'ms' => 1234]],
            ['do' => 'event', 'name' => 'msp:card-outcome', 'detail' => ['context' => 'none', 'input' => 'mouse', 'outcome' => 'bounce', 'ms' => -5]],
            ['do' => 'event', 'name' => 'msp:card-bypass', 'detail' => ['context' => 'leaderboard', 'input' => 'mouse']],
            // Dropped: an unknown kind, input or outcome, no detail
            ['do' => 'event', 'name' => 'msp:card-open', 'detail' => ['context' => 'duel', 'input' => 'touch']],
            ['do' => 'event', 'name' => 'msp:card-open', 'detail' => ['context' => 'feed', 'input' => 'voice']],
            ['do' => 'event', 'name' => 'msp:card-outcome', 'detail' => ['context' => 'feed', 'input' => 'touch', 'outcome' => 'challenge', 'ms' => 1]],
            ['do' => 'event', 'name' => 'msp:card-bypass', 'detail' => null],
        ]]);

        $lines = array_map(
            static fn (array $beacon): array => array_diff_key($beacon, ['url' => true, 'v' => true, 'layout' => true, 'wd' => true]),
            array_values(array_filter($did['beacons'], static fn (array $beacon): bool => is_string($beacon['k'] ?? null) && str_starts_with($beacon['k'], 'card_'))),
        );

        $page = '/en/puzzle/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
        self::assertSame([
            ['k' => 'card_open', 'context' => 'puzzle', 'input' => 'touch', 'page' => $page],
            ['k' => 'card_outcome', 'context' => 'puzzle', 'input' => 'touch', 'outcome' => 'deep', 'ms' => 1234, 'page' => $page],
            ['k' => 'card_outcome', 'context' => 'none', 'input' => 'mouse', 'outcome' => 'bounce', 'ms' => null, 'page' => $page],
            ['k' => 'card_bypass', 'context' => 'leaderboard', 'input' => 'mouse', 'page' => $page],
        ], $lines);
    }

    /**
     * TEASE-01: each open of the members modal is one line with the teaser's placement - 'none' from a lock without one
     */
    public function testEachMembersModalOpenIsOneLine(): void
    {
        $did = $this->scenario(['steps' => [
            ['do' => 'load', 'page' => '/en/puzzlers'],
            ['do' => 'event', 'name' => 'msp:members-modal', 'detail' => ['placement' => 'card.tier']],
            ['do' => 'event', 'name' => 'msp:members-modal', 'detail' => ['placement' => 'none']],
            ['do' => 'event', 'name' => 'msp:members-modal', 'detail' => ['placement' => '<b>']],
            ['do' => 'event', 'name' => 'msp:members-modal', 'detail' => null],
        ]]);

        self::assertSame(
            [['/en/puzzlers', 'card.tier'], ['/en/puzzlers', 'none'], ['/en/puzzlers', 'none'], ['/en/puzzlers', 'none']],
            array_map(static fn (array $beacon): array => [$beacon['page'], $beacon['placement']], self::beacons($did, 'members_modal')),
        );
    }

    /**
     * The script and the server know the same card values (CARD-07): context kinds (PlayerCardContextKind + none),
     * inputs, outcomes, the longest ms
     */
    public function testTheScriptAndTheServerKnowTheSameCardValues(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../assets/nav_instrumentation.js');
        $list = static function (string $name) use ($script): array {
            self::assertSame(1, preg_match('~export const ' . $name . ' = \[([^\]]*)\];~', $script, $match), $name);

            return array_map(static fn (string $value): string => trim($value, " '"), explode(',', $match[1]));
        };

        self::assertSame(NavClickController::cardContexts(), $list('CARD_CONTEXTS'));
        self::assertSame(NavClickController::CARD_INPUTS, $list('CARD_INPUTS'));
        self::assertSame(NavClickController::CARD_OUTCOMES, $list('CARD_OUTCOMES'));
        self::assertStringContainsString('export const CARD_MS_MAX = ' . NavClickController::CARD_MS_MAX . ';', $script);

        // Every placement the server knows passes the script's pattern
        self::assertSame(1, preg_match('~export const MEMBERS_PLACEMENT_PATTERN = /(.+)/;~', $script, $match));

        foreach (MembersPlacement::cases() as $placement) {
            self::assertSame(1, preg_match('~' . $match[1] . '~', $placement->value), $placement->value);
        }
    }

    /**
     * The script and the server know the same places (NavPlace)
     */
    public function testTheScriptAndTheServerKnowTheSamePlaces(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../assets/nav_instrumentation.js');
        self::assertSame(1, preg_match("~export const PLACES = \[([^\]]*)\];~", $script, $match));

        $places = array_map(static fn (string $place): string => trim($place, " '"), explode(',', $match[1]));
        self::assertSame(NavClickController::PLACES, $places);

        // ... and they are the places of the navigation (NavPlace)
        foreach (NavPlace::cases() as $place) {
            self::assertContains($place->value, $places);
        }
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
