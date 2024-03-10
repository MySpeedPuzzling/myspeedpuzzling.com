import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['existingPuzzleForm', 'customPuzzleForm', 'checkbox'];

    connect() {
        this.toggleForms(); // Call on connect to set the initial state
    }

    toggleForms() {
        if (this.checkboxTarget.checked) {
            this.customPuzzleFormTarget.style.display = 'block';
            this.existingPuzzleFormTarget.style.display = 'none';
        } else {
            this.customPuzzleFormTarget.style.display = 'none';
            this.existingPuzzleFormTarget.style.display = 'block';
        }
    }

    update() {
        this.toggleForms();
    }
}
