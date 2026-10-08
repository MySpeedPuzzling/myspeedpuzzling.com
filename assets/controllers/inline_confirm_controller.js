/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * In-place confirmations of the events page's organiser actions (templates/events/_manage_items.html.twig): Delete
 * ("Delete “X”? This cannot be undone.") and Reject (the reason). The markup is a <details> whose <summary> is the
 * action, so it works without JavaScript; this adds Cancel, Escape (closes the confirmation, not the menu around it) and
 * moves the focus into the opened confirmation and back to the action when it closes.
 */
export default class extends Controller {
    static targets = ['summary', 'focus', 'cancel'];

    connect() {
        this.cancelTargets.forEach((button) => {
            button.hidden = false;
        });
    }

    toggled() {
        if (this.element.open && this.hasFocusTarget) {
            this.focusTarget.focus({ preventScroll: true });
        }
    }

    cancel(event) {
        if (!this.element.open) {
            return;
        }

        event.preventDefault();
        // The menu around it stays open
        event.stopPropagation();

        this.element.open = false;

        if (this.hasSummaryTarget) {
            this.summaryTarget.focus({ preventScroll: true });
        }
    }
}
