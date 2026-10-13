import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import layers, { LAYER_CLOSED_EVENT } from '../layer_history.js';
import { isNativeApp } from '../platform.js';
import { TOPBAR_REVEAL_EVENT } from '../topbar_scroll.js';
import { focusMovedByTab, focusReturnedWithWindow } from '../navigation/focus_origin.js';

// Whatever opens the page's search (the top bar's 🔍 icon, the old header's search tool, the 404 page's button) - one
// delegated listener
const TOGGLE = '[data-global-search-toggle]';

// The Menu panel's shell (templates/navigation/_menu_layer.html.twig): searching there hides the Menu (CSS)
const SHELL = '.menu-layer';
const SEARCHING = 'is-searching';

// The phone search view's id in assets/layer_history.js
const LAYER = 'search';

// The open search on <html> (CSS shows the view, the topbar controller never hides the bar meanwhile) - and the class
// the pull-to-refresh guard (pwa_lifecycle_controller.js) and a few stylesheets read on <body>
const OPEN_ATTRIBUTE = 'data-search-open';
const OPEN_CLASS = 'global-search-shown';

/** Phones in Media Queries Level 3 syntax (requirements, Conventions; $nav-phone-media in _nav-tokens.scss) */
const PHONE_QUERY = '(max-width: 767.98px), (max-height: 500.98px) and (pointer: coarse)';

/** Desktop - everything else ($nav-desktop-media) */
const DESKTOP_QUERY = '(min-width: 768px) and (min-height: 501px), (min-width: 768px) and (pointer: fine)';

// Ctrl/Cmd+K never reaches behind an open modal, dialog (the Menu panel, the desktop overlay) or the Help sheet
const BLOCKING_LAYER = '.modal.show, dialog[open]';

// Typing in another field keeps its own Ctrl/Cmd+K (a link shortcut of an editor, say)
const OTHER_FIELD = 'input, textarea, select, [contenteditable=""], [contenteditable="true"]';

// The Menu panel asks the page's search to close when it opens (menu_layer_controller.js, review F11)
const CLOSE_EVENT = 'global-search:close';

// This instance's own barcode scanner (templates/components/GlobalSearch.html.twig)
const SCANNER = 'barcode-scanner';

// Every barcode scanner of the page stops on this window event (barcode_scanner_controller.js)
const SCANNER_CLOSE_EVENT = 'barcode-scan:close';

// What Tab can reach (the phone search view keeps it inside the top bar and the bottom bar - follow-up review Q5)
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

/**
 * The page search's own behaviour (templates/components/GlobalSearch.html.twig; navigation MENU-03, NAV-03, R3): one
 * controller per GlobalSearch instance, on the component's root, reading only its own elements (targets) - never "the
 * first search input on the page". Mode:
 * - `bar`: the top bar's search field. Focusing it (or the scan button, or any [data-global-search-toggle] - the 🔍 that
 *   replaces the field next to a running stopwatch, the old header's tool) opens the search: <html data-search-open>.
 *   Not when Tab merely passes through the field (NAV-11, WCAG 3.2.1 - assets/navigation/focus_origin.js): the phone
 *   view would hide ?, Inbox and ☰ from the Tab order; there the search opens with the first character typed. Nor when
 *   the browser only hands the focus back to the field because its window (tab, app) got the focus again (follow-up
 *   review F3). Opening the search - not the field's focus - stops the page's own barcode scanner (multiscan, the add
 *   form: follow-up review F2): a Tab pass leaves its camera running.
 *   On phones that is the search view, a layer of assets/layer_history.js (GEST-01): one same-URL history entry, so
 *   Back, ×, Esc and a tap on the scrim close it and a result opened from it replaces its entry (Back then returns to
 *   the page the search was opened on); the scrim and the page's scroll lock belong to it (phones only, CSS). With a
 *   keyboard (follow-up review Q5) Tab and Shift+Tab cycle through the top bar (the logo, the field, its buttons, the
 *   results) and the bottom bar, which stay usable - never into the dimmed page; closing it puts the focus back where
 *   the search opened (the field at its place in the bar, or the 🔍) once a key opened it, Esc closed it or Tab moved in
 *   it - a tap-opened view drops the focus instead (the on-screen keyboard goes away). On desktop it is a dropdown under
 *   the field with no history entry (decision 22), no scrim, the page scrolls on (NAV review F2): Esc anywhere in it, a
 *   click outside it and the focus leaving it (Tab past it - not onto its own results, not into a modal it opened)
 *   close it. Esc and × put the focus back into the field - or, where the field hides again (the 768-1023 px bar), on the
 *   🔍 that opened it - never on <body> (review F5); typing in the still focused field (or a click on it) opens it
 *   again. Closing clears the field. Opened from an icon, the field is shown and focused in the same tap (iOS opens the
 *   keyboard only then).
 * - `menu`: the Menu panel's field. Typing shows the results inside the panel (the shell gets .is-searching, CSS hides
 *   #site-menu) - same layer, same history entry. Esc - in the field or on a result - clears a typed query first and
 *   puts focus back into the field; it closes the panel only when the field is empty (assets/layer_history.js handles
 *   that Esc). The field is cleared whenever the panel closes, and its own scanner stops on every close (×, Esc, Back,
 *   a modal taking over, a visit leaving - review F3): the shell stays in the page, so nothing else would stop it.
 * The Menu's field closes the page's barcode scanner when it gets the focus. Opening the Menu panel closes the bar's
 * search: the phone search view through the layer swap (the Menu's layer replaces its entry), the desktop dropdown on
 * `global-search:close` (review F11). A Tab move onto the bar's field never scrolls the page (follow-up review Q1,
 * handleTab). "Pages & settings" is nav_search_controller.js's.
 *
 * Desktop (NAV-04, README §4.3): Ctrl/Cmd+K focuses the bar's field - in the 768-1023 px bar, where search is the 🔍,
 * it opens the field first; never a single-key shortcut, never behind an open modal or dialog, never while typing in
 * another field. The field's placeholder is the one-word one on phones and the long one ("Search puzzles, puzzlers,
 * pages & settings") on desktop whenever it fits the field (canvas measureText in the input's font) - decided again
 * when the field changes size and after every Live re-render, which puts the server's one-word placeholder back.
 */
export default class extends Controller {
    static targets = ['searchInput', 'panel'];
    static values = { mode: { type: String, default: 'bar' } };

    connect() {
        this.shown = false;
        this.layered = false;
        this.quietFocus = false;
        // A key opened the search or moved in it (the phone view gives the focus back on closing - follow-up review Q5)
        this.keyboardUsed = false;

        this.searchInputTarget.addEventListener('focus', this.handleFocus);
        // On the whole instance: Esc on a result row too (review Q5)
        this.element.addEventListener('keydown', this.handleKeydown);
        this.searchInputTarget.addEventListener('input', this.handleInput);

        if (this.isMenu()) {
            document.addEventListener(LAYER_CLOSED_EVENT, this.handleLayerClosed);
            this.syncSearching();
        } else {
            document.addEventListener('click', this.handleToggleClick);
            document.addEventListener('keydown', this.handleShortcut);
            document.addEventListener('keydown', this.handleTab);
            document.addEventListener(CLOSE_EVENT, this.handleCloseRequest);
            document.addEventListener('turbo:before-cache', this.handleBeforeCache);
            this.element.addEventListener('focusout', this.handleFocusOut);
            this.searchInputTarget.addEventListener('click', this.handleFieldClick);

            if (this.hasPanelTarget) {
                this.panelTarget.addEventListener('click', this.handlePanelClick);
            }

            this.watchPlaceholder();
        }
    }

    disconnect() {
        this.searchInputTarget.removeEventListener('focus', this.handleFocus);
        this.element.removeEventListener('keydown', this.handleKeydown);
        this.searchInputTarget.removeEventListener('input', this.handleInput);
        document.removeEventListener(LAYER_CLOSED_EVENT, this.handleLayerClosed);
        document.removeEventListener('click', this.handleToggleClick);
        document.removeEventListener('keydown', this.handleShortcut);
        document.removeEventListener('keydown', this.handleTab);
        document.removeEventListener(CLOSE_EVENT, this.handleCloseRequest);
        document.removeEventListener('click', this.handleOutsideClick);
        document.removeEventListener('turbo:before-cache', this.handleBeforeCache);
        this.element.removeEventListener('focusout', this.handleFocusOut);
        this.searchInputTarget.removeEventListener('click', this.handleFieldClick);

        if (this.hasPanelTarget) {
            this.panelTarget.removeEventListener('click', this.handlePanelClick);
        }

        this.unwatchPlaceholder();

        // The page went away with the search open (a result was opened from it): <html> outlives the body
        if (this.shown) {
            this.setOpen(false);
        }
    }

    isMenu() {
        return this.modeValue === 'menu';
    }

    handleFocus = () => {
        // The focus coming back after Esc or × (restoreFocus) reopens nothing
        if (this.quietFocus) {
            return;
        }

        if (this.isMenu()) {
            window.dispatchEvent(new CustomEvent(SCANNER_CLOSE_EVENT));

            return;
        }

        // A tap or a click opens the search; Tab passing through does not (a change of context on focus, NAV-11), nor
        // the focus the browser gives back to the field with its window (follow-up review F3) - typing opens it then
        if (focusMovedByTab() || focusReturnedWithWindow()) {
            return;
        }

        this.open(false);
    };

    handleInput = () => {
        if (this.isMenu()) {
            this.syncSearching();

            return;
        }

        // The focus is in the closed field - reached by Tab, kept after Esc, given back with the window: typing opens the
        // search (clear() sends an empty input, which never does)
        if (!this.shown && this.searchInputTarget.value !== '' && document.activeElement === this.searchInputTarget) {
            this.open(true);
        }
    };

    /** Desktop: a click on the field that kept the focus after Esc (no new focus event) opens the dropdown again */
    handleFieldClick = (event) => {
        if (!this.shown) {
            this.open(Boolean(event) && event.detail === 0);
        }
    };

    /**
     * Desktop (review F2): the dropdown closes when the focus leaves the search - Tab past it, Shift+Tab back to the
     * logo, a click on a focusable element outside it. Not for a move inside it (the field → the scan button → a
     * result), not into a modal opened from it (the members-only scan modal), and not when nothing gets the focus (the
     * window lost it - another tab or app - or a click on nothing focusable: the outside click decides that one). The
     * phone search view is a layer: Back, ×, Esc, the scrim and a visit close it.
     */
    handleFocusOut = (event) => {
        if (!this.shown || this.layered) {
            return;
        }

        const next = event.relatedTarget;

        if (!next || typeof next.closest !== 'function' || this.element.contains(next) || next.closest('.modal')) {
            return;
        }

        this.closeView('close');
    };

    /**
     * Menu: Esc in the field or on a result clears a filled field (and stops there - the panel stays) with focus back in
     * the field (review Q5); with an empty field Esc closes the panel.
     * Bar: on phones Esc closes the search view through assets/layer_history.js (its document listener); the desktop
     * dropdown has no history entry and closes here - Esc on the field or on a result - and the focus goes back to
     * the field (or the 🔍 where the field hides again).
     */
    handleKeydown = (event) => {
        if (event.key !== 'Escape' || event.defaultPrevented) {
            return;
        }

        if (!this.isMenu()) {
            if (this.shown && !this.layered) {
                event.preventDefault();
                this.closeView('close');
                this.restoreFocus();
            } else if (this.shown) {
                // Phones: assets/layer_history.js closes the view on this Esc - a keyboard is there, so the focus goes
                // back to where the search opened (follow-up review Q5)
                this.keyboardUsed = true;
            }

            return;
        }

        if (this.searchInputTarget.value === '') {
            return;
        }

        event.preventDefault();
        this.clear();

        if (document.activeElement !== this.searchInputTarget) {
            this.searchInputTarget.focus({ preventScroll: true });
        }
    };

    /**
     * Menu: the panel closed (Back, ×, Esc, a modal taking over, a visit leaving) - its camera stops at once; the next
     * open starts with an empty field (a visit leaving keeps it until the new page arrives)
     */
    handleLayerClosed = (event) => {
        const detail = event && event.detail ? event.detail : {};

        if (detail.id !== 'menu') {
            return;
        }

        this.stopScanner();

        if (detail.reason !== 'leave') {
            this.clear();
        }
    };

    /** This instance's scanner only: a scanner of the page itself (multiscan, the add form) keeps running */
    stopScanner() {
        const element = this.element.querySelector(`[data-controller~="${SCANNER}"]`);
        const scanner = element ? this.application.getControllerForElementAndIdentifier(element, SCANNER) : null;

        if (scanner && scanner.hasToggleButtonTarget && scanner.hasWrapperTarget && typeof scanner.stopScanning === 'function') {
            scanner.stopScanning();
        }
    }

    syncSearching() {
        const shell = this.element.closest(SHELL);

        if (shell) {
            shell.classList.toggle(SEARCHING, this.searchInputTarget.value.trim() !== '');
        }
    }

    clear() {
        if (this.searchInputTarget.value !== '') {
            this.searchInputTarget.value = '';
            // The Live model and "Pages & settings" follow the field
            this.searchInputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }

        if (this.isMenu()) {
            this.syncSearching();
        }
    }

    // ---- bar ---------------------------------------------------------------------------------------------------

    /**
     * Ctrl/Cmd+K (NAV-04): the field takes the focus - opened first where search is an icon (the 768-1023 px bar, a
     * phone next to a running stopwatch). Left to the browser behind an open modal or dialog and in another field.
     */
    handleShortcut = (event) => {
        if (event.defaultPrevented || event.altKey || event.shiftKey || !(event.ctrlKey || event.metaKey)
            || typeof event.key !== 'string' || event.key.toLowerCase() !== 'k' || !this.element.isConnected) {
            return;
        }

        const target = event.target;
        const input = this.searchInputTarget;

        if (document.querySelector(BLOCKING_LAYER)
            || (target !== input && target instanceof Element && target.closest(OTHER_FIELD))) {
            return;
        }

        event.preventDefault();

        if (!this.shown) {
            this.open(true);
        }

        // Synchronously: html[data-search-open] shows the field where it was the icon, then it can take the focus - never
        // scrolling the page (the sticky bar is in view; <html>'s scroll padding made its caret look covered - Q1)
        input.focus({ preventScroll: true });
        input.select();
    };

    /** The placeholder: decided now, whenever the field changes size and after every Live re-render */
    watchPlaceholder() {
        this.placeholderFrame = null;
        this.desktopQuery = typeof window.matchMedia === 'function' ? window.matchMedia(DESKTOP_QUERY) : null;

        if (typeof window.ResizeObserver === 'function') {
            this.placeholderObserver = new window.ResizeObserver(this.schedulePlaceholder);
            this.placeholderObserver.observe(this.searchInputTarget);
        } else {
            window.addEventListener('resize', this.schedulePlaceholder);
        }

        if (this.desktopQuery && typeof this.desktopQuery.addEventListener === 'function') {
            this.desktopQuery.addEventListener('change', this.schedulePlaceholder);
        }

        // The web font may arrive after the first measure (font-display: optional - within 100 ms or never)
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(this.schedulePlaceholder, () => {});
        }

        getComponent(this.element).then((component) => {
            if (!this.element.isConnected) {
                return;
            }

            this.liveComponent = component;
            component.on('render:finished', this.schedulePlaceholder);
        }, () => {});

        this.fitPlaceholder();
    }

    unwatchPlaceholder() {
        if (this.placeholderObserver) {
            this.placeholderObserver.disconnect();
            this.placeholderObserver = null;
        }

        window.removeEventListener('resize', this.schedulePlaceholder);

        if (this.desktopQuery && typeof this.desktopQuery.removeEventListener === 'function') {
            this.desktopQuery.removeEventListener('change', this.schedulePlaceholder);
        }

        if (this.liveComponent && typeof this.liveComponent.off === 'function') {
            this.liveComponent.off('render:finished', this.schedulePlaceholder);
        }

        this.liveComponent = null;

        if (this.placeholderFrame) {
            window.cancelAnimationFrame(this.placeholderFrame);
            this.placeholderFrame = null;
        }
    }

    schedulePlaceholder = () => {
        if (this.placeholderFrame || !this.element.isConnected) {
            return;
        }

        this.placeholderFrame = window.requestAnimationFrame(() => {
            this.placeholderFrame = null;
            this.fitPlaceholder();
        });
    };

    /** The long placeholder on desktop when it fits the input's content box, else the one-word one */
    fitPlaceholder() {
        const input = this.searchInputTarget;
        const short = input.dataset.placeholderShort || input.getAttribute('placeholder') || '';
        const long = input.dataset.placeholderLong || '';
        let wanted = short;

        if (long !== '' && this.desktopQuery && this.desktopQuery.matches && input.clientWidth > 0) {
            const style = window.getComputedStyle(input);
            const room = input.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);

            if (room > 0 && this.textWidth(long, style) <= room) {
                wanted = long;
            }
        }

        if (input.getAttribute('placeholder') !== wanted) {
            input.setAttribute('placeholder', wanted);
        }
    }

    textWidth(text, style) {
        this.measureCanvas = this.measureCanvas || document.createElement('canvas');
        const context = this.measureCanvas.getContext('2d');

        if (!context) {
            return Number.POSITIVE_INFINITY;
        }

        context.font = style.font || `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;

        return context.measureText(text).width;
    }

    /**
     * The Menu panel opens (review F11): the desktop dropdown closes here. A phone search view is left alone - the Menu's
     * layer takes its history entry over (a swap closes it through onLayerClose); asking history to go back here as
     * well would race that swap
     */
    handleCloseRequest = () => {
        if (this.shown && !this.layered) {
            this.closeView('close');
        }
    };

    /** The scan button's action: a click with detail 0 came from Enter / Space on it */
    openView = (event) => {
        this.open(Boolean(event) && event.type === 'click' && event.detail === 0);
    };

    /**
     * Opens the search: the field's focus, typing, the scan button, a toggle, Ctrl/Cmd+K. Idempotent - the focus that
     * follows a toggle's open finds it open. The page's own barcode scanner stops (the multiscan camera, a form's scan -
     * follow-up review F2; the scan button starts this instance's one right after). Phones: the search view takes a
     * history entry (not inside the app: no entry there, and no web top bar either).
     */
    open(byKeyboard) {
        if (this.isMenu() || this.shown) {
            return;
        }

        this.shown = true;
        this.keyboardUsed = Boolean(byKeyboard);
        this.layered = typeof window.matchMedia === 'function' && window.matchMedia(PHONE_QUERY).matches
            && !isNativeApp(document.documentElement);
        window.dispatchEvent(new CustomEvent(SCANNER_CLOSE_EVENT));
        this.setOpen(true);
        // The bar comes back if it was hidden (and stays while the search is open - topbar_controller.js)
        window.dispatchEvent(new CustomEvent(TOPBAR_REVEAL_EVENT));

        if (this.layered) {
            layers.open(LAYER, { element: this.element, onClose: this.onLayerClose });
        } else {
            // Added while the opening click may still be on its way up: a click inside the search never closes it
            document.addEventListener('click', this.handleOutsideClick);
        }

        this.closeLegacyCollapse();
    }

    onLayerClose = ({ reason }) => {
        if (reason === 'leave') {
            // A result (or a tab) opens another page from the search view: it stays until that page replaces it
            // (disconnect() takes the open state off <html>); the field keeps the query meanwhile
            return;
        }

        this.closeView(reason);
    };

    /** Closes the search's UI and empties the field; the layer's entry is already gone (or there is none) */
    closeView(reason = 'close') {
        if (!this.shown) {
            return;
        }

        const layered = this.layered;
        const keyboard = this.keyboardUsed;
        const active = document.activeElement;
        // The focus would be lost with the view: on the field, ×, a result, or already nowhere (a tap on the scrim)
        const focusInView = !active || active === document.body || this.element.contains(active);

        this.shown = false;
        this.layered = false;
        this.keyboardUsed = false;
        document.removeEventListener('click', this.handleOutsideClick);
        this.setOpen(false);
        window.dispatchEvent(new CustomEvent(SCANNER_CLOSE_EVENT));
        this.clear();

        if (!layered || reason === 'cache' || reason === 'swap') {
            // Desktop keeps the focus where it is (review F5); the Menu taking the view's entry over focuses its own ×
            if (layered && reason === 'swap' && document.activeElement === this.searchInputTarget) {
                this.searchInputTarget.blur();
            }

            return;
        }

        if (keyboard && focusInView) {
            // Phones with a keyboard (follow-up review Q5): back to where the search opened - never <body> (WCAG 2.4.3)
            this.restoreFocus();
        } else if (document.activeElement === this.searchInputTarget) {
            // Phones: the on-screen keyboard goes away with the search view
            this.searchInputTarget.blur();
        }
    }

    /**
     * Tab, Shift+Tab (and Safari's Option+Tab) around the bar's field.
     * - Phones, the open search view (follow-up review Q5): they cycle through the top bar - the logo, the field, its
     *   buttons, the results - and the bottom bar (README §4.12, as the Menu panel), never into the page behind the scrim.
     * - Everywhere else, a Tab move onto the field from its neighbour in the bar is made here, without a scroll
     *   (follow-up review Q1): the browser's own move reveals the field's caret, and <html>'s scroll-padding-top (the
     *   bar's height, NAV-11) makes everything inside the sticky bar look covered - the page jumped up by hundreds of
     *   pixels for nothing. The bar's other tools keep the browser's move: their scroll margin (_nav-topbar.scss) keeps
     *   them in view, which no margin does for a caret.
     */
    handleTab = (event) => {
        if (event.key !== 'Tab' || event.defaultPrevented || event.ctrlKey || event.metaKey) {
            return;
        }

        const header = this.element.closest('.site-header') || this.element;

        if (this.shown && this.layered) {
            this.keyboardUsed = true;
            this.moveFocus(event, [...this.focusables(header), ...this.focusables(document.getElementById('bottom-nav'))]);

            return;
        }

        const tools = this.focusables(header).filter((element) => !element.closest('.dropdown-menu') && !this.inPanel(element));
        const at = tools.indexOf(document.activeElement);

        if (at !== -1 && tools[at + (event.shiftKey ? -1 : 1)] === this.searchInputTarget) {
            event.preventDefault();
            this.searchInputTarget.focus({ preventScroll: true });
        }
    };

    /** The next / previous of the cycle (wrapping); the bars' tools without a scroll, a result inside its panel's own */
    moveFocus(event, cycle) {
        if (cycle.length === 0) {
            return;
        }

        const at = cycle.indexOf(document.activeElement);
        const next = cycle[at === -1
            ? (event.shiftKey ? cycle.length - 1 : 0)
            : (at + (event.shiftKey ? -1 : 1) + cycle.length) % cycle.length];

        event.preventDefault();
        next.focus(this.inPanel(next) ? undefined : { preventScroll: true });
    }

    inPanel(element) {
        return this.hasPanelTarget && this.panelTarget.contains(element);
    }

    focusables(root) {
        if (!root) {
            return [];
        }

        return Array.from(root.querySelectorAll(FOCUSABLE)).filter((element) => element.getClientRects().length > 0 && !element.closest('[inert]'));
    }

    /**
     * Desktop, after Esc or ×: the focus stays in (or goes back to) the field where it is still shown - from 1024 px -
     * else on the header's shown 🔍, which opened it (768-1023 px: the field hides again) - never on <body> (WCAG 2.4.3,
     * review F5). Phones, the search view closed with a keyboard (follow-up review Q5): the same - the field at its place
     * in the bar, or the 🔍 standing in for it next to a running stopwatch. The focus coming back opens nothing.
     */
    restoreFocus() {
        const input = this.searchInputTarget;
        const target = input.getClientRects().length > 0 ? input : this.shownToggle();

        if (!target || document.activeElement === target) {
            return;
        }

        this.quietFocus = true;

        try {
            target.focus({ preventScroll: true });
        } finally {
            this.quietFocus = false;
        }
    }

    /** The header's 🔍 that stands in for the field right now, or null */
    shownToggle() {
        const header = this.element.closest('.site-header');

        if (!header) {
            return null;
        }

        return Array.from(header.querySelectorAll(TOGGLE)).find((toggle) => toggle.getClientRects().length > 0) || null;
    }

    /**
     * ×: through Back while the search view holds its history entry, else at once (desktop: the focus back to the field
     * or the 🔍 - review F5). Without an event: the scrim, the panel's empty part, an outside click, the 🔍 again.
     */
    closeSearchBar(event) {
        if (event) {
            event.preventDefault();
        }

        if (layers.isOpen(LAYER)) {
            layers.requestClose(LAYER);

            return;
        }

        const shown = this.shown;
        this.closeView('close');

        if (event && shown) {
            this.restoreFocus();
        }
    }

    toggleSearchBar(event) {
        if (event) {
            event.preventDefault();
        }

        if (this.shown) {
            this.closeSearchBar();
        } else {
            // Enter / Space on the 🔍 is a click with detail 0; the field in the sticky bar is in view (Q1)
            this.open(Boolean(event) && event.type === 'click' && event.detail === 0);
            this.searchInputTarget.focus({ preventScroll: true });
        }
    }

    handleToggleClick = (event) => {
        const toggle = event.target && typeof event.target.closest === 'function' ? event.target.closest(TOGGLE) : null;

        if (!toggle || event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        // Synchronously in the tap: the field shows (html[data-search-open]) and takes the focus - iOS opens the
        // keyboard only for a focus inside the user's own gesture
        this.toggleSearchBar(event);
    };

    /** A tap on the panel's empty part - the scrim shows through there - closes the search */
    handlePanelClick = (event) => {
        if (event.target === this.panelTarget && this.shown) {
            this.closeSearchBar();
        }
    };

    /**
     * Desktop: a click anywhere outside the search closes it. Phones: only the scrim (.global-search-overlay) - a tap on a
     * link (the bottom bar, the logo) leaves the page and replaces the search view's entry instead.
     */
    handleOutsideClick = (event) => {
        const target = event.target;

        if (!this.shown || !target || typeof target.closest !== 'function' || target.closest(TOGGLE)) {
            return;
        }

        if (target.closest('.modal') || target.closest('.modal-backdrop') || this.element.contains(target)) {
            return;
        }

        this.closeSearchBar();
    };

    handleBeforeCache = () => {
        // Turbo's snapshot of this page shows the search closed and empty
        if (this.shown) {
            if (layers.isOpen(LAYER)) {
                layers.closeForCache();
            }

            this.closeView('cache');
        }
    };

    setOpen(open) {
        const root = document.documentElement;

        if (open) {
            root.setAttribute(OPEN_ATTRIBUTE, '');
        } else {
            root.removeAttribute(OPEN_ATTRIBUTE);
        }

        document.body.classList.toggle(OPEN_CLASS, open);
        this.syncOverlay(open);
    }

    /** The scrim behind the search (base.html.twig's .global-search-overlay): phones close the search on a tap there */
    syncOverlay(open) {
        const overlay = document.querySelector('.global-search-overlay');

        if (!overlay) {
            return;
        }

        if (open) {
            overlay.addEventListener('click', this.handleOverlayClick);
        } else {
            overlay.removeEventListener('click', this.handleOverlayClick);
        }
    }

    handleOverlayClick = (event) => {
        event.preventDefault();

        if (this.shown) {
            this.closeSearchBar();
        }
    };

    /** The old header's collapsed menu (until NAV-06) closes when the search opens */
    closeLegacyCollapse() {
        const navbarCollapse = document.querySelector('.navbar-collapse.show');

        if (!navbarCollapse) {
            return;
        }

        navbarCollapse.classList.remove('show');

        const navbarToggler = document.querySelector('.navbar-toggler[aria-expanded="true"]');

        if (navbarToggler) {
            navbarToggler.setAttribute('aria-expanded', 'false');
        }
    }
}
