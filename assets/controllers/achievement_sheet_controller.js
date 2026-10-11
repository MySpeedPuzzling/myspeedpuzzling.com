import { Controller } from '@hotwired/stimulus';

const PHONE = '(max-width: 575.98px)';
const SHEET_CLASS = 'achievement-sheet-open';
// A pull this far (px), or a flick this fast (px/ms) after at least FLICK_MIN px, closes the sheet
const CLOSE_DISTANCE = 96;
const FLICK_VELOCITY = 0.6;
const FLICK_MIN = 24;
// A finger on the content must move this far down (at the top of the scroll) before it drags the sheet
const BODY_SLOP = 8;

/**
 * The achievement detail sheet (docs/features/xp-levels/ux-polish/design-spec.md §2 + §8.3) opts the shared dynamic
 * modal (base.html.twig) into a bottom sheet on phones - only while this content is in it, no other modal changes:
 *
 * - connect: a modifier class on #dynamic-modal-container (styles in assets/styles/_achievement-medal.scss: anchored
 *   to the bottom, rounded top, grab handle, at most 92dvh with its own scroll, safe-area inset); disconnect (Turbo
 *   clears the frame after the modal hid) and `hidden.bs.modal` take it off again.
 * - Swipe down closes it: from the handle / title area (pointer events), or from the content while it is scrolled to
 *   its top (touch events - the content's own scroll keeps working otherwise). A short pull springs back (instantly
 *   under reduced motion). The × and Escape stay. Closing goes through the dynamic modal (`modal:close`).
 * - The Android back gesture closes it through CloseWatcher where the browser has one - no history entry (app.js turns
 *   restoration visits into full page loads).
 * - Focus starts on the heading (not on the medal, where Space would turn it); the dynamic modal returns it to the
 *   tapped cell on close.
 */
export default class extends Controller {
    static targets = ['handle', 'heading', 'body'];

    connect() {
        this.container = this.element.closest('#dynamic-modal-container');
        this.dialog = this.container?.querySelector('.modal-dialog') ?? null;
        this.phone = window.matchMedia(PHONE);
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        this.drag = null;
        this.touch = null;
        this.closeWatcher = null;

        this.container?.classList.add(SHEET_CLASS);
        this.container?.addEventListener('shown.bs.modal', this.onShown);
        this.container?.addEventListener('hidden.bs.modal', this.onHidden);

        if (this.hasHandleTarget) {
            this.handleTarget.addEventListener('pointerdown', this.onDown);
        }

        if (this.hasBodyTarget) {
            this.bodyTarget.addEventListener('touchstart', this.onTouchStart, { passive: true });
            this.bodyTarget.addEventListener('touchmove', this.onTouchMove, { passive: false });
            this.bodyTarget.addEventListener('touchend', this.onTouchEnd);
            this.bodyTarget.addEventListener('touchcancel', this.onTouchCancel);
        }

        this.watchClose();

        // Content swapped into an already open modal: no shown event comes
        if (this.container?.classList.contains('show')) {
            this.focusHeading();
        }
    }

    disconnect() {
        this.endDrag();
        this.touch = null;
        this.container?.removeEventListener('shown.bs.modal', this.onShown);
        this.container?.removeEventListener('hidden.bs.modal', this.onHidden);

        if (this.hasHandleTarget) {
            this.handleTarget.removeEventListener('pointerdown', this.onDown);
        }

        if (this.hasBodyTarget) {
            this.bodyTarget.removeEventListener('touchstart', this.onTouchStart);
            this.bodyTarget.removeEventListener('touchmove', this.onTouchMove);
            this.bodyTarget.removeEventListener('touchend', this.onTouchEnd);
            this.bodyTarget.removeEventListener('touchcancel', this.onTouchCancel);
        }

        this.unwatchClose();
        this.resetDialog();
        this.container?.classList.remove(SHEET_CLASS);
    }

    onShown = () => {
        this.focusHeading();
    };

    onHidden = () => {
        this.endDrag();
        this.unwatchClose();
        this.resetDialog();
        this.container?.classList.remove(SHEET_CLASS);
    };

    focusHeading() {
        if (this.hasHeadingTarget) {
            this.headingTarget.focus({ preventScroll: true });
        }
    }

    watchClose() {
        if (typeof window.CloseWatcher !== 'function' || this.closeWatcher !== null) {
            return;
        }

        try {
            this.closeWatcher = new window.CloseWatcher();
            this.closeWatcher.onclose = () => {
                this.closeWatcher = null;
                this.close();
            };
        } catch {
            this.closeWatcher = null;
        }
    }

    unwatchClose() {
        const watcher = this.closeWatcher;
        this.closeWatcher = null;
        watcher?.destroy();
    }

    close() {
        this.resetDialog();
        document.dispatchEvent(new CustomEvent('modal:close'));
    }

    // ---- Dragging from the handle / title area (pointer events) ----

    onDown = (event) => {
        if (!this.phone.matches || !this.dialog || event.button > 0 || this.drag !== null) {
            return;
        }

        // The close button (and anything else you can press) keeps working as a tap
        if (event.target instanceof Element && event.target.closest('button, a, input, select, textarea')) {
            return;
        }

        this.startDrag(event.clientY, event.timeStamp, event.pointerId);
        this.handleTarget.setPointerCapture?.(event.pointerId);
        this.handleTarget.addEventListener('pointermove', this.onMove);
        this.handleTarget.addEventListener('pointerup', this.onUp);
        this.handleTarget.addEventListener('pointercancel', this.onCancel);
    };

    onMove = (event) => {
        if (this.drag !== null && event.pointerId === this.drag.id) {
            this.moveDrag(event.clientY, event.timeStamp);
        }
    };

    onUp = (event) => {
        if (this.drag !== null && event.pointerId === this.drag.id) {
            this.finishDrag();
        }
    };

    onCancel = () => {
        this.endDrag();
        this.springBack();
    };

    // ---- Dragging from the content while it is scrolled to its top (touch events) ----

    onTouchStart = (event) => {
        if (!this.phone.matches || !this.dialog || event.touches.length !== 1 || this.drag !== null) {
            this.touch = null;
            return;
        }

        this.touch = { startY: event.touches[0].clientY, dragging: false };
    };

    onTouchMove = (event) => {
        const touch = this.touch;

        if (touch === null || event.touches.length !== 1) {
            return;
        }

        const y = event.touches[0].clientY;

        if (!touch.dragging) {
            const pull = y - touch.startY;

            // Up, or the content is not at its top: an ordinary scroll, never a drag
            if (pull < 0 || this.bodyTarget.scrollTop > 0) {
                this.touch = null;
                return;
            }

            if (pull < BODY_SLOP) {
                return;
            }

            touch.dragging = true;
            this.startDrag(y, event.timeStamp, 'touch');
        }

        event.preventDefault();
        this.moveDrag(y, event.timeStamp);
    };

    onTouchEnd = () => {
        const dragging = this.touch?.dragging === true;
        this.touch = null;

        if (dragging) {
            this.finishDrag();
        }
    };

    onTouchCancel = () => {
        const dragging = this.touch?.dragging === true;
        this.touch = null;

        if (dragging) {
            this.endDrag();
            this.springBack();
        }
    };

    // ---- The drag itself ----

    startDrag(y, time, id) {
        this.drag = { id, startY: y, y: 0, lastY: y, lastTime: time, velocity: 0 };
        this.dialog.style.transition = 'none';
    }

    moveDrag(clientY, time) {
        const drag = this.drag;
        drag.y = Math.max(0, clientY - drag.startY);
        const dt = time - drag.lastTime;

        if (dt > 0) {
            drag.velocity = (clientY - drag.lastY) / dt;
        }

        drag.lastY = clientY;
        drag.lastTime = time;
        this.dialog.style.transform = `translateY(${drag.y}px)`;
    }

    finishDrag() {
        const drag = this.drag;
        const close = drag !== null && (drag.y > CLOSE_DISTANCE || (drag.velocity > FLICK_VELOCITY && drag.y > FLICK_MIN));
        this.endDrag();

        if (close) {
            // The modal's own hide transition takes the sheet from where the finger left it down and out
            this.close();
            return;
        }

        this.springBack();
    }

    springBack() {
        if (!this.dialog) {
            return;
        }

        this.dialog.style.transition = this.reducedMotion.matches ? 'none' : 'transform .2s ease-out';
        this.dialog.style.transform = '';
    }

    endDrag() {
        const drag = this.drag;
        this.drag = null;

        if (drag === null || !this.hasHandleTarget || drag.id === 'touch') {
            return;
        }

        if (this.handleTarget.hasPointerCapture?.(drag.id)) {
            this.handleTarget.releasePointerCapture(drag.id);
        }

        this.handleTarget.removeEventListener('pointermove', this.onMove);
        this.handleTarget.removeEventListener('pointerup', this.onUp);
        this.handleTarget.removeEventListener('pointercancel', this.onCancel);
    }

    resetDialog() {
        if (this.dialog) {
            this.dialog.style.transition = '';
            this.dialog.style.transform = '';
        }
    }
}
