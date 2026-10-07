/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { officialResultsRequest, newClientId, isGone } from '../official_results_api.js';
import { OfficialResultsEvents } from '../official_results_events.js';
import { formatResultTime, parseResultTime } from '../official_results_time.js';
import { rankEntries } from '../official_results_ranking.js';
import { bestOfEachCountry, qualificationDiff, topN } from '../official_results_qualification.js';
import { PendingChanges, sameValue } from '../official_results_pending_changes.js';
import { chooseTranslation } from '../translation_choice.js';

const RESULT = 'result';
const TABLE = 'table_number';
const QUALIFIED = 'qualified';
const RETRY_SECONDS = [2, 5, 10, 20, 30];
const RESYNC_EVERY_MS = 60000;
const OWN_WRITE_WINDOW_MS = 5000;
const MAX_CHANGES_PER_REQUEST = 500;

/**
 * The results desk of one round (docs/features/competitions-management/results-desk.md): the ranked table with
 * inline edits of results, table numbers and qualified marks, the qualification helpers and publishing.
 *
 * Every write is a change set to `official_results_record` with the value the desk last saw from the server as
 * `from`, so a value somebody else saved meanwhile comes back as a conflict (keep mine / take theirs) instead of
 * being overwritten. Unsaved changes live in PendingChanges and stay visibly unsaved until the server answered for
 * exactly that value - a lost connection, a login redirect or a refused change never look saved. One request at a
 * time, in order; offline and 5xx retry with backoff; signed out stops sending until the organiser signed in again.
 *
 * Other devices' saves arrive over the round's private Mercure topic, on the page's own stream with the token its
 * state carries (official_results_events.js: reopened, caught up and renewed with the state); the state is fetched
 * again on `official_results.refresh`, when the tab comes back, when the connection returns and once a minute while
 * shown - the last safety net.
 */
export default class extends Controller {
    static targets = [
        'tbody', 'tableColumn', 'syncPill', 'banner', 'conflictBar', 'counts', 'readiness', 'publication', 'search',
        'filter', 'emptyState', 'helperModal', 'helperBody', 'helperSummary', 'helperApply', 'publishModal',
        'publishModalTitle', 'publishModalBody', 'publishConfirm',
    ];

    static values = {
        state: Object,
        urls: Object,
        csrfToken: String,
        texts: Object,
        countries: Object,
        locale: String,
    };

    connect() {
        this.pending = new PendingChanges();
        this.competition = this.stateValue.competition;
        this.round = this.stateValue.round;
        this.entries = new Map();
        this.rows = new Map();
        this.editor = null;
        this.frozenOrder = null;
        this.transport = 'ok';
        this.flushing = false;
        this.retryIndex = 0;
        this.retryTimer = null;
        this.resyncing = null;
        this.resyncTimer = null;
        this.ownWrites = new Map();
        this.helper = null;
        this.publishAction = null;
        this.hiddenSince = null;
        // Counts every merge of newer entries - a state fetched meanwhile may be older than them (resync)
        this.generation = 0;

        this.replaceEntries(this.stateValue.entries ?? []);

        this.onVisibility = this.onVisibility.bind(this);
        this.onOnline = this.onOnline.bind(this);
        this.onBeforeUnload = this.onBeforeUnload.bind(this);
        this.onBeforeVisit = this.onBeforeVisit.bind(this);
        this.onUnsavedMarks = this.onUnsavedMarks.bind(this);

        this.events = new OfficialResultsEvents({
            subscription: this.stateValue.mercure ?? null,
            onMessage: (data) => this.onMercure({ detail: data }),
            refresh: () => this.resync(),
        });
        document.addEventListener('official-results:unsaved-marks', this.onUnsavedMarks);
        document.addEventListener('visibilitychange', this.onVisibility);
        document.addEventListener('turbo:before-visit', this.onBeforeVisit);
        window.addEventListener('online', this.onOnline);
        window.addEventListener('beforeunload', this.onBeforeUnload);

        this.resyncInterval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.resync();
            }
        }, RESYNC_EVERY_MS);

        this.render();
        this.events.start();
    }

    disconnect() {
        this.events.close();
        document.removeEventListener('official-results:unsaved-marks', this.onUnsavedMarks);
        document.removeEventListener('visibilitychange', this.onVisibility);
        document.removeEventListener('turbo:before-visit', this.onBeforeVisit);
        window.removeEventListener('online', this.onOnline);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        clearInterval(this.resyncInterval);
        clearTimeout(this.retryTimer);
        clearTimeout(this.resyncTimer);
        this.modal(this.helperModalTarget)?.hide();
        this.modal(this.publishModalTarget)?.hide();
    }

    // ---------------------------------------------------------------- texts

    t(key, params = {}) {
        let text = this.textsValue[key];

        if (typeof text !== 'string') {
            return key;
        }

        for (const [name, value] of Object.entries(params)) {
            text = text.replaceAll(`%${name}%`, String(value));
        }

        return text;
    }

    tc(key, count, params = {}) {
        const message = this.textsValue[key];
        let text = message && typeof message === 'object'
            ? chooseTranslation(message.message, count, message.locale)
            : null;

        if (text === null) {
            text = typeof message === 'string' ? message : String(count);
        }

        for (const [name, value] of Object.entries({ count, ...params })) {
            text = text.replaceAll(`%${name}%`, String(value));
        }

        return text;
    }

    // ---------------------------------------------------------------- state

    replaceEntries(list) {
        this.entries = new Map(list.map((entry) => [entry.ref, entry]));

        for (const cell of this.pending.list()) {
            if (!this.entries.has(cell.ref)) {
                this.pending.discard(cell.ref, cell.field);
            }
        }
    }

    mergeEntries(list, fromLiveUpdate) {
        const now = Date.now();
        let suspicious = false;

        for (const incoming of list ?? []) {
            const current = this.entries.get(incoming.ref);
            let merged = incoming;

            // An answer or an update that arrives late must not undo a newer result
            if (current !== undefined && current.enteredAt && incoming.enteredAt && Date.parse(incoming.enteredAt) < Date.parse(current.enteredAt)) {
                merged = { ...incoming, result: current.result, enteredAt: current.enteredAt, enteredBy: current.enteredBy };
            }

            if (current !== undefined && fromLiveUpdate) {
                const ownWrite = this.ownWrites.get(incoming.ref);
                if (ownWrite !== undefined && now - ownWrite < OWN_WRITE_WINDOW_MS && !sameValue(this.fields(current), this.fields(incoming))) {
                    suspicious = true;
                }
            }

            this.entries.set(incoming.ref, merged);
        }

        this.generation++;

        if (suspicious) {
            // Our own answer and a live update disagree about an entry we just saved - ask the server once more
            clearTimeout(this.resyncTimer);
            this.resyncTimer = setTimeout(() => this.resync(), 1500);
        }
    }

    fields(entry) {
        return [entry.result, entry.tableNumber, entry.qualified];
    }

    updateRound(overview) {
        if (overview && overview.id === this.round.id) {
            // The JSON endpoints answer the overview only - keep what the page knows on top (colours, public URL)
            this.round = { ...this.round, ...overview };
        }
    }

    serverValue(entry, field) {
        if (field === RESULT) {
            return entry.result ?? null;
        }

        if (field === TABLE) {
            return entry.tableNumber ?? null;
        }

        return entry.qualified === true;
    }

    shown(entry, field) {
        return this.pending.value(entry.ref, field, this.serverValue(entry, field));
    }

    usesTableNumbers() {
        return !this.competition.isOnline && !this.round.tableNumbersOff;
    }

    rankedEntries() {
        return rankEntries([...this.entries.values()].map((entry) => ({
            ...entry,
            result: this.shown(entry, RESULT),
            tableNumber: this.shown(entry, TABLE),
            qualified: this.shown(entry, QUALIFIED),
        })));
    }

    // ---------------------------------------------------------------- rendering

    render() {
        this.renderTable();
        this.renderCounts();
        this.renderSync();
        this.renderPublication();
    }

    visibleEntries() {
        let ranked = this.rankedEntries();

        if (this.editor !== null && this.frozenOrder !== null) {
            const position = new Map(this.frozenOrder.map((ref, index) => [ref, index]));
            ranked = [...ranked].sort((a, b) => (position.get(a.ref) ?? Infinity) - (position.get(b.ref) ?? Infinity));
        }

        const query = this.hasSearchTarget ? normalise(this.searchTarget.value) : '';
        const filter = this.hasFilterTarget ? this.filterTarget.value : 'all';

        return ranked.filter((entry) => {
            if (this.editor !== null && this.editor.ref === entry.ref) {
                return true;
            }

            return this.matchesFilter(entry, filter) && this.matchesSearch(entry, query);
        });
    }

    matchesFilter(entry, filter) {
        switch (filter) {
            case 'no_result':
                return entry.result === null;
            case 'qualified':
                return entry.qualified === true;
            case 'not_saved':
                return [RESULT, TABLE, QUALIFIED].some((field) => this.pending.get(entry.ref, field) !== null);
            default:
                return true;
        }
    }

    matchesSearch(entry, query) {
        if (query === '') {
            return true;
        }

        if (/^\d+$/.test(query) && String(entry.tableNumber ?? '') === query) {
            return true;
        }

        const haystack = [
            entry.displayName,
            entry.name,
            entry.playerCode ? `#${entry.playerCode}` : '',
            entry.playerName,
            ...(entry.members ?? []).flatMap((member) => [member.name, member.playerCode ? `#${member.playerCode}` : '', member.playerName]),
        ].map(normalise).join(' ');

        return haystack.includes(query);
    }

    renderTable() {
        const usesTables = this.usesTableNumbers();
        this.tableColumnTargets.forEach((column) => { column.hidden = !usesTables; });

        const visible = this.visibleEntries();
        const visibleRefs = new Set(visible.map((entry) => entry.ref));
        const focusKey = this.focusedKey();
        const tbody = this.tbodyTarget;

        for (const [ref, row] of this.rows) {
            if (!visibleRefs.has(ref)) {
                row.remove();
                this.rows.delete(ref);
            }
        }

        let previous = null;
        for (const entry of visible) {
            let row = this.rows.get(entry.ref);

            if (row === undefined) {
                row = document.createElement('tr');
                row.dataset.ref = entry.ref;
                this.rows.set(entry.ref, row);
            }

            if (this.editor === null || this.editor.ref !== entry.ref || row.dataset.editing !== 'true') {
                const html = this.rowHtml(entry, usesTables);

                if (row.dataset.html !== html) {
                    row.innerHTML = html;
                    row.dataset.html = html;
                }
            }

            row.classList.toggle('table-success', entry.qualified === true);

            const expected = previous === null ? tbody.firstElementChild : previous.nextElementSibling;
            if (expected !== row) {
                tbody.insertBefore(row, expected);
            }

            previous = row;
        }

        if (this.hasEmptyStateTarget) {
            this.emptyStateTarget.hidden = visible.length > 0;
            this.emptyStateTarget.textContent = this.entries.size === 0 ? this.t('empty_round') : this.t('empty_filter');
        }

        this.restoreFocus(focusKey);
    }

    focusedKey() {
        const active = document.activeElement;

        if (!active || !this.tbodyTarget.contains(active)) {
            return null;
        }

        return active.closest('[data-focus-key]')?.dataset.focusKey ?? null;
    }

    restoreFocus(key) {
        if (key === null) {
            return;
        }

        const active = document.activeElement;
        if (active && this.tbodyTarget.contains(active)) {
            return;
        }

        const target = [...this.tbodyTarget.querySelectorAll('[data-focus-key]')].find((element) => element.dataset.focusKey === key);
        target?.focus({ preventScroll: true });
    }

    rowHtml(entry, usesTables) {
        const ref = escapeHtml(entry.ref);
        const name = entry.displayName ?? '';
        const cells = [];

        cells.push(`<td class="text-end fw-semibold text-nowrap">${entry.rank ?? (entry.result?.didNotStart ? '<span class="text-body-secondary">–</span>' : '')}</td>`);

        if (usesTables) {
            cells.push(`<td class="text-nowrap">
                <button type="button" class="btn btn-sm btn-link text-reset text-decoration-none results-desk-cell" data-action="results-desk#editTable" data-ref="${ref}" data-focus-key="table:${ref}" aria-label="${escapeHtml(this.t('edit_table_label', { name }))}">${entry.tableNumber !== null && entry.tableNumber !== undefined ? escapeHtml(String(entry.tableNumber)) : '<span class="text-body-secondary">—</span>'}</button>
                ${this.cellState(entry, TABLE)}
            </td>`);
        }

        cells.push(`<td>${this.entrantHtml(entry)}${this.takeOutHtml(entry)}</td>`);
        cells.push(`<td class="text-nowrap">${this.flagsHtml(entry.countries ?? [])}</td>`);
        cells.push(`<td>
            <button type="button" class="btn btn-sm btn-link text-reset text-decoration-none text-nowrap results-desk-cell results-desk-result" data-action="results-desk#editResult" data-ref="${ref}" data-focus-key="result:${ref}" aria-label="${escapeHtml(this.t('edit_result_label', { name }))}">${entry.result === null ? `<span class="text-body-secondary">${escapeHtml(this.t('add_result'))}</span>` : escapeHtml(this.formatResult(entry.result))}</button>
            ${this.cellState(entry, RESULT)}
        </td>`);
        cells.push(`<td class="small text-body-secondary">${this.enteredHtml(this.entries.get(entry.ref))}</td>`);
        cells.push(`<td class="text-center">
            <label class="results-desk-check d-inline-flex align-items-center justify-content-center">
                <input type="checkbox" class="form-check-input m-0" data-action="change->results-desk#toggleQualified" data-ref="${ref}" data-focus-key="qualified:${ref}" ${entry.qualified ? 'checked' : ''} aria-label="${escapeHtml(this.t('qualified_label', { name }))}">
            </label>
            ${this.cellState(entry, QUALIFIED)}
        </td>`);

        return cells.join('');
    }

    entrantHtml(entry) {
        const lines = [`<div class="fw-semibold">${escapeHtml(entry.displayName ?? '')}</div>`];

        if (entry.kind === 'person') {
            if (entry.playerCode) {
                const playerName = entry.playerName && entry.playerName !== entry.name ? ` ${escapeHtml(entry.playerName)}` : '';
                lines.push(`<div class="small text-body-secondary">#${escapeHtml(String(entry.playerCode).toUpperCase())}${playerName}</div>`);
            }
        } else if (entry.name !== null && (entry.members ?? []).length > 0) {
            const members = entry.members.map((member) => `${this.flagsHtml(member.country ? [member.country] : [])} ${escapeHtml(member.name)}${member.playerCode ? ` <span class="text-body-secondary">#${escapeHtml(String(member.playerCode).toUpperCase())}</span>` : ''}`);
            lines.push(`<div class="small">${members.join('<span class="text-body-secondary">, </span>')}</div>`);
        }

        return lines.join('');
    }

    /**
     * "Take out of this round" - only for an entry without a result or a qualified mark (saved or on its way): a
     * mistaken advance, the wrong group. Official data is never removed this way (the server refuses it too).
     */
    takeOutHtml(entry) {
        const server = this.entries.get(entry.ref);

        if (!server || server.result !== null || server.qualified === true || [RESULT, QUALIFIED].some((field) => this.pending.get(entry.ref, field) !== null)) {
            return '';
        }

        return `<button type="button" class="btn btn-sm btn-link p-0 small text-body-secondary results-desk-take-out" data-action="results-desk#takeOut" data-ref="${escapeHtml(entry.ref)}" data-focus-key="take-out:${escapeHtml(entry.ref)}">
            <i class="bi bi-box-arrow-left me-1" aria-hidden="true"></i>${escapeHtml(this.t('take_out'))}
        </button>`;
    }

    flagsHtml(countries) {
        return countries
            .filter((country) => /^[a-z]{2}$/.test(country))
            .map((country) => {
                const label = escapeHtml(this.countriesValue[country] ?? country.toUpperCase());

                return `<span class="fi fi-${country} shadow-custom" role="img" aria-label="${label}" title="${label}"></span>`;
            })
            .join(' ');
    }

    enteredHtml(entry) {
        if (!entry || !entry.enteredAt) {
            return '';
        }

        const who = entry.enteredBy?.name ?? '';
        const when = this.formatTime(entry.enteredAt);

        return escapeHtml(who !== '' ? this.t('entered_by', { name: who, time: when }) : when);
    }

    cellState(entry, field) {
        const cell = this.pending.get(entry.ref, field);

        if (cell === null) {
            return '';
        }

        const ref = escapeHtml(entry.ref);
        const fieldAttr = escapeHtml(field);

        if (cell.status === 'sending' || (cell.status === 'queued' && this.transport === 'ok')) {
            return `<span class="spinner-border spinner-border-sm text-secondary align-middle ms-1" role="status"><span class="visually-hidden">${escapeHtml(this.t('status_saving'))}</span></span>`;
        }

        if (cell.status === 'queued') {
            const key = this.transport === 'auth' ? 'status_sign_in' : (this.transport === 'forbidden' ? 'status_forbidden' : 'status_waiting');

            return `<i class="bi bi-cloud-slash text-warning ms-1 align-middle" role="img" aria-label="${escapeHtml(this.t(key))}" title="${escapeHtml(this.t(key))}"></i>`;
        }

        if (cell.status === 'error' && field === TABLE && cell.reason === 'table_number_taken') {
            const holder = this.tableHolder(cell.to, entry.ref);

            if (holder !== null) {
                return `<div class="small text-danger mt-1" role="alert">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>${escapeHtml(this.t('table_taken_by', { number: cell.to, name: holder.displayName ?? '' }))}
                    <span class="d-inline-flex gap-1 ms-1">
                        <button type="button" class="btn btn-sm btn-outline-primary py-0" data-action="results-desk#swapTables" data-ref="${ref}" data-holder="${escapeHtml(holder.ref)}">${escapeHtml(this.t('swap_tables'))}</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-action="results-desk#discardCell" data-ref="${ref}" data-field="${fieldAttr}">${escapeHtml(this.t('discard'))}</button>
                    </span>
                </div>`;
            }
        }

        if (cell.status === 'error') {
            return `<div class="small text-danger mt-1" role="alert">
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>${escapeHtml(this.t('status_not_saved'))}${cell.message ? `: ${escapeHtml(cell.message)}` : ''}
                <span class="d-inline-flex gap-1 ms-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-action="results-desk#retryCell" data-ref="${ref}" data-field="${fieldAttr}">${escapeHtml(this.t('retry'))}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-action="results-desk#discardCell" data-ref="${ref}" data-field="${fieldAttr}">${escapeHtml(this.t('discard'))}</button>
                </span>
            </div>`;
        }

        // Conflict: somebody else saved another value meanwhile
        const theirs = this.formatField(field, cell.conflict?.current ?? null);
        const who = cell.conflict?.enteredBy?.name;
        const text = who ? this.t('conflict_by', { name: who, value: theirs }) : this.t('conflict', { value: theirs });

        return `<div class="small text-danger mt-1" role="alert">
            <i class="bi bi-people me-1" aria-hidden="true"></i>${escapeHtml(text)}
            <span class="d-inline-flex gap-1 ms-1">
                <button type="button" class="btn btn-sm btn-outline-danger py-0" data-action="results-desk#keepMine" data-ref="${ref}" data-field="${fieldAttr}">${escapeHtml(this.t('keep_mine'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-action="results-desk#takeTheirs" data-ref="${ref}" data-field="${fieldAttr}">${escapeHtml(this.t('take_theirs'))}</button>
            </span>
        </div>`;
    }

    formatField(field, value) {
        if (field === RESULT) {
            return value === null ? this.t('no_result') : this.formatResult(value);
        }

        if (field === TABLE) {
            return value === null ? this.t('no_table') : String(value);
        }

        return value ? this.t('qualified_yes') : this.t('qualified_no');
    }

    formatResult(result) {
        if (result === null || result === undefined) {
            return '';
        }

        if (Number.isInteger(result.seconds)) {
            return formatResultTime(result.seconds);
        }

        if (Number.isInteger(result.piecesPlaced)) {
            return Number.isInteger(this.round.piecesCount)
                ? this.t('pieces_placed_of', { placed: result.piecesPlaced, pieces: this.round.piecesCount })
                : this.t('pieces_placed', { placed: result.piecesPlaced });
        }

        return result.didNotStart ? this.t('did_not_start') : '';
    }

    /**
     * "entered at" / "published since" in the round's zone - the zone the export and the round's pages use, not the
     * device's (review2-b nit); the date is left out on the round's own today.
     */
    formatTime(iso) {
        const date = new Date(iso);

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        const zone = typeof this.round.timezone === 'string' && this.round.timezone !== '' ? this.round.timezone : undefined;
        const day = (value) => {
            try {
                return new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(value);
            } catch (e) {
                return value.toDateString();
            }
        };
        const today = day(new Date()) === day(date);
        const options = today ? { hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' };

        try {
            return new Intl.DateTimeFormat(this.localeValue || undefined, { ...options, timeZone: zone }).format(date);
        } catch (e) {
            return date.toLocaleString();
        }
    }

    renderCounts() {
        const ranked = this.rankedEntries();
        const total = ranked.length;
        const withResult = ranked.filter((entry) => entry.result !== null).length;
        const qualified = ranked.filter((entry) => entry.qualified === true).length;

        if (this.hasCountsTarget) {
            this.countsTarget.textContent = [
                this.tc('count_entries', total),
                this.tc('count_results', withResult),
                this.tc('count_qualified', qualified),
            ].join(' · ');
        }

        if (this.hasReadinessTarget) {
            // The server's one rule (SeatingReadiness, `tablesReadiness`) decides whether it shows - the numbers follow
            // what the desk shows, unsaved table numbers included
            const seated = ranked.filter((entry) => Number.isInteger(entry.tableNumber)).length;
            const shows = this.usesTableNumbers() && total > 0 && this.round.tablesReadiness === true;

            this.readinessTarget.hidden = !shows;

            if (shows) {
                const done = seated >= total;
                this.readinessTarget.innerHTML = `<div class="small ${done ? 'text-success' : 'text-warning-emphasis'}" data-seating-readiness><i class="bi ${done ? 'bi-check-circle' : 'bi-exclamation-circle'} me-1" aria-hidden="true"></i>${escapeHtml(this.t('readiness_progress', { assigned: seated, total }))} <span class="text-body-secondary">- ${escapeHtml(this.t(done ? 'readiness_done' : 'readiness_recommended'))}</span></div>`;
            }
        }
    }

    renderSync() {
        const counts = this.pending.counts();
        let tone = 'success';
        let text = this.t('pill_all_saved');
        let banner = null;

        if (this.transport === 'gone') {
            tone = 'danger';
            text = this.t('pill_gone');
            banner = 'gone';
        } else if (this.transport === 'auth') {
            tone = 'danger';
            text = this.t('pill_sign_in');
            banner = 'auth';
        } else if (this.transport === 'forbidden') {
            tone = 'danger';
            text = this.t('pill_forbidden');
            banner = 'forbidden';
        } else if (counts.conflict > 0) {
            tone = 'warning';
            text = this.tc('pill_conflicts', counts.conflict);
        } else if (counts.error > 0) {
            tone = 'danger';
            text = this.tc('pill_not_saved', counts.error);
        } else if ((this.transport === 'offline' || this.transport === 'server') && counts.total > 0) {
            tone = 'warning';
            text = this.tc('pill_waiting', counts.total);
            banner = 'offline';
        } else if (counts.total > 0) {
            tone = 'secondary';
            text = this.t('pill_saving');
        }

        if (this.hasSyncPillTarget) {
            this.syncPillTarget.className = `badge rounded-pill fs-6 fw-normal text-bg-${tone}`;
            this.syncPillTarget.textContent = text;
        }

        if (this.hasBannerTarget) {
            this.bannerTarget.querySelectorAll('[data-banner]').forEach((element) => {
                element.hidden = element.dataset.banner !== banner;
            });
            this.bannerTarget.hidden = banner === null;
        }

        if (this.hasConflictBarTarget) {
            this.conflictBarTarget.hidden = counts.conflict < 2;
            const label = this.conflictBarTarget.querySelector('[data-conflict-count]');

            if (label) {
                label.textContent = this.tc('conflicts_bar', counts.conflict);
            }
        }
    }

    renderPublication() {
        if (!this.hasPublicationTarget) {
            return;
        }

        const published = this.round.resultsPublished === true;
        this.publicationTarget.querySelectorAll('[data-published]').forEach((element) => {
            element.hidden = (element.dataset.published === 'true') !== published;
        });

        const since = this.publicationTarget.querySelector('[data-published-since]');
        if (since) {
            since.textContent = published && this.round.resultsPublishedAt ? this.t('published_since', { time: this.formatTime(this.round.resultsPublishedAt) }) : '';
        }
    }

    // ---------------------------------------------------------------- editing

    editResult(event) {
        this.openEditor(event.currentTarget.dataset.ref, RESULT);
    }

    editTable(event) {
        this.openEditor(event.currentTarget.dataset.ref, TABLE);
    }

    openEditor(ref, field) {
        const entry = this.entries.get(ref);

        if (entry === undefined) {
            return;
        }

        if (this.editor !== null) {
            this.closeEditor(false);
        }

        this.frozenOrder = this.visibleEntries().map((visible) => visible.ref);
        this.editor = { ref, field };

        const row = this.rows.get(ref);
        if (row === undefined) {
            return;
        }

        row.dataset.editing = 'true';
        row.dataset.html = '';
        const cell = row.querySelector(field === RESULT ? '.results-desk-result' : `[data-focus-key="table:${CSS.escape(ref)}"]`)?.closest('td');

        if (!cell) {
            return;
        }

        cell.innerHTML = field === RESULT ? this.resultEditorHtml(entry) : this.tableEditorHtml(entry);
        this.editorPreview();
        cell.querySelector('[data-editor-input]:not([hidden])')?.focus();
        cell.querySelector('[data-editor-input]:not([hidden])')?.select?.();
    }

    resultEditorHtml(entry) {
        const shown = this.shown(entry, RESULT);
        const mode = shown === null ? 'time' : (Number.isInteger(shown.seconds) ? 'time' : (Number.isInteger(shown.piecesPlaced) ? 'pieces' : (shown.didNotStart ? 'dns' : 'time')));
        const value = shown === null ? '' : (Number.isInteger(shown.seconds) ? formatResultTime(shown.seconds) : (Number.isInteger(shown.piecesPlaced) ? String(shown.piecesPlaced) : ''));
        const option = (key, label) => `<option value="${key}" ${key === mode ? 'selected' : ''}>${escapeHtml(label)}</option>`;

        return `<form class="results-desk-editor" data-editor="result" novalidate data-action="submit->results-desk#saveEditor keydown->results-desk#editorKeydown">
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <select class="form-select form-select-sm w-auto" data-editor-mode aria-label="${escapeHtml(this.t('editor_kind'))}" data-action="change->results-desk#editorPreview">
                    ${option('time', this.t('mode_time'))}
                    ${option('pieces', this.t('mode_pieces'))}
                    ${option('dns', this.t('mode_dns'))}
                    ${option('none', this.t('mode_none'))}
                </select>
                <input type="text" class="form-control form-control-sm results-desk-input" data-editor-input inputmode="numeric" autocomplete="off" value="${escapeHtml(value)}" aria-label="${escapeHtml(this.t('editor_value'))}" aria-describedby="results-desk-preview" data-action="input->results-desk#editorPreview">
                <button type="submit" class="btn btn-sm btn-primary" data-editor-save>${escapeHtml(this.t('save'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-action="results-desk#cancelEditor">${escapeHtml(this.t('cancel'))}</button>
            </div>
            <div class="small mt-1" id="results-desk-preview" data-editor-preview aria-live="polite"></div>
        </form>`;
    }

    tableEditorHtml(entry) {
        const shown = this.shown(entry, TABLE);

        return `<form class="results-desk-editor" data-editor="table" novalidate data-action="submit->results-desk#saveEditor keydown->results-desk#editorKeydown">
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <input type="text" class="form-control form-control-sm results-desk-input results-desk-input-table" data-editor-input inputmode="numeric" autocomplete="off" value="${shown === null ? '' : escapeHtml(String(shown))}" aria-label="${escapeHtml(this.t('editor_table'))}" aria-describedby="results-desk-preview" data-action="input->results-desk#editorPreview">
                <button type="submit" class="btn btn-sm btn-primary" data-editor-save>${escapeHtml(this.t('save'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-action="results-desk#cancelEditor">${escapeHtml(this.t('cancel'))}</button>
            </div>
            <div class="small mt-1" id="results-desk-preview" data-editor-preview aria-live="polite"></div>
        </form>`;
    }

    editorForm() {
        if (this.editor === null) {
            return null;
        }

        return this.rows.get(this.editor.ref)?.querySelector('form[data-editor]') ?? null;
    }

    /**
     * What the open editor would save: {value} or {error}.
     */
    editorValue() {
        const form = this.editorForm();

        if (form === null) {
            return { error: '' };
        }

        const input = form.querySelector('[data-editor-input]');
        const text = input.value.trim();

        if (form.dataset.editor === 'table') {
            if (text === '') {
                return { value: null, preview: this.t('preview_no_table') };
            }

            if (!/^\d{1,4}$/.test(text) || Number(text) < 1) {
                return { error: this.t('error_table') };
            }

            return { value: Number(text), preview: '' };
        }

        const mode = form.querySelector('[data-editor-mode]').value;
        input.hidden = mode === 'dns' || mode === 'none';
        input.placeholder = mode === 'pieces' ? this.t('placeholder_pieces') : this.t('placeholder_time');

        if (mode === 'dns') {
            return { value: { didNotStart: true }, preview: this.t('preview_dns') };
        }

        if (mode === 'none') {
            return { value: null, preview: this.t('preview_none') };
        }

        if (mode === 'pieces') {
            const max = Number.isInteger(this.round.piecesCount) ? this.round.piecesCount - 1 : null;

            if (!/^\d{1,6}$/.test(text) || Number(text) < 1 || (max !== null && Number(text) > max)) {
                return { error: max !== null ? this.t('error_pieces_max', { max }) : this.t('error_pieces') };
            }

            return { value: { piecesPlaced: Number(text) }, preview: this.formatResult({ piecesPlaced: Number(text) }) };
        }

        const parsed = parseResultTime(text);

        if (parsed.empty) {
            return { error: this.t('error_time_empty') };
        }

        if (parsed.error) {
            return { error: parsed.error === 'out_of_range' ? this.t('error_time_range') : this.t('error_time') };
        }

        return { value: { seconds: parsed.seconds }, preview: this.t('preview_time', { time: formatResultTime(parsed.seconds) }) };
    }

    editorPreview() {
        const form = this.editorForm();

        if (form === null) {
            return;
        }

        const outcome = this.editorValue();
        const preview = form.querySelector('[data-editor-preview]');
        const input = form.querySelector('[data-editor-input]');

        preview.className = `small mt-1 ${outcome.error ? 'text-danger' : 'text-body-secondary'}`;
        preview.textContent = outcome.error ?? outcome.preview ?? '';
        input.setAttribute('aria-invalid', outcome.error ? 'true' : 'false');
    }

    editorKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.cancelEditor();
        }
    }

    saveEditor(event) {
        event.preventDefault();

        if (this.editor === null) {
            return;
        }

        const outcome = this.editorValue();

        if (outcome.error !== undefined) {
            this.editorPreview();
            this.editorForm()?.querySelector('[data-editor-input]')?.focus();

            return;
        }

        const { ref, field } = this.editor;
        const entry = this.entries.get(ref);

        if (entry !== undefined) {
            this.pending.set(ref, field, outcome.value, this.serverValue(entry, field));
        }

        this.closeEditor(true);
        this.flush();
    }

    cancelEditor() {
        this.closeEditor(true);
    }

    closeEditor(refocus) {
        if (this.editor === null) {
            return;
        }

        const { ref, field } = this.editor;
        const row = this.rows.get(ref);

        if (row !== undefined) {
            delete row.dataset.editing;
            row.dataset.html = '';
        }

        this.editor = null;
        this.frozenOrder = null;
        this.render();

        if (refocus) {
            const key = `${field === RESULT ? 'result' : 'table'}:${ref}`;
            [...this.tbodyTarget.querySelectorAll('[data-focus-key]')].find((element) => element.dataset.focusKey === key)?.focus({ preventScroll: true });
        }
    }

    toggleQualified(event) {
        const ref = event.currentTarget.dataset.ref;
        const entry = this.entries.get(ref);

        if (entry === undefined) {
            return;
        }

        this.pending.set(ref, QUALIFIED, event.currentTarget.checked, this.serverValue(entry, QUALIFIED));
        this.render();
        this.flush();
    }

    keepMine(event) {
        this.pending.keepMine(event.currentTarget.dataset.ref, event.currentTarget.dataset.field);
        this.render();
        this.flush();
    }

    takeTheirs(event) {
        this.pending.discard(event.currentTarget.dataset.ref, event.currentTarget.dataset.field);
        this.render();
    }

    keepAllMine() {
        this.pending.list().filter((cell) => cell.status === 'conflict').forEach((cell) => this.pending.keepMine(cell.ref, cell.field));
        this.render();
        this.flush();
    }

    takeAllTheirs() {
        this.pending.list().filter((cell) => cell.status === 'conflict').forEach((cell) => this.pending.discard(cell.ref, cell.field));
        this.render();
    }

    retryCell(event) {
        this.pending.retry(event.currentTarget.dataset.ref, event.currentTarget.dataset.field);
        this.render();
        this.flush();
    }

    discardCell(event) {
        this.pending.discard(event.currentTarget.dataset.ref, event.currentTarget.dataset.field);
        this.render();
    }

    filterChanged() {
        this.renderTable();
    }

    /**
     * The entry the desk shows at a table number, other than `exceptRef`.
     */
    tableHolder(number, exceptRef) {
        if (!Number.isInteger(number)) {
            return null;
        }

        for (const entry of this.entries.values()) {
            if (entry.ref !== exceptRef && this.shown(entry, TABLE) === number) {
                return entry;
            }
        }

        return null;
    }

    /**
     * "Swap them": the refused table number goes to this entry and the holder gets this entry's number - both changes
     * in one request, checked by the server after the whole set (review2-b m6).
     */
    swapTables(event) {
        const ref = event.currentTarget.dataset.ref;
        const holder = this.entries.get(event.currentTarget.dataset.holder);
        const entry = this.entries.get(ref);
        const cell = this.pending.get(ref, TABLE);

        if (!holder || !entry || cell === null || cell.status !== 'error') {
            return;
        }

        this.pending.set(holder.ref, TABLE, cell.base ?? null, this.serverValue(holder, TABLE));
        this.pending.retry(ref, TABLE);
        this.render();
        this.flush();
    }

    async takeOut(event) {
        const ref = event.currentTarget.dataset.ref;
        const entry = this.entries.get(ref);

        if (entry === undefined || !window.confirm(this.t('take_out_confirm', { name: entry.displayName ?? '' }))) {
            return;
        }

        const answer = await officialResultsRequest(this.urlsValue.takeOut, {
            method: 'POST',
            body: { entry: ref },
            csrfToken: this.csrfTokenValue,
        });

        if (answer.kind === 'ok') {
            this.entries.delete(ref);
            [RESULT, TABLE, QUALIFIED].forEach((field) => this.pending.discard(ref, field));
            this.generation++;
            this.updateRound(answer.data.round);
            this.render();
            this.toast(this.t('take_out_done', { name: entry.displayName ?? '' }), 'success');

            return;
        }

        if (answer.kind === 'auth') {
            this.transport = 'auth';
            this.renderSync();
        } else if (isGone(answer)) {
            this.goneAway();
        }

        this.toast(answer.data?.message ?? this.t(answer.kind === 'offline' ? 'publish_offline' : 'error_request'), 'error');
        this.resync();
    }

    // ---------------------------------------------------------------- saving

    async flush() {
        if (this.flushing || this.transport === 'auth' || this.transport === 'forbidden' || this.transport === 'gone') {
            this.renderSync();

            return;
        }

        const changes = this.pending.take(newClientId, MAX_CHANGES_PER_REQUEST);

        if (changes.length === 0) {
            this.render();

            return;
        }

        clearTimeout(this.retryTimer);
        this.flushing = true;
        this.render();

        const answer = await officialResultsRequest(this.urlsValue.record, {
            method: 'POST',
            body: { changes },
            csrfToken: this.csrfTokenValue,
        });

        this.flushing = false;

        if (answer.kind === 'ok') {
            this.transport = 'ok';
            this.retryIndex = 0;

            const now = Date.now();
            for (const outcome of answer.data.outcomes ?? []) {
                this.pending.settle(outcome);

                if (outcome.entry) {
                    this.ownWrites.set(outcome.entry, now);
                }
            }

            // Anything the answer did not mention was not saved - shown as such, never sent in a loop
            this.pending.failInFlight(this.t('error_request'));
            this.mergeEntries(answer.data.entries ?? [], false);
        } else if (answer.kind === 'auth') {
            this.pending.retryInFlight();
            this.transport = 'auth';
        } else if (answer.kind === 'forbidden') {
            this.pending.retryInFlight();
            this.transport = 'forbidden';
        } else if (isGone(answer)) {
            this.pending.failInFlight(answer.data?.message ?? this.t('error_request'));
            this.goneAway();
        } else if (answer.kind === 'client') {
            this.pending.failInFlight(answer.data?.message ?? this.t('error_request'));
        } else {
            this.pending.retryInFlight();
            this.transport = answer.kind;
            this.scheduleRetry();
        }

        this.render();

        if (this.transport === 'ok' && this.pending.hasQueued()) {
            this.flush();
        }
    }

    scheduleRetry() {
        clearTimeout(this.retryTimer);
        const seconds = RETRY_SECONDS[Math.min(this.retryIndex, RETRY_SECONDS.length - 1)];
        this.retryIndex++;
        this.retryTimer = setTimeout(() => this.flush(), seconds * 1000);
    }

    retryNow() {
        if (this.transport === 'gone') {
            return;
        }

        this.transport = 'ok';
        this.retryIndex = 0;
        this.flush();
        this.resync();
    }

    /**
     * The round's state again - one request at a time (a call meanwhile gets that one). Resolves to the answer's kind.
     */
    resync() {
        // The round is gone - nothing to ask for any more
        if (this.transport === 'gone') {
            return Promise.resolve('client');
        }

        if (this.resyncing === null) {
            this.resyncing = this.fetchState().finally(() => {
                this.resyncing = null;
            });
        }

        return this.resyncing;
    }

    async fetchState() {
        let answer;

        for (let attempt = 0; ; attempt++) {
            const generation = this.generation;
            answer = await officialResultsRequest(this.urlsValue.state);

            // Newer entries arrived while the state was on its way - it may be older than them: ask again
            if (answer.kind !== 'ok' || this.generation === generation || attempt >= 3) {
                break;
            }
        }

        if (!this.element.isConnected) {
            return answer.kind;
        }

        if (answer.kind === 'ok') {
            this.replaceEntries(answer.data.entries ?? []);
            this.updateRound(answer.data.round);
            // A fresh token: the stream is renewed with it in its last minutes, or opened again if it is down
            this.events.update(answer.data.mercure ?? null);

            if (this.transport === 'offline' || this.transport === 'server') {
                // The server answers again - send what waits
                this.transport = 'ok';
                this.flush();
            }

            this.render();
        } else if (answer.kind === 'auth' || answer.kind === 'forbidden') {
            // A token is only for whoever may still edit the event: no stream until a state answers again
            this.events.suspend();

            if (answer.kind === 'auth') {
                this.transport = 'auth';
                this.renderSync();
            }
        } else if (isGone(answer)) {
            this.goneAway();
        }

        return answer.kind;
    }

    /**
     * The round was deleted (or never existed): nothing of it can be read or saved any more - the page says so and
     * stops asking (no stream, no minute refresh, no retries).
     */
    goneAway() {
        if (this.transport === 'gone') {
            return;
        }

        this.transport = 'gone';
        this.events.close();
        clearInterval(this.resyncInterval);
        clearTimeout(this.retryTimer);
        clearTimeout(this.resyncTimer);
        this.renderSync();
    }

    // ---------------------------------------------------------------- events

    onMercure(event) {
        const detail = event.detail;

        if (!detail || detail.roundId !== this.round.id || typeof detail.type !== 'string') {
            return;
        }

        if (detail.type === 'official_results.entries') {
            this.mergeEntries(detail.entries ?? [], true);
            this.updateRound(detail.round);
            this.render();
        } else if (detail.type === 'official_results.refresh') {
            this.resync();
        } else if (detail.type === 'official_results.round') {
            this.updateRound(detail.round);
            this.render();
        }
    }

    onVisibility() {
        if (document.visibilityState === 'hidden') {
            this.hiddenSince = Date.now();

            return;
        }

        if (this.hiddenSince !== null && Date.now() - this.hiddenSince > 10000) {
            this.resync();
        }

        this.hiddenSince = null;
    }

    onOnline() {
        if (this.transport === 'offline' || this.transport === 'server') {
            this.transport = 'ok';
        }

        this.retryIndex = 0;
        this.flush();
        this.resync();
    }

    /**
     * The advance dialog asks before planning: qualified marks of this page that are not saved yet are not in the plan.
     */
    onUnsavedMarks(event) {
        if (event.detail && typeof event.detail.count === 'number') {
            event.detail.count += this.pending.list().filter((cell) => cell.field === QUALIFIED).length;
        }
    }

    onBeforeUnload(event) {
        if (!this.pending.isEmpty()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    onBeforeVisit(event) {
        if (!this.pending.isEmpty() && !window.confirm(this.t('leave_unsaved'))) {
            event.preventDefault();
        }
    }

    switchRound(event) {
        const url = event.currentTarget.value;

        if (url) {
            window.location.assign(url);
        }
    }

    // ---------------------------------------------------------------- qualification helpers

    openHelper(event) {
        const mode = event.currentTarget.dataset.helper;
        this.helper = { mode, count: mode === 'top' ? Math.max(1, this.rankedEntries().filter((entry) => entry.qualified).length || 10) : 1, keepOthers: mode === 'country', include: new Map() };

        this.helperBodyTarget.querySelectorAll('[data-helper-section]').forEach((section) => {
            section.hidden = section.dataset.helperSection !== mode;
        });

        const countInput = this.helperBodyTarget.querySelector(`[data-helper-section="${mode}"] [data-helper-count]`);
        if (countInput) {
            countInput.value = String(this.helper.count);
        }

        const keep = this.helperBodyTarget.querySelector('[data-helper-keep]');
        if (keep) {
            keep.checked = this.helper.keepOthers;
            keep.closest('[data-helper-keep-row]').hidden = mode === 'clear';
        }

        this.helperModalTarget.querySelector('[data-helper-title]').textContent = this.t(`helper_title_${mode}`);
        this.renderHelper();
        this.modal(this.helperModalTarget).show();
    }

    helperChanged(event) {
        if (this.helper === null) {
            return;
        }

        const target = event.target;

        if (target.matches('[data-helper-count]')) {
            this.helper.count = Math.max(0, Math.floor(Number(target.value) || 0));
            this.helper.include = new Map();
        } else if (target.matches('[data-helper-keep]')) {
            this.helper.keepOthers = target.checked;
        } else if (target.matches('[data-helper-include]')) {
            // Only the summary changes - the list being ticked stays as it is (and keeps the focus)
            this.helper.include.set(target.dataset.ref, target.checked);
            this.renderHelper(false);

            return;
        }

        this.renderHelper();
    }

    /**
     * The helper's proposal for the current inputs: {selected: Set, decide: [{ref, why, proposed}], countries}.
     */
    helperProposal() {
        const ranked = this.rankedEntries();
        const helper = this.helper;

        if (helper.mode === 'clear') {
            return { selected: new Set(), decide: [], countries: [] };
        }

        if (helper.mode === 'top') {
            const proposal = topN(ranked, helper.count);
            const tied = new Set(proposal.tiedAtCut);

            return {
                selected: new Set(proposal.selected.filter((ref) => !tied.has(ref) || helper.include.get(ref) !== false)),
                decide: proposal.tiedAtCut.map((ref) => ({ ref, why: 'tie', proposed: helper.include.get(ref) !== false })),
                countries: [],
            };
        }

        const proposal = bestOfEachCountry(ranked, helper.count);
        const tied = new Set(proposal.tiedAtCut);
        const selected = new Set(proposal.selected.filter((ref) => !tied.has(ref) || helper.include.get(ref) !== false));

        for (const ref of proposal.withoutCountry) {
            if (helper.include.get(ref) === true) {
                selected.add(ref);
            }
        }

        return {
            selected,
            decide: [
                ...proposal.tiedAtCut.map((ref) => ({ ref, why: 'tie', proposed: helper.include.get(ref) !== false })),
                ...proposal.withoutCountry.map((ref) => ({ ref, why: 'no_country', proposed: helper.include.get(ref) === true })),
            ],
            countries: proposal.countries,
        };
    }

    renderHelper(withLists = true) {
        const ranked = this.rankedEntries();
        const byRef = new Map(ranked.map((entry) => [entry.ref, entry]));
        const proposal = this.helperProposal();
        const diff = qualificationDiff(ranked, proposal.selected, this.helper.mode !== 'clear' && this.helper.keepOthers);
        this.helper.diff = diff;

        const label = (ref) => {
            const entry = byRef.get(ref);

            return entry ? `${entry.rank ?? '–'}. ${escapeHtml(entry.displayName)}${entry.result ? ` <span class="text-body-secondary">${escapeHtml(this.formatResult(entry.result))}</span>` : ''}` : '';
        };

        const decideTarget = this.helperBodyTarget.querySelector('[data-helper-decide]');
        if (decideTarget && withLists) {
            const ties = proposal.decide.filter((item) => item.why === 'tie');
            const noCountry = proposal.decide.filter((item) => item.why === 'no_country');
            const list = (items) => items.map((item) => `<div class="form-check">
                <input class="form-check-input" type="checkbox" id="helper-${escapeHtml(item.ref)}" data-helper-include data-ref="${escapeHtml(item.ref)}" ${item.proposed ? 'checked' : ''}>
                <label class="form-check-label" for="helper-${escapeHtml(item.ref)}">${label(item.ref)}</label>
            </div>`).join('');

            decideTarget.innerHTML = [
                ties.length > 0 ? `<div class="alert alert-warning py-2 mb-2"><div class="fw-semibold mb-1">${escapeHtml(this.tc('helper_ties', ties.length))}</div>${list(ties)}</div>` : '',
                noCountry.length > 0 ? `<div class="alert alert-secondary py-2 mb-2"><div class="fw-semibold mb-1">${escapeHtml(this.tc('helper_no_country', noCountry.length))}</div>${list(noCountry)}</div>` : '',
            ].join('');
        }

        const countriesTarget = this.helperBodyTarget.querySelector('[data-helper-countries]');
        if (countriesTarget && withLists) {
            countriesTarget.hidden = this.helper.mode !== 'country' || proposal.countries.length === 0;
            countriesTarget.innerHTML = proposal.countries.map((country) => `<li class="mb-1">${this.flagsHtml([country.country])} <span class="fw-semibold">${escapeHtml(this.countriesValue[country.country] ?? country.country.toUpperCase())}</span>: ${country.selected.map((ref) => escapeHtml(byRef.get(ref)?.displayName ?? '')).join(', ')}</li>`).join('');
        }

        const listOf = (refs) => refs.map((ref) => `<li>${label(ref)}</li>`).join('');
        const qualifiedNow = ranked.filter((entry) => entry.qualified === true).length;
        const total = qualifiedNow + diff.mark.length - diff.unmark.length;
        this.helperSummaryTarget.innerHTML = `<p class="fw-semibold mb-2">${escapeHtml(this.t('helper_diff', { mark: diff.mark.length, unmark: diff.unmark.length, total }))}</p>
            ${diff.mark.length > 0 ? `<details><summary>${escapeHtml(this.tc('helper_will_mark', diff.mark.length))}</summary><ul class="small mb-2">${listOf(diff.mark)}</ul></details>` : ''}
            ${diff.unmark.length > 0 ? `<details><summary>${escapeHtml(this.tc('helper_will_unmark', diff.unmark.length))}</summary><ul class="small mb-2">${listOf(diff.unmark)}</ul></details>` : ''}`;

        this.helperApplyTarget.disabled = diff.mark.length + diff.unmark.length === 0;
    }

    applyHelper() {
        if (this.helper?.diff === undefined) {
            return;
        }

        const { mark, unmark } = this.helper.diff;

        for (const [refs, to] of [[mark, true], [unmark, false]]) {
            for (const ref of refs) {
                const entry = this.entries.get(ref);

                if (entry !== undefined) {
                    this.pending.set(ref, QUALIFIED, to, this.serverValue(entry, QUALIFIED));
                }
            }
        }

        this.modal(this.helperModalTarget).hide();
        this.helper = null;
        this.render();
        this.flush();
        this.toast(this.t('helper_applied', { mark: mark.length, unmark: unmark.length }), 'info');
    }

    // ---------------------------------------------------------------- publishing

    openPublish(event) {
        this.publishAction = event.currentTarget.dataset.publishAction === 'unpublish' ? 'unpublish' : 'publish';
        const ranked = this.rankedEntries();
        const withoutResult = ranked.filter((entry) => entry.result === null).length;
        const unsaved = this.pending.counts().total;
        const lines = [];

        if (this.publishAction === 'publish') {
            lines.push(`<p>${escapeHtml(this.t('publish_intro'))}</p>`);

            if (this.round.category !== 'solo') {
                lines.push(`<p class="mb-2"><i class="bi bi-people me-1" aria-hidden="true"></i>${escapeHtml(this.t('publish_team_names'))}</p>`);
            }

            lines.push(`<p class="mb-2"><i class="bi bi-bell me-1" aria-hidden="true"></i>${escapeHtml(this.round.resultsFirstPublishedAt ? this.t('publish_no_new_notification') : this.t('publish_notification'))}</p>`);

            if (this.competition.isPubliclyVisible === false) {
                lines.push(`<div class="alert alert-info py-2 mb-2"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>${escapeHtml(this.t('publish_not_public'))}</div>`);
            }

            if (withoutResult > 0) {
                lines.push(`<div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>${escapeHtml(this.tc('publish_without_result', withoutResult))}</div>`);
            }
        } else {
            lines.push(`<p>${escapeHtml(this.t('unpublish_intro'))}</p>`);
        }

        if (unsaved > 0) {
            lines.push(`<div class="alert alert-danger py-2 mb-0"><i class="bi bi-cloud-slash me-1" aria-hidden="true"></i>${escapeHtml(this.tc('publish_unsaved', unsaved))}</div>`);
        }

        this.publishModalTitleTarget.textContent = this.t(this.publishAction === 'publish' ? 'publish_title' : 'unpublish_title');
        this.publishModalBodyTarget.innerHTML = lines.join('');
        this.publishConfirmTarget.textContent = this.t(this.publishAction === 'publish' ? 'publish_confirm' : 'unpublish_confirm');
        this.publishConfirmTarget.className = `btn ${this.publishAction === 'publish' ? 'btn-primary' : 'btn-outline-danger'}`;
        this.publishConfirmTarget.disabled = false;
        this.modal(this.publishModalTarget).show();
    }

    async confirmPublish() {
        const action = this.publishAction;

        if (action === null) {
            return;
        }

        this.publishConfirmTarget.disabled = true;
        const answer = await officialResultsRequest(action === 'publish' ? this.urlsValue.publish : this.urlsValue.unpublish, {
            method: 'POST',
            body: {},
            csrfToken: this.csrfTokenValue,
        });
        this.publishConfirmTarget.disabled = false;

        if (answer.kind === 'ok') {
            this.updateRound(answer.data.round);
            this.modal(this.publishModalTarget).hide();
            this.publishAction = null;
            this.render();
            this.toast(this.t(action === 'publish' ? 'published_toast' : 'unpublished_toast'), 'success');

            return;
        }

        if (answer.kind === 'auth') {
            this.transport = 'auth';
            this.renderSync();
        } else if (isGone(answer)) {
            this.modal(this.publishModalTarget).hide();
            this.publishAction = null;
            this.goneAway();
            this.toast(answer.data.message, 'error');

            return;
        }

        this.toast(this.t(answer.kind === 'auth' ? 'pill_sign_in' : (answer.kind === 'offline' ? 'publish_offline' : 'publish_failed')), 'error');
    }

    // ---------------------------------------------------------------- helpers

    modal(element) {
        return element ? Modal.getOrCreateInstance(element) : null;
    }

    toast(message, type) {
        document.dispatchEvent(new CustomEvent('toast:show', { detail: { message, type, duration: 4000 } }));
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function normalise(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}
