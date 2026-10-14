import { Controller } from '@hotwired/stimulus';

const EXPANDED = 'is-expanded';

export default class extends Controller {
    static targets = ['list', 'button'];

    connect() {
        // A list that connects expanded came from Turbo's copy of the page (a Back, GEST-15): Live Component - it connects
        // after this controller - would take the class for the server's and drop it at the next refresh. It goes back on
        // once Live watches the element, as a change of the page's own (what revealRows() makes); within this task, so
        // nothing is painted collapsed
        if (this.hasListTarget && this.listTarget.classList.contains(EXPANDED)) {
            const list = this.listTarget;
            const expand = () => list.classList.add(EXPANDED);

            list.classList.remove(EXPANDED);
            this.element.addEventListener('live:connect', expand, { once: true });
            // Without Live on this element: back at once, as it was
            queueMicrotask(() => {
                if (!this.element.matches('[data-controller~="live"]')) {
                    expand();
                }
            });
        }
    }

    revealRows() {
        if (this.hasListTarget) {
            // A class on an element that stays, not removed nodes: Live Component lays a class changed by JavaScript back
            // over every re-render, so an automatic refresh keeps the rows shown and the button hidden
            this.listTarget.classList.add(EXPANDED);
            return;
        }

        // Static lists (Hub most active / most solved): rows rendered hidden with d-none, the button goes away
        this.element.querySelectorAll('tr.d-none').forEach(row => row.classList.remove('d-none'));

        if (this.hasButtonTarget) {
            this.buttonTarget.remove();
        }
    }
}
