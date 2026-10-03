import { Controller } from '@hotwired/stimulus';

/**
 * The player card on the Players page (docs/features/players-page/README.md, stream S1). Sits on .players-page; a tap
 * on a person (templates/players/_person.html.twig: data-action="players-card#open" + data-players-card-url-param)
 * opens that person's card instead of following the link to the profile:
 *   - from 576 px up a popover next to the person, kept inside the viewport,
 *   - below that a full-width bottom sheet over a scrim.
 * One card at a time, loaded into <turbo-frame id="player-card"> (PlayerCardController). Closes on × (inside the card,
 * data-players-card-close), Escape, the scrim or a tap outside; focus moves into the card and back to the person.
 * Ctrl/Cmd/Shift/Alt-click and the middle button keep opening the profile in a new tab.
 *
 * The layer lives on <body> (no ancestor can clip or transform it) and goes with the page on a Turbo visit. Motion is
 * CSS only and switched off under prefers-reduced-motion (assets/styles/players/_card.scss).
 */
const FRAME_ID = 'player-card';
const POPOVER_MEDIA = '(min-width: 576px)';
// Distance to the person and to the viewport's edges, px
const GAP = 8;
const EDGE = 12;
// The popover starts this far into a person row - right of the avatar and the name, not at the row's far end
const NAME_WIDTH = 220;
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export default class extends Controller {
    connect() {
        this.layer = null;
        this.dialog = null;
        this.frame = null;
        this.anchor = null;
        this.offset = null;
        this.openingEvent = null;
        this.focusOnLoad = false;
        this.scrollFrame = null;
        this.media = window.matchMedia(POPOVER_MEDIA);

        this.onDocumentClick = this.onDocumentClick.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
        this.onViewportChange = this.onViewportChange.bind(this);
        this.onScroll = this.onScroll.bind(this);
        this.onFrameLoad = this.onFrameLoad.bind(this);
        this.onFrameMissing = this.onFrameMissing.bind(this);
    }

    disconnect() {
        this.close({ restoreFocus: false });
        this.layer?.remove();
        this.layer = null;
        this.dialog = null;
    }

    open(event) {
        // A new tab or window: the browser follows the link to the profile
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const url = event.params.url;

        if (!url) {
            return;
        }

        event.preventDefault();

        const anchor = event.currentTarget;

        // The same person again closes their card
        if (this.isOpen() && this.anchor === anchor) {
            this.close();
            return;
        }

        this.openingEvent = event;
        this.show(anchor, url);
    }

    show(anchor, url) {
        const wasOpen = this.isOpen();

        this.ensureLayer();
        this.anchor = anchor;
        this.applyMode();

        // A fresh frame per card: nothing of the previous person stays on screen while the next one loads
        this.removeFrame();
        const frame = document.createElement('turbo-frame');
        frame.id = FRAME_ID;
        frame.className = 'players-card-frame';
        frame.innerHTML = '<div class="players-card-loading"><span class="spinner-border text-primary" aria-hidden="true"></span></div>';
        frame.addEventListener('turbo:frame-load', this.onFrameLoad);
        frame.addEventListener('turbo:frame-missing', this.onFrameMissing);
        this.dialog.appendChild(frame);
        this.frame = frame;
        this.focusOnLoad = true;
        frame.setAttribute('src', url);

        this.layer.hidden = false;
        this.place();

        if (!wasOpen) {
            document.addEventListener('click', this.onDocumentClick);
            document.addEventListener('keydown', this.onKeydown);
            window.addEventListener('resize', this.onViewportChange);
            window.addEventListener('scroll', this.onScroll, { passive: true, capture: true });
        }
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

        const anchor = this.anchor;
        this.anchor = null;
        this.offset = null;
        this.openingEvent = null;

        if (restoreFocus && anchor && anchor.isConnected) {
            anchor.focus({ preventScroll: true });
        }
    }

    isOpen() {
        return this.layer !== null && this.layer.hidden === false;
    }

    ensureLayer() {
        if (this.layer !== null) {
            return;
        }

        const layer = document.createElement('div');
        layer.className = 'players-card-layer';
        layer.hidden = true;

        const scrim = document.createElement('div');
        scrim.className = 'players-card-scrim';
        scrim.setAttribute('data-players-card-close', '');

        // Named by the card's heading (templates/players/_card.html.twig) once it has loaded
        const dialog = document.createElement('div');
        dialog.className = 'players-card-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-labelledby', 'players-card-name');
        dialog.tabIndex = -1;

        layer.append(scrim, dialog);
        document.body.appendChild(layer);

        this.layer = layer;
        this.dialog = dialog;
    }

    removeFrame() {
        if (this.frame === null) {
            return;
        }

        this.frame.removeEventListener('turbo:frame-load', this.onFrameLoad);
        this.frame.removeEventListener('turbo:frame-missing', this.onFrameMissing);
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
     * Popover: beside the person (right of the name, else left of the row), else under or over them - always inside
     * the viewport. Remembered as an offset from the person, so the popover scrolls with them. The sheet is CSS.
     */
    place() {
        const style = this.dialog.style;

        if (this.isSheet()) {
            style.top = '';
            style.left = '';
            this.offset = null;
            return;
        }

        const anchor = this.anchor.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = window.innerHeight;
        const width = this.dialog.offsetWidth;
        const height = this.dialog.offsetHeight;
        const fitsRight = (left) => left + width <= viewportWidth - EDGE;
        const clamp = (value, min, max) => Math.max(min, Math.min(value, max));

        let left = anchor.left + Math.min(anchor.width, NAME_WIDTH) + GAP;
        let top = anchor.top - 2 * GAP;

        if (!fitsRight(left)) {
            left = anchor.left - width - GAP;

            if (left < EDGE) {
                // No room on either side: under the person, or over them when that is where the room is
                left = anchor.left;
                top = anchor.bottom + GAP;

                if (top + height > viewportHeight - EDGE && anchor.top - GAP - height >= EDGE) {
                    top = anchor.top - GAP - height;
                }
            }
        }

        left = clamp(left, EDGE, Math.max(EDGE, viewportWidth - width - EDGE));
        top = clamp(top, EDGE, Math.max(EDGE, viewportHeight - height - EDGE));

        this.offset = { top: top - anchor.top, left: left - anchor.left };
        style.top = `${top}px`;
        style.left = `${left}px`;
    }

    // The popover is position: fixed - it follows the person while the page scrolls
    onScroll() {
        if (this.offset === null || this.anchor === null || !this.anchor.isConnected || this.scrollFrame !== null) {
            return;
        }

        this.scrollFrame = requestAnimationFrame(() => {
            this.scrollFrame = null;

            if (this.offset === null || this.anchor === null || !this.anchor.isConnected) {
                return;
            }

            const anchor = this.anchor.getBoundingClientRect();
            this.dialog.style.top = `${anchor.top + this.offset.top}px`;
            this.dialog.style.left = `${anchor.left + this.offset.left}px`;
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
        // The loaded card is taller or shorter than the placeholder - keep it inside the viewport
        this.place();

        // Into the card once it has its name; after Favorite / Compare the pressed button is gone - stay in the card
        if (this.focusOnLoad || !this.dialog.contains(document.activeElement)) {
            this.focusOnLoad = false;
            this.dialog.focus({ preventScroll: true });
        }
    }

    // A Favorite / Compare answer that is not the card (e.g. the full comparison to swap someone out): a full visit
    onFrameMissing(event) {
        event.preventDefault();
        this.close({ restoreFocus: false });
        event.detail.visit(event.detail.response);
    }

    onDocumentClick(event) {
        if (!this.isOpen() || event === this.openingEvent) {
            return;
        }

        const target = event.target;

        if (target.closest('[data-players-card-close]')) {
            this.close();
            return;
        }

        // Inside the card, or in the members modal the card opened on top of itself
        if (this.dialog.contains(target) || target.closest('.modal')) {
            return;
        }

        this.close({ restoreFocus: false });
    }

    onKeydown(event) {
        if (!this.isOpen()) {
            return;
        }

        // The members modal over the card closes first
        if (document.querySelector('.modal.show')) {
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

    keepFocusInside(event) {
        const focusable = Array.from(this.dialog.querySelectorAll(FOCUSABLE))
            .filter((element) => element.getClientRects().length > 0);
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
}
