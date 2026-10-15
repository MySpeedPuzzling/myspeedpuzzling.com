import { Controller } from '@hotwired/stimulus';
import { haptic } from '../haptics.js';
import layers from '../layer_history.js';
import { isNativeApp, os } from '../platform.js';
import { BUBBLE_ID, bubblePosition, CLICK_WINDOW_MS, isTruncated, LongPress, longPressEnabled, wantsTitle } from '../tab_long_press.js';
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

// While a mark is pending or a finger is held on a tab (GEST-18), the pointer is followed on the window (capture): a
// mouse is not captured to the tab like a finger is, so a press dragged off the 88 px rail and released on the page never
// reaches the bar (GEST review F6)
const FOLLOW = { capture: true };
const FOLLOW_PASSIVE = { capture: true, passive: true };

// GEST-18: what reads as "current" for NAV-02's step 3 (the server's aria-current, unless GEST-04 moved the pill away)
const CURRENT_LOOK = '[aria-current]:not(.is-leaving), .is-pending';
const LABEL = '.nav-tab-label';
// The title GEST-18 set itself (a tab may carry none of its own): removed again when the label fits
const OWN_TITLE = 'data-label-title';

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
 *
 * GEST-18 - long-press a cut tab (assets/tab_long_press.js decides): on an iPhone or an Android phone a finger held
 * 500 ms on a tab whose label is not fully shown (NAV-02's step 3, or a label wider than its box - read from
 * data-label-step / .nav-tab-label, the contract of `nav:labels-fitted`) shows the full label in a bubble 12 px above it
 * (one <span role="tooltip"> on <body>, the tab's aria-describedby while shown), Android feels `longPress`; the release
 * does not navigate (the click that may follow is dropped once), the bubble goes on release or after 1.5 s. A touch's
 * context menu (the link preview) never opens on a tab. Desktop and other devices: a cut label's text is the tab's
 * native `title`, set here (no markup per tab) whenever the bar fits its labels.
 */
export default class extends Controller {
    connect() {
        this.pending = null;
        this.noClickTimer = null;
        this.visitStarted = false;
        this.pressed = null;
        this.dropClick = null;
        this.longPress = new LongPress({ onShow: () => this.showBubble(), onHide: () => this.hideBubble() });

        document.addEventListener('pointerdown', this.onPointerDown);
        this.element.addEventListener('nav:labels-fitted', this.updateTitles);
        document.addEventListener('click', this.onClick);
        document.addEventListener('turbo:visit', this.onVisit);
        document.addEventListener('turbo:fetch-request-error', this.revert);
        document.addEventListener('turbo:before-cache', this.endLongPress);
        window.addEventListener('pageshow', this.revert);

        // A bar restored from Turbo's snapshot cache (GEST-15) carries the marks it had when the page was left
        this.revert();
        // bottom_nav_controller.js (connected first) fitted the labels before this listener existed
        this.updateTitles();
    }

    disconnect() {
        document.removeEventListener('pointerdown', this.onPointerDown);
        this.element.removeEventListener('nav:labels-fitted', this.updateTitles);
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('turbo:visit', this.onVisit);
        document.removeEventListener('turbo:fetch-request-error', this.revert);
        document.removeEventListener('turbo:before-cache', this.endLongPress);
        window.removeEventListener('pageshow', this.revert);
        clearTimeout(this.noClickTimer);
        this.endLongPress();
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

        // GEST-18: the release of a long press shows a label, it never navigates
        if (this.dropClick && this.dropClick.tab === link && performance.now() <= this.dropClick.until) {
            this.dropClick = null;
            event.preventDefault();
            this.revert();

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

        this.dropClick = null;
        this.revert();
        // The cut is read before the tab is marked pending below - a pending tab shows its label in step 3
        this.pressLongPress(tab, event);

        const current = bar.querySelector(CURRENT_TAB);

        // The current place's tab: nothing moves (GEST-03 decides what the tap does) - a held finger is still followed
        if (tab === current) {
            this.follow();

            return;
        }

        tab.classList.add(PENDING);

        if (current) {
            current.classList.add(LEAVING);
        }

        this.pending = { tab, current, pointerId: event.pointerId, x: event.clientX, y: event.clientY, clicked: false };
        this.follow();
    };

    /** The pointer is followed on the window while a mark is pending or a finger is held (adding twice adds once) */
    follow() {
        if (!this.pending && !this.pressed) {
            return;
        }

        window.addEventListener('pointermove', this.onPointerMove, FOLLOW_PASSIVE);
        window.addEventListener('pointerup', this.onPointerUp, FOLLOW);
        window.addEventListener('pointercancel', this.onPointerCancel, FOLLOW);
        window.addEventListener('contextmenu', this.onContextMenu, FOLLOW);
    }

    /** ... and no longer once neither is */
    unfollow() {
        if (this.pending || this.pressed) {
            return;
        }

        window.removeEventListener('pointermove', this.onPointerMove, FOLLOW_PASSIVE);
        window.removeEventListener('pointerup', this.onPointerUp, FOLLOW);
        window.removeEventListener('pointercancel', this.onPointerCancel, FOLLOW);
        window.removeEventListener('contextmenu', this.onContextMenu, FOLLOW);
    }

    /** The bar a tab belongs to (#bottom-nav or the 768-1023 px top bar's places), null for any other link */
    barOf(tab) {
        if (this.element.contains(tab)) {
            return this.element;
        }

        return tab.closest(COMPACT_BAR);
    }

    onPointerMove = (event) => {
        if (this.pressed && event.pointerId === this.pressed.pointerId) {
            this.longPress.move({ x: event.clientX, y: event.clientY });
        }

        const pending = this.pending;

        if (!pending || event.pointerId !== pending.pointerId) {
            return;
        }

        if (Math.hypot(event.clientX - pending.x, event.clientY - pending.y) > MOVE_TOLERANCE_PX) {
            this.revert();
        }
    };

    onPointerUp = (event) => {
        if (this.pressed && event.pointerId === this.pressed.pointerId) {
            const tab = this.pressed.tab;
            this.pressed = null;

            if (this.longPress.up()) {
                this.dropClick = { tab, until: performance.now() + CLICK_WINDOW_MS };
            }
        }

        const pending = this.pending;

        if (!pending || event.pointerId !== pending.pointerId) {
            this.unfollow();

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

    onPointerCancel = (event) => {
        if (this.pressed && event.pointerId === this.pressed.pointerId) {
            this.pressed = null;
            this.longPress.cancel();
        }

        if (this.pending && event.pointerId === this.pending.pointerId) {
            this.revert();
        }

        this.unfollow();
    };

    /**
     * A touch's context menu (Android's link preview, iOS's callout - also off in CSS) never opens on a tab: the long
     * press is the label's. A mouse's right click keeps its menu (open in a new tab)
     */
    onContextMenu = (event) => {
        const tab = event.target instanceof Element ? event.target.closest(BAR_TAB) : null;

        if (tab && this.pressed && this.pressed.tab === tab) {
            event.preventDefault();
        }

        this.revert();
    };

    // ---- GEST-18: long-press a cut tab ---------------------------------------------------------------------------------

    /** A primary touch on a tab: starts the hold when this device shows bubbles and the tab's label is cut */
    pressLongPress(tab, event) {
        this.endLongPress();

        if (!longPressEnabled({ os: os(document.documentElement), nativeApp: isNativeApp(document.documentElement), pointerType: event.pointerType })) {
            return;
        }

        // Every touch on a tab is followed (its context menu is the label's); the cut is read before GEST-04 marks the
        // tab pending below - a pending tab shows its label in step 3
        this.pressed = { tab, pointerId: event.pointerId, label: this.labelText(tab) };

        if (!this.labelCut(tab) || this.pressed.label === '') {
            this.pressed.label = null;

            return;
        }

        this.longPress.down({ x: event.clientX, y: event.clientY });
    }

    labelCut(tab) {
        const label = tab.querySelector(LABEL);

        if (!label) {
            return false;
        }

        return isTruncated({
            step: this.element.getAttribute('data-label-step-override') || this.element.getAttribute('data-label-step'),
            // A bar without a current place keeps every label at step 3 (_nav-bars.scss, QA-04 R05): each counts as shown
            current: tab.matches(CURRENT_LOOK) || this.element.querySelector('.nav-tab[aria-current]') === null,
            scrollWidth: label.scrollWidth,
            clientWidth: label.clientWidth,
        });
    }

    labelText(tab) {
        const label = tab.querySelector(LABEL);

        return label ? label.textContent.trim() : '';
    }

    showBubble() {
        const pressed = this.pressed;

        if (!pressed || !pressed.label || !pressed.tab.isConnected) {
            return;
        }

        let bubble = document.getElementById(BUBBLE_ID);

        if (!bubble) {
            bubble = document.createElement('span');
            bubble.id = BUBBLE_ID;
            bubble.className = 'nav-tab-bubble';
            bubble.setAttribute('role', 'tooltip');
            document.body.appendChild(bubble);
        }

        bubble.textContent = pressed.label;
        bubble.hidden = false;

        const tabBox = pressed.tab.getBoundingClientRect();
        const { left, top } = bubblePosition({
            tab: { left: tabBox.left, top: tabBox.top, width: tabBox.width },
            bubble: { width: bubble.offsetWidth, height: bubble.offsetHeight },
            viewportWidth: document.documentElement.clientWidth || window.innerWidth,
        });
        bubble.style.left = `${left}px`;
        bubble.style.top = `${top}px`;

        pressed.tab.setAttribute('aria-describedby', BUBBLE_ID);
        this.bubbleTab = pressed.tab;
        haptic('longPress');
    }

    hideBubble() {
        const bubble = document.getElementById(BUBBLE_ID);

        if (bubble) {
            bubble.hidden = true;
        }

        if (this.bubbleTab) {
            if (this.bubbleTab.getAttribute('aria-describedby') === BUBBLE_ID) {
                this.bubbleTab.removeAttribute('aria-describedby');
            }

            this.bubbleTab = null;
        }
    }

    /** No bubble left behind: a cached snapshot (turbo:before-cache) or a bar that goes */
    endLongPress = () => {
        this.pressed = null;

        if (this.longPress) {
            this.longPress.cancel();
        }

        this.hideBubble();
        document.getElementById(BUBBLE_ID)?.remove();
        this.unfollow();
    };

    /**
     * Desktop and other devices: the tab's native `title` while its label is cut (README §4.13 G16) - after every fit of
     * the labels (`nav:labels-fitted`, bottom_nav_controller.js). Phones get the bubble instead.
     */
    updateTitles = () => {
        const device = os(document.documentElement);
        const nativeApp = isNativeApp(document.documentElement);
        // Phones and the app never get one: nothing to measure there
        const titles = wantsTitle({ os: device, nativeApp, truncated: true });

        this.element.querySelectorAll(BAR_TAB).forEach((tab) => {
            const text = this.labelText(tab);
            const own = tab.hasAttribute(OWN_TITLE);

            if (titles && text !== '' && wantsTitle({ os: device, nativeApp, truncated: this.labelCut(tab) })) {
                if (own || !tab.hasAttribute('title')) {
                    tab.setAttribute('title', text);
                    tab.setAttribute(OWN_TITLE, '');
                }
            } else if (own) {
                tab.removeAttribute('title');
                tab.removeAttribute(OWN_TITLE);
            }
        });
    };

    /** The marks go back: the server's aria-current shows again */
    revert = () => {
        clearTimeout(this.noClickTimer);
        this.noClickTimer = null;
        this.pending = null;
        this.unfollow();

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
