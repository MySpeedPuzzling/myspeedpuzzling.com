/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { carryDay } from '../event_wizard_days.js';

/**
 * The wizard's round starts (templates/event_wizard/structure.html.twig, spws-demo-ux-leftovers.md §7): a day picked in
 * one row fills the day fields below it that are empty or were filled this way (event_wizard_days.js) - never a day the
 * organiser picked. Without JavaScript every day is typed as before.
 *
 *   <form data-controller="event-wizard-days">
 *     <input type="date" data-event-wizard-days-target="day" data-action="change->event-wizard-days#picked">
 */
export default class extends Controller {
    static targets = ['day'];

    picked(event) {
        const inputs = this.dayTargets;
        const changed = inputs.indexOf(event.currentTarget);
        const { days, carried } = carryDay(inputs.map((input) => input.value), changed, inputs.map((input) => input.dataset.carried === '1'));

        inputs.forEach((input, index) => {
            if (input.value !== days[index]) {
                input.value = days[index];
            }

            if (carried[index]) {
                input.dataset.carried = '1';
            } else {
                delete input.dataset.carried;
            }
        });
    }
}
