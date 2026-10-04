import { Controller } from '@hotwired/stimulus';

// Change request review: pick which proposed fields get applied.
// Unchecked fields are dimmed, the approve button needs at least one field.
export default class extends Controller {
    static targets = ['field', 'checkbox', 'selectAll', 'approveButton', 'selectedCount'];

    connect() {
        this.update();
    }

    toggleAll() {
        this.checkboxTargets.forEach((checkbox) => {
            checkbox.checked = this.selectAllTarget.checked;
        });
        this.update();
    }

    update() {
        const checked = this.checkboxTargets.filter((checkbox) => checkbox.checked).length;

        this.fieldTargets.forEach((field) => {
            const checkbox = field.querySelector('[data-change-request-fields-target="checkbox"]');
            field.classList.toggle('opacity-50', checkbox !== null && !checkbox.checked);
        });

        if (this.hasSelectAllTarget) {
            this.selectAllTarget.checked = checked === this.checkboxTargets.length;
            this.selectAllTarget.indeterminate = checked > 0 && checked < this.checkboxTargets.length;
        }

        if (this.hasApproveButtonTarget) {
            this.approveButtonTarget.disabled = checked === 0;
        }

        if (this.hasSelectedCountTarget) {
            this.selectedCountTarget.textContent = `${checked}/${this.checkboxTargets.length}`;
        }
    }
}
