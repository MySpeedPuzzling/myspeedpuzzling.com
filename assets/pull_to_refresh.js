// Pull-to-refresh of the installed PWA (controllers/pwa_lifecycle_controller.js) - when a touch may become a pull, and
// when it is one. No DOM globals here: the controller hands in what it reads, tests/PullToRefreshGestureTest.php runs
// the same code under node.
//
// Only in the installed web app (docs/features/navigation README §4.13 G6, requirement GEST-06): browser tabs keep the
// browser's own pull-to-refresh and overscroll, the Hotwire Native app brings its own (README §4.14 (b)) - pullEnabled().
//
// A pull has to call preventDefault() on touchmove, and that cancels whatever the browser would have done with the
// finger. So it must never take a gesture that belongs to something else:
//  - nothing is armed while the page is scrolled, a modal / sheet / the site search is open, a layer holds a history
//    entry (assets/layer_history.js: the Menu panel, the player card, the phone search view, a sheet), or the finger
//    starts in an element that is scrolled itself (moving down there scrolls it back up), that scrolls sideways (a
//    table, a chip strip) or that says data-ptr-ignore (the bars, the section chips);
//  - one finger only, and the first few pixels decide the direction: only a clearly vertical downward move becomes a
//    pull - sideways and diagonal swipes are left to the browser from their first pixel to their last.

import { isNativeApp, isStandalone } from './platform.js';

// The movement that decides what a gesture is
export const DIRECTION_LOCK_DISTANCE = 8;

// Layers with their own scrolling over the page: while one is open, a finger moving down scrolls it, never the page. A
// released modal (the sent Help sheet, HELP-05: the page behind it is usable again) is not one - pull-to-refresh is
// armed again on the page, only a touch on the sheet itself stays the sheet's (review Q1)
// The open site search is <html data-search-open> (NAV-03; the phone search view also holds a layer entry).
export const OVERLAY_SELECTOR = '.modal.show:not(.is-released), .offcanvas.show, .offcanvas.showing, dialog[open], html[data-search-open]';
export const RELEASED_OVERLAY_SELECTOR = '.modal.show.is-released';

// The dynamic modal's id in assets/layer_history.js (controllers/dynamic_modal_controller.js): the released Help sheet
// keeps its history entry (Back still closes it) while the page behind it is usable again
export const MODAL_LAYER = 'modal';

/**
 * Is a layer open over the page for a touch starting on `target`?
 *
 * @param {Element|null} target where the finger landed
 * @param {{hasBodyClass: (name: string) => boolean, matches: (selector: string) => boolean, layer?: string|null}} page
 *        layer = the id of the layer holding the history entry (layer_history.js current()), null when none does
 */
export function overlayOpen(target, page) {
    if (page.hasBodyClass('modal-open') || page.hasBodyClass('global-search-shown') || page.matches(OVERLAY_SELECTOR)) {
        return true;
    }

    // A layer with a history entry covers the page (the Menu panel, the player card, the search view, the live results
    // views) - except the released Help sheet, which the selectors above already let through
    if (page.layer && !(page.layer === MODAL_LAYER && page.matches(RELEASED_OVERLAY_SELECTOR))) {
        return true;
    }

    return Boolean(target && typeof target.closest === 'function' && target.closest(RELEASED_OVERLAY_SELECTOR) !== null);
}

// How far the indicator follows the finger (GEST-06, README §4.13 G6): half of the finger's way, and the pull counts
// once the indicator has come PULL_TARGET px down - about 128 px of finger travel (it was about 256 px, twice what iOS and
// Android ask). Starting values: tuned on devices (QA-06).
export const DRAG_RATE = 0.5;
export const PULL_TARGET = 64;

/** How far the indicator follows a finger that travelled `distance` px down (px) */
export function pullOffset(distance) {
    return Math.max(0, distance) * DRAG_RATE;
}

/** Released here, does it refresh? ≈ 128 px of finger travel and more */
export function pastThreshold(distance) {
    return pullOffset(distance) >= PULL_TARGET;
}

/** 0…1 - how close the pull is to refreshing (the indicator's size, opacity and arrow) */
export function pullProgress(distance) {
    return Math.min(pullOffset(distance) / PULL_TARGET, 1);
}

/**
 * Is our pull-to-refresh on at all? In the installed web app only: a browser tab keeps the browser's own (and its
 * overscroll), the Hotwire Native app its own (NAV-14 (b)).
 *
 * @param {{standalone: boolean, nativeApp: boolean}} flags
 */
export function pullEnabled({ standalone, nativeApp }) {
    return Boolean(standalone) && !nativeApp;
}

/** pullEnabled() for a page's <html> (assets/platform.js reads the flags; `root` for node tests) */
export function pullEnabledFor(root) {
    return pullEnabled({ standalone: isStandalone(root), nativeApp: isNativeApp(root) });
}

// Vertical enough to be a pull: no more sideways than half of the way down (about 27° off vertical)
const MAX_SIDEWAYS_RATIO = 0.5;

/**
 * May a touch starting on `target` become a pull?
 *
 * @param {Element|null} target where the finger landed
 * @param {{scrollY: number, overlayOpen: boolean, styleOf: (element: Element) => {overflowX: string}}} page
 */
export function canStartPull(target, page) {
    if (page.scrollY > 0 || page.overlayOpen) {
        return false;
    }

    for (let element = target; element; element = element.parentElement) {
        if (element.tagName === 'BODY' || element.tagName === 'HTML') {
            break;
        }

        if (element.hasAttribute('data-ptr-ignore') || element.scrollTop > 0) {
            return false;
        }

        if (element.scrollWidth > element.clientWidth && ['auto', 'scroll'].includes(page.styleOf(element).overflowX)) {
            return false;
        }
    }

    return true;
}

/**
 * One touch, from touchstart to touchend. `move()` says whether that touchmove must be prevented (it is a pull).
 */
export class PullGesture {
    constructor() {
        this.state = 'idle';
        this.startX = 0;
        this.startY = 0;
        this.distance = 0;
    }

    /** `allowed` = canStartPull() for where the finger landed; a second finger (pinch) never pulls */
    start(x, y, allowed, touches = 1) {
        this.state = allowed && touches === 1 ? 'deciding' : 'idle';
        this.startX = x;
        this.startY = y;
        this.distance = 0;

        return this.state === 'deciding';
    }

    /**
     * @param {{scrollY: number, cancelable: boolean}} page
     * @returns {boolean} true = a pull: preventDefault() and show `distance`
     */
    move(x, y, page) {
        if (this.state === 'idle') {
            return false;
        }

        const dx = x - this.startX;
        const dy = y - this.startY;

        if (this.state === 'deciding') {
            if (Math.max(Math.abs(dx), Math.abs(dy)) < DIRECTION_LOCK_DISTANCE) {
                return false;
            }

            // A browser that already scrolls does not let go of the gesture (touchmove not cancelable)
            if (dy <= 0 || Math.abs(dx) > dy * MAX_SIDEWAYS_RATIO || page.scrollY > 0 || !page.cancelable) {
                this.state = 'idle';

                return false;
            }

            this.state = 'pulling';
        }

        // Back up past where it started, or the page scrolled after all: no longer a pull
        if (dy < 0 || page.scrollY > 0) {
            this.cancel();

            return false;
        }

        this.distance = dy;

        return true;
    }

    /** @returns {number} how far it was pulled (0 when it was not a pull) */
    end() {
        const distance = this.state === 'pulling' ? this.distance : 0;
        this.cancel();

        return distance;
    }

    cancel() {
        this.state = 'idle';
        this.distance = 0;
    }

    get pulling() {
        return this.state === 'pulling';
    }
}
