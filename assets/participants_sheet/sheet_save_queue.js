/**
 * The one save queue of the participants spreadsheet (contract §5, docs/features/competitions-management/
 * participants-spreadsheet.md §6 and "Client architecture (as built)") - a FIFO of
 *
 * - `sheet`   - groups of sheet changes → POST `urls.changes` as one changeset (`changesetId` kept across retries, so a
 *               resend whose answer got lost is answered from the server's receipt and never applied twice);
 * - `results` - the queued official fields of one round (a PendingChanges per round, the results desk's own module) →
 *               POST `urls.record` (RecordRoundResults, `__ROUND__` replaced);
 * - `tables`  - one AssignTableNumbers write → POST `urls.tables`;
 * - `preview` - a dry run of groups (the match-then-confirm dialogs) - after everything queued before it;
 * - `state`   - a fetch of the state (GET `urls.state`), merged into the model - after everything queued before it, so
 *               a state never races one of our own saves.
 *
 * Rules: ~800 ms debounce after the last edit (consecutive sheet groups and a round's results ride in one request);
 * **one request in flight**, in order; offline / 5xx / busy = kept and retried (2, 5, 10, 20, 30 s; at once when the
 * browser is back online); signed out (401 / a redirect / a 2xx without JSON) = nothing sent until "Try again" after
 * signing in; 403 = "Reload the page"; the event gone (JSON 404) = nothing more is sent. An item once sent is frozen
 * (a later group goes into a new changeset - a replayed id must carry the same content).
 *
 * The version protocol (contract §3.2): after a sheet answer, `versionBefore === model.version` (and not a replay) means
 * nobody else changed the sheet meanwhile - the model (applied groups folded in, refused/conflicting ones reverted) is
 * the server's state, `model.version = versionAfter`. Otherwise the state is fetched and merged. Saves that create
 * things the browser cannot know (entry ids of new places, `source` after a removal, registration after a restore)
 * also fetch the state, once things are quiet.
 *
 * Conflicts and refusals become **problems** (listed by the controller's panel, shown on their cells through
 * model.marks) with the server's translated message; `keepMine()` sends the organiser's value again over the current
 * one, `dismiss()` keeps theirs. Network and timers are injected - pinned by tests/participants-sheet-core-harness.mjs.
 *
 * Order: a round's results cells are stamped with the queue position when they are queued - a results request takes
 * only cells queued before the next sheet item, so a result typed for a pair created by a later sheet group never
 * overtakes the group (the server would not know the pair yet). Bulk answers settle in one model rebuild and one
 * re-render (model.batch / confirmMany / revertMany); a view throwing while it re-renders never leaves the rest of an
 * answer unsettled.
 */

import { officialResultsRequest, newClientId, isGone } from '../official_results_api.js';
import { PendingChanges, sameValue } from '../official_results_pending_changes.js';
import { changeTarget, invertGroups, wireGroups, MAX_CHANGES, MAX_GROUPS } from './sheet_changes.js';
import { OUT, parsePlace } from './sheet_model.js';

export const DEBOUNCE_MS = 800;
export const RETRY_SECONDS = [2, 5, 10, 20, 30];
export const MAX_RESULT_CHANGES = 500;
export const REFETCH_AFTER_SAVE_MS = 1500;
// A dry run waiting behind a retried save answers "could not check" after this long instead of "Checking…" forever
export const PREVIEW_TIMEOUT_MS = 15000;
// Versions our own saves produced - their Mercure echo is not "another organiser changed the sheet"
const OWN_VERSIONS_KEPT = 50;
// A state fetched while results arrived meanwhile may be older than them - asked again at most this often
const STATE_ATTEMPTS = 3;

/**
 * The marker key and entities of a results cell (`result:<ref>:<field>`).
 */
export function resultTarget(model, ref, field) {
    const people = [];
    const teams = [];
    const rounds = [];

    if (ref.startsWith('team:')) {
        const teamId = ref.slice(5);
        teams.push(teamId);
        model.membersOf(teamId).forEach((person) => people.push(person.id));
        const roundId = model.team(teamId)?.roundId;

        if (roundId) {
            rounds.push(roundId);
        }
    } else if (ref.startsWith('participant_round:')) {
        const place = model.placeById(ref.slice('participant_round:'.length));

        if (place !== null) {
            people.push(place.participantId);
            rounds.push(place.roundId);
        }
    }

    return { key: `result:${ref}:${field}`, people, teams, rounds };
}

export class SheetSaveQueue {
    /**
     * @param {object} options
     * @param {import('./sheet_model.js').SheetModel} options.model
     * @param {{changes: string, state: string, record?: string, tables?: string}} options.urls
     * @param {string} options.csrfToken
     * @param {object} [options.texts] {genericError: string} - the fallback message of a refusal without one
     * @param {function(string, object): Promise<object>} [options.request] official_results_api.js's officialResultsRequest
     * @param {function(): string} [options.newId]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     * @param {function(): number} [options.now]
     */
    constructor({
        model,
        urls,
        csrfToken,
        texts = {},
        request = officialResultsRequest,
        newId = newClientId,
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
        now = () => Date.now(),
    }) {
        this.model = model;
        this.urls = urls;
        this.csrfToken = csrfToken;
        this.texts = texts;
        this.request = request;
        this.newId = newId;
        this.schedule = schedule;
        this.cancel = cancel;
        this.now = now;

        this.items = [];
        this.inFlight = null;
        this.transport = 'ok';
        this.retryIndex = 0;
        this.timer = null;
        this.timerDue = null;
        this.holdUntil = 0;
        // Items up to this sequence number go without the debounce (a dry run, a fetch, "send now"); later ones wait
        this.itemSeq = 0;
        this.urgentSeq = 0;
        // Sheet items queued so far - a results cell is stamped with it (it waits for every sheet item queued before it)
        this.sheetSeq = 0;
        this.ownVersions = [];
        this.refetchTimer = null;
        this.resultPending = new Map();
        this.problemList = new Map();
        this.listeners = new Set();
        this.destroyed = false;
        this.lastStatus = '';
    }

    // ---------------------------------------------------------------- events

    /**
     * Events: {type: 'status', status} · {type: 'outcome', kind: 'sheet', group, outcome} (every answered group) ·
     * {type: 'warnings', warnings} · {type: 'problems'} · {type: 'state', state, kind} (a state fetch answered) ·
     * {type: 'results', roundId, outcomes} · {type: 'gone'}.
     *
     * @returns {function(): void} unsubscribe
     */
    subscribe(listener) {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }

    emit(event) {
        // A listener that throws (a view's bug) is logged; the others still hear the event
        for (const listener of [...this.listeners]) {
            try {
                listener(event);
            } catch (error) {
                console.error(error);
            }
        }
    }

    push(item) {
        item.seq = ++this.itemSeq;
        this.items.push(item);

        return item;
    }

    emitStatus() {
        const status = this.status();
        const key = JSON.stringify(status);

        if (key !== this.lastStatus) {
            this.lastStatus = key;
            this.emit({ type: 'status', status });
        }
    }

    // ---------------------------------------------------------------- enqueueing

    /**
     * Sheet groups the model shows already (model.applyLocal) - saved after the debounce, in order.
     */
    enqueueGroups(groups) {
        if (groups.length === 0 || this.destroyed) {
            return;
        }

        const state = this.waitingState();
        const marks = [];

        for (const group of groups) {
            for (const change of group.changes) {
                const target = changeTarget(change, this.model);
                marks.push({ key: target.key, mark: { state, groupId: group.id }, entities: target });
            }
        }

        this.model.marks.setMany(marks);

        const last = this.items[this.items.length - 1];

        if (last && last.kind === 'sheet' && !last.sent && canTake(last.groups, groups)) {
            last.groups.push(...groups);
        } else {
            for (const chunk of chunks(groups)) {
                this.push({ kind: 'sheet', id: this.newId(), groups: chunk, sent: false, sheetSeq: ++this.sheetSeq });
            }
        }

        this.hold();
    }

    /** The official fields of a round waiting to be sent (results desk semantics: `set(ref, field, to, seen)`). */
    results(roundId) {
        if (!this.resultPending.has(roundId)) {
            this.resultPending.set(roundId, new PendingChanges());
        }

        return this.resultPending.get(roundId);
    }

    /**
     * The round's queued official fields go with the next request (after the debounce).
     */
    enqueueResults(roundId) {
        if (this.destroyed) {
            return;
        }

        const marks = [];

        for (const cell of this.results(roundId).list()) {
            if (cell.status === 'queued') {
                // Stamped with the sheet items queued so far: sent only after all of them (a result for a pair a
                // sheet group creates never overtakes the group); a new value takes a new stamp
                if (cell.sheetSeq === undefined || !sameValue(cell.stampedTo, cell.to)) {
                    cell.sheetSeq = this.sheetSeq;
                    cell.stampedTo = cell.to;
                }

                const target = resultTarget(this.model, cell.ref, cell.field);
                marks.push({ key: target.key, mark: { state: this.waitingState(), groupId: null }, entities: target });
            }
        }

        this.model.marks.setMany(marks);

        // A cell changed back to the saved value before it was sent is gone - so is its "saving" marker
        const pending = this.results(roundId);

        for (const mark of this.model.marks.all()) {
            if ((mark.state === 'saving' || mark.state === 'waiting') && mark.key.startsWith('result:') && (mark.entities?.rounds ?? []).includes(roundId)) {
                const field = mark.key.slice(mark.key.lastIndexOf(':') + 1);
                const ref = mark.key.slice('result:'.length, mark.key.lastIndexOf(':'));

                if (pending.get(ref, field) === null) {
                    this.model.marks.set(mark.key, null);
                }
            }
        }

        const last = this.items[this.items.length - 1];

        if (!(last && last.kind === 'results' && last.roundId === roundId && !last.sent)) {
            this.push({ kind: 'results', roundId, sent: false });
        }

        this.hold();
    }

    /**
     * One AssignTableNumbers write (`[{entry, from, number}]`). Resolves to {kind: 'ok', entries} | {kind: 'refused',
     * problems} | {kind: 'auth'|'forbidden'|'gone'|'failed', message}.
     */
    enqueueTables(roundId, assignments) {
        return this.urgentItem({ kind: 'tables', roundId, assignments });
    }

    /**
     * A dry run of groups (contract §3.1 `dryRun: true`), after everything queued before it - resolves to the answer of
     * officialResultsRequest() (never retried: the dialog says it could not check and offers to try again).
     */
    preview(groups) {
        const promise = this.urgentItem({ kind: 'preview', groups });
        const item = this.items[this.items.length - 1];

        if (item?.kind === 'preview' && item.groups === groups) {
            // Behind a save that is being retried (offline, a server error) the dialog would say "Checking…" forever
            item.timer = this.schedule(() => {
                item.timer = null;

                if (!item.sent && this.items.includes(item)) {
                    this.items = this.items.filter((candidate) => candidate !== item);
                    item.resolve({ kind: this.transport === 'offline' ? 'offline' : 'timeout' });
                    this.emitStatus();
                }
            }, PREVIEW_TIMEOUT_MS);
        }

        return promise;
    }

    /**
     * The state again, merged into the model - one fetch at a time (a call meanwhile gets the queued one). Resolves to
     * the answer's kind (ok, auth, forbidden, offline, server, client).
     */
    refetch() {
        const queued = this.items.find((item) => item.kind === 'state' && !item.sent);

        if (queued !== undefined) {
            return queued.promise;
        }

        return this.urgentItem({ kind: 'state' });
    }

    /** A state fetch once things are quiet (after saves that created what the browser cannot know). */
    refetchSoon() {
        this.cancel(this.refetchTimer);
        this.refetchTimer = this.schedule(() => {
            this.refetchTimer = null;
            this.refetch();
        }, REFETCH_AFTER_SAVE_MS);
    }

    urgentItem(item) {
        if (this.destroyed) {
            return Promise.resolve(item.kind === 'state' ? 'closed' : { kind: 'closed' });
        }

        item.sent = false;
        item.promise = new Promise((resolve) => {
            item.resolve = resolve;
        });
        this.push(item);
        this.urgentSeq = item.seq;
        this.kick(0);

        return item.promise;
    }

    /** Send what waits now (leaving the page, the pill clicked) - what is queued later waits for the debounce again. */
    flushNow() {
        this.urgentSeq = this.itemSeq;
        this.kick(0);
    }

    /** "Try again" - after signing in again, after an outage. */
    retryNow() {
        if (this.transport === 'gone') {
            return;
        }

        this.transport = 'ok';
        this.retryIndex = 0;
        this.urgentSeq = this.itemSeq;
        this.kick(0);
        this.emitStatus();
    }

    /** The browser is online again. */
    online() {
        if (this.transport === 'offline' || this.transport === 'server') {
            this.retryNow();
        }
    }

    hold() {
        this.holdUntil = this.now() + DEBOUNCE_MS;

        // Offline or a server error: the retry's backoff decides when to try again, not the organiser's typing
        if (this.transport === 'ok') {
            this.kick(DEBOUNCE_MS);
        }

        this.emitStatus();
    }

    kick(ms) {
        if (this.destroyed) {
            return;
        }

        const due = this.now() + ms;

        if (this.timer !== null && this.timerDue <= due) {
            return;
        }

        this.cancel(this.timer);
        this.timerDue = due;
        this.timer = this.schedule(() => {
            this.timer = null;
            this.timerDue = null;
            this.flush();
        }, ms);
    }

    waitingState() {
        return this.transport === 'ok' ? 'saving' : 'waiting';
    }

    // ---------------------------------------------------------------- sending

    blocked() {
        return this.transport === 'auth' || this.transport === 'forbidden' || this.transport === 'gone';
    }

    async flush() {
        if (this.inFlight !== null || this.destroyed) {
            return;
        }

        if (this.blocked()) {
            // Nothing goes until "Try again"; dry runs and state fetches answer at once instead of waiting forever
            this.answerBlocked();
            this.emitStatus();

            return;
        }

        const item = this.items[0];

        if (item === undefined) {
            this.emitStatus();

            return;
        }

        if (item.seq > this.urgentSeq && (item.kind === 'sheet' || item.kind === 'results') && this.now() < this.holdUntil) {
            this.kick(this.holdUntil - this.now());

            return;
        }

        this.inFlight = item;
        this.emitStatus();

        let next = 'continue';

        try {
            next = await this.send(item);
        } catch (error) {
            // A bug while settling an answer: logged, the queue goes on (the item is tried again later, never lost)
            console.error(error);
            next = this.items[0] === item ? 'retry' : 'continue';
        } finally {
            this.inFlight = null;
        }

        if (this.destroyed) {
            return;
        }

        if (next === 'retry') {
            this.scheduleRetry();
        } else if (next === 'continue') {
            this.kick(0);
        }

        this.emitStatus();
    }

    answerBlocked() {
        const kind = this.transport === 'gone' ? 'client' : this.transport;

        this.items = this.items.filter((item) => {
            if (item.kind === 'state') {
                item.resolve(kind);

                return false;
            }

            if (item.kind === 'preview' || item.kind === 'tables') {
                item.resolve({ kind: this.transport === 'gone' ? 'gone' : this.transport });

                return false;
            }

            return true;
        });
    }

    scheduleRetry() {
        const seconds = RETRY_SECONDS[Math.min(this.retryIndex, RETRY_SECONDS.length - 1)];
        this.retryIndex++;
        this.kick(seconds * 1000);
    }

    /**
     * Sends one item; resolves to 'continue' (go on with the next), 'retry' (keep it, try later) or 'stop'.
     */
    async send(item) {
        switch (item.kind) {
            case 'sheet':
                return this.sendSheet(item);
            case 'results':
                return this.sendResults(item);
            case 'tables':
                return this.sendTables(item);
            case 'preview':
                return this.sendPreview(item);
            case 'state':
                return this.sendState(item);
            default:
                this.items.shift();

                return 'continue';
        }
    }

    /**
     * A transport failure of the item at the head: 'retry' / 'stop' and the transport state, the item kept.
     */
    failed(answer) {
        if (answer.kind === 'auth' || answer.kind === 'forbidden') {
            this.transport = answer.kind;

            return 'stop';
        }

        if (isGone(answer)) {
            this.goneAway();

            return 'stop';
        }

        this.transport = answer.kind === 'offline' ? 'offline' : 'server';
        this.refreshMarks();

        return 'retry';
    }

    ok() {
        if (this.transport !== 'ok') {
            this.transport = 'ok';
            this.refreshMarks();
        }

        this.retryIndex = 0;
    }

    goneAway() {
        this.transport = 'gone';
        this.emit({ type: 'gone' });
    }

    /** Waiting cells say "saving" while the server answers, "waiting" while it does not. */
    refreshMarks() {
        const state = this.waitingState();

        for (const mark of this.model.marks.all()) {
            if (mark.state === 'saving' || mark.state === 'waiting') {
                this.model.marks.set(mark.key, { ...mark, state });
            }
        }
    }

    async sendSheet(item) {
        item.sent = true;

        const answer = await this.request(this.urls.changes, {
            method: 'POST',
            body: { changesetId: item.id, dryRun: false, groups: wireGroups(item.groups) },
            csrfToken: this.csrfToken,
        });

        if (this.destroyed) {
            return 'stop';
        }

        if (answer.kind === 'ok') {
            this.ok();
            this.items.shift();
            this.settleSheet(item, answer.data ?? {});

            return 'continue';
        }

        if (answer.kind === 'client' && answer.status === 409 && answer.data?.error === 'changed_meanwhile') {
            // Something changed on the event while the server planned it (a deleted player, a stale read): nothing was
            // saved - kept and sent again later like a busy server, never a refusal of the groups
            return this.failed({ kind: 'server', status: 409, retryAfter: null, busy: true });
        }

        if (answer.kind === 'client' && !isGone(answer)) {
            // The changeset as a whole was refused (400 invalid_changes, 409 changeset_id_taken): nothing of it applied -
            // every group is reverted and answered like a refused one (undo and views hear it)
            this.ok();
            this.items.shift();
            const message = answer.data?.message ?? this.texts.genericError ?? null;
            const reason = answer.data?.reason ?? answer.data?.error ?? 'invalid_change';

            this.settleSheet(item, {
                replayed: true,
                groups: item.groups.map((group) => ({
                    id: group.id,
                    status: 'refused',
                    changes: group.changes.map((change, index) => ({ index, status: index === 0 ? 'refused' : 'skipped', reason, message, current: null })),
                    warnings: [],
                    deletedTeams: [],
                })),
            }, { refetch: false });

            return 'continue';
        }

        return this.failed(answer);
    }

    /**
     * The answer of a sheet changeset: applied/unchanged groups folded into the base, the others reverted and listed as
     * problems - in one model rebuild and one re-render, each group settled on its own (a bug with one never leaves the
     * others pending). Then the version protocol (contract §3.2).
     */
    settleSheet(item, data, { refetch = true } = {}) {
        const answered = new Map((data.groups ?? []).map((group) => [group.id, group]));
        let structural = false;
        const warnings = [];
        const outcomes = item.groups.map((group) => ({
            group,
            outcome: answered.get(group.id) ?? {
                id: group.id,
                status: 'refused',
                changes: group.changes.map((change, index) => ({ index, status: index === 0 ? 'refused' : 'skipped', reason: 'invalid_change', message: this.texts.genericError ?? null, current: null })),
                warnings: [],
                deletedTeams: [],
            },
        }));
        const went = ({ outcome }) => outcome.status === 'applied' || outcome.status === 'unchanged';

        this.model.batch(() => {
            guarded(() => this.model.confirmMany(outcomes.filter(went).map(({ group, outcome }) => ({ groupId: group.id, deletedTeams: outcome.deletedTeams ?? [] }))));
            guarded(() => this.model.revertMany(outcomes.filter((entry) => !went(entry)).map(({ group }) => group.id)));

            for (const { group, outcome } of outcomes) {
                guarded(() => {
                    if (went({ outcome })) {
                        for (const change of group.changes) {
                            const target = changeTarget(change, this.model);
                            this.model.marks.clearFor(target.key, group.id);
                            this.dropProblemsAt(target.key, false);
                        }

                        if (outcome.status === 'applied' && group.changes.some(createsUnknown)) {
                            structural = true;
                        }
                    } else {
                        this.refuseGroup(group, outcome.changes ?? [], false, true);
                    }

                    for (const warning of outcome.warnings ?? []) {
                        warnings.push({ ...warning, groupId: group.id });
                    }
                });
            }
        });

        if (data.versionAfter !== undefined) {
            this.rememberOwnVersion(data.versionAfter);
        }

        for (const { group, outcome } of outcomes) {
            this.emit({ type: 'outcome', kind: 'sheet', group, outcome });
        }

        this.emit({ type: 'problems' });

        if (warnings.length > 0) {
            this.emit({ type: 'warnings', warnings });
        }

        if (!refetch) {
            return;
        }

        if (!data.replayed && data.versionBefore !== undefined && data.versionBefore === this.model.version) {
            this.model.version = data.versionAfter ?? this.model.version;

            if (structural) {
                this.refetchSoon();
            }
        } else {
            // Somebody else changed the sheet meanwhile (or a replay): the server's state is fetched and merged
            this.refetch();
        }
    }

    /** A version one of our saves produced (its Mercure echo is ours, not another organiser's change). */
    rememberOwnVersion(version) {
        if (typeof version !== 'string' || this.ownVersions.includes(version)) {
            return;
        }

        this.ownVersions.push(version);

        if (this.ownVersions.length > OWN_VERSIONS_KEPT) {
            this.ownVersions.shift();
        }
    }

    isOwnVersion(version) {
        return this.ownVersions.includes(version);
    }

    /**
     * A group the server did not apply: the organiser's values go (model.revert), every conflicting or refused change
     * becomes a problem shown on its cell.
     */
    refuseGroup(group, changeOutcomes, announce = true, reverted = false) {
        if (!reverted) {
            this.model.revert(group.id);
        }

        group.changes.forEach((change, index) => {
            const outcome = changeOutcomes.find((candidate) => candidate.index === index) ?? { status: 'skipped' };
            const target = changeTarget(change, this.model);

            if (outcome.status !== 'conflict' && outcome.status !== 'refused') {
                this.model.marks.clearFor(target.key, group.id);

                return;
            }

            const problem = {
                id: `${group.id}:${index}`,
                kind: 'sheet',
                status: outcome.status,
                reason: outcome.reason ?? null,
                message: outcome.message ?? this.texts.genericError ?? null,
                current: outcome.current ?? null,
                change,
                group,
                target,
            };

            this.dropProblemsAt(target.key, false);
            this.problemList.set(problem.id, problem);
            this.model.marks.set(target.key, { state: outcome.status, message: problem.message, groupId: group.id, problemId: problem.id }, target);
        });

        if (announce) {
            this.emit({ type: 'problems' });
        }
    }

    dropProblemsAt(key, announce = true) {
        let dropped = false;

        for (const [id, problem] of this.problemList) {
            if (problem.target.key === key) {
                this.problemList.delete(id);
                dropped = true;
            }
        }

        if (dropped && announce) {
            this.emit({ type: 'problems' });
        }
    }

    async sendResults(item) {
        const pending = this.results(item.roundId);
        // Only cells queued before the next sheet item still waiting (they may need what it creates)
        const nextSheet = this.items.find((candidate) => candidate !== item && candidate.kind === 'sheet');
        const before = nextSheet === undefined ? Infinity : nextSheet.sheetSeq;
        const changes = pending.take(this.newId, MAX_RESULT_CHANGES, (cell) => (cell.sheetSeq ?? 0) < before);

        if (changes.length === 0) {
            this.items.shift();

            return 'continue';
        }

        item.sent = true;

        const answer = await this.request(this.urls.record.replace('__ROUND__', item.roundId), {
            method: 'POST',
            body: { changes },
            csrfToken: this.csrfToken,
        });

        if (this.destroyed) {
            return 'stop';
        }

        if (answer.kind === 'ok') {
            this.ok();

            for (const outcome of answer.data?.outcomes ?? []) {
                pending.settle(outcome);
            }

            // Anything the answer did not mention was not saved - shown as such, never sent in a loop
            pending.failInFlight(this.texts.genericError ?? null);
            const merged = this.model.mergeEntries(answer.data?.entries ?? []);

            if (merged.unknown.length > 0) {
                this.refetchSoon();
            }

            this.syncResultMarks(item.roundId, changes);
            this.emit({ type: 'results', roundId: item.roundId, outcomes: answer.data?.outcomes ?? [] });

            if (pending.list().some((cell) => cell.status === 'queued' && cell.inFlight === null && (cell.sheetSeq ?? 0) < before)) {
                item.sent = false;

                return 'continue';
            }

            this.items.shift();

            return 'continue';
        }

        if (answer.kind === 'client' && !isGone(answer)) {
            this.ok();
            pending.failInFlight(answer.data?.message ?? this.texts.genericError ?? null);
            this.items.shift();
            this.syncResultMarks(item.roundId, changes);

            return 'continue';
        }

        if (isGone(answer)) {
            pending.failInFlight(answer.data?.message ?? this.texts.genericError ?? null);
            this.items.shift();
            this.syncResultMarks(item.roundId, changes);
            this.goneAway();

            return 'stop';
        }

        pending.retryInFlight();
        item.sent = false;

        return this.failed(answer);
    }

    /** Markers and problems of the results cells sent: saved ones cleared, conflicts and refusals listed. */
    syncResultMarks(roundId, sent) {
        const pending = this.results(roundId);

        for (const change of sent) {
            const target = resultTarget(this.model, change.entry, change.field);
            const cell = pending.get(change.entry, change.field);
            const problemId = `result:${roundId}:${change.entry}:${change.field}`;
            this.problemList.delete(problemId);

            if (cell === null) {
                this.model.marks.set(target.key, null);
                continue;
            }

            if (cell.status === 'conflict' || cell.status === 'error') {
                const status = cell.status === 'conflict' ? 'conflict' : 'refused';
                this.problemList.set(problemId, {
                    id: problemId,
                    kind: 'results',
                    status,
                    reason: cell.reason ?? null,
                    message: cell.message ?? null,
                    current: cell.conflict?.current ?? null,
                    enteredBy: cell.conflict?.enteredBy ?? null,
                    roundId,
                    ref: change.entry,
                    field: change.field,
                    to: cell.to,
                    target,
                });
                this.model.marks.set(target.key, { state: status, message: cell.message ?? null, problemId }, target);
            } else {
                this.model.marks.set(target.key, { state: this.waitingState(), groupId: null }, target);
            }
        }

        this.emit({ type: 'problems' });
    }

    async sendTables(item) {
        item.sent = true;

        const answer = await this.request(this.urls.tables.replace('__ROUND__', item.roundId), {
            method: 'POST',
            body: { assignments: item.assignments },
            csrfToken: this.csrfToken,
        });

        if (this.destroyed) {
            return 'stop';
        }

        if (answer.kind === 'ok') {
            this.ok();
            this.items.shift();
            const merged = this.model.mergeEntries(answer.data?.entries ?? []);

            if (merged.unknown.length > 0) {
                this.refetchSoon();
            }

            item.resolve({ kind: 'ok', entries: answer.data?.entries ?? [] });

            return 'continue';
        }

        if (answer.kind === 'client' && !isGone(answer)) {
            this.ok();
            this.items.shift();
            // changed_meanwhile: somebody else renumbered - the page shows the numbers as they are now
            this.refetch();
            item.resolve({ kind: 'refused', problems: answer.data?.problems ?? [], message: answer.data?.message ?? null });

            return 'continue';
        }

        const next = this.failed(answer);

        if (next === 'stop') {
            this.items.shift();
            item.resolve({ kind: this.transport === 'gone' ? 'gone' : this.transport });
        } else {
            item.sent = false;
        }

        return next === 'stop' ? 'continue' : next;
    }

    async sendPreview(item) {
        item.sent = true;
        this.cancel(item.timer);
        item.timer = null;

        const answer = await this.request(this.urls.changes, {
            method: 'POST',
            body: { dryRun: true, groups: wireGroups(item.groups) },
            csrfToken: this.csrfToken,
        });

        this.items.shift();

        if (answer.kind === 'auth' || answer.kind === 'forbidden') {
            this.transport = answer.kind;
        } else if (isGone(answer)) {
            this.goneAway();
        } else if (answer.kind === 'ok' || answer.kind === 'client') {
            this.ok();
        }

        item.resolve(answer);

        return 'continue';
    }

    async sendState(item) {
        item.sent = true;
        let answer;

        for (let attempt = 0; attempt < STATE_ATTEMPTS; attempt++) {
            const generation = this.model.resultsGeneration;
            answer = await this.request(this.urls.state);

            // Results arrived while the state was on its way - it may be older than them: ask again
            if (answer.kind !== 'ok' || this.model.resultsGeneration === generation || this.destroyed) {
                break;
            }
        }

        this.items.shift();

        if (this.destroyed) {
            item.resolve('closed');

            return 'stop';
        }

        if (answer.kind === 'ok') {
            const wasDown = this.transport === 'offline' || this.transport === 'server';
            this.ok();
            this.model.replaceState(answer.data);
            this.emit({ type: 'state', state: answer.data, kind: 'ok' });

            if (wasDown) {
                // Back: what waited goes at once
                this.urgentSeq = this.itemSeq;
            }
        } else if (answer.kind === 'auth' || answer.kind === 'forbidden') {
            this.transport = answer.kind;
            this.emit({ type: 'state', state: null, kind: answer.kind });
        } else if (isGone(answer)) {
            this.goneAway();
        }

        item.resolve(answer.kind);

        return 'continue';
    }

    // ---------------------------------------------------------------- problems

    problems() {
        return [...this.problemList.values()];
    }

    problem(id) {
        return this.problemList.get(id) ?? null;
    }

    /**
     * "Keep mine": the organiser's value goes again over what the server has now. A sheet group is sent again as a new
     * group with `from` = the current value of every conflicting change (the other changes of the group unchanged).
     * Returns the new group (the caller shows it - model.applyLocal is done here) or null. The controller uses
     * keepMineAction() instead, so the resend is an undo step of its own.
     */
    keepMine(problemId) {
        const action = this.keepMineAction(problemId);

        if (action === null) {
            return null;
        }

        const [group] = action.groups;
        this.model.applyLocal(group.id, group.changes);
        this.enqueueGroups([group]);

        return group;
    }

    /**
     * "Keep mine" as an action (sheet_changes.js shape - `{label, groups, inverse, errors}`), not performed: the caller
     * performs it like any edit (shown, queued, one undo step). A results problem is sent again at once and answers
     * null (its undo is the original step's).
     */
    keepMineAction(problemId) {
        const problem = this.problemList.get(problemId);

        if (problem === undefined) {
            return null;
        }

        if (problem.kind === 'results') {
            const pending = this.results(problem.roundId);
            pending.keepMine(problem.ref, problem.field);
            this.problemList.delete(problemId);
            this.emit({ type: 'problems' });
            this.enqueueResults(problem.roundId);

            return null;
        }

        const siblings = [...this.problemList.values()].filter((other) => other.kind === 'sheet' && other.group.id === problem.group.id);
        const currentByIndex = new Map(siblings.filter((other) => other.status === 'conflict').map((other) => [Number(other.id.split(':').pop()), other.current]));
        const changes = problem.group.changes.map((change, index) => (currentByIndex.has(index) ? { ...change, from: currentByIndex.get(index) } : change));
        const group = { id: this.newId(), changes, origin: problem.group.origin, label: problem.group.label };

        siblings.forEach((other) => this.problemList.delete(other.id));
        this.emit({ type: 'problems' });

        return { label: problem.group.label ?? { key: 'keep_mine' }, groups: [group], inverse: invertGroups([group], this.model, this.newId), errors: [] };
    }

    /**
     * "Use theirs" / "OK": the problem goes, the cell shows the server's value (fetched again for a conflict).
     */
    dismiss(problemId) {
        const problem = this.problemList.get(problemId);

        if (problem === undefined) {
            return;
        }

        this.problemList.delete(problemId);

        if (problem.kind === 'results') {
            this.results(problem.roundId).discard(problem.ref, problem.field);
        }

        this.model.marks.set(problem.target.key, null);
        this.emit({ type: 'problems' });

        if (problem.status === 'conflict') {
            this.refetch();
        }
    }

    /** A refused results cell sent once more as it is. */
    retryProblem(problemId) {
        const problem = this.problemList.get(problemId);

        if (problem === undefined || problem.kind !== 'results') {
            return;
        }

        this.results(problem.roundId).retry(problem.ref, problem.field);
        this.problemList.delete(problemId);
        this.emit({ type: 'problems' });
        this.enqueueResults(problem.roundId);
    }

    // ---------------------------------------------------------------- status

    /** Changes not saved yet: sheet groups queued or on their way, results cells, table writes. */
    waitingCount() {
        let count = 0;

        for (const item of this.items) {
            if (item.kind === 'sheet') {
                count += item.groups.length;
            } else if (item.kind === 'tables') {
                count++;
            }
        }

        for (const pending of this.resultPending.values()) {
            const counts = pending.counts();
            count += counts.queued + counts.sending;
        }

        return count;
    }

    /**
     * {state: 'saved'|'saving'|'waiting'|'offline'|'attention'|'auth'|'forbidden'|'gone', waiting, attention} - the
     * status pill: All saved / Saving… / N waiting / N waiting - offline / N need you / Sign in again / Reload the page.
     */
    status() {
        const waiting = this.waitingCount();
        const attention = this.problemList.size;
        // Offline is said even while problems need the organiser ("1 needs you · offline")
        const offline = waiting > 0 && this.transport === 'offline';

        if (this.transport === 'gone' || this.transport === 'forbidden' || this.transport === 'auth') {
            return { state: this.transport, waiting, attention, offline: false };
        }

        if (attention > 0) {
            return { state: 'attention', waiting, attention, offline };
        }

        if (offline) {
            return { state: 'offline', waiting, attention, offline };
        }

        if (waiting > 0 && this.transport === 'server') {
            return { state: 'waiting', waiting, attention, offline };
        }

        if (waiting > 0 || (this.inFlight !== null && this.inFlight.kind !== 'state')) {
            return { state: 'saving', waiting, attention, offline };
        }

        return { state: 'saved', waiting, attention, offline };
    }

    /** Leaving now would lose something: changes not saved yet, or conflicts the organiser has not decided. */
    hasUnsaved() {
        return this.waitingCount() > 0
            || this.items.some((item) => item.kind === 'sheet' || item.kind === 'tables')
            || [...this.problemList.values()].some((problem) => problem.status === 'conflict');
    }

    /**
     * `beforeunload` + `turbo:before-visit` warnings while hasUnsaved(). Returns the teardown.
     *
     * @param {{window: Window, document: Document, confirm: function(string): boolean, message: string}} options
     */
    installLeaveGuards({ window: win, document: doc, confirm, message }) {
        const onBeforeUnload = (event) => {
            if (this.hasUnsaved()) {
                this.flushNow();
                event.preventDefault();
                event.returnValue = '';
            }
        };
        const onBeforeVisit = (event) => {
            if (this.hasUnsaved() && !confirm(message)) {
                event.preventDefault();
            }
        };

        win.addEventListener('beforeunload', onBeforeUnload);
        doc.addEventListener('turbo:before-visit', onBeforeVisit);

        return () => {
            win.removeEventListener('beforeunload', onBeforeUnload);
            doc.removeEventListener('turbo:before-visit', onBeforeVisit);
        };
    }

    destroy() {
        this.destroyed = true;
        this.cancel(this.timer);
        this.cancel(this.refetchTimer);
        this.timer = null;

        for (const item of this.items) {
            this.cancel(item.timer);
            item.resolve?.(item.kind === 'state' ? 'closed' : { kind: 'closed' });
        }

        this.listeners.clear();
    }
}

/**
 * A change whose result the browser cannot fully know: a new round entry (its id), a removal or restore, a new
 * person, a profile linked or unlinked (whose own times guard the rounds, how the profile may be shown - O9).
 */
function createsUnknown(change) {
    return (change.op === 'place' && parsePlace(change.from).kind === OUT && parsePlace(change.to).kind !== OUT)
        || change.op === 'remove'
        || change.op === 'restore'
        || change.op === 'newParticipant'
        || change.op === 'player';
}

/** One part of settling an answer: a bug in it is logged, the rest goes on. */
function guarded(fn) {
    try {
        fn();
    } catch (error) {
        console.error(error);
    }
}

function changeCount(groups) {
    return groups.reduce((sum, group) => sum + group.changes.length, 0);
}

function canTake(current, added) {
    return current.length + added.length <= MAX_GROUPS && changeCount(current) + changeCount(added) <= MAX_CHANGES;
}

/** Groups split into changesets within the endpoint's limits. */
function chunks(groups) {
    const list = [];
    let current = [];

    for (const group of groups) {
        if (current.length > 0 && !canTake(current, [group])) {
            list.push(current);
            current = [];
        }

        current.push(group);
    }

    if (current.length > 0) {
        list.push(current);
    }

    return list;
}
