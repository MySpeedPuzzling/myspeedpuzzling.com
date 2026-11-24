import { Controller } from '@hotwired/stimulus';

/*
 * One-tap collection chips above a collection picker (templates/collections/_collection_quick_pick.html.twig,
 * docs/features/collections/default-collection.md). The picker input stays the submitted field: a chip only sets its
 * value (through TomSelect when it is there, which fires the input's own change event the forms listen to), and the
 * chips follow whatever the picker holds. Without JavaScript the picker alone still works.
 */
export default class extends Controller {
    static targets = ['chip'];
    static values = { inputId: String };

    connect() {
        this.onChange = () => this.sync();
        this.input = document.getElementById(this.inputIdValue);

        if (this.input === null) {
            return;
        }

        this.input.addEventListener('change', this.onChange);
        this.sync();
    }

    disconnect() {
        this.input?.removeEventListener('change', this.onChange);
    }

    pick(event) {
        if (this.input === null) {
            return;
        }

        const value = event.currentTarget.dataset.value;
        const tomselect = this.input.tomselect;

        if (tomselect) {
            tomselect.setValue(value);
            tomselect.blur();
        } else {
            this.input.value = value;
            this.input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        this.sync();
    }

    sync() {
        const value = this.input.value;

        this.chipTargets.forEach((chip) => {
            const on = chip.dataset.value === value;

            chip.classList.toggle('btn-primary', on);
            chip.classList.toggle('btn-outline-primary', !on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
            chip.querySelector('[data-check]')?.classList.toggle('d-none', !on);
        });
    }
}
