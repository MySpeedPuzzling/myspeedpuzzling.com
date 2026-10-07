/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Add/remove repeated rows of a page section form (FAQ questions, photos, sponsors, links). The template target holds
 * a row with __INDEX__ placeholders, replaced by a fresh index on insert; the new row's first field gets the focus.
 */
export default class extends Controller {
    static targets = ['container', 'template'];

    add() {
        const index = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
        const html = this.templateTarget.innerHTML.replaceAll('__INDEX__', index);
        this.containerTarget.insertAdjacentHTML('beforeend', html);
        this.containerTarget.lastElementChild?.querySelector('input:not([type="hidden"]), textarea')?.focus();
    }

    remove(event) {
        event.target.closest('[data-repeatable-row]')?.remove();
    }
}
