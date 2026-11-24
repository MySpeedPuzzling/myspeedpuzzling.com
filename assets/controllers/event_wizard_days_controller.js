/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { carryDay } from '../event_wizard_days.js';

/**
 * The wizard's round starts (templates/event_wizard/structure.html.twig, spws-demo-ux-leftovers.md §7): a day picked in
 * one row fills the empty day fields of the rows after it (event_wizard_days.js). Without JavaScript every day is typed
 * as before.
 *
 *   <form data-controller="event-wizard-days">
 *     <input type="date" data-event-wizard-days-target="day" data-action="change->event-wizard-days#picked">
 */
export default class extends Controller {
    static targets = ['day'];

    picked(event) {
        const inputs = this.dayTargets;
        const changed = inputs.indexOf(event.currentTarget);
        const days = carryDay(inputs.map((input) => input.value), changed);

        inputs.forEach((input, index) => {
            if (input.value !== days[index]) {
                input.value = days[index];
            }
        });
    }
}
