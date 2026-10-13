import { Controller } from '@hotwired/stimulus';
import { scrollActiveIntoRow } from '../navigation/scroll_active_into_row.js';

/* stimulusFetch: 'lazy' */

/**
 * The section chips (templates/navigation/_section_chips.html.twig; docs/features/navigation COM-02, PUZ-01): the row
 * scrolls sideways on phones, so its current chip is brought into view on load - the row only, the page never moves.
 */
export default class extends Controller {
    static targets = ['row', 'active'];

    connect() {
        if (this.hasRowTarget && this.hasActiveTarget) {
            scrollActiveIntoRow(this.rowTarget, this.activeTarget);
        }
    }
}
