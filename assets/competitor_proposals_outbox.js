/**
 * The competitor's time proposals on their phone (competition toolkit B8, toolkit/B-qr-competitor-page.md §5.8,
 * docs/features/competitions-management/competitor-pages.md "Propose my time"): a "Stop" tap is written to the phone
 * first (IndexedDB, its own database) and only then sent - bad venue Wi-Fi, a closed tab or a reload never lose it.
 *
 * - An item is keyed by the proposal's id - a UUIDv7 made on the phone at the tap (the server's primary key: a resend
 *   is answered `known`, never stored twice). The tap moment (`tappedAtMs`, the server's time read synchronously in
 *   the tap handler) and the id never change, however often or late the item is sent.
 * - Endpoints (P1, TimeProposalApi): `POST /{_locale}/competitor-proposals/rounds/{roundId}` and
 *   `POST /{_locale}/competitor-proposals/{proposalId}/withdraw`, JSON, the stateless CSRF token `time_proposal` in
 *   `X-CSRF-Token`. Answers `{status: proposed|known|withdrawn|refused, reason, message, proposal}`.
 * - proposed / known / withdrawn → gone from the phone, `onAnswer(roundId, answer)`. A refusal (`times_up`,
 *   `linked_after_start`, `already_proposed` …, also the 404s) → gone, `onAnswer` with the reason and the translated
 *   message - shown plainly, never retried.
 * - Offline: again after 1 s, 2 s, 4 s … at most 30 s, at once when the browser says it is back (connectionBack()).
 *   A server error or a busy server (429 rate limit, a proxy page): 5 s, 15 s … 2 min, or its Retry-After.
 *   Signed out (401, a redirect) or a stale page (403 `invalid_csrf_token`): nothing is sent until the page is loaded
 *   again - the items stay.
 * - Undo (withdraw()): a tap still on the phone is simply dropped (the server never hears of it); one being sent is
 *   withdrawn right after its answer; a sent one gets a withdraw item, sent like a tap.
 * - One tab sends at a time (createTabLock); another signed-in player's items on the phone are never sent.
 * - Timing evidence (reliability hardening T2, competitor-pages.md "Timing you can trust"): every send says how the tap
 *   travelled - proposalBody(): the clock sample's age at the tap, `queuedOffline` (the phone knew it was offline at the
 *   tap, or an earlier send failed), `attempts` (this one included) and `sentAtMs` (the server's time at this send).
 *   outboxSummary() is what the page's heartbeat reports: the round's taps still on the phone (any tab - read from the
 *   storage) and the oldest one's tap moment, so a heartbeat never counts against a tap the phone is still trying.
 *
 * Storage, the network, the clock, the timers and the lock are injected - tests/competitor-proposals-harness.mjs.
 */

import {
    backoff,
    BUSY_BACKOFF_MS,
    createMemoryStorage,
    createNamedIndexedDbStorage,
    createTabLock,
    MAX_RETRY_AFTER_MS,
    OFFLINE_BACKOFF_MS,
    SERVER_BACKOFF_MS,
    uuidv7,
} from './online/event_outbox.js';

export { createMemoryStorage, createTabLock, uuidv7 };

export const PROPOSALS_DATABASE = 'msp_competitor_proposals';
export const PROPOSALS_LOCK = 'msp-competitor-proposals';
export const LOCK_RETRY_MS = 2000;
const ANSWERED = ['proposed', 'known', 'withdrawn'];

/**
 * The phone's store (null where IndexedDB can not be opened - then createMemoryStorage()).
 */
export function createProposalsStorage(factory = globalThis.indexedDB) {
    return createNamedIndexedDbStorage(PROPOSALS_DATABASE, factory);
}

/**
 * Sends one request to the proposal endpoints and says what it means for the item - never rejects:
 * {kind: 'ok', data} | {kind: 'refused', reason, message, proposal} | {kind: 'auth'} | {kind: 'csrf'} |
 * {kind: 'busy', retryAfterMs} | {kind: 'server'} | {kind: 'offline'}.
 */
export async function proposalRequest(url, body, csrfToken, fetchFn = globalThis.fetch) {
    let response;

    try {
        response = await fetchFn(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            redirect: 'manual',
            cache: 'no-store',
            body: JSON.stringify(body),
        });
    } catch {
        return { kind: 'offline' };
    }

    return classifyAnswer(response.status, response.type, await readJson(response), response.headers?.get?.('Retry-After') ?? null);
}

async function readJson(response) {
    const type = response.headers?.get?.('Content-Type') ?? '';

    if (!type.includes('json')) {
        return null;
    }

    try {
        return await response.json();
    } catch {
        return null;
    }
}

/**
 * What an HTTP answer of the proposal endpoints means (pure - pinned by the harness).
 */
export function classifyAnswer(status, type, data, retryAfter = null) {
    if (type === 'opaqueredirect' || (status >= 300 && status < 400) || status === 401) {
        return { kind: 'auth' };
    }

    if (status === 429 || status === 408 || status === 425) {
        return { kind: 'busy', retryAfterMs: retryAfterMs(retryAfter) };
    }

    if (status >= 500 || data === null || typeof data !== 'object') {
        return status >= 500 ? { kind: 'server' } : { kind: 'busy', retryAfterMs: retryAfterMs(retryAfter) };
    }

    if (data.error === 'invalid_csrf_token') {
        return { kind: 'csrf' };
    }

    if (status >= 200 && status < 300 && ANSWERED.includes(data.status)) {
        return { kind: 'ok', data };
    }

    if (data.status === 'refused' || typeof data.error === 'string') {
        return {
            kind: 'refused',
            reason: typeof data.reason === 'string' ? data.reason : (typeof data.error === 'string' ? data.error : 'refused'),
            message: typeof data.message === 'string' ? data.message : null,
            proposal: data.proposal && typeof data.proposal === 'object' ? data.proposal : null,
        };
    }

    return { kind: 'busy', retryAfterMs: retryAfterMs(retryAfter) };
}

function retryAfterMs(header) {
    if (typeof header !== 'string' || !/^\d+$/.test(header.trim())) {
        return null;
    }

    return Math.min(MAX_RETRY_AFTER_MS, parseInt(header.trim(), 10) * 1000);
}

/**
 * The JSON a propose item is sent with (T2): the tap as stored, never changed, plus how it travelled.
 */
export function proposalBody(item, sentAtMs) {
    const attempts = Number.isInteger(item.attempts) ? item.attempts : 0;

    return {
        proposalId: item.id,
        entry: item.entry,
        tappedAtMs: Math.round(item.tappedAtMs),
        offsetMs: item.offsetMs ?? null,
        rttMs: item.rttMs ?? null,
        stateHash: item.stateHash ?? null,
        offsetSampleAgeMs: typeof item.offsetSampleAgeMs === 'number' && Number.isFinite(item.offsetSampleAgeMs) ? Math.round(item.offsetSampleAgeMs) : null,
        queuedOffline: item.queuedOffline === true || item.offline === true || attempts > 0,
        attempts: attempts + 1,
        sentAtMs: typeof sentAtMs === 'number' && Number.isFinite(sentAtMs) ? Math.round(sentAtMs) : null,
    };
}

/**
 * What the heartbeat tells the server about the round's taps on this phone (T2): how many proposals still wait to be
 * sent - plus one still in the confirm sheet (`confirmingTapMs`) - and the oldest tap moment of them. Withdraw items are
 * no taps. Pure.
 *
 * @returns {{unsent: number, oldestUnsentTapMs: null|number}}
 */
export function outboxSummary(items, userId, roundId, confirmingTapMs = null) {
    const user = String(userId ?? '').toLowerCase();
    const round = String(roundId ?? '').toLowerCase();
    const taps = (Array.isArray(items) ? items : [])
        .filter((item) => item && item.action === 'propose' && item.userId === user && String(item.roundId ?? '').toLowerCase() === round)
        .map((item) => item.tappedAtMs)
        .filter((at) => typeof at === 'number' && Number.isFinite(at));

    if (typeof confirmingTapMs === 'number' && Number.isFinite(confirmingTapMs)) {
        taps.push(confirmingTapMs);
    }

    return { unsent: taps.length, oldestUnsentTapMs: taps.length === 0 ? null : Math.round(Math.min(...taps)) };
}

/**
 * @typedef {object} ProposalItem
 * @property {string} id            the proposal's UUIDv7 (made at the tap)
 * @property {'propose'|'withdraw'} action
 * @property {string} userId        the signed-in player who tapped
 * @property {string} roundId
 * @property {string} [entry]       "participant_round:<id>" / "team:<id>"
 * @property {number} [tappedAtMs]  the server's time at the tap - never changed
 * @property {null|number} [offsetMs]
 * @property {null|number} [rttMs]
 * @property {null|string} [stateHash]
 * @property {null|number} [offsetSampleAgeMs] the age of the clock sample at the tap (T2)
 * @property {boolean} [queuedOffline] the browser said it was offline at the tap (T2)
 * @property {number} createdAt
 * @property {number} attempts
 * @property {number} nextAttemptAt
 * @property {boolean} offline      the last attempt never reached the server
 * @property {boolean} [withdrawAfter] Undo while it was being sent
 */
export class ProposalOutbox {
    /**
     * @param {object} options
     * @param {object} options.storage                         createProposalsStorage() / createMemoryStorage()
     * @param {function(ProposalItem): Promise<object>} options.send  one request → what classifyAnswer() says
     * @param {string} options.userId
     * @param {function(string, object): void} [options.onAnswer] (roundId, {kind, action, proposal, reason, message})
     * @param {function(): void} [options.onChange]            items or their sending state changed
     * @param {function(function(): Promise): Promise<boolean>} [options.lock]
     * @param {function(): number} [options.now]
     * @param {function(function(), number): *} [options.schedule]
     * @param {function(*): void} [options.cancel]
     */
    constructor({
        storage,
        send,
        userId,
        onAnswer = () => {},
        onChange = () => {},
        lock = null,
        now = () => Date.now(),
        schedule = (task, ms) => setTimeout(task, ms),
        cancel = (timer) => clearTimeout(timer),
    }) {
        this.storage = storage;
        this.sendFn = send;
        this.userId = String(userId ?? '').toLowerCase();
        this.onAnswer = onAnswer;
        this.onChange = onChange;
        this.lock = lock ?? (async (task) => {
            await task();

            return true;
        });
        this.now = now;
        this.scheduleFn = schedule;
        this.cancelFn = cancel;
        this.items = new Map();
        this.inFlight = null;
        this.blocked = null;
        this.timer = null;
        this.running = null;
        this.again = false;
        this.closed = false;
    }

    async load() {
        const items = await this.storage.getAll();
        this.items = new Map(items.filter((item) => item && typeof item.id === 'string').map((item) => [item.id, item]));
        this.onChange();
        this.kick();
    }

    close() {
        this.closed = true;

        if (this.timer !== null) {
            this.cancelFn(this.timer);
            this.timer = null;
        }
    }

    own() {
        return [...this.items.values()]
            .filter((item) => item.userId === this.userId)
            .sort((a, b) => (a.createdAt - b.createdAt) || a.id.localeCompare(b.id));
    }

    /**
     * The round's item still on the phone (the newest), or null.
     */
    itemOf(roundId) {
        const items = this.own().filter((item) => item.roundId.toLowerCase() === String(roundId).toLowerCase());

        return items.length === 0 ? null : items[items.length - 1];
    }

    isSending(id) {
        return this.inFlight === id;
    }

    hasUnsent() {
        return this.own().length > 0;
    }

    /**
     * A tap: stored first, then sent. The id is made from the tap moment unless given (tests).
     */
    async propose({ roundId, entry, tappedAtMs, offsetMs = null, rttMs = null, stateHash = null, id = null, offsetSampleAgeMs = null, offline = false }) {
        const item = {
            id: id ?? uuidv7(tappedAtMs),
            action: 'propose',
            userId: this.userId,
            roundId,
            entry,
            tappedAtMs,
            offsetMs: typeof offsetMs === 'number' && Number.isFinite(offsetMs) ? offsetMs : null,
            rttMs: typeof rttMs === 'number' && Number.isFinite(rttMs) ? rttMs : null,
            stateHash: typeof stateHash === 'string' ? stateHash : null,
            offsetSampleAgeMs: typeof offsetSampleAgeMs === 'number' && Number.isFinite(offsetSampleAgeMs) ? offsetSampleAgeMs : null,
            queuedOffline: offline === true,
            createdAt: this.now(),
            attempts: 0,
            nextAttemptAt: 0,
            offline: false,
        };
        await this.put(item);
        this.kick();

        return item;
    }

    /**
     * Undo of the proposal `id` of the round.
     */
    async withdraw(id, roundId) {
        const item = this.items.get(id);

        if (item !== undefined && item.action === 'propose') {
            if (this.inFlight === id) {
                await this.put({ ...item, withdrawAfter: true });

                return;
            }

            // Never sent: the server never hears of it
            await this.remove(id);
            this.onAnswer(roundId, { kind: 'withdrawn', action: 'withdraw', local: true, proposal: null, proposalId: id });

            return;
        }

        if (item !== undefined) {
            return;
        }

        await this.put({ id, action: 'withdraw', userId: this.userId, roundId, createdAt: this.now(), attempts: 0, nextAttemptAt: 0, offline: false });
        this.kick();
    }

    /**
     * The browser is back online (or a state fetch went through): what waited only for being offline goes now.
     */
    connectionBack() {
        let changed = false;

        for (const item of this.own()) {
            if (item.offline && item.nextAttemptAt > this.now()) {
                this.items.set(item.id, { ...item, nextAttemptAt: 0 });
                changed = true;
            }
        }

        if (changed) {
            this.kick(0);
        }
    }

    async put(item) {
        this.items.set(item.id, item);
        await this.storage.put(item);
        this.onChange();
    }

    async remove(id) {
        this.items.delete(id);
        await this.storage.delete(id);
        this.onChange();
    }

    /**
     * Plans the next run at the earliest item's moment (now with `delay = 0`).
     */
    kick(delay = null) {
        if (this.closed || this.blocked !== null) {
            return;
        }

        const items = this.own();

        if (this.timer !== null) {
            this.cancelFn(this.timer);
            this.timer = null;
        }

        if (items.length === 0) {
            return;
        }

        const wait = delay ?? Math.max(0, Math.min(...items.map((item) => item.nextAttemptAt)) - this.now());
        this.timer = this.scheduleFn(() => {
            this.timer = null;
            this.run();
        }, wait);
    }

    /**
     * Sends what is due - one run at a time; a call while one runs runs once more after it.
     */
    run() {
        if (this.closed) {
            return Promise.resolve();
        }

        if (this.running !== null) {
            this.again = true;

            return this.running;
        }

        let held = true;
        this.running = (async () => {
            held = await this.lock(() => this.sendDue());
        })().catch(() => {}).finally(() => {
            this.running = null;

            if (this.again) {
                this.again = false;
                this.run();
            } else if (held) {
                this.kick();
            } else {
                // Another tab sends - look again shortly (it may not have seen this tap)
                this.kick(LOCK_RETRY_MS);
            }
        });

        return this.running;
    }

    async sendDue() {
        for (const item of this.own()) {
            if (this.closed || this.blocked !== null) {
                return;
            }

            // Undone meanwhile, or not due yet
            if (!this.items.has(item.id) || item.nextAttemptAt > this.now()) {
                continue;
            }

            this.inFlight = item.id;
            this.onChange();
            let answer;

            try {
                answer = await this.sendFn(item);
            } catch {
                answer = { kind: 'offline' };
            }

            this.inFlight = null;
            const current = this.items.get(item.id) ?? item;

            if (answer?.kind === 'ok') {
                await this.remove(item.id);

                if (current.withdrawAfter && current.action === 'propose' && answer.data?.status !== 'withdrawn') {
                    await this.put({ id: item.id, action: 'withdraw', userId: this.userId, roundId: item.roundId, createdAt: this.now(), attempts: 0, nextAttemptAt: 0, offline: false });
                }

                this.onAnswer(item.roundId, { kind: answer.data.status, action: item.action, proposal: answer.data.proposal ?? null, proposalId: item.id });
            } else if (answer?.kind === 'refused') {
                await this.remove(item.id);
                this.onAnswer(item.roundId, { kind: 'refused', action: item.action, reason: answer.reason, message: answer.message, proposal: answer.proposal ?? null, proposalId: item.id });
            } else if (answer?.kind === 'auth' || answer?.kind === 'csrf') {
                // Signed out or a stale page: everything stays on the phone until the page is loaded again
                this.blocked = answer.kind;
                this.onChange();

                return;
            } else if (answer?.kind === 'offline') {
                const attempts = current.attempts + 1;
                await this.put({ ...current, attempts, offline: true, nextAttemptAt: this.now() + backoff(OFFLINE_BACKOFF_MS, attempts) });

                // The others would not get through either
                return;
            } else {
                const attempts = current.attempts + 1;
                const pause = Math.max(answer?.retryAfterMs ?? 0, backoff(answer?.kind === 'busy' ? BUSY_BACKOFF_MS : SERVER_BACKOFF_MS, attempts));
                await this.put({ ...current, attempts, offline: false, nextAttemptAt: this.now() + pause });
            }
        }
    }
}
