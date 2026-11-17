/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "Move the rounds too" on the edit event form (templates/edit_competition.html.twig, docs/features/competitions-management/
 * README.md "Moving an event's date"): asked only once the start date differs from the saved one (smart forms - a
 * question that changes nothing is not asked). Without JavaScript the checkbox is simply visible. The date picker
 * (flatpickr) writes the original input and fires its change event, which bubbles here.
 */
export default class extends Controller {
    static targets = ['date', 'option'];

    static values = {
        original: String,
    };

    connect() {
        this.update();
    }

    update() {
        if (!this.hasDateTarget || !this.hasOptionTarget) {
            return;
        }

        this.optionTarget.hidden = this.dateTarget.value.trim() === this.originalValue;
    }
}
