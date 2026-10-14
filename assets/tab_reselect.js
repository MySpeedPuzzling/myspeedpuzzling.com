import { isNativeApp } from './platform.js';

/**
 * Tap the active tab (docs/features/navigation README §4.13 G3, requirement GEST-03, implementation plan GEST-03):
 * the pure decision behind assets/controllers/tab_gestures_controller.js, run under node by
 * tests/tab-reselect-harness.mjs. Identical on iOS and Android.
 *
 * In this order:
 * 1. a layer holds the history entry (assets/layer_history.js current(): the Menu panel, the player card, a sheet - Help,
 *    an achievement, a result -, the phone search view): the current place's tab only closes it (`close-layer`, GEST
 *    review Q1 - the tap would otherwise act on the page hidden behind it); another tab navigates - the visit replaces
 *    the layer's history entry (GEST-01 (d));
 * 2. another place's tab: `navigate`;
 * 3. the same place on a deeper page: `navigate` - the tab's link is the place's start page;
 * 4. the start page scrolled: `scroll-top` - smooth, instant with reduced motion - and the top bar comes back;
 * 5. at the top of Home: `refresh-feed` - the Hub's feed refreshes in place (live_refresh_controller.js);
 *    at the top of Add: `none` - Add never reloads, a half-filled form survives;
 *    at the top of any other start page: `navigate` (a visit to the same URL).
 * `close-layer`, `scroll-top`, `refresh-feed` and `none` keep the link from navigating (preventDefault).
 *
 * The start page of a tab is the path of its current href (decision 58 D-5) - the Puzzles tab leads to the section used
 * last, so every section root counts (assets/puzzles_section.js points it there before the tap) - or the page whose
 * tab the server marked aria-current="page" (`startPage`: PlacesBuilder knows the four Puzzles section start pages
 * without the browser's storage, GEST review F4). Add also counts the add form with a chosen puzzle
 * (`…/puzzle-add/{puzzleId}`, where a puzzle page's Add leads), whose tab links the bare form, and every page showing
 * the solving-time form (`formShown`: the stopwatch's save form, the edit form - GEST review F3): those are the forms a
 * tap must never throw away.
 *
 * Inside the Hotwire Native app there is no web tab bar; the neutral answer is `navigate` (the link does what it says).
 */

export const CLOSE_LAYER = 'close-layer';
export const NAVIGATE = 'navigate';
export const SCROLL_TOP = 'scroll-top';
export const REFRESH_FEED = 'refresh-feed';
export const NONE = 'none';

/** Asks the shown Live feed of the page to refresh now (assets/controllers/live_refresh_controller.js), on document */
export const FEED_REFRESH_EVENT = 'msp:feed-refresh';

const HOME = 'home';
const ADD = 'add';
const PLACES = [HOME, 'puzzles', ADD, 'community', 'profile'];

/**
 * The place a link without data-nav-place stands for, from its data-nav (implementation plan §3.3:
 * <surface>/<place>/<id> - the top bar's + Add is topbar/add/add; the Menu's rows are menu/<group>/<id>, a place row's
 * id being the place or <place>_place, GEST review Q3). null for anything that names no place
 */
export function placeOfNav(nav) {
    const [surface, place, id] = typeof nav === 'string' ? nav.split('/') : [];
    const value = surface === 'menu' ? String(id || '').replace(/_place$/, '') : place;

    return PLACES.includes(value) ? value : null;
}

/** A path without its trailing slash (the root stays "/"); anything that is not a string is no path */
export function normalizePath(path) {
    if (typeof path !== 'string' || path === '') {
        return null;
    }

    const trimmed = path.length > 1 ? path.replace(/\/+$/, '') : path;

    return trimmed === '' ? '/' : trimmed;
}

/**
 * Is the current page the start page of the tab's place (D-5)? `startPage`: the server says so (its tab is
 * aria-current="page"); `formShown`: the page shows the solving-time form - Add's start page wherever it is
 */
export function isStartPage(tabPlace, tabPath, currentPath, { startPage = false, formShown = false } = {}) {
    if (startPage === true || (tabPlace === ADD && formShown === true)) {
        return true;
    }

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
 * facts: {layer, tabPlace, currentPlace, tabPath, currentPath, startPage, formShown, scrollY, reducedMotion, nativeApp}
 * - `layer`: the id of the layer holding the history entry, null when none does.
 * Returns {action} - and {behavior} with `scroll-top`, {layer} with `close-layer`.
 */
export function decide(facts = {}) {
    const { layer, tabPlace, currentPlace, tabPath, currentPath, startPage, formShown, scrollY, reducedMotion, nativeApp } = facts || {};

    if (nativeApp) {
        return { action: NAVIGATE };
    }

    const samePlace = typeof tabPlace === 'string' && tabPlace !== '' && tabPlace === currentPlace;

    if (typeof layer === 'string' && layer !== '') {
        return samePlace ? { action: CLOSE_LAYER, layer } : { action: NAVIGATE };
    }

    if (!samePlace || !isStartPage(tabPlace, tabPath, currentPath, { startPage, formShown })) {
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
