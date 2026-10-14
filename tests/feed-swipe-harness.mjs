// Runs assets/feed_swipe.js (swipe between Home's two feeds, docs/features/navigation README §4.13 G14, GEST-16) through
// the cases tests/FeedSwipeScriptTest.php asks for and prints one JSON object {case: answer}.

import {
    AWAKE_CLASS,
    indicatorAt,
    paneStates,
    PHONE_QUERY,
    RESTING_CLASS,
    SCROLL_IDLE_MS,
    scrollBehavior,
    settledIndex,
    SHOWN_EVENT,
    SWIPEABLE_CLASS,
    tabsScrollTop,
    VISIBLE_RATIO,
} from '../assets/feed_swipe.js';

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

// Two tabs of different widths (nav-fill sizes them by their words): Favorites + its count, Everyone
const TABS = [{ left: 0, width: 190 }, { left: 190, width: 138 }];

const results = Object.fromEntries([
    run('constants', () => ({ PHONE_QUERY, SHOWN_EVENT, RESTING_CLASS, AWAKE_CLASS, SWIPEABLE_CLASS, VISIBLE_RATIO, SCROLL_IDLE_MS })),

    // The pane a scroll position rests on: the nearest, clamped; a 328 px wide scroller of two panes
    run('settledIndex', () => ({
        start: settledIndex(0, 328, 2),
        aThird: settledIndex(109, 328, 2),
        justPastHalf: settledIndex(165, 328, 2),
        second: settledIndex(328, 328, 2),
        overscrolled: settledIndex(400, 328, 2),
        negative: settledIndex(-328, 328, 2),
        noWidth: settledIndex(200, 0, 2),
        noPanes: settledIndex(200, 328, 0),
        notANumber: settledIndex(NaN, 328, 2),
    })),

    // The indicator follows scrollLeft between the two tabs' boxes
    run('indicatorAt', () => ({
        start: indicatorAt(0, 328, TABS),
        quarter: indicatorAt(82, 328, TABS),
        half: indicatorAt(164, 328, TABS),
        end: indicatorAt(328, 328, TABS),
        past: indicatorAt(500, 328, TABS),
        noTabs: indicatorAt(100, 328, []),
        noWidth: indicatorAt(100, 0, TABS),
    })),

    run('scrollBehavior', () => ({ motion: scrollBehavior(false), reduced: scrollBehavior(true) })),

    // Switched below the tabs (their top above the 56 px top bar): back up so they sit under it; in view: nothing
    run('tabsScrollTop', () => ({
        inView: tabsScrollTop({ tabsTop: 120, scrollY: 0, headerHeight: 56 }),
        underTheBar: tabsScrollTop({ tabsTop: 56, scrollY: 300, headerHeight: 56 }),
        scrolledPast: tabsScrollTop({ tabsTop: -800, scrollY: 1200, headerHeight: 56 }),
        noHeader: tabsScrollTop({ tabsTop: -10, scrollY: 400, headerHeight: NaN }),
        junk: tabsScrollTop({}),
    })),

    run('paneStates', () => ({ first: paneStates(2, 0), second: paneStates(2, 1), none: paneStates(0, 0) })),
]);

process.stdout.write(JSON.stringify(results));
