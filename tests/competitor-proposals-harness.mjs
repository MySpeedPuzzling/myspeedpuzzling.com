// Runs the competitor's "propose my time" modules (competition toolkit B8) through their scenarios and prints
// {scenario: "ok" | error} as JSON - tests/CompetitorProposalsScriptTest.php asserts every one is "ok":
// - assets/competitor_proposals_outbox.js: the phone's outbox (the id and the tap moment kept across resends, offline,
//   a reload, refusals, signed out / stale page, busy server, Undo before / while / after sending, other players)
// - assets/competitor_proposals_view.js: what the round's slot shows (Stop only on the own running entry, the
//   "Propose?" sheet, sending / offline / waiting / confirmed / changed / rejected / withdrawn / reset / refused)
// - assets/competitor_page_live.js: the 5-second poll while something waits for the judge
// - T2 (timing you can trust): proposalBody() / outboxSummary() of the outbox and assets/competitor_heartbeat.js - the
//   beat's body, its rhythm, nothing while hidden, at once when back, an expired token, a reload's unsent tap

import assert from 'node:assert/strict';
import {
    classifyAnswer,
    createMemoryStorage,
    LOCK_RETRY_MS,
    outboxSummary,
    ProposalOutbox,
    proposalBody,
    proposalRequest,
} from '../assets/competitor_proposals_outbox.js';
import { CompetitorHeartbeat, heartbeatBody, heartbeatDelay, HEARTBEAT_MS, MIN_GAP_MS, sendHeartbeat } from '../assets/competitor_heartbeat.js';
import { createServerClock } from '../assets/round_stopwatch_clock.js';
import { currentProposal, FINAL_REFUSALS, proposalPanel, secondsAt, waitsForJudge } from '../assets/competitor_proposals_view.js';
import { CompetitorPageLive, PROPOSAL_POLL_MS } from '../assets/competitor_page_live.js';

const NOW = 1_800_000_000_000;
const ROUND = '018d0020-0000-0000-0000-000000000101';
const ENTRY = 'participant_round:018d0020-0000-0000-0000-000000000201';
const USER = '018d0020-0000-0000-0000-000000000301';

async function settle() {
    for (let i = 0; i < 20; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

/**
 * An outbox with fake time, timers and network: `network.answers` is a queue of what the next requests get (default
 * `{kind: 'ok', data: {status: 'proposed', proposal: …}}`); `network.requests` records every item sent.
 */
function setup({ storage = createMemoryStorage(), userId = USER, lock = null } = {}) {
    const clock = { now: NOW };
    const timers = [];
    const network = { answers: [], requests: [], hold: null };
    const answers = [];

    const outbox = new ProposalOutbox({
        storage,
        userId,
        lock,
        now: () => clock.now,
        schedule: (task, ms) => {
            const timer = { task, at: clock.now + ms };
            timers.push(timer);

            return timer;
        },
        cancel: (timer) => {
            const index = timers.indexOf(timer);

            if (index >= 0) {
                timers.splice(index, 1);
            }
        },
        send: async (item) => {
            network.requests.push(structuredClone(item));

            if (network.hold !== null) {
                await network.hold;
            }

            return network.answers.length > 0
                ? network.answers.shift()
                : { kind: 'ok', data: { status: item.action === 'withdraw' ? 'withdrawn' : 'proposed', proposal: { id: item.id, status: item.action === 'withdraw' ? 'withdrawn' : 'pending' } } };
        },
        onAnswer: (roundId, answer) => answers.push({ roundId, ...answer }),
    });

    async function advance(ms) {
        const until = clock.now + ms;

        for (;;) {
            await settle();
            timers.sort((a, b) => a.at - b.at);
            const next = timers[0];

            if (next === undefined || next.at > until) {
                break;
            }

            timers.shift();
            clock.now = Math.max(clock.now, next.at);
            next.task();
            await settle();
        }

        clock.now = until;
        await settle();
    }

    return { outbox, storage, clock, timers, network, answers, advance };
}

function stopwatch(status, startedAtMs, minutesLimit = 60) {
    return { roundId: ROUND, status, startedAt: null, stoppedAt: null, minutesLimit, startedAtMs, stoppedAtMs: null, asOfMs: NOW, stateHash: 'h1' };
}

function round(overrides = {}) {
    return {
        roundId: ROUND,
        name: 'Final',
        current: true,
        stopwatch: stopwatch('running', NOW - 600_000),
        published: false,
        masked: false,
        entry: { isTeam: false, own: true, unpaired: false, result: null, entry: ENTRY },
        canPropose: true,
        proposal: null,
        ...overrides,
    };
}

function proposal(overrides = {}) {
    return { id: 'p1', status: 'pending', proposedSeconds: 2952, tappedAtMs: NOW - 1000, decided: null, ...overrides };
}

const scenarios = {
    async 'a tap is stored first with its UUIDv7 made from the tap moment, then sent and gone on the answer'() {
        const { outbox, storage, network, answers, advance } = setup();
        await outbox.load();
        const item = await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW - 250, offsetMs: 12.5, rttMs: 80, stateHash: 'h1' });

        assert.match(item.id, /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        assert.equal(parseInt(item.id.replaceAll('-', '').slice(0, 12), 16), NOW - 250, 'the id carries the tap moment');
        assert.equal((await storage.getAll()).length, 1, 'on the phone before anything is sent');
        assert.equal(outbox.hasUnsent(), true);

        await advance(0);
        assert.equal(network.requests.length, 1);
        assert.deepEqual(
            { id: network.requests[0].id, tappedAtMs: network.requests[0].tappedAtMs, entry: network.requests[0].entry, offsetMs: network.requests[0].offsetMs, rttMs: network.requests[0].rttMs, stateHash: network.requests[0].stateHash },
            { id: item.id, tappedAtMs: NOW - 250, entry: ENTRY, offsetMs: 12.5, rttMs: 80, stateHash: 'h1' },
        );
        assert.equal((await storage.getAll()).length, 0);
        assert.equal(outbox.hasUnsent(), false);
        assert.deepEqual(answers.map((a) => [a.roundId, a.kind, a.proposal?.status]), [[ROUND, 'proposed', 'pending']]);
    },

    async 'offline: resent with growing pauses, always the same id and tap moment; at once when the browser is back'() {
        const { outbox, network, answers, advance } = setup();
        await outbox.load();
        network.answers.push({ kind: 'offline' }, { kind: 'offline' }, { kind: 'offline' });
        const item = await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW - 100 });

        await advance(0);
        assert.equal(network.requests.length, 1);
        assert.equal(outbox.itemOf(ROUND).offline, true);
        await advance(999);
        assert.equal(network.requests.length, 1, 'not before 1 s');
        await advance(1);
        assert.equal(network.requests.length, 2);
        await advance(1999);
        assert.equal(network.requests.length, 2, 'then 2 s');
        await advance(1);
        assert.equal(network.requests.length, 3);

        // Back online: no waiting out the 4 s pause
        outbox.connectionBack();
        await advance(0);
        assert.equal(network.requests.length, 4);
        assert.ok(network.requests.every((r) => r.id === item.id && r.tappedAtMs === NOW - 100), 'id + tap moment never change');
        assert.deepEqual(answers.map((a) => a.kind), ['proposed']);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'a reload while offline loses the page, not the tap: the next page sends the same item'() {
        const storage = createMemoryStorage();
        const first = setup({ storage });
        await first.outbox.load();
        first.network.answers.push({ kind: 'offline' });
        const item = await first.outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW - 5000 });
        await first.advance(0);
        first.outbox.close();

        const second = setup({ storage });
        second.clock.now = NOW + 60_000;
        await second.outbox.load();
        assert.equal(second.outbox.itemOf(ROUND)?.id, item.id);
        await second.advance(0);
        assert.equal(second.network.requests.length, 1);
        assert.equal(second.network.requests[0].id, item.id);
        assert.equal(second.network.requests[0].tappedAtMs, NOW - 5000, 'the original tap moment, a minute later');
        assert.equal((await storage.getAll()).length, 0);
    },

    async 'a refusal drops the item and says why - never retried'() {
        const { outbox, network, answers, advance } = setup();
        await outbox.load();
        network.answers.push({ kind: 'refused', reason: 'linked_after_start', message: 'Your profile was linked…', proposal: null });
        await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW });
        await advance(600_000);

        assert.equal(network.requests.length, 1);
        assert.equal(outbox.hasUnsent(), false);
        assert.deepEqual(answers.map((a) => [a.kind, a.reason, a.message]), [['refused', 'linked_after_start', 'Your profile was linked…']]);
    },

    async 'signed out or a stale page: nothing more is sent, everything stays on the phone'() {
        for (const kind of ['auth', 'csrf']) {
            const { outbox, network, advance } = setup();
            await outbox.load();
            network.answers.push({ kind });
            await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW });
            await advance(600_000);

            assert.equal(network.requests.length, 1, kind);
            assert.equal(outbox.blocked, kind);
            assert.equal(outbox.hasUnsent(), true, kind);
        }
    },

    async 'another player signed in on the browser: the tap stays on the phone, never refused (D1)'() {
        assert.deepEqual(classifyAnswer(409, 'basic', { error: 'another_account' }), { kind: 'auth', anotherAccount: true });

        const { outbox, network, advance, answers } = setup();
        await outbox.load();
        network.answers.push(classifyAnswer(409, 'basic', { error: 'another_account' }));
        await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW });
        await advance(600_000);

        assert.equal(network.requests.length, 1);
        // The item names its player - the page sends it as `expectedPlayerId`
        assert.equal(network.requests[0].userId, USER);
        assert.equal(outbox.blocked, 'auth');
        assert.equal(outbox.hasUnsent(), true);
        assert.deepEqual(answers, []);
    },

    async 'a busy server (rate limit) waits for its Retry-After, a server error backs off'() {
        const { outbox, network, advance } = setup();
        await outbox.load();
        network.answers.push({ kind: 'busy', retryAfterMs: 30_000 }, { kind: 'server' });
        await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW });
        await advance(0);
        await advance(29_000);
        assert.equal(network.requests.length, 1);
        await advance(1000);
        assert.equal(network.requests.length, 2);
        // The second failed attempt: 15 s
        await advance(14_999);
        assert.equal(network.requests.length, 2);
        await advance(1);
        assert.equal(network.requests.length, 3);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'Undo of a tap still on the phone: dropped, the server never hears of it'() {
        const { outbox, network, answers, advance } = setup();
        await outbox.load();
        network.answers.push({ kind: 'offline' });
        const item = await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW });
        await advance(0);
        await outbox.withdraw(item.id, ROUND);
        await advance(60_000);

        assert.equal(network.requests.length, 1, 'only the first, offline attempt');
        assert.equal(outbox.hasUnsent(), false);
        assert.deepEqual(answers.map((a) => [a.kind, a.local]), [['withdrawn', true]]);
    },

    async 'Undo while the tap is on its way: withdrawn right after the answer'() {
        const { outbox, network, answers, advance } = setup();
        await outbox.load();
        let release;
        network.hold = new Promise((resolve) => {
            release = resolve;
        });
        const item = await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW });
        await advance(0);
        assert.equal(outbox.isSending(item.id), true);
        await outbox.withdraw(item.id, ROUND);
        network.hold = null;
        release();
        await advance(0);

        assert.deepEqual(network.requests.map((r) => [r.action, r.id]), [['propose', item.id], ['withdraw', item.id]]);
        assert.deepEqual(answers.map((a) => a.kind), ['proposed', 'withdrawn']);
        assert.equal(outbox.hasUnsent(), false);
    },

    async 'Undo of a sent proposal: a withdraw item, sent like a tap (offline too)'() {
        const { outbox, network, answers, advance } = setup();
        await outbox.load();
        network.answers.push({ kind: 'offline' });
        await outbox.withdraw('018d0020-0000-7000-8000-000000000999', ROUND);
        assert.equal(outbox.itemOf(ROUND).action, 'withdraw');
        await advance(0);
        await advance(1000);

        assert.deepEqual(network.requests.map((r) => r.action), ['withdraw', 'withdraw']);
        assert.deepEqual(answers.map((a) => [a.kind, a.proposal?.status]), [['withdrawn', 'withdrawn']]);
    },

    async 'another player\'s taps on the phone are never sent; another tab holding the lock = look again shortly'() {
        const storage = createMemoryStorage([{ id: 'x', action: 'propose', userId: 'someone-else', roundId: ROUND, entry: ENTRY, tappedAtMs: NOW, createdAt: NOW, attempts: 0, nextAttemptAt: 0, offline: false }]);
        let held = false;
        const { outbox, network, advance } = setup({ storage, lock: async (task) => {
            if (held) {
                return false;
            }

            await task();

            return true;
        } });
        await outbox.load();
        await advance(10_000);
        assert.equal(network.requests.length, 0);
        assert.equal(outbox.itemOf(ROUND), null);
        assert.equal(outbox.hasUnsent(), false);

        held = true;
        await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW });
        await advance(0);
        assert.equal(network.requests.length, 0);
        held = false;
        await advance(LOCK_RETRY_MS);
        assert.equal(network.requests.length, 1);
    },

    async 'what each HTTP answer means'() {
        assert.equal(classifyAnswer(200, 'basic', { status: 'proposed', proposal: {} }).kind, 'ok');
        assert.equal(classifyAnswer(200, 'basic', { status: 'known', proposal: {} }).kind, 'ok');
        assert.equal(classifyAnswer(200, 'basic', { status: 'withdrawn', proposal: {} }).kind, 'ok');
        assert.deepEqual(classifyAnswer(409, 'basic', { status: 'refused', reason: 'times_up', message: 'Over', proposal: null }), { kind: 'refused', reason: 'times_up', message: 'Over', proposal: null });
        assert.equal(classifyAnswer(403, 'basic', { status: 'refused', reason: 'linked_after_start', message: 'x', proposal: null }).reason, 'linked_after_start');
        assert.equal(classifyAnswer(404, 'basic', { error: 'proposals_off', message: 'off' }).reason, 'proposals_off');
        assert.equal(classifyAnswer(403, 'basic', { error: 'invalid_csrf_token' }).kind, 'csrf');
        assert.equal(classifyAnswer(401, 'basic', { error: 'sign_in_required' }).kind, 'auth');
        assert.equal(classifyAnswer(0, 'opaqueredirect', null).kind, 'auth');
        assert.deepEqual(classifyAnswer(429, 'basic', { error: 'rate_limited' }, '60'), { kind: 'busy', retryAfterMs: 60_000 });
        assert.equal(classifyAnswer(502, 'basic', null).kind, 'server');
        assert.equal(classifyAnswer(403, 'basic', null).kind, 'busy', 'a proxy page is no verdict');

        // proposalRequest: the JSON, the CSRF header, never following a redirect
        const calls = [];
        const answer = await proposalRequest('/en/competitor-proposals/rounds/r', { proposalId: 'p' }, 'tok', async (url, init) => {
            calls.push({ url, init });

            return { status: 200, type: 'basic', headers: new Map([['Content-Type', 'application/json']]), json: async () => ({ status: 'proposed', proposal: { id: 'p' } }) };
        });
        assert.equal(answer.kind, 'ok');
        assert.equal(calls[0].init.headers['X-CSRF-Token'], 'tok');
        assert.equal(calls[0].init.redirect, 'manual');
        assert.equal(JSON.parse(calls[0].init.body).proposalId, 'p');
        assert.equal((await proposalRequest('/x', {}, 't', async () => {
            throw new TypeError('Failed to fetch');
        })).kind, 'offline');
    },

    async 'the Stop button: only the own entry, while the round runs, without a result and nothing waiting'() {
        const now = NOW;
        assert.equal(proposalPanel({ round: round(), nowMs: now }).button, true);
        assert.equal(proposalPanel({ round: round({ canPropose: undefined }), nowMs: now }).mode, 'none', 'somebody else\'s page / a guest / proposals off');
        assert.equal(proposalPanel({ round: round({ stopwatch: stopwatch('scheduled', now + 5000) }), nowMs: now }).mode, 'none');
        assert.equal(proposalPanel({ round: round({ stopwatch: stopwatch(null, null) }), nowMs: now }).mode, 'none');
        assert.equal(proposalPanel({ round: round({ stopwatch: stopwatch('running', now - 61 * 60_000) }), nowMs: now }).mode, 'none', 'times up');
        assert.equal(proposalPanel({ round: round({ stopwatch: stopwatch('stopped', now - 600_000) }), nowMs: now }).mode, 'none', 'paused');
        assert.equal(proposalPanel({ round: round({ entry: { ...round().entry, result: { seconds: 100 } } }), nowMs: now }).mode, 'none');
        assert.equal(proposalPanel({ round: round({ entry: { ...round().entry, unpaired: true } }), nowMs: now }).mode, 'none');
        assert.equal(proposalPanel({ round: round({ masked: true }), nowMs: now }).mode, 'none');
        assert.equal(proposalPanel({ round: round({ proposal: proposal() }), nowMs: now }).button, false);
    },

    async 'the "Propose?" sheet shows the time of the tap, not of the confirm'() {
        const r = round({ stopwatch: stopwatch('running', NOW - 2952_400) });
        const panel = proposalPanel({ round: r, nowMs: NOW + 4000, confirming: { tappedAtMs: NOW } });

        assert.equal(panel.mode, 'confirm');
        assert.equal(panel.confirm.seconds, 2952);
        assert.equal(secondsAt(r, NOW), 2952);
        assert.equal(panel.button, false);
    },

    async 'states while the tap is on the phone: sending, offline, signed out, stale page, taking back'() {
        const r = round({ stopwatch: stopwatch('running', NOW - 60_000) });
        const item = { id: 'p1', action: 'propose', tappedAtMs: NOW - 10_000, offline: false };

        assert.deepEqual([proposalPanel({ round: r, nowMs: NOW, item }).mode, proposalPanel({ round: r, nowMs: NOW, item }).status.time], ['sending', 50]);
        assert.equal(proposalPanel({ round: r, nowMs: NOW, item: { ...item, offline: true } }).mode, 'offline');
        assert.equal(proposalPanel({ round: r, nowMs: NOW, item, blocked: 'auth' }).mode, 'sign_in_again');
        assert.equal(proposalPanel({ round: r, nowMs: NOW, item, blocked: 'csrf' }).mode, 'reload');
        assert.equal(proposalPanel({ round: r, nowMs: NOW, item }).undo, true);
        // The time's up meanwhile: the tap still shows, still sent
        assert.equal(proposalPanel({ round: r, nowMs: NOW + 3600_000, item }).mode, 'sending');
        assert.equal(proposalPanel({ round: r, nowMs: NOW, item: { id: 'p1', action: 'withdraw' } }).mode, 'withdrawing');
    },

    async 'states from the server: waiting, confirmed, changed by the judge, rejected, withdrawn, reset, recorded otherwise'() {
        const pending = proposalPanel({ round: round({ proposal: proposal() }), nowMs: NOW });
        assert.deepEqual([pending.mode, pending.undo, pending.status.time], ['pending', true, 2952]);

        const confirmed = proposalPanel({ round: round({ proposal: proposal({ status: 'confirmed', decided: { seconds: 2952, reason: null } }), entry: { ...round().entry, result: { seconds: 2952 } } }), nowMs: NOW });
        assert.deepEqual([confirmed.mode, confirmed.status.time, confirmed.button], ['confirmed', 2952, false]);

        const changed = proposalPanel({ round: round({ proposal: proposal({ status: 'confirmed', decided: { seconds: 2955, reason: null } }) }), nowMs: NOW });
        assert.deepEqual([changed.mode, changed.status.time, changed.status.proposed], ['changed', 2955, 2952]);
        assert.equal(JSON.stringify(changed).includes('byName'), false, 'no judge identity anywhere');

        const rejected = proposalPanel({ round: round({ proposal: proposal({ status: 'rejected', decided: { seconds: null, reason: 'not_finished' } }) }), nowMs: NOW });
        assert.deepEqual([rejected.mode, rejected.status.reason, rejected.button], ['rejected', 'not_finished', true], 'the Stop button again while it runs');

        const withdrawn = proposalPanel({ round: round({ proposal: proposal({ status: 'withdrawn' }) }), nowMs: NOW });
        assert.deepEqual([withdrawn.mode, withdrawn.button], ['withdrawn', true]);

        const reset = proposalPanel({ round: round({ proposal: proposal({ status: 'superseded', decided: { seconds: null, reason: 'stopwatch_reset' } }) }), nowMs: NOW });
        assert.equal(reset.mode, 'reset');

        const superseded = proposalPanel({ round: round({ proposal: proposal({ status: 'superseded', decided: { seconds: null, reason: 'result_recorded' } }), entry: { ...round().entry, result: { seconds: 3000 } } }), nowMs: NOW });
        assert.deepEqual([superseded.mode, superseded.button], ['superseded', false]);

        // Toolkit H3: the judge undid the confirmation (result cleared) - Stop again while the round runs, a text otherwise
        const cleared = proposalPanel({ round: round({ proposal: proposal({ status: 'superseded', decided: { seconds: 2952, reason: 'result_cleared' } }) }), nowMs: NOW });
        assert.deepEqual([cleared.mode, cleared.status.time, cleared.button], ['cleared', 2952, true]);
        const clearedAfter = proposalPanel({ round: round({ proposal: proposal({ status: 'superseded', decided: { seconds: 2952, reason: 'result_cleared' } }) }), nowMs: NOW + 3 * 3600 * 1000 });
        assert.deepEqual([clearedAfter.mode, clearedAfter.button], ['cleared', false], 'past the time limit: the text, no Stop');
        const changedAway = proposalPanel({ round: round({ proposal: proposal({ status: 'superseded', decided: { seconds: 2952, reason: 'result_changed' } }), entry: { ...round().entry, result: { seconds: 3000 } } }), nowMs: NOW });
        assert.deepEqual([changedAway.mode, changedAway.button], ['superseded', false]);

        // Published: the official result says it all
        assert.equal(proposalPanel({ round: round({ published: true, proposal: proposal({ status: 'confirmed', decided: { seconds: 2952 } }) }), nowMs: NOW }).mode, 'none');
    },

    async 'refusals are shown plainly; after a final one there is no Stop button'() {
        const final = proposalPanel({ round: round(), nowMs: NOW, answer: { kind: 'refused', reason: 'linked_after_start', message: 'Linked after the start' } });
        assert.deepEqual([final.mode, final.status.message, final.button], ['refused', 'Linked after the start', false]);
        assert.ok(FINAL_REFUSALS.includes('times_up'));

        const again = proposalPanel({ round: round(), nowMs: NOW, answer: { kind: 'refused', reason: 'clock_ahead', message: 'Reload' } });
        assert.equal(again.button, true);

        // already_proposed: the pending one is shown instead (it comes with the answer)
        const known = proposalPanel({ round: round(), nowMs: NOW, answer: { kind: 'refused', reason: 'already_proposed', proposal: proposal({ id: 'teammate' }) } });
        assert.equal(known.mode, 'pending');
    },

    async 'the newer view of the proposal wins: a decided answer over a stale pending state, a later tap over an older one'() {
        assert.equal(currentProposal(proposal(), proposal({ status: 'withdrawn' })).status, 'withdrawn');
        assert.equal(currentProposal(proposal({ status: 'confirmed' }), proposal()).status, 'confirmed');
        assert.equal(currentProposal(proposal({ id: 'old', status: 'rejected', tappedAtMs: NOW - 9000 }), proposal({ id: 'new', tappedAtMs: NOW })).id, 'new');
        assert.equal(currentProposal(proposal({ id: 'new', tappedAtMs: NOW }), proposal({ id: 'old', status: 'withdrawn', tappedAtMs: NOW - 9000 })).id, 'new');
        assert.equal(currentProposal(null, null), null);
        assert.equal(waitsForJudge([{ mode: 'stop' }, { mode: 'pending' }]), true);
        assert.equal(waitsForJudge([{ mode: 'confirmed' }, { mode: 'none' }]), false);
    },

    async 'while something waits for the judge the state is asked for every 5 s (visible tab only)'() {
        const clock = { now: NOW };
        const timers = [];
        const fetches = [];
        const page = { visible: true, waiting: false };
        const live = new CompetitorPageLive({
            state: { serverNowMs: NOW, rounds: [round()] },
            fetchState: async () => {
                fetches.push(clock.now);

                return { status: 'ok', state: { serverNowMs: clock.now, rounds: [round()] }, sentAtMs: clock.now, receivedAtMs: clock.now };
            },
            render: () => {},
            isVisible: () => page.visible,
            fastPoll: () => page.waiting,
            now: () => clock.now,
            random: () => 0.5,
            schedule: (task, ms) => {
                const timer = { task, at: clock.now + ms };
                timers.push(timer);

                return timer;
            },
            cancel: (timer) => {
                const index = timers.indexOf(timer);

                if (index >= 0) {
                    timers.splice(index, 1);
                }
            },
            createEvents: () => ({ start() {}, update() {}, suspend() {}, close() {}, isLive: () => true }),
        });
        const advance = async (ms) => {
            const until = clock.now + ms;

            for (;;) {
                await settle();
                timers.sort((a, b) => a.at - b.at);
                const next = timers[0];

                if (next === undefined || next.at > until) {
                    break;
                }

                timers.shift();
                clock.now = next.at;
                next.task();
            }

            clock.now = until;
            await settle();
        };

        live.start();
        await advance(2000);
        const before = fetches.length;
        await advance(60_000);
        assert.equal(fetches.length, before, 'a live stream: only the 5-minute sample');

        page.waiting = true;
        live.replan();
        await advance(60_000);
        assert.equal(fetches.length - before, 60_000 / PROPOSAL_POLL_MS);

        page.visible = false;
        const hidden = fetches.length;
        await advance(60_000);
        assert.equal(fetches.length, hidden, 'a hidden page asks nothing');

        page.visible = true;
        page.waiting = false;
        live.replan();
        const settled = fetches.length;
        await advance(60_000);
        assert.equal(fetches.length, settled);
        live.close();
    },
};

// ---- T2: timing you can trust

function heartbeatSetup({ rounds = [{ roundId: ROUND, token: 'tok' }], summary = { unsent: 0, oldestUnsentTapMs: null } } = {}) {
    const clock = { now: NOW };
    const timers = [];
    const page = { visible: true, rounds, summary, sent: [], answers: [], samples: [], expired: 0 };
    const heartbeat = new CompetitorHeartbeat({
        send: async (body) => {
            page.sent.push({ at: clock.now, body });

            return page.answers.length > 0 ? page.answers.shift() : { status: 'ok', serverNowMs: clock.now + 500, sentAtMs: clock.now, receivedAtMs: clock.now + 40 };
        },
        active: () => page.rounds,
        summary: async () => page.summary,
        clock: () => ({ atMs: clock.now + 500, offsetMs: 500, rttMs: 40, sampleAgeMs: 1000 }),
        onSample: (sample) => page.samples.push(sample),
        onExpired: () => { page.expired += 1; },
        isVisible: () => page.visible,
        now: () => clock.now,
        schedule: (task, ms) => {
            const timer = { task, at: clock.now + ms };
            timers.push(timer);

            return timer;
        },
        cancel: (timer) => {
            const index = timers.indexOf(timer);

            if (index >= 0) {
                timers.splice(index, 1);
            }
        },
        random: () => 0.5,
    });

    async function advance(ms) {
        const until = clock.now + ms;

        for (;;) {
            await settle();
            timers.sort((a, b) => a.at - b.at);
            const next = timers[0];

            if (next === undefined || next.at > until) {
                break;
            }

            timers.shift();
            clock.now = Math.max(clock.now, next.at);
            next.task();
            await settle();
        }

        clock.now = until;
        await settle();
    }

    return { heartbeat, page, clock, timers, advance };
}

Object.assign(scenarios, {
    async 'T2: a send says how the tap travelled - the tap itself never changes'() {
        const { outbox, network, advance } = setup();
        await outbox.load();
        network.answers.push({ kind: 'offline' }, { kind: 'offline' });
        const item = await outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW - 250, offsetMs: 12.5, rttMs: 80, offsetSampleAgeMs: 4200.4, stateHash: 'h1' });
        assert.equal(item.queuedOffline, false);
        assert.equal(item.offsetSampleAgeMs, 4200.4);

        const first = proposalBody(item, NOW + 0.4);
        assert.deepEqual(first, {
            expectedPlayerId: USER,
            proposalId: item.id,
            entry: ENTRY,
            tappedAtMs: NOW - 250,
            offsetMs: 12.5,
            rttMs: 80,
            stateHash: 'h1',
            offsetSampleAgeMs: 4200,
            queuedOffline: false,
            attempts: 1,
            sentAtMs: NOW,
        });

        // Two sends failed - the third says so, the tap is the same
        await advance(0);
        await advance(1000);
        await advance(2000);
        assert.equal(network.requests.length, 3);
        const third = proposalBody(network.requests[2], NOW + 3000);
        assert.equal(third.attempts, 3);
        assert.equal(third.queuedOffline, true);
        assert.equal(third.tappedAtMs, NOW - 250);
        assert.equal(third.proposalId, item.id);

        // A tap made while the browser said it was offline
        const offline = await setup().outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW, offline: true });
        assert.equal(proposalBody(offline, NOW).queuedOffline, true);
        assert.equal(proposalBody({ ...offline, offsetSampleAgeMs: undefined }, Number.NaN).offsetSampleAgeMs, null);
        assert.equal(proposalBody(offline, Number.NaN).sentAtMs, null);
    },

    async 'T2: a reload keeps the tap - resent with the same id, the original tap and its attempts; the summary holds it'() {
        const storage = createMemoryStorage();
        const first = setup({ storage });
        await first.outbox.load();
        first.network.answers.push({ kind: 'offline' });
        const item = await first.outbox.propose({ roundId: ROUND, entry: ENTRY, tappedAtMs: NOW - 42_000, offsetMs: 3, rttMs: 50 });
        await first.advance(0);
        first.outbox.close();

        // The reloaded page: what the heartbeat reports before anything is sent again
        const items = await storage.getAll();
        assert.deepEqual(outboxSummary(items, USER, ROUND.toUpperCase()), { unsent: 1, oldestUnsentTapMs: NOW - 42_000 });

        const second = setup({ storage });
        await second.outbox.load();
        // Its backoff after the failed send still holds
        await second.advance(1000);
        assert.equal(second.network.requests.length, 1);
        const body = proposalBody(second.network.requests[0], NOW);
        assert.equal(body.proposalId, item.id);
        assert.equal(body.tappedAtMs, NOW - 42_000);
        assert.equal(body.attempts, 2);
        assert.equal(body.queuedOffline, true);
        assert.deepEqual(outboxSummary(await storage.getAll(), USER, ROUND), { unsent: 0, oldestUnsentTapMs: null });
    },

    'T2: the summary counts own taps of the round and the one in the confirm sheet, never withdrawals or others'() {
        const items = [
            { id: 'a', action: 'propose', userId: USER, roundId: ROUND, tappedAtMs: NOW - 5000 },
            { id: 'b', action: 'withdraw', userId: USER, roundId: ROUND },
            { id: 'c', action: 'propose', userId: 'someone-else', roundId: ROUND, tappedAtMs: NOW - 9000 },
            { id: 'd', action: 'propose', userId: USER, roundId: 'another-round', tappedAtMs: NOW - 9000 },
        ];
        assert.deepEqual(outboxSummary(items, USER.toUpperCase(), ROUND), { unsent: 1, oldestUnsentTapMs: NOW - 5000 });
        assert.deepEqual(outboxSummary([], USER, ROUND, NOW - 8000), { unsent: 1, oldestUnsentTapMs: NOW - 8000 }, 'the confirm sheet is a tap the phone holds');
        assert.deepEqual(outboxSummary(items, USER, ROUND, NOW - 1000), { unsent: 2, oldestUnsentTapMs: NOW - 5000 });
        assert.deepEqual(outboxSummary(null, USER, ROUND), { unsent: 0, oldestUnsentTapMs: null });
    },

    'T2: the beat body and its rhythm'() {
        assert.deepEqual(heartbeatBody('tok', { atMs: NOW + 0.6, offsetMs: -41.5, rttMs: 90, sampleAgeMs: 3000.2 }, { unsent: 1, oldestUnsentTapMs: NOW - 9000 }), {
            token: 'tok', atMs: NOW + 1, offsetMs: -41.5, rttMs: 90, sampleAgeMs: 3000, unsent: 1, oldestUnsentTapMs: NOW - 9000,
        });
        assert.deepEqual(heartbeatBody('tok', { offsetMs: Number.NaN }, null), {
            token: 'tok', atMs: null, offsetMs: null, rttMs: null, sampleAgeMs: null, unsent: 0, oldestUnsentTapMs: null,
        });
        assert.equal(heartbeatDelay(() => 0), HEARTBEAT_MS * 0.8);
        assert.equal(heartbeatDelay(() => 1), HEARTBEAT_MS * 1.2);
    },

    async 'T2: beats every 15 s while visible and active, none while hidden, at once when back (2 s apart at most)'() {
        const { heartbeat, page, advance } = heartbeatSetup();
        heartbeat.start();
        await advance(0);
        assert.equal(page.sent.length, 1, 'at once');
        assert.equal(page.sent[0].body.token, 'tok');
        assert.equal(page.sent[0].body.unsent, 0);
        assert.equal(page.samples.length, 1, 'the answer is a clock sample');
        assert.equal(page.samples[0].serverNowMs - page.samples[0].sentAtMs, 500);

        await advance(60_000);
        assert.equal(page.sent.length, 5, 'one per 15 s');

        page.visible = false;
        await advance(60_000);
        assert.equal(page.sent.length, 5, 'a hidden page beats nothing');
        heartbeat.update();
        await advance(60_000);
        assert.equal(page.sent.length, 5);

        page.visible = true;
        heartbeat.nudge();
        await advance(0);
        assert.equal(page.sent.length, 6, 'shown again: at once');
        heartbeat.nudge();
        heartbeat.nudge();
        await advance(0);
        assert.equal(page.sent.length, 6, 'not twice within 2 s');
        await advance(MIN_GAP_MS);
        assert.equal(page.sent.length, 7);

        page.rounds = [];
        heartbeat.update();
        await advance(60_000);
        assert.equal(page.sent.length, 7, 'nothing to beat for: silent');

        page.rounds = [{ roundId: ROUND, token: 'tok2' }];
        heartbeat.update();
        await advance(0);
        assert.equal(page.sent.length, 8, 'a round became active');
        assert.equal(page.sent[7].body.token, 'tok2');
        heartbeat.close();
        await advance(60_000);
        assert.equal(page.sent.length, 8);
    },

    async 'T2: a beat carries the unsent tap; an expired token asks for the state; a failed beat waits for the next'() {
        const { heartbeat, page, advance } = heartbeatSetup({ summary: { unsent: 1, oldestUnsentTapMs: NOW - 30_000 } });
        page.answers.push({ status: 'expired' }, { status: 'error' });
        heartbeat.start();
        await advance(0);
        assert.equal(page.sent[0].body.unsent, 1);
        assert.equal(page.sent[0].body.oldestUnsentTapMs, NOW - 30_000);
        assert.equal(page.expired, 1);
        await advance(15_000);
        assert.equal(page.sent.length, 2);
        assert.equal(page.samples.length, 0);
        await advance(15_000);
        assert.equal(page.sent.length, 3);
        assert.equal(page.samples.length, 1);
        heartbeat.close();
    },

    async 'T2: sendHeartbeat sends no cookie and reads 200 / 403 / errors'() {
        const calls = [];
        const respond = (status, data) => async (url, init) => {
            calls.push({ url, init });

            return { status, ok: status >= 200 && status < 300, json: async () => data };
        };
        let t = NOW;
        const now = () => (t += 10);

        assert.deepEqual(await sendHeartbeat('/-/competitor-heartbeat', { token: 'x', unsent: 0 }, respond(200, { serverNowMs: 5 }), now), { status: 'ok', serverNowMs: 5, sentAtMs: NOW + 10, receivedAtMs: NOW + 20 });
        assert.equal(calls[0].init.credentials, 'omit');
        assert.equal(calls[0].init.method, 'POST');
        assert.deepEqual(JSON.parse(calls[0].init.body), { token: 'x', unsent: 0 });
        assert.deepEqual(await sendHeartbeat('/x', {}, respond(403, { error: 'expired_token', serverNowMs: 1 }), now), { status: 'expired' });
        assert.deepEqual(await sendHeartbeat('/x', {}, respond(429, { error: 'rate_limited', serverNowMs: 1 }), now), { status: 'error' });
        assert.deepEqual(await sendHeartbeat('/x', {}, async () => { throw new TypeError('offline'); }, now), { status: 'error' });
    },

    'T2: the clock says how old the sample behind the offset is'() {
        let local = NOW;
        const clock = createServerClock({ now: () => local });
        assert.equal(clock.sampleAgeMs(), null, 'only a hint');
        clock.observe({ sentAtMs: NOW - 100, receivedAtMs: NOW, serverNowMs: NOW + 1000 });
        local = NOW + 90_000;
        assert.equal(clock.sampleAgeMs(), 90_000);
        clock.observe({ sentAtMs: local - 300, receivedAtMs: local, serverNowMs: local + 1000 });
        assert.equal(clock.sampleAgeMs(), 90_000, 'the best round trip is still the older one');
    },
});

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
