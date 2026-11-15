/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * One answer for a whole pile of an import's puzzles is being saved on the worker ("Keep them just for me" for a
 * thousand puzzles - docs/features/data-import/guided-flow.md §6.3): the part of the page that says how many are
 * left is loaded again every few seconds, and when it no longer says "saving" the page is loaded once more, whole -
 * with what comes next. The page is only on the screen while an answer runs, so nothing is asked on a page that
 * has nothing to wait for. Without this script the page has a link that loads it again.
 */

const EVERY_MS = 3000;

export default class extends Controller {
    static values = {
        frame: String,
    };

    connect() {
        this.onLoad = (event) => {
            if (!(event.target instanceof Element) || event.target.id !== this.frameValue) {
                return;
            }

            if (event.target.querySelector('[data-import-pile-running]') === null) {
                window.clearTimeout(this.timer);
                window.location.reload();
            }
        };

        document.addEventListener('turbo:frame-load', this.onLoad);
        this.later();
    }

    disconnect() {
        document.removeEventListener('turbo:frame-load', this.onLoad);
        window.clearTimeout(this.timer);
    }

    later() {
        window.clearTimeout(this.timer);

        this.timer = window.setTimeout(() => {
            const frame = document.getElementById(this.frameValue);

            if (frame !== null && !document.hidden) {
                if (frame.getAttribute('src')) {
                    frame.reload();
                } else {
                    frame.setAttribute('src', window.location.href);
                }
            }

            // Whatever became of that request - the next one is asked for in its turn
            this.later();
        }, EVERY_MS);
    }
}
