import { Controller } from '@hotwired/stimulus';
import layers from '../layer_history.js';
import { clearDraft, restoreDraft, saveDraft, sessionStorageOf } from '../feedback_draft.js';

// Asks the dynamic modal (dynamic_modal_controller.js) to let the page be used again with the sheet still on screen
const RELEASE_EVENT = 'modal:release';

/**
 * The Help sheet (docs/features/navigation README §4.5, implementation plan §7, HELP-01b) - on the content root of
 * help/_frame.html.twig, next to bottom-sheet (the swipe):
 * - The feedback draft: kept in sessionStorage (assets/feedback_draft.js) while it is typed, put back into the empty
 *   field when the sheet opens again - also after a swipe that started in the field - and dropped when the sent state
 *   connects (`data-help-sheet-sent`: FeedbackController answered the send into this frame).
 * - The sent state releases the modal (`modal:release`): the confirmation stays in the sheet while the page behind is
 *   usable again (no scrim, no scroll lock - HELP-05). "Send another" brings the empty form back in place.
 * - Focus goes to the field when the sheet is shown to a signed-in player (iOS may still want a second tap: a field
 *   focused outside the tap's own handler opens no keyboard there - QA-06), and when a form with an error came back.
 * - reload(): the "Reload page" row (HELP-02) closes the sheet first (Back, layer_history closeThen), then reloads.
 */
export default class extends Controller {
    static targets = ['message', 'sent', 'box'];

    connect() {
        this.host = this.element.closest('.modal');
        this.storage = sessionStorageOf(window);
        const sent = this.element.hasAttribute('data-help-sheet-sent');

        if (sent) {
            clearDraft(this.storage);
            this.element.dispatchEvent(new CustomEvent(RELEASE_EVENT, { bubbles: true }));
        } else if (this.hasMessageTarget) {
            restoreDraft(this.messageTarget, this.storage);
        }

        this.element.addEventListener('input', this.onInput);
        this.host?.addEventListener('shown.bs.modal', this.onShown);

        // Content swapped into the open sheet (the form came back with an error): no shown event comes
        if (!sent && this.host?.classList.contains('show')) {
            this.focusMessage();
        }
    }

    disconnect() {
        this.element.removeEventListener('input', this.onInput);
        this.host?.removeEventListener('shown.bs.modal', this.onShown);
    }

    onInput = (event) => {
        if (this.hasMessageTarget && event.target === this.messageTarget) {
            saveDraft(this.storage, this.messageTarget.value);
        }
    };

    onShown = () => {
        this.focusMessage();
    };

    sendAnother() {
        if (this.hasSentTarget) {
            this.sentTarget.hidden = true;
        }

        if (this.hasBoxTarget) {
            this.boxTarget.hidden = false;
        }

        this.focusMessage();
    }

    reload(event) {
        event?.preventDefault();
        layers.closeThen(() => window.location.reload());
    }

    focusMessage() {
        if (this.hasMessageTarget && this.messageTarget.closest('[hidden]') === null) {
            this.messageTarget.focus({ preventScroll: true });
        }
    }
}
