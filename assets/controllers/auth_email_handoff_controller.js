/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'msp.authEmail';

/**
 * Carries the typed email address between the auth pages (sign in → sign-in
 * link → forgot password → back) without putting it in a URL
 * (docs/features/auth-ux-redesign.md §6.2).
 *
 * sessionStorage is per tab and dies with it - the right lifetime - and is
 * never sent to the server, so the address stays out of access logs, history
 * and Referer headers. A value the server rendered always wins; an empty
 * field is filled from storage. Storage can be unavailable (private mode,
 * blocked site data): then this simply does nothing.
 *
 * `known` targets hold an address the page already shows (the "check your
 * email" screen), so "Change it" comes back with it filled in.
 */
export default class extends Controller {
    static targets = ['known'];

    connect() {
        this.remember = this.remember.bind(this);

        this.knownTargets.forEach((element) => this.store(element.textContent.trim()));

        this.fields().forEach((field) => {
            if (field.value.trim() !== '') {
                this.store(field.value.trim());
            } else {
                const stored = this.read();

                if (stored) {
                    field.value = stored;
                }
            }

            field.addEventListener('input', this.remember);
        });
    }

    disconnect() {
        this.fields().forEach((field) => field.removeEventListener('input', this.remember));
    }

    remember(event) {
        this.store(event.target.value.trim());
    }

    fields() {
        return this.element.querySelectorAll('input[type="email"][autocomplete~="username"]');
    }

    read() {
        try {
            return window.sessionStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    store(value) {
        try {
            if (value === '') {
                window.sessionStorage.removeItem(STORAGE_KEY);
            } else {
                window.sessionStorage.setItem(STORAGE_KEY, value);
            }
        } catch (e) {
            // Storage blocked - the hand-off is a convenience only
        }
    }
}
