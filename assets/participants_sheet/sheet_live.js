/**
 * Live updates of the participants spreadsheet (O12; docs/features/competitions-management/participants-spreadsheet.md
 * "Client architecture (as built)"):
 *
 * - one stream (official_results_events.js, `OfficialResultsEvents`) with the state's `mercure` token - the private
 *   topic `/competition-participants/{id}` (`participants_sheet.changed` + version) and every round's
 *   `/round-results/{roundId}` (`official_results.entries` / `.refresh` / `.round` from the live entry, the desk, seating);
 * - `participants_sheet.changed` with a version the page does not know → the state is fetched and merged (the save
 *   queue's `refetch()`, so a fetch never races one of our own saves) and `onForeignChange()` is called (the controller
 *   says "another organiser changed the sheet"); our own writes announce a version we know - adopted already, or the
 *   answer of the save on its way brings it - nothing to fetch; a late echo of an earlier save of ours
 *   (queue.isOwnVersion()) is fetched but never said;
 * - `official_results.entries` → merged into the model by ref (an unknown ref = an entry the page does not know: fetch);
 *   `.refresh` → fetch; `.round` → the round's publication / table numbers usage;
 * - safety nets: `GET urls.version` every 30 s while the page is visible (and nothing of ours is on its way), a full
 *   fetch when the stream opens again (OfficialResultsEvents' catch-up), when the tab comes back after 10 s, when the
 *   connection returns. Every fetched state passes its fresh token to the stream; signed out / no rights suspend it.
 *
 * DOM-free: visibility, timers and the request are injected (the controller wires the document events) - pinned by
 * tests/participants-sheet-core-harness.mjs.
 */

import { officialResultsRequest } from '../official_results_api.js';
import { OfficialResultsEvents } from '../official_results_events.js';

export const VERSION_POLL_MS = 30000;
export const RETURN_AFTER_MS = 10000;

export class SheetLive {
    /**
     * @param {object} options
     * @param {import('./sheet_model.js').SheetModel} options.model
     * @param {import('./sheet_save_queue.js').SheetSaveQueue} options.queue
     * @param {{version: string}} options.urls
     * @param {function(string, object=): Promise<object>} [options.request]
     * @param {function(object): object} [options.eventsFactory] builds the stream (OfficialResultsEvents options)
     * @param {function(): boolean} [options.isVisible]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): number} [options.now]
     * @param {function(object): void} [options.onMessage] every update after it was handled
     * @param {function(object): void} [options.onForeignChange] somebody else changed the sheet (not our own echo)
     */
    constructor({
        model,
        queue,
        urls,
        request = officialResultsRequest,
        eventsFactory = (options) => new OfficialResultsEvents(options),
        isVisible = () => true,
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        now = () => Date.now(),
        onMessage = () => {},
        onForeignChange = () => {},
    }) {
        this.model = model;
        this.queue = queue;
        this.urls = urls;
        this.request = request;
        this.isVisible = isVisible;
        this.schedule = schedule;
        this.cancel = cancel;
        this.now = now;
        this.onMessageHandled = onMessage;
        this.onForeignChange = onForeignChange;
        this.pollTimer = null;
        this.polling = false;
        this.hiddenSince = null;
        this.closed = false;

        this.events = eventsFactory({
            subscription: model.mercure ?? null,
            refresh: () => this.queue.refetch(),
            onMessage: (data) => this.handle(data),
        });

        this.awaitedVersion = null;
        this.unsubscribe = queue.subscribe((event) => {
            if (event.type === 'status' && this.awaitedVersion !== null && this.queue.inFlight === null) {
                const awaited = this.awaitedVersion;
                this.awaitedVersion = null;

                if (awaited !== this.model.version) {
                    this.queue.refetch();
                    this.tellForeign({ version: awaited });
                }
            }

            if (event.type !== 'state') {
                return;
            }

            if (event.kind === 'ok') {
                // A fresh token with every state: the stream is renewed with it, or opened again if it is down
                this.events.update(event.state?.mercure ?? null);
            } else if (event.kind === 'auth' || event.kind === 'forbidden') {
                // A token is only for whoever may still edit the event
                this.events.suspend();
            }
        });
    }

    start() {
        this.events.start();
        this.schedulePoll();
    }

    close() {
        this.closed = true;
        this.events.close();
        this.cancel(this.pollTimer);
        this.pollTimer = null;
        this.unsubscribe();
    }

    /**
     * One update of the stream.
     */
    handle(data) {
        if (this.closed || data === null || typeof data !== 'object' || typeof data.type !== 'string') {
            return;
        }

        switch (data.type) {
            case 'participants_sheet.changed':
                if (typeof data.version === 'string' && data.version !== this.model.version) {
                    if (this.queue.inFlight?.kind === 'sheet') {
                        // Our own save may be this very version - its answer (on its way) tells; checked once it arrived
                        this.awaitedVersion = data.version;
                    } else {
                        // A late echo of an earlier save of ours fetches too (cheap, always right), but is not news
                        this.queue.refetch();
                        this.tellForeign(data);
                    }
                }
                break;

            case 'official_results.entries':
                if (this.model.round(data.roundId) === null) {
                    return;
                }

                if (this.model.mergeEntries(data.entries ?? []).unknown.length > 0) {
                    this.queue.refetch();
                }

                this.model.updateRound(data.round);
                break;

            case 'official_results.refresh':
                if (this.model.round(data.roundId) !== null) {
                    this.queue.refetch();
                }
                break;

            case 'official_results.round':
                this.model.updateRound(data.round);
                break;

            default:
                return;
        }

        this.onMessageHandled(data);
    }

    schedulePoll() {
        if (this.closed) {
            return;
        }

        this.cancel(this.pollTimer);
        this.pollTimer = this.schedule(() => {
            this.pollTimer = null;
            this.poll().finally(() => this.schedulePoll());
        }, VERSION_POLL_MS);
    }

    /**
     * The version check: a different version fetches the state. Skipped while hidden or while our own save is on its
     * way (its answer moves the version itself).
     */
    async poll() {
        if (this.closed || this.polling || !this.isVisible() || this.queue.blocked() || this.queue.inFlight !== null || this.queue.items.length > 0) {
            return;
        }

        this.polling = true;

        try {
            const answer = await this.request(this.urls.version);

            if (this.closed) {
                return;
            }

            if (answer.kind === 'ok' && typeof answer.data?.version === 'string' && answer.data.version !== this.model.version && this.queue.inFlight === null) {
                this.tellForeign({ version: answer.data.version });
                await this.queue.refetch();
            } else if (answer.kind === 'auth' || answer.kind === 'forbidden') {
                // The save queue learns it too - the pill says "Sign in again" / "Reload the page"
                this.queue.transport = answer.kind;
                this.queue.emitStatus();
                this.events.suspend();
            }
        } finally {
            this.polling = false;
        }
    }

    /** "Another organiser changed the sheet" - never for the echo of one of our own saves. */
    tellForeign(data) {
        if (typeof this.queue.isOwnVersion === 'function' && this.queue.isOwnVersion(data.version)) {
            return;
        }

        this.onForeignChange(data);
    }

    /** The document's visibility changed (the controller listens). */
    visibilityChanged() {
        if (!this.isVisible()) {
            this.hiddenSince = this.now();

            return;
        }

        if (this.hiddenSince !== null && this.now() - this.hiddenSince > RETURN_AFTER_MS) {
            this.queue.refetch();
        }

        this.hiddenSince = null;
    }

    /** The browser is online again: send what waits, catch up. */
    online() {
        this.queue.online();
        this.queue.refetch();
    }

    isLive() {
        return this.events.isLive?.() ?? false;
    }
}
