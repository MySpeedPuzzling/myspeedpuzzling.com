import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list'];

    revealRows() {
        // A class on an element that stays, not removed nodes: Live Component lays a class changed by JavaScript back
        // over every re-render, so an automatic refresh keeps the rows shown and the button hidden
        this.listTarget.classList.add('is-expanded');
    }
}
