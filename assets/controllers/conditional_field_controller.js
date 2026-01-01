import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['conditionalElement'];
    static values = {
        trigger: String,
        hideOn: { type: Boolean, default: false }
    }

    connect() {
        this._updateVisibility();

        // Listen for changes on radio buttons and selects within this controller
        this.element.addEventListener('change', (event) => {
            if (event.target.matches('input[type="radio"], select')) {
                this._updateVisibility(event.target.value);
            }
        });
    }

    toggle(event) {
        this._updateVisibility(event.target.value);
    }

    _updateVisibility(value = null) {
        if (!this.hasConditionalElementTarget) {
            return;
        }

        if (value === null) {
            // Try select first, then checked radio button
            const select = this.element.querySelector('select');
            if (select) {
                value = select.value;
            } else {
                const checkedRadio = this.element.querySelector('input[type="radio"]:checked');
                if (checkedRadio) {
                    value = checkedRadio.value;
                }
            }
        }

        const triggerValues = this.triggerValue.split(',').map(v => v.trim());
        const matches = triggerValues.includes(value);
        const shouldShow = this.hideOnValue ? !matches : matches;

        if (shouldShow) {
            this.conditionalElementTarget.classList.remove('d-none');
        } else {
            this.conditionalElementTarget.classList.add('d-none');
        }
    }
}
