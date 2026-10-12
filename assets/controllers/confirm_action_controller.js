import { Controller } from '@hotwired/stimulus';
import { confirmDialog } from '../confirm_dialog.js';

/**
 * Asks in the in-page dialog (assets/confirm_dialog.js, GEST-13, decision 60) before the actions bound after it on the
 * same element - for buttons Turbo's data-turbo-confirm cannot reach, such as Live Component actions
 * (components/RoundTableManager):
 *
 *   <button data-controller="confirm-action" data-action="confirm-action#ask live#action"
 *           data-confirm-action-message-param="…" data-confirm-action-danger-param="true"
 *           data-confirm-action-label-param="…" data-live-action-param="…">
 *
 * ask() stops the click before the later actions (Stimulus honours stopImmediatePropagation for the bindings after it),
 * awaits the answer and, on yes, clicks the element again with a pass for exactly that click.
 */
export default class extends Controller {
    ask(event) {
        if (this.passing) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        const element = event.currentTarget;
        const { message, danger, label } = event.params;

        confirmDialog(message, { danger: danger === true, actionLabel: label, returnFocusTo: element }).then((yes) => {
            if (!yes || !element.isConnected) {
                return;
            }

            this.passing = true;

            try {
                element.click();
            } finally {
                this.passing = false;
            }
        });
    }
}
