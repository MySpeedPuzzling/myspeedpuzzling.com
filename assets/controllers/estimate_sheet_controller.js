/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * A personal time estimate on a list row (templates/insights/estimate/_line.html.twig) opens the page's one estimate
 * sheet (#estimate-sheet, base.html.twig) filled with its own texts - rendered by the server into the values, so the
 * sheet never computes or translates anything. Rows without a value hide; the tier chip is copied from the row.
 * docs/features/insights/estimates.md
 */
export default class extends Controller {
    static values = {
        name: String,
        time: String,
        likely: String,
        range80: String,
        basis: String,
        certainty: String,
        estimated: Boolean,
    };

    open(event) {
        event.preventDefault();

        const sheet = document.getElementById('estimate-sheet');

        if (sheet === null) {
            return;
        }

        this.fill(sheet, 'name', this.nameValue);
        this.fill(sheet, 'time', this.timeValue);
        this.fill(sheet, 'likely', this.likelyValue);
        this.fill(sheet, 'range80', this.range80Value);
        this.fill(sheet, 'basis', this.basisValue);
        this.fill(sheet, 'certainty', this.certaintyValue);

        const tier = sheet.querySelector('[data-estimate-sheet-field="tier"]');
        const chip = this.element.querySelector('.est-chip');
        tier.replaceChildren();

        if (chip !== null) {
            const copy = chip.cloneNode(true);
            copy.querySelector('.est-chip__label')?.removeAttribute('aria-hidden');
            copy.querySelector('.visually-hidden')?.remove();
            tier.append(copy);
        }

        this.toggleRow(sheet, 'tier', chip !== null);
        this.toggleRow(sheet, 'estimated', this.estimatedValue);

        Modal.getOrCreateInstance(sheet).show(this.element);
    }

    fill(sheet, field, text) {
        const element = sheet.querySelector(`[data-estimate-sheet-field="${field}"]`);

        if (element !== null) {
            element.textContent = text;
        }

        this.toggleRow(sheet, field, text !== '');
    }

    toggleRow(sheet, row, visible) {
        const element = sheet.querySelector(`[data-estimate-sheet-row="${row}"]`) ?? sheet.querySelector(`[data-estimate-sheet-field="${row}"]`);

        if (element !== null) {
            element.hidden = !visible;
        }
    }
}
