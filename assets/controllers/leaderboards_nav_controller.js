import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * The leaderboards pill row (templates/_leaderboards_nav.html.twig) scrolls sideways on phones: the active pill is
 * brought into view on load - horizontally only, the page itself never moves.
 */
export default class extends Controller {
    static targets = ['active'];

    connect() {
        if (!this.hasActiveTarget) {
            return;
        }

        const pill = this.activeTarget;
        const overflow = pill.offsetLeft + pill.offsetWidth - this.element.clientWidth;

        if (overflow > 0) {
            this.element.scrollLeft = pill.offsetLeft - (this.element.clientWidth - pill.offsetWidth) / 2;
        }
    }
}
