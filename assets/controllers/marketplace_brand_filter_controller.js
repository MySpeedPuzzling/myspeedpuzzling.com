import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    connect() {
        const emptyOption = this.element.querySelector('option[value=""]');
        const placeholder = emptyOption ? emptyOption.textContent.trim() : '';
        if (emptyOption) emptyOption.remove();

        this.tomSelect = new TomSelect(this.element, {
            placeholder: placeholder,
            plugins: { clear_button: { title: '' } },
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
