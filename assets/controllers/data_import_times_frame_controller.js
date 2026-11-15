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

        this.element.addEventListener('turbo:frame-missing', this.onFrameMissing);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-missing', this.onFrameMissing);
    }
}
