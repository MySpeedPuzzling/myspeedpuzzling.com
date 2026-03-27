import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    connect() {
        const emptyOption = this.element.querySelector('option[value=""]');
        const placeholder = emptyOption ? emptyOption.textContent.trim() : '';
        if (emptyOption) emptyOption.textContent = '';

        this.tomSelect = new TomSelect(this.element, {
            allowEmptyOption: true,
            placeholder: placeholder,
        });

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
