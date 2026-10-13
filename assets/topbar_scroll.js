import { isNativeApp } from './platform.js';

// The phone top bar's hide-on-scroll (docs/features/navigation README §4.1, NAV-03, decision 20): pure decisions for
// assets/controllers/topbar_controller.js, run under node by tests/topbar-scroll-harness.mjs.
//
// - It hides after 8 px of scrolling down once the page is past the bar's height, and comes back after 8 px up or near
//   the top. Each change of direction starts counting again from where it happened.
// - It never hides on desktop, on viewports under 480 px tall (there it scrolls with the page, WCAG technique C34),
//   inside the Hotwire Native app (no web top bar there), while the search, the Menu or a menu of the bar is open, or
//   while the focus is inside the bar.
// - Sub-bars stuck under it (position: sticky at var(--header-height)) follow it up through `translate` - only while
//   they really are stuck: a sticky element further down the page must not move (stuckAt()).

/** A scroll down of this many px (past the bar's height) hides the bar */
export const HIDE_AFTER_PX = 8;

/** A scroll up of this many px brings it back */
export const SHOW_AFTER_PX = 8;

/** The phone top bar's height (critical CSS: --header-height on phones) */
export const PHONE_TOPBAR_PX = 52;

/** Below this viewport height the bar scrolls with the page */
export const SHORT_VIEWPORT_PX = 480;

/** GEST-03 (a tap on the active tab) and the search bring the bar back with this event on window */
export const TOPBAR_REVEAL_EVENT = 'msp:topbar-reveal';

/**
 * The sticky sub-bars that follow the bar (SCSS: @include nav-follow-topbar(…, $sticky: true) - tests/Navigation/
 * NavLayoutCssTest.php keeps both lists equal). Marked data-topbar-stuck while stuck; only marked ones translate.
 */
export const STICKY_FOLLOWERS = [
    '.cmp-aside',
    '.cmp-table-float',
    '.achievements-layout-side',
    '.achievement-tier-section-head',
    '.ev-detail-side',
    '.seating-sticky-bar',
    '.ev-month-header',
    '.ev-toolbar',
    '.ev-rail',
    '.wjpc-search',
];

/**
 * Whether the bar may hide now (native-aware decision, tests/native-step-aside-harness.mjs): inside the app never.
 * facts: {phone, viewportHeight, searchOpen, menuOpen, popupOpen, focusInside}
 */
export function topbarMayHide(nativeApp, facts = {}) {
    if (nativeApp) {
        return false;
    }

    return Boolean(facts.phone)
        && Number(facts.viewportHeight) >= SHORT_VIEWPORT_PX
        && !facts.searchOpen
        && !facts.menuOpen
        && !facts.popupOpen
        && !facts.focusInside;
}

/**
 * The scroll position the hide/show rules read: window.scrollY within [0, max] (max = scrollHeight - innerHeight).
 * iOS's rubber band reports positions beyond both ends while a fling bounces - a bounce back from beyond the end would
 * otherwise read as a scroll up and bring the bar back (NAV review F3). A max that is not a number clamps only at 0.
 */
export function clampScroll(y, max = Infinity) {
    const current = Math.max(0, Number.isFinite(y) ? y : 0);

    return Number.isFinite(max) ? Math.min(current, Math.max(0, max)) : current;
}

/**
 * The bar's state on <html> back to neutral - shown, no shadow (NAV-07, NAV review F1/Q1). <html> outlives a Turbo
 * visit: the next page must never start with a hidden bar (its header would be computed translated and slide in, or -
 * a page without the controller, the sign-in pages - stay out of view). `remove` drops data-topbar too: a page without
 * the controller then starts neutral.
 */
export function resetTopbar(root, { remove = false } = {}) {
    if (!root || typeof root.setAttribute !== 'function') {
        return;
    }

    if (remove) {
        root.removeAttribute('data-topbar');
    } else {
        root.setAttribute('data-topbar', 'shown');
    }

    root.removeAttribute('data-topbar-scrolled');
}

/**
 * One scroll step. state: {hidden, anchor, lastY, dir} (null at first); y: window.scrollY; mayHide: topbarMayHide();
 * maxY: the largest real scroll position (scrollHeight - innerHeight) - y is clamped to [0, maxY] first, so a move that
 * only comes from the clamp (a rubber band beyond either end) is no move.
 * Returns the next state, with `scrolled` (the page is not at its top: the bar's shadow).
 */
export function nextTopbarState(state, y, mayHide, barHeight = PHONE_TOPBAR_PX, maxY = Infinity) {
    const current = clampScroll(y, maxY);
    const previous = state && typeof state === 'object' ? state : { hidden: false, anchor: current, lastY: current, dir: null };
    const lastY = Number.isFinite(previous.lastY) ? previous.lastY : current;

    if (!mayHide || current <= barHeight) {
        return { hidden: false, anchor: current, lastY: current, dir: null, scrolled: current > 0 };
    }

    const dir = current > lastY ? 'down' : (current < lastY ? 'up' : previous.dir);
    // A change of direction counts from where it changed
    const anchor = dir !== previous.dir && previous.dir !== null ? lastY : (Number.isFinite(previous.anchor) ? previous.anchor : lastY);
    let hidden = Boolean(previous.hidden);

    if (dir === 'down' && current - anchor >= HIDE_AFTER_PX) {
        hidden = true;
    } else if (dir === 'up' && anchor - current >= SHOW_AFTER_PX) {
        hidden = false;
    }

    return { hidden, anchor, lastY: current, dir, scrolled: true };
}

/** The vertical part of a computed `translate` ("none", "10px", "0px -52px", "0px -52px 0px") */
export function translateY(value) {
    if (typeof value !== 'string' || value === 'none' || value.trim() === '') {
        return 0;
    }

    const parts = value.trim().split(/\s+/);

    return parts.length > 1 ? (parseFloat(parts[1]) || 0) : 0;
}

/** The vertical part of a computed `transform` ("none", "matrix(a, b, c, d, e, f)", "matrix3d(…16)") */
export function transformY(value) {
    if (typeof value !== 'string' || value === 'none') {
        return 0;
    }

    const match = value.match(/^matrix(3d)?\(([^)]*)\)$/);

    if (!match) {
        return 0;
    }

    const numbers = match[2].split(',').map((part) => parseFloat(part));

    return (match[1] ? numbers[13] : numbers[5]) || 0;
}

/**
 * A sticky element is stuck when its layout box (its on-screen top without its own translate/transform) sits at its
 * sticky offset: a stuck element is held exactly there, one further down the page is below it.
 */
export function stuckAt(rectTop, stickyTop, shiftY = 0) {
    const layoutTop = rectTop - shiftY;

    return Number.isFinite(layoutTop) && Number.isFinite(stickyTop) && layoutTop <= stickyTop + 0.5;
}
