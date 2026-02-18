import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["input", "select"];

    selectChanged(event) {
        const select = event.target;
        const input = this.inputTarget;

        if (select.value !== '') {
            input.value = `#${select.value}`;
            input.setAttribute('readonly', true);
            this.selectTargets.forEach(s => { if (s !== select) s.value = ''; });
        } else {
            input.value = '';
            input.removeAttribute('readonly');
        }
    }
}
