import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['offlineFields', 'recurringField', 'typeSelectedFields'];

    connect() {
        this._toggle();
    }

    toggle() {
        this._toggle();
    }

    _toggle() {
        const checkedRadio = this.element.querySelector('input[type="radio"]:checked');
        const hasSelection = checkedRadio !== null;
        const isOnline = checkedRadio !== null && checkedRadio.value === '1';

        this.offlineFieldsTargets.forEach(el => {
            el.style.display = hasSelection && !isOnline ? '' : 'none';
        });

        this.recurringFieldTarget.style.display = hasSelection && isOnline ? '' : 'none';

        this.typeSelectedFieldsTargets.forEach(el => {
            el.style.display = hasSelection ? '' : 'none';
        });
    }
}
