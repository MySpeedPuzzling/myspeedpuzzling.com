import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    connect() {
        const emptyOption = this.element.querySelector('option[value=""]');
        const placeholder = emptyOption ? emptyOption.textContent.trim() : '';
        // Capture before removing the empty option: without an explicit
        // selection the browser would fall back to the first remaining option.
        const hadEmptyValue = !this.element.value;
        if (emptyOption) emptyOption.remove();

        this.tomSelect = new TomSelect(this.element, {
            placeholder: placeholder,
            plugins: { clear_button: { title: '' } },
        });

        if (hadEmptyValue) {
            this.tomSelect.clear(true);
        }

        this.tomSelect.on('change', () => {
            this.tomSelect.blur();
        });
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
        }
    }
}
