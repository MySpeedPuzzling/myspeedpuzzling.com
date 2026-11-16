/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The picture grid of "Looks like a match" (docs/features/data-import/guided-flow.md §6.1): counts the tiles that
 * are still ticked on the button - "Yes, these 19 are right" - and switches the button off when none is.
 *
 * It only counts. The boxes are plain checkboxes of a plain form: without this script the button reads "Yes, the
 * ticked ones are right" and sends the same thing. Nothing is saved until the player presses the button, and the
 * server takes only the tiles whose box arrives ticked - and checks each again.
 *
 * `labels` is what the button says for 0, 1, 2 … ticked boxes, rendered by the server in the page's language (no
 * plural rules here).
 */
export default class extends Controller {
    static targets = ['box', 'button', 'label'];

    static values = {
        labels: Array,
    };

    connect() {
        // The screen may be rendered again around the boxes (an answer shown in place, morphed): counted again
        this.onMorph = () => this.count();
        this.element.addEventListener('turbo:morph-element', this.onMorph);
        this.count();
    }

    disconnect() {
        this.element.removeEventListener('turbo:morph-element', this.onMorph);
    }

    boxTargetConnected() {
        this.count();
    }

    count() {
        const ticked = this.boxTargets.filter((box) => box.checked).length;
        const text = this.labelsValue[ticked];

        if (this.hasLabelTarget && typeof text === 'string' && text !== '') {
            this.labelTarget.textContent = text;
        }

        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = ticked === 0;
        }
    }
}
