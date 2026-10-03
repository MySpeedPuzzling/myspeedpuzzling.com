import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list', 'button'];

    revealRows() {
        if (this.hasListTarget) {
            // A class on an element that stays, not removed nodes: Live Component lays a class changed by JavaScript back
            // over every re-render, so an automatic refresh keeps the rows shown and the button hidden
            this.listTarget.classList.add('is-expanded');
            return;
        }

        // Static lists (Hub most active / most solved): rows rendered hidden with d-none, the button goes away
        this.element.querySelectorAll('tr.d-none').forEach(row => row.classList.remove('d-none'));

        if (this.hasButtonTarget) {
            this.buttonTarget.remove();
        }
    }
}
