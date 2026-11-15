/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The live line of one "Add" - "Adding… 320 of 1,240" - and of one Undo of a spreadsheet import
 * (docs/features/data-import/adding-screens.md "The live line").
 *
 * The same mechanism as the reading's and the set-up's lines (data_import_progress_controller.js,
 * data_import_setup_controller.js): progress arrives by the player's own private Mercure topic (`mercure:message`
 * events of `mercure_hub_controller.js`, `type: import_adding` - published by the worker after each row's commit),
 * and whenever no event came for a few seconds the page asks its JSON endpoint instead - more and more seldom when
 * it does not answer, and not at all after ten minutes without one answer. An Undo publishes nothing: its page asks.
 *
 * The payloads carry numbers and states only. When the work is through, the page is loaded again - it then shows
 * what came of it. The work itself never depends on this page: it may be closed at any time.
 */

const ASK_EVERY_MS = 3000;
const QUIET_MS = 3000;
const BACK_OFF_MS = [3000, 6000, 12000, 30000];
const GIVE_UP_AFTER_MS = 10 * 60 * 1000;

export default class extends Controller {
    static targets = ['line', 'bar', 'spinner', 'stale', 'paused'];

    static values = {
        // The JSON endpoint of this "Add" / this Undo
        url: String,
        // The "Add" whose `import_adding` events are this page's (empty for an Undo)
        batchId: String,
        total: Number,
        done: Number,
        // "Adding… %done% of %total%"
        lineText: String,
        // All rows are through, what is owed for them is being finished
        finishingText: String,
    };

    connect() {
        this.lastEventAt = 0;
        this.asking = false;
        this.stopped = false;
        this.failures = 0;
        this.failingSince = 0;
        this.numbers = new Intl.NumberFormat(document.documentElement.lang || undefined);

        this.onMessage = (event) => {
            const data = event.detail;

            if (!data || data.type !== 'import_adding' || !this.batchIdValue || data.batchId !== this.batchIdValue) {
                return;
            }

            this.lastEventAt = Date.now();
            this.show({
                running: data.state === 'running',
                paused: data.paused === true,
                total: data.rows,
                done: Number.isInteger(data.rows) && Number.isInteger(data.queued) ? data.rows - data.queued : null,
            });
        };

        document.addEventListener('mercure:message', this.onMessage);

        // What happened between the page's rendering and now is asked for at once
        this.ask(true).then(() => this.askLater(ASK_EVERY_MS));
    }

    disconnect() {
        document.removeEventListener('mercure:message', this.onMessage);
        this.stopped = true;
        window.clearTimeout(this.timer);
    }

    askLater(ms) {
        window.clearTimeout(this.timer);

        if (this.stopped) {
            return;
        }

        this.timer = window.setTimeout(async () => {
            await this.ask(false);
            this.askLater(this.failures > 0 ? BACK_OFF_MS[Math.min(this.failures, BACK_OFF_MS.length) - 1] : ASK_EVERY_MS);
        }, ms);
    }

    async ask(now) {
        if (this.asking || this.stopped) {
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

            // Signed out meanwhile, or the import is gone: the page itself says what is
            if ([401, 403, 404].includes(response.status) || (response.ok && (response.redirected || !isJson))) {
                this.finish();

                return;
            }

            if (!response.ok) {
                this.failed();

                return;
            }

            this.failures = 0;
            this.failingSince = 0;
            this.show(await response.json());
        } catch {
            // Offline or a deploy
            this.failed();
        } finally {
            this.asking = false;
        }
    }

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
        if (this.stopped) {
            return;
        }

        if (data.running !== true) {
            this.finish();

            return;
        }

        const total = Number.isInteger(data.total) ? data.total : this.totalValue;
        const done = Number.isInteger(data.done) ? Math.min(Math.max(data.done, 0), total) : this.doneValue;

        this.totalValue = total;
        this.doneValue = done;

        if (this.hasLineTarget) {
            this.lineTarget.textContent = done >= total && this.finishingTextValue
                ? this.finishingTextValue
                : this.lineTextValue
                    .replace('%done%', this.numbers.format(done))
                    .replace('%total%', this.numbers.format(total));
        }

        if (this.hasBarTarget) {
            const percent = total > 0 ? Math.round((done / total) * 100) : 0;

            this.barTarget.style.width = `${percent}%`;
            this.barTarget.parentElement.setAttribute('aria-valuenow', String(percent));
        }

        if (this.hasPausedTarget) {
            this.pausedTarget.hidden = data.paused !== true;
        }
    }

    /**
     * The work is through: the page is loaded again and shows what came of it.
     */
    finish() {
        this.stopped = true;
        window.clearTimeout(this.timer);
        window.location.reload();
    }
}
