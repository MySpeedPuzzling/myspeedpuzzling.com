import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
        type: String,
    };

    dismiss() {
        fetch(this.urlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `type=${this.typeValue}`,
        });

        this.element.remove();
    }
}
