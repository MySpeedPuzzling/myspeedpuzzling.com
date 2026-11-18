// The competition load test (tools/competition-load/README.md): judges saving results through the live entry's endpoint,
// competitors proposing their times and their phones' heartbeats - over HTTP against the prod-mode stack.
//
//   MIX=saves       6 judges, one save each every 3-8 s (30 % a result + puzzles group of the pick round)
//   MIX=proposals   100 proposals within 10 s
//   MIX=all         both at once (the proposals PROPOSALS_AT into the run) + 300 phones' heartbeats (every 15 s ±20 %),
//                   their idle state polls (every 5 min) and the 5 s state poll of every phone whose proposal waits
//   MIX=single      uncontended: 30 saves, then 30 proposals, one at a time (the service time)
//   MIX=real        real events (`load.sh setup` with ROUND_FORMAT=realevents): 3 rounds at once - 18 judges (6 per round;
//                   on the solo pick round 30 % per-puzzle splits first, then result + puzzles; 30 % grouped at once),
//                   every entrant's phone a heartbeat every 15 s + an idle state poll every 5 min (arrival rates), the
//                   proposers (solo 251-350, one member of teams 111-150 per team round) in BURSTS bursts BURST_GAP_S
//                   apart from PROPOSALS_AT, each then polling its state every 5 s, the 6 displays' state every ~2 s
//                   (DISPLAY_POLL_MS - a display refetches 1.5 s after each live signal)
//
// Options (-e NAME=value): BASE_URL (required), MIX (all), DURATION (3m), PROPOSALS_AT (60s), PROPOSALS (100),
// PROPOSALS_WINDOW_S (10), GROUPED_SHARE (0.3), CREDENTIALS (/out/credentials.json). Writes report.md + summary.json to OUT.
import http from 'k6/http';
import exec from 'k6/execution';
import { sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { SharedArray } from 'k6/data';

const BASE = __ENV.BASE_URL;
const MIX = __ENV.MIX || 'all';
const DURATION = __ENV.DURATION || '3m';
const PROPOSALS = parseInt(__ENV.PROPOSALS || '100', 10);
const PROPOSALS_AT = __ENV.PROPOSALS_AT || '60s';
const PROPOSALS_WINDOW_S = parseFloat(__ENV.PROPOSALS_WINDOW_S || '10');
const GROUPED_SHARE = parseFloat(__ENV.GROUPED_SHARE || '0.3');
const OUT = __ENV.OUT || '/out';
const CREDENTIALS = __ENV.CREDENTIALS || '/out/credentials.json';
// The competitors once for every VU (real events: 1,550 of them) - the rest is small
const COMPETITORS = new SharedArray('competitors', () => JSON.parse(open(CREDENTIALS)).competitors);
const DATA = new SharedArray('meta', () => {
    const data = JSON.parse(open(CREDENTIALS));
    delete data.competitors;
    return [data];
})[0];
const ROUND = DATA.roundId;
const BURSTS = parseInt(__ENV.BURSTS || '2', 10);
const BURST_GAP_S = parseFloat(__ENV.BURST_GAP_S || '45');
const SPLIT_SHARE = parseFloat(__ENV.SPLIT_SHARE || '0.3');
const DISPLAY_POLL_MS = parseFloat(__ENV.DISPLAY_POLL_MS || '2000');
const PROPOSERS = DATA.rounds ? Array.from({ length: COMPETITORS.length }, (_, i) => i).filter((i) => COMPETITORS[i].proposer) : [];
// Judges save entries 1..200, the competitors who propose sit at 201..300 (nothing of theirs has a result)
const JUDGED = 200;

if (!BASE) {
    throw new Error('BASE_URL is required');
}

const saveMs = new Trend('save_ms', true);
const saveGroupedMs = new Trend('save_grouped_ms', true);
const proposeMs = new Trend('propose_ms', true);
const heartbeatMs = new Trend('heartbeat_ms', true);
const judgeStateMs = new Trend('judge_state_ms', true);
const competitorStateMs = new Trend('competitor_state_ms', true);
const saveSplitMs = new Trend('save_split_ms', true);
const displayStateMs = new Trend('display_state_ms', true);
const failures = new Counter('failures');

function seconds(duration) {
    const match = /^(\d+(?:\.\d+)?)(ms|s|m)$/.exec(duration);
    if (!match) {
        throw new Error('duration: ' + duration);
    }
    return parseFloat(match[1]) * { ms: 0.001, s: 1, m: 60 }[match[2]];
}

const runSeconds = seconds(DURATION);

function scenarios() {
    const judges = { executor: 'per-vu-iterations', vus: DATA.judges.length, iterations: 1, maxDuration: DURATION, exec: 'judge' };
    const proposals = { executor: 'per-vu-iterations', vus: PROPOSALS, iterations: 1, maxDuration: DURATION, exec: 'competitor' };
    const phones = { executor: 'per-vu-iterations', vus: COMPETITORS.length, iterations: 1, maxDuration: DURATION, exec: 'phone' };

    switch (MIX) {
        case 'saves':
            return { judges };
        case 'proposals':
            return { proposals: { ...proposals, maxDuration: '2m' } };
        case 'all':
            return { judges, phones, proposals: { ...proposals, startTime: PROPOSALS_AT } };
        case 'real':
            return {
                judges,
                displays: { executor: 'per-vu-iterations', vus: DATA.displays.length, iterations: 1, maxDuration: DURATION, exec: 'display' },
                heartbeats: { executor: 'constant-arrival-rate', rate: COMPETITORS.length, timeUnit: '15s', duration: DURATION, preAllocatedVUs: 40, maxVUs: 300, exec: 'realHeartbeat' },
                idleStates: { executor: 'constant-arrival-rate', rate: COMPETITORS.length, timeUnit: '300s', duration: DURATION, preAllocatedVUs: 10, maxVUs: 100, exec: 'realIdleState' },
                proposals: { executor: 'per-vu-iterations', vus: PROPOSERS.length, iterations: 1, maxDuration: DURATION, startTime: PROPOSALS_AT, exec: 'realProposer' },
            };
        case 'single':
            return { single: { executor: 'per-vu-iterations', vus: 1, iterations: 1, maxDuration: '10m', exec: 'single' } };
        default:
            throw new Error('MIX: ' + MIX);
    }
}

export const options = {
    scenarios: scenarios(),
    summaryTrendStats: ['count', 'avg', 'med', 'p(95)', 'p(99)', 'max'],
    discardResponseBodies: false,
    insecureSkipTLSVerify: true,
    noCookiesReset: true,
};

// --- helpers

function uuid7() {
    const ms = Date.now();
    const hex = (n, width) => n.toString(16).padStart(width, '0');
    const random = (width) => Array.from({ length: width }, () => Math.floor(Math.random() * 16).toString(16)).join('');
    const time = hex(ms, 12);
    const variant = (8 + Math.floor(Math.random() * 4)).toString(16);
    return `${time.slice(0, 8)}-${time.slice(8, 12)}-7${random(3)}-${variant}${random(3)}-${random(12)}`;
}

function signIn(cookie) {
    http.cookieJar().set(BASE, 'REMEMBERME', cookie, { path: '/' });
}

const JSON_HEADERS = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    // The stateless CSRF check (SameOriginCsrfTokenManager): a same-origin fetch + the double-submit marker
    'Sec-Fetch-Site': 'same-origin',
    'X-CSRF-Token': 'csrf-token',
};

function post(path, body, tags) {
    return http.post(BASE + path, JSON.stringify(body), { headers: JSON_HEADERS, tags, timeout: '60s' });
}

function jitter(ms, spread) {
    return ms * (1 - spread + Math.random() * 2 * spread);
}

function fail(kind, response, detail) {
    failures.add(1, { kind });
    if (Math.random() < 0.2) {
        console.warn(`${kind}: ${response.status} ${detail || ''} ${(response.body || '').toString().slice(0, 200)}`);
    }
}

function judgeState(judge) {
    const response = http.get(`${BASE}/en/official-results/rounds/${ROUND}?viewer=1`, { headers: { Accept: 'application/json' }, tags: { name: 'judge_state' } });
    judgeStateMs.add(response.timings.duration);
    if (response.status !== 200) {
        fail('judge_state', response);
    }
}

function save(judge, table, grouped) {
    const entry = `participant_round:018d0098-0000-0000-0002-${String(table).padStart(12, '0')}`;
    const finished = 1800 + Math.floor(Math.random() * 3600);
    const changes = [{ clientChangeId: uuid7(), entry, field: 'result', from: null, to: { seconds: finished } }];
    if (grouped) {
        changes.push({
            clientChangeId: uuid7(),
            entry,
            field: 'puzzles',
            from: null,
            to: [
                { roundPuzzleId: DATA.roundPuzzles[table % 3], done: true, atSeconds: finished - 900 },
                { roundPuzzleId: DATA.roundPuzzles[(table + 1) % 3], done: true, atSeconds: finished },
            ],
        });
    }
    const response = post(`/en/official-results/rounds/${ROUND}/changes`, { changes, dryRun: false, expectedPlayerId: judge.playerId }, { name: 'save' });
    (grouped ? saveGroupedMs : saveMs).add(response.timings.duration);
    let applied = false;
    try {
        applied = response.status === 200 && response.json('outcomes').every((outcome) => outcome.status === 'applied');
    } catch (e) {
        applied = false;
    }
    if (!applied) {
        fail('save', response, `table ${table}`);
    }
}

function propose(competitor) {
    const tappedAtMs = Date.now() - 1500;
    const response = post(`/en/competitor-proposals/rounds/${ROUND}`, {
        proposalId: uuid7(),
        entry: competitor.entry,
        tappedAtMs,
        offsetMs: 0,
        rttMs: 40,
        stateHash: null,
        offsetSampleAgeMs: 20000,
        queuedOffline: false,
        attempts: 1,
        sentAtMs: Date.now(),
        expectedPlayerId: competitor.playerId,
    }, { name: 'propose' });
    proposeMs.add(response.timings.duration);
    let proposed = false;
    try {
        proposed = response.status === 200 && response.json('status') === 'proposed';
    } catch (e) {
        proposed = false;
    }
    if (!proposed) {
        fail('propose', response, competitor.entry);
    }
}

function competitorState(competitor) {
    const response = http.get(`${BASE}/competitor-state/${DATA.competitionId}/p/${competitor.participantId}`, { headers: { Accept: 'application/json' }, tags: { name: 'competitor_state' } });
    competitorStateMs.add(response.timings.duration);
    if (response.status !== 200) {
        fail('competitor_state', response);
    }
}

function heartbeat(competitor, unsent) {
    const response = post('/-/competitor-heartbeat', {
        token: competitor.heartbeat,
        unsent,
        atMs: Date.now(),
        offsetMs: 0,
        rttMs: 40,
        sampleAgeMs: 5000,
    }, { name: 'heartbeat' });
    heartbeatMs.add(response.timings.duration);
    if (response.status !== 200) {
        fail('heartbeat', response);
    }
}

// --- the actors

// A judge: the page's state first (signs in from the remember-me cookie like an open phone), then a save every 3-8 s
// over their own slice of tables until the run ends
export function judge() {
    if (MIX === 'real') {
        return realJudge();
    }
    const index = exec.scenario.iterationInTest;
    const me = DATA.judges[index];
    signIn(me.cookie);
    judgeState(me);
    const ends = Date.now() + (runSeconds - 2) * 1000;
    sleep(Math.random() * 3);

    for (let table = index + 1; table <= JUDGED && Date.now() < ends; table += DATA.judges.length) {
        save(me, table, Math.random() < GROUPED_SHARE);
        sleep(3 + Math.random() * 5);
    }
}

// A competitor who taps Stop: signed in with the page open (its state), the tap somewhere within the window, then
// (MIX=all) the page's 5 s poll while the proposal waits for the judge
export function competitor() {
    const index = exec.scenario.iterationInTest;
    const me = COMPETITORS[JUDGED + (index % (COMPETITORS.length - JUDGED))];
    signIn(me.cookie);
    competitorState(me);
    sleep(Math.random() * PROPOSALS_WINDOW_S);
    propose(me);

    if (MIX !== 'all') {
        return;
    }

    const ends = Date.now() + (runSeconds - seconds(PROPOSALS_AT) - PROPOSALS_WINDOW_S - 2) * 1000;
    while (Date.now() < ends) {
        sleep(jitter(5000, 0.2) / 1000);
        competitorState(me);
    }
}

// Every competitor's phone (MIX=all): heartbeats every 15 s ±20 % (no session - the token), the idle state poll every
// 5 min (signed in)
export function phone() {
    const me = COMPETITORS[exec.scenario.iterationInTest];
    signIn(me.cookie);
    const ends = Date.now() + (runSeconds - 2) * 1000;
    let nextState = Date.now() + Math.random() * 300_000;
    sleep(Math.random() * 15);

    while (Date.now() < ends) {
        heartbeat(me, 0);
        if (Date.now() >= nextState) {
            competitorState(me);
            nextState = Date.now() + jitter(300_000, 0.2);
        }
        sleep(jitter(15000, 0.2) / 1000);
    }
}

// The service time: one request at a time
export function single() {
    const judgeMe = DATA.judges[0];
    signIn(judgeMe.cookie);
    judgeState(judgeMe);
    for (let i = 0; i < 30; i++) {
        save(judgeMe, i + 1, i % 3 === 0);
        sleep(0.5);
    }
    for (let i = 0; i < 5; i++) {
        judgeState(judgeMe);
        sleep(0.5);
    }

    for (let i = 0; i < 30; i++) {
        http.cookieJar().clear(BASE);
        const me = COMPETITORS[JUDGED + i];
        signIn(me.cookie);
        competitorState(me);
        propose(me);
        heartbeat(me, 0);
        sleep(0.5);
    }
}

// --- real events (MIX=real)

function saveReal(judge, round, entry, changes, kind) {
    const response = post(`/en/official-results/rounds/${round.id}/changes`, { changes, dryRun: false, expectedPlayerId: judge.playerId }, { name: kind });
    ({ save: saveMs, save_grouped: saveGroupedMs, save_split: saveSplitMs })[kind].add(response.timings.duration);
    let applied = false;
    try {
        applied = response.status === 200 && response.json('outcomes').every((outcome) => outcome.status === 'applied');
    } catch (e) {
        applied = false;
    }
    if (!applied) {
        fail(kind, response, entry);
    }
}

// A judge of one round (6 per round): the round's state, then a save every 3-8 s over their slice of its tables. On the
// solo pick round ("Solve all 2"): SPLIT_SHARE of the entries get puzzle 1's split first ("Finished now" on it) and
// the result + both puzzles 3-8 s later; GROUPED_SHARE the result + both puzzles at once; the rest the result alone
export function realJudge() {
    const index = exec.scenario.iterationInTest;
    const me = DATA.judges[index];
    const round = DATA.rounds[me.round];
    const ordinal = DATA.judges.slice(0, index).filter((judge) => judge.round === me.round).length;
    const perRound = DATA.judges.filter((judge) => judge.round === me.round).length;
    signIn(me.cookie);
    judgeStateOf(round);
    const ends = Date.now() + (runSeconds - 2) * 1000;
    sleep(Math.random() * 3);

    for (let table = ordinal + 1; table <= round.judged && Date.now() < ends; table += perRound) {
        const entry = round.entries[table - 1];
        const finished = 1800 + Math.floor(Math.random() * 3600);
        const result = { clientChangeId: uuid7(), entry, field: 'result', from: null, to: { seconds: finished } };
        const roll = Math.random();
        if (round.format === 'pick' && roll < SPLIT_SHARE + GROUPED_SHARE) {
            const first = { roundPuzzleId: round.puzzles[table % 2], done: true, atSeconds: finished - 900 };
            const both = [first, { roundPuzzleId: round.puzzles[(table + 1) % 2], done: true, atSeconds: finished }];
            let from = null;
            if (roll < SPLIT_SHARE) {
                saveReal(me, round, entry, [{ clientChangeId: uuid7(), entry, field: 'puzzles', from: null, to: [first] }], 'save_split');
                from = [first];
                sleep(3 + Math.random() * 5);
            }
            saveReal(me, round, entry, [result, { clientChangeId: uuid7(), entry, field: 'puzzles', from, to: both }], 'save_grouped');
        } else {
            saveReal(me, round, entry, [result], 'save');
        }
        sleep(3 + Math.random() * 5);
    }
}

function judgeStateOf(round) {
    const response = http.get(`${BASE}/en/official-results/rounds/${round.id}?viewer=1`, { headers: { Accept: 'application/json' }, tags: { name: 'judge_state' } });
    judgeStateMs.add(response.timings.duration);
    if (response.status !== 200) {
        fail('judge_state', response);
    }
}

function proposeIn(competitor) {
    const tappedAtMs = Date.now() - 1500;
    const response = post(`/en/competitor-proposals/rounds/${DATA.rounds[competitor.round].id}`, {
        proposalId: uuid7(),
        entry: competitor.entry,
        tappedAtMs,
        offsetMs: 0,
        rttMs: 40,
        stateHash: null,
        offsetSampleAgeMs: 20000,
        queuedOffline: false,
        attempts: 1,
        sentAtMs: Date.now(),
        expectedPlayerId: competitor.playerId,
    }, { name: 'propose' });
    proposeMs.add(response.timings.duration);
    let proposed = false;
    try {
        proposed = response.status === 200 && response.json('status') === 'proposed';
    } catch (e) {
        proposed = false;
    }
    if (!proposed) {
        fail('propose', response, competitor.entry);
    }
}

function competitorStateWith(competitor, jar) {
    const params = { headers: { Accept: 'application/json' }, tags: { name: 'competitor_state' } };
    if (jar) {
        params.jar = jar;
    }
    const response = http.get(`${BASE}/competitor-state/${DATA.competitionId}/p/${competitor.participantId}`, params);
    competitorStateMs.add(response.timings.duration);
    if (response.status !== 200) {
        fail('competitor_state', response);
    }
}

// Every entrant's phone: a heartbeat every 15 s (the arrival rate walks through all of them)
export function realHeartbeat() {
    heartbeat(COMPETITORS[exec.scenario.iterationInTest % COMPETITORS.length], 0);
}

// Every entrant's idle state poll every 5 min - each phone its own session (a jar per phone, signed in once)
const jars = {};
export function realIdleState() {
    const index = Math.floor(Math.random() * COMPETITORS.length);
    const me = COMPETITORS[index];
    if (!jars[index]) {
        jars[index] = new http.CookieJar();
        jars[index].set(BASE, 'REMEMBERME', me.cookie, { path: '/' });
    }
    competitorStateWith(me, jars[index]);
}

// A proposer: page open (state), the tap in its burst's window, then the 5 s poll while the proposal waits
export function realProposer() {
    const index = exec.scenario.iterationInTest;
    const me = COMPETITORS[PROPOSERS[index]];
    signIn(me.cookie);
    competitorStateWith(me);
    const burstDelay = (index % BURSTS) * BURST_GAP_S;
    sleep(burstDelay + Math.random() * PROPOSALS_WINDOW_S);
    proposeIn(me);

    const ends = Date.now() + (runSeconds - seconds(PROPOSALS_AT) - burstDelay - PROPOSALS_WINDOW_S - 2) * 1000;
    while (Date.now() < ends) {
        sleep(jitter(5000, 0.2) / 1000);
        competitorStateWith(me);
    }
}

// A display (screen or overlay) pinned to its round: the state after each live signal (debounced 1.5 s) - ~every 2 s
// while the judges save
export function display() {
    const me = DATA.displays[exec.scenario.iterationInTest];
    const ends = Date.now() + (runSeconds - 2) * 1000;
    sleep(Math.random() * 2);
    while (Date.now() < ends) {
        const response = http.get(`${BASE}/display/${me.token}/state`, { headers: { Accept: 'application/json' }, tags: { name: 'display_state' } });
        displayStateMs.add(response.timings.duration);
        if (response.status !== 200) {
            fail('display_state', response);
        }
        sleep(jitter(DISPLAY_POLL_MS, 0.25) / 1000);
    }
}

// --- the report

function row(name, metric) {
    if (!metric) {
        return `| ${name} | 0 | | | | | |`;
    }
    const v = metric.values;
    const f = (x) => (x === undefined ? '' : Math.round(x));
    return `| ${name} | ${v.count} | ${f(v.med)} | ${f(v['p(95)'])} | ${f(v['p(99)'])} | ${f(v.max)} | ${f(v.avg)} |`;
}

export function handleSummary(data) {
    const m = data.metrics;
    const failed = Object.entries(m)
        .filter(([name]) => name.startsWith('failures'))
        .map(([name, metric]) => `${name} ${metric.values.count}`);
    const report = [
        `# competition-load ${MIX} (${DURATION}${MIX === 'all' || MIX === 'real' ? `, proposals at ${PROPOSALS_AT}` : ''}${MIX === 'real' ? `, ${PROPOSERS.length} proposals in ${BURSTS} burst(s) of ${PROPOSALS_WINDOW_S} s, ${BURST_GAP_S} s apart` : ''})`,
        '',
        '| Request | n | p50 ms | p95 ms | p99 ms | max ms | avg ms |',
        '|---|---|---|---|---|---|---|',
        row('save (result)', m.save_ms),
        row('save (result + puzzles)', m.save_grouped_ms),
        row('save (puzzle split alone)', m.save_split_ms),
        row('propose', m.propose_ms),
        row('heartbeat', m.heartbeat_ms),
        row('judge state (round)', m.judge_state_ms),
        row('competitor state', m.competitor_state_ms),
        row('display state', m.display_state_ms),
        '',
        `Failures: ${m.failures ? m.failures.values.count : 0} ${failed.join(', ')}`,
        `HTTP requests: ${m.http_reqs ? m.http_reqs.values.count : 0} (${m.http_reqs ? m.http_reqs.values.rate.toFixed(1) : 0}/s)`,
        '',
    ].join('\n');

    return {
        stdout: report,
        [`${OUT}/report.md`]: report,
        [`${OUT}/summary.json`]: JSON.stringify(data, null, 2),
    };
}
