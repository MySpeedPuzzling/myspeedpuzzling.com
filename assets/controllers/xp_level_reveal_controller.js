/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The tap-started level reveal of the XP launch (templates/xp/_level_reveal.html.twig) - in the launch modal and on
 * the reveal page. Sits on the same element as last_piece_reveal_controller.js, which does the puzzle, the sound and
 * the XP count:
 * - the big "Reveal my level" button clicks the stage inside its own tap, so the browser lets the sound play exactly
 *   as when the puzzle itself is tapped;
 * - `last-piece-reveal:revealed` = the level is complete: the button part makes way for what comes next, and the
 *   keyboard focus moves there (the button it was on is gone).
 */
export default class extends Controller {
    static targets = ['stage', 'before', 'after'];

    /** action: the big button */
    reveal(event) {
        event.preventDefault();

        if (this.hasStageTarget) {
            this.stageTarget.click();
        }
    }

    /** action: `last-piece-reveal:revealed` */
    revealed() {
        if (this.element.classList.contains('is-level-revealed')) {
            return;
        }

        this.element.classList.add('is-level-revealed');

        if (this.hasBeforeTarget) {
            // a tap on the puzzle keeps the focus there (its label becomes "Level 23"); from the button it moves on
            const hadFocus = this.beforeTarget.contains(document.activeElement);
            this.beforeTarget.hidden = true;

            if (hadFocus && this.hasAfterTarget) {
                this.afterTarget.focus({ preventScroll: true });
            }
        }
    }
}
