import { Controller } from '@hotwired/stimulus';

/**
 * Add/remove repeated form rows. The template target holds a prototype with
 * __INDEX__ placeholders replaced by a fresh index on insert.
 */
export default class extends Controller {
    static targets = ['container', 'template'];

    add() {
        const index = Date.now();
        const html = this.templateTarget.innerHTML.replaceAll('__INDEX__', String(index));
        this.containerTarget.insertAdjacentHTML('beforeend', html);
    }

    remove(event) {
        const row = event.target.closest('[data-repeatable-row]');
        if (row) {
            row.remove();
        }
    }
}
