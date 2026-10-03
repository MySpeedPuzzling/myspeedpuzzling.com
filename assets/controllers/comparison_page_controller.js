/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Sits on the compare page's live component root (docs/features/player-comparison.md). The URL is the page's state:
 * the server normalizes it on the first render (members-only filters of a free player, subjects this viewer may not
 * see, defaults), and Live Component only rewrites the URL after its own re-renders - so the first one is taken over
 * here, without a new history entry.
 */
export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        if (!this.hasUrlValue || this.urlValue === '') {
            return;
        }

        const target = new URL(this.urlValue, window.location.origin);
        const current = new URL(window.location.href);

        if (target.pathname + target.search === current.pathname + current.search) {
            return;
        }

        history.replaceState(history.state, '', target.pathname + target.search + current.hash);
    }
}
