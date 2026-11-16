/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Around the lazy "unusual times" frame of the review before adding (docs/features/data-import/adding-screens.md
 * "Unusual times"). The frame loads a page of its own; when the answer does not hold the frame - signed out
 * meanwhile, the import gone, an error page - Turbo would leave the frame as it is and log an error. Like the other
 * frames of the import (data_import_setup_controller.js), such an answer becomes a full visit: the page itself says
 * what is. A request that fails outright leaves the frame's own content in place - a plain link to the same page.
 */
export default class extends Controller {
    connect() {
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
        };
        this.onFrameRender = (event) => {
            const frame = event.target;

            if (!this.swapped || !(frame instanceof Element)) {
                return;
            }

            const row = this.swapped.row === null ? null : document.getElementById(this.swapped.row);
            const target = (row === null ? null : row.querySelector('button, a[href]')) || frame.querySelector('[role="status"]');
            this.swapped = null;

            if (target instanceof HTMLElement) {
                if (!target.matches('a[href], button') && !target.hasAttribute('tabindex')) {
                    target.setAttribute('tabindex', '-1');
                }

                target.focus({ preventScroll: true });
            }
        };

        this.element.addEventListener('turbo:frame-missing', this.onFrameMissing);
        this.element.addEventListener('turbo:submit-start', this.onSubmitStart);
        this.element.addEventListener('turbo:frame-render', this.onFrameRender);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-missing', this.onFrameMissing);
        this.element.removeEventListener('turbo:submit-start', this.onSubmitStart);
        this.element.removeEventListener('turbo:frame-render', this.onFrameRender);
    }
}
