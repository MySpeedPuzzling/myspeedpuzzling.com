// The competition load test (tools/competition-load/README.md): judges saving results through the live entry's endpoint,
// competitors proposing their times and their phones' heartbeats - over HTTP against the prod-mode stack.
//
//   MIX=saves       6 judges, one save each every 3-8 s (30 % a result + puzzles group of the pick round)
//   MIX=proposals   100 proposals within 10 s
//   MIX=all         both at once (the proposals PROPOSALS_AT into the run) + 300 phones' heartbeats (every 15 s ±20 %),
//                   their idle state polls (every 5 min) and the 5 s state poll of every phone whose proposal waits
//   MIX=single      uncontended: 30 saves, then 30 proposals, one at a time (the service time)
//
// Options (-e NAME=value): BASE_URL (required), MIX (all), DURATION (3m), PROPOSALS_AT (60s), PROPOSALS (100),
// PROPOSALS_WINDOW_S (10), GROUPED_SHARE (0.3), CREDENTIALS (/out/credentials.json). Writes report.md + summary.json to OUT.
import http from 'k6/http';
import exec from 'k6/execution';
import { sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL;
const MIX = __ENV.MIX || 'all';
const DURATION = __ENV.DURATION || '3m';
const PROPOSALS = parseInt(__ENV.PROPOSALS || '100', 10);
const PROPOSALS_AT = __ENV.PROPOSALS_AT || '60s';
const PROPOSALS_WINDOW_S = parseFloat(__ENV.PROPOSALS_WINDOW_S || '10');
const GROUPED_SHARE = parseFloat(__ENV.GROUPED_SHARE || '0.3');
const OUT = __ENV.OUT || '/out';
const DATA = JSON.parse(open(__ENV.CREDENTIALS || '/out/credentials.json'));
const ROUND = DATA.roundId;
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
    const phones = { executor: 'per-vu-iterations', vus: DATA.competitors.length, iterations: 1, maxDuration: DURATION, exec: 'phone' };

    switch (MIX) {
        case 'saves':
            return { judges };
        case 'proposals':
            return { proposals: { ...proposals, maxDuration: '2m' } };
        case 'all':
            return { judges, phones, proposals: { ...proposals, startTime: PROPOSALS_AT } };
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
    const me = DATA.competitors[JUDGED + (index % (DATA.competitors.length - JUDGED))];
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
    const me = DATA.competitors[exec.scenario.iterationInTest];
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
        const me = DATA.competitors[JUDGED + i];
        signIn(me.cookie);
        competitorState(me);
        propose(me);
        heartbeat(me, 0);
        sleep(0.5);
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
        `# competition-load ${MIX} (${DURATION}${MIX === 'all' ? `, proposals at ${PROPOSALS_AT}` : ''})`,
        '',
        '| Request | n | p50 ms | p95 ms | p99 ms | max ms | avg ms |',
        '|---|---|---|---|---|---|---|',
        row('save (result)', m.save_ms),
        row('save (result + puzzles)', m.save_grouped_ms),
        row('propose', m.propose_ms),
        row('heartbeat', m.heartbeat_ms),
        row('judge state (300 entries)', m.judge_state_ms),
        row('competitor state', m.competitor_state_ms),
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
