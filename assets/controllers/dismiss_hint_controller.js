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

        // One dismissal hides every copy of the hint on the page - the navigation launch's pointer is on the Hub and at
        // the top of the Menu at once (docs/features/navigation decision 52)
        document.querySelectorAll('[data-controller~="dismiss-hint"]').forEach((element) => {
            if (element !== this.element && element.getAttribute('data-dismiss-hint-type-value') === this.typeValue) {
                element.remove();
            }
        });

        this.element.remove();
    }
}
