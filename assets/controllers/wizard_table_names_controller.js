/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { generate } from '../table_labels_editor.js';

/**
 * The creation wizard's "Name the tables like your hall plan" (docs/features/competitions-management/spws-wizard.md
 * package D): a live preview per category - "Pairs: 350 names, A1 L … G22 R" - over the seating page's generator
 * (assets/table_labels_editor.js, twin of TableLabelGenerator). Nothing is written from here: the form posts, the
 * server generates and validates again.
 */
export default class extends Controller {
    static targets = ['letters', 'numbers', 'order', 'skip', 'seatStyle', 'seats', 'preview'];

    static values = { texts: Object };

    connect() {
        this.update();
    }

    update() {
        if (!this.hasPreviewTarget) {
            return;
        }

        const items = this.seatsTargets.map((select) => {
            const category = select.dataset.category ?? '';
            const name = this.textsValue.categories?.[category] ?? category;
            const result = generate({
                letters: this.lettersTarget.value,
                numbers: this.numbersTarget.value,
                seats: Number(select.value),
                seatStyle: this.seatStyleTarget.value,
                order: this.orderTarget.value,
                skip: this.skipTarget.value,
            });
            const item = document.createElement('li');

            if (Array.isArray(result.labels) && result.labels.length > 0) {
                item.textContent = this.fill(this.textsValue.summary, {
                    '%category%': name,
                    '%count%': String(result.labels.length),
                    '%first%': result.labels[0],
                    '%last%': result.labels[result.labels.length - 1],
                });
            } else {
                item.className = 'text-danger';
                item.textContent = this.fill(this.textsValue.problem, { '%category%': name });
            }

            return item;
        });

        this.previewTarget.replaceChildren(...items);
    }

    fill(text, values) {
        return Object.entries(values).reduce((result, [key, value]) => result.split(key).join(value), String(text ?? ''));
    }
}
