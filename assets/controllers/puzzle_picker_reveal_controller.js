import { Controller } from '@hotwired/stimulus';

/**
 * "Show 5 more" of the puzzle picker: the extra cards are already in the HTML
 * (over-fetched in the same query), hidden with d-none - revealing them costs
 * zero requests. The counter target switches from "1 of N" to "6 of N".
 */
export default class extends Controller {
    static targets = ['card', 'button', 'count'];

    reveal() {
        this.cardTargets.forEach((card) => card.classList.remove('d-none'));

        if (this.hasCountTarget) {
            this.countTarget.textContent = this.cardTargets.length;
        }

        if (this.hasButtonTarget) {
            this.buttonTarget.remove();
        }
    }
}
