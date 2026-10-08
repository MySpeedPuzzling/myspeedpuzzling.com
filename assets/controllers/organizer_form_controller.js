/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Organiser forms (docs/features/organizations/README.md "Forms", "Add several dates"): a part of the form is shown only
 * while another field holds one of some values - "When it happens" while "Recurring" is ticked, the rule's fields while
 * "Repeat" is chosen, "Which week" only for "Every month on the …". Without JavaScript every part stays visible (the
 * server ignores what the chosen way does not use).
 *
 *   <div data-controller="organizer-form" data-action="change->organizer-form#update">
 *     <div data-organizer-form-target="part" data-show-field="isRecurring" data-show-values="1">…</div>
 *
 * `data-show-field` is the field's name (`how`) or the last part of it (`competition_form[isRecurring]`),
 * `data-show-values` the values (space separated) that show the part; an unticked checkbox has the value "".
 */
export default class extends Controller {
    static targets = ['part'];

    connect() {
        this.update();
    }

    update() {
        this.partTargets.forEach((part) => {
            const values = (part.dataset.showValues || '').split(' ');
            part.hidden = !values.includes(this.valueOf(part.dataset.showField || ''));
        });
    }

    valueOf(field) {
        const inputs = [...this.element.querySelectorAll('input, select, textarea')].filter((input) => {
            return input.name === field || input.name.endsWith(`[${field}]`);
        });

        for (const input of inputs) {
            if (input.type === 'checkbox') {
                return input.checked ? input.value : '';
            }

            if (input.type === 'radio') {
                if (input.checked) {
                    return input.value;
                }

                continue;
            }

            return input.value;
        }

        return '';
    }
}
