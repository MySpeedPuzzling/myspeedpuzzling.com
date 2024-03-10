import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["form"];

    connect() {
        this.formTarget.addEventListener('input', () => this.submitForm());
        this.formTarget.addEventListener('change', () => this.submitForm());
    }

    submitForm() {
        /*
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => {
            this.formTarget.requestSubmit();
        }, 500); // Adjust debounce time as needed
        */
    }
}
