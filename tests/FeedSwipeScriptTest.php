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
 * controller and live_refresh_controller.js around it. The Hub's markup is pinned by HubControllerTest; the browser part
 * (a swipe selects the tab, a vertical drag never scrolls sideways, the height after a Live re-render, only the shown
 * feed refreshes, no shift at load) is checks/GEST-16.py.
 */
final class FeedSwipeScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

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
     * overridden, a resting pane without height (no column before the controller runs), the tabs' underline in one bar
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

        self::assertSame('height: 0;overflow: hidden;', $rules['.hub-feeds > .tab-pane.is-resting'] ?? null);
        self::assertSame('display: none;', $rules['.hub-feeds:not(.is-awake) > .tab-pane.is-resting'] ?? null);
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
