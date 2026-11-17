// Runs the live result entry's offline copy (competition toolkit C5: assets/live_entry_route.js, live_entry_shell.js)
// through its scenarios and prints {scenario: "ok" | error} as JSON - tests/LiveEntryShellScriptsTest.php asserts every
// one is "ok". Route parsing of every live entry URL the service worker may answer with the copy, the round the copy
// shows from the device's snapshots (the current-round port shares its cases with LiveResultsCurrentRoundTest), the
// neutral screens, and the way back to the live page.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseLiveEntryRoute } from '../assets/live_entry_route.js';
import { currentRound, liveUrlOnceLive, loginUrlWithReturn, shellPlan } from '../assets/live_entry_shell.js';
import { createMemorySnapshotStorage, SnapshotStore } from '../assets/live_entry_snapshot.js';
import { parseNameTagUrl } from '../assets/official_results_scan.js';

const ANNA = '018d0020-0000-0000-0000-00000000a001';
const COMPETITION = '018d0020-0000-0000-0000-000000000c01';
const OTHER_COMPETITION = '018d0020-0000-0000-0000-000000000c02';
const ROUND_A = '018d0020-0000-0000-0000-000000000101';
const ROUND_B = '018d0020-0000-0000-0000-000000000102';
const ROUND_C = '018d0020-0000-0000-0000-000000000103';
const PARTICIPANT = '018d0020-0000-0000-0000-0000000000f1';
const NOW = Date.parse('2026-11-21T12:00:00Z');

function round(id, name, startsAt, status = null, startedAt = null) {
    return { id, name, startsAt, stopwatch: { status, startedAt, stoppedAt: null } };
}

function snapshot(roundId, competitionId, roundState, { entries = [], savedAt = NOW - 60000 } = {}) {
    return {
        key: `${ANNA}:${roundId}`,
        playerId: ANNA,
        roundId,
        competitionId,
        savedAt,
        clockOffsetMs: -400,
        clockMeasuredAt: savedAt,
        state: {
            competition: { id: competitionId, name: 'Cup', isOnline: false },
            round: roundState,
            rounds: [],
            entries,
            viewer: { playerId: ANNA, organiser: false },
        },
        eventPeople: [],
    };
}

const morning = snapshot(ROUND_A, COMPETITION, round(ROUND_A, 'Group A', '2026-11-21T09:00:00Z', 'stopped', '2026-11-21T09:00:00Z'));
const running = snapshot(ROUND_B, COMPETITION, round(ROUND_B, 'Group B', '2026-11-21T11:30:00Z', 'running', '2026-11-21T11:31:00Z'), {
    entries: [{ ref: 'participant_round:x', participantId: '018d0020-0000-0000-0000-0000000000f2', name: 'Someone' }],
});
const later = snapshot(ROUND_C, COMPETITION, round(ROUND_C, 'Final', '2026-11-21T16:00:00Z'), {
    entries: [{ ref: 'participant_round:y', participantId: PARTICIPANT, name: 'Tagged' }],
});
const foreign = snapshot('018d0020-0000-0000-0000-000000000201', OTHER_COMPETITION, round('018d0020-0000-0000-0000-000000000201', 'Elsewhere', '2026-11-21T10:00:00Z'));

const scenarios = {
    async 'live_results in every locale'() {
        assert.deepEqual(parseLiveEntryRoute(`/en/live-results/${ROUND_A}`), { kind: 'round', roundId: ROUND_A, locale: 'en' });
        assert.deepEqual(parseLiveEntryRoute(`/zadavani-vysledku/${ROUND_A}`), { kind: 'round', roundId: ROUND_A, locale: 'cs' });

        for (const locale of ['es', 'ja', 'fr', 'de']) {
            assert.deepEqual(parseLiveEntryRoute(`https://myspeedpuzzling.com/${locale}/live-results/${ROUND_A}?entrant=${PARTICIPANT}`), { kind: 'round', roundId: ROUND_A, locale });
        }

        // Upper case ids, a trailing slash
        assert.deepEqual(parseLiveEntryRoute(`/en/live-results/${ROUND_A.toUpperCase()}/`), { kind: 'round', roundId: ROUND_A, locale: 'en' });
        // cs has no prefixed English path
        assert.equal(parseLiveEntryRoute(`/cs/live-results/${ROUND_A}`), null);
    },

    async 'live_results_event in every locale'() {
        assert.deepEqual(parseLiveEntryRoute(`/en/live-results/event/${COMPETITION}`), { kind: 'event', competitionId: COMPETITION, locale: 'en' });
        assert.deepEqual(parseLiveEntryRoute(`/zadavani-vysledku-udalosti/${COMPETITION}`), { kind: 'event', competitionId: COMPETITION, locale: 'cs' });
        assert.deepEqual(parseLiveEntryRoute(`/de/live-results/event/${COMPETITION}`), { kind: 'event', competitionId: COMPETITION, locale: 'de' });
    },

    async 'the printed name tag is read as it always was'() {
        for (const locale of ['en', 'cs', 'es', 'ja', 'fr', 'de']) {
            const url = `https://myspeedpuzzling.com/${locale}/live/${COMPETITION}/p/${PARTICIPANT}`;

            assert.deepEqual(parseLiveEntryRoute(url), { kind: 'participant', competitionId: COMPETITION, id: PARTICIPANT, locale });
            // The scanner's own reading of it is unchanged
            assert.deepEqual(parseNameTagUrl(url), { competitionId: COMPETITION, participantId: PARTICIPANT });
        }

        // The name tag route always has a locale
        assert.equal(parseLiveEntryRoute(`/live/${COMPETITION}/p/${PARTICIPANT}`), null);
    },

    async 'the frozen new tags, with and without a locale'() {
        const lineup = '018d0020-0000-5000-8000-0000000000aa';

        assert.deepEqual(parseLiveEntryRoute(`/live/${COMPETITION}/g/${lineup}`), { kind: 'lineup', competitionId: COMPETITION, id: lineup, locale: null });
        assert.deepEqual(parseLiveEntryRoute(`/fr/live/${COMPETITION}/g/${lineup}`), { kind: 'lineup', competitionId: COMPETITION, id: lineup, locale: 'fr' });
        assert.deepEqual(parseLiveEntryRoute(`/live/${COMPETITION}/t/12`), { kind: 'table', competitionId: COMPETITION, id: 12, locale: null });
        assert.deepEqual(parseLiveEntryRoute(`/cs/live/${COMPETITION}/t/9999`), { kind: 'table', competitionId: COMPETITION, id: 9999, locale: 'cs' });
        assert.deepEqual(parseLiveEntryRoute(`http://other.host/live/${COMPETITION}/e/${ROUND_A}`), { kind: 'entry', competitionId: COMPETITION, id: ROUND_A, locale: null });
        assert.deepEqual(parseLiveEntryRoute(`/ja/live/${COMPETITION}/e/${ROUND_A}`), { kind: 'entry', competitionId: COMPETITION, id: ROUND_A, locale: 'ja' });

        // Tables 1-9999 only
        for (const table of ['0', '10000', '012', 'a', '-1', '1.5']) {
            assert.equal(parseLiveEntryRoute(`/live/${COMPETITION}/t/${table}`), null, table);
        }

        // The rejected `/r/{rid}/e/…` of the design, an unknown kind, an unknown locale
        assert.equal(parseLiveEntryRoute(`/live/${COMPETITION}/r/${ROUND_A}/e/${ROUND_B}`), null);
        assert.equal(parseLiveEntryRoute(`/live/${COMPETITION}/x/${ROUND_A}`), null);
        assert.equal(parseLiveEntryRoute(`/it/live/${COMPETITION}/g/${lineup}`), null);
    },

    async 'garbage and other pages are no live entry URL'() {
        for (const text of [
            '', null, undefined, 'not a url', 'http://[', '/en/live-results-shell', '/zadavani-vysledku-shell', '/en/hub',
            `/en/live-results/${ROUND_A}/extra`, '/en/live-results/not-a-uuid', `/en/live-results/event/${COMPETITION}/x`,
            `/en/official-results/rounds/${ROUND_A}`, `/live/${COMPETITION}`, `/live/not-a-uuid/t/1`,
        ]) {
            assert.equal(parseLiveEntryRoute(text), null, String(text));
        }
    },

    async 'the current-round port picks what PHP picks (shared cases)'() {
        const cases = JSON.parse(readFileSync(new URL('./fixtures/live-current-round-cases.json', import.meta.url), 'utf8'));

        assert.ok(cases.length >= 10);

        for (const testCase of cases) {
            const rounds = testCase.rounds.map((item) => round(item.name.toLowerCase(), item.name, item.startsAt, item.status, item.startedAt));
            const picked = currentRound(rounds, Date.parse(testCase.now));

            assert.equal(picked?.name ?? null, testCase.expected, testCase.case);
        }
    },

    async 'no player on the device: the signed-out screen, nothing of anybody'() {
        for (const player of [null, '', undefined]) {
            assert.deepEqual(shellPlan({ route: parseLiveEntryRoute(`/en/live-results/${ROUND_B}`), player, snapshots: [running], nowMs: NOW }), { screen: 'signedOut' });
        }
    },

    async 'a round URL renders its own snapshot'() {
        const plan = shellPlan({ route: parseLiveEntryRoute(`/en/live-results/${ROUND_A.toUpperCase()}`), player: ANNA, snapshots: [running, morning, later], nowMs: NOW });

        assert.equal(plan.screen, 'round');
        assert.equal(plan.snapshot.roundId, ROUND_A);
        assert.equal(plan.landing, null);
    },

    async 'a round without a snapshot: the no-copy screen, the round asked for, the event first among the others'() {
        const plan = shellPlan({ route: parseLiveEntryRoute(`/en/live-results/${'018d0020-0000-0000-0000-000000000999'}`), player: ANNA, snapshots: [foreign, morning], nowMs: NOW });

        assert.equal(plan.screen, 'noCopy');
        assert.equal(plan.roundId, '018d0020-0000-0000-0000-000000000999');
        assert.deepEqual(plan.others.map((item) => item.roundId), [foreign.roundId, ROUND_A]);

        const event = shellPlan({ route: parseLiveEntryRoute(`/en/live-results/event/${COMPETITION}`), player: ANNA, snapshots: [foreign], nowMs: NOW });

        assert.equal(event.screen, 'noCopy');
        assert.equal(event.roundId, null);

        const ofEvent = shellPlan({ route: parseLiveEntryRoute(`/en/live-results/event/${OTHER_COMPETITION}`), player: ANNA, snapshots: [morning, foreign], nowMs: NOW });

        assert.equal(ofEvent.screen, 'round');

        const nothing = shellPlan({ route: null, player: ANNA, snapshots: [morning, foreign], nowMs: NOW });

        assert.equal(nothing.screen, 'noCopy');
        assert.deepEqual(nothing.others.map((item) => item.roundId), [ROUND_A, foreign.roundId]);
    },

    async 'an event URL renders its current round, a round picked by hand within 12 hours wins'() {
        const route = parseLiveEntryRoute(`/en/live-results/event/${COMPETITION}`);
        const plan = shellPlan({ route, player: ANNA, snapshots: [morning, running, later, foreign], nowMs: NOW });

        assert.equal(plan.snapshot.roundId, ROUND_B);

        const asked = [];
        const picked = shellPlan({
            route,
            player: ANNA,
            snapshots: [morning, running, later],
            nowMs: NOW,
            remembered: (competitionId) => {
                asked.push(competitionId);

                return { roundId: ROUND_C, at: NOW - 3600000 };
            },
        });

        assert.equal(picked.snapshot.roundId, ROUND_C);
        assert.deepEqual(asked, [COMPETITION]);

        // Picked yesterday: the current round again
        assert.equal(shellPlan({ route, player: ANNA, snapshots: [morning, running, later], nowMs: NOW, remembered: () => ({ roundId: ROUND_C, at: NOW - 13 * 3600000 }) }).snapshot.roundId, ROUND_B);
    },

    async 'a name tag opens the entrant in their own round, else the current one says they are not in it'() {
        const route = parseLiveEntryRoute(`/en/live/${COMPETITION}/p/${PARTICIPANT}`);
        const plan = shellPlan({ route, player: ANNA, snapshots: [morning, running, later], nowMs: NOW });

        assert.equal(plan.snapshot.roundId, ROUND_C);
        assert.deepEqual(plan.landing, { kind: 'entrant', participantId: PARTICIPANT });

        const elsewhere = shellPlan({ route, player: ANNA, snapshots: [morning, running], nowMs: NOW });

        assert.equal(elsewhere.snapshot.roundId, ROUND_B);
        assert.deepEqual(elsewhere.landing, { kind: 'entrant', participantId: PARTICIPANT });
    },

    async 'line-up, table and entry tags open the event current round (C8 resolves them)'() {
        for (const path of [`/live/${COMPETITION}/t/12`, `/live/${COMPETITION}/g/${ROUND_A}`, `/en/live/${COMPETITION}/e/${ROUND_A}`]) {
            const plan = shellPlan({ route: parseLiveEntryRoute(path), player: ANNA, snapshots: [morning, running, foreign], nowMs: NOW, remembered: () => ({ roundId: ROUND_A, at: NOW }) });

            assert.equal(plan.screen, 'round', path);
            assert.equal(plan.snapshot.roundId, ROUND_B, path);
            assert.equal(plan.landing, null, path);
        }
    },

    async 'the shell finds the snapshots the live page stored, never another player\'s'() {
        let cookiePlayer = ANNA;
        const store = new SnapshotStore({
            storage: createMemorySnapshotStorage(),
            playerId: ANNA,
            devicePlayer: () => cookiePlayer,
            now: () => NOW,
            delayMs: 0,
        });

        await store.saveNow({ roundId: ROUND_B, state: { ...running.state, mercure: { token: 'secret' } } });

        const plan = shellPlan({ route: parseLiveEntryRoute(`/en/live-results/${ROUND_B}`), player: ANNA, snapshots: await store.list(), nowMs: NOW });

        assert.equal(plan.screen, 'round');
        assert.equal(plan.snapshot.state.round.name, 'Group B');
        assert.equal(plan.snapshot.state.mercure, undefined);

        // The cookie names somebody else now: nothing to show
        cookiePlayer = '018d0020-0000-0000-0000-00000000b002';
        assert.equal(shellPlan({ route: parseLiveEntryRoute(`/en/live-results/${ROUND_B}`), player: cookiePlayer, snapshots: await store.list(), nowMs: NOW }).screen, 'noCopy');
    },

    async 'sign-in comes back to the URL asked for'() {
        assert.equal(loginUrlWithReturn('/en/login', `/en/live/${COMPETITION}/p/${PARTICIPANT}`), `/en/login?return=${encodeURIComponent(`/en/live/${COMPETITION}/p/${PARTICIPANT}`)}`);
        assert.equal(loginUrlWithReturn('/prihlaseni?x=1', '/zadavani-vysledku/a?b=c'), '/prihlaseni?x=1&return=%2Fzadavani-vysledku%2Fa%3Fb%3Dc');
        // Never another site
        assert.equal(loginUrlWithReturn('/en/login', '//evil.example'), '/en/login');
        assert.equal(loginUrlWithReturn('/en/login', 'https://evil.example/'), '/en/login');
    },

    async 'going live: the address becomes the round live URL'() {
        assert.equal(liveUrlOnceLive(`https://x.test/en/live-results/event/${COMPETITION}`, `/en/live-results/${ROUND_B}`), `/en/live-results/${ROUND_B}`);
        assert.equal(liveUrlOnceLive(`https://x.test/live/${COMPETITION}/t/4`, `/en/live-results/${ROUND_B}`), `/en/live-results/${ROUND_B}`);
        assert.equal(liveUrlOnceLive(`https://x.test/en/live-results/${ROUND_B}?entrant=x`, `/en/live-results/${ROUND_B}`), null);
        assert.equal(liveUrlOnceLive('not a url', `/en/live-results/${ROUND_B}`), null);
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
