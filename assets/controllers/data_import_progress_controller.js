/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/**
 * The live line of an import whose file waits for the worker or is being read
 * (docs/features/data-import/reading.md "Progress").
 *
 * Progress arrives by the player's own private Mercure topic: `mercure_hub_controller.js` hands every JSON message
 * to the document as a `mercure:message` event, and the ones of this import (`type: import_progress`) move the line.
 * The stream only brings it sooner - the progress is always in the database: whenever no event came for a few
 * seconds (the stream could not be opened, dropped, or the worker is busy) the page asks the JSON endpoint instead.
 *
 * When nothing moves any more (read, failed, or closed as a file the player already imported) the endpoint says
 * where to go: this page again - now with the sheets, or with why the file was not read - or the other import.
 */

const ASK_EVERY_MS = 4000;
// An event younger than this: the stream works, no need to ask
const QUIET_MS = 4000;

export default class extends Controller {
    static targets = ['state', 'sheets', 'rows'];

    static values = {
        importId: String,
        url: String,
        waitingText: String,
        readingText: String,
        sheetsText: String,
        rowsText: String,
    };

    connect() {
        this.lastEventAt = 0;
        this.asking = false;
        this.leaving = false;
        this.numbers = new Intl.NumberFormat(document.documentElement.lang || undefined);

        this.onMessage = (event) => {
            const data = event.detail;

            if (!data || data.type !== 'import_progress' || data.importId !== this.importIdValue) {
                return;
            }

            this.lastEventAt = Date.now();
            this.show(data);

            if (data.moving === false) {
                // The event carries no address (it is written once for every page and language): the endpoint does
                this.ask(true);
            }
        };

        document.addEventListener('mercure:message', this.onMessage);
        this.timer = window.setInterval(() => this.ask(false), ASK_EVERY_MS);
    }

    disconnect() {
        document.removeEventListener('mercure:message', this.onMessage);
        window.clearInterval(this.timer);
    }

    async ask(now) {
        if (this.asking || this.leaving) {
            return;
        }

        if (!now && (document.hidden || Date.now() - this.lastEventAt < QUIET_MS)) {
            return;
        }

        this.asking = true;

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (response.status === 404) {
                // Discarded meanwhile (another tab): this page is no more
                this.leave(window.location.href);

                return;
            }

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            this.show(data);

            if (data.moving === false && data.url) {
                this.leave(data.url);
            }
        } catch {
            // Offline or a deploy: the next tick asks again
        } finally {
            this.asking = false;
        }
    }

    show(data) {
        if (this.hasStateTarget) {
            this.stateTarget.textContent = data.status === 'uploaded' ? this.waitingTextValue : this.readingTextValue;
        }

        if (this.hasSheetsTarget && Number.isInteger(data.sheetsTotal)) {
            this.sheetsTarget.textContent = this.sheetsTextValue
                .replace('%done%', this.numbers.format(data.sheetsRead || 0))
                .replace('%total%', this.numbers.format(data.sheetsTotal));
            this.sheetsTarget.hidden = false;
        }

        if (this.hasRowsTarget && data.status === 'reading') {
            this.rowsTarget.textContent = this.rowsTextValue.replace('%count%', this.numbers.format(data.rowsRead || 0));
            this.rowsTarget.hidden = false;
        }
    }

    leave(url) {
        if (this.leaving) {
            return;
        }

        this.leaving = true;
        window.clearInterval(this.timer);
        visit(url, { action: 'replace' });
    }
}
