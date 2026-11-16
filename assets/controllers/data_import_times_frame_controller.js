/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

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
// What is waited for is not a row: the notice "being read again"
const NOTICE = '@notice';

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
            this.address(frame);

            // Something of the list is on its way back - a row that is "coming back" stands in its place without its
            // buttons, a sheet (or the whole file) is "being read again": the frame is asked for again until it is
            // there - then the rows have their tick boxes and the count is right, without a reload. Not for ever:
            // after the last time the page says it takes longer, with "Refresh"
            const coming = frame.querySelector('[data-import-coming-back]');
            const waiting = coming || frame.querySelector('[data-import-reading-again]');
            const waitedFor = this.keep;

            window.clearTimeout(this.timer);

            if (waiting !== null && typeof frame.reload === 'function') {
                this.keep = coming !== null ? coming.id : NOTICE;

                if (this.asked < ASK_AGAIN_TIMES) {
                    this.timer = window.setTimeout(() => {
                        this.asked += 1;
                        frame.reload();
                    }, this.askAgainMsValue);
                } else {
                    // Asked for the last time: no spinner that turns for ever
                    frame.querySelectorAll('[data-import-waiting-spinner]').forEach((spinner) => spinner.remove());
                    frame.querySelectorAll('[data-import-taking-longer]').forEach((line) => line.removeAttribute('hidden'));
                    frame.querySelectorAll('[data-import-usually-at-once]').forEach((line) => line.setAttribute('hidden', ''));
                }
            } else {
                this.keep = null;
            }

            // Where focus goes. After an answer: the row that was answered (its control - or the row itself while it
            // is coming back), else what the frame says happened. While something is waited for, and when it is
            // there: the same row (or notice) again - but only when focus is nowhere, never taken from the player
            const somewhere = document.activeElement !== null && document.activeElement !== document.body && document.activeElement.isConnected;
            const id = this.swapped ? this.swapped.row : (this.keep || waitedFor);

            if (!this.swapped && (id === null || somewhere)) {
                return;
            }

            const row = id === null || id === NOTICE ? null : document.getElementById(id);
            // (a control that is shown - "Refresh" of a row that takes longer is in the page all along, hidden)
            const control = row === null ? null : [...row.querySelectorAll('button, a[href]')].find((element) => element.closest('[hidden]') === null);
            const target = (row === null ? null : control || row) || frame.querySelector('[data-import-reading-again]') || frame.querySelector('[role="status"]');
            this.swapped = null;

            if (target instanceof HTMLElement) {
                if (!target.matches('a[href], button') && !target.hasAttribute('tabindex')) {
                    target.setAttribute('tabindex', '-1');
                }

                // In view below the site's header (a notice at the frame's top stands under it otherwise)
                const header = parseFloat(window.getComputedStyle(document.documentElement).getPropertyValue('--header-height')) || 101;
                const top = target.getBoundingClientRect().top;

                if (top < header + 20) {
                    window.scrollBy({ top: top - header - 20, behavior: 'auto' });
                }

                target.focus({ preventScroll: true });
            }
        };

        // A bar fixed to the bottom of the window (the list's "N will be added"): the frame keeps its real height free
        this.element.querySelectorAll('turbo-frame').forEach((frame) => this.room(frame));

        // A folded question opened inside the frame ("Don't import any" → "Yes, don't import this file"): it opens
        // near the end of the screen, behind the bar that is fixed there - brought above the bar, its button focused
        this.onToggle = (event) => {
            const question = event.target;

            if (!(question instanceof HTMLElement) || !question.matches('details[open]') || question.closest('turbo-frame') === null) {
                return;
            }

            const bar = question.closest('turbo-frame').querySelector('[data-import-fixed-bar]');
            const button = question.querySelector('button[type="submit"]');

            if (bar === null || button === null) {
                return;
            }

            const limit = window.innerHeight - Math.ceil(bar.getBoundingClientRect().height) - 8;
            const bottom = question.getBoundingClientRect().bottom;

            if (bottom > limit) {
                window.scrollBy({ top: bottom - limit, behavior: 'auto' });
            }

            button.focus({ preventScroll: true });
        };

        this.element.addEventListener('toggle', this.onToggle, true);
        this.element.addEventListener('turbo:frame-missing', this.onFrameMissing);
        this.element.addEventListener('turbo:submit-start', this.onSubmitStart);
        this.element.addEventListener('turbo:frame-render', this.onFrameRender);
    }

    /**
     * A frame that says the address of what it shows ("What will be added": the filters, the page, the sheet that
     * was left out): after a swap the address bar says the same - a reload, or the address opened again, shows this
     * list (with "Put back" for the sheet), not the one before the answer.
     */
    address(frame) {
        // Said by an element inside the frame: a swap brings the frame's content, not its own attributes
        const holder = frame.querySelector('[data-import-frame-address]');
        const said = holder === null ? null : holder.getAttribute('data-import-frame-address');

        if (!said) {
            return;
        }

        const url = new URL(said, window.location.href);

        if (url.pathname + url.search === window.location.pathname + window.location.search) {
            return;
        }

        const history = Turbo.navigator ? Turbo.navigator.history : null;

        if (history && typeof history.replace === 'function') {
            history.replace(url);
        } else {
            window.history.replaceState(window.history.state, '', url.href);
        }
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
        this.element.removeEventListener('toggle', this.onToggle, true);
        this.element.removeEventListener('turbo:frame-missing', this.onFrameMissing);
        this.element.removeEventListener('turbo:submit-start', this.onSubmitStart);
        this.element.removeEventListener('turbo:frame-render', this.onFrameRender);
    }
}
