/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { formatResultTime, parseResultTime } from '../official_results_time.js';
import { newClientId, officialResultsRequest } from '../official_results_api.js';
import { createIndexedDbStorage, createMemoryStorage, createTabLock, Outbox, STUCK_AFTER_ATTEMPTS } from '../official_results_outbox.js';
import {
    buildSearchIndex,
    describeResult,
    entryForEnter,
    entryOfParticipant,
    entryValue,
    preferredRound,
    recentEntries,
    sameValue,
    searchEntries,
    shownValue,
} from '../official_results_live.js';
import { parseNameTagUrl, startQrScan } from '../official_results_scan.js';
import { chooseTranslation } from '../translation_choice.js';

/**
 * Live result entry on a referee's phone (templates/live_results/live_results.html.twig,
 * docs/features/competitions-management/live-results.md): find → enter → confirm → save → back to find.
 *
 * Every save goes into the outbox (official_results_outbox.js) before the network sees it; the page shows the
 * device's own unsent values on top of the server's. Other devices' saves come in over a private Mercure topic,
 * the stopwatch over its public one. Inputs are never re-rendered: lists and texts around them are.
 */

const UUID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';
const REQUEST_TIMEOUT_MS = 15000;
const STATE_REFRESH_MS = 60000;
const FLUSH_TICK_MS = 5000;
const TOAST_MS = 8000;
const CHANNEL = 'msp-official-results';
const KEYBOARD_KEY = 'msp.liveResults.keyboard';
const ROUND_KEY = 'msp.liveResults.round.';

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
}

function storageGet(key) {
    try {
        return window.localStorage.getItem(key);
    } catch (e) {
        return null;
    }
}

function storageSet(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch (e) {
        // A private window - the default next time
    }
}

export default class extends Controller {
    static targets = [
        'initialState', 'roundSelect', 'pill', 'pillText', 'stopwatch', 'tables', 'banner',
        'findView', 'findInput', 'keyboardToggle', 'findNotice', 'readiness', 'matches', 'addButton', 'recentBlock', 'recent',
        'entryView', 'entryTable', 'entryName', 'entryMembers', 'entryNow', 'entryProblems',
        'timeForm', 'timeInput', 'timeParsed', 'piecesForm', 'piecesInput', 'piecesParsed', 'finishedNow', 'finishedNowClock', 'clearButton',
        'confirmView', 'confirmTable', 'confirmName', 'confirmResult', 'confirmNote', 'saveButton',
        'addView', 'addForm', 'addName', 'addMembers', 'addTable', 'addError', 'addSuggestions', 'addSuggestionList',
        'toast', 'toastText', 'toastUndo', 'sheet', 'sheetBody', 'scanner', 'video', 'scanHint',
    ];

    static values = {
        userId: String,
        competitionId: String,
        roundId: String,
        locale: String,
        stateUrl: String,
        recordUrl: String,
        liveUrl: String,
        scanUrl: String,
        loginUrl: String,
        mercureUrl: String,
        csrfToken: String,
        entrant: String,
        entrantName: String,
        autoPicked: Boolean,
        messages: Object,
        plurals: Object,
    };

    connect() {
        const state = JSON.parse(this.initialStateTarget.textContent);
        // Server clock minus ours - refined (round trip compensated) by every state fetch
        this.clockOffset = Date.now() - Date.parse(state.serverNow);
        this.serverEntries = new Map();
        this.localEntries = new Map();
        this.applyState(state);

        if (this.autoPickedValue) {
            const remembered = this.readRememberedRound();
            const target = preferredRound(this.roundIdValue, remembered, this.rounds, Date.now());

            if (target !== this.roundIdValue) {
                window.Turbo?.visit(this.liveUrl(target), { action: 'replace' });

                return;
            }
        }

        this.cleanUrl();

        this.view = 'find';
        this.openRef = null;
        this.pendingValue = null;
        this.pendingFinishedNow = false;
        this.query = '';
        this.historyEntry = false;
        this.leavingHistoryEntry = false;
        this.toastItem = null;
        this.toastTimer = null;
        this.renderQueued = false;
        this.stateBlocked = null;
        this.storageMissing = false;
        this.foreign = 0;
        this.lastClockSecond = null;

        this.setupOutbox();
        this.setKeyboard(storageGet(KEYBOARD_KEY) ?? (this.usesTables() && this.round?.entries?.withTableNumber > 0 ? 'numeric' : 'text'));

        this.onOnline = () => this.flushSoon();
        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.refreshState();
                this.flushSoon();
            }
        };
        this.onFocus = () => {
            // Back from the sign-in tab
            if (this.outbox.blocked !== null || this.stateBlocked !== null) {
                this.retryAll();
            }
        };
        this.onBeforeUnload = (event) => {
            if (this.outbox.hasUnsent()) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        this.onBeforeVisit = (event) => {
            const target = new URL(event.detail?.url ?? '', window.location.href).pathname;
            const liveResults = new URL(this.liveUrl(UUID_PLACEHOLDER).replace(UUID_PLACEHOLDER, ''), window.location.href).pathname;

            // Another live entry page sends this device's changes too - only leaving the tool asks
            if (this.outbox.hasUnsent() && !target.startsWith(liveResults) && !window.confirm(this.t('leaveUnsent'))) {
                event.preventDefault();

                return;
            }

            // Leaving from a view over the find view: Turbo's history takes over again for the next page
            if (this.historyEntry) {
                this.historyEntry = false;
                this.resumeTurboHistory();
            }
        };
        this.onPopState = () => this.handlePopState();
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && (this.view !== 'find' || !this.sheetTarget.hidden || !this.scannerTarget.hidden)) {
                event.preventDefault();
                this.closeEverything();
            }
        };

        window.addEventListener('online', this.onOnline);
        window.addEventListener('focus', this.onFocus);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        window.addEventListener('popstate', this.onPopState);
        document.addEventListener('visibilitychange', this.onVisibility);
        document.addEventListener('turbo:before-visit', this.onBeforeVisit);
        document.addEventListener('keydown', this.onKeydown);

        if (typeof BroadcastChannel !== 'undefined') {
            this.channel = new BroadcastChannel(CHANNEL);
            this.channel.onmessage = (event) => {
                if (event.data?.type === 'outbox' && event.data.userId === this.userIdValue) {
                    this.outbox.load();
                }
            };
        }

        this.clockTimer = setInterval(() => this.tickClock(), 250);
        this.flushTimer = setInterval(() => this.flushSoon(), FLUSH_TICK_MS);
        this.stateTimer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.refreshState();
            }
        }, STATE_REFRESH_MS);

        this.render();
        this.tickClock();

        if (this.entrantValue !== '') {
            const entry = entryOfParticipant(this.allEntries(), this.entrantValue);

            if (entry !== null) {
                this.openEntry(entry.ref);
            } else {
                this.notice(this.t('entrantNotInRound', { '%name%': this.entrantNameValue }), 'warning');
            }
        } else {
            this.findInputTarget.focus({ preventScroll: true });
        }

        // The page came with the state; this fetch syncs the clock and authorises the round's private topic
        this.refreshState();
    }

    disconnect() {
        clearInterval(this.clockTimer);
        clearInterval(this.flushTimer);
        clearInterval(this.stateTimer);
        clearTimeout(this.toastTimer);
        clearTimeout(this.countsTimer);
        this.eventSource?.close();
        this.eventSource = null;
        this.channel?.close();
        this.stopScan?.();

        window.removeEventListener('online', this.onOnline);
        window.removeEventListener('focus', this.onFocus);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        window.removeEventListener('popstate', this.onPopState);
        document.removeEventListener('visibilitychange', this.onVisibility);
        document.removeEventListener('turbo:before-visit', this.onBeforeVisit);
        document.removeEventListener('keydown', this.onKeydown);

        if (this.historyEntry) {
            this.historyEntry = false;
            this.resumeTurboHistory();
        }
    }

    // ---- Texts ----

    t(key, parameters = {}) {
        let text = this.messagesValue[key] ?? key;

        for (const [name, value] of Object.entries(parameters)) {
            text = text.split(name).join(String(value));
        }

        return text;
    }

    plural(key, count) {
        const message = this.pluralsValue[key];

        return message ? (chooseTranslation(message.message, count, message.locale) ?? String(count)) : String(count);
    }

    describe(result) {
        return describeResult(result, this.round?.piecesCount ?? null, {
            noResult: '–',
            piecesPlaced: this.t('piecesPlaced'),
            piecesPlacedOf: this.t('piecesPlacedOf'),
            didNotStart: this.t('didNotStart'),
        });
    }

    timeOfDay(iso) {
        const date = new Date(iso);

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        try {
            return date.toLocaleTimeString(this.localeValue || undefined, { hour: 'numeric', minute: '2-digit' });
        } catch (e) {
            return date.toISOString().slice(11, 16);
        }
    }

    whoName(enteredBy) {
        if (!enteredBy) {
            return '';
        }

        return enteredBy.playerId === this.userIdValue ? this.t('you') : (enteredBy.name ?? '');
    }

    // ---- State ----

    applyState(state) {
        this.competition = state.competition;
        this.round = state.round;
        this.rounds = Array.isArray(state.rounds) ? state.rounds : [];
        this.stopwatch = state.round?.stopwatch ?? { status: null, startedAt: null, stoppedAt: null };
        this.serverEntries = new Map((state.entries ?? []).map((entry) => [entry.ref, entry]));

        for (const ref of this.serverEntries.keys()) {
            this.localEntries.delete(ref);
        }

        this.indexDirty = true;
    }

    mergeEntries(entries, { fromOwnSave = false } = {}) {
        let countsChanged = false;

        for (const entry of entries) {
            if (entry.roundId !== this.roundIdValue) {
                continue;
            }

            const before = this.serverEntries.get(entry.ref);
            countsChanged = countsChanged || before === undefined || before.tableNumber !== entry.tableNumber;
            this.serverEntries.set(entry.ref, entry);
            this.localEntries.delete(entry.ref);
        }

        this.indexDirty = true;
        this.scheduleRender();

        // An entrant added or a table number given from this device: the round's counts ("Tables: x / y") come with
        // the state - Mercure brings them to the other devices
        if (fromOwnSave && countsChanged) {
            clearTimeout(this.countsTimer);
            this.countsTimer = setTimeout(() => this.refreshState(), 1500);
        }
    }

    usesTables() {
        return this.competition?.isOnline !== true && this.round?.tableNumbersOff !== true;
    }

    /**
     * The server's entries, entrants added on this device and not on the server yet (from the outbox, or just typed
     * in), each with this device's unsent table number on top.
     */
    allEntries() {
        const entries = [...this.serverEntries.values()];
        const known = new Set(this.serverEntries.keys());
        const items = this.outbox ? this.outbox.all() : [];

        for (const item of items) {
            if (item.roundId !== this.roundIdValue || !item.newEntry || known.has(item.entryRef) || this.localEntries.has(item.entryRef)) {
                continue;
            }

            this.localEntries.set(item.entryRef, this.localEntry(item.newEntry));
        }

        for (const [ref, entry] of this.localEntries) {
            if (!known.has(ref)) {
                entries.push(entry);
            }
        }

        // A refused table number (taken meanwhile) is not shown as the entry's table - its problem is
        const waitingTables = items.filter((item) => item.field === 'table_number' && item.state === 'pending');

        return entries.map((entry) => {
            const table = shownValue(entry, 'table_number', waitingTables);

            return table.item !== null ? { ...entry, tableNumber: table.value } : entry;
        });
    }

    localEntry(newEntry) {
        const team = newEntry.kind === 'team';
        const members = team ? (newEntry.members ?? []).map((member) => ({ name: typeof member === 'string' ? member : member.name })) : [];
        const name = newEntry.name ?? null;

        return {
            ref: `${team ? 'team' : 'participant_round'}:${newEntry.clientEntryId}`,
            kind: team ? 'team' : 'person',
            id: newEntry.clientEntryId,
            roundId: this.roundIdValue,
            name,
            displayName: name ?? members.map((member) => member.name).join(', '),
            participantId: null,
            country: null,
            countries: [],
            members,
            playerId: null,
            playerCode: null,
            playerName: null,
            tableNumber: null,
            result: null,
            rank: null,
            qualified: false,
            enteredAt: null,
            enteredBy: null,
            local: true,
            newEntry,
        };
    }

    entry(ref) {
        return this.allEntries().find((entry) => entry.ref === ref) ?? null;
    }

    searchIndex() {
        if (this.indexDirty || !this.index) {
            this.index = buildSearchIndex(this.allEntries());
            this.indexDirty = false;
        }

        return this.index;
    }

    async refreshState() {
        if (this.refreshing) {
            return;
        }

        this.refreshing = true;
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), REQUEST_TIMEOUT_MS);
        const sentAt = Date.now();

        try {
            const answer = await officialResultsRequest(this.stateUrlValue, { signal: abort.signal });
            const answeredAt = Date.now();

            if (answer.kind === 'ok') {
                this.stateBlocked = null;
                this.clockOffset = (sentAt + answeredAt) / 2 - Date.parse(answer.data.serverNow);
                this.applyState(answer.data);
                this.scheduleRender();
                // Subscribed only now: this answer's Mercure cookie authorises the round's private topic
                this.connectEvents();
            } else if (answer.kind === 'auth' || answer.kind === 'forbidden') {
                this.stateBlocked = answer.kind;
                this.scheduleRender();
            }
        } finally {
            clearTimeout(timer);
            this.refreshing = false;
        }
    }

    connectEvents() {
        if (this.eventSource || !this.mercureUrlValue || !this.isConnected()) {
            return;
        }

        const url = new URL(this.mercureUrlValue, window.location.href);
        url.searchParams.append('topic', `/round-results/${this.roundIdValue}`);
        url.searchParams.append('topic', `/round-stopwatch/${this.roundIdValue}`);

        this.eventSource = new EventSource(url, { withCredentials: true });
        this.eventsBroken = false;
        this.eventSource.addEventListener('message', (event) => {
            let data;

            try {
                data = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            this.receiveUpdate(data);
        });
        this.eventSource.addEventListener('error', () => {
            this.eventsBroken = true;
        });
        this.eventSource.addEventListener('open', () => {
            // Back after a drop: whatever was published meanwhile is fetched
            if (this.eventsBroken) {
                this.eventsBroken = false;
                this.refreshState();
            }
        });
    }

    isConnected() {
        return this.element.isConnected;
    }

    receiveUpdate(data) {
        if (typeof data?.type === 'string') {
            if (data.roundId !== this.roundIdValue) {
                return;
            }

            if (data.type === 'official_results.entries') {
                if (data.round) {
                    this.round = data.round;
                }

                this.mergeEntries(Array.isArray(data.entries) ? data.entries : []);
            } else if (data.type === 'official_results.refresh') {
                this.refreshState();
            } else if (data.type === 'official_results.round' && data.round) {
                this.round = data.round;
                this.scheduleRender();
            }

            return;
        }

        // The round's stopwatch (public topic, RoundStopwatchStateController's shape)
        if (data && 'status' in data) {
            this.stopwatch = { status: data.status ?? null, startedAt: data.startedAt ?? null, stoppedAt: data.stoppedAt ?? null };

            if (typeof data.minutesLimit === 'number' && this.round) {
                this.round.minutesLimit = data.minutesLimit;
            }

            this.tickClock(true);
        }
    }

    // ---- Outbox ----

    setupOutbox() {
        const opened = createIndexedDbStorage().then((storage) => {
            if (storage !== null) {
                return storage;
            }

            this.storageMissing = true;
            this.scheduleRender();

            return createMemoryStorage();
        });
        const storage = {
            getAll: async () => (await opened).getAll(),
            get: async (id) => (await opened).get(id),
            put: async (item) => (await opened).put(item),
            delete: async (id) => (await opened).delete(id),
        };

        this.outbox = new Outbox({
            storage,
            userId: this.userIdValue,
            newId: newClientId,
            send: (roundId, changes) => this.sendChanges(roundId, changes),
            lock: createTabLock(`msp-official-results-outbox:${this.userIdValue}`),
            onEntries: (entries, roundId) => {
                if (roundId === this.roundIdValue) {
                    this.mergeEntries(entries, { fromOwnSave: true });
                }
            },
            onChange: () => {
                this.indexDirty = true;
                this.scheduleRender();
            },
        });

        this.outbox.load().then(async () => {
            this.foreign = await this.outbox.foreignCount();
            this.scheduleRender();
            this.flushSoon();
        });
    }

    async sendChanges(roundId, changes) {
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), REQUEST_TIMEOUT_MS);

        try {
            return await officialResultsRequest(this.recordUrlValue.replace(UUID_PLACEHOLDER, roundId), {
                method: 'POST',
                body: { changes },
                csrfToken: this.csrfTokenValue,
                signal: abort.signal,
            });
        } finally {
            clearTimeout(timer);
        }
    }

    flushSoon() {
        if (!this.outbox || !this.outbox.hasUnsent()) {
            return;
        }

        this.outbox.flush().then(() => this.broadcast());
    }

    broadcast() {
        this.channel?.postMessage({ type: 'outbox', userId: this.userIdValue });
    }

    enqueue(entry, field, to) {
        const server = this.serverEntries.get(entry.ref);
        const { item, persisted } = this.outbox.enqueue({
            roundId: this.roundIdValue,
            entryRef: entry.ref,
            newEntry: server === undefined && entry.newEntry ? entry.newEntry : null,
            field,
            serverValue: server !== undefined ? entryValue(server, field) : (field === 'qualified' ? false : null),
            to,
            // The name stays readable on the "unsent" list even when the entry is gone or in another round
            label: entry.displayName ?? entry.name ?? '',
        });

        persisted
            .catch(() => {
                this.storageMissing = true;
                this.scheduleRender();
            })
            .finally(() => {
                this.broadcast();
                this.flushSoon();
            });

        return item;
    }

    async retryAll() {
        this.stateBlocked = null;
        await this.refreshState();
        await this.outbox.retryNow();
        this.broadcast();
    }

    // ---- Rendering (never touches an input) ----

    scheduleRender() {
        if (this.renderQueued) {
            return;
        }

        this.renderQueued = true;
        requestAnimationFrame(() => {
            this.renderQueued = false;

            if (this.isConnected()) {
                this.render();
            }
        });
    }

    render() {
        this.renderHeader();
        this.renderBanner();

        if (this.view === 'find') {
            this.renderFind();
        }

        if (this.view === 'entry' || this.view === 'confirm') {
            this.renderEntryInfo();
        }

        if (!this.sheetTarget.hidden) {
            this.renderSheet();
        }
    }

    renderHeader() {
        const status = this.outbox ? this.outbox.status() : { pending: 0, conflicts: 0, rejected: 0, blocked: null, offline: false, sending: false };
        const blocked = status.blocked ?? this.stateBlocked;
        let state = 'saved';
        let text = this.t('syncSaved');

        if (blocked === 'auth') {
            state = 'blocked';
            text = this.t('syncSignIn');
        } else if (blocked === 'forbidden') {
            state = 'blocked';
            text = this.t('syncForbidden');
        } else if (status.conflicts + status.rejected > 0) {
            state = 'problem';
            text = this.plural('attention', status.conflicts + status.rejected);
        } else if (status.pending > 0) {
            state = 'waiting';
            text = status.offline ? this.plural('offline', status.pending) : (status.sending ? this.t('syncSending') : this.plural('waiting', status.pending));
        }

        this.pillTarget.dataset.state = state;
        this.pillTarget.dataset.sending = status.sending ? '1' : '0';

        if (this.pillTextTarget.textContent !== text) {
            this.pillTextTarget.textContent = text;
        }

        if (this.usesTables() && this.round?.entries) {
            const tables = this.t('tables', { '%assigned%': this.round.entries.withTableNumber, '%total%': this.round.entries.total });
            this.tablesTarget.hidden = false;
            this.tablesTarget.textContent = tables;

            if (this.hasReadinessTarget) {
                this.readinessTarget.hidden = this.round.tablesReadiness !== true || this.round.entries.withTableNumber >= this.round.entries.total;
            }
        } else {
            this.tablesTarget.hidden = true;

            if (this.hasReadinessTarget) {
                this.readinessTarget.hidden = true;
            }
        }
    }

    renderBanner() {
        const blocked = this.outbox?.blocked ?? null;
        const reason = blocked ?? this.stateBlocked;
        const parts = [];

        if (reason === 'auth' || reason === 'forbidden') {
            parts.push(`<div class="alert alert-danger mb-2" role="alert">
                <p class="mb-2">${escapeHtml(this.t(reason === 'auth' ? 'bannerAuth' : 'bannerForbidden'))}</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-danger" href="${escapeHtml(this.loginUrlValue)}" target="_blank" rel="noopener">${escapeHtml(this.t('signIn'))}</a>
                    <button type="button" class="btn btn-outline-danger" data-action="live-results#retry">${escapeHtml(this.t('retry'))}</button>
                </div>
            </div>`);
        }

        if (this.storageMissing) {
            parts.push(`<div class="alert alert-warning mb-2 small" role="alert">${escapeHtml(this.t('bannerStorage'))}</div>`);
        }

        if (this.foreign > 0) {
            parts.push(`<div class="alert alert-secondary mb-2 small" role="status">${escapeHtml(this.plural('foreign', this.foreign))}</div>`);
        }

        const html = parts.join('');

        if (this.bannerHtml !== html) {
            this.bannerHtml = html;
            this.bannerTarget.innerHTML = html;
            this.bannerTarget.hidden = html === '';
        }
    }

    renderFind() {
        const index = this.searchIndex();
        const items = this.outbox ? this.outbox.all() : [];
        const typed = this.query.trim() !== '';
        const tableNumbersOff = !this.usesTables();

        this.recentBlockTarget.hidden = typed;

        if (typed) {
            const found = searchEntries(index, this.query, { tableNumbersOff });
            this.matchesTarget.innerHTML = found.matches.length > 0
                ? found.matches.map((entry) => this.rowHtml(entry, items, entry === found.exactTable)).join('')
                : `<p class="text-muted text-center my-3 mb-0">${escapeHtml(this.t('matchesNone'))}</p>`;
        } else {
            this.matchesTarget.innerHTML = '';
            const recent = recentEntries(this.allEntries(), items.filter((item) => item.roundId === this.roundIdValue));
            this.recentTarget.innerHTML = recent.length > 0
                ? recent.map(({ entry }) => this.rowHtml(entry, items, false)).join('')
                : `<p class="text-muted small mb-0">${escapeHtml(this.t('recentEmpty'))}</p>`;
        }
    }

    rowHtml(entry, items, exact) {
        const result = shownValue(entry, 'result', items);
        const own = items.filter((item) => item.entryRef === entry.ref);
        const sub = [];

        if (entry.kind === 'team' && entry.name !== null && Array.isArray(entry.members)) {
            sub.push(entry.members.map((member) => member.name).join(', '));
        }

        const code = entry.playerCode ?? null;

        if (code) {
            sub.push(`#${code.toUpperCase()}`);
        }

        if (entry.local) {
            sub.push(this.t('local'));
        }

        let sync = '';

        if (own.some((item) => item.state === 'conflict' || item.state === 'rejected')) {
            sync = `<i class="bi bi-exclamation-triangle-fill text-danger" title="${escapeHtml(this.t('rowProblem'))}"></i><span class="visually-hidden">${escapeHtml(this.t('rowProblem'))}</span>`;
        } else if (own.length > 0) {
            sync = `<i class="bi bi-clock-history text-warning" title="${escapeHtml(this.t('rowWaiting'))}"></i><span class="visually-hidden">${escapeHtml(this.t('rowWaiting'))}</span>`;
        } else if (entry.result !== null && entry.result !== undefined) {
            sync = `<i class="bi bi-check2 text-success" title="${escapeHtml(this.t('rowSaved'))}"></i><span class="visually-hidden">${escapeHtml(this.t('rowSaved'))}</span>`;
        }

        const flag = typeof entry.country === 'string' && /^[a-z]{2}(-[a-z]{2,4})?$/.test(entry.country)
            ? `<span class="fi fi-${entry.country} me-1" aria-hidden="true"></span>`
            : '';

        return `<button type="button" class="lr-row${exact ? ' lr-row-exact' : ''}" data-action="live-results#openRow" data-ref="${escapeHtml(entry.ref)}">
            ${this.tableHtml(entry.tableNumber)}
            <span class="lr-row-main">
                <span class="lr-row-name">${flag}${escapeHtml(entry.displayName ?? entry.name ?? '')}</span>
                ${sub.length > 0 ? `<span class="lr-row-sub">${escapeHtml(sub.join(' · '))}</span>` : ''}
            </span>
            <span class="lr-row-result">${escapeHtml(result.value === null || result.value === undefined ? '' : this.describe(result.value))}</span>
            <span class="lr-row-sync">${sync}</span>
        </button>`;
    }

    tableHtml(number) {
        if (!this.usesTables()) {
            return '';
        }

        return typeof number === 'number'
            ? `<span class="lr-table" title="${escapeHtml(this.t('table', { '%number%': number }))}">${number}</span>`
            : `<span class="lr-table lr-table-none" title="${escapeHtml(this.t('noTable'))}">–</span>`;
    }

    renderEntryInfo() {
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;

        if (entry === null) {
            return;
        }

        const items = this.outbox ? this.outbox.all() : [];
        const name = entry.displayName ?? entry.name ?? '';

        this.setText(this.entryNameTarget, name);
        this.setText(this.entryMembersTarget, entry.kind === 'team' && entry.name !== null ? (entry.members ?? []).map((member) => member.name).join(', ') : (entry.playerCode ? `#${entry.playerCode.toUpperCase()}` : ''));
        this.setText(this.entryTableTarget, typeof entry.tableNumber === 'number' ? String(entry.tableNumber) : '–');
        this.entryTableTarget.classList.toggle('lr-table-none', typeof entry.tableNumber !== 'number');

        const shown = shownValue(entry, 'result', items);
        let now;

        if (shown.item !== null && shown.item.state === 'pending') {
            now = `<i class="bi bi-clock-history text-warning me-1" aria-hidden="true"></i>${escapeHtml(this.t('waiting', { '%result%': this.describe(shown.value) }))}`;
        } else if (entry.result !== null && entry.result !== undefined) {
            now = entry.enteredBy
                ? escapeHtml(this.t('savedBy', { '%result%': this.describe(entry.result), '%name%': this.whoName(entry.enteredBy), '%time%': this.timeOfDay(entry.enteredAt) }))
                : escapeHtml(this.t('saved', { '%result%': this.describe(entry.result) }));
            now = `<i class="bi bi-check2-circle text-success me-1" aria-hidden="true"></i>${now}`;
        } else {
            now = `<span class="text-muted">${escapeHtml(entry.local ? this.t('local') : this.t('noResult'))}</span>`;
        }

        if (this.entryNowHtml !== now) {
            this.entryNowHtml = now;
            this.entryNowTarget.innerHTML = now;
        }

        const problems = items.filter((item) => item.entryRef === entry.ref && (item.state !== 'pending' || item.attempts >= STUCK_AFTER_ATTEMPTS));
        const problemsHtml = problems.map((item) => this.problemHtml(item, entry, false)).join('');

        if (this.entryProblemsHtml !== problemsHtml) {
            this.entryProblemsHtml = problemsHtml;
            this.entryProblemsTarget.innerHTML = problemsHtml;
        }

        this.clearButtonTarget.disabled = shown.value === null || shown.value === undefined;
    }

    describeField(field, value) {
        if (field === 'result') {
            return value === null ? this.t('noResult') : this.describe(value);
        }

        if (field === 'table_number') {
            return value === null ? this.t('noTable') : this.t('table', { '%number%': value });
        }

        return value === true ? this.t('qualified') : this.t('notQualified');
    }

    problemHtml(item, entry, withName) {
        const fieldLabel = { result: this.t('fieldResult'), table_number: this.t('fieldTable'), qualified: this.t('fieldQualified') }[item.field] ?? '';
        const title = withName ? `<strong>${escapeHtml(entry?.displayName ?? '')}</strong> · ${escapeHtml(fieldLabel)}` : `<strong>${escapeHtml(fieldLabel)}</strong>`;
        const id = escapeHtml(item.id);

        if (item.state === 'conflict') {
            const theirs = this.describeField(item.field, item.current ?? null);
            const text = item.enteredBy
                ? this.t('conflictBy', { '%result%': theirs, '%name%': this.whoName(item.enteredBy), '%time%': this.timeOfDay(item.enteredAt) })
                : this.t('conflict', { '%result%': theirs });

            return `<div class="alert alert-danger lr-problem" role="alert">
                <div>${title}</div>
                <p class="mb-1">${escapeHtml(text)}</p>
                <p class="mb-2">${escapeHtml(this.t('yours', { '%result%': this.describeField(item.field, item.to) }))}</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-danger" data-action="live-results#keepMine" data-id="${id}">${escapeHtml(this.t('keepMine'))}</button>
                    <button type="button" class="btn btn-outline-danger" data-action="live-results#takeTheirs" data-id="${id}">${escapeHtml(this.t('takeTheirs'))}</button>
                </div>
            </div>`;
        }

        if (item.state === 'rejected') {
            return `<div class="alert alert-danger lr-problem" role="alert">
                <div>${title}: ${escapeHtml(this.describeField(item.field, item.to))}</div>
                <p class="mb-2">${escapeHtml(this.t('rejected', { '%message%': item.message ?? item.reason ?? '' }))}</p>
                <div class="d-flex flex-wrap gap-2">
                    ${item.field === 'result' ? `<button type="button" class="btn btn-danger" data-action="live-results#fix" data-id="${id}" data-ref="${escapeHtml(item.entryRef)}">${escapeHtml(this.t('fix'))}</button>` : ''}
                    <button type="button" class="btn btn-outline-danger" data-action="live-results#discard" data-id="${id}">${escapeHtml(this.t('discard'))}</button>
                </div>
            </div>`;
        }

        const seconds = Math.max(0, Math.ceil((item.nextAttemptAt - Date.now()) / 1000));
        const waiting = this.outbox.inFlight.has(item.id)
            ? this.t('sheetSending')
            : (this.outbox.offline ? this.t('sheetOffline') : (item.attempts >= STUCK_AFTER_ATTEMPTS ? this.t('stuck') : this.t('sheetWaiting')));

        return `<div class="alert ${item.attempts >= STUCK_AFTER_ATTEMPTS ? 'alert-warning' : 'alert-light border'} lr-problem py-2">
            <div>${title}: ${escapeHtml(this.describeField(item.field, item.to))}</div>
            <div class="small">${escapeHtml(waiting)}${seconds > 0 ? ` · ${escapeHtml(this.t('retryIn', { '%seconds%': seconds }))}` : ''}</div>
            ${item.attempts > 0 || this.outbox.offline ? `<button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-action="live-results#retryItem" data-id="${id}">${escapeHtml(this.t('retryNow'))}</button>` : ''}
        </div>`;
    }

    renderSheet() {
        const items = this.outbox.all();

        if (items.length === 0) {
            this.sheetBodyTarget.innerHTML = `<p class="text-success fs-5 my-4 text-center"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>${escapeHtml(this.t('sheetEmpty'))}</p>`;

            return;
        }

        const entries = new Map(this.allEntries().map((entry) => [entry.ref, entry]));
        const roundNames = new Map(this.rounds.map((round) => [round.id, round.name]));
        const sorted = [...items].sort((a, b) => Number(a.state === 'pending') - Number(b.state === 'pending') || a.seq - b.seq);

        this.sheetBodyTarget.innerHTML = sorted.map((item) => {
            const entry = entries.get(item.entryRef) ?? (item.newEntry ? this.localEntry(item.newEntry) : { displayName: item.label ?? '' });
            const other = item.roundId !== this.roundIdValue
                ? `<div class="small text-muted mb-1">${escapeHtml(this.t('sheetOtherRound', { '%round%': roundNames.get(item.roundId) ?? '' }))}</div>`
                : '';

            return other + this.problemHtml(item, entry, true);
        }).join('');

        if (items.some((item) => item.state === 'pending') || this.outbox.blocked !== null) {
            this.sheetBodyTarget.insertAdjacentHTML('beforeend', `<button type="button" class="btn btn-outline-primary w-100 mt-2 lr-big-btn fw-normal" data-action="live-results#retry">${escapeHtml(this.t('sheetRetryAll'))}</button>`);
        }
    }

    setText(element, text) {
        if (element.textContent !== text) {
            element.textContent = text;
        }
    }

    notice(text, kind = 'warning', extraHtml = '') {
        this.findNoticeTarget.innerHTML = `<div class="alert alert-${kind} py-2 mb-0" role="alert">${escapeHtml(text)}${extraHtml}</div>`;
        this.findNoticeTarget.hidden = false;
    }

    clearNotice() {
        this.findNoticeTarget.hidden = true;
        this.findNoticeTarget.innerHTML = '';
    }

    // ---- Stopwatch ----

    serverNow() {
        return Date.now() - this.clockOffset;
    }

    elapsedSeconds() {
        if (this.stopwatch?.status !== 'running' || !this.stopwatch.startedAt) {
            return null;
        }

        return Math.max(0, Math.floor((this.serverNow() - Date.parse(this.stopwatch.startedAt)) / 1000));
    }

    tickClock(force = false) {
        const status = this.stopwatch?.status ?? null;
        const elapsed = this.elapsedSeconds();
        const limit = (this.round?.minutesLimit ?? 0) * 60;
        let text;
        let state = status ?? 'none';

        if (status === 'running' && elapsed !== null) {
            text = `${this.t('stopwatchRunning')} ${formatResultTime(elapsed)}`;

            if (limit > 0 && elapsed >= limit) {
                text = `${this.t('stopwatchTimesUp')} ${formatResultTime(elapsed)}`;
                state = 'times_up';
            }
        } else if (status === 'stopped' && this.stopwatch.startedAt && this.stopwatch.stoppedAt) {
            text = `${this.t('stopwatchStopped')} ${formatResultTime(Math.floor((Date.parse(this.stopwatch.stoppedAt) - Date.parse(this.stopwatch.startedAt)) / 1000))}`;
        } else {
            text = this.t('stopwatchNotStarted');
        }

        if (!force && text === this.lastClockText) {
            return;
        }

        this.lastClockText = text;
        this.stopwatchTarget.textContent = text;
        this.stopwatchTarget.dataset.state = state;

        const running = status === 'running' && elapsed !== null;
        this.finishedNowTarget.hidden = !running;

        if (running) {
            this.finishedNowClockTarget.textContent = formatResultTime(elapsed);
            this.finishedNowTarget.classList.toggle('btn-success', !(limit > 0 && elapsed >= limit));
            this.finishedNowTarget.classList.toggle('btn-warning', limit > 0 && elapsed >= limit);
        }
    }

    // ---- Find ----

    search() {
        this.query = this.findInputTarget.value;
        this.clearNotice();
        this.renderFind();
    }

    find(event) {
        event.preventDefault();
        this.query = this.findInputTarget.value;
        const found = searchEntries(this.searchIndex(), this.query, { tableNumbersOff: !this.usesTables() });
        const entry = entryForEnter(found);

        if (entry !== null) {
            this.openEntry(entry.ref);
        } else {
            this.renderFind();
        }
    }

    openRow(event) {
        const ref = event.currentTarget.dataset.ref;

        if (ref) {
            this.openEntry(ref);
        }
    }

    toggleKeyboard() {
        const next = this.keyboard === 'numeric' ? 'text' : 'numeric';
        this.setKeyboard(next);
        storageSet(KEYBOARD_KEY, next);
        // The new keyboard shows only on a fresh focus
        this.findInputTarget.blur();
        this.findInputTarget.focus();
    }

    setKeyboard(mode) {
        this.keyboard = mode === 'numeric' && this.usesTables() ? 'numeric' : 'text';
        const input = this.findInputTarget;

        if (this.keyboard === 'numeric') {
            input.setAttribute('inputmode', 'numeric');
            input.setAttribute('pattern', '[0-9]*');
        } else {
            input.setAttribute('inputmode', 'search');
            input.removeAttribute('pattern');
        }

        if (this.hasKeyboardToggleTarget) {
            this.keyboardToggleTarget.textContent = this.keyboard === 'numeric' ? this.t('keyboardLetters') : this.t('keyboardDigits');
        }
    }

    switchRound(event) {
        const roundId = event.target.value;

        if (!roundId || roundId === this.roundIdValue) {
            return;
        }

        storageSet(ROUND_KEY + this.competitionIdValue, JSON.stringify({ roundId, at: Date.now() }));
        window.Turbo ? window.Turbo.visit(this.liveUrl(roundId)) : window.location.assign(this.liveUrl(roundId));
    }

    readRememberedRound() {
        try {
            return JSON.parse(storageGet(ROUND_KEY + this.competitionIdValue) ?? 'null');
        } catch (e) {
            return null;
        }
    }

    liveUrl(roundId) {
        return this.liveUrlValue.replace(UUID_PLACEHOLDER, roundId);
    }

    cleanUrl() {
        // ?entrant= / ?auto= do their job once - a reload must not open the entrant again
        const url = new URL(window.location.href);

        if (url.searchParams.has('entrant') || url.searchParams.has('auto')) {
            url.searchParams.delete('entrant');
            url.searchParams.delete('auto');
            window.history.replaceState(window.history.state, '', url.toString());
        }
    }

    // ---- Views ----

    showView(view) {
        this.view = view;
        this.findViewTarget.hidden = view !== 'find';
        this.entryViewTarget.hidden = view !== 'entry';
        this.confirmViewTarget.hidden = view !== 'confirm';
        this.addViewTarget.hidden = view !== 'add';

        if (view !== 'find') {
            this.claimHistory();
        }

        window.scrollTo(0, 0);
    }

    openEntry(ref) {
        const entry = this.entry(ref);

        if (entry === null) {
            return;
        }

        this.closeOverlays({ keepHistory: true });
        this.openRef = ref;
        this.pendingValue = null;
        this.entryNowHtml = null;
        this.entryProblemsHtml = null;
        this.showTimeForm();

        // A refused result opens with the refused value, ready to fix
        const rejected = (this.outbox?.all() ?? []).find((item) => item.entryRef === ref && item.field === 'result' && item.state === 'rejected');
        this.timeInputTarget.value = rejected && rejected.to && typeof rejected.to.seconds === 'number' ? formatResultTime(rejected.to.seconds) : '';
        this.timeTyped();
        this.showView('entry');

        if (rejected && rejected.to && typeof rejected.to.piecesPlaced === 'number') {
            this.didNotFinish();
            this.piecesInputTarget.value = String(rejected.to.piecesPlaced);
            this.piecesTyped();
        }
        this.renderEntryInfo();
        this.tickClock(true);

        if (!this.piecesFormTarget.classList.contains('lr-hidden')) {
            this.piecesInputTarget.focus();
        } else if (!this.finishedNowTarget.hidden && this.timeInputTarget.value === '') {
            // A running stopwatch: "finished now" is the likely next tap - no keyboard over it
            this.finishedNowTarget.focus();
        } else {
            this.timeInputTarget.focus();
        }
    }

    showTimeForm() {
        this.timeFormTarget.classList.remove('lr-hidden');
        this.piecesFormTarget.classList.add('lr-hidden');

        if (this.view === 'entry') {
            this.timeInputTarget.focus();
        }
    }

    backToFind() {
        this.showFind({ focus: true });
        this.releaseHistory();
    }

    showFind({ focus = true } = {}) {
        this.closeOverlays({ keepHistory: true });
        this.openRef = null;
        this.pendingValue = null;
        this.findInputTarget.value = '';
        this.query = '';
        this.showView('find');
        this.renderFind();

        if (focus) {
            this.findInputTarget.focus();
        }
    }

    closeEverything() {
        if (!this.scannerTarget.hidden || !this.sheetTarget.hidden) {
            this.closeOverlays();

            return;
        }

        if (this.view === 'confirm') {
            this.backToEntry();

            return;
        }

        this.backToFind();
    }

    // History: a view or overlay over the find view gets an entry of its own, so the phone's back button/gesture
    // returns to finding instead of leaving the page (Turbo's history waits meanwhile - dynamic_modal_controller.js)
    claimHistory() {
        if (this.historyEntry) {
            return;
        }

        const turboHistory = window.Turbo?.session?.history;

        if (!turboHistory || typeof turboHistory.stop !== 'function') {
            return;
        }

        turboHistory.stop();
        window.history.pushState({ liveResults: true }, '', window.location.href);
        this.historyEntry = true;
    }

    releaseHistory() {
        if (this.historyEntry) {
            this.historyEntry = false;
            this.leavingHistoryEntry = true;
            window.history.back();
        }
    }

    resumeTurboHistory() {
        const turboHistory = window.Turbo?.session?.history;

        if (turboHistory && typeof turboHistory.start === 'function') {
            turboHistory.start();
        }
    }

    handlePopState() {
        if (this.leavingHistoryEntry) {
            this.leavingHistoryEntry = false;
            this.resumeTurboHistory();

            return;
        }

        if (this.historyEntry) {
            this.historyEntry = false;
            this.resumeTurboHistory();
            this.showFind({ focus: false });
        }
    }

    // ---- Enter ----

    timeTyped() {
        const text = this.timeInputTarget.value;
        const parsed = parseResultTime(text);
        const limit = (this.round?.minutesLimit ?? 0) * 60;
        let html;

        if (parsed.empty) {
            html = `<span class="text-muted small">${escapeHtml(this.t('timeHint'))}</span>`;
        } else if (parsed.error === 'out_of_range') {
            html = `<span class="text-danger">${escapeHtml(this.t('timeOutOfRange'))}</span>`;
        } else if (parsed.error) {
            html = `<span class="text-danger">${escapeHtml(this.t('timeInvalid'))}</span>`;
        } else {
            html = `<strong>${escapeHtml(this.t('timeParsed', { '%time%': formatResultTime(parsed.seconds) }))}</strong>`;

            if (limit > 0 && parsed.seconds > limit) {
                html += ` <span class="text-warning small">${escapeHtml(this.t('overLimit', { '%minutes%': this.round.minutesLimit }))}</span>`;
            }
        }

        this.timeInputTarget.classList.toggle('is-invalid', !parsed.empty && parsed.error !== undefined);
        this.timeParsedTarget.innerHTML = html;
    }

    reviewTime(event) {
        event.preventDefault();
        const parsed = parseResultTime(this.timeInputTarget.value);

        if (parsed.empty || parsed.error) {
            this.timeTyped();
            this.timeInputTarget.focus();

            return;
        }

        this.review({ seconds: parsed.seconds });
    }

    finishedNow() {
        // Frozen the moment it is tapped
        const elapsed = this.elapsedSeconds();

        if (elapsed === null || elapsed < 1) {
            return;
        }

        this.review({ seconds: Math.min(elapsed, 86399) }, { finishedNow: true });
    }

    didNotFinish() {
        this.timeFormTarget.classList.add('lr-hidden');
        this.piecesFormTarget.classList.remove('lr-hidden');
        this.piecesInputTarget.value = '';
        this.piecesTyped();
        this.piecesInputTarget.focus();
    }

    piecesLimit() {
        const pieces = this.round?.piecesCount ?? null;

        return typeof pieces === 'number' && pieces > 1 ? pieces - 1 : null;
    }

    piecesTyped() {
        const text = this.piecesInputTarget.value.trim();
        const pieces = this.round?.piecesCount ?? null;
        const value = /^\d+$/.test(text) ? parseInt(text, 10) : null;
        const max = this.piecesLimit();
        let html;

        if (text === '') {
            html = `<span class="text-muted small">${escapeHtml(this.t('piecesHint'))}${pieces ? ` · ${escapeHtml(this.t('piecesOf', { '%pieces%': pieces }))}` : ''}</span>`;
        } else if (value === null || value < 1 || (max !== null && value > max)) {
            html = `<span class="text-danger">${escapeHtml(this.t('piecesInvalid'))}</span>`;
        } else {
            html = `<strong>${escapeHtml(this.describe({ piecesPlaced: value }))}</strong>`;
        }

        this.piecesInputTarget.classList.toggle('is-invalid', text !== '' && html.includes('text-danger'));
        this.piecesParsedTarget.innerHTML = html;
    }

    reviewPieces(event) {
        event.preventDefault();
        const text = this.piecesInputTarget.value.trim();
        const value = /^\d+$/.test(text) ? parseInt(text, 10) : null;
        const max = this.piecesLimit();

        if (value === null || value < 1 || (max !== null && value > max)) {
            this.piecesTyped();
            this.piecesInputTarget.focus();

            return;
        }

        this.review({ piecesPlaced: value });
    }

    didNotStart() {
        this.review({ didNotStart: true });
    }

    clearResult() {
        this.review(null);
    }

    review(value, { finishedNow = false } = {}) {
        const entry = this.entry(this.openRef);

        if (entry === null) {
            return;
        }

        this.pendingValue = value;
        this.pendingFinishedNow = finishedNow;
        const shown = shownValue(entry, 'result', this.outbox.all());
        const table = this.usesTables() && typeof entry.tableNumber === 'number' ? this.t('table', { '%number%': entry.tableNumber }) : '';

        this.confirmTableTarget.textContent = table;
        this.confirmNameTarget.textContent = entry.displayName ?? entry.name ?? '';
        this.confirmResultTarget.textContent = value === null ? this.t('clearResult') : this.describe(value);
        this.confirmResultTarget.classList.toggle('text-danger', value === null);

        let note = finishedNow ? this.t('finishedNowNote') : '';

        if (sameValue(shown.value, value)) {
            note = this.t('same');
        } else if (shown.value !== null && shown.value !== undefined) {
            const replaces = shown.item === null && entry.enteredBy
                ? this.t('replacesBy', { '%result%': this.describe(shown.value), '%name%': this.whoName(entry.enteredBy), '%time%': this.timeOfDay(entry.enteredAt) })
                : this.t('replaces', { '%result%': this.describe(shown.value) });
            note = note !== '' ? `${note} · ${replaces}` : replaces;
        }

        this.confirmNoteTarget.textContent = note;
        this.confirmNoteTarget.className = `small mb-3 ${shown.value !== null && shown.value !== undefined && !sameValue(shown.value, value) ? 'text-warning-emphasis fw-medium' : 'text-muted'}`;
        this.showView('confirm');
        this.saveButtonTarget.focus();
    }

    backToEntry() {
        this.showView('entry');

        if (!this.piecesFormTarget.classList.contains('lr-hidden')) {
            this.piecesInputTarget.focus();
        } else {
            this.timeInputTarget.focus();
        }
    }

    save() {
        const entry = this.entry(this.openRef);

        if (entry === null) {
            this.backToFind();

            return;
        }

        const value = this.pendingValue;
        const shown = shownValue(entry, 'result', this.outbox.all());

        if (sameValue(shown.value, value) && (shown.item === null || shown.item.state === 'pending')) {
            this.showToast(this.t('toastNothing'), null);
            this.backToFind();

            return;
        }

        const item = this.enqueue(entry, 'result', value);

        if (navigator.vibrate) {
            navigator.vibrate(30);
        }

        this.showToast(this.t('toastSaved', {
            '%name%': entry.displayName ?? entry.name ?? '',
            '%result%': value === null ? this.t('clearResult') : this.describe(value),
        }), item);
        this.backToFind();
    }

    // ---- Toast + undo ----

    showToast(text, item) {
        clearTimeout(this.toastTimer);
        this.toastItem = item;
        this.toastTextTarget.textContent = text;
        this.toastUndoTarget.hidden = item === null;
        this.toastTarget.hidden = false;
        this.toastTimer = setTimeout(() => this.hideToast(), TOAST_MS);
    }

    hideToast() {
        clearTimeout(this.toastTimer);
        this.toastTarget.hidden = true;
        this.toastItem = null;
    }

    async undo() {
        const item = this.toastItem;
        this.hideToast();

        if (item === null) {
            return;
        }

        await this.outbox.undo(item);
        this.broadcast();
        this.flushSoon();
        this.showToast(this.t('toastUndone'), null);
        this.openEntry(item.entryRef);
    }

    // ---- Problems ----

    keepMine(event) {
        this.outbox.keepMine(event.currentTarget.dataset.id).finally(() => {
            this.broadcast();
            this.flushSoon();
        });
    }

    takeTheirs(event) {
        this.outbox.takeTheirs(event.currentTarget.dataset.id).finally(() => {
            this.broadcast();
            this.refreshState();
        });
    }

    discard(event) {
        this.outbox.remove(event.currentTarget.dataset.id).finally(() => this.broadcast());
    }

    fix(event) {
        const ref = event.currentTarget.dataset.ref;
        this.closeOverlays({ keepHistory: true });

        if (ref) {
            this.openEntry(ref);
        }
    }

    retry() {
        this.retryAll();
    }

    retryItem(event) {
        this.outbox.retryNow(event.currentTarget.dataset.id).then(() => this.broadcast());
    }

    // ---- Overlays ----

    openSheet() {
        this.renderSheet();
        this.sheetTarget.hidden = false;
        this.claimHistory();
        this.sheetTarget.querySelector('button')?.focus();
    }

    closeOverlays({ keepHistory = false } = {}) {
        const wasOpen = !this.sheetTarget.hidden || !this.scannerTarget.hidden;
        this.sheetTarget.hidden = true;

        if (this.stopScan) {
            this.stopScan();
            this.stopScan = null;
        }

        this.scannerTarget.hidden = true;

        if (wasOpen && !keepHistory && this.view === 'find') {
            this.releaseHistory();
            this.findInputTarget.focus();
        }
    }

    async openScanner() {
        this.clearNotice();
        this.scannerTarget.hidden = false;
        this.scanHintTarget.textContent = this.t('scanHint');
        this.claimHistory();

        const stop = await startQrScan({
            video: this.videoTarget,
            onResult: (text) => this.scanned(text),
            onError: (reason) => {
                this.scanHintTarget.textContent = this.t(reason === 'decoder' ? 'scanDecoder' : 'scanCamera');
            },
        });

        // Closed while the camera was starting
        if (this.scannerTarget.hidden) {
            stop();
        } else {
            this.stopScan = stop;
        }
    }

    scanned(text) {
        this.stopScan = null;
        this.scannerTarget.hidden = true;
        const tag = parseNameTagUrl(text);

        if (tag === null || tag.competitionId !== this.competitionIdValue.toLowerCase()) {
            this.showFind({ focus: false });
            this.releaseHistory();
            this.notice(this.t('scanForeign'), 'warning');

            return;
        }

        const entry = entryOfParticipant(this.allEntries(), tag.participantId);

        if (entry === null) {
            this.showFind({ focus: false });
            this.releaseHistory();
            const link = this.scanUrlValue.replace(UUID_PLACEHOLDER, tag.participantId);
            this.notice(this.t('scanNotInRound'), 'warning', ` <a class="alert-link" href="${escapeHtml(link)}">${escapeHtml(this.t('openTheirRound'))}</a>`);

            return;
        }

        if (navigator.vibrate) {
            navigator.vibrate(40);
        }

        this.openEntry(entry.ref);
    }

    // ---- Quick add ----

    openAdd() {
        const typed = this.query.trim();
        this.clearNotice();
        this.addFormTarget.reset();
        this.addErrorTarget.hidden = true;

        if (typed !== '' && /^\d+$/.test(typed) && this.hasAddTableTarget) {
            this.addTableTarget.value = typed;
        } else if (typed !== '' && !typed.startsWith('#')) {
            this.addNameTarget.value = typed;
        }

        this.showView('add');
        this.addTyped();
        this.addNameTarget.focus();
    }

    addMemberInput(event) {
        const inputs = this.addMembersTarget.querySelectorAll('input[data-member]');

        if (inputs.length >= 20) {
            return;
        }

        const label = (event.currentTarget.dataset.label ?? '').replace('__N__', String(inputs.length + 1));
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-lg';
        input.dataset.member = '';
        input.setAttribute('aria-label', label);
        input.placeholder = label;
        input.autocomplete = 'off';
        input.setAttribute('autocapitalize', 'words');
        input.maxLength = 255;
        this.addMembersTarget.appendChild(input);
        input.focus();
    }

    addValues() {
        const name = this.addNameTarget.value.trim();
        const members = this.hasAddMembersTarget
            ? [...this.addMembersTarget.querySelectorAll('input[data-member]')].map((input) => input.value.trim()).filter((value) => value !== '')
            : [];
        const tableText = this.hasAddTableTarget ? this.addTableTarget.value.trim() : '';

        return { name, members, tableText };
    }

    addTyped() {
        const { name, members } = this.addValues();
        const typed = [name, ...members].filter((value) => value.length >= 2);
        const seen = new Set();
        const suggestions = [];

        for (const text of typed) {
            for (const entry of searchEntries(this.searchIndex(), text, { tableNumbersOff: true, limit: 3 }).matches) {
                if (!seen.has(entry.ref) && suggestions.length < 5) {
                    seen.add(entry.ref);
                    suggestions.push(entry);
                }
            }
        }

        const items = this.outbox ? this.outbox.all() : [];
        this.addSuggestionListTarget.innerHTML = suggestions.map((entry) => this.rowHtml(entry, items, false)).join('');
        this.addSuggestionsTarget.hidden = suggestions.length === 0;
    }

    addEntrant(event) {
        event.preventDefault();
        const solo = this.round?.category === 'solo';
        const { name, members, tableText } = this.addValues();
        let error = null;
        let table = null;

        if (solo && name === '') {
            error = this.t('addNameMissing');
        } else if (!solo && name === '' && members.length === 0) {
            error = this.t('addTeamMissing');
        }

        if (error === null && tableText !== '') {
            table = /^\d+$/.test(tableText) ? parseInt(tableText, 10) : null;

            if (table === null || table < 1 || table > 9999) {
                error = this.t('addTableInvalid');
            } else {
                const holder = this.allEntries().find((entry) => entry.tableNumber === table);

                if (holder) {
                    error = this.t('addTableTaken', { '%number%': table, '%name%': holder.displayName ?? holder.name ?? '' });
                }
            }
        }

        if (error !== null) {
            this.addErrorTarget.textContent = error;
            this.addErrorTarget.hidden = false;

            return;
        }

        const clientEntryId = newClientId();
        const newEntry = solo
            ? { clientEntryId, kind: 'person', name }
            : { clientEntryId, kind: 'team', name: name === '' ? null : name, members };
        const entry = this.localEntry(newEntry);
        this.localEntries.set(entry.ref, entry);
        this.indexDirty = true;

        if (table !== null) {
            this.enqueue(entry, 'table_number', table);
        }

        this.openEntry(entry.ref);
    }
}
