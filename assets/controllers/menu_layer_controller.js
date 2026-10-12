import { Controller } from '@hotwired/stimulus';
import layers from '../layer_history.js';
import { applyMenuOrder, DOCK, PHONE } from '../navigation/menu_order.js';
import { listenForMenuLinks, menuPaths } from '../navigation/menu_links.js';
import { MENU_OPEN_EVENT } from '../nav_instrumentation.js';

// The layer's id in assets/layer_history.js
const LAYER = 'menu';

// The way out: 200 ms (README §4.12); the timer closes it even when no animationend comes (reduced motion, hidden tab)
const OUT_MS = 200;

// Direct children of <body> that stay usable while the panel is open: the bottom bar (README §4.12), the toasts (a
// live region) and the dynamic modal, which takes the layer over (decision 58 D-1) and is display: none until then
const KEEP_USABLE = '#bottom-nav, #toast-container, #dynamic-modal-container, script, template, style, link, noscript';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

/**
 * The Menu's phone panel (docs/features/navigation README §4.12 "Phone panel", MENU-02b): the shell
 * <dialog id="menu-layer"> at the end of <body> (templates/navigation/_menu_layer.html.twig). Opening moves the page's
 * one #site-menu from its docked-column slot into the shell, in phone order (assets/navigation/menu_order.js); closing
 * moves it back in dock order - there is never a second copy.
 *
 * - Opened by links to the `menu` page and the ☰ (assets/navigation/menu_links.js) on phones - never inside the app.
 * - One same-URL history entry through assets/layer_history.js (GEST-01): Back, the iOS edge swipe and the Android
 *   back gesture close it; × and Esc close it through that Back. A visit from inside it (a row, a tab of the bottom
 *   bar) replaces its entry; a dynamic modal opened from it (Send feedback → the Help sheet) takes the entry over and
 *   the panel closes at once (decision 58 D-1).
 * - dialog.show(), never showModal(): the bottom bar stays usable under it. While open, everything else on the page is
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

        this.unlisten = listenForMenuLinks({
            document,
            window,
            paths: menuPaths(this.element.getAttribute('data-menu-paths')),
            open: (opener) => this.toggle(opener),
        });

        document.addEventListener('keydown', this.handleTab);
        document.addEventListener('click', this.handleTabBarClick, true);
        document.addEventListener('turbo:before-cache', this.handleBeforeCache);
        document.addEventListener('turbo:load', this.handleLoad);
        this.element.addEventListener('cancel', this.handleCancel);
        this.element.addEventListener('animationend', this.handleAnimationEnd);
    }

    disconnect() {
        if (this.unlisten) {
            this.unlisten();
            this.unlisten = null;
        }

        document.removeEventListener('keydown', this.handleTab);
        document.removeEventListener('click', this.handleTabBarClick, true);
        document.removeEventListener('turbo:before-cache', this.handleBeforeCache);
        document.removeEventListener('turbo:load', this.handleLoad);
        this.element.removeEventListener('cancel', this.handleCancel);
        this.element.removeEventListener('animationend', this.handleAnimationEnd);
        clearTimeout(this.closingTimer);

        // Still on the page (the controller itself went away): never leave the page inert
        if (this.element.isConnected && (this.shown || this.element.open)) {
            this.closeUi('cache');
        }
    }

    /** A link to `menu` or the ☰ on a phone: opens the panel, or closes it when it is open */
    toggle(opener) {
        if (this.shown) {
            this.close();

            return true;
        }

        return this.open(opener);
    }

    open(opener = null) {
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

        layers.open(LAYER, { element: this.element, onClose: this.onLayerClose });

        this.bodyTarget.appendChild(nav);
        applyMenuOrder(nav, PHONE);
        this.element.classList.remove('is-closing');
        this.element.show();
        this.shown = true;
        this.bodyTarget.scrollTop = 0;

        this.setInert(true);
        this.setExpanded(true);
        this.focusFirst();

        // The lazy counts, fresh on every open (assets/navigation/menu_counts.js, MENU-01)
        nav.dispatchEvent(new CustomEvent('menu-counts:refresh', { detail: { maxAge: 0 } }));
        // P0-03 (assets/nav_instrumentation.js; not started inside the app)
        document.dispatchEvent(new CustomEvent(MENU_OPEN_EVENT, { detail: { surface: 'panel', place: this.placeValue || 'none' } }));

        return true;
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

        if (reason === 'back' || reason === 'close') {
            this.focusOpener(opener);
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
            this.element.close();
        }

        this.element.classList.remove('is-closing');

        const nav = this.bodyTarget.querySelector('#site-menu');
        const slot = document.getElementById('menu-slot');

        if (nav) {
            applyMenuOrder(nav, DOCK);

            if (slot) {
                slot.appendChild(nav);
            }
        }
    }

    handleAnimationEnd = (event) => {
        if (event.target === this.element && this.element.classList.contains('is-closing')) {
            this.finishClose();
        }
    };

    handleCancel = (event) => {
        event.preventDefault();

        if (this.shown) {
            this.close();
        }
    };

    /** Turbo caches the page: the Menu goes back to its slot first (a restored page has no open panel) */
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

    /** Tab and Shift+Tab cycle through the panel and the bottom bar (README §4.12) */
    handleTab = (event) => {
        if (event.key !== 'Tab' || !this.shown || this.released || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const cycle = [...this.focusables(this.element), ...this.focusables(document.getElementById('bottom-nav'))];

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

    focusFirst() {
        const first = this.hasCloseTarget ? this.closeTarget : this.focusables(this.element)[0];

        if (first) {
            first.focus({ preventScroll: true });
        }
    }

    focusOpener(opener) {
        const target = opener && opener.isConnected ? opener : document.querySelector('[data-bar-action="menu"]');

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
