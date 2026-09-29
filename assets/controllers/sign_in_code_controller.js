/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The 6-digit code input on "Check your email" (auth UX redesign phase 2).
 * Progressive: the plain form works without this (the server strips spaces).
 *
 * - paste: "123 456", "123-456" or a whole sentence from the mail keeps its
 *   digits only - before maxlength="6" could cut "123 456" to "123 45";
 * - input: anything that is not a digit is dropped as it is typed;
 * - six digits (typed, pasted or filled by the OS's one-time-code autofill)
 *   submit the form. The same six digits are never sent twice in a row, so
 *   a wrong code does not loop - changing a digit sends again.
 */
export default class extends Controller {
    static targets = ['input'];

    connect() {
        this.lastSubmitted = null;
    }

    paste(event) {
        const text = event.clipboardData ? event.clipboardData.getData('text') : null;

        if (text === null) {
            return;
        }

        event.preventDefault();

        const input = this.inputTarget;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        const merged = input.value.slice(0, start) + text + input.value.slice(end);

        input.value = this.digits(merged);
        this.submitWhenComplete();
    }

    input() {
        const cleaned = this.digits(this.inputTarget.value);

        if (cleaned !== this.inputTarget.value) {
            this.inputTarget.value = cleaned;
        }

        this.submitWhenComplete();
    }

    submitWhenComplete() {
        const value = this.inputTarget.value;

        if (value.length !== 6 || value === this.lastSubmitted) {
            return;
        }

        this.lastSubmitted = value;
        this.element.requestSubmit();
    }

    digits(value) {
        return value.replace(/\D+/g, '').slice(0, 6);
    }
}
