import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['conditionalElement'];
    static values = {
        trigger: String,
        hideOn: { type: Boolean, default: false }
    }

    connect() {
        const checkbox = this.element.querySelector('input[type="checkbox"]');
        if (checkbox) {
            this._updateCheckboxVisibility();
        } else {
            this._updateVisibility();
        }

        // Listen for changes on radio buttons, selects and checkboxes within this controller
        // Ignore events from inside the conditional element (e.g. a select inside the toggled area)
        this.element.addEventListener('change', (event) => {
            if (this.hasConditionalElementTarget && this.conditionalElementTarget.contains(event.target)) {
                return;
            }

            if (event.target.matches('input[type="radio"], select')) {
                this._updateVisibility(event.target.value);
            } else if (event.target.matches('input[type="checkbox"]')) {
                this._updateCheckboxVisibility(event.target.checked);
            }
        });
    }

    toggle(event) {
        if (event.target.matches('input[type="checkbox"]')) {
            this._updateCheckboxVisibility(event.target.checked);
        } else {
            this._updateVisibility(event.target.value);
        }
    }

    _updateCheckboxVisibility(checked = null) {
        if (!this.hasConditionalElementTarget) {
            return;
        }

        if (checked === null) {
            const checkbox = this.element.querySelector('input[type="checkbox"]');
            checked = checkbox ? checkbox.checked : false;
        }

        const shouldShow = this.hideOnValue ? !checked : checked;

        if (shouldShow) {
            this.conditionalElementTarget.classList.remove('d-none');
        } else {
            this.conditionalElementTarget.classList.add('d-none');
        }
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
