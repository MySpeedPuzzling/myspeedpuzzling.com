import { Controller } from '@hotwired/stimulus';

/*
 * The row editor of the data import (templates/data_import/row.html.twig): "No time - I didn't time this one"
 * beside the time's input. Ticked, the input is switched off - it is not sent, and what was typed stays to come
 * back to. The server needs none of this: the tick alone decides.
 */
export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.toggle();
    }

    toggle() {
        const input = this.element.querySelector('[data-testid="import-answer-input"]');

        if (input) {
            input.disabled = this.toggleTarget.checked;
        }
    }
}
