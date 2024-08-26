import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["submit"];
    static values = {
        isSubmitting: Boolean
    }

    connect() {
        this.isSubmittingValue = false; // Initialize the submission status
        this.element.addEventListener('submit', this.preventDuplicateSubmission.bind(this)); // Listen for the form submission
    }

    preventDuplicateSubmission(event) {
        if (this.isSubmittingValue) {
            event.preventDefault(); // Prevent the form from submitting again
            return;
        }

        this.isSubmittingValue = true; // Mark the form as being submitted

        this.disableSubmitButton(); // Disable the submit button

        // Optionally, you can include a setTimeout to re-enable the button if something goes wrong
        setTimeout(() => {
            if (this.isSubmittingValue) {
                this.isSubmittingValue = false;
                this.enableSubmitButton(); // Re-enable the button in case of a timeout
            }
        }, 10000); // Adjust the timeout duration as needed
    }

    disableSubmitButton() {
        this.submitTarget.setAttribute('disabled', 'disabled');
        this.submitTarget.classList.add('is-loading'); // Optional: Add a loading class for UI feedback
    }

    enableSubmitButton() {
        this.submitTarget.removeAttribute('disabled');
        this.submitTarget.classList.remove('is-loading'); // Remove the loading class
    }
}
