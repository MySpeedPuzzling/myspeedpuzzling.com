import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["submit"];
    static values = {
        isSubmitting: Boolean
    }

    connect() {
        this.isSubmittingValue = false;
        this.element.addEventListener('submit', this.preventDuplicateSubmission.bind(this));

        // Re-enable button when Turbo finishes (success, redirect, or error)
        this.element.addEventListener('turbo:submit-end', this.reset.bind(this));
    }

    preventDuplicateSubmission(event) {
        if (this.isSubmittingValue) {
            event.preventDefault();
            return;
        }

        this.isSubmittingValue = true;
        this.disableSubmitButton();
    }

    reset() {
        this.isSubmittingValue = false;
        this.enableSubmitButton();
    }

    disableSubmitButton() {
        this.submitTarget.setAttribute('disabled', 'disabled');
        this.submitTarget.classList.add('is-loading');
    }

    enableSubmitButton() {
        this.submitTarget.removeAttribute('disabled');
        this.submitTarget.classList.remove('is-loading');
    }
}
