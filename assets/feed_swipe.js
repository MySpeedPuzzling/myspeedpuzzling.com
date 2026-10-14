// Swipe between Home's two feeds, Favorites | Everyone (docs/features/navigation README §4.13 G14; requirement GEST-16;
// decision 67) - the maths of controllers/feed_swipe_controller.js. Phones (< 768 px) only: both panes sit in one
// horizontal scroll-snap scroller (assets/styles/_hub-feeds.scss - no JS gesture code, the browser scrolls); the tabs stay
// the main control; the selected tab follows the pane the scroller settles on; the indicator under the tabs follows
// scrollLeft; the scroller is as high as the shown pane; the other pane rests (.is-resting: height 0, inert) and its Live
// feed does not refresh (live_refresh_controller.js). Identical on iOS and Android; from 768 px Bootstrap's tabs as before.
//
// Pure: no DOM access; node-tested in tests/feed-swipe-harness.mjs.

/** Where the scroller exists (_hub-feeds.scss uses the same width) */
export const PHONE_QUERY = '(max-width: 767.98px)';

/** Dispatched on a pane when the scroller settles on it (bubbles; detail.pane) - INB-04a's mark-seen listens to it */
export const SHOWN_EVENT = 'feed-swipe:shown';

/** The pane nobody sees: height 0 (rendered so by the server for the non-default pane - no shift at load) */
export const RESTING_CLASS = 'is-resting';

/** On the scroller once the controller runs: both panes are columns (before, a resting first pane takes none) */
export const AWAKE_CLASS = 'is-awake';

/** On the tab list while the indicator follows the scroller (the tabs' own underline steps aside) */
export const SWIPEABLE_CLASS = 'is-swipeable';

/** The settled pane without scrollend: ≥ 60 % visible (IntersectionObserver), and the live feed's "shown" */
export const VISIBLE_RATIO = 0.6;

/** Without scrollend: the scroller is at rest after this long without a scroll event */
export const SCROLL_IDLE_MS = 150;

/**
 * The pane a scroll position rests on: the nearest one (scroll-snap puts it exactly there). `pitch`: from one pane's start
 * to the next one's (the pane's width + the gap between them)
 */
export function settledIndex(scrollLeft, pitch, count) {
    if (!(pitch > 0) || !(count > 0) || !Number.isFinite(scrollLeft)) {
        return 0;
    }

    return Math.max(0, Math.min(count - 1, Math.round(Math.abs(scrollLeft) / pitch)));
}

function round(value) {
    return Math.round(value * 100) / 100;
}

/**
 * The indicator under the tabs for a scroll position: between the boxes of the two tabs around it, as far as the
 * scroller has come (`pitch` as in settledIndex(); tabs: [{left, width}] relative to the tab list; null without tabs)
 */
export function indicatorAt(scrollLeft, pitch, tabs) {
    if (!Array.isArray(tabs) || tabs.length === 0) {
        return null;
    }

    const last = tabs.length - 1;
    const position = pitch > 0 && Number.isFinite(scrollLeft) ? Math.max(0, Math.min(last, Math.abs(scrollLeft) / pitch)) : 0;
    const index = Math.min(last, Math.floor(position));
    const fraction = position - index;
    const from = tabs[index];
    const to = tabs[Math.min(last, index + 1)];

    return {
        x: round(from.left + (to.left - from.left) * fraction),
        width: round(from.width + (to.width - from.width) * fraction),
    };
}

/** A tab tap scrolls its pane into view smoothly - at once with reduced motion */
export function scrollBehavior(reducedMotion) {
    return reducedMotion ? 'auto' : 'smooth';
}

/**
 * Switching panes while scrolled below the tabs: the page goes back up so the tabs sit right under the top bar (the new
 * feed starts at its top). null when the tabs are in view.
 */
export function tabsScrollTop({ tabsTop, scrollY, headerHeight }) {
    if (!Number.isFinite(tabsTop) || !Number.isFinite(scrollY)) {
        return null;
    }

    const header = Number.isFinite(headerHeight) ? headerHeight : 0;

    return tabsTop < header ? Math.max(0, Math.round(scrollY + tabsTop - header)) : null;
}

/** Each pane for a selected one: the others rest and are inert */
export function paneStates(count, selected) {
    return Array.from({ length: Math.max(0, count) }, (_, index) => ({ resting: index !== selected, inert: index !== selected }));
}
