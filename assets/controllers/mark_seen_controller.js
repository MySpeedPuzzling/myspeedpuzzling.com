import { Controller } from '@hotwired/stimulus';
import { clearSnapshots } from '../instant_back.js';

// Remembers that the player followed this link (Getting started guide: steps that leave
// no other trace). sendBeacon, because the click navigates away and a fetch would be cut off.
//
// A write like any other (Instant Back, decision 65): the Getting started card on the Hub and the own profile counts
// the step, so the copies of those pages go first and every tab learns of it (assets/instant_back.js) - the beacon
// itself is invisible to the fetch watcher. The click that sends it starts a visit by a write: the page it leaves keeps
// no copy either.
export default class extends Controller {
    static values = {
        url: String,
        type: String,
    };

    mark() {
        const body = new URLSearchParams({ type: this.typeValue });

        clearSnapshots();

        if (navigator.sendBeacon) {
            navigator.sendBeacon(this.urlValue, body);

            return;
        }

        fetch(this.urlValue, { method: 'POST', body, keepalive: true });
    }
}
