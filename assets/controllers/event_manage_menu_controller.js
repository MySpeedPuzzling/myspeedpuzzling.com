/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The ⋯ menus of the events page (docs/features/events-page/README.md, "The ⋯ menu"). Sits on the page's menu host
 * (templates/event_parts/_manage_menu_host.html.twig); a tap on a ⋯ link (templates/event_parts/_manage_button.html.twig,
 * [data-ev-manage-menu]) opens that row's menu instead of following the link to the menu's own page:
 *   - from 992 px up a popover under the button, right-aligned to it and kept inside the viewport,
 *   - below that a full-width bottom sheet over a scrim.
 * One menu at a time, loaded on demand into <turbo-frame id="event-manage-menu"> (EventManageMenuController), so the
 * page carries no forms or CSRF tokens for rows nobody opens. Closes on ×, Escape, the scrim or a tap outside; focus
 * moves into the menu and back to the ⋯ button. A menu that cannot be loaded says so ("Please try again").
 * Ctrl/Cmd/Shift/Alt-click and the middle button keep opening the menu's page.
 *
 * The layer lives on <body> (no ancestor can clip or transform it, the rows clone into the calendar) and goes with the
 * page on a Turbo visit. Motion is CSS only, off under prefers-reduced-motion (assets/styles/_events-organizer.scss).
 */
const FRAME_ID = 'event-manage-menu';
const POPOVER_MEDIA = '(min-width: 992px)';
const GAP = 4;
const EDGE = 12;
// A menu squeezed between the button and the viewport's edge still shows a few actions (and scrolls)
const MIN_HEIGHT = 120;
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

export default class extends Controller {
    static values = {
        loading: String,
        error: String,
        close: String,
    };

    connect() {
        this.layer = null;
        this.dialog = null;
        this.body = null;
        this.frame = null;
        this.anchor = null;
        this.scrollFrame = null;
        this.resizeObserver = null;
        this.media = window.matchMedia(POPOVER_MEDIA);

        this.onOpenerClick = this.onOpenerClick.bind(this);
        this.onDocumentClick = this.onDocumentClick.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
        this.onViewportChange = this.onViewportChange.bind(this);
        this.onScroll = this.onScroll.bind(this);
        this.onFrameLoad = this.onFrameLoad.bind(this);
        this.onFrameMissing = this.onFrameMissing.bind(this);
        this.onFetchError = this.onFetchError.bind(this);

        // The ⋯ links are rows outside this element: listen on the document
        document.addEventListener('click', this.onOpenerClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onOpenerClick);
        this.close({ restoreFocus: false });
        this.layer?.remove();
        this.layer = null;
        this.dialog = null;
    }

    onOpenerClick(event) {
        const opener = event.target.closest?.('a[data-ev-manage-menu]');

        // A new tab or window: the browser opens the menu's page
        if (!opener || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();

        // The same ⋯ again closes its menu
        if (this.isOpen() && this.anchor === opener) {
            this.close();
            return;
        }

        this.show(opener);
        // This very click reaches the document listener too: it must not close the menu it opened
        this.openingEvent = event;
    }

    show(opener) {
        const wasOpen = this.isOpen();

        if (wasOpen) {
            this.close({ restoreFocus: false });
        }

        this.ensureLayer();
        this.anchor = opener;
        this.anchor.setAttribute('aria-expanded', 'true');
        this.dialog.setAttribute('aria-label', opener.getAttribute('aria-label') || '');
        this.applyMode();

        // A fresh frame per menu: nothing of the previous row stays on screen while the next one loads
        this.removeFrame();
        const frame = document.createElement('turbo-frame');
        frame.id = FRAME_ID;
        frame.className = 'ev-menu-frame';
        frame.innerHTML = `<p class="ev-menu-status" role="status">${this.escape(this.loadingValue)}</p>`;
        frame.addEventListener('turbo:frame-load', this.onFrameLoad);
        frame.addEventListener('turbo:frame-missing', this.onFrameMissing);
        frame.addEventListener('turbo:fetch-request-error', this.onFetchError);
        this.body.appendChild(frame);
        this.frame = frame;
        frame.setAttribute('src', this.menuUrl(opener));

        this.layer.hidden = false;
        this.place();
        this.resizeObserver?.observe(this.dialog);
        this.dialog.focus({ preventScroll: true });

        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
        window.addEventListener('resize', this.onViewportChange);
        window.addEventListener('scroll', this.onScroll, { passive: true, capture: true });
    }

    close({ restoreFocus = true } = {}) {
        if (!this.isOpen()) {
            return;
        }

        this.layer.hidden = true;
        this.removeFrame();

        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
        window.removeEventListener('resize', this.onViewportChange);
        window.removeEventListener('scroll', this.onScroll, { capture: true });

        if (this.scrollFrame !== null) {
            cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
        }

        this.resizeObserver?.disconnect();

        const anchor = this.anchor;
        this.anchor = null;
        this.openingEvent = null;

        if (anchor) {
            anchor.setAttribute('aria-expanded', 'false');

            if (restoreFocus && anchor.isConnected) {
                anchor.focus({ preventScroll: true });
            }
        }
    }

    isOpen() {
        return this.layer !== null && this.layer.hidden === false;
    }

    // The page's URL may have changed since it was rendered (scope, search, calendar): come back to it as it is now
    menuUrl(opener) {
        const url = new URL(opener.href, window.location.origin);
        url.searchParams.set('return', window.location.pathname + window.location.search);

        return url.pathname + url.search;
    }

    ensureLayer() {
        if (this.layer !== null) {
            return;
        }

        const layer = document.createElement('div');
        layer.className = 'ev-menu-layer';
        layer.hidden = true;

        const scrim = document.createElement('div');
        scrim.className = 'ev-menu-scrim';
        scrim.setAttribute('data-ev-menu-close', '');

        const dialog = document.createElement('div');
        dialog.className = 'ev-menu-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.tabIndex = -1;

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'ev-menu-close btn-close';
        close.setAttribute('aria-label', this.closeValue);
        close.setAttribute('data-ev-menu-close', '');

        const body = document.createElement('div');
        body.className = 'ev-menu-body';

        dialog.append(close, body);
        layer.append(scrim, dialog);
        document.body.appendChild(layer);

        this.layer = layer;
        this.dialog = dialog;
        this.body = body;

        if (typeof ResizeObserver !== 'undefined') {
            this.resizeObserver = new ResizeObserver(() => {
                if (this.isOpen()) {
                    this.place();
                }
            });
        }
    }

    removeFrame() {
        if (this.frame === null) {
            return;
        }

        this.frame.removeEventListener('turbo:frame-load', this.onFrameLoad);
        this.frame.removeEventListener('turbo:frame-missing', this.onFrameMissing);
        this.frame.removeEventListener('turbo:fetch-request-error', this.onFetchError);
        this.frame.remove();
        this.frame = null;
    }

    isSheet() {
        return this.media.matches === false;
    }

    applyMode() {
        const sheet = this.isSheet();
        this.layer.classList.toggle('is-sheet', sheet);
        this.dialog.setAttribute('aria-modal', sheet ? 'true' : 'false');
    }

    /**
     * Popover: under the ⋯ button, its right edge on the button's right edge; above the button when there is more room
     * there - never over the button itself: when neither side fits the whole menu, it gets the bigger side and scrolls.
     * Placed again on scroll and whenever its height changes (a confirmation opens). The sheet is CSS.
     */
    place() {
        const style = this.dialog.style;

        if (this.isSheet()) {
            style.top = '';
            style.left = '';
            style.maxHeight = '';
            return;
        }

        const anchor = this.anchor.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = window.innerHeight;
        const width = this.dialog.offsetWidth;
        // The whole menu's height, whatever max-height an earlier placement gave it (borders included)
        const height = this.dialog.scrollHeight + (this.dialog.offsetHeight - this.dialog.clientHeight);
        const clamp = (value, min, max) => Math.max(min, Math.min(value, max));

        const roomBelow = viewportHeight - EDGE - (anchor.bottom + GAP);
        const roomAbove = anchor.top - GAP - EDGE;
        const below = height <= roomBelow || roomBelow >= roomAbove;
        const room = Math.max(MIN_HEIGHT, below ? roomBelow : roomAbove);
        const shown = Math.min(height, room);

        let left = anchor.right - width;
        let top = below ? anchor.bottom + GAP : anchor.top - GAP - shown;

        left = clamp(left, EDGE, Math.max(EDGE, viewportWidth - width - EDGE));
        top = clamp(top, EDGE, Math.max(EDGE, viewportHeight - shown - EDGE));

        const maxHeight = height > room ? `${room}px` : '';

        if (style.maxHeight !== maxHeight) {
            style.maxHeight = maxHeight;
        }

        style.top = `${top}px`;
        style.left = `${left}px`;
    }

    // The popover is position: fixed - it follows its row while the page scrolls (smooth scrolling included)
    onScroll() {
        if (this.isSheet() || this.anchor === null || !this.anchor.isConnected || this.scrollFrame !== null) {
            return;
        }

        this.scrollFrame = requestAnimationFrame(() => {
            this.scrollFrame = null;

            if (this.isOpen() && this.anchor !== null && this.anchor.isConnected) {
                this.place();
            }
        });
    }

    onViewportChange() {
        if (!this.isOpen()) {
            return;
        }

        this.applyMode();
        this.place();
    }

    onFrameLoad() {
        this.place();

        const first = this.focusable().find((element) => element.classList.contains('ev-menu-close') === false);

        if (first) {
            first.focus({ preventScroll: true });
        } else {
            this.dialog.focus({ preventScroll: true });
        }
    }

    // Not the menu (403, 404, a server error page): say so instead of showing that page
    onFrameMissing(event) {
        event.preventDefault();
        this.showError();
    }

    onFetchError() {
        this.showError();
    }

    showError() {
        if (this.frame === null) {
            return;
        }

        this.frame.innerHTML = `<p class="ev-menu-status ev-menu-error" role="alert">${this.escape(this.errorValue)}</p>`;
        this.place();
    }

    onDocumentClick(event) {
        if (!this.isOpen() || event === this.openingEvent) {
            return;
        }

        const target = event.target;

        if (target.closest('[data-ev-menu-close]')) {
            this.close();
            return;
        }

        // Another ⋯ is handled by onOpenerClick
        if (this.dialog.contains(target) || target.closest('a[data-ev-manage-menu]')) {
            return;
        }

        this.close({ restoreFocus: false });
    }

    onKeydown(event) {
        if (!this.isOpen()) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
            return;
        }

        if (event.key === 'Tab') {
            this.keepFocusInside(event);
        }
    }

    focusable() {
        return Array.from(this.dialog.querySelectorAll(FOCUSABLE))
            .filter((element) => element.getClientRects().length > 0);
    }

    keepFocusInside(event) {
        const focusable = this.focusable();
        const active = document.activeElement;

        if (focusable.length === 0) {
            event.preventDefault();
            this.dialog.focus({ preventScroll: true });
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (!this.dialog.contains(active)) {
            event.preventDefault();
            first.focus();
        } else if (event.shiftKey && (active === first || active === this.dialog)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    escape(text) {
        const span = document.createElement('span');
        span.textContent = text || '';

        return span.innerHTML;
    }
}
