/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The creation wizard's spare rows (Structure: stages and new rounds of the schedule table; Puzzles: another puzzle of
 * a round): a spare row the server rendered empty (`data-empty`) is hidden until "+ add" shows the next one. Without
 * JavaScript every spare row stays visible and "+ add" is not rendered as a button that does anything else
 * (`data-event-wizard-rows-target="add"` starts `hidden` in the markup).
 *
 *   <div data-controller="event-wizard-rows">
 *     <div data-event-wizard-rows-target="spare" data-empty>…</div>
 *     <button type="button" hidden data-event-wizard-rows-target="add" data-action="event-wizard-rows#add">+ add</button>
 */
export default class extends Controller {
    static targets = ['spare', 'add', 'more'];

    connect() {
        this.spareTargets.forEach((row) => {
            if (row.hasAttribute('data-empty')) {
                row.hidden = true;
            }
        });
        this.addTargets.forEach((button) => { button.hidden = false; });
        this.refresh();
    }

    add() {
        const next = this.spareTargets.find((row) => row.hidden);

        if (next) {
            next.hidden = false;
            next.querySelector('input:not([type=hidden]), select')?.focus();
        }

        this.refresh();
    }

    refresh() {
        const hiddenLeft = this.spareTargets.some((row) => row.hidden);
        this.addTargets.forEach((button) => { button.hidden = !hiddenLeft; });
        // Shown once a second puzzle of the round is on its way ("each competitor solves K of them")
        const shownSpares = this.spareTargets.filter((row) => !row.hidden).length;
        this.moreTargets.forEach((element) => { element.hidden = shownSpares < 2 && !element.hasAttribute('data-always'); });
    }
}
