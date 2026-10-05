/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Rows a quiet link on a label line adds - the add form's "+ name in another language" (docs/features/puzzle-names/,
 * decision 7), the "+ another" of a puzzle's EANs and brand codes (one input per code). Nothing shows until the link
 * is tapped. Every row comes from the <template> (a Symfony collection prototype) with `__name__` replaced by an index
 * of its own, × removes its row; the link hides while `max` rows stand. Rows the server rendered (a refused form sent
 * back) are plain rows - a code list's first input is one too, only without ×. Every change of the rows is announced
 * as `optional-rows:change` (the moderators' record form marks the field).
 */
export default class extends Controller {
    static targets = ['rows', 'row', 'template', 'add'];

    static values = {
        // The next free index - one past the highest the server rendered
        index: Number,
        // 0 = no limit
        max: { type: Number, default: 0 },
        prototypeName: { type: String, default: '__name__' },
    };

    add() {
        const row = this.appendRow();

        row?.querySelector('input, select, textarea')?.focus();
        this.dispatch('change');
    }

    remove(event) {
        event.target.closest('[data-optional-rows-target="row"]')?.remove();
        this.dispatch('change');
    }

    // A code list set to these values: the rows standing take them in order, rows are added or removed for the rest -
    // the first row stays (a code list's first input is never removed)
    load(values) {
        const wanted = Math.max(values.length, 1);

        while (this.rowTargets.length > wanted) {
            this.rowTargets[this.rowTargets.length - 1].remove();
        }

        for (let count = this.rowTargets.length; count < wanted; count += 1) {
            if (this.appendRow() === null) {
                break;
            }
        }

        this.rowTargets.forEach((row, index) => {
            const input = row.querySelector('input');

            if (input) {
                input.value = values[index] ?? '';
            }
        });

        this.dispatch('change');
    }

    appendRow() {
        if (this.isFull()) {
            return null;
        }

        const holder = document.createElement('div');
        holder.innerHTML = this.templateTarget.innerHTML.replaceAll(this.prototypeNameValue, String(this.indexValue)).trim();
        this.indexValue += 1;

        const row = holder.firstElementChild;
        this.rowsTarget.append(row);

        return row;
    }

    rowTargetConnected() {
        this.updateAdd();
    }

    rowTargetDisconnected() {
        this.updateAdd();
    }

    updateAdd() {
        this.addTargets.forEach((link) => {
            link.hidden = this.isFull();
        });
    }

    isFull() {
        return this.maxValue > 0 && this.rowTargets.length >= this.maxValue;
    }
}
