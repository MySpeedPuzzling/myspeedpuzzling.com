import { Controller } from '@hotwired/stimulus';
import * as bootstrap from 'bootstrap';

/**
 * The one-time "sign-in has moved home" explainer on the login page (D15, issue
 * #147). Shown exactly where the confusion strikes, and only once per browser.
 *
 * localStorage, never the session: the login page is reached by anonymous
 * visitors and must not gain a cookie (#164 anonymous-cacheability). The element
 * starts hidden and is only revealed here, so a dismissed modal never flashes in
 * for someone who has already read it.
 */
export default class extends Controller {
    static STORAGE_KEY = 'sign-in-cutover-modal-dismissed';

    connect() {
        let dismissed = null;

        try {
            dismissed = window.localStorage.getItem(this.constructor.STORAGE_KEY);
        } catch (error) {
            // Private mode or storage disabled: better to show it than to hide it
        }

        if (dismissed !== null) {
            this.element.remove();

            return;
        }

        this.modal = new bootstrap.Modal(this.element, {});
        this.modal.show();
    }

    dismiss() {
        try {
            window.localStorage.setItem(this.constructor.STORAGE_KEY, '1');
        } catch (error) {
            // Nothing to remember it with: the modal simply comes back next time
        }
    }

    disconnect() {
        if (this.modal) {
            this.modal.dispose();
            this.modal = null;
        }
    }
}
