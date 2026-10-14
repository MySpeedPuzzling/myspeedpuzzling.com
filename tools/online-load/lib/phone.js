/**
 * One participant's phone, as the participant page behaves (assets/controllers/compete_controller.js and the modules
 * in assets/online/ - every cadence and rule below is theirs, named the same):
 * - opens the personal link (the page, its embedded state), syncs the clock (8 × /-/time, 150 ms apart, the
 *   fastest sample wins - clock_sync.js), sends consent + device.ready, takes the empty-mat photo;
 * - a heartbeat every 15 s; a heartbeat whose state hash differs fetches the state;
 * - periodic photos every 30 s, every 10 s in the last 3 minutes before GO (compete_flow.js periodicGapMs());
 * - the start: the state fetched 2 s before and 1 s after GO with 4 clock samples each, a full clock sync when a start
 *   is learned, countdown.seen, start.seen and the start photo at GO;
 * - the Finished tap: finish.button + a photo + the ±5 s burst; some take it back within 10 s (finish.button.undone)
 *   and tap again later;
 * - time's up while still solving: the end photo at once, timeup.pieces once the pieces are counted;
 * - events through an outbox (event_outbox.js: batches of 50, acknowledged ones leave), photos through the media outbox
 *   (media_outbox.js: presign → PUT straight to the store → media.uploaded; critical items first, periodic newest
 *   first; the same backoffs and the same answers to a store's failures - `previousFailure` on the next presign).
 *
 * The SSE stream of the same phone runs in its own VU (subscriber.js): k6's SSE call holds its VU while connected.
 */

import http from 'k6/http';
import { sleep } from 'k6';
import * as metrics from './metrics.js';

// compete_controller.js / compete_flow.js / round_state.js / clock_sync.js
const HEARTBEAT_MS = 15000;
const PERIODIC_EVERY_MS = 30000;
const PERIODIC_UNWRAP_EVERY_MS = 10000;
const UNWRAP_WINDOW_MS = 3 * 60 * 1000;
const PERIODIC_LEAD_MS = 30 * 60 * 1000;
const COUNTDOWN_MS = 10000;
const CHECK_BEFORE_START_MS = 2000;
const CHECK_AFTER_MS = 1000;
const START_PHOTO_WITHIN_MS = 3000;
const UNDO_WINDOW_MS = 10000;
const SLOW_POLL_MS = 60000;
const SYNC_SAMPLES = 8;
const SYNC_GAP_MS = 150;
const CLOCK_WINDOW = 16;
// event_outbox.js / media_outbox.js
const BATCH_SIZE = 50;
const OFFLINE_BACKOFF_MS = [1000, 2000, 4000, 8000, 15000, 30000];
const SERVER_BACKOFF_MS = [5000, 15000, 30000, 60000, 120000];
const BUSY_BACKOFF_MS = [5000, 15000, 30000, 60000, 120000];
const MAX_RETRY_AFTER_MS = 15 * 60 * 1000;
const EXPIRY_MARGIN_MS = 15000;
const MAX_PRESIGNS_IN_A_ROW = 5;
// compete_api.js REQUEST_TIMEOUT_MS; an upload has no timeout of its own on the phone - the browser's
const API_TIMEOUT = '15s';
const PUT_TIMEOUT = '120s';
// Requests this close to GO count as the start burst (report: phase `start`)
export const START_WINDOW_MS = 3000;

const CRITICAL_KINDS = ['empty_mat', 'start', 'candidate', 'button', 'burst', 'end'];

function backoff(steps, attempt) {
    return steps[Math.min(Math.max(attempt, 1), steps.length) - 1];
}

function hex(digits) {
    let out = '';

    for (let i = 0; i < digits; i++) {
        out += Math.floor(Math.random() * 16).toString(16);
    }

    return out;
}

/**
 * A UUIDv7 for the moment (the phones' own event ids)
 */
export function uuidv7(ms) {
    const time = Math.floor(ms).toString(16).padStart(12, '0');
    const variant = (8 + Math.floor(Math.random() * 4)).toString(16);

    return `${time.slice(0, 8)}-${time.slice(8, 12)}-7${hex(3)}-${variant}${hex(3)}-${hex(12)}`;
}

function retryAfterMs(res) {
    const value = res.headers['Retry-After'] ?? res.headers['retry-after'];
    const seconds = Number(value);

    return Number.isFinite(seconds) && seconds > 0 ? Math.min(seconds * 1000, MAX_RETRY_AFTER_MS) : 0;
}

/**
 * The round clock of a state (round_state.js readClock / deriveView)
 */
export function viewOf(state, serverNowMs) {
    const round = state?.round ?? null;
    const startedAtMs = Number.isFinite(round?.startedAtMs) ? round.startedAtMs : null;
    const stoppedAtMs = Number.isFinite(round?.stoppedAtMs) ? round.stoppedAtMs : null;
    const endsAtMs = Number.isFinite(round?.endsAtMs)
        ? round.endsAtMs
        : (startedAtMs !== null && Number.isFinite(round?.minutesLimit) ? startedAtMs + round.minutesLimit * 60000 : null);

    if (startedAtMs === null) {
        return { screen: 'waiting', startedAtMs: null, countdownMs: null, elapsedMs: 0, endsAtMs: null };
    }

    let endedAtMs = null;

    if (stoppedAtMs !== null) {
        endedAtMs = endsAtMs !== null && endsAtMs <= stoppedAtMs ? endsAtMs : stoppedAtMs;
    } else if (endsAtMs !== null && serverNowMs >= endsAtMs) {
        endedAtMs = endsAtMs;
    }

    if (endedAtMs !== null) {
        return { screen: 'ended', startedAtMs, countdownMs: 0, elapsedMs: Math.max(0, endedAtMs - startedAtMs), endsAtMs };
    }

    if (serverNowMs < startedAtMs) {
        const countdownMs = startedAtMs - serverNowMs;

        return { screen: countdownMs <= COUNTDOWN_MS ? 'countdown' : 'scheduled', startedAtMs, countdownMs, elapsedMs: 0, endsAtMs };
    }

    return { screen: 'running', startedAtMs, countdownMs: 0, elapsedMs: serverNowMs - startedAtMs, endsAtMs };
}

/**
 * How often periodic photos are taken in this view (compete_flow.js periodicGapMs()) - null: none
 */
export function periodicGapMs(view) {
    switch (view.screen) {
        case 'waiting':
        case 'running':
            return PERIODIC_EVERY_MS;
        case 'scheduled':
        case 'countdown':
            if (!Number.isFinite(view.countdownMs) || view.countdownMs > PERIODIC_LEAD_MS) {
                return null;
            }

            return view.countdownMs <= UNWRAP_WINDOW_MS ? PERIODIC_UNWRAP_EVERY_MS : PERIODIC_EVERY_MS;
        default:
            return null;
    }
}

export class Phone {
    /**
     * @param {object} options
     * @param {number} options.index          0-based participant number
     * @param {string} options.token
     * @param {object} options.config         participants.js config()
     * @param {object} options.media          {photo: ArrayBuffer, burst: ArrayBuffer, burstFrames: [{offset, length}]}
     */
    constructor({ index, token, config, media }) {
        this.index = index;
        this.token = token;
        this.config = config;
        this.media = media;
        this.base = config.baseUrl;
        this.api = `${config.baseUrl}/api/compete/${token}`;
        this.deviceId = `load-${index + 1}-${hex(8)}`;
        this.headers = {
            'User-Agent': `msp-online-load/1 (k6; participant ${index + 1})`,
            Accept: 'application/json',
        };

        if (config.spoofIps) {
            // Every phone its own client IP (local stack only - the app trusts private proxies; 198.18.0.0/15 is the
            // benchmarking range). Through Traefik this header is replaced by the real address.
            this.headers['X-Forwarded-For'] = `198.18.${Math.floor(index / 250)}.${(index % 250) + 1}`;
        }

        // The clock: offset = server - local, from the fastest of the recent samples
        this.samples = [];
        this.offsetMs = 0;
        this.rttMs = null;

        this.state = null;
        this.knownStart = null;
        this.checksDone = {};
        this.marks = {};
        this.sawRunning = null;
        this.nextBeatAt = 0;
        this.lastPeriodicMs = null;
        this.nextSlowPollAt = 0;

        // The finish plan: a share of the participants tap Finished (participants.js decides who and when)
        this.finishPlan = config.finishPlan(index);
        this.finishAt = null;
        this.tapped = null;
        this.undoAt = null;
        this.retapAt = null;
        this.timeupAt = null;
        this.endPhotoTaken = false;
        this.finishedFor = null;

        // event_outbox.js
        this.events = [];
        this.eventsRetryAt = 0;
        this.eventFailures = 0;

        // media_outbox.js
        this.items = [];
        this.mediaRetryAt = 0;
        this.offlineAttempts = 0;
        this.storeFailures = 0;
        this.busyAttempts = 0;
        this.serverFailures = 0;
        this.sequence = 0;
        this.firstPutFailureRecorded = false;
        this.firstSecondaryRecorded = false;
        this.lastPrimaryUploadMs = null;
        this.pendingTaps = {};
    }

    // ---- requests

    serverNow() {
        return Date.now() + this.offsetMs;
    }

    phase() {
        if (this.joining) {
            return 'join';
        }

        const view = viewOf(this.state, this.serverNow());

        if (view.startedAtMs !== null && Math.abs(this.serverNow() - view.startedAtMs) <= START_WINDOW_MS) {
            return 'start';
        }

        if (this.config.inFinishWave(view, this.serverNow())) {
            return 'finish';
        }

        switch (view.screen) {
            case 'running':
                return 'running';
            case 'ended':
                return 'ended';
            default:
                return 'waiting';
        }
    }

    /**
     * One request with its metrics; JSON answers are read, their serverNowMs is a clock sample
     *
     * @returns {{status: number, data: object|null, res: object, sample: object|null}}
     */
    call(endpoint, method, url, body = null, { json = true, timeout = API_TIMEOUT, headers = {} } = {}) {
        const phase = this.phase();
        const params = {
            headers: { ...this.headers, ...headers },
            tags: { endpoint, phase },
            timeout,
            redirects: 0,
            responseType: endpoint === 'put' ? 'none' : 'text',
        };

        if (json && body !== null) {
            params.headers['Content-Type'] = 'application/json';
        }

        const w0 = Date.now();
        const res = http.request(method, url, json && body !== null ? JSON.stringify(body) : body, params);
        const w1 = Date.now();

        metrics.reqDuration.add(res.timings.duration, { endpoint, phase });
        metrics.reqCount.add(1, { endpoint, sc: metrics.statusClass(res.status) });

        let data = null;

        if (endpoint !== 'put' && typeof res.body === 'string' && String(res.headers['Content-Type'] ?? '').includes('json')) {
            try {
                data = JSON.parse(res.body);
            } catch (e) {
                data = null;
            }
        }

        const sample = data !== null && Number.isFinite(data.serverNowMs) ? this.observe(w0, w1, data.serverNowMs) : null;

        if (data !== null && typeof data.error === 'string') {
            metrics.apiError.add(1, { code: metrics.ERROR_CODES.includes(data.error) ? data.error : 'other' });
        }

        return { status: res.status, data, res, sample };
    }

    observe(w0, w1, serverNowMs) {
        const rtt = Math.max(0, w1 - w0);
        this.samples.push({ rtt, offset: serverNowMs - (w0 + rtt / 2) });

        if (this.samples.length > CLOCK_WINDOW) {
            this.samples.shift();
        }

        const best = this.samples.reduce((a, b) => (b.rtt < a.rtt ? b : a));
        this.offsetMs = best.offset;
        this.rttMs = best.rtt;

        return this.samples[this.samples.length - 1];
    }

    sync(samples = SYNC_SAMPLES) {
        let best = null;

        for (let i = 0; i < samples; i++) {
            if (i > 0) {
                sleep(SYNC_GAP_MS / 1000);
            }

            const { sample } = this.call('time', 'GET', `${this.base}/-/time`, null, { json: false });

            if (sample !== null && (best === null || sample.rtt < best)) {
                best = sample.rtt;
            }
        }

        if (best !== null) {
            metrics.clockBestRtt.add(best);
        }
    }

    adopt(state) {
        if (state === null || typeof state !== 'object' || !state.round) {
            return;
        }

        // The newest by asOfMs wins (round_state.js adoptState)
        if (this.state !== null && Number.isFinite(this.state.asOfMs) && Number.isFinite(state.asOfMs) && state.asOfMs < this.state.asOfMs) {
            return;
        }

        this.state = state;
    }

    fetchState() {
        const answer = this.call('state', 'GET', `${this.api}/state`);

        if (answer.status === 200 && answer.data) {
            this.adopt(answer.data);
        }
    }

    openPage() {
        const answer = this.call('page', 'GET', `${this.base}/en/compete/${this.token}`, null, { json: false, headers: { Accept: 'text/html' } });
        const html = typeof answer.res.body === 'string' ? answer.res.body : '';
        const match = html.match(/<script type="application\/json" data-compete-target="initialState">([\s\S]*?)<\/script>/);

        if (match) {
            try {
                this.adopt(JSON.parse(match[1]));
            } catch (e) {
                // The page without its state - the next fetch brings it
            }
        }
    }

    // ---- the phone's day

    run(endAt) {
        this.joining = true;

        if (this.config.page) {
            this.openPage();
        }

        this.sync();

        if (this.state === null) {
            this.fetchState();
        }

        this.joining = false;

        if (this.state?.round && this.state?.entry) {
            this.record('consent.given', {});
            this.record('device.ready', {
                takeover: false,
                userAgent: this.headers['User-Agent'],
                platform: 'k6',
                standalone: false,
                inApp: null,
                screen: { width: 390, height: 844, pixelRatio: 3 },
                camera: { ok: true, facing: 'user', pick: 'default', w: 1920, h: 1080, fps: 30 },
                clock: { offsetMs: this.offsetMs, rttMs: this.rttMs, errorMs: this.rttMs === null ? null : this.rttMs / 2 },
                wakeLock: 'active',
            });
            this.enqueueMedia('empty_mat', this.media.photo);
        }

        // The page's heartbeat timer starts with the page
        this.nextBeatAt = Date.now() + HEARTBEAT_MS;

        while (Date.now() < endAt) {
            this.tick();

            const wait = Math.min(this.nextDue() - Date.now(), 1000, endAt - Date.now());

            if (wait > 0) {
                sleep(wait / 1000);
            }
        }

        metrics.outboxLeftEvents.add(this.events.length);
        metrics.outboxLeftMedia.add(this.items.filter((item) => item.state === 'pending').length);

        if (this.lastPrimaryUploadMs !== null) {
            metrics.lastPrimaryUploadAt.add(this.lastPrimaryUploadMs);
        }
    }

    tick() {
        const now = Date.now();
        const view = viewOf(this.state, this.serverNow());
        const entered = Boolean(this.state?.round && this.state?.entry);

        if (!entered) {
            // No round or not entered: the state once a minute, nothing else
            if (now >= this.nextSlowPollAt) {
                this.nextSlowPollAt = now + SLOW_POLL_MS;
                this.fetchState();
            }

            return;
        }

        this.learnStart(view);
        this.plannedStateChecks();

        const fresh = viewOf(this.state, this.serverNow());
        this.startEffects(fresh);
        this.finishEffects(fresh);
        this.endEffects(fresh);

        if (Date.now() >= this.nextBeatAt) {
            this.nextBeatAt = Date.now() + HEARTBEAT_MS;
            this.beat(fresh);
        }

        const gap = periodicGapMs(fresh);

        if (gap !== null && (this.lastPeriodicMs === null || this.serverNow() >= this.lastPeriodicMs + gap)) {
            this.lastPeriodicMs = this.serverNow();
            this.enqueueMedia('periodic', this.media.photo);
        }

        if (this.events.length > 0 && Date.now() >= this.eventsRetryAt) {
            this.flushEvents();
        }

        this.processMedia();
    }

    nextDue() {
        const now = Date.now();
        const candidates = [this.nextBeatAt, now + 1000];
        const view = viewOf(this.state, this.serverNow());
        const toLocal = (serverMs) => serverMs - this.offsetMs;

        if (view.startedAtMs !== null) {
            for (const at of [view.startedAtMs - COUNTDOWN_MS, view.startedAtMs - CHECK_BEFORE_START_MS, view.startedAtMs, view.startedAtMs + CHECK_AFTER_MS]) {
                if (toLocal(at) > now) {
                    candidates.push(toLocal(at));
                }
            }
        }

        const gap = periodicGapMs(view);

        if (gap !== null && this.lastPeriodicMs !== null) {
            candidates.push(toLocal(this.lastPeriodicMs + gap));
        }

        for (const at of [this.finishAt, this.undoAt, this.retapAt, this.timeupAt]) {
            if (at !== null && toLocal(at) > now) {
                candidates.push(toLocal(at));
            }
        }

        if (this.events.length > 0) {
            candidates.push(Math.max(now, this.eventsRetryAt));
        }

        const pending = this.items.filter((item) => item.state === 'pending');

        if (pending.length > 0) {
            candidates.push(Math.max(this.mediaRetryAt, Math.min(...pending.map((item) => item.nextAttemptAt))));
        }

        return Math.max(now, Math.min(...candidates));
    }

    learnStart(view) {
        if (view.startedAtMs === null || view.startedAtMs === this.knownStart) {
            return;
        }

        this.knownStart = view.startedAtMs;
        metrics.startLearnedBefore.add(view.startedAtMs - this.serverNow());
        // A start learned: the clock is checked now (compete_controller.js afterStateChange)
        this.sync();

        if (this.finishPlan !== null) {
            this.finishAt = this.config.finishAt(this.finishPlan, view.startedAtMs);
        }
    }

    plannedStateChecks() {
        const view = viewOf(this.state, this.serverNow());

        if (view.startedAtMs === null || this.state.round.stoppedAtMs) {
            return;
        }

        // round_state.js nextStateCheckAt(): 2 s before GO, 1 s after it, 1 s after the time limit
        const moments = [view.startedAtMs - CHECK_BEFORE_START_MS, view.startedAtMs + CHECK_AFTER_MS];

        if (view.endsAtMs !== null) {
            moments.push(view.endsAtMs + CHECK_AFTER_MS);
        }

        for (const moment of moments) {
            const key = `${view.startedAtMs}:${moment}`;

            if (!this.checksDone[key] && this.serverNow() >= moment) {
                this.checksDone[key] = true;
                this.fetchState();
                this.sync(4);
            }
        }
    }

    once(mark, action) {
        if (!this.marks[mark]) {
            this.marks[mark] = true;
            action();
        }
    }

    startEffects(view) {
        if (view.screen === 'countdown') {
            this.once(`countdown:${view.startedAtMs}`, () => this.record('countdown.seen', {
                countdownMs: Math.round(view.countdownMs),
                startedAtMs: view.startedAtMs,
            }));
        }

        if (view.screen === 'running') {
            this.sawRunning = view.startedAtMs;
            this.once(`start:${view.startedAtMs}`, () => {
                metrics.startSeen.add(1);
                metrics.startSeenLate.add(view.elapsedMs);
                this.record('start.seen', { lateMs: Math.round(view.elapsedMs), startedAtMs: view.startedAtMs });

                if (view.elapsedMs < START_PHOTO_WITHIN_MS) {
                    this.enqueueMedia('start', this.media.photo);
                }
            });
        }
    }

    finishEffects(view) {
        if (view.screen !== 'running') {
            return;
        }

        const now = this.serverNow();

        if (this.finishAt !== null && now >= this.finishAt) {
            this.finishAt = null;
            this.tap();

            if (this.finishPlan.undo) {
                // "I tapped by mistake" a few seconds later, Finished again half a minute after that
                this.undoAt = now + 3000 + Math.random() * (UNDO_WINDOW_MS - 5000);
            }
        }

        if (this.undoAt !== null && now >= this.undoAt) {
            this.undoAt = null;
            this.record('finish.button.undone', { eventId: this.tapped.eventId });
            this.tapped = null;
            this.retapAt = now + 30000;
        }

        if (this.retapAt !== null && now >= this.retapAt) {
            this.retapAt = null;
            this.tap();
        }
    }

    tap() {
        const momentMs = this.serverNow();
        const eventId = uuidv7(momentMs);
        this.record('finish.button', {}, eventId, momentMs);
        this.tapped = { eventId, momentMs };
        this.finishedFor = this.knownStart;
        metrics.finishes.add(1);
        this.pendingTaps[eventId] = { momentMs, left: 2 };
        // A full-resolution photo at once, and the frames from 5 s before to 5 s after the tap (frame_buffer.js)
        this.enqueueMedia('button', this.media.photo, { eventId });
        this.enqueueMedia('burst', this.media.burst, { eventId, takenMs: momentMs, manifest: this.burstManifest(momentMs) });
    }

    burstManifest(momentMs) {
        const frames = this.media.burstFrames;
        const step = 10000 / Math.max(1, frames.length);

        return frames.map((frame, i) => ({ offset: frame.offset, length: frame.length, captureMs: Math.round(momentMs - 5000 + i * step) }));
    }

    endEffects(view) {
        if (view.screen !== 'ended' || this.sawRunning === null || this.sawRunning !== view.startedAtMs) {
            return;
        }

        const finished = this.finishedFor === view.startedAtMs && this.tapped !== null;

        this.once(`end:${view.startedAtMs}`, () => {
            if (!finished) {
                metrics.timeups.add(1);
                this.enqueueMedia('end', this.media.photo);
                // The participant counts the pieces and types them in
                this.timeupAt = this.serverNow() + 10000 + Math.random() * 20000;
            }
        });

        if (this.timeupAt !== null && this.serverNow() >= this.timeupAt) {
            this.timeupAt = null;
            this.record('timeup.pieces', { measure: 'left', pieces: 1 + Math.floor(Math.random() * 120) });
        }
    }

    beat(view) {
        const answer = this.call('heartbeat', 'POST', `${this.api}/heartbeat`, {
            deviceId: this.deviceId,
            roundId: this.state.round.id,
            visible: true,
            camera: { ok: true, w: 1920, h: 1080, fps: 30, facing: 'user' },
            detector: { ok: false, fps: null, mode: this.state.round.settings?.detection ?? 'off' },
            clock: { offsetMs: this.offsetMs, rttMs: this.rttMs },
            outbox: { events: this.events.length, media: this.items.filter((item) => item.state === 'pending').length },
            ready: true,
            screen: view.screen,
        });

        if (answer.status !== 200 || !answer.data) {
            return;
        }

        const otherRound = typeof answer.data.roundId === 'string' && answer.data.roundId.toLowerCase() !== String(this.state.round.id).toLowerCase();
        const otherHash = typeof answer.data.stateHash === 'string' && answer.data.stateHash !== this.state.round.stateHash;

        if (otherRound || otherHash) {
            this.fetchState();
        }
    }

    // ---- the event outbox

    record(type, data, id = null, atMs = null) {
        const clientMs = Math.round(atMs ?? this.serverNow());

        this.events.push({
            id: id ?? uuidv7(clientMs),
            type,
            roundId: this.state.round.id,
            clientMs,
            perfMs: Math.round(clientMs - this.offsetMs) % 100000000,
            offsetMs: this.offsetMs,
            rttMs: this.rttMs,
            data,
        });

        // Sent by the next tick's flush - events of one moment travel in one batch, as from the outbox
    }

    flushEvents() {
        const batch = this.events.slice(0, BATCH_SIZE);
        const answer = this.call('events', 'POST', `${this.api}/events`, { deviceId: this.deviceId, events: batch });
        const now = Date.now();

        if (answer.status === 200 && answer.data) {
            const done = new Set([...(answer.data.acks ?? []), ...(answer.data.refused ?? []).map((refusal) => refusal.id)]);
            metrics.eventsAcked.add((answer.data.acks ?? []).length);

            for (const refusal of answer.data.refused ?? []) {
                metrics.eventRefused.add(1, { reason: metrics.REFUSAL_REASONS.includes(refusal.reason) ? refusal.reason : 'other' });
            }

            this.events = this.events.filter((event) => !done.has(event.id));
            this.eventFailures = 0;
            this.acknowledged(done);

            return;
        }

        this.eventFailures += 1;

        if (answer.status === 0) {
            this.eventsRetryAt = now + backoff(OFFLINE_BACKOFF_MS, this.eventFailures);
        } else if (answer.status === 429 || answer.status === 503) {
            this.eventsRetryAt = now + Math.max(retryAfterMs(answer.res), backoff(BUSY_BACKOFF_MS, this.eventFailures));
        } else if (answer.status >= 500) {
            this.eventsRetryAt = now + backoff(SERVER_BACKOFF_MS, this.eventFailures);
        } else {
            // The batch itself refused (invalid_fields, too_many_events …): dropped, as the outbox sets it aside
            this.events = this.events.slice(batch.length);
        }
    }

    /**
     * A tap's evidence is complete once both of its media.uploaded events were acknowledged
     */
    acknowledged(ids) {
        for (const id of ids) {
            const tap = this.uploadedEvents?.[id];

            if (tap === undefined) {
                continue;
            }

            delete this.uploadedEvents[id];
            const pending = this.pendingTaps[tap];

            if (pending !== undefined && --pending.left === 0) {
                metrics.finishEvidence.add(this.serverNow() - pending.momentMs);
                delete this.pendingTaps[tap];
            }
        }
    }

    // ---- the media outbox

    enqueueMedia(kind, body, { eventId = null, takenMs = null, manifest = null } = {}) {
        const now = Date.now();

        this.items.push({
            id: ++this.sequence,
            kind,
            critical: CRITICAL_KINDS.includes(kind),
            body,
            bytes: body.byteLength,
            contentType: kind === 'burst' ? 'application/octet-stream' : 'image/jpeg',
            takenMs: Math.round(takenMs ?? this.serverNow()),
            eventId,
            manifest,
            state: 'pending',
            stage: 'queued',
            attempts: 0,
            nextAttemptAt: now,
            presignsInARow: 0,
            lastFailure: null,
            failedBefore: false,
        });
    }

    /**
     * media_outbox.js nextItem(): critical first (oldest first), periodic after them (newest first); items that failed
     * before only after all of those
     */
    nextItem(now) {
        const ready = this.items.filter((item) => item.state === 'pending' && item.nextAttemptAt <= now);

        if (ready.length === 0) {
            return null;
        }

        const rank = (item) => (item.attempts > 0 ? 3 : 0) + (item.stage === 'uploaded' ? 0 : (item.critical ? 1 : 2));
        ready.sort((a, b) => rank(a) - rank(b)
            || (rank(a) % 3 === 2 ? b.takenMs - a.takenMs : a.takenMs - b.takenMs)
            || a.id - b.id);

        return ready[0];
    }

    processMedia() {
        // One item per tick: the loop comes back at once while more are ready
        const now = Date.now();

        if (now < this.mediaRetryAt) {
            return;
        }

        const item = this.nextItem(now);

        if (item === null) {
            return;
        }

        if (item.stage === 'presigned' && item.validUntil !== null && item.validUntil - now < EXPIRY_MARGIN_MS) {
            this.dropPresign(item);
        }

        if (item.stage === 'queued' && !this.presign(item)) {
            return;
        }

        if (item.stage === 'presigned' && !this.upload(item)) {
            return;
        }

        if (item.stage === 'uploaded') {
            this.report(item);
        }
    }

    presign(item) {
        const answer = this.call('media', 'POST', `${this.api}/media`, {
            deviceId: this.deviceId,
            roundId: this.state.round.id,
            kind: item.kind,
            takenMs: item.takenMs,
            bytes: item.bytes,
            contentType: item.contentType,
            relatedEventId: item.eventId,
            previousFailure: item.lastFailure,
        });
        const now = Date.now();

        if (answer.status === 0) {
            this.goOffline(now);

            return false;
        }

        this.offlineAttempts = 0;

        if (answer.status === 429 || (answer.status >= 400 && answer.status < 500 && answer.data?.error === undefined)) {
            this.busyAttempts += 1;
            const retryAfter = retryAfterMs(answer.res);
            this.mediaRetryAt = now + (retryAfter > 0 ? retryAfter : backoff(BUSY_BACKOFF_MS, this.busyAttempts));

            return false;
        }

        this.busyAttempts = 0;

        if (answer.status >= 400 && answer.status < 500) {
            // The item can not be taken (or the link died): set aside, as the outbox does
            item.state = 'refused';

            return false;
        }

        const data = answer.status === 200 ? answer.data : null;

        if (!data || typeof data.url !== 'string') {
            this.serverFailures += 1;
            const retryAfter = retryAfterMs(answer.res);
            this.mediaRetryAt = now + Math.max(backoff(SERVER_BACKOFF_MS, this.serverFailures), retryAfter);
            item.attempts += 1;
            item.failedBefore = true;
            item.nextAttemptAt = now + backoff(SERVER_BACKOFF_MS, item.attempts);

            return false;
        }

        this.serverFailures = 0;
        item.stage = 'presigned';
        item.mediaId = String(data.mediaId);
        item.store = typeof data.store === 'string' ? data.store : null;
        item.url = data.url;
        item.method = typeof data.method === 'string' ? data.method.toUpperCase() : 'PUT';
        item.putHeaders = Object.fromEntries(Object.entries(data.headers ?? {})
            .filter(([name]) => !['content-length', 'host'].includes(name.toLowerCase()))
            .map(([name, value]) => [name, String(value)]));
        item.validUntil = Number.isFinite(data.expiresAtMs) && Number.isFinite(data.serverNowMs) ? now + (data.expiresAtMs - data.serverNowMs) : null;
        item.presignsInARow += 1;
        metrics.presigns.add(1, { store: item.store ?? 'unknown' });

        return true;
    }

    upload(item) {
        if (item.failedBefore) {
            metrics.putRetries.add(1);
        }

        const answer = this.call('put', item.method, item.url, item.body, { json: false, timeout: PUT_TIMEOUT, headers: item.putHeaders });
        const now = Date.now();
        const status = answer.status;
        const store = item.store ?? 'unknown';

        if (status >= 200 && status < 300) {
            this.offlineAttempts = 0;
            this.storeFailures = 0;
            item.stage = 'uploaded';
            item.attempts = 0;
            item.presignsInARow = 0;
            item.lastFailure = null;
            metrics.uploads.add(1, { store, kind: item.kind });
            metrics.uploadBytes.add(item.bytes, { store });

            if (store === 'secondary' && !this.firstSecondaryRecorded) {
                this.firstSecondaryRecorded = true;
                metrics.firstSecondaryUploadAt.add(Date.now());
            }

            if (store === 'primary') {
                this.lastPrimaryUploadMs = Date.now();
            }

            return true;
        }

        metrics.putFailures.add(1, { store });
        item.failedBefore = true;

        if (!this.firstPutFailureRecorded) {
            this.firstPutFailureRecorded = true;
            metrics.firstPutFailureAt.add(Date.now());
        }

        if (status === 0) {
            // No answer from the store (media_outbox.js unreachable()): the offline pause, and - the phone's browser
            // being online - the failure kept for the next presign, a fresh URL from the second failure on
            this.goOffline(now);
            item.lastFailure = { status: 0, store: item.store, mediaId: item.mediaId };
            item.attempts += 1;

            if (item.attempts >= 2) {
                this.dropPresign(item);
            }

            return false;
        }

        item.lastFailure = { status, store: item.store, mediaId: item.mediaId };

        if (status === 408 || status === 429 || status >= 500) {
            this.storeFailures += 1;
            item.attempts += 1;
            const retryAfter = retryAfterMs(answer.res);
            item.nextAttemptAt = now + Math.max(backoff(SERVER_BACKOFF_MS, item.attempts), retryAfter);
            this.mediaRetryAt = now + Math.max(backoff(SERVER_BACKOFF_MS, this.storeFailures), retryAfter);

            if (item.attempts >= 2) {
                this.dropPresign(item);
            }

            return false;
        }

        // 400 / 403 / other 4xx: a fresh URL at once, unless fresh ones keep being refused
        this.dropPresign(item);

        if (item.presignsInARow >= MAX_PRESIGNS_IN_A_ROW) {
            item.attempts += 1;
            item.nextAttemptAt = now + backoff(SERVER_BACKOFF_MS, item.attempts);
        }

        return false;
    }

    report(item) {
        const data = { mediaId: item.mediaId, bytes: item.bytes, kind: item.kind };

        if (Array.isArray(item.manifest) && item.manifest.length > 0) {
            data.manifest = item.manifest;
        }

        const clientMs = Math.round(this.serverNow());
        const eventId = uuidv7(clientMs);

        if (item.eventId !== null && this.pendingTaps[item.eventId] !== undefined) {
            this.uploadedEvents = this.uploadedEvents ?? {};
            this.uploadedEvents[eventId] = item.eventId;
        }

        this.record('media.uploaded', data, eventId, clientMs);
        // The bytes are in the store; the event outbox carries the report from here
        item.state = 'done';
        item.body = null;
    }

    dropPresign(item) {
        item.stage = 'queued';
        item.mediaId = null;
        item.store = null;
        item.url = null;
        item.putHeaders = null;
        item.validUntil = null;
    }

    goOffline(now) {
        this.offlineAttempts += 1;
        this.mediaRetryAt = now + backoff(OFFLINE_BACKOFF_MS, this.offlineAttempts);
    }
}
