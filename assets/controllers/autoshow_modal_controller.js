import { Controller } from '@hotwired/stimulus';
import * as bootstrap from 'bootstrap';

/**
 * Opens an announcement modal on page load (docs/features/announcement-modals.md) and tells the server
 * once it really opened. Whether the modal is on the page at all was decided - and recorded - by the
 * server; the report below only feeds the "rendered vs. actually seen" numbers and decides nothing.
 */
export default class extends Controller {
    static values = {
        seenUrl: String,
        name: String,
    };

    connect() {
        this.onShown = () => this.reportSeen();
        this.element.addEventListener('shown.bs.modal', this.onShown, { once: true });

        this.modal = new bootstrap.Modal(this.element, {});
        this.modal.show();
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.modal', this.onShown);
    }

    reportSeen() {
        if (!this.hasSeenUrlValue || !this.hasNameValue) {
            return;
        }

        const body = new URLSearchParams({ modal: this.nameValue });

        fetch(this.seenUrlValue, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    }
}
