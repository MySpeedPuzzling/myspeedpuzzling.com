/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Rows a quiet link on a label line adds - the add form's "+ name in another language" (docs/features/puzzle-names/,
 * decision 7), any "+ another" list of inputs. Nothing shows until the link is tapped. Every row comes from the
 * <template> (a Symfony collection prototype) with `__name__` replaced by an index of its own, × removes its row; the
 * link hides while `max` rows stand. Rows the server rendered (a refused form sent back) are plain rows.
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
        if (this.isFull()) {
            return;
        }

        const holder = document.createElement('div');
        holder.innerHTML = this.templateTarget.innerHTML.replaceAll(this.prototypeNameValue, String(this.indexValue)).trim();
        this.indexValue += 1;

        const row = holder.firstElementChild;
        this.rowsTarget.append(row);
        row.querySelector('input, select, textarea')?.focus();
    }

    remove(event) {
        event.target.closest('[data-optional-rows-target="row"]')?.remove();
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
