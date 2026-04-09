import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['offlineFields', 'dateFields', 'recurringField', 'typeSelectedFields'];

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
        const recurringCheckbox = this.element.querySelector('input[type="checkbox"][name*="isRecurring"]');
        const isRecurring = recurringCheckbox !== null && recurringCheckbox.checked;

        this.offlineFieldsTargets.forEach(el => {
            el.style.display = hasSelection && !isOnline ? '' : 'none';
        });

        this.dateFieldsTargets.forEach(el => {
            el.style.display = hasSelection && !isRecurring ? '' : 'none';
        });

        if (this.hasRecurringFieldTarget) {
            this.recurringFieldTarget.style.display = hasSelection ? '' : 'none';
        }

        this.typeSelectedFieldsTargets.forEach(el => {
            el.style.display = hasSelection ? '' : 'none';
        });
    }
}
