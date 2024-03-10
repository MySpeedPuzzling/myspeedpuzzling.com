import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['nameRow', 'idRow', 'checkbox'];

    connect() {
        this.toggleVisibility(); // Set the initial state on connect
    }

    toggleVisibility() {
        const isChecked = this.checkboxTarget.checked;
        this.nameRowTarget.style.display = isChecked ? 'block' : 'none';
        this.idRowTarget.style.display = isChecked ? 'none' : 'block';
    }

    showName(e) {
        e.preventDefault();
        this.checkboxTarget.checked = true;
        this.toggleVisibility();
    }

    showId(e) {
        e.preventDefault();
        this.checkboxTarget.checked = false;
        this.toggleVisibility();
    }
}
