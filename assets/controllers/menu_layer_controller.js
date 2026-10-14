import { Controller } from '@hotwired/stimulus';
import layers from '../layer_history.js';
import { BEFORE_CACHE_EVENT } from '../instant_back.js';
import { applyMenuOrder, DOCK, PHONE } from '../navigation/menu_order.js';
import { currentMenuSurface, listenForMenuLinks, menuFragmentOf, menuPaths, revealInMenu } from '../navigation/menu_links.js';
import { focusDockedMenu, toggleNavLayout } from '../navigation/nav_layout.js';
import { MENU_OPEN_EVENT } from '../nav_instrumentation.js';

// The layer's id in assets/layer_history.js
const LAYER = 'menu';

// The way out: 200 ms (README §4.12, §4.3); the timer closes it even when no animationend comes (hidden tab)
const OUT_MS = 200;

// The two ways the shell shows the Menu: the phone panel (show(), MENU-02b) and the desktop overlay (showModal(), NAV-13)
const PANEL = 'panel';
const OVERLAY = 'overlay';
const OVERLAY_CLASS = 'menu-layer--overlay';

// The screen changes under an open shell (a window resized across a tier): it closes - the other surface takes over
const TIER_QUERIES = [
    '(max-width: 767.98px), (max-height: 500.98px) and (pointer: coarse)',
    '(min-width: 1280px) and (min-height: 501px), (min-width: 1280px) and (pointer: fine)',
    '(min-width: 1440px) and (min-height: 501px), (min-width: 1440px) and (pointer: fine)',
];

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';

// Asks the page's (bar) search to close (global_search_controller.js): the panel is the one search surface while open
const CLOSE_BAR_SEARCH_EVENT = 'global-search:close';

// The panel hands its opener over when a modal takes its history entry (dynamic_modal_controller.js: focus goes back to
// whatever opened the Menu when that modal closes - review F7)
const MENU_HANDOVER_EVENT = 'menu-layer:handover';

// Direct children of <body> that stay usable while the panel is open: the bottom bar (README §4.12), the toasts (a
// live region) and the dynamic modal, which takes the layer over (decision 58 D-1) and is display: none until then
const KEEP_USABLE = '#bottom-nav, #toast-container, #dynamic-modal-container, script, template, style, link, noscript';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

/**
 * The Menu's phone panel (docs/features/navigation README §4.12 "Phone panel", MENU-02b) and desktop overlay (README §4.3,
 * NAV-13): the shell <dialog id="menu-layer"> at the end of <body> (templates/navigation/_menu_layer.html.twig). Opening
 * moves the page's one #site-menu from its docked-column slot into the shell - in phone order for the panel
 * (assets/navigation/menu_order.js), in dock order for the overlay; closing moves it back in dock order - there is never
 * a second copy.
 *
 * - Opened by links to the `menu` page and the ☰ (assets/navigation/menu_links.js decides) - never inside the app. On
 *   desktop the same listener docks/folds the column from 1280 px (the ☰) and focuses the docked column's first row (a
 *   link to `menu` where it is docked; assets/navigation/nav_layout.js).
 * - The desktop overlay (768-1279 px, and from a link in the rail state): showModal() - there is no bottom bar to keep
 *   usable, the browser makes the page inert and keeps the focus in it (Tab cycles through the overlay); a 320 px sheet
 *   from the left with its own 64 px header (× and the logo), a scrim (a click on it closes), the Menu as in the docked
 *   column; focus on the first row, back to the ☰ when it closes; 200 ms slide, none with reduced motion. Esc, the scrim,
 *   × and Back (an Android tablet's back gesture) close it through assets/layer_history.js (bindDialog()).
 * - One same-URL history entry through assets/layer_history.js (GEST-01): Back, the iOS edge swipe and the Android
 *   back gesture close it; × and Esc close it through that Back. A visit from inside it (a row, a tab of the bottom
 *   bar) replaces its entry; a dynamic modal opened from it (Send feedback → the Help sheet) takes the entry over and
 *   the panel closes at once (decision 58 D-1).
 * - The panel: dialog.show(), never showModal(): the bottom bar stays usable under it. While open, everything else is
 *   inert (header, main, footer and every other layer - not the bottom bar), focus cycles through the panel and the
 *   bottom bar, and <html> does not scroll (CSS: :root:has(> body > .menu-layer[open]), so no exit path can leave the
 *   page locked).
 * - A tap on the current place's tab only closes it. The lazy counts (menu_counts) are asked on every open; each open
 *   sends the P0-03 `menu_open` beacon.
 */
export default class extends Controller {
    static targets = ['body', 'close'];
    static values = { place: String };

    connect() {
        this.shown = false;
        this.released = false;
        this.opener = null;
        this.inerted = [];
        this.closingTimer = null;
        this.focusAfterClose = null;

        this.mode = PANEL;

        this.paths = menuPaths(this.element.getAttribute('data-menu-paths'));
        this.unlisten = listenForMenuLinks({
            document,
            window,
            paths: this.paths,
            open: (opener, action) => this.act(action, opener),
        });
        // Registered before the helper's listeners: the close events of our own dialog.close() never reach them (a
        // reopen during the closing slide would otherwise ask the new entry to go back)
        this.ownCloses = 0;
        this.element.addEventListener('close', this.handleOwnClose, { capture: true });
        this.unbindDialog = layers.bindDialog(this.element, LAYER);
        this.tierQueries = typeof window.matchMedia === 'function' ? TIER_QUERIES.map((query) => window.matchMedia(query)) : [];
        this.tierQueries.forEach((query) => query.addEventListener('change', this.handleTierChange));

        document.addEventListener('keydown', this.handleTab);
        document.addEventListener('click', this.handleTabBarClick, true);
        document.addEventListener(BEFORE_CACHE_EVENT, this.handleBeforeCache);
        document.addEventListener('turbo:load', this.handleLoad);
        this.element.addEventListener('cancel', this.handleCancel);
        this.element.addEventListener('click', this.handleScrimClick);
        this.element.addEventListener('animationend', this.handleAnimationEnd);
    }

    disconnect() {
        if (this.unlisten) {
            this.unlisten();
            this.unlisten = null;
        }

        if (this.unbindDialog) {
            this.unbindDialog();
            this.unbindDialog = null;
        }

        this.tierQueries.forEach((query) => query.removeEventListener('change', this.handleTierChange));
        this.tierQueries = [];

        document.removeEventListener('keydown', this.handleTab);
        document.removeEventListener('click', this.handleTabBarClick, true);
        document.removeEventListener(BEFORE_CACHE_EVENT, this.handleBeforeCache);
        document.removeEventListener('turbo:load', this.handleLoad);
        this.element.removeEventListener('close', this.handleOwnClose, { capture: true });
        this.element.removeEventListener('cancel', this.handleCancel);
        this.element.removeEventListener('click', this.handleScrimClick);
        this.element.removeEventListener('animationend', this.handleAnimationEnd);
        clearTimeout(this.closingTimer);

        // Still on the page (the controller itself went away): never leave the page inert
        if (this.element.isConnected && (this.shown || this.element.open)) {
            this.closeUi('cache');
        }
    }

    /** What menu_links.js decided for a click on a link to `menu` or the ☰ (true: taken care of) */
    act(action, opener) {
        if (action === 'toggle') {
            toggleNavLayout(window, document);

            return true;
        }

        if (action === 'focus') {
            return focusDockedMenu(window, document, this.fragmentOf(opener));
        }

        return this.toggle(opener, action === 'overlay' ? OVERLAY : PANEL);
    }

    /** A link to `menu` or the ☰: opens the panel (phones) or the overlay (desktop), or closes it when it is open */
    toggle(opener, mode = PANEL) {
        if (this.shown) {
            this.close();

            return true;
        }

        return this.open(opener, mode);
    }

    open(opener = null, mode = PANEL) {
        const nav = document.getElementById('site-menu');

        // No Menu on this page, or it is the `menu` page's content: the link goes to the page
        if (!nav || nav.closest('main')) {
            return false;
        }

        if (this.closingTimer !== null) {
            this.finishClose();
        }

        this.opener = opener;
        this.released = false;

        // One search surface at a time: the page's search dropdown closes under the panel (review F11)
        if (document.body.classList.contains('global-search-shown')) {
            document.dispatchEvent(new CustomEvent(CLOSE_BAR_SEARCH_EVENT));
        }

        layers.open(LAYER, { element: this.element, onClose: this.onLayerClose });

        this.mode = mode;
        this.bodyTarget.appendChild(nav);
        this.element.classList.remove('is-closing');

        // showModal() throws on a dialog that is open already
        if (this.element.open) {
            this.closeDialog();
        }

        if (mode === OVERLAY) {
            // Desktop (NAV-13): the Menu as in the docked column, a modal sheet from the left - the browser makes the
            // page inert and puts the overlay above everything (the top layer)
            applyMenuOrder(nav, DOCK);
            this.element.classList.add(OVERLAY_CLASS);
            this.element.showModal();
        } else {
            applyMenuOrder(nav, PHONE);
            this.element.classList.remove(OVERLAY_CLASS);
            this.element.show();
            this.setInert(true);
        }

        // Shown now (a display: none body cannot scroll): every open starts at the top
        this.bodyTarget.scrollTop = 0;
        this.shown = true;
        this.setExpanded(true);
        this.focusFirst();

        // A link to a place inside the Menu (`menu#nav-language`, the Language result of a search): open it there
        const fragment = this.fragmentOf(opener);

        if (fragment) {
            revealInMenu(nav, fragment);
        }

        // The lazy counts, fresh on every open (assets/navigation/menu_counts.js, MENU-01)
        nav.dispatchEvent(new CustomEvent('menu-counts:refresh', { detail: { maxAge: 0 } }));
        // P0-03 (assets/nav_instrumentation.js; not started inside the app)
        document.dispatchEvent(new CustomEvent(MENU_OPEN_EVENT, { detail: { surface: mode, place: this.placeValue || 'none' } }));

        return true;
    }

    /** The id a link to `menu#…` names inside the Menu, or null */
    fragmentOf(opener) {
        return opener && typeof opener.getAttribute === 'function' ? menuFragmentOf(opener.getAttribute('href'), this.paths, window.location.href) : null;
    }

    /** ×: through Back when the panel holds its history entry, else at once */
    close() {
        if (layers.isOpen(LAYER)) {
            layers.requestClose(LAYER);

            return;
        }

        this.closeUi('close');
    }

    onLayerClose = ({ reason }) => {
        if (reason === 'leave') {
            // A visit leaves from the panel (a row, a tab): it stays on screen until the new page replaces it - the page
            // under it is usable again, nothing is left inert if that page never comes (handleLoad)
            this.released = true;
            this.setInert(false);

            return;
        }

        this.closeUi(reason);
    };

    /**
     * Closes the panel's UI. back/close animate out (200 ms) and give focus back to the opener; swap (a modal takes the
     * entry over) and cache (Turbo's snapshot, a page render) close at once.
     */
    closeUi(reason) {
        if (!this.shown && !this.element.open) {
            return;
        }

        const opener = this.opener;
        this.shown = false;
        this.released = false;
        this.opener = null;

        this.setInert(false);
        this.setExpanded(false);

        // A modal took the entry over (Send feedback → the Help sheet): when it closes, focus goes back to whatever
        // opened the Menu - the row it was opened from is back in the hidden slot by then (review F7)
        if (reason === 'swap') {
            this.element.dispatchEvent(new CustomEvent(MENU_HANDOVER_EVENT, { bubbles: true, detail: { opener: opener && opener.isConnected ? opener : null } }));
        }

        if (reason === 'back' || reason === 'close') {
            if (this.mode === OVERLAY) {
                // The page stays inert while the modal overlay slides out: the focus goes back once it is closed. No
                // animation under reduced motion (README §4.3): it goes at once
                this.focusAfterClose = opener || true;

                if (typeof window.matchMedia === 'function' && window.matchMedia(REDUCED_MOTION).matches) {
                    this.finishClose();

                    return;
                }
            } else {
                this.focusOpener(opener);
            }

            this.element.classList.add('is-closing');
            clearTimeout(this.closingTimer);
            this.closingTimer = setTimeout(() => this.finishClose(), OUT_MS + 50);

            return;
        }

        this.finishClose();
    }

    /** The panel is gone: the shell closes (display: none) and #site-menu goes back to its slot in dock order */
    finishClose() {
        clearTimeout(this.closingTimer);
        this.closingTimer = null;

        if (this.element.open) {
            this.closeDialog();
        }

        this.element.classList.remove('is-closing', OVERLAY_CLASS);
        this.mode = PANEL;

        if (this.focusAfterClose) {
            const opener = this.focusAfterClose === true ? null : this.focusAfterClose;
            this.focusAfterClose = null;
            this.focusOpener(opener);
        }

        const nav = this.bodyTarget.querySelector('#site-menu');
        const slot = document.getElementById('menu-slot');

        if (nav) {
            applyMenuOrder(nav, DOCK);

            if (slot) {
                slot.appendChild(nav);
            }
        }
    }

    /** dialog.close() of our own: its close event is ours, not a close request for the helper */
    closeDialog() {
        this.ownCloses += 1;
        this.element.close();
    }

    handleOwnClose = (event) => {
        if (event.target === this.element && this.ownCloses > 0) {
            this.ownCloses -= 1;
            event.stopImmediatePropagation();
        }
    };

    handleAnimationEnd = (event) => {
        if (event.target === this.element && this.element.classList.contains('is-closing')) {
            this.finishClose();
        }
    };

    /** Esc on the open shell: never the browser's own close (layer_history.bindDialog() asks for Back) */
    handleCancel = (event) => {
        event.preventDefault();

        if (this.shown && !layers.isOpen(LAYER)) {
            this.close();
        }
    };

    /** The overlay's scrim: a click outside the sheet lands on the <dialog> itself (its ::backdrop) */
    handleScrimClick = (event) => {
        if (this.mode !== OVERLAY || !this.shown || event.target !== this.element) {
            return;
        }

        const box = this.element.getBoundingClientRect();
        const inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;

        if (!inside) {
            this.close();
        }
    };

    /** The window crossed a tier while the shell is open: it closes (the column, the rail or the panel takes over) */
    handleTierChange = () => {
        if (!this.shown || this.released) {
            return;
        }

        const surface = currentMenuSurface(window, document);
        const fits = this.mode === OVERLAY ? (surface === 'overlay' || surface === 'rail') : surface === 'phone';

        if (!fits) {
            this.close();
        }
    };

    /**
     * Turbo caches the page (GEST-15, `msp:before-cache` of assets/instant_back.js - after a view transition captured it):
     * the Menu goes back to its slot first (a restored page has no open panel)
     */
    handleBeforeCache = () => {
        if (!this.shown && !this.element.open) {
            return;
        }

        layers.closeForCache();
        this.closeUi('cache');
    };

    /** A visit left from the panel, but this page is still here (nothing replaced it): close what is left */
    handleLoad = () => {
        if (this.released && this.element.isConnected) {
            this.closeUi('cache');
        }
    };

    /** While open, a tap on the current place's tab only closes the panel (the other tabs leave, GEST-01 (d)) */
    handleTabBarClick = (event) => {
        if (!this.shown || this.released || event.defaultPrevented || event.button > 0) {
            return;
        }

        const tab = event.target && typeof event.target.closest === 'function' ? event.target.closest('#bottom-nav a[data-nav-place]') : null;

        if (!tab || !this.placeValue || this.placeValue === 'none' || tab.getAttribute('data-nav-place') !== this.placeValue) {
            return;
        }

        event.preventDefault();
        this.close();
    };

    /** Tab and Shift+Tab cycle through the panel and the bottom bar (README §4.12) - through the overlay alone (§4.3) */
    handleTab = (event) => {
        if (event.key !== 'Tab' || !this.shown || this.released || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const cycle = this.mode === OVERLAY
            ? this.focusables(this.element)
            : [...this.focusables(this.element), ...this.focusables(document.getElementById('bottom-nav'))];

        if (cycle.length === 0) {
            return;
        }

        const at = cycle.indexOf(document.activeElement);
        const next = at === -1
            ? (event.shiftKey ? cycle.length - 1 : 0)
            : (at + (event.shiftKey ? -1 : 1) + cycle.length) % cycle.length;

        event.preventDefault();
        cycle[next].focus();
    };

    focusables(root) {
        if (!root) {
            return [];
        }

        return Array.from(root.querySelectorAll(FOCUSABLE)).filter((element) => element.getClientRects().length > 0 && !element.closest('[inert]'));
    }

    /** The panel: its × first (MENU-02b); the overlay: the Menu's first row (README §4.3) */
    focusFirst() {
        const nav = this.mode === OVERLAY ? this.bodyTarget.querySelector('#site-menu') : null;
        const row = nav ? this.focusables(nav)[0] : null;
        const first = row || (this.hasCloseTarget ? this.closeTarget : this.focusables(this.element)[0]);

        if (first) {
            first.focus({ preventScroll: true });
        }
    }

    /**
     * Focus back to what opened the Menu - while it is still shown; else to the shown ☰ (the top bar renders one for
     * desktop and one for phones, NAV-04 - CSS shows one of them)
     */
    focusOpener(opener) {
        const shown = (element) => element && element.isConnected && element.getClientRects().length > 0;
        const target = shown(opener) ? opener : Array.from(document.querySelectorAll('[data-bar-action="menu"]')).find(shown);

        if (target && typeof target.focus === 'function') {
            target.focus({ preventScroll: true });
        }
    }

    setInert(on) {
        if (!on) {
            this.inerted.forEach((element) => {
                element.inert = false;
            });
            this.inerted = [];

            return;
        }

        Array.from(document.body.children).forEach((element) => {
            if (element === this.element || element.matches(KEEP_USABLE) || element.inert) {
                return;
            }

            element.inert = true;
            this.inerted.push(element);
        });
    }

    setExpanded(open) {
        document.querySelectorAll('[data-bar-action="menu"]').forEach((element) => {
            element.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
}
