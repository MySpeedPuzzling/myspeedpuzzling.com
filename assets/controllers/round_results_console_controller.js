import { Controller } from '@hotwired/stimulus';

/**
 * Offline-first results entry console for competition rounds.
 *
 * Every mutation is written to an IndexedDB outbox BEFORE the network call and
 * replayed in order until the server confirms. Result ids are generated
 * client-side (UUID v7) so replays are idempotent. When online, the console
 * subscribes to the round's Mercure topic and merges rows entered by other
 * devices; local unsynced edits always win for their own rows.
 */

const DB_NAME = 'msp-results-console';
const DB_VERSION = 1;
const OUTBOX_STORE = 'outbox';
const SNAPSHOT_STORE = 'snapshots';

function uuidv7() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    const timestamp = BigInt(Date.now());
    bytes[0] = Number((timestamp >> 40n) & 0xffn);
    bytes[1] = Number((timestamp >> 32n) & 0xffn);
    bytes[2] = Number((timestamp >> 24n) & 0xffn);
    bytes[3] = Number((timestamp >> 16n) & 0xffn);
    bytes[4] = Number((timestamp >> 8n) & 0xffn);
    bytes[5] = Number(timestamp & 0xffn);
    bytes[6] = (bytes[6] & 0x0f) | 0x70;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(OUTBOX_STORE)) {
                const outbox = db.createObjectStore(OUTBOX_STORE, { keyPath: 'seq', autoIncrement: true });
                outbox.createIndex('roundId', 'roundId');
            }
            if (!db.objectStoreNames.contains(SNAPSHOT_STORE)) {
                db.createObjectStore(SNAPSHOT_STORE, { keyPath: 'roundId' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function idbRequest(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

export default class extends Controller {
    static values = {
        roundId: String,
        category: String,
        stateUrl: String,
        upsertUrl: String,
        deleteUrl: String,
        publishUrl: String,
        mercureUrl: String,
        topic: String,
        published: Boolean,
    };

    static targets = [
        'tbody', 'syncStatus', 'offlineBanner', 'publishButton', 'publishState',
        'notifyWrapper', 'notifyCheckbox',
        'quickName', 'quickHours', 'quickMinutes', 'quickSeconds', 'quickMissing',
    ];

    async connect() {
        this.labels = { ...this.element.dataset };
        this.results = new Map();
        this.entrants = [];
        this.entrantResultIds = new Map();
        this.pendingResultIds = new Set();
        this.flushing = false;
        this.retryDelay = 2000;
        this.retryTimer = null;
        this.errorCount = 0;

        this.onOnline = () => { this.updateSyncStatus(); this.flush(); };
        this.onOffline = () => this.updateSyncStatus();
        window.addEventListener('online', this.onOnline);
        window.addEventListener('offline', this.onOffline);

        try {
            this.db = await openDatabase();
        } catch (e) {
            this.db = null;
        }

        await this.restoreSnapshot();
        await this.reloadPendingFlags();
        this.render();

        await this.fetchState();
        this.subscribeMercure();
        this.flush();
    }

    disconnect() {
        window.removeEventListener('online', this.onOnline);
        window.removeEventListener('offline', this.onOffline);
        if (this.eventSource) {
            this.eventSource.close();
        }
        if (this.retryTimer) {
            clearTimeout(this.retryTimer);
        }
    }

    // ---------------------------------------------------------------- state

    async fetchState() {
        try {
            const response = await fetch(this.stateUrlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const state = await response.json();
            this.applyServerState(state);
            await this.saveSnapshot(state);
            this.render();
        } catch (e) {
            // Offline — snapshot + outbox keep the console usable
            this.updateSyncStatus();
        }
    }

    applyServerState(state) {
        this.publishedValue = state.round.resultsPublished;

        const serverResults = new Map();
        for (const row of state.results) {
            serverResults.set(row.resultId, row);
        }

        // Local rows with pending ops win over server state
        for (const [resultId, row] of this.results) {
            if (this.pendingResultIds.has(resultId) && !serverResults.has(resultId)) {
                serverResults.set(resultId, row);
            } else if (this.pendingResultIds.has(resultId)) {
                serverResults.set(resultId, row);
            }
        }

        this.results = serverResults;
        this.entrants = state.entrantsWithoutResult.filter((entrant) => {
            return ![...this.results.values()].some(
                (row) => row.participantId === entrant.id || row.teamId === entrant.id,
            );
        });
        this.updatePublishUi();
    }

    async saveSnapshot(state) {
        if (!this.db) return;
        try {
            const tx = this.db.transaction(SNAPSHOT_STORE, 'readwrite');
            tx.objectStore(SNAPSHOT_STORE).put({ roundId: this.roundIdValue, state, savedAt: Date.now() });
        } catch (e) { /* snapshot is best-effort */ }
    }

    async restoreSnapshot() {
        if (!this.db) return;
        try {
            const tx = this.db.transaction(SNAPSHOT_STORE, 'readonly');
            const snapshot = await idbRequest(tx.objectStore(SNAPSHOT_STORE).get(this.roundIdValue));
            if (snapshot && snapshot.state) {
                this.applyServerState(snapshot.state);
            }
        } catch (e) { /* no snapshot */ }
    }

    // --------------------------------------------------------------- outbox

    async enqueue(op) {
        op.roundId = this.roundIdValue;
        if (this.db) {
            const tx = this.db.transaction(OUTBOX_STORE, 'readwrite');
            await idbRequest(tx.objectStore(OUTBOX_STORE).add(op));
        } else {
            this.memoryOutbox = this.memoryOutbox || [];
            this.memoryOutbox.push(op);
        }
        if (op.payload && op.payload.resultId) {
            this.pendingResultIds.add(op.payload.resultId);
        }
        this.updateSyncStatus();
        this.flush();
    }

    async peekOutbox() {
        if (this.db) {
            const tx = this.db.transaction(OUTBOX_STORE, 'readonly');
            const all = await idbRequest(tx.objectStore(OUTBOX_STORE).index('roundId').getAll(this.roundIdValue));
            return all.sort((a, b) => a.seq - b.seq);
        }
        return this.memoryOutbox || [];
    }

    async removeFromOutbox(op) {
        if (this.db && op.seq !== undefined) {
            const tx = this.db.transaction(OUTBOX_STORE, 'readwrite');
            await idbRequest(tx.objectStore(OUTBOX_STORE).delete(op.seq));
        } else if (this.memoryOutbox) {
            this.memoryOutbox = this.memoryOutbox.filter((item) => item !== op);
        }
    }

    async reloadPendingFlags() {
        const ops = await this.peekOutbox();
        for (const op of ops) {
            if (op.payload && op.payload.resultId) {
                this.pendingResultIds.add(op.payload.resultId);
            }
            // Re-apply optimistic outbox state on top of the snapshot
            if (op.type === 'upsert') {
                this.applyLocalUpsert(op.payload, false);
            } else if (op.type === 'delete') {
                this.results.delete(op.payload.resultId);
            }
        }
    }

    async flush() {
        if (this.flushing || !navigator.onLine) {
            this.updateSyncStatus();
            return;
        }
        this.flushing = true;

        try {
            let ops = await this.peekOutbox();

            while (ops.length > 0) {
                const op = ops[0];
                const url = op.type === 'delete' ? this.deleteUrlValue : this.upsertUrlValue;

                let response;
                try {
                    response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                        body: JSON.stringify(op.payload),
                    });
                } catch (networkError) {
                    this.scheduleRetry();
                    return;
                }

                if (response.ok) {
                    await this.removeFromOutbox(op);
                    if (op.payload && op.payload.resultId) {
                        this.pendingResultIds.delete(op.payload.resultId);
                    }
                    this.retryDelay = 2000;
                    this.errorCount = 0;
                } else if (response.status >= 400 && response.status < 500) {
                    // Permanent rejection — drop the op and surface the error
                    await this.removeFromOutbox(op);
                    if (op.payload && op.payload.resultId) {
                        this.pendingResultIds.delete(op.payload.resultId);
                    }
                    this.errorCount++;
                } else {
                    this.scheduleRetry();
                    return;
                }

                ops = await this.peekOutbox();
            }

            this.updateSyncStatus();
        } finally {
            this.flushing = false;
            this.updateSyncStatus();
        }
    }

    scheduleRetry() {
        this.flushing = false;
        this.updateSyncStatus();
        if (this.retryTimer) {
            clearTimeout(this.retryTimer);
        }
        this.retryTimer = setTimeout(() => this.flush(), this.retryDelay);
        this.retryDelay = Math.min(this.retryDelay * 2, 30000);
    }

    async updateSyncStatus() {
        if (!this.hasSyncStatusTarget) return;
        const pending = (await this.peekOutbox()).length;
        const pill = this.syncStatusTarget;

        if (this.errorCount > 0) {
            pill.className = 'badge rounded-pill bg-danger';
            pill.textContent = this.labels.labelError;
        } else if (!navigator.onLine) {
            pill.className = 'badge rounded-pill bg-secondary';
            pill.textContent = `${this.labels.labelOffline} (${pending})`;
        } else if (pending > 0) {
            pill.className = 'badge rounded-pill bg-warning text-dark';
            pill.textContent = `${this.labels.labelPending} (${pending})`;
        } else {
            pill.className = 'badge rounded-pill bg-success';
            pill.textContent = this.labels.labelSynced;
        }

        if (this.hasOfflineBannerTarget) {
            this.offlineBannerTarget.classList.toggle('d-none', navigator.onLine);
        }
        if (this.hasPublishButtonTarget) {
            this.publishButtonTarget.disabled = !navigator.onLine;
        }
    }

    // -------------------------------------------------------------- mercure

    subscribeMercure() {
        if (!this.mercureUrlValue || !this.topicValue) return;
        try {
            const url = new URL(this.mercureUrlValue);
            url.searchParams.append('topic', this.topicValue);
            this.eventSource = new EventSource(url);
            this.eventSource.onmessage = (event) => this.onMercureMessage(event);
        } catch (e) { /* live sync unavailable — polling on reconnect still works */ }
    }

    onMercureMessage(event) {
        let payload;
        try {
            payload = JSON.parse(event.data);
        } catch (e) {
            return;
        }

        if (payload.type === 'result_changed' && payload.result) {
            const row = payload.result;
            // Local unsynced edits win for their own rows
            if (this.pendingResultIds.has(row.resultId)) {
                return;
            }
            const existing = this.results.get(row.resultId) || { members: [] };
            this.results.set(row.resultId, { ...existing, ...row });
            this.entrants = this.entrants.filter(
                (entrant) => entrant.id !== row.participantId && entrant.id !== row.teamId,
            );
            this.render();
        } else if (payload.type === 'result_deleted') {
            if (this.pendingResultIds.has(payload.resultId)) {
                return;
            }
            this.results.delete(payload.resultId);
            this.render();
        } else if (payload.type === 'publication_changed') {
            this.publishedValue = payload.published;
            this.updatePublishUi();
        }
    }

    // -------------------------------------------------------------- actions

    quickAdd() {
        const name = this.quickNameTarget.value.trim();
        if (name === '') {
            this.quickNameTarget.focus();
            return;
        }

        const seconds = this.readTimeInputs(this.quickHoursTarget, this.quickMinutesTarget, this.quickSecondsTarget);
        const missing = this.readIntInput(this.quickMissingTarget);
        const resultId = uuidv7();

        const payload = {
            resultId,
            entrantName: name,
            participantId: null,
            teamId: null,
            secondsToSolve: seconds,
            missingPieces: missing,
        };

        this.applyLocalUpsert(payload, true);
        this.enqueue({ type: 'upsert', payload });

        this.quickNameTarget.value = '';
        this.quickHoursTarget.value = '';
        this.quickMinutesTarget.value = '';
        this.quickSecondsTarget.value = '';
        this.quickMissingTarget.value = '';
        this.quickNameTarget.focus();
    }

    onRowChange(event) {
        const tr = event.target.closest('tr');
        if (!tr) return;

        const entrantKey = tr.dataset.entrantKey;
        const seconds = this.readTimeInputs(
            tr.querySelector('[data-role="hours"]'),
            tr.querySelector('[data-role="minutes"]'),
            tr.querySelector('[data-role="seconds"]'),
        );
        const missing = this.readIntInput(tr.querySelector('[data-role="missing"]'));

        let resultId = tr.dataset.resultId;
        let participantId = tr.dataset.participantId || null;
        let teamId = tr.dataset.teamId || null;
        let entrantName = tr.dataset.entrantName || null;

        if (!resultId) {
            // First value for an entrant without result — allocate a stable id once
            resultId = this.entrantResultIds.get(entrantKey) || uuidv7();
            this.entrantResultIds.set(entrantKey, resultId);
        }

        if (seconds === null && missing === null) {
            // Nothing entered (or cleared): delete existing result, else ignore
            if (this.results.has(resultId)) {
                this.results.delete(resultId);
                this.enqueue({ type: 'delete', payload: { resultId } });
                this.render();
            }
            return;
        }

        const payload = {
            resultId,
            participantId,
            teamId,
            entrantName,
            secondsToSolve: seconds,
            missingPieces: missing,
        };

        this.applyLocalUpsert(payload, true);
        this.enqueue({ type: 'upsert', payload });
    }

    deleteRow(event) {
        const tr = event.target.closest('tr');
        if (!tr || !tr.dataset.resultId) return;

        if (!window.confirm(this.labels.labelConfirmDelete)) {
            return;
        }

        const resultId = tr.dataset.resultId;
        const row = this.results.get(resultId);
        this.results.delete(resultId);

        // Deleting a result puts a known entrant back into the no-result list
        if (row && (row.participantId || row.teamId)) {
            this.entrants.push({
                id: row.participantId || row.teamId,
                name: row.entrantName,
                type: row.participantId ? 'participant' : 'team',
            });
        }

        this.enqueue({ type: 'delete', payload: { resultId } });
        this.render();
    }

    async togglePublish() {
        if (!navigator.onLine) return;

        const publish = !this.publishedValue;

        try {
            const response = await fetch(this.publishUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    published: publish,
                    notifyParticipants: this.hasNotifyCheckboxTarget ? this.notifyCheckboxTarget.checked : true,
                }),
            });
            if (response.ok) {
                this.publishedValue = publish;
                this.updatePublishUi();
            }
        } catch (e) {
            this.updateSyncStatus();
        }
    }

    autoAdvance(event) {
        const input = event.target;
        if (input.value.length >= 2) {
            const inputs = [this.quickHoursTarget, this.quickMinutesTarget, this.quickSecondsTarget];
            const index = inputs.indexOf(input);
            if (index >= 0 && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
        }
    }

    // ------------------------------------------------------------ rendering

    applyLocalUpsert(payload, moveEntrant) {
        const existing = this.results.get(payload.resultId) || { members: [] };
        this.results.set(payload.resultId, {
            ...existing,
            resultId: payload.resultId,
            participantId: payload.participantId || existing.participantId || null,
            teamId: payload.teamId || existing.teamId || null,
            entrantName: payload.entrantName || existing.entrantName,
            secondsToSolve: payload.secondsToSolve,
            missingPieces: payload.missingPieces,
        });

        if (moveEntrant) {
            this.entrants = this.entrants.filter(
                (entrant) => entrant.id !== payload.participantId && entrant.id !== payload.teamId,
            );
            this.render();
        }
    }

    rankedResults() {
        const rows = [...this.results.values()];

        rows.sort((a, b) => {
            const aFinished = a.secondsToSolve !== null && a.secondsToSolve !== undefined;
            const bFinished = b.secondsToSolve !== null && b.secondsToSolve !== undefined;
            if (aFinished !== bFinished) return aFinished ? -1 : 1;
            if (aFinished) return a.secondsToSolve - b.secondsToSolve;

            const aMissing = a.missingPieces ?? Infinity;
            const bMissing = b.missingPieces ?? Infinity;
            if (aMissing !== bMissing) return aMissing - bMissing;
            return (a.entrantName || '').localeCompare(b.entrantName || '');
        });

        let rank = 0;
        let lastKey = null;
        rows.forEach((row, index) => {
            const key = `${row.secondsToSolve ?? 'dnf'}:${row.missingPieces ?? 'x'}`;
            if (key !== lastKey) {
                rank = index + 1;
                lastKey = key;
            }
            row.rank = rank;
        });

        return rows;
    }

    render() {
        if (!this.hasTbodyTarget) return;

        const tbody = this.tbodyTarget;
        tbody.textContent = '';

        // Entrants without a result first — at the venue these are the rows to fill
        for (const entrant of this.entrants) {
            tbody.appendChild(this.buildRow({
                resultId: null,
                entrantKey: `entrant:${entrant.id}`,
                entrantName: entrant.name,
                participantId: entrant.type === 'participant' ? entrant.id : null,
                teamId: entrant.type === 'team' ? entrant.id : null,
                secondsToSolve: null,
                missingPieces: null,
                members: [],
                noResult: true,
            }));
        }

        for (const row of this.rankedResults()) {
            tbody.appendChild(this.buildRow({ ...row, entrantKey: row.resultId, noResult: false }));
        }

        if (tbody.children.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 5;
            td.className = 'text-center text-muted py-4';
            td.textContent = this.labels.labelNoResultYet;
            tr.appendChild(td);
            tbody.appendChild(tr);
        }
    }

    buildRow(row) {
        const tr = document.createElement('tr');
        tr.dataset.entrantKey = row.entrantKey;
        if (row.resultId) tr.dataset.resultId = row.resultId;
        if (row.participantId) tr.dataset.participantId = row.participantId;
        if (row.teamId) tr.dataset.teamId = row.teamId;
        if (row.entrantName) tr.dataset.entrantName = row.entrantName;
        if (row.noResult) tr.className = 'table-light';

        // Rank
        const rankTd = document.createElement('td');
        rankTd.className = 'fw-bold';
        if (row.noResult) {
            rankTd.textContent = '—';
            rankTd.className = 'text-muted';
        } else if (row.secondsToSolve === null || row.secondsToSolve === undefined) {
            rankTd.textContent = `${row.rank}.`;
            rankTd.className = 'text-muted';
        } else {
            rankTd.textContent = `${row.rank}.`;
            if (row.rank <= 3) rankTd.classList.add('text-warning');
        }
        tr.appendChild(rankTd);

        // Name + members + pending dot
        const nameTd = document.createElement('td');
        const nameSpan = document.createElement('span');
        nameSpan.textContent = row.entrantName || '—';
        nameTd.appendChild(nameSpan);

        if (row.resultId && this.pendingResultIds.has(row.resultId)) {
            const dot = document.createElement('span');
            dot.className = 'ms-2 text-warning';
            dot.title = this.labels.labelPending;
            dot.textContent = '●';
            nameTd.appendChild(dot);
        }

        if (row.members && row.members.length > 0) {
            const membersDiv = document.createElement('div');
            membersDiv.className = 'small text-muted';
            membersDiv.textContent = row.members.map((m) => m.playerName || m.name).join(', ');
            nameTd.appendChild(membersDiv);
        }
        tr.appendChild(nameTd);

        // Time inputs
        const timeTd = document.createElement('td');
        const timeGroup = document.createElement('div');
        timeGroup.className = 'input-group input-group-sm';

        const total = row.secondsToSolve;
        const parts = total !== null && total !== undefined
            ? [Math.floor(total / 3600), Math.floor((total % 3600) / 60), total % 60]
            : ['', '', ''];

        ['hours', 'minutes', 'seconds'].forEach((role, index) => {
            if (index > 0) {
                const sep = document.createElement('span');
                sep.className = 'input-group-text px-1';
                sep.textContent = ':';
                timeGroup.appendChild(sep);
            }
            const input = document.createElement('input');
            input.type = 'text';
            input.inputMode = 'numeric';
            input.maxLength = index === 0 ? 2 : 2;
            input.className = 'form-control text-center';
            input.dataset.role = role;
            input.placeholder = index === 0 ? 'h' : index === 1 ? 'mm' : 'ss';
            input.value = parts[index] === '' ? '' : String(parts[index]).padStart(2, '0');
            input.addEventListener('change', (event) => this.onRowChange(event));
            timeGroup.appendChild(input);
        });

        timeTd.appendChild(timeGroup);
        tr.appendChild(timeTd);

        // Missing pieces
        const missingTd = document.createElement('td');
        const missingInput = document.createElement('input');
        missingInput.type = 'number';
        missingInput.min = '0';
        missingInput.inputMode = 'numeric';
        missingInput.className = 'form-control form-control-sm';
        missingInput.dataset.role = 'missing';
        missingInput.value = row.missingPieces ?? '';
        missingInput.addEventListener('change', (event) => this.onRowChange(event));
        missingTd.appendChild(missingInput);
        tr.appendChild(missingTd);

        // Actions + DNF badge
        const actionsTd = document.createElement('td');
        actionsTd.className = 'text-nowrap text-end';

        if (!row.noResult && (row.secondsToSolve === null || row.secondsToSolve === undefined)) {
            const dnf = document.createElement('span');
            dnf.className = 'badge bg-secondary me-1';
            dnf.textContent = this.labels.labelDnf;
            actionsTd.appendChild(dnf);
        }

        if (row.resultId) {
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-sm btn-outline-danger py-0 px-1';
            del.title = this.labels.labelDelete;
            del.innerHTML = '<i class="bi bi-trash"></i>';
            del.addEventListener('click', (event) => this.deleteRow(event));
            actionsTd.appendChild(del);
        }
        tr.appendChild(actionsTd);

        return tr;
    }

    updatePublishUi() {
        if (this.hasPublishStateTarget) {
            const badge = this.publishStateTarget.querySelector('.badge');
            if (badge) {
                badge.className = this.publishedValue ? 'badge bg-success' : 'badge bg-secondary';
                badge.textContent = this.publishedValue
                    ? this.publishStateTarget.dataset.labelPublished || badge.textContent
                    : this.publishStateTarget.dataset.labelDraft || badge.textContent;
            }
        }
        if (this.hasPublishButtonTarget) {
            const button = this.publishButtonTarget;
            button.textContent = this.publishedValue
                ? button.dataset.labelUnpublish || button.textContent
                : button.dataset.labelPublish || button.textContent;
        }
        if (this.hasNotifyWrapperTarget) {
            this.notifyWrapperTarget.classList.toggle('d-none', this.publishedValue);
        }
    }

    // -------------------------------------------------------------- helpers

    readTimeInputs(hoursInput, minutesInput, secondsInput) {
        const h = this.readIntInput(hoursInput);
        const m = this.readIntInput(minutesInput);
        const s = this.readIntInput(secondsInput);

        if (h === null && m === null && s === null) {
            return null;
        }

        return (h ?? 0) * 3600 + (m ?? 0) * 60 + (s ?? 0);
    }

    readIntInput(input) {
        if (!input) return null;
        const value = String(input.value).trim();
        if (value === '') return null;
        const parsed = parseInt(value, 10);
        return Number.isNaN(parsed) ? null : parsed;
    }
}
