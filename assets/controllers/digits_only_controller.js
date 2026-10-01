import { Controller } from '@hotwired/stimulus';

/**
 * Whole-number field that accepts digits only (piece-count from-to).
 * Sits on an <input type="text" inputmode="numeric">: a type=number field lets
 * "e", "-", "+" and "." in and then reports an empty value for them.
 *
 * Typing a non-digit is refused before it lands; a mixed insertion (pasting
 * "1 000") and anything that cannot be refused (autofill, some IMEs) is let in
 * and stripped down to its digits on input.
 * List this action first in data-action so later input listeners read the
 * cleaned value.
 */
export default class extends Controller {
    connect() {
        this.refuse = this.refuse.bind(this);
        this.element.addEventListener('beforeinput', this.refuse);
        this.sanitize();
    }

    disconnect() {
        this.element.removeEventListener('beforeinput', this.refuse);
    }

    refuse(event) {
        if (event.data !== null && event.data !== undefined && !/\d/.test(event.data) && event.cancelable) {
            event.preventDefault();
        }
    }

    sanitize() {
        const value = this.element.value;
        const digits = value.replace(/\D+/g, '');

        if (digits !== value) {
            this.element.value = digits;
        }
    }
}
