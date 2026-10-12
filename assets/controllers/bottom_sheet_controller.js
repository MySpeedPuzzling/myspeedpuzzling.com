import { Controller } from '@hotwired/stimulus';
import { canStart, OUT_MS, pointerReleased, SHEET_EASING, SHEET_PHONE_MEDIA, SheetDrag, SPRING_MS } from '../sheet_drag.js';

// On the host (the dynamic modal #dynamic-modal-container, or a sheet dialog) while this content is in it: the phone
// layout of assets/styles/_bottom-sheet.scss (anchored to the bottom, handle, rises from the bottom)
const OPEN_CLASS = 'bottom-sheet-open';

// Where a closed sheet rests (assets/styles/_bottom-sheet.scss): below the screen and the bottom bar
const OUT_TRANSFORM = 'translateY(calc(100% + var(--bottom-nav-height, 0px)))';

// A swipe closed the sheet, but the host never hid it (no listener, a refused close): it springs back after this
const CLOSE_FALLBACK_MS = 1000;

const DRAFT_PREFIX = 'msp:draft:';

/**
 * One bottom-sheet controller for every sheet (docs/features/navigation README §4.13 G2, GEST-02): the dynamic modal's
 * sheets (achievement sheet, result detail, Help sheet) and the player card sheet (CARD-01a). The rules are in
 * assets/sheet_drag.js; this controller wires them to the sheet:
 *
 * - The panel that moves: the closest `panel` value (default `.modal-dialog`; the card passes its dialog). The host
 *   (the closest `.modal`, else the panel) gets `bottom-sheet-open` while this content is in it - styles in
 *   assets/styles/_bottom-sheet.scss. No panel (a plain page, e.g. inside the app): nothing happens.
 * - Phones only (< 576 px). The `handle` target (handle + header) drags by pointer events (touch-action: none there),
 *   the `body` target by touch events (passive: false, preventDefault only once dragging), while it is at its top.
 *   The panel follows the finger; reduced motion: no spring or slide, the drag still follows the finger.
 * - On close it dispatches a bubbling `bottom-sheet:dismiss`; the host closes through GEST-01 (c)
 *   (dynamic_modal_controller.js → assets/layer_history.js requestClose → history.back()). The panel slides out from
 *   where the finger left it and keeps that inline transform until the host is hidden.
 * - Focus starts on the optional `heading` target when the modal is shown (not on a control inside).
 * - Drafts: a `textarea[data-bottom-sheet-draft-key]` is kept in sessionStorage `msp:draft:<key>` while typed, put
 *   back into the empty field when the sheet opens again and dropped after a successful submit inside the sheet.
 */
export default class extends Controller {
    static targets = ['handle', 'body', 'heading'];

    static values = { panel: { type: String, default: '.modal-dialog' } };

    connect() {
        this.panel = this.element.closest(this.panelValue);
        this.host = this.panel?.closest('.modal') ?? this.panel;
        this.phone = window.matchMedia(SHEET_PHONE_MEDIA);
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        this.drag = new SheetDrag();
        this.pointerId = null;
        this.closing = false;
        this.timer = null;

        this.restoreDrafts();
        this.element.addEventListener('input', this.onInput);
        this.element.addEventListener('turbo:submit-end', this.onSubmitEnd);

        if (!this.panel) {
            return;
        }

        this.host.classList.add(OPEN_CLASS);
        this.host.addEventListener('shown.bs.modal', this.onShown);
        this.host.addEventListener('hidden.bs.modal', this.onHidden);
        this.host.addEventListener('close', this.onHidden);

        if (this.hasHandleTarget) {
            this.handleTarget.addEventListener('pointerdown', this.onPointerDown);
            this.handleTarget.addEventListener('pointermove', this.onPointerMove);
            this.handleTarget.addEventListener('pointerup', this.onPointerUp);
            this.handleTarget.addEventListener('pointercancel', this.onPointerCancel);
        }

        if (this.hasBodyTarget) {
            this.bodyTarget.addEventListener('touchstart', this.onTouchStart, { passive: true });
            this.bodyTarget.addEventListener('touchmove', this.onTouchMove, { passive: false });
            this.bodyTarget.addEventListener('touchend', this.onTouchEnd);
            this.bodyTarget.addEventListener('touchcancel', this.onTouchCancel);
        }

        // Content swapped into an already open modal: no shown event comes
        if (this.host.classList.contains('show')) {
            this.focusHeading();
        }
    }

    disconnect() {
        this.element.removeEventListener('input', this.onInput);
        this.element.removeEventListener('turbo:submit-end', this.onSubmitEnd);

        if (!this.panel) {
            return;
        }

        clearTimeout(this.timer);
        this.drag.reset();
        this.releasePointer();
        this.host.removeEventListener('shown.bs.modal', this.onShown);
        this.host.removeEventListener('hidden.bs.modal', this.onHidden);
        this.host.removeEventListener('close', this.onHidden);

        if (this.hasHandleTarget) {
            this.handleTarget.removeEventListener('pointerdown', this.onPointerDown);
            this.handleTarget.removeEventListener('pointermove', this.onPointerMove);
            this.handleTarget.removeEventListener('pointerup', this.onPointerUp);
            this.handleTarget.removeEventListener('pointercancel', this.onPointerCancel);
        }

        if (this.hasBodyTarget) {
            this.bodyTarget.removeEventListener('touchstart', this.onTouchStart);
            this.bodyTarget.removeEventListener('touchmove', this.onTouchMove);
            this.bodyTarget.removeEventListener('touchend', this.onTouchEnd);
            this.bodyTarget.removeEventListener('touchcancel', this.onTouchCancel);
        }

        this.resetPanel();
        this.host.classList.remove(OPEN_CLASS);
    }

    onShown = () => {
        this.focusHeading();
    };

    onHidden = () => {
        clearTimeout(this.timer);
        this.closing = false;
        this.drag.reset();
        this.releasePointer();
        this.resetPanel();
    };

    focusHeading() {
        if (this.hasHeadingTarget) {
            this.headingTarget.focus({ preventScroll: true });
        }
    }

    active() {
        return this.panel !== null && this.phone.matches && !this.closing;
    }

    // ---- The handle / header area (pointer events) ----

    onPointerDown = (event) => {
        if (!this.active() || event.button > 0 || !event.isPrimary || this.drag.state === 'pending' || this.drag.dragging) {
            return;
        }

        this.pointerId = event.pointerId;
        this.drag.start(event.clientX, event.clientY, event.timeStamp, canStart(event.target, { zone: 'handle', root: this.handleTarget }));
    };

    onPointerMove = (event) => {
        if (event.pointerId !== this.pointerId) {
            return;
        }

        if (pointerReleased(event)) {
            this.releasePointer();
            this.finish(this.drag.cancel());

            return;
        }

        const step = this.drag.move(event.clientX, event.clientY, event.timeStamp);

        if (step.started) {
            this.handleTarget.setPointerCapture?.(event.pointerId);
            this.beginDrag();
        }

        if (step.dragging) {
            this.follow(step.offset);
        }
    };

    onPointerUp = (event) => {
        if (event.pointerId === this.pointerId) {
            this.releasePointer();
            this.finish(this.drag.release(this.sheetHeight(), event.timeStamp));
        }
    };

    onPointerCancel = (event) => {
        if (event.pointerId === this.pointerId) {
            this.releasePointer();
            this.finish(this.drag.cancel());
        }
    };

    releasePointer() {
        const id = this.pointerId;
        this.pointerId = null;

        if (id !== null && this.hasHandleTarget && this.handleTarget.hasPointerCapture?.(id)) {
            this.handleTarget.releasePointerCapture(id);
        }
    }

    // ---- The content, while it is at its top (touch events) ----

    onTouchStart = (event) => {
        if (!this.active() || event.touches.length !== 1 || this.pointerId !== null) {
            this.drag.reset();
            return;
        }

        const touch = event.touches[0];
        this.drag.start(touch.clientX, touch.clientY, event.timeStamp, canStart(event.target, { zone: 'body', root: this.bodyTarget }));
    };

    onTouchMove = (event) => {
        if (this.pointerId !== null) {
            return;
        }

        if (event.touches.length !== 1) {
            this.finish(this.drag.cancel());
            return;
        }

        const touch = event.touches[0];
        const step = this.drag.move(touch.clientX, touch.clientY, event.timeStamp);

        if (step.prevent && event.cancelable) {
            event.preventDefault();
        }

        if (step.started) {
            this.beginDrag();
        }

        if (step.dragging) {
            this.follow(step.offset);
        }
    };

    onTouchEnd = (event) => {
        if (this.pointerId === null && event.touches.length === 0) {
            this.finish(this.drag.release(this.sheetHeight(), event.timeStamp));
        }
    };

    onTouchCancel = () => {
        if (this.pointerId === null) {
            this.finish(this.drag.cancel());
        }
    };

    // ---- Moving the sheet ----

    beginDrag() {
        clearTimeout(this.timer);
        this.panel.style.transition = 'none';
    }

    follow(offset) {
        this.panel.style.transform = offset > 0 ? `translateY(${offset}px)` : '';
    }

    sheetHeight() {
        return this.panel ? this.panel.getBoundingClientRect().height : 0;
    }

    finish(decision) {
        if (decision === 'close') {
            this.close();
        } else if (decision === 'spring') {
            this.springBack();
        }
    }

    close() {
        this.closing = true;
        // Out of the screen from where the finger left it; the host's hide only fades the scrim
        this.panel.style.transition = this.reducedMotion.matches ? 'none' : `transform ${OUT_MS}ms ${SHEET_EASING}`;
        this.panel.style.transform = OUT_TRANSFORM;
        this.element.dispatchEvent(new CustomEvent('bottom-sheet:dismiss', { bubbles: true }));

        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            if (this.closing && this.element.isConnected && this.isHostOpen()) {
                this.closing = false;
                this.springBack();
            }
        }, CLOSE_FALLBACK_MS);
    }

    isHostOpen() {
        return this.host.classList.contains('show') || this.host.hasAttribute('open');
    }

    springBack() {
        this.panel.style.transition = this.reducedMotion.matches ? 'none' : `transform ${SPRING_MS}ms ease-out`;
        this.panel.style.transform = '';

        // The sheet's own CSS transitions apply again once it is back
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            if (!this.drag.dragging && !this.closing) {
                this.panel.style.transition = '';
            }
        }, SPRING_MS + 50);
    }

    resetPanel() {
        if (this.panel) {
            this.panel.style.transition = '';
            this.panel.style.transform = '';
        }
    }

    // ---- Drafts ----

    draftFields() {
        return Array.from(this.element.querySelectorAll('textarea[data-bottom-sheet-draft-key]'));
    }

    restoreDrafts() {
        for (const field of this.draftFields()) {
            if (field.value !== '') {
                continue;
            }

            const draft = readDraft(field.dataset.bottomSheetDraftKey);

            if (draft) {
                field.value = draft;
            }
        }
    }

    onInput = (event) => {
        const field = event.target;

        if (field instanceof HTMLTextAreaElement && field.dataset.bottomSheetDraftKey) {
            writeDraft(field.dataset.bottomSheetDraftKey, field.value);
        }
    };

    onSubmitEnd = (event) => {
        if (event.detail?.success !== true || !(event.target instanceof Element)) {
            return;
        }

        for (const field of event.target.querySelectorAll('textarea[data-bottom-sheet-draft-key]')) {
            writeDraft(field.dataset.bottomSheetDraftKey, '');
        }
    };
}

function readDraft(key) {
    try {
        return window.sessionStorage.getItem(DRAFT_PREFIX + key);
    } catch (e) {
        return null;
    }
}

function writeDraft(key, value) {
    try {
        if (value === '') {
            window.sessionStorage.removeItem(DRAFT_PREFIX + key);
        } else {
            window.sessionStorage.setItem(DRAFT_PREFIX + key, value);
        }
    } catch (e) {
        // Storage blocked (private mode, quota): the draft lives only as long as the field
    }
}
