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
 *
 * At most one submission in flight: iOS fills the code from Mail AND may submit
 * the form itself while our sixth-digit submit fires too - two POSTs, the
 * second finding the sign-in already used although the first one signed in.
 * Every submit after the first is cancelled here (the form's own listener runs
 * before Turbo's document-level one) until the request ends; the server treats
 * a straggler as a duplicate anyway (SignInCodeCompletion). A 422 answer renders
 * a fresh page, which reconnects this controller - turbo:submit-end only
 * matters when the request itself failed.
 */
export default class extends Controller {
    static targets = ['input', 'button'];

    connect() {
        this.lastSubmitted = null;
        this.submitting = false;
        this.onSubmitEnd = this.submitEnd.bind(this);
        this.element.addEventListener('turbo:submit-end', this.onSubmitEnd);

        // No autofocus attribute: on iPhones focusing the field on load pops
        // the iCloud Passwords "fill username" sheet instead of the code
        // suggestion. Mouse/trackpad users still land in the field.
        if (window.matchMedia && window.matchMedia('(pointer: fine)').matches) {
            this.inputTarget.focus({ preventScroll: true });
        }
    }

    disconnect() {
        this.element.removeEventListener('turbo:submit-end', this.onSubmitEnd);
    }

    paste(event) {
        if (this.submitting) {
            event.preventDefault();
            return;
        }

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
        if (this.submitting) {
            return;
        }

        const cleaned = this.digits(this.inputTarget.value);

        if (cleaned !== this.inputTarget.value) {
            this.inputTarget.value = cleaned;
        }

        this.submitWhenComplete();
    }

    /** submit->sign-in-code#submit - listed first on the form, so it runs before the rest */
    submit(event) {
        if (this.submitting) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        this.submitting = true;
        this.lastSubmitted = this.inputTarget.value;

        // Next tick: the submission is already built by then. readonly, not
        // disabled - a disabled input would be left out of the POST
        setTimeout(() => {
            if (!this.submitting) {
                return;
            }

            this.inputTarget.readOnly = true;
            this.element.setAttribute('aria-busy', 'true');

            if (this.hasButtonTarget) {
                this.buttonTarget.disabled = true;
            }
        }, 0);
    }

    submitEnd(event) {
        if (event.detail && event.detail.success) {
            // Redirecting away - stay locked
            return;
        }

        this.unlock();
    }

    unlock() {
        this.submitting = false;
        this.inputTarget.readOnly = false;
        this.element.removeAttribute('aria-busy');

        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = false;
        }
    }

    submitWhenComplete() {
        const value = this.inputTarget.value;

        if (this.submitting || value.length !== 6 || value === this.lastSubmitted) {
            return;
        }

        this.element.requestSubmit();
    }

    digits(value) {
        return value.replace(/\D+/g, '').slice(0, 6);
    }
}
