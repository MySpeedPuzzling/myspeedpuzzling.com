// A small invented event for the core suites, and fakes of the network and the clock.

export const ROUND_SOLO = 'r-solo';
export const ROUND_PAIRS = 'r-pairs';
export const ROUND_TEAMS = 'r-teams';

/**
 * Solo, Pairs (duo) and Teams (team, size not set). Kim + Pat = pair "Corners" (table 2), Jo in the pairs round without
 * a pair, Lee + Max = pair "corners" (same name), Ola removed (was in Corners), Ana not in any round, a team "Edge" of
 * three and a team "Flat" of four (usual size of the round: 3 vs 4 → tie → the smaller, 3).
 */
export function smallState(overrides = {}) {
    return {
        serverNow: '2026-10-08T09:00:00+00:00',
        version: 'v1',
        competition: { id: 'c1', name: 'Example Open', isOnline: false, registrationManaged: false, capacity: null },
        rounds: [
            { id: ROUND_SOLO, name: 'Solo', category: 'solo', teamSize: null, started: false, tableNumbersOff: false, piecesCount: 500, resultsPublished: false },
            { id: ROUND_PAIRS, name: 'Pairs', category: 'duo', teamSize: null, started: false, tableNumbersOff: false, piecesCount: 500, resultsPublished: false },
            { id: ROUND_TEAMS, name: 'Teams', category: 'team', teamSize: null, started: false, tableNumbersOff: false, piecesCount: 1000, resultsPublished: false },
        ],
        people: [
            person('p-ana', 'Ana Example'),
            person('p-jo', 'Jo Do'),
            person('p-kim', 'Kim Example', { country: 'us', player: { id: 'pl-kim', visible: true, name: 'Kim E.', code: 'kim01', country: 'us', avatar: null, profileUrl: '/en/player/pl-kim' }, playerResultRounds: [ROUND_SOLO] }),
            person('p-lee', 'Lee Mock'),
            person('p-max', 'Max Demo'),
            person('p-ola', 'Ola Fictive', { removedAt: '2026-10-01T10:00:00+00:00' }),
            person('p-pat', 'Pat Sample', { country: 'ca' }),
            person('p-t1', 'Tia One'),
            person('p-t2', 'Tom Two'),
            person('p-t3', 'Tu Three'),
            person('p-t4', 'Uma Four'),
            person('p-t5', 'Vic Five'),
            person('p-t6', 'Wes Six'),
            person('p-t7', 'Xan Seven'),
        ],
        places: [
            place('e-kim-solo', 'p-kim', ROUND_SOLO, null, { result: { seconds: 3600 } }),
            place('e-pat-solo', 'p-pat', ROUND_SOLO),
            place('e-kim-pairs', 'p-kim', ROUND_PAIRS, 't-corners'),
            place('e-pat-pairs', 'p-pat', ROUND_PAIRS, 't-corners'),
            place('e-ola-pairs', 'p-ola', ROUND_PAIRS, 't-corners'),
            place('e-jo-pairs', 'p-jo', ROUND_PAIRS),
            place('e-lee-pairs', 'p-lee', ROUND_PAIRS, 't-corners2'),
            place('e-max-pairs', 'p-max', ROUND_PAIRS, 't-corners2'),
            place('e-t1', 'p-t1', ROUND_TEAMS, 't-edge'),
            place('e-t2', 'p-t2', ROUND_TEAMS, 't-edge'),
            place('e-t3', 'p-t3', ROUND_TEAMS, 't-edge'),
            place('e-t4', 'p-t4', ROUND_TEAMS, 't-flat'),
            place('e-t5', 'p-t5', ROUND_TEAMS, 't-flat'),
            place('e-t6', 'p-t6', ROUND_TEAMS, 't-flat'),
            place('e-t7', 'p-t7', ROUND_TEAMS, 't-flat'),
        ],
        teams: [
            team('t-corners', ROUND_PAIRS, 'Corners', { table: 2 }),
            team('t-corners2', ROUND_PAIRS, 'corners'),
            team('t-empty', ROUND_PAIRS, null),
            team('t-edge', ROUND_TEAMS, 'Edge'),
            team('t-flat', ROUND_TEAMS, 'Flat', { result: { seconds: 5000 }, enteredAt: '2026-10-08T08:00:00+00:00', enteredBy: 'Eva' }),
        ],
        mercure: { url: 'https://hub.example/.well-known/mercure', topics: ['/competition-participants/c1'], token: 'tok', expiresAt: '2026-10-08T10:00:00+00:00', expiresIn: 3600 },
        ...overrides,
    };
}

export function person(id, name, extra = {}) {
    return {
        id,
        name,
        country: null,
        externalId: null,
        note: null,
        source: 'manual',
        removedAt: null,
        connectedAt: null,
        player: null,
        registration: null,
        playerResultRounds: [],
        ...extra,
    };
}

export function place(id, participantId, roundId, teamId = null, extra = {}) {
    return { id, participantId, roundId, teamId, table: null, result: null, qualified: false, enteredAt: null, enteredBy: null, ...extra };
}

export function team(id, roundId, name, extra = {}) {
    return { id, roundId, name, table: null, result: null, qualified: false, enteredAt: null, enteredBy: null, ...extra };
}

/** Deterministic ids: id1, id2, … */
export function ids(prefix = 'id') {
    let counter = 0;

    return () => `${prefix}${++counter}`;
}

/** A manual clock with timers: advance(ms) runs what falls due, letting promises settle in between. */
export function clock(start = 1_000_000) {
    let now = start;
    let seq = 0;
    const timers = new Map();

    return {
        now: () => now,
        schedule(fn, ms) {
            const id = ++seq;
            timers.set(id, { at: now + Math.max(0, ms), fn, id });

            return id;
        },
        cancel(id) {
            timers.delete(id);
        },
        pending() {
            return [...timers.values()].map((timer) => timer.at - now).sort((a, b) => a - b);
        },
        async advance(ms) {
            const target = now + ms;

            for (;;) {
                await settle();
                const due = [...timers.values()].filter((timer) => timer.at <= target).sort((a, b) => a.at - b.at || a.id - b.id)[0];

                if (due === undefined) {
                    break;
                }

                timers.delete(due.id);
                now = due.at;
                due.fn();
            }

            now = target;
            await settle();
        },
    };
}

/** Lets every queued promise reaction run. */
export async function settle() {
    for (let i = 0; i < 20; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

/**
 * A fake officialResultsRequest(): every call is recorded and waits until the test answers it with reply().
 */
export function fakeServer() {
    const calls = [];

    const request = (url, options = {}) => new Promise((resolve) => {
        calls.push({ url, method: options.method ?? 'GET', body: options.body ?? null, csrfToken: options.csrfToken ?? null, resolve, answered: false });
    });

    return {
        request,
        calls,
        open() {
            return calls.filter((call) => !call.answered);
        },
        /** Answers the oldest open call. */
        async reply(answer) {
            const call = calls.find((candidate) => !candidate.answered);

            if (call === undefined) {
                throw new Error('No request waits for an answer');
            }

            call.answered = true;
            call.resolve(answer);
            await settle();

            return call;
        },
    };
}

/** A server answer applying every group of a sheet request. */
export function appliedAnswer(call, { versionBefore = 'v1', versionAfter = 'v2', deletedTeams = {} } = {}) {
    return {
        kind: 'ok',
        status: 200,
        data: {
            dryRun: false,
            replayed: false,
            versionBefore,
            versionAfter,
            groups: call.body.groups.map((group) => ({
                id: group.id,
                status: 'applied',
                changes: group.changes.map((change, index) => ({ index, status: 'applied', reason: null, message: null, current: null })),
                warnings: [],
                deletedTeams: deletedTeams[group.id] ?? [],
            })),
        },
    };
}
