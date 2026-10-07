import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'offlineFields',
        'dateFields',
        'dateLabel',
        'onlineDatesHelp',
        'recurringField',
        'typeSelectedFields',
        'editionLinks',
        'editionLinksNote',
    ];

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

        // Dates are required for an in-person event only - an online one may leave them empty (ongoing)
        this.dateLabelTargets.forEach(el => {
            el.classList.toggle('required', !isOnline);
        });

        this.onlineDatesHelpTargets.forEach(el => {
            el.style.display = isOnline ? '' : 'none';
        });

        // A series has no registration and results links - its editions have them
        this.editionLinksTargets.forEach(el => {
            el.style.display = isRecurring ? 'none' : '';
        });

        this.editionLinksNoteTargets.forEach(el => {
            el.style.display = isRecurring ? '' : 'none';
        });

        if (this.hasRecurringFieldTarget) {
            this.recurringFieldTarget.style.display = hasSelection ? '' : 'none';
        }

        this.typeSelectedFieldsTargets.forEach(el => {
            el.style.display = hasSelection ? '' : 'none';
        });
    }
}
