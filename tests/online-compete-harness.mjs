// Runs the participant page's decisions - assets/online/compete_flow.js (screens, the checklist, periodic photos, the
// Finished tap's undo, pieces, practice, what the device remembers) and assets/online/compete_api.js (the participant
// API's requests: clock samples, answers, no cookies) - through their scenarios and prints {scenario: "ok" | error} as
// JSON. tests/OnlineCompeteScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    answerReach,
    buildEvent,
    cameraWanted,
    CHECK_CODES,
    checklistRows,
    connectionStatus,
    clockText,
    countdownText,
    currentFinish,
    deviceIdFrom,
    DEVICE_KEY,
    emptyProgress,
    fill,
    GO_SHOWN_MS,
    goneSince,
    loadConsent,
    loadProgress,
    markAlive,
    memoryStorage,
    nextDisplayChangeAt,
    nextPeriodicPhotoAt,
    periodicGapMs,
    practiceAllowed,
    practiceStartAt,
    practiceState,
    readiness,
    readPieces,
    RELOAD_DECIDE_WITHIN_MS,
    RELOAD_GONE_MAX_MS,
    reloadReported,
    runKeyOf,
    saveConsent,
    saveProgress,
    SCREENS,
    screenFor,
    shortClock,
    TAKEOVER_GRACE_MS,
    takeoverVerdict,
    undoAccepted,
    UNDO_GUARD_MS,
    undoLeftMs,
    UNDO_WINDOW_MS,
    WAKE_SCREENS,
    wakeTapNeeded,
    wakeTapText,
} from '../assets/online/compete_flow.js';
import { createCompeteApi, isDeadLink, readCompeteAnswer } from '../assets/online/compete_api.js';
import { deriveView } from '../assets/online/round_state.js';

const ROUND = '018d0050-0000-0000-0000-000000000101';
const PARTICIPANT = '018d0050-0000-0000-0000-000000000202';
const START = 1_792_000_000_000;
const MINUTE = 60_000;

/**
 * A participant state (implementation-plan.md §3.1).
 */
function state({ startedAtMs = null, stoppedAtMs = null, minutesLimit = 60, entry = {}, round = {} } = {}) {
    return {
        serverNowMs: START - 100_000,
        asOfMs: START - 100_000,
        participant: { id: PARTICIPANT, name: 'Arthur Anonymous', connectedPlayer: null },
        competition: { id: 'c', name: 'Online Test Cup' },
        round: {
            id: ROUND,
            name: 'Online Round',
            minutesLimit,
            piecesTotal: 500,
            status: 'waiting',
            startedAtMs,
            stoppedAtMs,
            endsAtMs: null,
            stateHash: 'h',
            settings: { detection: 'off', video: false, seeEachOther: false, studio: false, unfinishedMeasure: 'left' },
            ...round,
        },
        entry: entry === null ? null : { confirmedMomentMs: null, candidates: 0, taps: 0, reportedPieces: null, review: 'pending', ...entry },
        mercure: null,
    };
}

function progress(overrides = {}) {
    return { ...emptyProgress(), ...overrides };
}

function screenAt(roundState, serverNowMs, { consent = true, ready = true, finishes = [], pieces = null, takenOver = false, linkDead = false } = {}) {
    return screenFor({
        state: roundState,
        view: deriveView(roundState, serverNowMs),
        consent,
        progress: progress({ ready, finishes, pieces }),
        takenOver,
        linkDead,
    });
}

/**
 * A fetch() answer as compete_api.js reads it.
 */
function response(status, body, { type = 'basic', contentType = 'application/json', headers = {} } = {}) {
    const all = { 'content-type': contentType, ...Object.fromEntries(Object.entries(headers).map(([k, v]) => [k.toLowerCase(), v])) };

    return {
        ok: status >= 200 && status < 300,
        status,
        type,
        headers: { get: (name) => all[name.toLowerCase()] ?? null },
        json: async () => (typeof body === 'string' ? JSON.parse(body) : body),
    };
}

const HEARTBEAT_MS = 15_000;

/**
 * Two (or more) devices on one link, heartbeating, claiming it ("I'm ready" / "Use this device instead") - what the
 * page does with takeoverVerdict() (compete_controller.js applyTakeover(), noteClaim(), scheduleRecheck()), on a fake
 * time line in ms. The server holds the device whose claim it stored last; a claim is stored `ackMs` after the tap and
 * the device hears the ack then; a heartbeat is read by the server `rttMs / 2` after it was sent and answered after
 * `rttMs`. Returns every device's state and the moment each one gave way.
 */
function simulateDevices({ devices, claims, untilMs, ackMs = 300, rttMs = 200 }) {
    const state = Object.fromEntries(devices.map(({ id, phaseMs }) => [id, {
        id,
        phaseMs,
        takenOver: false,
        claim: null,
        recheckAfterClaim: false,
        gaveWayAt: [],
    }]));
    const stored = claims.map(({ device, atMs }) => ({ device, storedAt: atMs + ackMs })).sort((a, b) => a.storedAt - b.storedAt);
    const activeAt = (ms) => stored.filter((claim) => claim.storedAt <= ms).at(-1)?.device ?? null;
    const queue = [];
    const push = (atMs, kind, device, extra = {}) => queue.push({ atMs, kind, device, ...extra });

    for (const { id, phaseMs } of devices) {
        for (let at = phaseMs; at <= untilMs; at += HEARTBEAT_MS) {
            push(at, 'beat', id);
        }
    }

    for (const { device, atMs } of claims) {
        push(atMs, 'claim', device);
        push(atMs + ackMs, 'ack', device, { claimAt: atMs });
        // ready() / takeOver() beat right away
        push(atMs, 'beat', device);
    }

    const order = { claim: 0, ack: 1, beat: 2, answer: 3 };

    while (queue.length > 0) {
        queue.sort((a, b) => a.atMs - b.atMs || order[a.kind] - order[b.kind]);
        const step = queue.shift();

        if (step.atMs > untilMs) {
            break;
        }

        const device = state[step.device];

        if (step.kind === 'claim') {
            device.claim = { pending: true, ackedAt: null, claimAt: step.atMs };
            device.takenOver = false;
        } else if (step.kind === 'ack') {
            // noteClaim() - only the latest claim of the device
            if (device.claim !== null && device.claim.claimAt === step.claimAt && device.claim.pending) {
                device.claim = { ...device.claim, pending: false, ackedAt: step.atMs };

                if (device.recheckAfterClaim) {
                    device.recheckAfterClaim = false;
                    push(step.atMs + TAKEOVER_GRACE_MS + 50, 'beat', device.id);
                }
            }
        } else if (step.kind === 'beat') {
            push(step.atMs + rttMs, 'answer', device.id, { sentAt: step.atMs, active: activeAt(step.atMs + rttMs / 2) });
        } else {
            const verdict = takeoverVerdict({
                activeDevice: step.active,
                deviceId: device.id,
                ready: true,
                takenOver: device.takenOver,
                claim: device.claim,
                beatSentAt: step.sentAt,
            });

            if (verdict.takenOver && !device.takenOver) {
                device.gaveWayAt.push(step.atMs);
            }

            device.takenOver = verdict.takenOver;

            if (verdict.recheckAt !== null) {
                push(Math.max(step.atMs, verdict.recheckAt) + 50, 'beat', device.id);
            } else if (verdict.otherDeviceActive && device.claim?.pending) {
                device.recheckAfterClaim = true;
            }
        }
    }

    return state;
}

const scenarios = {
    async 'the screens of a round: welcome → checklist → waiting → countdown → GO → running → finished → after'() {
        const waiting = state();
        const scheduled = state({ startedAtMs: START });

        assert.equal(screenAt(waiting, START - 3_600_000, { consent: false, ready: false }), 'welcome');
        assert.equal(screenAt(waiting, START - 3_600_000, { ready: false }), 'checklist');
        assert.equal(screenAt(waiting, START - 3_600_000), 'waiting');
        assert.equal(screenAt(scheduled, START - 3_600_000), 'waiting', 'a start an hour away: "Starts in …" on the waiting screen');
        assert.equal(screenAt(scheduled, START - 9_000), 'countdown');
        assert.equal(screenAt(scheduled, START), 'countdown', 'GO itself');
        assert.equal(screenAt(scheduled, START + GO_SHOWN_MS - 1), 'countdown', 'GO stays a moment');
        assert.equal(screenAt(scheduled, START + GO_SHOWN_MS), 'running');

        const tap = { eventId: 'e1', momentMs: START + 47 * MINUTE, undone: false, continued: false };
        assert.equal(screenAt(scheduled, START + 47 * MINUTE + 1000, { finishes: [tap] }), 'finished');
        assert.equal(screenAt(scheduled, START + 61 * MINUTE, { finishes: [tap] }), 'after', 'the round ended after the finish');

        for (const screen of ['welcome', 'checklist', 'waiting', 'countdown', 'running', 'finished', 'timeup', 'after', 'no_round', 'not_entered', 'taken_over', 'dead_link']) {
            assert.ok(SCREENS.includes(screen), screen);
        }
    },

    async 'no round, not entered, a dead link - before anything else'() {
        assert.equal(screenAt({ ...state(), round: null }, START), 'no_round');
        assert.equal(screenAt(null, START), 'no_round');
        assert.equal(screenAt(state({ entry: null }), START, { consent: true, ready: true }), 'not_entered');
        assert.equal(screenAt(state(), START, { linkDead: true }), 'dead_link');
        assert.equal(screenAt({ ...state(), round: null }, START, { linkDead: true }), 'dead_link');
    },

    async 'time\'s up: the pieces question only for a page that took part and neither finished nor reported'() {
        const ended = state({ startedAtMs: START, minutesLimit: 30 });
        const after = START + 31 * MINUTE;

        assert.equal(screenAt(ended, after), 'timeup');
        assert.equal(screenAt(ended, after, { pieces: { measure: 'left', pieces: 12 } }), 'after', 'reported on this device');
        assert.equal(screenAt(state({ startedAtMs: START, minutesLimit: 30, entry: { reportedPieces: 12 } }), after), 'after', 'reported on another device');
        assert.equal(screenAt(ended, after, { ready: false }), 'after', 'never got ready here');
        assert.equal(screenAt(ended, after, { consent: false, ready: false }), 'after', 'opened after the round');

        // "End now" by the organiser ends it as well
        const stopped = state({ startedAtMs: START, stoppedAtMs: START + 10 * MINUTE });
        assert.equal(screenAt(stopped, START + 10 * MINUTE + 1), 'timeup');
    },

    async 'a tap the server knows of counts as finished - unless this device went on puzzling after its own'() {
        const running = state({ startedAtMs: START, entry: { taps: 1 } });

        assert.equal(screenAt(running, START + 20 * MINUTE), 'finished', 'tapped on another device (or this one forgot)');

        const continued = { eventId: 'e1', momentMs: START + 10 * MINUTE, undone: false, continued: true };
        assert.equal(screenAt(running, START + 20 * MINUTE, { finishes: [continued] }), 'running', 'kept puzzling: Finished again');

        const undone = { eventId: 'e2', momentMs: START + 10 * MINUTE, undone: true, continued: false };
        assert.equal(screenAt(state({ startedAtMs: START }), START + 20 * MINUTE, { finishes: [undone] }), 'running', 'an undone tap');
        // A state fetched between the tap and its undo still counts it - this device's own record wins
        assert.equal(screenAt(running, START + 20 * MINUTE, { finishes: [undone] }), 'running', 'undone here, still counted in the state held');
        assert.equal(screenAt(state({ startedAtMs: START, minutesLimit: 30, entry: { taps: 1 } }), START + 31 * MINUTE, { finishes: [undone] }), 'timeup', 'and at the end it asks for the pieces');
    },

    async 'taken over: only a device that was ready gives way; one still setting up keeps its checklist'() {
        const waiting = state();

        assert.equal(screenAt(waiting, START, { takenOver: true }), 'taken_over');
        assert.equal(screenAt(waiting, START, { takenOver: true, ready: false }), 'checklist');
        assert.equal(screenAt(waiting, START, { takenOver: true, consent: false, ready: false }), 'welcome');
        // The round's end outranks it
        assert.equal(screenAt(state({ startedAtMs: START, minutesLimit: 1 }), START + 2 * MINUTE, { takenOver: true }), 'timeup');
    },

    async 'the Finished tap: the latest one neither undone nor continued; undo for 10 s on the server clock'() {
        const first = { eventId: 'a', momentMs: START + 1000, undone: true, continued: false };
        const second = { eventId: 'b', momentMs: START + 5000, undone: false, continued: true };
        const third = { eventId: 'c', momentMs: START + 9000, undone: false, continued: false };

        assert.equal(currentFinish(progress({ finishes: [first, second, third] })), third);
        assert.equal(currentFinish(progress({ finishes: [first, second] })), null);
        assert.equal(currentFinish(progress()), null);
        assert.equal(currentFinish(null), null);

        assert.equal(undoLeftMs(third, third.momentMs), UNDO_WINDOW_MS);
        assert.equal(undoLeftMs(third, third.momentMs + 7_400), 2_600);
        assert.equal(undoLeftMs(third, third.momentMs + UNDO_WINDOW_MS), 0);
        assert.equal(undoLeftMs(third, third.momentMs + 60_000), 0);
        assert.equal(undoLeftMs(null, START), 0);
    },

    async '"I tapped by mistake" never takes the click of the Finished tap itself - only a later tap, within the window'() {
        const tap = { eventId: 'c', momentMs: START + 9000, undone: false, continued: false };

        // The phone's click of the same tap, delivered to the button that appeared under the finger (rehearsal 2026-10-14:
        // 50-90 ms after the tap on 375 × 667 and 360 × 740 screens) - and a slow phone's, up to the guard
        assert.equal(undoAccepted(tap, tap.momentMs + 50), false, 'the tap\'s own click');
        assert.equal(undoAccepted(tap, tap.momentMs + 90), false);
        assert.equal(undoAccepted(tap, tap.momentMs + UNDO_GUARD_MS - 1), false, 'a slow phone\'s click of the tap');
        // The participant's own tap on the button
        assert.equal(undoAccepted(tap, tap.momentMs + UNDO_GUARD_MS), true);
        assert.equal(undoAccepted(tap, tap.momentMs + 3_000), true);
        assert.equal(undoAccepted(tap, tap.momentMs + UNDO_WINDOW_MS - 1), true);
        // The window still closes
        assert.equal(undoAccepted(tap, tap.momentMs + UNDO_WINDOW_MS), false);
        assert.ok(UNDO_GUARD_MS < UNDO_WINDOW_MS / 2, 'the guard leaves most of the window');
        // The button is offered all the while - only the tap's own click is not taken
        assert.ok(undoLeftMs(tap, tap.momentMs + 50) > 0);
    },

    async '"I\'m ready" needs the camera on and the clock synced'() {
        assert.deepEqual(readiness({ status: 'live' }, { synced: true }), { cameraOk: true, clockOk: true, canBeReady: true });
        assert.equal(readiness({ status: 'live' }, { synced: false }).canBeReady, false);
        assert.equal(readiness({ status: 'muted' }, { synced: true }).canBeReady, false);
        assert.equal(readiness({ status: 'opening' }, { synced: true }).canBeReady, false);
        assert.equal(readiness(null, null).canBeReady, false);
    },

    async 'the checklist rows: a level and a code each, every code one of CHECK_CODES'() {
        const base = {
            camera: { status: 'live' },
            clock: { synced: true, samples: 8, errorMs: 12, rttMs: 24 },
            clockSyncing: false,
            wakeLock: 'active',
            connection: { online: true, lastOkAgoMs: 2000, failing: false },
            uploads: { pending: 0, refused: 0, uploaded: 1, offline: false, blocked: null },
            emptyMat: true,
        };

        assert.deepEqual(checklistRows(base), {
            camera: { level: 'ok', code: 'live' },
            clock: { level: 'ok', code: 'synced' },
            wake: { level: 'ok', code: 'active' },
            connection: { level: 'ok', code: 'ok' },
            uploads: { level: 'ok', code: 'ok' },
            emptyMat: { level: 'ok', code: 'taken' },
        });

        const rows = checklistRows({
            ...base,
            camera: { status: 'denied' },
            clock: { synced: false, samples: 3, errorMs: null, rttMs: 1500 },
            wakeLock: 'idle',
            connection: { online: false, lastOkAgoMs: null, failing: false },
            uploads: { pending: 3, refused: 0, uploaded: 0, offline: true, blocked: null },
            emptyMat: false,
        });
        assert.deepEqual(rows.camera, { level: 'bad', code: 'denied' });
        assert.deepEqual(rows.clock, { level: 'bad', code: 'slow' }, 'samples came, none under a second');
        assert.deepEqual(rows.wake, { level: 'info', code: 'on_ready' });
        assert.deepEqual(rows.connection, { level: 'bad', code: 'offline' });
        assert.deepEqual(rows.uploads, { level: 'warn', code: 'offline', count: 3 });
        assert.deepEqual(rows.emptyMat, { level: 'info', code: 'take' });

        assert.deepEqual(checklistRows({ ...base, clock: { synced: false, samples: 0 }, clockSyncing: true }).clock, { level: 'warn', code: 'syncing' });
        assert.deepEqual(checklistRows({ ...base, uploads: { pending: 2, refused: 1, uploaded: 4, offline: false, blocked: null } }).uploads, { level: 'bad', code: 'refused', count: 1 });
        assert.deepEqual(checklistRows({ ...base, uploads: { pending: 2, refused: 0, uploaded: 0, offline: false, blocked: 'unknown_link' } }).uploads, { level: 'bad', code: 'blocked' });
        assert.deepEqual(checklistRows({ ...base, uploads: null }).uploads, { level: 'info', code: 'none' });
        assert.deepEqual(checklistRows({ ...base, connection: { online: true, lastOkAgoMs: null, failing: false } }).connection, { level: 'warn', code: 'checking' });
        assert.deepEqual(checklistRows({ ...base, connection: { online: true, lastOkAgoMs: 1000, failing: true } }).connection, { level: 'bad', code: 'failing' });

        // Every code the rows can take has its text (tests/CompeteTextsTest.php reads CHECK_CODES)
        const cameraStatuses = ['idle', 'opening', 'live', 'muted', 'ended', 'denied', 'unavailable', 'busy', 'error', undefined];
        const seen = { camera: new Set(), clock: new Set(), wake: new Set(), connection: new Set(), uploads: new Set(), emptyMat: new Set() };
        const variants = [];

        for (const status of cameraStatuses) {
            for (const wakeLock of ['idle', 'pending', 'active', 'released', 'denied', 'unsupported']) {
                variants.push({ ...base, camera: { status }, wakeLock });
            }
        }

        variants.push(
            { ...base, clock: { synced: false, samples: 0 }, clockSyncing: true },
            { ...base, clock: { synced: false, samples: 2 }, clockSyncing: false },
            { ...base, connection: { online: false } },
            { ...base, connection: { online: true, failing: true } },
            { ...base, connection: { online: true, lastOkAgoMs: null } },
            { ...base, uploads: null, emptyMat: false },
            { ...base, uploads: { pending: 1, refused: 0, uploaded: 0, offline: false, blocked: null } },
            { ...base, uploads: { pending: 1, refused: 0, uploaded: 0, offline: true, blocked: null } },
            { ...base, uploads: { pending: 0, refused: 2, uploaded: 0, offline: false, blocked: null } },
            { ...base, uploads: { pending: 0, refused: 0, uploaded: 0, offline: false, blocked: 'unknown_link' } },
        );

        for (const variant of variants) {
            for (const [row, value] of Object.entries(checklistRows(variant))) {
                assert.ok(CHECK_CODES[row].includes(value.code), `${row}: ${value.code}`);
                assert.ok(['ok', 'warn', 'bad', 'info'].includes(value.level));
                seen[row].add(value.code);
            }
        }

        for (const [row, codes] of Object.entries(CHECK_CODES)) {
            assert.deepEqual([...seen[row]].sort(), [...codes].sort(), `every code of ${row} is reachable`);
        }
    },

    async 'periodic photos: every 30 s, every 10 s in the last 3 minutes before GO, none hours ahead or after the end'() {
        const scheduled = state({ startedAtMs: START });

        assert.equal(periodicGapMs(deriveView(state(), START)), 30_000, 'waiting for the organiser');
        assert.equal(periodicGapMs(deriveView(scheduled, START - 2 * 3_600_000)), null, 'two hours ahead: nothing to see yet');
        assert.equal(periodicGapMs(deriveView(scheduled, START - 20 * MINUTE)), 30_000);
        assert.equal(periodicGapMs(deriveView(scheduled, START - 3 * MINUTE)), 10_000, 'the unwrapping on camera');
        assert.equal(periodicGapMs(deriveView(scheduled, START - 5_000)), 10_000, 'the countdown');
        assert.equal(periodicGapMs(deriveView(scheduled, START + 5 * MINUTE)), 30_000, 'running');
        assert.equal(periodicGapMs(deriveView(scheduled, START + 61 * MINUTE)), null, 'ended');

        const running = deriveView(scheduled, START + 5 * MINUTE);
        assert.equal(nextPeriodicPhotoAt(running, START + 5 * MINUTE, null), START + 5 * MINUTE, 'none taken yet: now');
        assert.equal(nextPeriodicPhotoAt(running, START + 5 * MINUTE, START + 5 * MINUTE - 12_000), START + 5 * MINUTE + 18_000);
        assert.equal(nextPeriodicPhotoAt(running, START + 5 * MINUTE, START + 6 * MINUTE), START + 5 * MINUTE, 'a mark from the future (a clock fix): now');
        assert.equal(nextPeriodicPhotoAt(deriveView(scheduled, START + 61 * MINUTE), START + 61 * MINUTE, START), null);

        const unwrapping = deriveView(scheduled, START - 2 * MINUTE);
        assert.equal(nextPeriodicPhotoAt(unwrapping, START - 2 * MINUTE, START - 2 * MINUTE - 4_000), START - 2 * MINUTE + 6_000);
    },

    async 'pieces at time-up: a whole number from 0 to the puzzle\'s pieces'() {
        assert.deepEqual(readPieces(' 12 ', 500), { ok: true, pieces: 12 });
        assert.deepEqual(readPieces('0', 500), { ok: true, pieces: 0 });
        assert.deepEqual(readPieces('500', 500), { ok: true, pieces: 500 });
        assert.deepEqual(readPieces('501', 500), { ok: false, reason: 'too_many' });
        assert.deepEqual(readPieces('501', null), { ok: true, pieces: 501 }, 'the pieces unknown (a secret puzzle)');
        assert.deepEqual(readPieces('', 500), { ok: false, reason: 'empty' });
        assert.deepEqual(readPieces('   ', 500), { ok: false, reason: 'empty' });
        assert.deepEqual(readPieces('12.5', 500), { ok: false, reason: 'not_a_number' });
        assert.deepEqual(readPieces('-3', 500), { ok: false, reason: 'not_a_number' });
        assert.deepEqual(readPieces('abc', 500), { ok: false, reason: 'not_a_number' });
        assert.deepEqual(readPieces(null, 500), { ok: false, reason: 'empty' });
    },

    async 'practice: a whole second at least 10 s ahead, one minute, only while nothing real is coming'() {
        assert.equal(practiceStartAt(START + 1), START + 11_000);
        assert.equal(practiceStartAt(START), START + 10_000);
        assert.equal(practiceStartAt(START + 999), START + 11_000);

        const practice = { startedAtMs: START + 10_000 };
        const asRound = practiceState(practice);
        assert.equal(deriveView(asRound, START + 5_000).screen, 'countdown');
        assert.equal(deriveView(asRound, START + 40_000).screen, 'running');
        assert.equal(deriveView(asRound, START + 70_000).screen, 'ended', 'one minute');

        assert.equal(practiceAllowed(deriveView(state(), START)), true, 'waiting for the organiser');
        assert.equal(practiceAllowed(deriveView(state({ startedAtMs: START }), START - 2 * MINUTE)), true, 'the minute fits before the start');
        assert.equal(practiceAllowed(deriveView(state({ startedAtMs: START }), START - 80_000)), false, 'it would not fit any more');
        assert.equal(practiceAllowed(deriveView(state({ startedAtMs: START }), START - 5_000)), false, 'the countdown');
        assert.equal(practiceAllowed(deriveView(state({ startedAtMs: START }), START + MINUTE)), false, 'running');
        assert.equal(practiceAllowed(deriveView(state({ startedAtMs: START }), START + 2 * 3_600_000)), true, 'after the round');
    },

    async 'the clock: MM:SS for a round of up to an hour (60:00 at its end), HH:MM:SS for a longer one'() {
        assert.equal(shortClock(60 * MINUTE), true);
        assert.equal(shortClock(90 * MINUTE), false);
        assert.equal(shortClock(null), false);

        assert.equal(clockText(47 * MINUTE + 12_999, { limitMs: 60 * MINUTE }), '47:12');
        assert.equal(clockText(60 * MINUTE, { limitMs: 60 * MINUTE }), '60:00');
        assert.equal(clockText(0, { limitMs: 30 * MINUTE }), '00:00');
        assert.equal(clockText(1, { limitMs: 30 * MINUTE, ceil: true }), '00:01', 'what is left rounds up');
        assert.equal(clockText(75 * MINUTE + 5_000, { limitMs: 90 * MINUTE }), '01:15:05');
        assert.equal(clockText(5_000), '00:00:05', 'no limit known: the long form');

        // The time to a start: rounded up, hours only when there are some
        assert.equal(countdownText(30_000), '00:30');
        assert.equal(countdownText(29_001), '00:30');
        assert.equal(countdownText(2 * 3_600_000 + 13 * MINUTE + 4_200), '02:13:05');
        assert.equal(countdownText(0), '00:00');
    },

    async 'what the device remembers: consent per participant, the set-up per round, taps and pieces per run, nothing breaks on a broken storage'() {
        const storage = memoryStorage();

        assert.equal(loadConsent(storage, PARTICIPANT), false);
        saveConsent(storage, PARTICIPANT, START);
        assert.equal(loadConsent(storage, PARTICIPANT), true);
        assert.equal(loadConsent(storage, 'other'), false);

        const mine = loadProgress(storage, PARTICIPANT, ROUND, START);
        assert.deepEqual(mine, emptyProgress());
        mine.ready = true;
        mine.consentSent = true;
        mine.finishes.push({ eventId: 'e1', momentMs: START + 1000, undone: false, continued: false });
        mine.pieces = { measure: 'placed', pieces: 812 };
        mine.seen['start:1'] = true;
        assert.equal(saveProgress(storage, PARTICIPANT, ROUND, mine, START), true);

        const again = loadProgress(storage, PARTICIPANT, ROUND, START);
        assert.deepEqual(again, mine);
        assert.notEqual(again, mine);
        assert.deepEqual(loadProgress(storage, PARTICIPANT, 'another-round', START), emptyProgress(), 'a new round starts afresh');
        assert.deepEqual(loadProgress(storage, PARTICIPANT, null, START), emptyProgress());
        assert.equal(saveProgress(storage, PARTICIPANT, null, mine, START), false);

        // Junk stored: the defaults, junk finishes dropped
        storage.setItem(`msp-compete:${PARTICIPANT}:${ROUND}`, '{not json');
        storage.setItem(`msp-compete:${PARTICIPANT}:${ROUND}:run:${START}`, '{not json');
        assert.deepEqual(loadProgress(storage, PARTICIPANT, ROUND, START), emptyProgress());
        storage.setItem(`msp-compete:${PARTICIPANT}:${ROUND}`, JSON.stringify({ ready: 'yes' }));
        storage.setItem(`msp-compete:${PARTICIPANT}:${ROUND}:run:${START}`, JSON.stringify({ finishes: [{ eventId: 1 }, { eventId: 'ok', momentMs: START + 5 }], pieces: { pieces: 'many' } }));
        const cleaned = loadProgress(storage, PARTICIPANT, ROUND, START);
        assert.equal(cleaned.ready, false);
        assert.deepEqual(cleaned.finishes, [{ eventId: 'ok', momentMs: START + 5, undone: false, continued: false }]);
        assert.equal(cleaned.pieces, null);

        // A storage that throws (private mode, quota): defaults, and saving says it failed
        const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
        assert.deepEqual(loadProgress(broken, PARTICIPANT, ROUND, START), emptyProgress());
        assert.equal(saveProgress(broken, PARTICIPANT, ROUND, mine, START), false);
        assert.equal(loadConsent(broken, PARTICIPANT), false);
    },

    async 'a run is one start of the round clock: after a Reset and a new start no tap and no pieces carry over - the set-up does'() {
        const storage = memoryStorage();
        const firstRun = state({ startedAtMs: START, minutesLimit: 30 });
        assert.equal(runKeyOf(firstRun), START);
        assert.equal(runKeyOf(state()), null, 'no start - no run');
        assert.equal(runKeyOf({ ...state(), round: null }), null);
        assert.equal(runKeyOf(null), null);

        const first = loadProgress(storage, PARTICIPANT, ROUND, START);
        first.consentSent = true;
        first.ready = true;
        first.emptyMat = true;
        first.finishes.push({ eventId: 'e1', momentMs: START + 20 * MINUTE, undone: false, continued: false });
        first.pieces = { measure: 'left', pieces: 12 };
        first.seen[`start:${START}`] = true;
        saveProgress(storage, PARTICIPANT, ROUND, first, START);

        // The organiser resets: no run - the set-up stays, nothing of the run that was
        const reset = loadProgress(storage, PARTICIPANT, ROUND, null);
        assert.deepEqual([reset.consentSent, reset.ready, reset.emptyMat, reset.seen[`start:${START}`]], [true, true, true, true]);
        assert.deepEqual([reset.finishes, reset.pieces], [[], null]);
        assert.equal(saveProgress(storage, PARTICIPANT, ROUND, reset, null), true, 'no run: the set-up only');
        assert.deepEqual(loadProgress(storage, PARTICIPANT, ROUND, START).finishes.length, 1, 'the run that was is still on the device');

        // ...and starts again an hour later: a fresh run
        const secondStart = START + 60 * MINUTE;
        const secondRun = state({ startedAtMs: secondStart, minutesLimit: 30 });
        const second = loadProgress(storage, PARTICIPANT, ROUND, runKeyOf(secondRun));
        assert.deepEqual([second.finishes, second.pieces, second.ready], [[], null, true]);

        const screen = (serverNowMs, progress, entry = {}) => screenFor({
            state: state({ startedAtMs: secondStart, minutesLimit: 30, entry }),
            view: deriveView(secondRun, serverNowMs),
            consent: true,
            progress,
        });
        // The server counts the new run's taps only (OnlineRoundState) - GO is GO, not "Finish recorded"
        assert.equal(screen(secondStart + 5 * MINUTE, second), 'running');
        // ...and at its end the pieces question comes again
        assert.equal(screen(secondStart + 31 * MINUTE, second), 'timeup');

        // A tap in the second run is kept with it
        second.finishes.push({ eventId: 'e2', momentMs: secondStart + 25 * MINUTE, undone: false, continued: false });
        saveProgress(storage, PARTICIPANT, ROUND, second, secondStart);
        assert.deepEqual(loadProgress(storage, PARTICIPANT, ROUND, secondStart).finishes.map((finish) => finish.eventId), ['e2']);
        assert.deepEqual(loadProgress(storage, PARTICIPANT, ROUND, START).finishes.map((finish) => finish.eventId), ['e1']);
    },

    async 'progress an older page kept with the round: the run keeps only the taps from its own start on'() {
        const storage = memoryStorage();
        storage.setItem(`msp-compete:${PARTICIPANT}:${ROUND}`, JSON.stringify({
            consentSent: true,
            ready: true,
            finishes: [
                { eventId: 'earlier', momentMs: START - 30 * MINUTE },
                { eventId: 'this-run', momentMs: START + 10 * MINUTE },
            ],
            pieces: { measure: 'left', pieces: 3 },
        }));

        const progress = loadProgress(storage, PARTICIPANT, ROUND, START);
        assert.equal(progress.ready, true);
        assert.deepEqual(progress.finishes.map((finish) => finish.eventId), ['this-run']);
        assert.equal(progress.pieces, null, 'a report can not be told apart from an earlier run\'s');
    },

    async 'the device id is kept, so a reload is no takeover; a broken storage gets one for the page'() {
        const storage = memoryStorage();
        let made = 0;
        const make = () => {
            made += 1;

            return `018d0050-0000-7000-8000-00000000000${made}`;
        };

        const first = deviceIdFrom(storage, make);
        assert.equal(first, '018d0050-0000-7000-8000-000000000001');
        assert.equal(deviceIdFrom(storage, make), first);
        assert.equal(storage.getItem(DEVICE_KEY), first);
        assert.equal(made, 1);

        storage.setItem(DEVICE_KEY, '<script>');
        assert.equal(deviceIdFrom(storage, make), '018d0050-0000-7000-8000-000000000002', 'junk is replaced');

        const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
        assert.equal(deviceIdFrom(broken, make), '018d0050-0000-7000-8000-000000000003');
    },

    async 'an event: its id, type, round, the clock stamp and its data; texts filled'() {
        const stamp = { clientMs: START + 2_832_345, perfMs: 2_832_345.5, offsetMs: -1234.5, rttMs: 38 };

        assert.deepEqual(buildEvent('finish.button', stamp, { id: 'id-1', roundId: ROUND }), {
            id: 'id-1',
            type: 'finish.button',
            roundId: ROUND,
            clientMs: START + 2_832_345,
            perfMs: 2_832_345.5,
            offsetMs: -1234.5,
            rttMs: 38,
            data: {},
        });
        assert.deepEqual(buildEvent('timeup.pieces', stamp, { id: 'id-2', roundId: ROUND, data: { measure: 'left', pieces: 3 } }).data, { measure: 'left', pieces: 3 });

        assert.equal(fill('On - %width% × %height%', { width: 1920, height: 1080 }), 'On - 1920 × 1080');
        assert.equal(fill('No placeholders'), 'No placeholders');
        assert.equal(fill(null), '');
    },

    async 'the clock on screen changes on the round\'s whole seconds and when GO ends - the page draws those frames at once'() {
        const scheduled = state({ startedAtMs: START });
        const at = (serverNow, from = scheduled) => nextDisplayChangeAt(deriveView(from, serverNow), serverNow);

        // Nothing counts: no start, or the round is over
        assert.equal(at(START - 5_000, state()), null);
        assert.equal(at(START + 70 * MINUTE, scheduled), null, 'the time limit ended it');
        assert.equal(at(START + 5_000, state({ startedAtMs: START, stoppedAtMs: START + 3_000 })), null, 'stopped');

        // The countdown: "4" until 3 s are left, … "1" until the start itself = GO (end-to-end run 2026-10-13: GO was
        // drawn up to 50 ms late when the next render came on its own)
        assert.equal(at(START - 3_400), START - 3_000);
        assert.equal(at(START - 3_000), START - 2_000, 'at exactly 3 s left "3" is shown already');
        assert.equal(at(START - 1), START, 'GO at the start');
        // "Starts in 2:13:06" changes to "2:13:05" on the second too
        assert.equal(at(START - (2 * 3_600_000 + 13 * MINUTE + 5_400)), START - (2 * 3_600_000 + 13 * MINUTE + 5_000));

        // Running: the next whole second - and GO_SHOWN_MS, when GO gives way to the stopwatch
        assert.equal(at(START), START + 1_000);
        assert.equal(at(START + 1_200), START + GO_SHOWN_MS);
        assert.equal(at(START + GO_SHOWN_MS), START + 2_000);
        assert.equal(at(START + 61_234), START + 62_000);
        // Always ahead of now, so a frame drawn on the change never asks for itself again
        for (const serverNow of [START - 10_000, START - 999, START, START + 999, START + 1_499, START + 1_500, START + 59 * MINUTE + 59_999]) {
            assert.ok(at(serverNow) > serverNow, String(serverNow - START));
        }
    },

    async '"tap here once": a ready participant whose page loaded again, on the round\'s screens, until the first touch'() {
        // A reload after "I'm ready" (end-to-end run 2026-10-13): no sound, no screen lock asked for, nobody touched it
        const reloaded = { ready: true, screen: 'waiting', touched: false, soundLocked: true, wakeLock: 'idle' };

        for (const screen of WAKE_SCREENS) {
            assert.equal(wakeTapNeeded({ ...reloaded, screen }), true, screen);
        }

        assert.deepEqual(WAKE_SCREENS, ['waiting', 'countdown', 'running', 'finished']);

        // Chrome granted the lock without a tap - the sound still waits for one
        assert.equal(wakeTapNeeded({ ...reloaded, wakeLock: 'active' }), true);
        // Safari refused the lock without a tap - a tap gets it
        assert.equal(wakeTapNeeded({ ...reloaded, soundLocked: false, wakeLock: 'denied' }), true);
        assert.equal(wakeTapNeeded({ ...reloaded, soundLocked: false, wakeLock: 'released' }), true);
        // The sound unlocked and the lock held, being asked for, or impossible here: a tap would change nothing
        for (const wakeLock of ['active', 'pending', 'unsupported']) {
            assert.equal(wakeTapNeeded({ ...reloaded, soundLocked: false, wakeLock }), false, wakeLock);
        }

        // The first touch of the page ends it, whatever the browser granted then
        assert.equal(wakeTapNeeded({ ...reloaded, touched: true }), false);
        assert.equal(wakeTapNeeded({ ...reloaded, touched: true, wakeLock: 'denied' }), false);

        // Not ready (the checklist's own "I'm ready" tap unlocks both), a practice, or no screen of the round
        assert.equal(wakeTapNeeded({ ...reloaded, ready: false }), false);
        assert.equal(wakeTapNeeded({ ...reloaded, practice: true }), false);

        for (const screen of SCREENS.filter((name) => !WAKE_SCREENS.includes(name))) {
            assert.equal(wakeTapNeeded({ ...reloaded, screen }), false, screen);
        }
    },

    async '"tap here once" says what it is for by the round: the start before GO, time\'s up while it runs - never after the end (stream RP)'() {
        const at = (roundState, serverNow) => wakeTapText(deriveView(roundState, serverNow));
        const started = state({ startedAtMs: START });

        assert.equal(at(state(), START), 'start', 'waiting for the organiser');
        assert.equal(at(started, START - 2 * MINUTE), 'start', 'a start set');
        assert.equal(at(started, START - 5_000), 'start', 'the countdown');
        assert.equal(at(started, START + 500), 'timeup', 'GO - the start is behind');
        assert.equal(at(started, START + 30 * MINUTE), 'timeup', 'running');
        assert.equal(at(started, START + 61 * MINUTE), null, 'time is up');
        assert.equal(at(state({ startedAtMs: START, stoppedAtMs: START + 10 * MINUTE }), START + 11 * MINUTE), null, 'ended by the organiser');
        assert.equal(wakeTapText(null), 'start', 'no view yet: nothing has started');

        // A page loaded again after the end lands on time's up or the after screen - the banner never asks there
        // (nothing left to hear: the alarm rang on the page that was open at the end)
        const ended = deriveView(started, START + 61 * MINUTE);
        const reloaded = { ready: true, touched: false, soundLocked: true, wakeLock: 'idle' };

        for (const progress of [
            { ...emptyProgress(), ready: true },
            { ...emptyProgress(), ready: true, finishes: [{ eventId: 'tap', momentMs: START + 20 * MINUTE, undone: false, continued: false }] },
            { ...emptyProgress(), ready: true, pieces: { measure: 'left', pieces: 12 } },
        ]) {
            const screen = screenFor({ state: started, view: ended, consent: true, progress });
            assert.ok(['timeup', 'after'].includes(screen), screen);
            assert.equal(wakeTapNeeded({ ...reloaded, screen }), false, screen);
        }
    },

    async 'after the end only time\'s up and the after screen - the practice still offered there, so its hint never says "before the round" (stream RP)'() {
        const started = state({ startedAtMs: START });

        for (const ended of [deriveView(started, START + 2 * 3_600_000), deriveView(state({ startedAtMs: START, stoppedAtMs: START + MINUTE }), START + 2 * MINUTE)]) {
            assert.equal(ended.screen, 'ended');
            assert.equal(practiceAllowed(ended), true, 'the practice run is still there after the round');

            // Whatever this device did - never a screen of before the round (the waiting / checklist screens carry the
            // hint; the after screen's practice comes without it - templates/compete/_screens_round.html.twig)
            for (const consent of [true, false]) {
                for (const progress of [emptyProgress(), { ...emptyProgress(), ready: true }, { ...emptyProgress(), ready: true, pieces: { measure: 'left', pieces: 3 } }]) {
                    const screen = screenFor({ state: started, view: ended, consent, progress });
                    assert.ok(['timeup', 'after'].includes(screen), `${consent} ${JSON.stringify(progress)}: ${screen}`);
                }
            }
        }
    },

    async 'a page (re)opened during the round on a device that was ready leaves one trace, with how long it was gone (stream RP)'() {
        const started = state({ startedAtMs: START });
        const running = deriveView(started, START + 5 * MINUTE);

        assert.equal(reloadReported({ ready: true, view: running }), true);
        assert.equal(reloadReported({ ready: true, view: deriveView(started, START + 200) }), true, 'right after GO');
        assert.equal(reloadReported({ ready: false, view: running }), false, 'never ready on this device - a first visit mid-round');

        for (const [name, view] of [
            ['waiting', deriveView(state(), START)],
            ['a start set', deriveView(started, START - 2 * MINUTE)],
            ['the countdown', deriveView(started, START - 3_000)],
            ['after the end', deriveView(started, START + 61 * MINUTE)],
            ['no view', null],
        ]) {
            assert.equal(reloadReported({ ready: true, view }), false, name);
        }

        assert.equal(reloadReported({ ready: true, view: running, preview: true }), false, 'an organiser\'s preview records nothing');
        assert.equal(reloadReported({ ready: true, view: running, secondScreen: true }), false, 'a second screen records nothing');
        assert.equal(reloadReported({ ready: true, view: running, practice: true }), false, 'a practice records nothing');

        // How long the page was gone: the last alive mark of the same round on this device
        const storage = memoryStorage();
        assert.equal(goneSince(storage, PARTICIPANT, ROUND, START), null, 'nothing kept yet');
        assert.equal(markAlive(storage, PARTICIPANT, ROUND, START), true);
        assert.equal(goneSince(storage, PARTICIPANT, ROUND, START + 4_321), 4_321);
        assert.equal(goneSince(storage, PARTICIPANT, '018d0050-0000-0000-0000-000000000999', START + 4_321), null, 'another round\'s mark');
        assert.equal(goneSince(storage, '018d0050-0000-0000-0000-000000000999', ROUND, START + 4_321), null, 'another participant\'s');
        assert.equal(goneSince(storage, PARTICIPANT, ROUND, START - 1), null, 'a clock that went back');
        assert.equal(goneSince(storage, PARTICIPANT, ROUND, START + RELOAD_GONE_MAX_MS), RELOAD_GONE_MAX_MS);
        assert.equal(goneSince(storage, PARTICIPANT, ROUND, START + RELOAD_GONE_MAX_MS + 1), null, 'too old to tell');
        assert.equal(markAlive(storage, PARTICIPANT, null, START + 9_000), false, 'no round - nothing kept');
        assert.equal(goneSince(storage, PARTICIPANT, ROUND, START + 4_321), 4_321);

        storage.setItem(`msp-compete-alive:${PARTICIPANT}`, JSON.stringify({ roundId: ROUND, atMs: '12' }));
        assert.equal(goneSince(storage, PARTICIPANT, ROUND, START), null, 'a mark that is no moment');
        storage.setItem(`msp-compete-alive:${PARTICIPANT}`, '{broken');
        assert.equal(goneSince(storage, PARTICIPANT, ROUND, START), null);

        // A storage that refuses (private mode, blocked site data): nothing known, nothing breaks
        const refusing = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
        assert.equal(markAlive(refusing, PARTICIPANT, ROUND, START), false);
        assert.equal(goneSince(refusing, PARTICIPANT, ROUND, START + 1_000), null);

        // The event as the outbox keeps it - stamped with the load's moment on the synced clock
        const stamp = { clientMs: START + 5 * MINUTE, perfMs: 812.5, offsetMs: -20.5, rttMs: 31 };
        assert.deepEqual(buildEvent('page.reloaded', stamp, { id: 'e-1', roundId: ROUND, data: { goneMs: 4_321, screen: 'running' } }), {
            id: 'e-1',
            type: 'page.reloaded',
            roundId: ROUND,
            clientMs: START + 5 * MINUTE,
            perfMs: 812.5,
            offsetMs: -20.5,
            rttMs: 31,
            data: { goneMs: 4_321, screen: 'running' },
        });
        assert.equal(RELOAD_DECIDE_WITHIN_MS, 10_000, 'an unsynced clock decides by the phone\'s own after 10 s');
    },

    async 'the camera runs on the screens of the round and the practice only'() {
        for (const screen of ['checklist', 'waiting', 'countdown', 'running', 'finished', 'timeup']) {
            assert.equal(cameraWanted(screen), true, screen);
        }

        for (const screen of ['welcome', 'after', 'no_round', 'not_entered', 'taken_over', 'dead_link']) {
            assert.equal(cameraWanted(screen), false, screen);
            assert.equal(cameraWanted(screen, true), true, `${screen} with a practice`);
        }
    },

    async 'the API: every JSON answer is a clock sample, nothing carries a cookie, no answer is cached'() {
        const requests = [];
        const samples = [];
        const names = [];
        let perf = 1000;
        let wall = START;
        const api = createCompeteApi({
            urls: { state: '/api/compete/T/state', heartbeat: '/api/compete/T/heartbeat', events: '/api/compete/T/events', media: '/api/compete/T/media' },
            fetch: async (url, init) => {
                requests.push({ url, init });
                perf += 40;
                wall += 40;

                return response(200, { serverNowMs: START + 5_000, stateHash: 'abc', takenOverBy: null });
            },
            now: () => perf,
            wallNow: () => wall,
            onSample: (sample, name) => {
                samples.push(sample);
                names.push(name);
            },
        });

        const answer = await api.heartbeat({ deviceId: 'd', roundId: ROUND });
        assert.equal(answer.kind, 'ok');
        assert.equal(answer.data.stateHash, 'abc');
        assert.deepEqual(samples, [{ t0: 1000, t1: 1040, w0: START, w1: START + 40, serverNowMs: START + 5_000 }]);

        const sent = requests[0];
        assert.equal(sent.url, '/api/compete/T/heartbeat');
        assert.equal(sent.init.method, 'POST');
        assert.equal(sent.init.credentials, 'omit', 'the token is the only credential');
        assert.equal(sent.init.cache, 'no-store');
        assert.equal(sent.init.redirect, 'manual');
        assert.equal(sent.init.headers['Content-Type'], 'application/json');
        assert.equal(sent.init.headers['X-CSRF-Token'], undefined);
        assert.deepEqual(JSON.parse(sent.init.body), { deviceId: 'd', roundId: ROUND });

        await api.state();
        assert.equal(requests[1].init.method, 'GET');
        assert.equal(requests[1].init.credentials, 'omit');
        assert.equal(requests[1].init.body, undefined);

        await api.events({ deviceId: 'd', events: [] });
        await api.media({ deviceId: 'd', kind: 'periodic' });
        assert.deepEqual(requests.map((request) => request.url), ['/api/compete/T/heartbeat', '/api/compete/T/state', '/api/compete/T/events', '/api/compete/T/media']);
        assert.equal(samples.length, 4);
        // The page takes the light requests' samples only (compete_controller.js) - each comes with its request's name
        assert.deepEqual(names, ['heartbeat', 'state', 'events', 'media']);
    },

    async 'several devices on one link: the device that claimed last holds it; an answer older than this device\'s own claim never makes it give way'() {
        const verdict = (input) => takeoverVerdict({ deviceId: 'phone-b', ready: true, takenOver: false, claim: null, beatSentAt: START, ...input });

        // Nobody else, or this device itself
        assert.deepEqual(verdict({ activeDevice: null }), { otherDeviceActive: false, takenOver: false, recheckAt: null });
        assert.deepEqual(verdict({ activeDevice: 'phone-b' }), { otherDeviceActive: false, takenOver: false, recheckAt: null });
        // Still setting up: the checklist only says the link is in use elsewhere
        assert.deepEqual(verdict({ activeDevice: 'phone-a', ready: false }), { otherDeviceActive: true, takenOver: false, recheckAt: null });
        // Ready, no claim on this page (a reload): another device holds it - give way
        assert.deepEqual(verdict({ activeDevice: 'phone-a' }), { otherDeviceActive: true, takenOver: true, recheckAt: null });
        // This device's claim is not stored yet: the answer says nothing about it
        assert.deepEqual(verdict({ activeDevice: 'phone-a', claim: { pending: true, ackedAt: null } }), { otherDeviceActive: true, takenOver: false, recheckAt: null });
        // Stored, but the heartbeat was sent before the grace ended: set aside, asked again when it ends
        const claim = { pending: false, ackedAt: START };
        assert.deepEqual(verdict({ activeDevice: 'phone-a', claim, beatSentAt: START + TAKEOVER_GRACE_MS - 1 }), { otherDeviceActive: true, takenOver: false, recheckAt: START + TAKEOVER_GRACE_MS });
        // Sent after it: another device took the link since - give way
        assert.deepEqual(verdict({ activeDevice: 'phone-a', claim, beatSentAt: START + TAKEOVER_GRACE_MS }), { otherDeviceActive: true, takenOver: true, recheckAt: null });
        // Given way, it stays so - only its own claim takes the link back, never an answer
        assert.deepEqual(verdict({ activeDevice: 'phone-b', takenOver: true }), { otherDeviceActive: false, takenOver: true, recheckAt: null });
        assert.deepEqual(verdict({ activeDevice: null, takenOver: true }), { otherDeviceActive: false, takenOver: true, recheckAt: null });
    },

    async 'take-back: the device that lost the link stops within 20 s - whenever its heartbeat comes and however recently it claimed'() {
        let worst = 0;

        for (const takeBackAfterMs of [1_000, 2_500, 3_500, 5_000, 10_000, 20_000, 45_000]) {
            for (let phaseMs = 100; phaseMs < HEARTBEAT_MS; phaseMs += 700) {
                // phone-a got ready first, phone-b took the link at 0, phone-a takes it back
                const devices = simulateDevices({
                    devices: [{ id: 'phone-a', phaseMs: (phaseMs * 7) % HEARTBEAT_MS }, { id: 'phone-b', phaseMs }],
                    claims: [{ device: 'phone-a', atMs: -60_000 }, { device: 'phone-b', atMs: 0 }, { device: 'phone-a', atMs: takeBackAfterMs }],
                    untilMs: takeBackAfterMs + 60_000,
                });
                const storedAt = takeBackAfterMs + 300;
                const gaveWay = devices['phone-b'].gaveWayAt.filter((at) => at >= storedAt);

                assert.equal(gaveWay.length, 1, `phone-b gives way once (take back after ${takeBackAfterMs} ms, phase ${phaseMs} ms)`);
                assert.ok(gaveWay[0] - storedAt <= 20_000, `phone-b gave way ${gaveWay[0] - storedAt} ms after the take-back was stored`);
                assert.equal(devices['phone-b'].takenOver, true);
                assert.equal(devices['phone-a'].takenOver, false, 'phone-a records');
                worst = Math.max(worst, gaveWay[0] - storedAt);
            }
        }

        // The heartbeat's 15 s and its round trip - no 30-s grace on top any more
        assert.ok(worst <= HEARTBEAT_MS + 500, `worst ${worst} ms`);
    },

    async 'two devices fighting over the link never flap: each gives way only to a newer claim, and once'() {
        const devices = simulateDevices({
            devices: [{ id: 'phone-a', phaseMs: 4_000 }, { id: 'laptop', phaseMs: 11_000 }],
            // Taps on both, a second apart, then quiet
            claims: [
                { device: 'phone-a', atMs: 0 },
                { device: 'laptop', atMs: 1_000 },
                { device: 'phone-a', atMs: 2_000 },
                { device: 'laptop', atMs: 2_600 },
                { device: 'phone-a', atMs: 4_200 },
            ],
            untilMs: 120_000,
        });

        // The last claim wins; the other gives way after it - once, and only to it
        assert.equal(devices['phone-a'].takenOver, false);
        assert.equal(devices.laptop.takenOver, true);
        assert.deepEqual(devices['phone-a'].gaveWayAt.filter((at) => at > 4_200), [], 'the last claimer never gives way after its claim');
        const laptopAfter = devices.laptop.gaveWayAt.filter((at) => at > 4_200 + 300);
        assert.equal(laptopAfter.length, 1);
        assert.ok(laptopAfter[0] - 4_500 <= 20_000);
    },

    async 'the status line: "Offline" only when the network is down - a refusal or a server error is a connection problem, retried'() {
        assert.equal(connectionStatus({ online: false }), 'offline', 'the browser says so');
        assert.equal(connectionStatus({ online: true, noAnswers: 2 }), 'offline', 'no answer twice in a row');
        assert.equal(connectionStatus({ online: true, noAnswers: 1 }), 'online', 'one is a hiccup');
        assert.equal(connectionStatus({ online: true, failedAnswers: 2 }), 'problem');
        assert.equal(connectionStatus({ online: true, failedAnswers: 1 }), 'online');
        assert.equal(connectionStatus({ online: true }), 'online');

        const now = START;
        // 429 rate_limited, 503 retry, a 500 page, a ban page: the server answered - a problem, not offline
        assert.equal(answerReach(readCompeteAnswer(response(429, null, { headers: { 'Retry-After': '30' } }), { error: 'rate_limited' }, now)), 'failed');
        assert.equal(answerReach(readCompeteAnswer(response(503, null), { error: 'retry' }, now)), 'failed');
        assert.equal(answerReach(readCompeteAnswer(response(500, null, { contentType: 'text/html' }), null, now)), 'failed');
        assert.equal(answerReach(readCompeteAnswer(response(403, null, { contentType: 'text/html' }), null, now)), 'failed');
        // No answer, or not ours (a captive portal): offline
        assert.equal(answerReach(readCompeteAnswer(null, null, now)), 'none');
        assert.equal(answerReach(readCompeteAnswer(response(200, null, { contentType: 'text/html' }), null, now)), 'none');
        assert.equal(answerReach(undefined), 'none');
        // Our answers, refusals of ours included: connected
        assert.equal(answerReach(readCompeteAnswer(response(200, { serverNowMs: 1 }), { serverNowMs: 1 }, now)), 'ok');
        assert.equal(answerReach(readCompeteAnswer(response(404, null), { error: 'unknown_link' }, now)), 'ok');
        assert.equal(answerReach(readCompeteAnswer(response(403, null), { error: 'forbidden' }, now)), 'ok');
    },

    async 'the API\'s answers: ok, our refusals, busy, failing, a portal page, offline - and a dead link'() {
        const now = START;

        assert.equal(readCompeteAnswer(response(200, { serverNowMs: 1 }), { serverNowMs: 1 }, now).kind, 'ok');
        assert.deepEqual(readCompeteAnswer(response(404, null), { error: 'unknown_link', serverNowMs: 1 }, now), { kind: 'client', status: 404, data: { error: 'unknown_link', serverNowMs: 1 } });
        assert.equal(readCompeteAnswer(response(403, null), { error: 'forbidden' }, now).kind, 'forbidden');
        assert.deepEqual(readCompeteAnswer(response(429, null, { headers: { 'Retry-After': '30' } }), { error: 'rate_limited' }, now), { kind: 'server', status: 429, retryAfter: 30_000, busy: true });
        assert.deepEqual(readCompeteAnswer(response(403, null, { contentType: 'text/html' }), null, now), { kind: 'server', status: 403, retryAfter: null, busy: true }, 'a ban page, not our JSON');
        assert.deepEqual(readCompeteAnswer(response(503, null), null, now), { kind: 'server', status: 503, retryAfter: null, busy: false });
        assert.deepEqual(readCompeteAnswer(response(200, null, { contentType: 'text/html' }), null, now), { kind: 'auth', status: 200 }, 'a captive portal');
        assert.deepEqual(readCompeteAnswer(response(302, null), null, now), { kind: 'auth', status: 302 });
        assert.deepEqual(readCompeteAnswer({ type: 'opaqueredirect', status: 0, ok: false, headers: { get: () => null } }, null, now), { kind: 'auth', status: 0 });
        assert.deepEqual(readCompeteAnswer(null, null, now), { kind: 'offline' });

        assert.equal(isDeadLink({ kind: 'client', status: 404, data: { error: 'unknown_link' } }), true);
        assert.equal(isDeadLink({ kind: 'client', status: 409, data: { error: 'round_not_online' } }), false);
        assert.equal(isDeadLink({ kind: 'offline' }), false);

        // A request that throws, or never answers before the timeout: offline - and no sample
        const timers = [];
        const samples = [];
        const answers = [];
        const api = createCompeteApi({
            urls: { state: '/s', heartbeat: '/h', events: '/e', media: '/m' },
            fetch: (url, init) => new Promise((resolve, reject) => {
                init.signal?.addEventListener('abort', () => reject(new Error('aborted')));
            }),
            now: () => 0,
            wallNow: () => START,
            onSample: (sample) => samples.push(sample),
            onAnswer: (name, answer) => answers.push([name, answer.kind]),
            timeoutMs: 15_000,
            schedule: (task, ms) => {
                timers.push({ task, ms });

                return timers.length;
            },
            cancel: () => {},
        });
        const pending = api.state();
        assert.equal(timers[0].ms, 15_000);
        timers[0].task();
        assert.deepEqual(await pending, { kind: 'offline' });
        assert.deepEqual(answers, [['state', 'offline']]);
        assert.deepEqual(samples, []);

        const thrown = createCompeteApi({ urls: { state: '/s' }, fetch: async () => { throw new TypeError('Load failed'); } });
        assert.deepEqual(await thrown.state(), { kind: 'offline' });

        // An error answer carries serverNowMs too: still a clock sample
        const errorSamples = [];
        const refused = createCompeteApi({
            urls: { events: '/e' },
            fetch: async () => response(400, { error: 'invalid_json', serverNowMs: START }),
            now: () => 5,
            wallNow: () => START,
            onSample: (sample) => errorSamples.push(sample.serverNowMs),
        });
        assert.equal((await refused.events({})).kind, 'client');
        assert.deepEqual(errorSamples, [START]);
    },

    async 'connect to the profile: the visitor\'s own cookies, the stateless CSRF token, confirm for an organiser'() {
        const requests = [];
        const api = createCompeteApi({
            urls: { state: '/s', connect: '/en/compete/T/connect' },
            fetch: async (url, init) => {
                requests.push({ url, init });

                return response(200, { status: 'connected' });
            },
        });

        const answer = await api.connect({ csrfToken: 'csrf-token', confirm: true });
        assert.deepEqual(answer, { kind: 'ok', status: 200, data: { status: 'connected' } });

        const { url, init } = requests[0];
        assert.equal(url, '/en/compete/T/connect');
        assert.equal(init.method, 'POST');
        assert.equal(init.credentials, 'same-origin');
        assert.equal(init.redirect, 'manual');
        assert.equal(init.headers['X-CSRF-Token'], 'csrf-token');
        assert.equal(init.body.get('_token'), 'csrf-token');
        assert.equal(init.body.get('confirm'), '1');

        await api.connect({ csrfToken: 'csrf-token' });
        assert.equal(requests[1].init.body.get('confirm'), null, 'no confirm for the automatic connect');

        // No connect route (yet): nothing is sent
        const without = createCompeteApi({ urls: { state: '/s' }, fetch: async () => { throw new Error('must not be called'); } });
        assert.deepEqual(await without.connect({ csrfToken: 'x' }), { kind: 'offline' });
    },
};

const results = {};

for (const [name, run] of Object.entries(scenarios)) {
    try {
        await run();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
