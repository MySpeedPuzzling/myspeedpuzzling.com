import { Controller } from '@hotwired/stimulus';

// "The box has no barcode (EAN)" in "Suggest a change": while ticked, the EAN inputs (and the scan button) are hidden
// and disabled - a disabled input is not sent, so the proposal never carries an EAN and "no barcode" at once. Unticked,
// they come back as they were.
export default class extends Controller {
    static targets = ['checkbox', 'codes'];

    connect() {
        this.toggle();
    }

    toggle() {
        if (!this.hasCheckboxTarget || !this.hasCodesTarget) {
            return;
        }

        const absent = this.checkboxTarget.checked;

        this.codesTarget.classList.toggle('d-none', absent);
        this.codesTarget.querySelectorAll('input, button').forEach((element) => {
            element.disabled = absent;
        });
    }
}
