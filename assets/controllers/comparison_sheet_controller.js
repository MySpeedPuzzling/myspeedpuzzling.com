/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { getComponent } from '@symfony/ux-live-component';

/**
 * A Bootstrap modal inside the compare page's live component, driven by the server's render:
 *  - `open`: shown while true (the swap prompt opens itself when the line-up had no room);
 *  - `revision`: the line-up changed - whoever was picked is in, the add sheet closes;
 *  - `closeAction`: closed by the visitor (✕, Escape, backdrop, a "keep" button) while the server still wants it open
 *    → that live action tells the component, so a reload does not bring it back.
 * Bootstrap's own classes on the modal survive re-renders (Live Component keeps what JavaScript changed).
 */
export default class extends Controller {
    static values = {
        open: Boolean,
        revision: String,
        closeAction: String,
    };

    connect() {
        this.modal = Modal.getOrCreateInstance(this.element);
        this.onHidden = () => {
            if (this.openValue && this.hasCloseActionValue && this.closeActionValue !== '') {
                this.callAction(this.closeActionValue);
            }
        };
        this.element.addEventListener('hidden.bs.modal', this.onHidden);

        if (this.openValue) {
            this.modal.show();
        }
    }

    disconnect() {
        this.element.removeEventListener('hidden.bs.modal', this.onHidden);
        this.modal?.dispose();
        this.modal = null;
    }

    openValueChanged(open, previous) {
        // The first call comes before connect()
        if (!this.modal || previous === undefined) {
            return;
        }

        if (open) {
            this.modal.show();
        } else {
            this.modal.hide();
        }
    }

    revisionValueChanged(revision, previous) {
        if (!this.modal || previous === undefined || revision === previous) {
            return;
        }

        this.modal.hide();
    }

    async callAction(name) {
        const root = this.element.closest('[data-controller~="live"]');

        if (root === null) {
            return;
        }

        const component = await getComponent(root);
        component.action(name);
    }
}
