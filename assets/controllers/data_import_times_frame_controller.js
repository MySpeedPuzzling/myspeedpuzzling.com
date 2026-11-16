/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Around the lazy "unusual times" frame of the review before adding (docs/features/data-import/adding-screens.md
 * "Unusual times"). The frame loads a page of its own; when the answer does not hold the frame - signed out
 * meanwhile, the import gone, an error page - Turbo would leave the frame as it is and log an error. Like the other
 * frames of the import (data_import_setup_controller.js), such an answer becomes a full visit: the page itself says
 * what is. A request that fails outright leaves the frame's own content in place - a plain link to the same page.
 */
// A row that was put back is ready again when its puzzle is matched again - a moment on the worker: asked for
// this often, this many times at most
const ASK_AGAIN_MS = 1500;
const ASK_AGAIN_TIMES = 12;

export default class extends Controller {
    static values = {
        // (a test asks faster)
        askAgainMs: { type: Number, default: ASK_AGAIN_MS },
    };

    connect() {
        this.asked = 0;
        this.keep = null;

        this.onFrameMissing = (event) => {
            if (!(event.target instanceof Element) || !this.element.contains(event.target)) {
                return;
            }

            event.preventDefault();
            event.detail.visit(event.detail.response.url || window.location.href);
        };

        // A form that swaps its frame ("Don't import" / "Put back" on "What will be added") takes the tapped button
        // away with the old frame: focus would be on nothing. It goes back to the same row's control in the new
        // frame - or, when the row is not there any more, to what the frame says happened.
        this.onSubmitStart = (event) => {
            const form = event.target;
            const row = form instanceof Element ? form.closest('[id^="row-"]') : null;

            this.swapped = form instanceof Element && form.hasAttribute('data-turbo-frame') ? { row: row === null ? null : row.id } : null;
            // Another answer: whatever was waited for is not waited for by this page any more
            window.clearTimeout(this.timer);
            this.asked = 0;
            this.keep = null;
        };
        this.onFrameRender = (event) => {
            const frame = event.target;

            if (!(frame instanceof Element)) {
                return;
            }

            this.room(frame);

            // A row that is "coming back" stands in its place without its buttons: the frame is asked for again
            // until the row is ready - then it has its tick box again and the count is right, without a reload
            const coming = frame.querySelector('[data-import-coming-back]');

            if (coming !== null && this.asked < ASK_AGAIN_TIMES && typeof frame.reload === 'function') {
                this.keep = coming.id;
                window.clearTimeout(this.timer);
                this.timer = window.setTimeout(() => {
                    this.asked += 1;
                    frame.reload();
                }, this.askAgainMsValue);
            }

            const waitedFor = coming === null && this.keep !== null ? this.keep : null;

            if (coming === null) {
                this.keep = null;
            }

            if (!this.swapped && waitedFor === null) {
                return;
            }

            // Where focus goes: the row that was answered (its control - or the row itself while it is coming back),
            // else what the frame says happened. A row that was waited for takes focus only when focus is nowhere
            const id = this.swapped ? this.swapped.row : waitedFor;
            const row = id === null ? null : document.getElementById(id);
            const somewhere = document.activeElement !== null && document.activeElement !== document.body && document.activeElement.isConnected;

            if (!this.swapped && somewhere) {
                return;
            }

            const target = (row === null ? null : row.querySelector('button, a[href]') || row) || frame.querySelector('[role="status"]');
            this.swapped = null;

            if (target instanceof HTMLElement) {
                if (!target.matches('a[href], button') && !target.hasAttribute('tabindex')) {
                    target.setAttribute('tabindex', '-1');
                }

                target.focus({ preventScroll: true });
            }
        };

        // A bar fixed to the bottom of the window (the list's "N will be added"): the frame keeps its real height free
        this.element.querySelectorAll('turbo-frame').forEach((frame) => this.room(frame));

        this.element.addEventListener('turbo:frame-missing', this.onFrameMissing);
        this.element.addEventListener('turbo:submit-start', this.onSubmitStart);
        this.element.addEventListener('turbo:frame-render', this.onFrameRender);
    }

    room(frame) {
        const bar = frame.querySelector('[data-import-fixed-bar]');

        if (bar !== null) {
            const height = Math.ceil(bar.getBoundingClientRect().height);

            if (height > 0) {
                frame.style.paddingBottom = `${height + 16}px`;
            }
        }
    }

    disconnect() {
        window.clearTimeout(this.timer);
        this.element.removeEventListener('turbo:frame-missing', this.onFrameMissing);
        this.element.removeEventListener('turbo:submit-start', this.onSubmitStart);
        this.element.removeEventListener('turbo:frame-render', this.onFrameRender);
    }
}
