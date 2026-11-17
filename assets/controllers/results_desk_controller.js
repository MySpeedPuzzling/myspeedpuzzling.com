/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { officialResultsRequest, newClientId, isGone } from '../official_results_api.js';
import { OfficialResultsEvents } from '../official_results_events.js';
import { formatResultTime, parseResultTime } from '../official_results_time.js';
import { rankEntries } from '../official_results_ranking.js';
import { bestOfEachCountry, overallForRound, qualificationDiff, topN } from '../official_results_qualification.js';
import {
    PendingChanges,
    sameValue,
    openEditor as editorOpened,
    savedMeanwhile,
    keepMineInEditor,
    saveEditor as saveEditorValue,
} from '../official_results_pending_changes.js';
import { chooseTranslation } from '../translation_choice.js';
import { editorOutcome, editorRows, pickFormat, puzzleOptions, recordChips, recordText } from '../official_results_desk_puzzles.js';
import { convertTyped, MISSING, PLACED, typedPieces } from '../official_results_missing.js';
import { FIELD as MISSING_PIECES, missingOf, missingPiecesChange, parseMissingPieces, withMissing } from '../official_results_finished_missing.js';

const RESULT = 'result';
const TABLE = 'table_number';
const QUALIFIED = 'qualified';
// A `pick` round's per-puzzle record (competition toolkit A4) - saved with the result as one group (A rule 7)
const PUZZLES = 'puzzles';
const RETRY_SECONDS = [2, 5, 10, 20, 30];
const RESYNC_EVERY_MS = 60000;
const OWN_WRITE_WINDOW_MS = 5000;
const MAX_CHANGES_PER_REQUEST = 500;

/**
 * The results desk of one round (docs/features/competitions-management/results-desk.md): the ranked table with
 * inline edits of results, table numbers and qualified marks, the qualification helpers and publishing.
 *
 * Every write is a change set to `official_results_record` with the value the organiser saw when they started changing
 * the field as `from` (captured when an editor opens - a live update arriving while it is open is shown next to it,
 * never taken as the new `from`), so a value somebody else saved meanwhile comes back as a conflict (keep mine / take
 * theirs) instead of being overwritten. Unsaved changes live in PendingChanges and stay visibly unsaved until the server answered for
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
        'publishModalTitle', 'publishModalBody', 'publishConfirm', 'revealMissed',
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
        // "Also reveal the hidden puzzles now" of the publish dialog (competition toolkit G3): the list's hash, once loaded
        this.revealOffer = null;
        this.revealOfferRequest = 0;
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
                merged = { ...incoming, result: current.result, enteredAt: current.enteredAt, enteredBy: current.enteredBy, ...('puzzles' in current ? { puzzles: current.puzzles } : {}) };
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
        return 'puzzles' in entry ? [entry.result, entry.tableNumber, entry.qualified, entry.puzzles] : [entry.result, entry.tableNumber, entry.qualified];
    }

    /** The round's `puzzleFormat` when it is a `pick` round (each entrant solves K of its N puzzles), else null. */
    pickFormat() {
        return pickFormat(this.round);
    }

    updateRound(overview) {
        if (overview && overview.id === this.round.id) {
            // The JSON endpoints answer the overview only - keep what the page knows on top (colours, public URL)
            this.round = { ...this.round, ...overview };

            // A key the overview carries only while it applies (competition toolkit G3) - gone = not any more
            if (!('stopwatchRevealMissed' in overview)) {
                delete this.round.stopwatchRevealMissed;
            }
        }
    }

    serverValue(entry, field) {
        if (field === RESULT) {
            return entry.result ?? null;
        }

        if (field === TABLE) {
            return entry.tableNumber ?? null;
        }

        if (field === PUZZLES) {
            return entry.puzzles ?? null;
        }

        if (field === MISSING_PIECES) {
            return entry.missingPieces ?? null;
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
        this.renderEditorMeanwhile();
        this.renderCounts();
        this.renderSync();
        this.renderPublication();
        this.renderRevealMissed();
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
                return [RESULT, TABLE, QUALIFIED, PUZZLES].some((field) => this.pending.get(entry.ref, field) !== null);
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
            <button type="button" class="btn btn-sm btn-link text-reset text-decoration-none text-nowrap results-desk-cell results-desk-result" data-action="results-desk#editResult" data-ref="${ref}" data-focus-key="result:${ref}" aria-label="${escapeHtml(this.t('edit_result_label', { name }))}">${entry.result === null ? `<span class="text-body-secondary">${escapeHtml(this.t('add_result'))}</span>` : escapeHtml(this.formatResult(entry.result, this.shown(entry, MISSING_PIECES)))}</button>
            ${this.recordHtml(entry)}
            ${this.cellState(entry, RESULT)}
            ${this.pickFormat() !== null ? this.cellState(entry, PUZZLES) : ''}
            ${this.pending.get(entry.ref, MISSING_PIECES) !== null ? this.cellState(entry, MISSING_PIECES) : ''}
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

        if (!server || server.result !== null || server.qualified === true || [RESULT, QUALIFIED, PUZZLES].some((field) => this.pending.get(entry.ref, field) !== null)) {
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

        // The group's other cell already says what happened (both were refused together) - one line, not two
        if (cell.status === 'error' && cell.reason === 'group_refused') {
            const partner = this.pending.get(entry.ref, field === PUZZLES ? RESULT : PUZZLES);

            if (partner !== null && (partner.status === 'error' || partner.status === 'conflict') && partner.reason !== 'group_refused') {
                return '';
            }
        }

        // Both of a group in conflict: one line - the result's, naming the record saved meanwhile too
        if (field === PUZZLES && cell.status === 'conflict' && this.pending.get(entry.ref, RESULT)?.status === 'conflict') {
            return '';
        }

        // One spinner per result cell - the record travels with the result
        if (field === PUZZLES && this.pending.get(entry.ref, RESULT) !== null && (cell.status === 'sending' || cell.status === 'queued')) {
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
        let theirs = this.formatField(field, cell.conflict?.current ?? null);
        const record = field === RESULT ? this.pending.get(entry.ref, PUZZLES) : null;

        if (record !== null && record.status === 'conflict') {
            theirs = `${theirs} (${this.formatField(PUZZLES, record.conflict?.current ?? null)})`;
        }

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

        if (field === PUZZLES) {
            const text = recordText(this.pickFormat(), value, { pieces: this.t('pieces_placed') });

            return text === '' ? this.t('puzzles_no_record') : text;
        }

        if (field === MISSING_PIECES) {
            return missingOf(value) === null ? this.t('missing_none') : this.tc('missing_pieces', value);
        }

        return value ? this.t('qualified_yes') : this.t('qualified_no');
    }

    formatResult(result, missing = null) {
        if (result === null || result === undefined) {
            return '';
        }

        if (Number.isInteger(result.seconds)) {
            // Finished with pieces missing (competition toolkit T6): "1:23:45 · 1 piece missing"
            return withMissing(formatResultTime(result.seconds), result, missing, (count) => this.tc('missing_pieces', count));
        }

        if (Number.isInteger(result.piecesPlaced)) {
            return Number.isInteger(this.round.piecesCount)
                ? this.t('pieces_placed_of', { placed: result.piecesPlaced, pieces: this.round.piecesCount })
                : this.t('pieces_placed', { placed: result.piecesPlaced });
        }

        return result.didNotStart ? this.t('did_not_start') : '';
    }

    /**
     * A `pick` round row's record under its result: the chosen puzzles in solving order - label (the name only of a
     * puzzle that is out), ✓ + the time it was finished at, or the pieces of a puzzle not finished. Unsaved values show.
     */
    recordHtml(entry) {
        const format = this.pickFormat();

        if (format === null) {
            return '';
        }

        const chips = recordChips(format, this.shown(entry, PUZZLES));

        if (chips.length === 0) {
            return '';
        }

        const html = chips.map((chip) => {
            const name = chip.name !== null ? ` <span class="text-body-secondary">${escapeHtml(chip.name)}</span>` : '';
            let state = '';

            if (chip.done) {
                state = ` <i class="bi bi-check-lg text-success" aria-hidden="true"></i><span class="visually-hidden">${escapeHtml(this.t('puzzles_status_done'))}</span>${chip.atSeconds !== null ? ` ${escapeHtml(formatResultTime(chip.atSeconds))}` : ''}`;
            } else if (chip.piecesPlaced !== null) {
                state = ` ${escapeHtml(this.t('pieces_placed', { placed: chip.piecesPlaced }))}`;
            }

            return `<span class="badge rounded-pill text-bg-light border fw-normal text-wrap text-start" data-record-chip>${escapeHtml(chip.label)}${name}${state}</span>`;
        });

        return `<div class="d-flex flex-wrap gap-1 mt-1 small" data-record aria-label="${escapeHtml(this.t('puzzles_record_label'))}">${html.join('')}</div>`;
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

    /**
     * "Still hidden: the stopwatch was never started with Reveal" (competition toolkit G3) - only on a desk that has the
     * notice (a round using the stopwatch reveal)
     */
    renderRevealMissed() {
        if (!this.hasRevealMissedTarget) {
            return;
        }

        const missed = this.revealMissedCount();
        this.revealMissedTarget.hidden = missed === 0;
        const text = this.revealMissedTarget.querySelector('[data-reveal-missed-text]');

        if (text && missed > 0) {
            text.textContent = this.tc('reveal_missed_text', missed);
        }
    }

    revealMissedCount() {
        const missed = this.round.stopwatchRevealMissed;

        return Number.isInteger(missed) && missed > 0 ? missed : 0;
    }

    // ---------------------------------------------------------------- editing

    editResult(event) {
        this.openEditor(event.currentTarget.dataset.ref, RESULT);
    }

    editTable(event) {
        this.openEditor(event.currentTarget.dataset.ref, TABLE);
    }

    openEditor(ref, field, { totalOnly = false } = {}) {
        const entry = this.entries.get(ref);

        if (entry === undefined) {
            return;
        }

        if (this.editor !== null) {
            this.closeEditor(false);
        }

        this.frozenOrder = this.visibleEntries().map((visible) => visible.ref);
        // What the organiser sees now is the `from` of the save - never the server's value at save time
        this.editor = editorOpened(this.pending, ref, field, this.serverValue(entry, field));

        // Finished with pieces missing (toolkit T6): the count the editor shows is its `from` too (reliability F2)
        if (field === RESULT) {
            this.editor.missing = editorOpened(this.pending, ref, MISSING_PIECES, this.serverValue(entry, MISSING_PIECES));
        }

        // A `pick` round's result cell opens the per-puzzle editor: the result and the record are saved as one group,
        // both with what the organiser saw when it opened. The plain total editor only for an entry without a record.
        if (field === RESULT && this.pickFormat() !== null) {
            this.editor.puzzles = editorOpened(this.pending, ref, PUZZLES, this.serverValue(entry, PUZZLES));
            this.editor.pick = !(totalOnly && this.editor.puzzles.seen === null);
        }

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

        cell.innerHTML = field === RESULT ? (this.editor.pick ? this.puzzlesEditorHtml(entry) : this.resultEditorHtml(entry)) : this.tableEditorHtml(entry);
        this.editorPreview();
        this.renderEditorMeanwhile();
        cell.querySelector('[data-editor-input]:not([hidden])')?.focus();
        cell.querySelector('[data-editor-input]:not([hidden])')?.select?.();
    }

    resultEditorHtml(entry) {
        const shown = this.shown(entry, RESULT);
        const mode = shown === null ? 'time' : (Number.isInteger(shown.seconds) ? 'time' : (Number.isInteger(shown.piecesPlaced) ? 'pieces' : (shown.didNotStart ? 'dns' : 'time')));
        const value = shown === null ? '' : (Number.isInteger(shown.seconds) ? formatResultTime(shown.seconds) : (Number.isInteger(shown.piecesPlaced) ? String(shown.piecesPlaced) : ''));
        const missing = missingOf(this.shown(entry, MISSING_PIECES));
        const option = (key, label) => `<option value="${key}" ${key === mode ? 'selected' : ''}>${escapeHtml(label)}</option>`;

        return `<form class="results-desk-editor" data-editor="result" novalidate data-action="submit->results-desk#saveEditor keydown->results-desk#editorKeydown">
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <select class="form-select form-select-sm w-auto" data-editor-mode aria-label="${escapeHtml(this.t('editor_kind'))}" data-action="change->results-desk#editorPreview">
                    ${option('time', this.t('mode_time'))}
                    ${option('pieces', this.t('mode_pieces'))}
                    ${Number.isInteger(this.round.piecesCount) && this.round.piecesCount > 1 ? option('missing', this.t('mode_missing')) : ''}
                    ${option('dns', this.t('mode_dns'))}
                    ${option('none', this.t('mode_none'))}
                </select>
                <input type="text" class="form-control form-control-sm results-desk-input" data-editor-input inputmode="numeric" autocomplete="off" value="${escapeHtml(value)}" aria-label="${escapeHtml(this.t('editor_value'))}" aria-describedby="results-desk-preview" data-action="input->results-desk#editorPreview">
                <button type="submit" class="btn btn-sm btn-primary" data-editor-save>${escapeHtml(this.t('save'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-action="results-desk#cancelEditor">${escapeHtml(this.t('cancel'))}</button>
            </div>
            <label class="d-flex align-items-center gap-1 small mt-1" data-editor-missing-row ${mode === 'time' ? '' : 'hidden'}>
                <span class="text-body-secondary">${escapeHtml(this.t('missing_label'))}</span>
                <input type="text" class="form-control form-control-sm" style="max-width: 5rem;" data-editor-missing inputmode="numeric" autocomplete="off" value="${missing !== null ? missing : ''}" data-action="input->results-desk#editorPreview">
                ${this.round.penalisedMissing === true ? `<span class="text-body-secondary" data-editor-missing-help>${escapeHtml(this.t('missing_penalty_help'))}</span>` : ''}
            </label>
            <div class="small mt-1" id="results-desk-preview" data-editor-preview aria-live="polite"></div>
            <div class="small text-danger mt-1" data-editor-meanwhile role="alert" hidden></div>
        </form>`;
    }

    /**
     * The per-puzzle editor of a `pick` round entry: the outcome (finished / didn't finish / did not start / no result),
     * then K rows in solving order - the puzzle by its label (the name only of a puzzle that is out), finished or not,
     * the time it was finished at (the last one's = the result's time) or its pieces placed. The total is computed by
     * official_results_puzzles.js.
     */
    puzzlesEditorHtml(entry) {
        const format = this.pickFormat();
        const shown = this.shown(entry, RESULT);
        const record = this.shown(entry, PUZZLES);
        const mode = shown === null ? (record === null ? 'time' : 'none') : (Number.isInteger(shown.seconds) ? 'time' : (Number.isInteger(shown.piecesPlaced) ? 'pieces' : (shown.didNotStart ? 'dns' : 'time')));
        const option = (key, label) => `<option value="${key}" ${key === mode ? 'selected' : ''}>${escapeHtml(label)}</option>`;
        const puzzles = puzzleOptions(format);
        const rows = editorRows(format, record).map((row, index) => {
            const choices = puzzles.map((puzzle) => `<option value="${escapeHtml(puzzle.id)}" ${puzzle.id === row.id ? 'selected' : ''}>${escapeHtml(puzzle.name !== null ? `${puzzle.label} - ${puzzle.name}` : puzzle.label)}</option>`).join('');
            const position = index + 1;

            return `<li class="d-flex flex-wrap gap-1 align-items-center mb-1" data-puzzle-row>
                <span class="small text-body-secondary results-desk-puzzle-position">${position}.</span>
                <select class="form-select form-select-sm w-auto" data-row-puzzle ${index === 0 ? 'data-editor-input' : ''} aria-label="${escapeHtml(this.t('puzzles_row', { position }))}" data-action="change->results-desk#editorPreview">
                    <option value="">${escapeHtml(this.t('puzzles_choose'))}</option>${choices}
                </select>
                <select class="form-select form-select-sm w-auto" data-row-status aria-label="${escapeHtml(this.t('puzzles_row_status', { position }))}" data-action="change->results-desk#editorPreview">
                    <option value="done" ${row.done ? 'selected' : ''}>${escapeHtml(this.t('puzzles_status_done'))}</option>
                    <option value="open" ${row.done ? '' : 'selected'}>${escapeHtml(this.t('puzzles_status_open'))}</option>
                </select>
                <input type="text" class="form-control form-control-sm results-desk-input" data-row-at inputmode="numeric" autocomplete="off" value="${row.at !== null ? escapeHtml(formatResultTime(row.at)) : ''}" placeholder="${escapeHtml(this.t('puzzles_placeholder_at'))}" aria-label="${escapeHtml(this.t('puzzles_row_at', { position }))}" data-action="input->results-desk#editorPreview">
                <input type="text" class="form-control form-control-sm results-desk-input" data-row-pieces inputmode="numeric" autocomplete="off" value="${row.pieces !== null ? escapeHtml(String(row.pieces)) : ''}" placeholder="${escapeHtml(this.t('placeholder_pieces'))}" aria-label="${escapeHtml(this.t('puzzles_row_pieces', { position }))}" data-action="input->results-desk#editorPreview">
            </li>`;
        }).join('');
        const clear = record !== null ? `<button type="button" class="btn btn-sm btn-outline-danger" data-action="results-desk#clearRecord">${escapeHtml(this.t('puzzles_clear'))}</button>` : '';
        const totalOnly = record === null ? `<button type="button" class="btn btn-sm btn-link" data-action="results-desk#totalOnly">${escapeHtml(this.t('puzzles_total_only'))}</button>` : '';

        return `<form class="results-desk-editor results-desk-puzzles-editor" data-editor="puzzles" novalidate data-action="submit->results-desk#saveEditor keydown->results-desk#editorKeydown">
            <div class="d-flex flex-wrap gap-1 align-items-center mb-1">
                <select class="form-select form-select-sm w-auto" data-editor-mode aria-label="${escapeHtml(this.t('editor_kind'))}" data-action="change->results-desk#editorPreview">
                    ${option('time', this.t('mode_time'))}
                    ${option('pieces', this.t('mode_pieces'))}
                    ${option('missing', this.t('mode_missing'))}
                    ${option('dns', this.t('mode_dns'))}
                    ${option('none', this.t('mode_none'))}
                </select>
                <span class="small text-body-secondary">${escapeHtml(this.t(format.toSolve >= format.puzzles.length ? 'puzzles_format_all' : 'puzzles_format', { count: format.toSolve, total: format.puzzles.length }))}</span>
            </div>
            <ol class="list-unstyled mb-1" data-puzzle-rows>${rows}</ol>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <button type="submit" class="btn btn-sm btn-primary" data-editor-save>${escapeHtml(this.t('save'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-action="results-desk#cancelEditor">${escapeHtml(this.t('cancel'))}</button>
                ${clear}${totalOnly}
            </div>
            <div class="small mt-1" id="results-desk-preview" data-editor-preview aria-live="polite"></div>
            <div class="small text-danger mt-1" data-editor-meanwhile role="alert" hidden></div>
        </form>`;
    }

    /**
     * The per-puzzle editor's rows as typed - {rows} or {error} (a split or pieces that cannot be read). Shows the inputs
     * the outcome uses: status not for a finished result (all finished), the split of a finished puzzle, the pieces of
     * one not finished (didn't finish only), no rows for did not start.
     */
    puzzlesEditorRows(form, mode) {
        const rows = [];
        let error = null;
        const rowsTarget = form.querySelector('[data-puzzle-rows]');
        rowsTarget.hidden = mode === 'dns';
        const all = [...form.querySelectorAll('[data-puzzle-row]')];
        const lastChosen = all.map((row) => row.querySelector('[data-row-puzzle]').value !== '').lastIndexOf(true);
        // Didn't finish counted as missing pieces (reliability hardening T1): per puzzle, placed = its pieces − missing
        const missing = mode === 'missing';
        const piecesOf = new Map((this.pickFormat()?.puzzles ?? []).map((puzzle) => [String(puzzle.roundPuzzleId).toLowerCase(), puzzle.piecesCount ?? null]));
        const counted = [];

        all.forEach((row, index) => {
            const status = row.querySelector('[data-row-status]');
            const at = row.querySelector('[data-row-at]');
            const pieces = row.querySelector('[data-row-pieces]');
            const id = row.querySelector('[data-row-puzzle]').value;
            const done = mode === 'time' || status.value === 'done';

            status.hidden = mode === 'time';
            at.hidden = !done;
            pieces.hidden = done || !(mode === 'pieces' || missing);
            pieces.placeholder = this.t(missing ? 'placeholder_missing' : 'placeholder_pieces');
            // A finished result's time is the last puzzle's "finished at"
            at.placeholder = mode === 'time' && index === lastChosen ? this.t('puzzles_placeholder_time') : this.t('puzzles_placeholder_at');

            const atText = at.value.trim();
            const piecesText = pieces.value.trim();
            let atSeconds = null;
            let piecesPlaced = null;

            if (done && atText !== '') {
                const parsed = parseResultTime(atText);

                if (parsed.error || parsed.empty) {
                    error ??= this.t('puzzles_error_split', { position: index + 1 });
                } else {
                    atSeconds = parsed.seconds;
                }
            }

            if (!done && missing && piecesText !== '') {
                const count = piecesOf.get(id.toLowerCase()) ?? null;
                const read = typedPieces(piecesText, MISSING, count, { allowNone: true });

                if (read.error) {
                    error ??= this.t('error_missing_row', { position: index + 1, max: count ?? '?' });
                } else {
                    piecesPlaced = read.placed > 0 ? read.placed : null;
                    counted.push(this.t('preview_missing', { missing: read.missing, placed: read.placed, pieces: count }));
                }
            } else if (!done && mode === 'pieces' && piecesText !== '') {
                if (!/^\d{1,6}$/.test(piecesText) || Number(piecesText) < 1) {
                    error ??= this.t('puzzles_error_pieces', { position: index + 1 });
                } else {
                    piecesPlaced = Number(piecesText);
                }
            }

            rows.push({ id, done, at: atSeconds, pieces: piecesPlaced });
        });

        return error !== null ? { error } : { rows, counted };
    }

    /**
     * What the per-puzzle editor would save: {value: {result, puzzles}, preview} or {error}.
     */
    puzzlesEditorValue(form) {
        const format = this.pickFormat();
        const mode = form.querySelector('[data-editor-mode]').value;
        const typed = this.puzzlesEditorRows(form, mode);

        if (typed.error !== undefined) {
            return { error: typed.error };
        }

        const outcome = editorOutcome(format, mode === 'missing' ? 'pieces' : mode, typed.rows);

        if (outcome.error !== null) {
            return { error: this.t(`puzzles_error_${outcome.error}`, { count: format.toSolve }) };
        }

        if (outcome.refusal !== null) {
            return { error: this.t(`reason_${outcome.refusal}`) };
        }

        let preview = this.t('preview_none');

        if (outcome.result?.didNotStart) {
            preview = this.t('preview_dns');
        } else if (Number.isInteger(outcome.result?.seconds)) {
            preview = this.t('preview_time', { time: formatResultTime(outcome.result.seconds) });
        } else if (Number.isInteger(outcome.result?.piecesPlaced)) {
            preview = [this.t('puzzles_preview_pieces', { placed: outcome.result.piecesPlaced }), ...typed.counted].join(' · ');
        }

        return { value: { result: outcome.result, puzzles: outcome.record }, preview };
    }

    /**
     * "Clear the per-puzzle record": the record alone goes (the result stays) - from = what the editor showed when it
     * opened. The plain total editor works again afterwards.
     */
    clearRecord() {
        if (this.editor === null || !this.editor.pick) {
            return;
        }

        const ref = this.editor.ref;

        if (this.entries.has(ref)) {
            saveEditorValue(this.pending, this.editor.puzzles, null);
        }

        this.closeEditor(true);
        this.flush();
        this.toast(this.t('puzzles_cleared', { name: this.entries.get(ref)?.displayName ?? '' }), 'info');
    }

    /** "Total only" (an entry without a record): the plain result editor instead of the per-puzzle one. */
    totalOnly() {
        if (this.editor === null || !this.editor.pick || this.editor.puzzles.seen !== null) {
            return;
        }

        this.openEditor(this.editor.ref, RESULT, { totalOnly: true });
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
            <div class="small text-danger mt-1" data-editor-meanwhile role="alert" hidden></div>
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

        if (form.dataset.editor === 'puzzles') {
            return this.puzzlesEditorValue(form);
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
        const missingRow = form.querySelector('[data-editor-missing-row]');

        if (missingRow !== null) {
            missingRow.hidden = mode !== 'time';
        }
        input.placeholder = this.t(mode === 'missing' ? 'placeholder_missing' : (mode === 'pieces' ? 'placeholder_pieces' : 'placeholder_time'));

        if (mode === 'dns') {
            return { value: { didNotStart: true }, preview: this.t('preview_dns') };
        }

        if (mode === 'none') {
            return { value: null, preview: this.t('preview_none') };
        }

        if (mode === 'missing') {
            // Counted as missing pieces (reliability hardening T1): placed = the round's pieces − missing, both shown
            const pieces = this.round.piecesCount;
            const read = typedPieces(text, MISSING, pieces);

            if (read.empty || read.error) {
                return { error: this.t('error_missing', { max: pieces - 1 }) };
            }

            return { value: { piecesPlaced: read.placed }, preview: this.t('preview_missing', { missing: read.missing, placed: read.placed, pieces }) };
        }

        if (mode === 'pieces') {
            // A `pick` round entry without a record: K × the biggest puzzle − 1 (official_results_puzzles.js)
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

        // Finished with pieces missing (competition toolkit T6): the optional count next to the time
        const missingInput = form.querySelector('[data-editor-missing]');
        const missing = missingInput === null ? { empty: true } : parseMissingPieces(missingInput.value, this.round.piecesCount ?? null);

        if (missing.error) {
            return { error: this.t('missing_error') };
        }

        const value = { seconds: parsed.seconds };

        return {
            value,
            missing: missing.empty ? null : missing.value,
            preview: withMissing(this.t('preview_time', { time: formatResultTime(parsed.seconds) }), value, missing.value ?? null, (count) => this.tc('missing_pieces', count)),
        };
    }

    /**
     * Didn't finish ↔ Didn't finish (missing pieces): the counts typed or prefilled keep what they meant - 4900 placed of
     * 5000 becomes 100 missing (reliability hardening T1).
     */
    convertCounts(form) {
        const select = form.querySelector('[data-editor-mode]');
        const was = form.dataset.counts ?? select?.querySelector('[selected]')?.value;
        const mode = select?.value;
        form.dataset.counts = mode ?? '';

        if (select === null || was === mode || ![was, mode].every((one) => one === 'pieces' || one === 'missing')) {
            return;
        }

        const piecesOf = new Map((this.pickFormat()?.puzzles ?? []).map((puzzle) => [String(puzzle.roundPuzzleId).toLowerCase(), puzzle.piecesCount ?? null]));
        const fields = form.dataset.editor === 'puzzles'
            ? [...form.querySelectorAll('[data-puzzle-row]')].map((row) => [row.querySelector('[data-row-pieces]'), piecesOf.get(row.querySelector('[data-row-puzzle]').value.toLowerCase()) ?? null])
            : [[form.querySelector('[data-editor-input]'), this.round.piecesCount ?? null]];

        for (const [input, pieces] of fields) {
            input.value = convertTyped(input.value, was === 'missing' ? MISSING : PLACED, mode === 'missing' ? MISSING : PLACED, pieces);
        }
    }

    editorPreview() {
        const form = this.editorForm();

        if (form === null) {
            return;
        }

        this.convertCounts(form);
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

        const meanwhile = this.editorMeanwhile();

        if (meanwhile !== null) {
            if (sameValue(meanwhile.field === PUZZLES ? outcome.value.puzzles : (this.editor.pick ? outcome.value.result : outcome.value), meanwhile.current)) {
                // Both typed the same - nothing to decide
                this.editorTakeTheirs();

                return;
            }

            // Somebody else saved this field while the editor was open: the organiser decides, nothing goes silently
            this.renderEditorMeanwhile();
            this.editorForm()?.querySelector('[data-editor-keep-mine]')?.focus();

            return;
        }

        this.commitEditor(outcome.value, outcome.missing);
    }

    commitEditor(value, missing = undefined) {
        const entry = this.entries.get(this.editor.ref);
        const missingChange = entry !== undefined && !this.editor.pick && this.editor.field === RESULT
            ? missingPiecesChange(value, missing, this.editor.missing?.seen ?? this.shown(entry, MISSING_PIECES))
            : null;

        if (missingChange !== null) {
            // Finished with pieces missing (toolkit T6): with a changed time one group, the result first - the server checks
            // the entry is finished at that point; alone (the time stays) just the count
            const group = `missing:${this.editor.ref}`;
            const resultChanges = !sameValue(value, this.shown(entry, RESULT));
            // `from` = the count the editor showed when it opened, never the one merged in since (reliability F2, D7); an
            // own unsent count replaced here starts from the server's, as before
            const missingFrom = this.pending.get(this.editor.ref, MISSING_PIECES) === null && this.editor.missing !== undefined
                ? this.editor.missing.seen
                : this.serverValue(entry, MISSING_PIECES);
            this.pending.discard(this.editor.ref, MISSING_PIECES);
            this.pending.set(this.editor.ref, RESULT, value, this.editor.seen, resultChanges ? group : null);
            this.pending.set(this.editor.ref, MISSING_PIECES, missingChange.to, missingFrom, resultChanges ? group : null);
            this.closeEditor(true);
            this.flush();

            return;
        }

        if (this.entries.has(this.editor.ref)) {
            if (this.editor.pick) {
                // One group: the server applies the result and the record together or not at all (A rule 7), and
                // the desk never sends one without the other
                const group = `puzzles:${this.editor.ref}`;
                this.pending.set(this.editor.ref, RESULT, value.result, this.editor.seen, group);
                this.pending.set(this.editor.ref, PUZZLES, value.puzzles, this.editor.puzzles.seen, group);
            } else {
                saveEditorValue(this.pending, this.editor, value);
            }
        }

        this.closeEditor(true);
        this.flush();
    }

    /**
     * Somebody else's value of the edited field that arrived while the editor was open (a live update, or a conflict
     * answer to the organiser's earlier value) - or null.
     */
    editorMeanwhile() {
        if (this.editor === null) {
            return null;
        }

        const entry = this.entries.get(this.editor.ref);

        if (entry === undefined) {
            return null;
        }

        const result = savedMeanwhile(this.pending, this.editor, {
            value: this.serverValue(entry, this.editor.field),
            enteredBy: this.editor.field === RESULT ? (entry.enteredBy ?? null) : null,
        });

        if (result !== null || this.editor.puzzles === undefined) {
            return result === null ? null : { ...result, field: this.editor.field };
        }

        // The record of a `pick` round entry changed meanwhile (a judge chose the puzzles) - the organiser decides too
        const puzzles = savedMeanwhile(this.pending, this.editor.puzzles, { value: this.serverValue(entry, PUZZLES), enteredBy: entry.enteredBy ?? null });

        return puzzles === null ? null : { ...puzzles, field: PUZZLES };
    }

    renderEditorMeanwhile() {
        const slot = this.editorForm()?.querySelector('[data-editor-meanwhile]');

        if (!slot) {
            return;
        }

        const meanwhile = this.editorMeanwhile();
        const key = meanwhile === null ? '' : JSON.stringify(meanwhile);

        if (slot.dataset.shown === key) {
            return;
        }

        slot.dataset.shown = key;
        slot.hidden = meanwhile === null;

        if (meanwhile === null) {
            slot.replaceChildren();

            return;
        }

        const theirs = this.formatField(meanwhile.field ?? this.editor.field, meanwhile.current);
        const who = meanwhile.enteredBy?.name;
        const text = who ? this.t('conflict_by', { name: who, value: theirs }) : this.t('conflict', { value: theirs });

        slot.innerHTML = `<i class="bi bi-people me-1" aria-hidden="true"></i>${escapeHtml(text)}
            <span class="d-inline-flex gap-1 ms-1">
                <button type="button" class="btn btn-sm btn-outline-danger py-0" data-editor-keep-mine data-action="results-desk#editorKeepMine">${escapeHtml(this.t('keep_mine'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-action="results-desk#editorTakeTheirs">${escapeHtml(this.t('take_theirs'))}</button>
            </span>`;
    }

    /**
     * "Keep mine" next to the editor: the organiser saw the other value and saves theirs over it.
     */
    editorKeepMine() {
        if (this.editor === null) {
            return;
        }

        const outcome = this.editorValue();

        if (outcome.error !== undefined) {
            this.editorPreview();
            this.editorForm()?.querySelector('[data-editor-input]')?.focus();

            return;
        }

        const entry = this.entries.get(this.editor.ref);

        if (entry !== undefined) {
            const puzzles = this.editor.puzzles;
            this.editor = keepMineInEditor(this.pending, this.editor, this.serverValue(entry, this.editor.field));

            if (puzzles !== undefined) {
                this.editor.puzzles = keepMineInEditor(this.pending, puzzles, this.serverValue(entry, PUZZLES));
            }
        }

        this.commitEditor(outcome.value);
    }

    /**
     * "Take theirs" next to the editor: the organiser's value is dropped, the other one stays.
     */
    editorTakeTheirs() {
        if (this.editor === null) {
            return;
        }

        for (const field of this.editor.puzzles !== undefined ? [this.editor.field, PUZZLES] : [this.editor.field]) {
            const cell = this.pending.get(this.editor.ref, field);

            if (cell !== null && cell.status === 'conflict') {
                this.pending.discard(this.editor.ref, field);
            }
        }

        this.closeEditor(true);
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

        // `from` = the mark the organiser saw before the click (a row with an open editor is not redrawn meanwhile)
        this.pending.set(ref, QUALIFIED, event.currentTarget.checked, !event.currentTarget.checked);
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

        // The holder's number is the one the refusal named - what the organiser saw them hold
        this.pending.set(holder.ref, TABLE, cell.base ?? null, cell.to);
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
            [RESULT, TABLE, QUALIFIED, PUZZLES].forEach((field) => this.pending.discard(ref, field));
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
        const button = event.currentTarget;
        const mode = button.dataset.helper;
        // An advancement step's "Mark top N" (competition toolkit A8) brings its N - and for "top N over all its rounds"
        // the address of the server's proposal; the review dialog and "Apply" are the same as by hand
        const preset = Math.floor(Number(button.dataset.helperCount) || 0);
        const presetUrl = mode === 'top' ? (button.dataset.helperPresetUrl ?? null) : null;
        this.helper = {
            mode,
            count: preset > 0 ? preset : (mode === 'top' ? Math.max(1, this.rankedEntries().filter((entry) => entry.qualified).length || 10) : 1),
            keepOthers: mode === 'country',
            include: new Map(),
            overall: presetUrl ? { url: presetUrl, answer: null, failed: false, requestId: 0 } : null,
        };

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

        if (this.helper.overall !== null) {
            this.loadOverallProposal();
        }
    }

    /**
     * "Top N over all the step's rounds" (competition toolkit A8): the server ranks every source round together by the
     * advancement seed (saved results); the dialog shows this round's part of it. The latest request wins.
     */
    async loadOverallProposal() {
        const helper = this.helper;
        const overall = helper?.overall;

        if (!overall) {
            return;
        }

        const requestId = ++overall.requestId;
        overall.answer = null;
        overall.failed = false;
        this.renderHelper();

        const separator = overall.url.includes('?') ? '&' : '?';
        const answer = await officialResultsRequest(`${overall.url}${separator}count=${encodeURIComponent(String(helper.count))}`);

        if (this.helper !== helper || overall.requestId !== requestId) {
            return;
        }

        if (answer.kind === 'ok') {
            overall.answer = answer.data;
        } else {
            overall.failed = true;
        }

        this.renderHelper();
    }

    helperChanged(event) {
        if (this.helper === null) {
            return;
        }

        const target = event.target;

        if (target.matches('[data-helper-count]')) {
            this.helper.count = Math.max(0, Math.floor(Number(target.value) || 0));
            this.helper.include = new Map();

            if (this.helper.overall !== null) {
                this.loadOverallProposal();

                return;
            }
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
            const proposal = helper.overall !== null ? overallForRound(helper.overall.answer, ranked) : topN(ranked, helper.count);
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
        // An advancement step's overall proposal is on its way (or failed): nothing to review or apply yet
        if (this.helper.overall !== null && this.helper.overall.answer === null) {
            const decide = this.helperBodyTarget.querySelector('[data-helper-decide]');
            if (decide) {
                decide.innerHTML = '';
            }

            this.helperSummaryTarget.innerHTML = `<p class="text-body-secondary mb-0">${escapeHtml(this.t(this.helper.overall.failed ? 'step_overall_failed' : 'step_overall_loading'))}</p>`;
            this.helperApplyTarget.disabled = true;

            return;
        }

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
        this.helperSummaryTarget.innerHTML = `${this.helper.overall !== null ? `<p class="small text-body-secondary mb-2">${escapeHtml(this.t('step_overall_note'))}</p>` : ''}<p class="fw-semibold mb-2">${escapeHtml(this.t('helper_diff', { mark: diff.mark.length, unmark: diff.unmark.length, total }))}</p>
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
                    // The helper's diff only lists marks it showed the other way round: that is what the organiser saw
                    this.pending.set(ref, QUALIFIED, to, !to);
                }
            }
        }

        this.modal(this.helperModalTarget).hide();
        this.helper = null;
        this.render();
        this.flush();
        this.toast(this.t('helper_applied', { mark: mark.length, unmark: unmark.length }), 'info');
    }

    /**
     * An advancement step's "Advance" (competition toolkit A8): the Advance dialog on this page opens prefilled from
     * the step (advance_qualified_controller.js `openStep`) - the dry run, plan hash and apply are unchanged.
     */
    advanceStep(event) {
        let step;

        try {
            step = JSON.parse(event.currentTarget.dataset.step ?? 'null');
        } catch {
            return;
        }

        window.dispatchEvent(new CustomEvent('advance-qualified:open-step', { detail: { step } }));
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

                // A standing scores them as no-shows once this is published (world-series-rules.md W1-b)
                if (Array.isArray(this.round.noShowStandings) && this.round.noShowStandings.length > 0) {
                    lines.push(`<div class="alert alert-warning py-2 mb-2" data-publish-no-shows><i class="bi bi-person-x me-1" aria-hidden="true"></i>${escapeHtml(this.tc('publish_no_shows', withoutResult, { standing: this.round.noShowStandings.join(', ') }))}</div>`);
                }
            }

            if (this.revealMissedCount() > 0 && typeof this.urlsValue.missedReveal === 'string') {
                lines.push(`<div class="border rounded p-2 mb-2" data-reveal-missed-offer><span class="small text-body-secondary">${escapeHtml(this.t('reveal_missed_loading'))}</span></div>`);
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
        this.revealOffer = null;
        this.modal(this.publishModalTarget).show();
        this.loadRevealOffer();
    }

    /**
     * The hidden puzzles the publish may reveal too - names and how far each comes out (the same preview "Reveal now"
     * confirms against): asked when the dialog opens, never before. The box is UNTICKED, always: publishing alone
     * reveals nothing, and an early reveal is the one thing that must never happen.
     */
    async loadRevealOffer() {
        const box = this.publishModalBodyTarget.querySelector('[data-reveal-missed-offer]');

        if (box === null) {
            return;
        }

        this.revealOfferRequest += 1;
        const request = this.revealOfferRequest;
        const answer = await officialResultsRequest(this.urlsValue.missedReveal);

        if (request !== this.revealOfferRequest || !box.isConnected) {
            return;
        }

        const puzzles = answer.kind === 'ok' && Array.isArray(answer.data?.puzzles) ? answer.data.puzzles : null;

        if (puzzles === null || typeof answer.data.hash !== 'string') {
            box.innerHTML = `<span class="small text-body-secondary">${escapeHtml(this.t('reveal_missed_failed'))}</span>`;

            return;
        }

        if (puzzles.length === 0) {
            box.remove();

            return;
        }

        const items = puzzles.map((puzzle) => {
            const scope = this.t(`reveal_scope_${puzzle.scope}`);
            const until = typeof puzzle.hiddenElsewhereUntil === 'string' ? ` (${this.t('reveal_missed_elsewhere', { time: this.formatTime(puzzle.hiddenElsewhereUntil) })})` : '';

            return `<li data-reveal-missed-item>${escapeHtml(puzzle.name)} – <span class="text-body-secondary">${escapeHtml(scope + until)}</span></li>`;
        });

        box.innerHTML = `<div class="form-check mb-1">`
            + `<input class="form-check-input" type="checkbox" id="results-desk-reveal-missed" data-reveal-missed-checkbox>`
            + `<label class="form-check-label fw-semibold" for="results-desk-reveal-missed">${escapeHtml(this.tc('reveal_missed_checkbox', puzzles.length))}</label>`
            + `</div><ul class="small mb-1 ps-4">${items.join('')}</ul>`
            + `<div class="form-text mt-0">${escapeHtml(this.t('reveal_missed_help'))}</div>`;
        this.revealOffer = { hash: answer.data.hash };
    }

    async confirmPublish() {
        const action = this.publishAction;

        if (action === null) {
            return;
        }

        const revealTicked = action === 'publish'
            && this.revealOffer !== null
            && this.publishModalBodyTarget.querySelector('[data-reveal-missed-checkbox]')?.checked === true;
        const body = revealTicked ? { revealMissed: true, revealHash: this.revealOffer.hash } : {};

        this.publishConfirmTarget.disabled = true;
        const answer = await officialResultsRequest(action === 'publish' ? this.urlsValue.publish : this.urlsValue.unpublish, {
            method: 'POST',
            body,
            csrfToken: this.csrfTokenValue,
        });
        this.publishConfirmTarget.disabled = false;

        if (answer.kind === 'ok') {
            this.updateRound(answer.data.round);
            this.modal(this.publishModalTarget).hide();
            this.publishAction = null;
            this.render();
            this.revealOffer = null;

            if (answer.data.reveal === 'revealed') {
                this.toast(this.t('reveal_missed_revealed'), 'success');
            } else if (answer.data.reveal === 'changed') {
                this.toast(this.t('reveal_missed_changed'), 'warning');
            } else {
                this.toast(this.t(action === 'publish' ? 'published_toast' : 'unpublished_toast'), 'success');
            }

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
