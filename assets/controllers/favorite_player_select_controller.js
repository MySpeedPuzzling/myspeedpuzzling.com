import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["input", "select"];

    selectChanged(event) {
        const select = event.target;
        const input = this.inputTarget;

        if (select.value !== '') {
            input.value = `#${select.value}`;
            input.setAttribute('readonly', true);
        } else {
            input.value = '';
            input.removeAttribute('readonly');
        }
    }
}
