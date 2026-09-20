import { Controller } from '@hotwired/stimulus';

// Remembers that the player followed this link (Getting started guide: steps that leave
// no other trace). sendBeacon, because the click navigates away and a fetch would be cut off.
export default class extends Controller {
    static values = {
        url: String,
        type: String,
    };

    mark() {
        const body = new URLSearchParams({ type: this.typeValue });

        if (navigator.sendBeacon) {
            navigator.sendBeacon(this.urlValue, body);

            return;
        }

        fetch(this.urlValue, { method: 'POST', body, keepalive: true });
    }
}
