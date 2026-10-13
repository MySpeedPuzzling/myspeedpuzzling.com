/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { officialResultsRequest, isGone } from '../official_results_api.js';
import { OfficialResultsEvents } from '../official_results_events.js';
import { startedAtMs } from '../round_stopwatch_clock.js';

const REFRESH_EVERY_MS = 60000;
const HIDDEN_LONG_MS = 10000;
// A countdown's moment: the page asks the server this long after it - and again, ever later, while this device's clock
// runs ahead of the server's (the regular refresh caps it)
const START_CHECK_AFTER_MS = 2000;
// setTimeout() takes 32-bit milliseconds - a start further ahead is looked at again on the way
const MAX_TIMEOUT_MS = 2 ** 31 - 1;

/**
 * The results overview's counters follow the rounds' private Mercure topics (docs/features/competitions-management/
 * results-desk.md): one stream for all of them with the token the page came with (official_results_events.js), every
 * official results update carries the round's progress (RoundResultsOverview). The rows are server-rendered; this only
 * rewrites the numbers and badges of the round that changed. A refresh signal, the stream opening again, the tab
 * coming back and a minute while it is shown fetch every round's progress again (`official_results_competition_state`,
 * one statement) - its answer also renews the stream's token.
 */
export default class extends Controller {
    static values = {
        stateUrl: String,
        mercure: Object,
        texts: Object,
        isOnline: Boolean,
    };

    connect() {
        this.hiddenSince = null;
        this.refreshing = null;
        this.gone = false;
        // A round counting down to a scheduled start: the server is asked once its moment has come (startTimers), as
        // often as it still says "scheduled" then (startChecks - a device clock ahead of the server's)
        this.startTimers = new Map();
        this.startChecks = new Map();
        this.onVisibility = this.onVisibility.bind(this);
        document.addEventListener('visibilitychange', this.onVisibility);

        this.events = new OfficialResultsEvents({
            subscription: this.mercureValue,
            onMessage: (data) => this.onMercure(data),
            refresh: () => this.refresh(),
        });
        this.events.start();

        this.refreshTimer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.refresh();
            }
        }, REFRESH_EVERY_MS);
    }

    disconnect() {
        this.events.close();
        clearInterval(this.refreshTimer);
        this.startTimers.forEach((timer) => clearTimeout(timer));
        this.startTimers.clear();
        this.startChecks.clear();
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    row(roundId) {
        return this.element.querySelector(`[data-overview-round="${CSS.escape(roundId)}"]`);
    }

    onMercure(detail) {
        if (!detail || typeof detail.roundId !== 'string' || this.row(detail.roundId) === null) {
            return;
        }

        if ((detail.type === 'official_results.entries' || detail.type === 'official_results.round') && detail.round) {
            this.update(detail.round);
        } else if (detail.type === 'official_results.refresh') {
            this.refresh();
        }
    }

    /**
     * Every round's progress again - one request at a time. Resolves to the answer's kind.
     */
    refresh() {
        // The event is gone - nothing to ask for any more
        if (this.gone) {
            return Promise.resolve('client');
        }

        if (this.refreshing === null) {
            this.refreshing = this.fetchState().finally(() => {
                this.refreshing = null;
            });
        }

        return this.refreshing;
    }

    async fetchState() {
        const answer = await officialResultsRequest(this.stateUrlValue);

        if (!this.element.isConnected) {
            return answer.kind;
        }

        if (answer.kind === 'ok') {
            (Array.isArray(answer.data.rounds) ? answer.data.rounds : []).forEach((round) => this.update(round));
            this.events.update(answer.data.mercure ?? null);
        } else if (answer.kind === 'auth' || answer.kind === 'forbidden') {
            this.events.suspend();
        } else if (isGone(answer)) {
            // The event was deleted while the page was open: it says so and stops asking
            this.gone = true;
            this.events.close();
            clearInterval(this.refreshTimer);
            this.element.querySelectorAll('[data-results-overview-gone]').forEach((element) => { element.hidden = false; });
        }

        return answer.kind;
    }

    update(round) {
        const row = this.row(round.id);

        if (row === null) {
            return;
        }

        const entries = round.entries ?? {};
        const set = (field, text) => {
            row.querySelectorAll(`[data-overview-field="${field}"]`).forEach((element) => { element.textContent = text; });
        };

        set('entries', String(entries.total ?? 0));
        set('results', `${entries.withResult ?? 0} / ${entries.total ?? 0}`);
        set('qualified', String(entries.qualified ?? 0));

        const progress = row.querySelector('[data-overview-progress]');
        if (progress) {
            const percent = entries.total > 0 ? Math.round((entries.withResult / entries.total) * 100) : 0;
            progress.style.width = `${percent}%`;
            progress.closest('[role="progressbar"]')?.setAttribute('aria-valuenow', String(percent));
        }

        const tables = row.querySelector('[data-overview-field="tables"]');
        if (tables && !this.isOnlineValue) {
            const seated = entries.withTableNumber ?? 0;
            const total = entries.total ?? 0;

            if (round.tableNumbersOff) {
                tables.textContent = this.textsValue.tables_off;
                tables.className = 'text-body-secondary';
            } else {
                tables.textContent = `${seated} / ${total}`;
                tables.className = total > 0 && seated >= total ? 'text-success' : (round.tablesReadiness === true ? 'text-warning-emphasis' : '');
            }
        }

        row.querySelectorAll('[data-overview-published]').forEach((element) => {
            element.hidden = (element.dataset.overviewPublished === 'true') !== (round.resultsPublished === true);
        });

        this.updateStopwatch(row, round);
    }

    /**
     * The stopwatch badge as the server read it. A countdown turns into "running" at its moment - or was cancelled,
     * which this page never hears (it follows the results topics, not the stopwatch's): once the moment has come by
     * this device's clock, the page asks the server instead of deciding itself.
     */
    updateStopwatch(row, round) {
        const status = round.stopwatch?.status ?? 'none';

        row.querySelectorAll('[data-overview-stopwatch]').forEach((element) => {
            element.hidden = element.dataset.overviewStopwatch !== status;
        });

        clearTimeout(this.startTimers.get(round.id));
        this.startTimers.delete(round.id);

        const start = status === 'scheduled' ? startedAtMs(round.stopwatch) : null;

        if (start === null || !this.element.isConnected) {
            this.startChecks.delete(round.id);

            return;
        }

        const untilStart = start - Date.now();
        // The moment has come here and the server still counted down: ask again, later each time (2 s, 4 s … 60 s)
        const lateChecks = untilStart > 0 ? 0 : (this.startChecks.get(round.id) ?? 0) + 1;
        this.startChecks.set(round.id, lateChecks);
        const delay = lateChecks === 0
            ? Math.min(untilStart + START_CHECK_AFTER_MS, MAX_TIMEOUT_MS)
            : Math.min(START_CHECK_AFTER_MS * 2 ** Math.min(lateChecks - 1, 5), REFRESH_EVERY_MS);

        this.startTimers.set(round.id, setTimeout(() => {
            this.startTimers.delete(round.id);
            this.refresh();
        }, delay));
    }

    onVisibility() {
        if (document.visibilityState === 'hidden') {
            this.hiddenSince = Date.now();

            return;
        }

        // Whatever changed while a laptop slept or the tab was in the background
        if (this.hiddenSince !== null && Date.now() - this.hiddenSince > HIDDEN_LONG_MS) {
            this.refresh();
        }

        this.hiddenSince = null;
    }
}
