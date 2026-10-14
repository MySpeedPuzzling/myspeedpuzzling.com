import layers from './layer_history.js';
import { createCardMeasure } from './player_card_measure.js';
import { contextKindOf, contextOf } from './player_card_rules.js';
import { SHEET_PHONE_MEDIA } from './sheet_drag.js';

/**
 * The player card's layer (docs/features/navigation README §4.8, CARD-01a; port of the Players page's
 * players_card_controller.js), loaded lazily by assets/player_card.js. One card at a time:
 *
 * - A <dialog class="player-card-layer"> created on first use at the end of <body>, opened with show() - never
 *   showModal(): the browser's top layer would sit above Bootstrap's modals, and the members modal (the locked tier
 *   chip) and the Message modal must open above the card (z-index $z-sheet = 1045 < 1050, decision 73).
 * - Below 576 px a bottom sheet: its own scrim, aria-modal="true", focus cycles inside, the page behind is inert (not
 *   the phone bottom bar, not Bootstrap's modals), it ends above the bottom bar, swipe down closes it (the card's
 *   content runs the shared bottom-sheet controller on .player-card-box, GEST-02), × always. From 576 px a popover
 *   anchored next to the tapped name, following it while the page scrolls: aria-modal="false", no focus trap, a click
 *   outside closes it.
 * - One fresh <turbo-frame id="player-card"> per open: a card prefetched or loaded ≤ 20 s ago shows at once (the frame's
 *   own first answer is kept like a prefetch - Safari and iOS focus no name before the tap, so they never prefetch),
 *   else "Loading…" (aria-busy; the dialog is named by it until the card's heading arrives) until turbo:frame-load; an
 *   answer without the frame (turbo:frame-missing) becomes a page visit; a card that never arrives (the network failed,
 *   turbo:fetch-request-error) is the profile, like a layer module that fails to load (player_card.js). Favorite and
 *   Compare navigate inside the frame - and empty the cache, which turbo:load empties too.
 * - Focus moves into the card and back to the name that opened it - a focus that prefetches nothing: a card just closed
 *   is not fetched again for 20 s (review CARD+TEASE F1).
 * - A Bootstrap modal opened from inside the card (the members modal of the locked tier chip) is above it, outside the
 *   layer history: Esc closes only the modal; Back (and ×) close the card and that modal together - it never outlives the
 *   card it was opened from (review CARD+TEASE F2).
 * - History only through assets/layer_history.js (GEST-01): opening pushes one same-URL entry ('card'); another name
 *   while the card is open swaps the frame, no history change; ×, Esc, the scrim, a swipe and a click outside close it
 *   through Back; a link leaving the card (data-turbo-frame="_top") is a replace visit; a layer opened over it (the
 *   Message modal, the Menu) takes the entry over and the card closes; turbo:before-cache closes it silently. Inside
 *   the native app the trigger never opens it (player_card.js).
 * - A guest's sign-in links (data-card-return-page) come back to the page the card was opened on, not the profile.
 * - Pull-to-refresh does not arm while it is open (an open <dialog>, assets/pull_to_refresh.js).
 * - Measurement (CARD-07, assets/player_card_measure.js): each open is one `card_open` (its context kind and input) and
 *   ends in exactly one `card_outcome` - the first thing used inside the card (its data-nav item), else how it closed;
 *   a prefetch is never an open. Sent as document events, assets/nav_instrumentation.js beacons them.
 */

export const LAYER_ID = 'card';
export const FRAME_ID = 'player-card';

/** A prefetched card is shown when it is younger than this (ms) */
export const PREFETCH_TTL_MS = 20000;

/** The sheet's way out (ms) after ×, Esc or Back - none under reduced motion */
export const OUT_MS = 200;

// Distance to the name and to the viewport's edges, px
const GAP = 8;
const EDGE = 12;
// The popover starts this far into a wide trigger (a Players page row) - right of the avatar and the name
const NAME_WIDTH = 220;

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

// Direct children of <body> that stay usable under the sheet: the phone bottom bar, the toasts (a live region),
// Bootstrap's modals and the dynamic modal (they open above the card)
const KEEP_USABLE = '#bottom-nav, #toast-container, #dynamic-modal-container, .modal, .modal-backdrop, script, template, style, link, noscript';

// A click outside the popover on one of these does something of its own (a visit replaces the card's entry, a form
// submits, another name opens its card, a modal opens above): the card does not ask for Back on top of it
const OUTSIDE_LEAVES_IT = 'a[href], form, .modal, label';

// Where a closed sheet rests (the bottom-sheet controller's slide-out): below the screen and the bottom bar
const OUT_TRANSFORM = 'translateY(calc(100% + var(--bottom-nav-height, 0px)))';
const OUT_TRANSITION = `transform ${OUT_MS}ms cubic-bezier(.2, .8, .2, 1)`;

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
const HTML_CONTENT_TYPE = /^text\/html|^application\/xhtml\+xml/;

/** Is this prefetched card still good to show? */
export function freshEntry(entry, now, ttl = PREFETCH_TTL_MS) {
    return Boolean(entry) && typeof entry.html === 'string' && typeof entry.at === 'number' && now - entry.at < ttl && now >= entry.at;
}

/** Was this card closed a moment ago ({url, at} of the last close)? Its name gets the focus back - no prefetch for that */
export function closedRecently(closed, url, now, ttl = PREFETCH_TTL_MS) {
    return Boolean(closed) && closed.url === url && typeof closed.at === 'number' && now - closed.at < ttl && now >= closed.at;
}

/** A guest's sign-in link pointed at the page the card was opened on: its `return` replaced, the rest kept */
export function withReturnPage(href, page, base) {
    if (typeof page !== 'string' || !page.startsWith('/') || page.startsWith('//')) {
        return null;
    }

    let url;

    try {
        url = new URL(href, base);
    } catch (e) {
        return null;
    }

    url.searchParams.set('return', page);

    return url.pathname + url.search + url.hash;
}

class PlayerCardLayer {
    constructor(window, document, layerHistory = layers) {
        this.window = window;
        this.document = document;
        this.layers = layerHistory;
        this.dialog = null;
        this.box = null;
        this.frame = null;
        this.anchor = null;
        this.url = null;
        this.offset = null;
        this.shown = false;
        this.released = false;
        this.inerted = [];
        this.scrollFrame = null;
        this.closingTimer = null;
        this.initialFetch = false;
        this.listening = false;
        this.cache = new Map();
        this.inflight = new Map();
        // The URL of the frame's first request - its answer is kept like a prefetched one
        this.keepAnswerOf = null;
        // {url, at} of the card closed last (F1)
        this.closedCard = null;
        // Bootstrap modals opened from inside the open card (F2)
        this.modalsOver = [];
        this.media = window.matchMedia(SHEET_PHONE_MEDIA);
        this.reducedMotion = window.matchMedia(REDUCED_MOTION);
        this.measure = createCardMeasure({
            now: () => (window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now()),
            emit: (name, detail) => {
                try {
                    document.dispatchEvent(new window.CustomEvent(name, { detail }));
                } catch (e) {
                    // a lost count, never a broken card
                }
            },
        });

        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        document.addEventListener('turbo:load', this.onLoad);
        this.media.addEventListener('change', this.onModeChange);
        // The page goes away with the card open (a full page load, the tab closed): how it ended still counts
        window.addEventListener('pagehide', this.onPageHide);
    }

    // ---- Opening -------------------------------------------------------------------------------------------------

    open(anchor, url, input = 'mouse') {
        if (this.closingTimer !== null) {
            this.finishClose();
        }

        // The same name again closes its card (the popover: the page is usable under it)
        if (this.shown && !this.released && this.anchor === anchor && this.url === url) {
            this.requestClose();

            return;
        }

        this.ensureDialog();
        const wasShown = this.shown && !this.released;

        // History first: a layer holding the entry (the Menu panel, the search view) hands it over and closes
        this.layers.open(LAYER_ID, { element: this.dialog, onClose: this.onLayerClose });

        this.anchor = anchor;
        this.url = url;
        this.released = false;
        this.loadFrame(url);

        if (!this.dialog.open) {
            this.dialog.show();
        }

        this.shown = true;
        this.applyMode();

        if (!wasShown) {
            this.listen(true);
        }

        this.place();
        this.box.focus({ preventScroll: true });
        // Another name while a card is open ends that card's measure first
        this.measure.opened(contextKindOf(contextOf(anchor)), input);
    }

    ensureDialog() {
        if (this.dialog !== null && this.dialog.isConnected) {
            return;
        }

        const document = this.document;
        const dialog = document.createElement('dialog');
        dialog.className = 'player-card-layer';
        // Named by the card's heading (templates/players/_card.html.twig) once it has loaded
        dialog.setAttribute('aria-labelledby', 'players-card-name');

        const scrim = document.createElement('div');
        scrim.className = 'player-card-scrim';
        scrim.setAttribute('data-player-card-close', '');

        const box = document.createElement('div');
        box.className = 'player-card-box';
        box.tabIndex = -1;

        dialog.append(scrim, box);
        // Capture: counted before whatever the click starts (a visit, a frame load, a modal)
        dialog.addEventListener('click', this.onMeasureClick, true);
        dialog.addEventListener('click', this.onDialogClick);
        dialog.addEventListener('cancel', this.onCancel);
        dialog.addEventListener('bottom-sheet:dismiss', this.onDismiss);
        document.body.appendChild(dialog);

        this.dialog = dialog;
        this.box = box;
    }

    loadFrame(url) {
        this.removeFrame();

        const frame = this.document.createElement('turbo-frame');
        frame.id = FRAME_ID;
        frame.addEventListener('turbo:frame-load', this.onFrameLoad);
        frame.addEventListener('turbo:frame-missing', this.onFrameMissing);
        frame.addEventListener('turbo:before-fetch-request', this.onFrameFetch);
        frame.addEventListener('turbo:before-fetch-response', this.onFrameResponse);
        frame.addEventListener('turbo:fetch-request-error', this.onFrameError);
        this.frame = frame;
        this.box.appendChild(frame);

        const cached = this.cache.get(url);

        if (freshEntry(cached, Date.now())) {
            this.fill(frame, cached.html);

            return;
        }

        this.showLoading(frame);
        const pending = this.inflight.get(url);

        if (pending) {
            // The prefetch started on the hover / the focus a moment ago: its answer, not a second request
            pending.then((html) => {
                if (this.frame !== frame || !frame.isConnected || frame.hasAttribute('src')) {
                    return;
                }

                if (html === null) {
                    this.load(frame, url);
                } else {
                    this.fill(frame, html);
                }
            });

            return;
        }

        this.load(frame, url);
    }

    load(frame, url) {
        this.initialFetch = true;
        this.keepAnswerOf = url;
        frame.setAttribute('src', url);
    }

    showLoading(frame) {
        this.box.setAttribute('aria-busy', 'true');

        const loading = this.document.createElement('div');
        loading.className = 'player-card-loading';
        loading.innerHTML = '<span class="spinner-border text-primary" aria-hidden="true"></span><span class="visually-hidden"></span>';
        // The page's own "Loading" (the dynamic modal's spinner, base.html.twig) - no string of the card's in every page
        const text = this.document.querySelector('[data-dynamic-modal-target="loading"] .visually-hidden');
        loading.lastElementChild.textContent = text ? text.textContent.trim() : '';
        // The dialog's name (aria-labelledby) until the card's heading replaces it (review CARD+TEASE F4)
        loading.lastElementChild.id = 'players-card-name';
        frame.replaceChildren(loading);
    }

    fill(frame, html) {
        frame.innerHTML = html;
        this.loaded();
    }

    loaded() {
        this.box.removeAttribute('aria-busy');
        this.pointSignInLinksHere();
        this.place();

        // Into the card once it has its name; after Favorite / Compare the pressed button is gone - stay in the card
        if (!this.dialog.contains(this.document.activeElement)) {
            this.box.focus({ preventScroll: true });
        }
    }

    /** A guest's sign-in links: back to the page the card was opened on (the card's response is the same for all) */
    pointSignInLinksHere() {
        const location = this.window.location;
        const page = location.pathname + location.search;

        this.box.querySelectorAll('a[data-card-return-page]').forEach((link) => {
            const href = withReturnPage(link.getAttribute('href'), page, location.href);

            if (href !== null) {
                link.setAttribute('href', href);
            }
        });
    }

    removeFrame() {
        if (this.frame === null) {
            return;
        }

        this.frame.removeEventListener('turbo:frame-load', this.onFrameLoad);
        this.frame.removeEventListener('turbo:frame-missing', this.onFrameMissing);
        this.frame.removeEventListener('turbo:before-fetch-request', this.onFrameFetch);
        this.frame.removeEventListener('turbo:before-fetch-response', this.onFrameResponse);
        this.frame.removeEventListener('turbo:fetch-request-error', this.onFrameError);
        this.frame.remove();
        this.frame = null;
        this.initialFetch = false;
        this.keepAnswerOf = null;
    }

    // ---- Prefetch -------------------------------------------------------------------------------------------------

    prefetch(url) {
        if (freshEntry(this.cache.get(url), Date.now())) {
            return Promise.resolve(this.cache.get(url).html);
        }

        if (this.inflight.has(url)) {
            return this.inflight.get(url);
        }

        // The focus returning to the name of the card just closed is no interest in it (F1); a reopen loads as usual
        if (closedRecently(this.closedCard, url, Date.now())) {
            return Promise.resolve(null);
        }

        const request = this.window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'Turbo-Frame': FRAME_ID, Accept: 'text/html, application/xhtml+xml' },
        })
            .then((response) => (response.ok ? response.text() : null))
            .then((html) => this.keep(url, html))
            .catch(() => null)
            .finally(() => {
                this.inflight.delete(url);
            });

        this.inflight.set(url, request);

        return request;
    }

    /** The card inside a frame response, kept for PREFETCH_TTL_MS; null when the response holds no card frame */
    keep(url, html) {
        const inner = typeof html === 'string' ? this.frameHtmlOf(html) : null;

        if (inner !== null) {
            this.prune();
            this.cache.set(url, { html: inner, at: Date.now() });
        }

        return inner;
    }

    frameHtmlOf(html) {
        const Parser = this.window.DOMParser;
        const frame = Parser ? new Parser().parseFromString(html, 'text/html').querySelector('turbo-frame#' + FRAME_ID) : null;

        return frame ? frame.innerHTML : null;
    }

    prune() {
        const now = Date.now();

        this.cache.forEach((entry, url) => {
            if (!freshEntry(entry, now)) {
                this.cache.delete(url);
            }
        });
    }

    // ---- Closing --------------------------------------------------------------------------------------------------

    /** ×, Esc, the scrim, a swipe, a click outside: through Back while the card holds its entry, else at once */
    requestClose() {
        if (this.layers.isOpen(LAYER_ID)) {
            this.layers.requestClose(LAYER_ID);

            return;
        }

        this.closeUi('close');
    }

    onLayerClose = ({ reason }) => {
        if (reason === 'leave') {
            // A visit leaves from the card (Results, a puzzle, a tab of the bottom bar): it stays on screen until the new
            // page replaces it - the page under it is usable again, nothing stays inert if that page never comes (onLoad)
            this.measure.closed();
            this.released = true;
            this.setInert(false);

            return;
        }

        this.closeUi(reason);
    };

    /** back / close: the sheet slides out and focus goes back to the name; swap / cache: gone at once */
    closeUi(reason) {
        if (!this.shown) {
            return;
        }

        this.measure.closed();

        const anchor = this.anchor;
        const animate = (reason === 'back' || reason === 'close') && this.isSheet() && !this.reducedMotion.matches;
        const modals = this.modalsOver;

        if (this.url !== null) {
            this.closedCard = { url: this.url, at: Date.now() };
        }

        this.shown = false;
        this.released = false;
        this.anchor = null;
        this.url = null;
        this.offset = null;
        this.modalsOver = [];
        this.setInert(false);
        this.listen(false);

        if (reason === 'back' || reason === 'close') {
            this.hideModals(modals, anchor);
            this.focusAnchor(anchor);
        }

        if (!animate || this.dialog === null || !this.dialog.isConnected) {
            this.finishClose();

            return;
        }

        // A swipe already slid it out (the bottom-sheet controller's inline transform): only the scrim still fades
        if (!this.box.style.transform) {
            this.box.style.transition = OUT_TRANSITION;
            this.box.style.transform = OUT_TRANSFORM;
        }

        this.dialog.classList.add('is-closing');
        this.window.clearTimeout(this.closingTimer);
        this.closingTimer = this.window.setTimeout(() => this.finishClose(), OUT_MS + 50);
    }

    finishClose() {
        this.window.clearTimeout(this.closingTimer);
        this.closingTimer = null;
        this.removeFrame();

        if (this.dialog === null) {
            return;
        }

        this.dialog.classList.remove('is-closing', 'is-sheet');

        if (this.dialog.open) {
            this.dialog.close();
        }

        this.box.removeAttribute('aria-busy');
        this.box.style.top = '';
        this.box.style.left = '';
        this.box.style.transform = '';
        this.box.style.transition = '';
    }

    focusAnchor(anchor) {
        if (anchor && anchor.isConnected && anchor.getClientRects().length > 0) {
            anchor.focus({ preventScroll: true });
        }
    }

    /**
     * Back (or ×) with a Bootstrap modal opened from the card still over it: one Back clears what the player sees - the
     * modal is not in the layer history, it would stay over the page, and the next Back would leave the page under it.
     * Bootstrap gives its focus back to the lock inside the card once hidden: the name gets it after that.
     */
    hideModals(modals, anchor) {
        const Modal = this.window.bootstrap && this.window.bootstrap.Modal;

        modals.forEach((element) => {
            const modal = element.isConnected && element.classList.contains('show') && Modal && typeof Modal.getInstance === 'function'
                ? Modal.getInstance(element)
                : null;

            if (!modal) {
                return;
            }

            element.addEventListener('hidden.bs.modal', () => this.focusAnchor(anchor), { once: true });
            modal.hide();
        });
    }

    // ---- Layout ---------------------------------------------------------------------------------------------------

    isSheet() {
        return this.media.matches;
    }

    applyMode() {
        const sheet = this.isSheet();
        this.dialog.classList.toggle('is-sheet', sheet);
        this.dialog.setAttribute('aria-modal', sheet ? 'true' : 'false');
        this.setInert(sheet && !this.released);
    }

    setInert(on) {
        if (!on) {
            this.inerted.forEach((element) => {
                element.inert = false;
            });
            this.inerted = [];

            return;
        }

        if (this.inerted.length > 0) {
            return;
        }

        Array.from(this.document.body.children).forEach((element) => {
            if (element === this.dialog || element.matches(KEEP_USABLE) || element.inert) {
                return;
            }

            element.inert = true;
            this.inerted.push(element);
        });
    }

    /**
     * Popover: beside the name (right of it, else left), else under or over it - always inside the viewport. Kept as an
     * offset from the name, so it scrolls with it. The sheet is CSS.
     */
    place() {
        if (this.box === null) {
            return;
        }

        const style = this.box.style;

        if (this.isSheet() || this.anchor === null || !this.anchor.isConnected) {
            if (this.isSheet()) {
                style.top = '';
                style.left = '';
                this.offset = null;
            }

            return;
        }

        const anchor = this.anchor.getBoundingClientRect();
        const viewportWidth = this.document.documentElement.clientWidth;
        const viewportHeight = this.window.innerHeight;
        const width = this.box.offsetWidth;
        const height = this.box.offsetHeight;
        const clamp = (value, min, max) => Math.max(min, Math.min(value, max));

        let left = anchor.left + Math.min(anchor.width, NAME_WIDTH) + GAP;
        let top = anchor.top - 2 * GAP;

        if (left + width > viewportWidth - EDGE) {
            left = anchor.left - width - GAP;

            if (left < EDGE) {
                // No room on either side: under the name, or over it when that is where the room is
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

    // ---- Listeners while open -------------------------------------------------------------------------------------

    listen(on) {
        if (on === this.listening) {
            return;
        }

        this.listening = on;
        const method = on ? 'addEventListener' : 'removeEventListener';

        this.document[method]('click', this.onDocumentClick);
        this.document[method]('keydown', this.onKeydown, { capture: true });
        this.window[method]('resize', this.onResize);
        this.window[method]('scroll', this.onScroll, { passive: true, capture: true });
        this.document[method]('show.bs.modal', this.onModalShow);

        if (!on && this.scrollFrame !== null) {
            this.window.cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
        }
    }

    /** CARD-07: a control inside the card was used - its data-nav item ("card/<kind>/<item>"), '' without one */
    onMeasureClick = (event) => {
        const target = event.target;

        if (!this.shown || this.released || this.box === null || !target || typeof target.closest !== 'function') {
            return;
        }

        const control = target.closest('a[href], button, summary');

        if (!control || !this.box.contains(control)) {
            return;
        }

        const parts = String(control.getAttribute('data-nav') || '').split('/');

        this.measure.clicked(parts.length === 3 ? parts[2] : '');
    };

    onPageHide = () => {
        this.measure.closed();
    };

    // A Bootstrap modal opened by a control inside the card (Bootstrap's relatedTarget: the members modal's lock)
    onModalShow = (event) => {
        const opener = event.relatedTarget;

        if (!this.shown || this.released || this.box === null || !opener || !this.box.contains(opener) || this.modalsOver.includes(event.target)) {
            return;
        }

        this.modalsOver.push(event.target);
    };

    onDialogClick = (event) => {
        const target = event.target;

        if (target && typeof target.closest === 'function' && target.closest('[data-player-card-close]')) {
            event.preventDefault();
            this.requestClose();
        }
    };

    // A show() dialog gets no cancel from Esc, but a close request of the browser closes it through Back all the same
    onCancel = (event) => {
        event.preventDefault();
        this.requestClose();
    };

    // The bottom-sheet controller's swipe-down (GEST-02)
    onDismiss = () => {
        this.requestClose();
    };

    onDocumentClick = (event) => {
        if (!this.shown || this.released || this.isSheet() || event.defaultPrevented) {
            return;
        }

        const target = event.target;

        if (!target || typeof target.closest !== 'function' || this.dialog.contains(target) || target.closest(OUTSIDE_LEAVES_IT)) {
            return;
        }

        this.requestClose();
    };

    onKeydown = (event) => {
        if (!this.shown || this.released) {
            return;
        }

        // A Bootstrap modal over the card (the members modal): its Esc closes it, not the card under it - prevented
        // here, assets/layer_history.js leaves it alone; Bootstrap's own handler still closes the modal
        const modalOpen = this.document.querySelector('.modal.show') !== null;

        if (event.key === 'Escape') {
            if (modalOpen) {
                event.preventDefault();
            }

            return;
        }

        if (event.key === 'Tab' && this.isSheet() && !modalOpen) {
            this.keepFocusInside(event);
        }
    };

    keepFocusInside(event) {
        const focusable = Array.from(this.dialog.querySelectorAll(FOCUSABLE))
            .filter((element) => element.getClientRects().length > 0);
        const active = this.document.activeElement;

        if (focusable.length === 0) {
            event.preventDefault();
            this.box.focus({ preventScroll: true });

            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (!this.dialog.contains(active)) {
            event.preventDefault();
            first.focus();
        } else if (event.shiftKey && (active === first || active === this.box)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    onResize = () => {
        if (this.shown) {
            this.place();
        }
    };

    onModeChange = () => {
        if (this.shown && !this.released) {
            this.applyMode();
            this.place();
        }
    };

    // The popover is position: fixed - it follows the name while the page scrolls
    onScroll = () => {
        if (this.offset === null || this.anchor === null || !this.anchor.isConnected || this.scrollFrame !== null) {
            return;
        }

        this.scrollFrame = this.window.requestAnimationFrame(() => {
            this.scrollFrame = null;

            if (this.offset === null || this.anchor === null || !this.anchor.isConnected || this.box === null) {
                return;
            }

            const anchor = this.anchor.getBoundingClientRect();
            this.box.style.top = `${anchor.top + this.offset.top}px`;
            this.box.style.left = `${anchor.left + this.offset.left}px`;
        });
    };

    // ---- The frame ------------------------------------------------------------------------------------------------

    onFrameLoad = () => {
        this.initialFetch = false;
        this.loaded();
    };

    // The frame's first answer is the card as a prefetch would have fetched it: kept for a reopen within 20 s (F1) - the
    // server's HTML (fetchResponse.responseHTML), never the live frame Stimulus has touched
    onFrameResponse = (event) => {
        const url = this.keepAnswerOf;
        const fetchResponse = event.detail ? event.detail.fetchResponse : null;

        if (url === null || event.target !== this.frame) {
            return;
        }

        this.keepAnswerOf = null;

        if (!fetchResponse || !fetchResponse.succeeded || fetchResponse.redirected) {
            return;
        }

        Promise.resolve(fetchResponse.responseHTML)
            .then((html) => {
                this.keep(url, html);
            })
            .catch(() => {});
    };

    // The card never came (the phone lost its connection): the profile, like a layer module that fails to load
    // (player_card.js) - the service worker's offline page answers that visit when the phone is offline (F5)
    onFrameError = () => {
        if (!this.shown || this.released || this.box === null || !this.box.hasAttribute('aria-busy')) {
            return;
        }

        const href = this.anchor && this.anchor.href ? this.anchor.href : this.url;

        if (href) {
            this.layers.visitFromLayer(href);
        }
    };

    // Favorite / Compare inside the card: what was prefetched is out of date
    onFrameFetch = () => {
        if (this.initialFetch) {
            this.initialFetch = false;

            return;
        }

        this.cache.clear();
    };

    // An answer that is not the card (e.g. the full comparison to swap someone out): a page visit, replacing the
    // card's history entry (Turbo's own frame-missing visit, through the layer helper)
    onFrameMissing = async (event) => {
        event.preventDefault();

        const response = event.detail.response;
        const location = response && response.url ? response.url : this.url;
        let responseHTML;

        try {
            if (response && HTML_CONTENT_TYPE.test(response.headers.get('Content-Type') || '')) {
                responseHTML = await response.clone().text();
            }
        } catch (e) {
            responseHTML = undefined;
        }

        if (!location) {
            return;
        }

        if (responseHTML === undefined) {
            this.layers.visitFromLayer(location);

            return;
        }

        this.layers.visitFromLayer(location, { response: { redirected: response.redirected, statusCode: response.status, responseHTML } });
    };

    // ---- Turbo ----------------------------------------------------------------------------------------------------

    /** Turbo caches the page: a restored page never shows an open card (and no inert page) */
    onBeforeCache = () => {
        if (this.dialog === null) {
            return;
        }

        if (this.shown) {
            if (this.layers.isOpen(LAYER_ID)) {
                this.layers.closeForCache();
            }

            this.closeUi('cache');
        }

        this.finishClose();
        this.dialog.remove();
        this.dialog = null;
        this.box = null;
    };

    onLoad = () => {
        this.cache.clear();

        if (this.dialog === null) {
            return;
        }

        if (!this.dialog.isConnected) {
            // The page was replaced with the card on it (a visit left from it): start over on the next open
            this.window.clearTimeout(this.closingTimer);
            this.closingTimer = null;
            this.frame = null;
            this.dialog = null;
            this.box = null;
            this.shown = false;
            this.released = false;
            this.anchor = null;
            this.inerted = [];
            this.listen(false);

            return;
        }

        // A visit left from the card, but this page is still here (nothing replaced it): close what is left
        if (this.released) {
            this.closeUi('cache');
        }
    };
}

/** The layer with its environment given (tests/player-card-harness.mjs: jsdom and a fake layer history) */
export function createPlayerCardLayer({ window, document, layerHistory = layers }) {
    return new PlayerCardLayer(window, document, layerHistory);
}

let layer = null;

function instance() {
    if (layer === null) {
        layer = new PlayerCardLayer(window, document);
    }

    return layer;
}

/** Opens the card of the player `link` names (assets/player_card.js); options.input = how it was asked for (CARD-07) */
export function openCard(link, url, options = {}) {
    instance().open(link, url, options.input || 'mouse');
}

/** Loads the card early - a pointer rests on the name, or it has the focus */
export function prefetchCard(url) {
    return instance().prefetch(url);
}
