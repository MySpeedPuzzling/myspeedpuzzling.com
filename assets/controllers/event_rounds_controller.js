/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The rounds timeline's "Show N earlier rounds" (docs/features/events-page/detail-pages.md, "Rounds timeline"): on the
 * timeline of an edition or event page only when it folds rounds. The <details> works without JavaScript; this opens it
 * when the URL's #round-<id> names a round inside - on arrival (the events page and the series page link sessions to
 * their first round) and on every hash change - and brings that round into view, since the browser could not scroll to
 * it while it was folded.
 */
export default class extends Controller {
    static targets = ['earlier'];

    connect() {
        this.onHashChange = () => this.reveal();
        window.addEventListener('hashchange', this.onHashChange);
        this.reveal();
    }

    disconnect() {
        window.removeEventListener('hashchange', this.onHashChange);
    }

    reveal() {
        const hash = window.location.hash;

        if (!this.hasEarlierTarget || !hash.startsWith('#round-')) {
            return;
        }

        let round;

        try {
            round = document.getElementById(decodeURIComponent(hash.slice(1)));
        } catch {
            return;
        }

        if (!round || !this.earlierTarget.contains(round) || this.earlierTarget.open) {
            return;
        }

        this.earlierTarget.open = true;
        round.scrollIntoView({ block: 'start' });
    }
}
