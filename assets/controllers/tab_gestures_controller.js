import { Controller } from '@hotwired/stimulus';
import layers from '../layer_history.js';
import { isNativeApp } from '../platform.js';
import { CLOSE_LAYER, decide, FEED_REFRESH_EVENT, NONE, placeOfNav, REFRESH_FEED, SCROLL_TOP } from '../tab_reselect.js';
import { TOPBAR_REVEAL_EVENT } from '../topbar_scroll.js';

// The links a tap can reselect a place with:
// - the tab bar / rail's tabs (this element) and the 768-1023 px top bar's places (GEST review F5): data-nav-place;
// - the top bar's + Add, from 768 px the only Add in the chrome (GEST review F1): the place of its data-nav;
// - the docked Menu column's place rows (while the Menu sits in its slot - in the phone panel or the desktop overlay they
//   are ordinary Menu rows): the place of their data-nav (no attribute of their own - the chrome's weight, GEST review Q3)
const RESELECT = 'a.nav-tab[data-nav-place], a.site-header-add, a.mr--place';
const COMPACT_BAR = 'header.site-header .site-header-places';
const TOP_ADD = 'header.site-header a.site-header-add';
const DOCK_ROW = '#menu-slot a.mr--place';

// GEST-04: the bars' tabs that move the pill on tap - the tab bar / rail's and the compact top bar's
const BAR_TAB = 'a.nav-tab[data-nav-place]';
const CURRENT_TAB = 'a.nav-tab[data-nav-place][aria-current]';
const MARKED = 'a.nav-tab.is-pending, a.nav-tab.is-leaving';

// Every page showing the solving-time form carries this controller on it (_solving_time_form.html.twig): the add form,
// the stopwatch's save form, the edit form - Add's start page wherever it is (GEST review F3)
const TIME_FORM = 'main form[data-controller~="first-try-check"]';

/** A finger or pointer travelling further than this is a drag, not a tap: the pill goes back */
export const MOVE_TOLERANCE_PX = 10;

/** A release without a click within this long (dragged off, cancelled by the browser): the pill goes back */
const NO_CLICK_MS = 400;

const PENDING = 'is-pending';
const LEAVING = 'is-leaving';

// While a mark is pending, the pointer is followed on the window (capture): a mouse is not captured to the tab like a
// finger is, so a press dragged off the 88 px rail and released on the page never reaches the bar (GEST review F6)
const FOLLOW = { capture: true };
const FOLLOW_PASSIVE = { capture: true, passive: true };

/**
 * The bars' tab gestures (docs/features/navigation README §4.13; implementation plan GEST-03, GEST-04 - GEST-18 adds the
 * long-press label here). On #bottom-nav: the phone tab bar and, from 1024 px, the same element as the desktop rail; the
 * 768-1023 px top bar's places, the top bar's + Add and the docked Menu column's place rows are handled through the same
 * delegated listeners on the document.
 *
 * GEST-03 - tap the active tab (assets/tab_reselect.js decides): a deeper page goes to the place's start page (the link
 * itself); the start page scrolled glides to the top (instant with reduced motion; `behavior` passed explicitly - <html>
 * has scroll-behavior: smooth) and asks the top bar back (`msp:topbar-reveal`); at the top of Home the shown Live feed
 * refreshes in place (`msp:feed-refresh`); at the top of Add nothing happens; while a layer holds the history entry (the
 * Menu panel, the player card, a sheet, the search view) the current place's tab only closes it (the Menu's own capture
 * listener, menu_layer_controller.js, already did - here it only stays put).
 * The click listener sits on the document in the bubble phase: before Turbo's own on the window.
 *
 * GEST-04 - the pill moves on tap: a primary pointerdown on another tab marks it `.is-pending` and the current one of
 * the same bar `.is-leaving` (CSS paints them like aria-current - _nav-bars.scss), long before Turbo answers.
 * aria-current stays the server's. The marks go back on pointercancel, a move over 10 px, a context menu, a release
 * without a click (followed on the window while pending, wherever the pointer went), a click that started no visit (a
 * prevented or refused visit), turbo:fetch-request-error and a back-forward cache restore (pageshow); a visit that
 * arrives replaces the bars (not data-turbo-permanent), and a bar that comes back from Turbo's snapshot cache drops
 * them when it connects (never in turbo:before-cache: the page would show the pill jump back for a frame before the
 * next one renders).
 */
export default class extends Controller {
    connect() {
        this.pending = null;
        this.noClickTimer = null;
        this.visitStarted = false;

        document.addEventListener('pointerdown', this.onPointerDown);
        document.addEventListener('click', this.onClick);
        document.addEventListener('turbo:visit', this.onVisit);
        document.addEventListener('turbo:fetch-request-error', this.revert);
        window.addEventListener('pageshow', this.revert);

        // A bar restored from Turbo's snapshot cache (GEST-15) carries the marks it had when the page was left
        this.revert();
    }

    disconnect() {
        document.removeEventListener('pointerdown', this.onPointerDown);
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('turbo:visit', this.onVisit);
        document.removeEventListener('turbo:fetch-request-error', this.revert);
        window.removeEventListener('pageshow', this.revert);
        this.revert();
    }

    // ---- GEST-03 ------------------------------------------------------------------------------------------------------

    onClick = (event) => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target instanceof Element ? event.target.closest(RESELECT) : null;
        const tabPlace = link ? this.placeOf(link) : null;

        if (tabPlace === null) {
            return;
        }

        if (this.pending && this.pending.tab === link) {
            this.pending.clicked = true;
            this.visitStarted = false;
            // The click goes on to Turbo (on the window, after this listener), which starts the visit at once: no visit by
            // the next task (somebody kept the click, a refused visit) = none is coming
            setTimeout(() => {
                if (this.pending && this.pending.tab === link && !this.visitStarted) {
                    this.revert();
                }
            }, 0);
        }

        const { action, behavior, layer } = decide(this.facts(link, tabPlace));

        if (action === CLOSE_LAYER) {
            // The Menu's capture listener closes it before this one; should nobody have, the layer's entry goes back here
            // (layer_history.requestClose() is a no-op while a close is under way)
            if (!event.defaultPrevented) {
                event.preventDefault();
                layers.requestClose(layer);
            }

            return;
        }

        if (event.defaultPrevented) {
            return;
        }

        if (action === SCROLL_TOP) {
            event.preventDefault();
            scrollToTop(behavior);
            window.dispatchEvent(new CustomEvent(TOPBAR_REVEAL_EVENT));
        } else if (action === REFRESH_FEED) {
            event.preventDefault();
            document.dispatchEvent(new CustomEvent(FEED_REFRESH_EVENT));
        } else if (action === NONE) {
            event.preventDefault();
        }
    };

    /** The place a link reselects - null for a link of no bar (a Menu row in the panel, a tab of another document) */
    placeOf(link) {
        if (link.matches(DOCK_ROW) || link.matches(TOP_ADD)) {
            return placeOfNav(link.getAttribute('data-nav'));
        }

        if (link.matches(BAR_TAB) && (this.element.contains(link) || link.closest(COMPACT_BAR) !== null)) {
            return link.getAttribute('data-nav-place');
        }

        return null;
    }

    facts(link, tabPlace) {
        // The bar knows the current place on every width: #bottom-nav is in the page as the tab bar, the rail, or hidden
        // under the 768-1023 px top bar
        const current = this.element.querySelector(CURRENT_TAB);
        let tabPath = null;

        try {
            tabPath = new URL(link.href, window.location.href).pathname;
        } catch (e) {
            tabPath = null;
        }

        return {
            layer: layers.current() || (document.querySelector('#menu-layer[open]:not(.is-closing)') !== null ? 'menu' : null),
            tabPlace,
            currentPlace: current ? current.getAttribute('data-nav-place') : null,
            tabPath,
            currentPath: window.location.pathname,
            startPage: current !== null && current.getAttribute('aria-current') === 'page',
            formShown: document.querySelector(TIME_FORM) !== null,
            scrollY: window.scrollY,
            reducedMotion: typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            nativeApp: isNativeApp(document.documentElement),
        };
    }

    // ---- GEST-04: the pending pill ------------------------------------------------------------------------------------

    onPointerDown = (event) => {
        if (!event.isPrimary || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        const tab = event.target instanceof Element ? event.target.closest(BAR_TAB) : null;
        const bar = tab ? this.barOf(tab) : null;

        if (bar === null) {
            return;
        }

        this.revert();

        const current = bar.querySelector(CURRENT_TAB);

        // The current place's tab: nothing moves (GEST-03 decides what the tap does)
        if (tab === current) {
            return;
        }

        tab.classList.add(PENDING);

        if (current) {
            current.classList.add(LEAVING);
        }

        this.pending = { tab, current, pointerId: event.pointerId, x: event.clientX, y: event.clientY, clicked: false };

        window.addEventListener('pointermove', this.onPointerMove, FOLLOW_PASSIVE);
        window.addEventListener('pointerup', this.onPointerUp, FOLLOW);
        window.addEventListener('pointercancel', this.onPointerCancel, FOLLOW);
        window.addEventListener('contextmenu', this.revert, FOLLOW);
    };

    /** The bar a tab belongs to (#bottom-nav or the 768-1023 px top bar's places), null for any other link */
    barOf(tab) {
        if (this.element.contains(tab)) {
            return this.element;
        }

        return tab.closest(COMPACT_BAR);
    }

    onPointerMove = (event) => {
        const pending = this.pending;

        if (!pending || event.pointerId !== pending.pointerId) {
            return;
        }

        if (Math.hypot(event.clientX - pending.x, event.clientY - pending.y) > MOVE_TOLERANCE_PX) {
            this.revert();
        }
    };

    onPointerUp = (event) => {
        const pending = this.pending;

        if (!pending || event.pointerId !== pending.pointerId) {
            return;
        }

        clearTimeout(this.noClickTimer);
        this.noClickTimer = setTimeout(() => {
            if (this.pending === pending && !pending.clicked) {
                this.revert();
            }
        }, NO_CLICK_MS);
    };

    onPointerCancel = (event) => {
        if (this.pending && event.pointerId === this.pending.pointerId) {
            this.revert();
        }
    };

    onVisit = () => {
        this.visitStarted = true;
    };

    /** The marks go back: the server's aria-current shows again */
    revert = () => {
        clearTimeout(this.noClickTimer);
        this.noClickTimer = null;
        this.pending = null;

        window.removeEventListener('pointermove', this.onPointerMove, FOLLOW_PASSIVE);
        window.removeEventListener('pointerup', this.onPointerUp, FOLLOW);
        window.removeEventListener('pointercancel', this.onPointerCancel, FOLLOW);
        window.removeEventListener('contextmenu', this.revert, FOLLOW);

        document.querySelectorAll(MARKED).forEach((tab) => {
            tab.classList.remove(PENDING, LEAVING);
        });
    };
}

/** To the top - `behavior` explicit (instant with reduced motion); a browser without the option jumps */
function scrollToTop(behavior) {
    try {
        window.scrollTo({ top: 0, left: window.scrollX, behavior });
    } catch (e) {
        window.scrollTo(0, 0);
    }
}
