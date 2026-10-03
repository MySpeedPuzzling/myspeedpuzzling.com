import { Controller } from '@hotwired/stimulus';

/**
 * "Show more" under a list of the Players page spotlight (docs/features/players-page/README.md): the other rows are
 * already in the page, so it only reveals them - no request. The button goes away, so focus moves to the first
 * revealed person and a keyboard user keeps their place.
 */
export default class extends Controller {
    static targets = ['extra'];

    reveal() {
        this.element.classList.add('is-expanded');

        if (!this.hasExtraTarget) {
            return;
        }

        const firstRevealed = this.extraTarget.querySelector('a');

        if (firstRevealed) {
            firstRevealed.focus();
        }
    }
}
