import { isNativeApp } from './platform.js';

/**
 * Tap the active tab (docs/features/navigation README §4.13 G3, requirement GEST-03, implementation plan GEST-03):
 * the pure decision behind assets/controllers/tab_gestures_controller.js, run under node by
 * tests/tab-reselect-harness.mjs. Identical on iOS and Android.
 *
 * In this order:
 * 1. the Menu panel is open: the current place's tab only closes it (`close-menu`); another tab navigates - the visit
 *    replaces the Menu's history entry (GEST-01 (d));
 * 2. another place's tab: `navigate`;
 * 3. the same place on a deeper page: `navigate` - the tab's link is the place's start page;
 * 4. the start page scrolled: `scroll-top` - smooth, instant with reduced motion - and the top bar comes back;
 * 5. at the top of Home: `refresh-feed` - the Hub's feed refreshes in place (live_refresh_controller.js);
 *    at the top of Add: `none` - Add never reloads, a half-filled form survives;
 *    at the top of any other start page: `navigate` (a visit to the same URL).
 * `close-menu`, `scroll-top`, `refresh-feed` and `none` keep the link from navigating (preventDefault).
 *
 * The start page of a tab is the path of its current href (decision 58 D-5) - the Puzzles tab leads to the section used
 * last, so every section root counts (assets/puzzles_section.js points it there before the tap). Add also counts the add
 * form with a chosen puzzle (`…/puzzle-add/{puzzleId}`, where a puzzle page's Add leads), whose tab links the bare form:
 * that is the form a tap must never throw away.
 *
 * Inside the Hotwire Native app there is no web tab bar; the neutral answer is `navigate` (the link does what it says).
 */

export const CLOSE_MENU = 'close-menu';
export const NAVIGATE = 'navigate';
export const SCROLL_TOP = 'scroll-top';
export const REFRESH_FEED = 'refresh-feed';
export const NONE = 'none';

/** Asks the shown Live feed of the page to refresh now (assets/controllers/live_refresh_controller.js), on document */
export const FEED_REFRESH_EVENT = 'msp:feed-refresh';

const HOME = 'home';
const ADD = 'add';

/** A path without its trailing slash (the root stays "/"); anything that is not a string is no path */
export function normalizePath(path) {
    if (typeof path !== 'string' || path === '') {
        return null;
    }

    const trimmed = path.length > 1 ? path.replace(/\/+$/, '') : path;

    return trimmed === '' ? '/' : trimmed;
}

/** Is the current page the start page of the tab's place (D-5; Add: also its form with a chosen puzzle)? */
export function isStartPage(tabPlace, tabPath, currentPath) {
    const tab = normalizePath(tabPath);
    const current = normalizePath(currentPath);

    if (tab === null || current === null) {
        return false;
    }

    if (current === tab) {
        return true;
    }

    return tabPlace === ADD && tab !== '/' && current.startsWith(tab + '/');
}

/** The scroll's behavior: reduced motion never glides */
export function scrollBehavior(reducedMotion) {
    return reducedMotion ? 'instant' : 'smooth';
}

/**
 * facts: {menuOpen, tabPlace, currentPlace, tabPath, currentPath, scrollY, reducedMotion, nativeApp}
 * Returns {action} - and {behavior} with `scroll-top`.
 */
export function decide(facts = {}) {
    const { menuOpen, tabPlace, currentPlace, tabPath, currentPath, scrollY, reducedMotion, nativeApp } = facts || {};

    if (nativeApp) {
        return { action: NAVIGATE };
    }

    const samePlace = typeof tabPlace === 'string' && tabPlace !== '' && tabPlace === currentPlace;

    if (menuOpen) {
        return { action: samePlace ? CLOSE_MENU : NAVIGATE };
    }

    if (!samePlace || !isStartPage(tabPlace, tabPath, currentPath)) {
        return { action: NAVIGATE };
    }

    if (Number(scrollY) > 0) {
        return { action: SCROLL_TOP, behavior: scrollBehavior(Boolean(reducedMotion)) };
    }

    if (tabPlace === HOME) {
        return { action: REFRESH_FEED };
    }

    if (tabPlace === ADD) {
        return { action: NONE };
    }

    return { action: NAVIGATE };
}

/** The native-aware entry (tests/native-step-aside-harness.mjs): the decision with the app flag of `root` */
export function decideFor(root, facts = {}) {
    return decide({ ...facts, nativeApp: isNativeApp(root) });
}
