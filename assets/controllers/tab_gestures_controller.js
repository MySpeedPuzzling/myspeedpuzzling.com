import { Controller } from '@hotwired/stimulus';
import layers from '../layer_history.js';
import { isNativeApp } from '../platform.js';
import { CLOSE_MENU, decide, FEED_REFRESH_EVENT, NONE, REFRESH_FEED, SCROLL_TOP } from '../tab_reselect.js';
import { TOPBAR_REVEAL_EVENT } from '../topbar_scroll.js';

// The places a tap can reselect: the tab bar / rail's tabs (this element) and the docked Menu column's place rows (while
// the Menu sits in its slot - in the phone panel or the desktop overlay they are ordinary Menu rows)
const TAB = 'a[data-nav-place]';
const DOCK_ROW = '#menu-slot a[data-nav-place]';

// GEST-04: the bar's tabs that move the pill on tap
const BAR_TAB = 'a.nav-tab[data-nav-place]';
const CURRENT_TAB = 'a.nav-tab[data-nav-place][aria-current]';

/** A finger or pointer travelling further than this is a drag, not a tap: the pill goes back */
export const MOVE_TOLERANCE_PX = 10;

/** A release without a click within this long (dragged off, cancelled by the browser): the pill goes back */
const NO_CLICK_MS = 400;

const PENDING = 'is-pending';
const LEAVING = 'is-leaving';

/**
 * The bar's tab gestures (docs/features/navigation README §4.13; implementation plan GEST-03, GEST-04 - GEST-18 adds the
 * long-press label here). On #bottom-nav: the phone tab bar and, from 1024 px, the same element as the desktop rail;
 * the docked Menu column's place rows are handled through the same delegated click (they carry data-nav-place).
 *
 * GEST-03 - tap the active tab (assets/tab_reselect.js decides): a deeper page goes to the place's start page (the link
 * itself); the start page scrolled glides to the top (instant with reduced motion; `behavior` passed explicitly - <html>
 * has scroll-behavior: smooth) and asks the top bar back (`msp:topbar-reveal`); at the top of Home the shown Live feed
 * refreshes in place (`msp:feed-refresh`); at the top of Add nothing happens; while the Menu panel is open the current
 * place's tab only closes it (menu_layer_controller.js already did in its capture listener - here it only stays put).
 * The click listener sits on the document in the bubble phase: before Turbo's own on the window.
 *
 * GEST-04 - the pill moves on tap: a primary pointerdown on another tab marks it `.is-pending` and the current one
 * `.is-leaving` (CSS paints them like aria-current - _nav-bars.scss), long before Turbo answers. aria-current stays the
 * server's. The marks go back on pointercancel, a move over 10 px, a context menu, a release without a click, a click
 * that started no visit (a prevented or refused visit), turbo:fetch-request-error and a back-forward cache restore
 * (pageshow); a visit that arrives replaces the bar (not data-turbo-permanent), and a bar that comes back from Turbo's
 * snapshot cache drops them when it connects (never in turbo:before-cache: the page would show the pill jump back for a
 * frame before the next one renders).
 */
export default class extends Controller {
    connect() {
        this.pending = null;
        this.noClickTimer = null;
        this.visitStarted = false;

        this.element.addEventListener('pointerdown', this.onPointerDown);
        this.element.addEventListener('pointermove', this.onPointerMove, { passive: true });
        this.element.addEventListener('pointerup', this.onPointerUp);
        this.element.addEventListener('pointercancel', this.revert);
        this.element.addEventListener('contextmenu', this.revert);
        document.addEventListener('click', this.onClick);
        document.addEventListener('turbo:visit', this.onVisit);
        document.addEventListener('turbo:fetch-request-error', this.revert);
        window.addEventListener('pageshow', this.revert);

        // A bar restored from Turbo's snapshot cache (GEST-15) carries the marks it had when the page was left
        this.revert();
    }

    disconnect() {
        this.element.removeEventListener('pointerdown', this.onPointerDown);
        this.element.removeEventListener('pointermove', this.onPointerMove, { passive: true });
        this.element.removeEventListener('pointerup', this.onPointerUp);
        this.element.removeEventListener('pointercancel', this.revert);
        this.element.removeEventListener('contextmenu', this.revert);
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('turbo:visit', this.onVisit);
        document.removeEventListener('turbo:fetch-request-error', this.revert);
        window.removeEventListener('pageshow', this.revert);
        clearTimeout(this.noClickTimer);
        this.revert();
    }

    // ---- GEST-03 ------------------------------------------------------------------------------------------------------

    onClick = (event) => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target instanceof Element ? event.target.closest(TAB) : null;

        if (!link || !(this.element.contains(link) || link.matches(DOCK_ROW))) {
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

        const { action, behavior } = decide(this.facts(link));

        if (action === CLOSE_MENU) {
            // menu_layer_controller.js closes it in its capture listener; should it not have, the entry goes back here
            // (layer_history.requestClose() is a no-op while a close is under way)
            if (!event.defaultPrevented) {
                event.preventDefault();
                layers.requestClose('menu');
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

    facts(link) {
        const current = this.element.querySelector(CURRENT_TAB);
        let tabPath = null;

        try {
            tabPath = new URL(link.href, window.location.href).pathname;
        } catch (e) {
            tabPath = null;
        }

        return {
            menuOpen: layers.isOpen('menu') || document.querySelector('#menu-layer[open]:not(.is-closing)') !== null,
            tabPlace: link.getAttribute('data-nav-place'),
            currentPlace: current ? current.getAttribute('data-nav-place') : null,
            tabPath,
            currentPath: window.location.pathname,
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

        if (!tab || !this.element.contains(tab)) {
            return;
        }

        this.revert();

        const current = this.element.querySelector(CURRENT_TAB);

        // The current place's tab: nothing moves (GEST-03 decides what the tap does)
        if (tab === current) {
            return;
        }

        tab.classList.add(PENDING);

        if (current) {
            current.classList.add(LEAVING);
        }

        this.pending = { tab, current, pointerId: event.pointerId, x: event.clientX, y: event.clientY, clicked: false };
    };

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

    onVisit = () => {
        this.visitStarted = true;
    };

    /** The marks go back: the server's aria-current shows again */
    revert = () => {
        clearTimeout(this.noClickTimer);
        this.noClickTimer = null;
        this.pending = null;

        this.element.querySelectorAll(`.${PENDING}, .${LEAVING}`).forEach((tab) => {
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
