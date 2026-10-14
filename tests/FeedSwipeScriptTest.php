<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\Navigation\ScssReader;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Swipe between Home's two feeds (docs/features/navigation README §4.13 G14, requirement GEST-16, decision 67):
 * assets/feed_swipe.js run under node (tests/feed-swipe-harness.mjs) - the settled pane, the indicator between the tabs,
 * the scroll behaviour, the way back up to the tabs, the resting panes - and the contract of the stylesheet, the
 * controller and live_refresh_controller.js around it. The controller itself runs under Stimulus in jsdom with
 * Bootstrap's real Tab (tests/feed-swipe-controller-harness.mjs): with and without `scrollend`, a finger holding the
 * scroller between the panes, a tab tapped, from 768 px. The Hub's markup is pinned by HubControllerTest; the browser
 * part (a swipe selects the tab, a vertical drag never scrolls sideways, the height after a Live re-render, only the
 * shown feed refreshes, no shift at load, the same without `scrollend`, the tables edge to edge) is checks/GEST-16.py.
 */
final class FeedSwipeScriptTest extends TestCase
{
    private const array AT_REST_ON_FAVORITES = [
        'panes' => [['resting' => false, 'inert' => false], ['resting' => true, 'inert' => true]],
        'height' => '',
    ];

    private const array SETTLED_ON_EVERYONE = [
        'scrollLeft' => 376,
        'selectedTab' => 1,
        'activeTab' => 1,
        'panes' => [['resting' => true, 'inert' => true], ['resting' => false, 'inert' => false]],
        'height' => '',
    ];

    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    /** @var null|array<string, mixed> */
    private static null|array $controller = null;

    public function testNoCaseThrew(): void
    {
        foreach (self::results() as $case => $result) {
            self::assertFalse(is_array($result) && array_key_exists('threw', $result), $case . ': ' . json_encode($result));
        }
    }

    public function testTheContract(): void
    {
        self::assertSame([
            'PHONE_QUERY' => '(max-width: 767.98px)',
            'SHOWN_EVENT' => 'feed-swipe:shown',
            'RESTING_CLASS' => 'is-resting',
            'AWAKE_CLASS' => 'is-awake',
            'SWIPEABLE_CLASS' => 'is-swipeable',
            'VISIBLE_RATIO' => 0.6,
            'SCROLL_IDLE_MS' => 150,
        ], self::results()['constants']);
    }

    public function testThePaneAScrollRestsOn(): void
    {
        self::assertSame([
            'start' => 0,
            'aThird' => 0,
            'justPastHalf' => 1,
            'second' => 1,
            'overscrolled' => 1,
            'negative' => 1,
            'noWidth' => 0,
            'noPanes' => 0,
            'notANumber' => 0,
        ], self::results()['settledIndex']);
    }

    /** The indicator moves and resizes with scrollLeft, from the first tab's box to the second's */
    public function testTheIndicatorFollowsTheScroller(): void
    {
        self::assertSame([
            'start' => ['x' => 0, 'width' => 190],
            'quarter' => ['x' => 47.5, 'width' => 177],
            'half' => ['x' => 95, 'width' => 164],
            'end' => ['x' => 190, 'width' => 138],
            'past' => ['x' => 190, 'width' => 138],
            'noTabs' => null,
            'noWidth' => ['x' => 0, 'width' => 190],
        ], self::results()['indicatorAt']);
    }

    public function testTheScrollsAndTheRestingPanes(): void
    {
        // A tab tap scrolls smoothly, at once with reduced motion (README §4.13 G8)
        self::assertSame(['motion' => 'smooth', 'reduced' => 'auto'], self::results()['scrollBehavior']);
        // Switched below the tabs: back up so they sit under the top bar
        self::assertSame(['inView' => null, 'underTheBar' => null, 'scrolledPast' => 344, 'noHeader' => 390, 'junk' => null], self::results()['tabsScrollTop']);
        self::assertSame([
            'first' => [['resting' => false, 'inert' => false], ['resting' => true, 'inert' => true]],
            'second' => [['resting' => true, 'inert' => true], ['resting' => false, 'inert' => false]],
            'none' => [],
        ], self::results()['paneStates']);
    }

    /**
     * README §4.13 G14: one horizontal scroll-snap scroller below 768 px (no JS gesture code), Bootstrap's hidden pane
     * overridden once the controller runs - before it (its lazy chunk loading, or failed) only the .active pane shows, as
     * Bootstrap's tabs (Platform feel review F6) - an awake resting pane without height, the tabs' underline in one bar.
     * The scroller reaches the screen's edges and the panes take the page's side padding (review F2): it clips both
     * axes, and the feeds' tables run edge to edge below 576 px.
     */
    public function testTheStylesheet(): void
    {
        $code = ScssReader::withoutComments((string) file_get_contents(__DIR__ . '/../assets/styles/_hub-feeds.scss'));
        $rules = ScssReader::rules($code);

        self::assertStringContainsString('@media (max-width: 767.98px) {', $code);

        // column-gap: the other pane never touches the scroller's edge (Live's lazy loading counts an edge-adjacent box as in view)
        foreach (['display: grid;', 'grid-auto-columns: 100%;', 'grid-auto-flow: column;', 'column-gap: 1rem;', 'overflow-x: auto;', 'overscroll-behavior-x: contain;', 'scroll-snap-type: x mandatory;'] as $declaration) {
            self::assertStringContainsString($declaration, $rules['.hub-feeds'] ?? '', $declaration);
        }

        foreach (['display: block;', 'opacity: 1;', 'scroll-snap-align: start;', 'scroll-snap-stop: always;'] as $declaration) {
            self::assertStringContainsString($declaration, $rules['.hub-feeds > .tab-pane'] ?? '', $declaration);
        }

        // Awake: a resting pane is a column without height. Before: Bootstrap's tabs - only the .active pane shows
        self::assertSame('height: 0;overflow: hidden;', $rules['.hub-feeds.is-awake > .tab-pane.is-resting'] ?? null);
        self::assertSame('display: none;', $rules['.hub-feeds:not(.is-awake) > .tab-pane:not(.active)'] ?? null);
        self::assertArrayNotHasKey('.hub-feeds > .tab-pane.is-resting', $rules, 'a resting pane has its height until the controller runs');

        // The scroller out to the screen's edges by the page's side padding, the panes in by the same
        self::assertStringContainsString('$hub-feeds-page-padding: map-get($spacers, 3);', $code);
        self::assertStringContainsString('margin-right: -$hub-feeds-page-padding;margin-left: -$hub-feeds-page-padding;', $rules['.hub-feeds'] ?? '');
        self::assertStringContainsString('padding-right: $hub-feeds-page-padding;padding-left: $hub-feeds-page-padding;', $rules['.hub-feeds > .tab-pane'] ?? '');
        // ...the content container's px-3 ($spacers 3), which the Hub's content sits in
        self::assertStringContainsString('<section class="container px-3 ', (string) file_get_contents(__DIR__ . '/../templates/base.html.twig'));
        self::assertStringNotContainsString('{% block full_content %}', (string) file_get_contents(__DIR__ . '/../templates/hub.html.twig'));
        self::assertStringContainsString('transform: translateX(var(--hub-feed-x, 0px)) scaleX(var(--hub-feed-w, 0));', $rules['.nav-tabs.hub-feed-tabs.is-swipeable::after'] ?? '');
        // Nothing animates: the indicator is the finger's, the panes snap by the browser
        self::assertDoesNotMatchRegularExpression('~(?<![\w-])animation\s*:|transition\s*:\s*+(?!none)~', $code);
    }

    /** The controller's contract: the tab's scroll from show.bs.tab, Bootstrap's tabs, the event INB-04a listens to */
    public function testTheControllerAndTheLiveFeed(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../assets/controllers/feed_swipe_controller.js');
        self::assertStringStartsWith("/* stimulusFetch: 'lazy' */", $controller);
        self::assertStringContainsString("this.scrollerTarget.addEventListener('scrollend', this.onScrollEnd);", $controller);
        self::assertStringContainsString('new IntersectionObserver((entries) => this.paneInView(entries), { root: this.scrollerTarget, threshold: VISIBLE_RATIO });', $controller);
        self::assertStringContainsString('pane.dispatchEvent(new CustomEvent(SHOWN_EVENT, { bubbles: true, detail: { pane } }));', $controller);
        self::assertStringContainsString('Tab.getOrCreateInstance(tab).show();', $controller);
        self::assertStringContainsString('pane.inert = state.inert;', $controller);
        self::assertStringContainsString('behavior: scrollBehavior(this.reducedMotion.matches)', $controller);

        // A tab's scroll starts in Bootstrap's show.bs.tab: its data-bs-toggle click handler runs in the capture phase on
        // the document, before any action on the link could (the tab links keep tabs#showTab only)
        self::assertStringContainsString("this.tabsTarget.addEventListener('show.bs.tab', this.onTabShow);", $controller);
        self::assertStringContainsString('if (!this.phone.matches || this.showingItself) {', $controller);
        $hub = (string) file_get_contents(__DIR__ . '/../templates/hub.html.twig');
        self::assertSame(2, substr_count($hub, 'data-action="tabs#showTab"'));

        // live_refresh: shown = ≥ 60 % inside the Hub's scroller (an observer rooted there), never inside [inert]
        $live = (string) file_get_contents(__DIR__ . '/../assets/controllers/live_refresh_controller.js');
        self::assertStringContainsString("const scroller = this.element.closest('.hub-feeds');", $live);
        self::assertStringContainsString('{ root: scroller, threshold: [0, VISIBLE_RATIO] }', $live);
        self::assertStringContainsString("if (this.element.closest('[inert]') !== null) {", $live);
        self::assertSame(0, preg_match('~[^.]isShown\(this\.element\)~', str_replace('return isShown(this.element);', '', $live)), 'every check goes through this.isShown()');

        // INB-04a's mark-seen listens to the scroller's event
        self::assertStringContainsString("document.addEventListener('feed-swipe:shown', this.onShown);", (string) file_get_contents(__DIR__ . '/../assets/controllers/favorites_seen_controller.js'));
    }

    public function testTheControllerRanWithoutAnError(): void
    {
        foreach (self::controller() as $case => $result) {
            self::assertFalse(is_array($result) && array_key_exists('threw', $result), $case . ': ' . json_encode($result));
        }

        self::assertSame([], self::controller()['errors']);
    }

    /**
     * Platform feel review F1: without `scrollend` (Safari before it, older iOS) the observer at 60 % selects the tab of
     * the pane coming in - Bootstrap runs the link's shown.bs.tab synchronously inside show() - and nothing more. While
     * the finger holds the scroller at 65 % for 300 ms (no scroll event: the idle wait runs out meanwhile) the pane it
     * came from keeps its content (not resting), is not inert, the scroller keeps that pane's height, no
     * feed-swipe:shown, the page does not move. Lifted, the snap scrolls on and the scroll at rest settles: then the
     * other pane rests and is inert, one feed-swipe:shown, the page back up to the tabs.
     */
    public function testWithoutScrollEndAFingerHoldingThePaneSettlesNothing(): void
    {
        $observer = 'no scrollend: the observer';
        self::assertFalse(self::fact($observer, 'hasScrollEnd'));
        self::assertSame([['threshold' => 0.6, 'root' => true, 'targets' => 2]], self::fact($observer, 'observers'));

        $hold = 'no scrollend: a finger holding at 65 % of the way';
        // The first touch wakes the resting pane, the scroller keeps the shown pane's height meanwhile
        self::assertSame([['resting' => false, 'inert' => false], ['resting' => false, 'inert' => true]], self::fact($hold, 'atTouch', 'panes'));
        self::assertSame('900px', self::fact($hold, 'atTouch', 'height'));
        // Every frame of the drag: the pane the finger came from never rested, never inert, one height, nothing settled
        self::assertSame(['resting' => false, 'inert' => false, 'heights' => ['900px'], 'pageScrolls' => 0, 'shown' => 0], self::fact($hold, 'during'));
        self::assertSame([
            'scrollLeft' => 244,
            'selectedTab' => 1,
            'activeTab' => 1,
            'panes' => [['resting' => false, 'inert' => false], ['resting' => false, 'inert' => true]],
            'height' => '900px',
            'shown' => [],
            'pageScrolls' => [],
        ], self::fact($hold, 'holding'));
        // Lifted, the snap's scroll still under way: nothing settled yet
        self::assertSame([], self::fact($hold, 'snapped', 'shown'));
        self::assertFalse(self::fact($hold, 'snapped', 'panes', 0, 'resting'));
        // At rest: settled on Everyone, back up to the tabs (scrolled 1200 px, the tabs 800 px above the top)
        self::assertSame(self::SETTLED_ON_EVERYONE + ['shown' => ['recent-activity'], 'pageScrolls' => [['top' => 400, 'behavior' => 'smooth']]], self::fact($hold, 'settled'));

        // To 65 % and back: the tab follows the pane in view both ways, nothing rests on the way, nothing is shown
        $back = 'no scrollend: to 65 % and back';
        self::assertSame(1, self::fact($back, 'out'));
        self::assertSame(0, self::fact($back, 'back'));
        self::assertSame(['resting' => false, 'inert' => false, 'heights' => ['900px'], 'pageScrolls' => 0, 'shown' => 0], self::fact($back, 'during'));
        self::assertSame(['scrollLeft' => 0, 'selectedTab' => 0, 'activeTab' => 0] + self::AT_REST_ON_FAVORITES + ['shown' => [], 'pageScrolls' => []], self::fact($back, 'settled'));

        // A second finger lifting is no release
        $fingers = 'no scrollend: a second finger lifting keeps the hold';
        self::assertSame([['resting' => false, 'inert' => false], ['resting' => false, 'inert' => true]], self::fact($fingers, 'holding', 'panes'));
        self::assertSame([], self::fact($fingers, 'holding', 'shown'));
        self::assertSame(self::SETTLED_ON_EVERYONE + ['shown' => ['recent-activity'], 'pageScrolls' => []], self::fact($fingers, 'settled'));

        // No finger at all (a wheel, a trackpad): the scroll at rest for 150 ms settles
        $wheel = 'no scrollend: a wheel or trackpad at rest settles after the idle wait';
        self::assertSame([], self::fact($wheel, 'waiting', 'shown'));
        self::assertFalse(self::fact($wheel, 'waiting', 'panes', 0, 'resting'));
        self::assertSame(self::SETTLED_ON_EVERYONE + ['shown' => ['recent-activity'], 'pageScrolls' => []], self::fact($wheel, 'settled'));

        // A tab tapped: its pane scrolls into view smoothly, the scroll at rest settles
        $tap = 'no scrollend: a tab tapped';
        self::assertSame([['left' => 376, 'behavior' => 'smooth']], self::fact($tap, 'scrollerScrolls'));
        self::assertSame([], self::fact($tap, 'tapped', 'shown'));
        self::assertSame(self::SETTLED_ON_EVERYONE + ['shown' => ['recent-activity'], 'pageScrolls' => []], self::fact($tap, 'settled'));
    }

    /** With `scrollend` nothing but scrollend settles (no observer, no idle wait); a tab tap scrolls and settles once */
    public function testWithScrollEndOnlyTheScrollsEndSettles(): void
    {
        $swipe = 'scrollend: a swipe settles at scrollend only';
        self::assertTrue(self::fact($swipe, 'hasScrollEnd'));
        self::assertSame([], self::fact($swipe, 'observers'));
        self::assertSame(['resting' => false, 'inert' => false, 'heights' => ['900px'], 'pageScrolls' => 0, 'shown' => 0], self::fact($swipe, 'during'));
        self::assertSame(0, self::fact($swipe, 'holding', 'selectedTab'));
        self::assertSame(0, self::fact($swipe, 'beforeEnd', 'selectedTab'));
        self::assertSame([], self::fact($swipe, 'beforeEnd', 'shown'));
        self::assertSame(self::SETTLED_ON_EVERYONE + ['shown' => ['recent-activity'], 'pageScrolls' => [['top' => 400, 'behavior' => 'smooth']]], self::fact($swipe, 'settled'));

        $tap = 'scrollend: a tab tapped';
        self::assertSame([['left' => 376, 'behavior' => 'smooth'], ['left' => 0, 'behavior' => 'smooth']], self::fact($tap, 'scrollerScrolls'));
        self::assertSame(self::SETTLED_ON_EVERYONE + ['shown' => ['recent-activity'], 'pageScrolls' => []], self::fact($tap, 'settled'));
        self::assertSame(['scrollLeft' => 0, 'selectedTab' => 0, 'activeTab' => 0] + self::AT_REST_ON_FAVORITES + ['shown' => ['recent-activity', 'recent-favorites-activity'], 'pageScrolls' => []], self::fact($tap, 'back'));
    }

    /** From 768 px: Bootstrap's tabs, no scroller, only the hidden pane inert */
    public function testFrom768PixelsBootstrapsTabs(): void
    {
        $desktop = 'from 768 px: Bootstrap tabs, only the hidden pane inert';
        self::assertFalse(self::fact($desktop, 'awake'));
        self::assertSame([], self::fact($desktop, 'scrollerScrolls'));
        self::assertSame([false, true], [self::fact($desktop, 'atLoad', 'panes', 0, 'inert'), self::fact($desktop, 'atLoad', 'panes', 1, 'inert')]);
        self::assertSame(1, self::fact($desktop, 'clicked', 'selectedTab'));
        self::assertSame([true, false], [self::fact($desktop, 'clicked', 'panes', 0, 'inert'), self::fact($desktop, 'clicked', 'panes', 1, 'inert')]);
    }

    /** One fact of a controller case: the case's answer followed down the path */
    private static function fact(string $case, string|int ...$path): mixed
    {
        $value = self::controller()[$case] ?? null;

        foreach ($path as $key) {
            self::assertIsArray($value, $case . ' › ' . implode(' › ', $path));
            $value = $value[$key] ?? null;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function controller(): array
    {
        if (self::$controller !== null) {
            return self::$controller;
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the controller - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/feed-swipe-controller-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$controller = $results;
    }

    /**
     * @return array<string, mixed>
     */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/feed-swipe-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
