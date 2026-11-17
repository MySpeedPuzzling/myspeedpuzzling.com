import { Controller } from '@hotwired/stimulus';
import { rememberCollapsed, startsOpen } from '../get_ready_collapse.js';

// The "Get ready" checklist on an event's edit page (templates/event_wizard/_get_ready.html.twig): a <details> that
// remembers the viewer's collapse per event (assets/get_ready_collapse.js). Right after Publish it opens whatever was
// remembered. Without JavaScript it is a plain <details>, open.
export default class extends Controller {
    static targets = ['details'];
    static values = { id: String, forceOpen: Boolean };

    connect() {
        const storage = this.storage();

        if (this.forceOpenValue) {
            rememberCollapsed(storage, this.idValue, false);
        }

        this.ready = false;
        this.detailsTarget.open = startsOpen(storage, this.idValue, this.forceOpenValue);
        // The toggle event of the line above comes asynchronously - only the viewer's own toggles are remembered
        setTimeout(() => { this.ready = true; }, 0);
    }

    toggled() {
        if (this.ready !== true) {
            return;
        }

        rememberCollapsed(this.storage(), this.idValue, this.detailsTarget.open === false);
    }

    storage() {
        try {
            return window.localStorage;
        } catch {
            return null;
        }
    }
}
