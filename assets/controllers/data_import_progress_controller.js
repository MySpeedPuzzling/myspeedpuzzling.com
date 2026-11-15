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
 * An endpoint that does not answer (offline, a deploy, a 5xx) is asked more and more seldom - 4, 8, 16, then every
 * 30 seconds - and after ten minutes without one answer not at all: the page says to reload. Signed out meanwhile
 * (401, 403, or the sign-in page instead of JSON) reloads the page at once.
 *
 * When nothing moves any more (read, failed, or closed as a file the player already imported) the endpoint says
 * where to go: this page again - now with the sheets, or with why the file was not read - or the other import.
 */

const ASK_EVERY_MS = 4000;
// An event younger than this: the stream works, no need to ask
const QUIET_MS = 4000;
// After an answer that was none (offline, a deploy, a 5xx): asked more and more seldom …
const BACK_OFF_MS = [4000, 8000, 16000, 30000];
// … and after this long without one answer not at all: the page says to reload
const GIVE_UP_AFTER_MS = 10 * 60 * 1000;

export default class extends Controller {
    static targets = ['state', 'sheets', 'rows', 'spinner', 'stale'];

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
        this.stopped = false;
        this.failures = 0;
        this.failingSince = 0;
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
        this.askLater(ASK_EVERY_MS);
    }

    askLater(ms) {
        window.clearTimeout(this.timer);

        if (this.stopped || this.leaving) {
            return;
        }

        this.timer = window.setTimeout(async () => {
            await this.ask(false);
            this.askLater(this.failures > 0 ? BACK_OFF_MS[Math.min(this.failures, BACK_OFF_MS.length) - 1] : ASK_EVERY_MS);
        }, ms);
    }

    disconnect() {
        document.removeEventListener('mercure:message', this.onMessage);
        this.stopped = true;
        window.clearTimeout(this.timer);
    }

    async ask(now) {
        if (this.asking || this.leaving || this.stopped) {
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
            const isJson = (response.headers.get('Content-Type') || '').includes('json');

            // Signed out meanwhile (the answer is a refusal, or the sign-in page it was redirected to), or the import
            // was discarded in another tab: nothing more to ask for from here - the page itself says what is
            if ([401, 403, 404].includes(response.status) || (response.ok && (response.redirected || !isJson))) {
                this.leave(window.location.href);

                return;
            }

            if (!response.ok) {
                this.failed();

                return;
            }

            const data = await response.json();
            this.failures = 0;
            this.failingSince = 0;
            this.show(data);

            if (data.moving === false && data.url) {
                this.leave(data.url);
            }
        } catch {
            // Offline or a deploy
            this.failed();
        } finally {
            this.asking = false;
        }
    }

    /**
     * No answer: the next question comes later each time, and after ten minutes of none the page stops asking.
     */
    failed() {
        this.failures += 1;
        this.failingSince = this.failingSince || Date.now();

        if (Date.now() - this.failingSince < GIVE_UP_AFTER_MS) {
            return;
        }

        this.stopped = true;
        window.clearTimeout(this.timer);

        if (this.hasSpinnerTarget) {
            this.spinnerTarget.hidden = true;
        }

        if (this.hasStaleTarget) {
            this.staleTarget.hidden = false;
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
        window.clearTimeout(this.timer);
        visit(url, { action: 'replace' });
    }
}
