// Runs assets/displays/render.js in jsdom (competition toolkit D4, D-displays-overlays.md §11.2): every element type of
// the v1 catalogue, the phases, timer formats, results source gating per element, puzzles only once revealed, QR,
// leaderboard diffing, the XSS corpus of tests/fixtures/display-design-cases.json (no element or attribute injection),
// and a source scan for innerHTML in the display scripts.
// Prints {"<scenario>": "ok" | "<what failed>"} as JSON (DisplayRenderScriptTest).

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import {
    createRenderer, formatDuration, qrPath, resultsFor, safeImageUrl, statusText, timerText,
} from '../assets/displays/render.js';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const COMPETITION = '018d0004-0000-0000-0000-00000000c0de';
const IMAGE = `competition-displays/${COMPETITION}/018d0004-0000-0000-0000-000000000001.png`;
const IMAGE2 = `competition-displays/${COMPETITION}/018d0004-0000-0000-0000-000000000002.png`;
const START = 1_763_380_000_000;
const results = {};

function check(name, run) {
    try {
        const outcome = run();
        results[name] = outcome === undefined || outcome === true ? 'ok' : String(outcome);
    } catch (error) {
        results[name] = `threw ${error?.stack ?? error}`;
    }
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function equal(actual, expected, message) {
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        throw new Error(`${message}: ${JSON.stringify(actual)} !== ${JSON.stringify(expected)}`);
    }
}

function dom() {
    const window = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>').window;

    return { window, document: window.document, root: window.document.getElementById('root') };
}

function element(id, type, props = {}, extra = {}) {
    return { id, type, frame: { x: 10, y: 20, w: 30, h: 10 }, props, ...extra };
}

function stopwatch(status, startedAtMs, minutesLimit = 60, stoppedAtMs = null) {
    return { roundId: 'r1', status, startedAtMs, stoppedAtMs, minutesLimit, asOfMs: START };
}

function baseState(overrides = {}) {
    return {
        kind: 'screen',
        locale: 'en',
        designVersion: 1,
        competition: { name: 'Results Cup', logoUrl: 'https://img.example/logo.png', seriesLogoUrl: null, organizationLogoUrl: null, location: 'Prague', dateFrom: '2026-11-20', dateTo: '2026-11-21' },
        round: { id: 'r1', name: 'Final', category: 'solo', badge: { bg: '#123456', fg: '#ffffff' }, minutesLimit: 60, startsAtMs: START, timezone: 'Europe/Prague', resultsPublished: false },
        stopwatch: stopwatch(null, null),
        puzzles: [],
        anyPuzzleRevealed: false,
        results: null,
        images: { [IMAGE]: 'https://img.example/a.png', [IMAGE2]: 'https://img.example/b.png' },
        qr: {},
        texts: {
            startsIn: 'Starts in %time%', timesUp: "Time's up", stopped: 'Paused', notStarted: 'Not started', finished: 'finished', piecesPlaced: '%count% pieces', table: 'Table %number%', pieces: '%count% pieces', category: 'Solo', invalid: 'No longer valid',
            standingProvisional: 'Provisional', standingRoundsOf: '%scored% of %count% rounds', standingRoundShort: 'R%number%', standingTotal: 'Total',
            standingFinal: { 3: 'Final - all 3 rounds' }, standingMissing: { 50: '50 pieces missing' },
        },
        ...overrides,
    };
}

function render(elements, state, now = START, extra = {}) {
    const { document, root, window } = dom();
    const design = { schema: 1, elements, ...extra };
    const renderer = createRenderer({ document, root, design, competitionId: COMPETITION, kind: state.kind ?? 'screen' });
    renderer.render(state, now);

    return { renderer, root, document, window, node: (id) => root.querySelectorAll('.msp-el')[elements.findIndex((item) => item.id === id)] };
}

const visible = (node) => node.style.display !== 'none';
const text = (node) => node.textContent;

// ---- pure formatting

check('durations: auto drops hours, hh:mm:ss, mm:ss keeps minutes past 59, rounding', () => {
    equal(formatDuration(65_000, 'auto'), '01:05', 'auto');
    equal(formatDuration(3_725_000, 'auto'), '1:02:05', 'auto with hours');
    equal(formatDuration(65_000, 'hh:mm:ss'), '00:01:05', 'hh:mm:ss');
    equal(formatDuration(4_512_000, 'mm:ss'), '75:12', 'mm:ss');
    equal(formatDuration(1_001, 'auto', 'ceil'), '00:02', 'ceil');
    equal(formatDuration(-5_000, 'auto'), '00:00', 'never negative');
});

check('timer modes per phase', () => {
    const limit = 3_600_000;
    const props = { mode: 'auto', format: 'auto', idleText: 'dashes' };
    equal(timerText(props, { phase: 'idle', elapsedMs: 0, countdownMs: 0, limitMs: limit }), '--:--', 'idle dashes');
    equal(timerText({ ...props, idleText: 'zero' }, { phase: 'idle', elapsedMs: 0, countdownMs: 0, limitMs: limit }), '00:00', 'idle zero');
    equal(timerText({ ...props, idleText: 'blank' }, { phase: 'idle', elapsedMs: 0, countdownMs: 0, limitMs: limit }), '', 'idle blank');
    equal(timerText(props, { phase: 'scheduled', elapsedMs: 0, countdownMs: 9_200, limitMs: limit }), '00:10', 'countdown rounds up');
    equal(timerText(props, { phase: 'running', elapsedMs: 61_900, countdownMs: 0, limitMs: limit }), '01:01', 'elapsed');
    equal(timerText({ ...props, mode: 'elapsed' }, { phase: 'scheduled', elapsedMs: 0, countdownMs: 9_000, limitMs: limit }), '--:--', 'elapsed before start = idle');
    equal(timerText({ ...props, mode: 'remaining' }, { phase: 'scheduled', elapsedMs: 0, countdownMs: 9_000, limitMs: limit }), '1:00:00', 'remaining before start = limit');
    equal(timerText({ ...props, mode: 'remaining' }, { phase: 'running', elapsedMs: 61_500, countdownMs: 0, limitMs: limit }), '58:59', 'remaining');
    equal(timerText({ ...props, mode: 'remaining' }, { phase: 'times_up', elapsedMs: limit, countdownMs: 0, limitMs: limit }), '00:00', 'remaining at times up');
    equal(timerText({ ...props, mode: 'remaining' }, { phase: 'running', elapsedMs: 61_500, countdownMs: 0, limitMs: 0 }), '01:01', 'no limit = elapsed');
    equal(timerText(props, { phase: 'stopped', elapsedMs: 125_000, countdownMs: 0, limitMs: limit }), '02:05', 'stopped');
});

check('status texts and organiser overrides', () => {
    const texts = baseState().texts;
    equal(statusText({ texts: {} }, { phase: 'scheduled', countdownMs: 9_000 }, texts), 'Starts in 0:00:09', 'starts in');
    equal(statusText({ texts: {} }, { phase: 'running', countdownMs: 0 }, texts), '', 'running blank');
    equal(statusText({ texts: { times_up: 'Hands up!' } }, { phase: 'times_up', countdownMs: 0 }, texts), 'Hands up!', 'override');
    equal(statusText({ texts: { scheduled: 'Go in %time%' } }, { phase: 'scheduled', countdownMs: 5_000 }, texts), 'Go in 0:00:05', 'override with time');
});

check('picture URLs: http(s) only', () => {
    equal(safeImageUrl('https://img.example/a.png'), 'https://img.example/a.png', 'https');
    for (const bad of ['javascript:alert(1)', 'data:image/svg+xml;base64,PHN2Zz4=', '//evil.example/x.png', ' https://x', 'vbscript:x', null, 7]) {
        equal(safeImageUrl(bad), null, `refused ${bad}`);
    }
});

// ---- every element type

check('every element type of the v1 catalogue renders', () => {
    const elements = [
        element('el000000', 'timer'),
        element('el000001', 'status'),
        element('el000002', 'progress'),
        element('el000003', 'text', { text: 'Hello\nhall' }),
        element('el000004', 'field', { field: 'competition_name', prefix: '» ' }),
        element('el000005', 'round_badge'),
        element('el000006', 'image', { source: 'upload', path: IMAGE }),
        element('el000007', 'rotator', { images: [{ path: IMAGE }, { path: IMAGE2 }], seconds: 5 }),
        element('el000008', 'clock', { seconds: true }),
        element('el000009', 'puzzle', { show: ['image', 'name', 'brand', 'pieces'] }),
        element('el000010', 'finished_count', { source: 'live' }),
        element('el000011', 'leaderboard', { source: 'live', columns: ['rank', 'flag', 'name', 'members', 'result', 'table'] }),
        element('el000012', 'ticker', { source: 'live' }),
        element('el000013', 'qr'),
        element('el000014', 'box', {}, { style: { background: '#ff0000' } }),
    ];
    const row = { key: 'k1', rank: 1, name: 'Anna Fast', members: [], countries: ['cz'], resultSeconds: 3600, piecesPlaced: null, didNotFinish: false, tableNumber: 4, enteredAtMs: START - 60_000 };
    const state = baseState({
        stopwatch: stopwatch('running', START - 61_000),
        puzzles: [{ name: 'Revealed One', brand: 'Ravensburger', pieces: 500, imageUrl: 'https://img.example/p.jpg' }],
        anyPuzzleRevealed: true,
        results: { published: false, finished: 3, total: 10, top: [row], latest: [row] },
        qr: { el000013: { size: 21, rows: Array.from({ length: 21 }, (_, y) => '1'.repeat(y % 2 === 0 ? 21 : 0).padEnd(21, '0')) } },
    });
    const { node, root } = render(elements, state);

    equal(root.querySelectorAll('.msp-el').length, 15, 'one node per element');
    equal(text(node('el000000')), '01:01', 'timer');
    equal(text(node('el000001')), '', 'status while running');
    assert(node('el000002').querySelector('.msp-el__bar').style.width !== '', 'progress bar width');
    equal(text(node('el000003')), 'Hello\nhall', 'text');
    equal(text(node('el000004')), '» Results Cup', 'field');
    equal(text(node('el000005')), 'Final', 'round badge');
    equal(node('el000005').querySelector('.msp-el__badge').style.backgroundColor, 'rgb(18, 52, 86)', 'badge colours');
    equal(node('el000006').querySelector('img').getAttribute('src'), 'https://img.example/a.png', 'image');
    equal(node('el000007').querySelectorAll('img').length, 2, 'rotator pictures');
    assert(/\d{2}:\d{2}:\d{2}/.test(text(node('el000008'))), `clock ${text(node('el000008'))}`);
    assert(text(node('el000009')).includes('Revealed One') && text(node('el000009')).includes('500 pieces'), 'puzzle');
    equal(text(node('el000010')), '3 / 10 finished', 'finished count');
    assert(text(node('el000011')).includes('Anna Fast') && text(node('el000011')).includes('1:00:00'), 'leaderboard');
    equal(node('el000011').querySelector('.fi').className, 'fi fi-cz', 'flag');
    equal(text(node('el000012')), 'Table 4 · Anna Fast · 1:00:00', 'ticker');
    assert(node('el000013').querySelector('svg path').getAttribute('d').startsWith('M4 4h21'), 'qr');
    equal(node('el000014').querySelector('.msp-el__body').style.backgroundColor, 'rgb(255, 0, 0)', 'box');
    equal(node('el000000').style.left, '10%', 'frame in % of the canvas');
});

check('field values: limit, category, dates, location, an empty value drops its prefix', () => {
    const fields = ['round_limit', 'round_category', 'event_date', 'location', 'round_start'];
    const elements = fields.map((field, index) => element(`fi00000${index}`, 'field', { field, prefix: 'P ' }));
    const { node } = render(elements, baseState());
    equal(text(node('fi000000')), 'P 60 min', 'limit');
    equal(text(node('fi000001')), 'P Solo', 'category');
    equal(text(node('fi000002')), 'P 20 November 2026 – 21 November 2026', 'dates');
    equal(text(node('fi000003')), 'P Prague', 'location');
    assert(/^P \d{2}:\d{2}$/.test(text(node('fi000004'))), `start ${text(node('fi000004'))}`);
    const none = render([element('fi000009', 'field', { field: 'round_name', prefix: 'P ' })], baseState({ round: null, stopwatch: null }));
    equal(text(none.node('fi000009')), '', 'no round');
});

// ---- phases

check('visibility per phase follows the stopwatch', () => {
    const elements = [
        element('ph000001', 'text', { text: 'before' }, { when: { phases: ['idle', 'scheduled'] } }),
        element('ph000002', 'text', { text: 'during' }, { when: { phases: ['running'] } }),
        element('ph000003', 'text', { text: 'after' }, { when: { phases: ['times_up', 'stopped'] } }),
        element('ph000004', 'text', { text: 'never' }, { when: { phases: [] } }),
        element('ph000005', 'text', { text: 'hidden' }, { hidden: true }),
    ];
    const cases = [
        [stopwatch(null, null), [true, false, false]],
        [stopwatch('scheduled', START + 30_000), [true, false, false]],
        [stopwatch('scheduled', START - 1_000), [false, true, false]],
        [stopwatch('running', START - 1_000), [false, true, false]],
        [stopwatch('running', START - 3_601_000), [false, false, true]],
        [stopwatch('stopped', START - 100_000, 60, START - 50_000), [false, false, true]],
    ];

    for (const [watch, expected] of cases) {
        const { node } = render(elements, baseState({ stopwatch: watch }));
        equal(['ph000001', 'ph000002', 'ph000003'].map((id) => visible(node(id))), expected, `phase of ${JSON.stringify(watch)}`);
        assert(!visible(node('ph000004')) && !visible(node('ph000005')), 'never / hidden');
    }
});

check('results and puzzle conditions', () => {
    const elements = [
        element('co000001', 'text', { text: 'a' }, { when: { results: 'published' } }),
        element('co000002', 'text', { text: 'b' }, { when: { results: 'not_published' } }),
        element('co000003', 'text', { text: 'c' }, { when: { puzzle: 'revealed' } }),
        element('co000004', 'text', { text: 'd' }, { when: { puzzle: 'not_revealed' } }),
    ];
    let { node } = render(elements, baseState());
    equal(['co000001', 'co000002', 'co000003', 'co000004'].map((id) => visible(node(id))), [false, true, false, true], 'before');
    ({ node } = render(elements, baseState({ round: { ...baseState().round, resultsPublished: true }, anyPuzzleRevealed: true })));
    equal(['co000001', 'co000002', 'co000003', 'co000004'].map((id) => visible(node(id))), [true, false, true, false], 'after');
});

check('the timer: countdown, big last seconds, warning colour, times up colour', () => {
    const timer = element('tm000001', 'timer', { bigCountdown: 10, warningSeconds: 300, phaseColors: { warning: '#ff0000', times_up: '#00ff00' } }, { style: { fontSize: 100 } });
    let { node } = render([timer], baseState({ stopwatch: stopwatch('scheduled', START + 65_000) }));
    equal(text(node('tm000001')), '01:05', 'countdown');
    ({ node } = render([timer], baseState({ stopwatch: stopwatch('scheduled', START + 7_500) })));
    equal(text(node('tm000001')), '8', 'big number');
    equal(node('tm000001').querySelector('.msp-el__body').style.fontSize, '103px', 'big but inside the frame (10 % of 1080 px)');
    ({ node } = render([timer], baseState({ stopwatch: stopwatch('running', START - 3_400_000) })));
    equal(node('tm000001').querySelector('.msp-el__body').style.color, 'rgb(255, 0, 0)', 'warning');
    equal(text(node('tm000001')), '56:40', 'elapsed');
    ({ node } = render([timer], baseState({ stopwatch: stopwatch('running', START - 3_700_000) })));
    equal(node('tm000001').querySelector('.msp-el__body').style.color, 'rgb(0, 255, 0)', 'times up');
    equal(text(node('tm000001')), '1:00:00', 'times up = limit');
});

check('progress: fill, drain, no limit hidden, warning bar', () => {
    let { node } = render([element('pg000001', 'progress', { warningSeconds: 60, warningColor: '#ff0000', barColor: '#00ff00' })], baseState({ stopwatch: stopwatch('running', START - 1_800_000) }));
    equal(node('pg000001').querySelector('.msp-el__bar').style.width, '50%', 'half');
    equal(node('pg000001').querySelector('.msp-el__bar').style.backgroundColor, 'rgb(0, 255, 0)', 'bar colour');
    ({ node } = render([element('pg000001', 'progress', { direction: 'drain' })], baseState({ stopwatch: stopwatch('running', START - 900_000) })));
    equal(node('pg000001').querySelector('.msp-el__bar').style.width, '75%', 'drain');
    ({ node } = render([element('pg000001', 'progress', { warningSeconds: 60, warningColor: '#ff0000' })], baseState({ stopwatch: stopwatch('running', START - 3_570_000) })));
    equal(node('pg000001').querySelector('.msp-el__bar').style.backgroundColor, 'rgb(255, 0, 0)', 'warning');
    ({ node } = render([element('pg000001', 'progress')], baseState({ stopwatch: { ...stopwatch('running', START - 1_000), minutesLimit: 0 } })));
    equal(node('pg000001').querySelector('.msp-el__track').style.visibility, 'hidden', 'no limit');
});

// ---- results

const ROW = (key, rank, name, extra = {}) => ({ key, rank, name, members: [], countries: [], resultSeconds: 3000 + rank, piecesPlaced: null, didNotFinish: false, tableNumber: rank, enteredAtMs: START - 100_000, ...extra });

check('results: each element honours its own source against published', () => {
    const elements = [
        element('rs000001', 'leaderboard', { source: 'published', whenUnpublished: 'text', unpublishedText: 'Results soon' }),
        element('rs000002', 'leaderboard', { source: 'live' }),
        element('rs000003', 'finished_count', { source: 'published' }),
        element('rs000004', 'ticker', { source: 'published' }),
    ];
    // The server sends rows because another element (rs000002) is live
    const unpublished = { published: false, finished: 2, total: 5, top: [ROW('a', 1, 'Gina Quick')], latest: [ROW('a', 1, 'Gina Quick')] };
    let { node } = render(elements, baseState({ results: unpublished }));
    equal(text(node('rs000001')), 'Results soon', 'published leaderboard waits');
    assert(text(node('rs000002')).includes('Gina Quick'), 'live leaderboard shows');
    equal(text(node('rs000003')), '', 'published count waits');
    equal(text(node('rs000004')), '', 'published ticker waits');
    ({ node } = render(elements, baseState({ results: { ...unpublished, published: true } })));
    assert(text(node('rs000001')).includes('Gina Quick'), 'published now');
    equal(text(node('rs000003')), '2 / 5 finished', 'count now');
    equal(resultsFor({ source: 'live' }, { results: null }).results, null, 'no results at all');
});

check('leaderboard: rows diffed by key - nodes reused and moved, removed rows gone, unfinished as pieces', () => {
    const { document, root } = dom();
    const elements = [element('lb000001', 'leaderboard', { source: 'live', columns: ['rank', 'name', 'members', 'result'], rows: 3 })];
    const renderer = createRenderer({ document, root, design: { schema: 1, elements }, competitionId: COMPETITION, kind: 'screen' });
    const state = (top) => baseState({ results: { published: false, finished: null, total: null, top, latest: [] } });
    renderer.render(state([ROW('a', 1, 'A'), ROW('b', 2, 'B'), ROW('c', 3, 'C', { members: ['C1', 'C2'] })]), START);
    const rows = () => [...root.querySelectorAll('.msp-el__row')];
    const before = Object.fromEntries(rows().map((row) => [row.querySelector('.msp-el__name').textContent, row]));
    equal(rows()[2].querySelector('.msp-el__members').textContent, 'C1, C2', 'members');
    renderer.render(state([ROW('c', 1, 'C'), ROW('a', 2, 'A'), ROW('d', 3, 'D', { didNotFinish: true, piecesPlaced: 850, resultSeconds: 5400 })]), START);
    equal(rows().map((row) => row.querySelector('.msp-el__name').textContent), ['C', 'A', 'D'], 'order');
    assert(rows()[0] === before.C && rows()[1] === before.A, 'nodes reused');
    assert(!root.contains(before.B), 'removed');
    equal(rows()[2].querySelector('.msp-el__cell--result').textContent, '850 pieces', 'unfinished');
    // The highlight of a just added row ends by itself
    renderer.render(state([ROW('e', 1, 'E', { enteredAtMs: START - 2_000 })]), START);
    assert(rows()[0].style.boxShadow !== '', 'highlighted');
    equal(renderer.nextChangeMs(state([ROW('e', 1, 'E', { enteredAtMs: START - 2_000 })]), START), 8_005, 'next change at the highlight end');
});

// ---- puzzles

check('puzzles: none before the reveal (hidden text), every revealed one up to max', () => {
    const elements = [element('pz000001', 'puzzle', { whenHidden: 'text', hiddenText: 'Revealed at the start', max: 2, show: ['name'] })];
    let { node } = render(elements, baseState());
    equal(text(node('pz000001')), 'Revealed at the start', 'hidden');
    equal(node('pz000001').querySelectorAll('.msp-el__puzzle').length, 0, 'no puzzle node');
    const puzzles = ['One', 'Two', 'Three'].map((name) => ({ name, brand: 'B', pieces: 500, imageUrl: null }));
    ({ node } = render(elements, baseState({ puzzles, anyPuzzleRevealed: true })));
    equal([...node('pz000001').querySelectorAll('.msp-el__puzzle-name')].map((item) => item.textContent), ['One', 'Two'], 'revealed up to max');
    assert(!visible(node('pz000001').querySelector('.msp-el__body > .msp-el__line')), 'hidden text gone');
});

// ---- QR, images, background, scale, invalid

check('QR: modules → one path, quiet zone, bad modules draw nothing', () => {
    equal(qrPath({ rows: ['110', '011', '000'] }, 0), { path: 'M0 0h2v1h-2zM1 1h2v1h-2z', size: 3 }, 'path');
    equal(qrPath({ rows: ['1'] }, 4).size, 9, 'quiet zone');
    equal(qrPath({ rows: ['1<script>', '11'] }, 0), null, 'not digits');
    equal(qrPath({ rows: ['10', '1'] }, 0), null, 'not square');
    const { node } = render([element('qr000001', 'qr', { fg: '#112233' })], baseState({ qr: { qr000001: { rows: ['"/><script>', 'x'] } } }));
    equal(node('qr000001').querySelector('svg'), null, 'nothing drawn');
});

check('canvas: background colour / transparent / chroma / image, scaled 16:9 with letterbox', () => {
    let rendered = render([], baseState(), START, { canvas: { background: { type: 'image', color: '#101010', image: { path: IMAGE, fit: 'contain', opacity: 0.5 } } } });
    equal(rendered.root.querySelector('.msp-display__background-image').getAttribute('src'), 'https://img.example/a.png', 'image');
    equal(rendered.root.querySelector('.msp-display__stage').style.backgroundColor, 'rgb(16, 16, 16)', 'colour');
    rendered = render([], baseState({ kind: 'overlay' }), START, { canvas: { background: { type: 'transparent' } } });
    equal(rendered.root.querySelector('.msp-display__stage').style.backgroundColor, 'transparent', 'transparent');
    rendered = render([], baseState(), START, { canvas: { background: { type: 'chroma', chroma: '#00ff00' } } });
    equal(rendered.root.querySelector('.msp-display__stage').style.backgroundColor, 'rgb(0, 255, 0)', 'chroma');
    rendered.renderer.fit(1000, 1000);
    equal(rendered.root.querySelector('.msp-display__stage').style.transform, 'translate(0px, 219px) scale(0.5208333333333334)', 'letterbox');
});

check('invalid link: a screen says so, an overlay shows nothing', () => {
    let rendered = render([element('iv000001', 'text', { text: 'x' })], baseState());
    rendered.renderer.showInvalid('No longer valid');
    equal(rendered.root.querySelector('.msp-display__invalid').textContent, 'No longer valid', 'screen text');
    equal(rendered.root.querySelector('.msp-display__stage').style.display, 'none', 'stage hidden');
    rendered.renderer.showInvalid(null);
    equal(rendered.root.querySelector('.msp-display__stage').style.display, '', 'back');
    rendered = render([element('iv000001', 'text', { text: 'x' })], baseState({ kind: 'overlay' }));
    rendered.renderer.showInvalid('No longer valid');
    equal(rendered.root.querySelector('.msp-display__invalid').style.display, 'none', 'overlay empty');
});

check('next change: every second while running or counting down, nothing while idle', () => {
    const elements = [element('nc000001', 'timer')];
    const { renderer } = render(elements, baseState());
    equal(renderer.nextChangeMs(baseState(), START), null, 'idle');
    equal(renderer.nextChangeMs(baseState({ stopwatch: stopwatch('running', START - 61_300) }), START), 705, 'running');
    equal(renderer.nextChangeMs(baseState({ stopwatch: stopwatch('scheduled', START + 9_200) }), START), 205, 'countdown');
    equal(renderer.nextChangeMs(baseState({ stopwatch: stopwatch('stopped', START - 9_000, 60, START - 1_000) }), START), null, 'stopped');
});

check('design swap: the old elements are gone, the element count is the new design', () => {
    const { renderer, root } = render([element('ds000001', 'text', { text: 'a' }), element('ds000002', 'timer')], baseState());

    for (let i = 0; i < 200; i += 1) {
        renderer.setDesign({ schema: 1, elements: [element('ds000003', 'leaderboard', { source: 'live' })] });
        renderer.setDesign({ schema: 1, elements: [element('ds000001', 'text', { text: 'a' }), element('ds000002', 'timer')] });
    }

    equal(root.querySelectorAll('.msp-el').length, 2, 'two elements');
    equal(renderer.elementCount, 2, 'count');
});

// ---- XSS corpus

const ALLOWED_TAGS = ['DIV', 'SPAN', 'IMG', 'svg', 'rect', 'path'];
const ALLOWED_ATTRIBUTES = ['class', 'style', 'src', 'alt', 'viewBox', 'preserveAspectRatio', 'shape-rendering', 'width', 'height', 'fill', 'd'];
const CLASS_PATTERN = /^(msp-[a-z0-9_-]+|fi|fi-[a-z]{2})$/;
const EVIL = '<img src=x onerror=alert(1)></script><script>alert(1)</script>"\'><svg onload=alert(1)>javascript:alert(1) expression(alert(1)) url(javascript:x)';

function evilStanding() {
    const score = { status: EVIL, scoreSeconds: EVIL, missingPieces: EVIL };
    const row = { key: EVIL, rank: EVIL, complete: EVIL, name: EVIL, members: [EVIL], countries: [EVIL, 'cz" onload="x', 'CZ', 'cz'], totalSeconds: EVIL, totalTimeSeconds: EVIL, totalMissingPieces: EVIL, totalPenaltySeconds: EVIL, roundsScored: EVIL, perRound: [score, EVIL, null], stageName: EVIL };

    return { name: EVIL, category: EVIL, provisional: EVIL, final: EVIL, roundsCounted: EVIL, roundsTotal: EVIL, rounds: [{ name: EVIL, counted: EVIL }, EVIL, null], rows: [row, { ...row, key: 'second' }, EVIL] };
}

function evilState(standingIds = []) {
    const evilRow = { key: EVIL, rank: 1, name: EVIL, members: [EVIL], countries: [EVIL, 'cz" onload="x', 'CZ', 'cz'], resultSeconds: EVIL, piecesPlaced: EVIL, didNotFinish: false, tableNumber: EVIL, enteredAtMs: EVIL };

    return baseState({
        locale: EVIL,
        competition: { name: EVIL, logoUrl: 'javascript:alert(1)', seriesLogoUrl: 'data:text/html,<script>alert(1)</script>', organizationLogoUrl: EVIL, location: EVIL, dateFrom: EVIL, dateTo: EVIL },
        round: { id: 'r1', name: EVIL, category: EVIL, badge: { bg: 'red;background:url(javascript:x)', fg: EVIL }, minutesLimit: 60, startsAtMs: START, timezone: EVIL, resultsPublished: true },
        stopwatch: stopwatch('running', START - 1_000),
        puzzles: [{ name: EVIL, brand: EVIL, pieces: EVIL, imageUrl: 'javascript:alert(1)' }],
        anyPuzzleRevealed: true,
        results: { published: true, finished: EVIL, total: EVIL, top: [evilRow], latest: [evilRow] },
        images: { [IMAGE]: 'javascript:alert(1)', [IMAGE2]: EVIL },
        qr: Object.fromEntries(Array.from({ length: 60 }, (_, index) => [`xss${String(index).padStart(5, '0')}`, { rows: [EVIL] }])),
        texts: Object.fromEntries(Object.keys(baseState().texts).map((key) => [key, EVIL])),
        standings: Object.fromEntries(standingIds.map((id) => [id, evilStanding()])),
    });
}

function injected(root) {
    for (const node of root.querySelectorAll('*')) {
        if (!ALLOWED_TAGS.includes(node.tagName)) {
            return `element ${node.tagName}`;
        }

        for (const attribute of node.getAttributeNames()) {
            if (!ALLOWED_ATTRIBUTES.includes(attribute)) {
                return `attribute ${attribute} on ${node.tagName}`;
            }
        }

        for (const token of (node.getAttribute('class') ?? '').split(/\s+/).filter(Boolean)) {
            if (!CLASS_PATTERN.test(token)) {
                return `class ${token}`;
            }
        }

        const style = node.getAttribute('style') ?? '';

        if (/url\(|javascript|expression|behavior|<|>/i.test(style)) {
            return `style ${style}`;
        }

        if (node.tagName === 'IMG' && node.hasAttribute('src') && !/^https?:\/\//.test(node.getAttribute('src'))) {
            return `img src ${node.getAttribute('src')}`;
        }
    }

    return null;
}

const corpus = JSON.parse(readFileSync(new URL('./fixtures/display-design-cases.json', import.meta.url), 'utf8'));

for (const testCase of corpus.cases) {
    check(`xss corpus: ${testCase.name}`, () => {
        const input = JSON.parse(JSON.stringify(testCase.input ?? null));
        const { document, root } = dom();
        const renderer = createRenderer({ document, root, design: input, competitionId: corpus.competitionId, kind: 'screen' });
        const ids = (Array.isArray(input?.elements) ? input.elements : []).map((item) => item?.id).filter((id) => typeof id === 'string');
        renderer.render(evilState(ids), START);
        renderer.render(baseState(), START);
        renderer.render(evilState(ids), START + 5_000);
        const problem = injected(root);

        return problem === null ? true : problem;
    });
}

check('xss: every element type fed evil data in every string', () => {
    const types = ['timer', 'status', 'progress', 'text', 'field', 'round_badge', 'image', 'rotator', 'clock', 'puzzle', 'finished_count', 'leaderboard', 'ticker', 'qr', 'box'];
    const elements = [];
    types.forEach((type, index) => {
        elements.push(element(`xss${String(index).padStart(5, '0')}`, type, {
            text: EVIL, prefix: EVIL, suffix: EVIL, texts: { running: EVIL }, source: 'live', path: IMAGE, images: [{ path: IMAGE }, { path: IMAGE2 }],
            columns: ['rank', 'flag', 'name', 'members', 'result', 'table'], whenHidden: 'text', hiddenText: EVIL, show: ['image', 'name', 'brand', 'pieces'],
        }));
    });
    ['competition_name', 'round_name', 'round_category', 'round_limit', 'round_start', 'location', 'event_date'].forEach((field, index) => {
        elements.push(element(`fld${String(index).padStart(5, '0')}`, 'field', { field, prefix: EVIL }));
    });
    elements.push(element('lgo00001', 'image', { source: 'competition_logo' }), element('lgo00002', 'image', { source: 'series_logo' }), element('lgo00003', 'image', { source: 'organization_logo' }));
    const { document, root, window } = dom();
    const renderer = createRenderer({ document, root, design: { schema: 1, elements, canvas: { background: { type: 'image', image: { path: IMAGE } } } }, competitionId: COMPETITION, kind: 'screen' });
    renderer.render(evilState(), START);
    const problem = injected(root);
    assert(problem === null, problem);
    assert(root.textContent.includes('<img src=x onerror=alert(1)>'), 'shown as text');
    assert(window.document.querySelectorAll('script').length === 0, 'no script');
    // Countries: only the valid lowercase code becomes a flag class
    equal([...root.querySelectorAll('.fi')].map((flag) => flag.className), ['fi fi-cz'], 'flags');
});

// ---- standings (competition toolkit v2 S2)

const SCORE = (seconds, status = 'finished', missingPieces = 0) => ({ status, scoreSeconds: seconds, missingPieces });
const NOT_COUNTED = { status: 'not_counted', scoreSeconds: null, missingPieces: 0 };
const STANDING_ROW = (key, rank, name, totalSeconds, extra = {}) => ({
    key, rank, complete: true, name, members: [], countries: ['cz'], totalSeconds, totalTimeSeconds: totalSeconds, totalMissingPieces: 0, totalPenaltySeconds: 0, roundsScored: 2,
    perRound: [SCORE(totalSeconds - 1000), SCORE(1000), NOT_COUNTED], ...extra,
});
const STANDING = (rows, extra = {}) => ({
    name: 'World Series - Solo', category: 'solo', provisional: true, final: false, roundsCounted: 2, roundsTotal: 3,
    rounds: [{ name: 'Solo 1', counted: true }, { name: 'Solo 2', counted: true }, { name: 'Solo 3', counted: false }], rows, ...extra,
});
const standingRows = (root) => [...root.querySelectorAll('.msp-standings__rows .msp-standings__row')];

check('standings: title, provisional badge, ranks with podium, rounds, T6 total + missing pieces, incomplete rows', () => {
    const columns = ['rank', 'flag', 'name', 'members', 'total', 'rounds', 'missing'];
    const standing = STANDING([
        STANDING_ROW('a', 1, 'Dora', 2400),
        STANDING_ROW('b', 2, 'Bob', 4900, { totalTimeSeconds: 4800, totalMissingPieces: 50, totalPenaltySeconds: 100, perRound: [SCORE(1300, 'unfinished', 50), SCORE(3600), NOT_COUNTED] }),
        STANDING_ROW('c', 3, 'Team Cyril', 5400, { members: ['Cyril', 'Eve'], countries: ['cz', 'sk'] }),
        STANDING_ROW('d', 4, 'Alice', 6000),
        // A status this renderer does not know yet (no_show) - its score shows
        STANDING_ROW('e', 5, 'Eva', 7200, { perRound: [SCORE(3600, 'no_show'), SCORE(3600), NOT_COUNTED] }),
        STANDING_ROW('f', null, 'Filip', 1500, { complete: false, roundsScored: 1, perRound: [SCORE(1500), { status: 'did_not_start', scoreSeconds: null, missingPieces: 0 }, NOT_COUNTED] }),
    ]);
    const { root, node } = render([element('st000001', 'standings', { columns, rows: 10, title: true })], baseState({ standings: { st000001: standing } }));
    const el = node('st000001');
    equal(el.querySelector('.msp-standings__name').textContent, 'World Series - Solo', 'name');
    equal(el.querySelector('.msp-standings__badge').textContent, 'Provisional', 'badge');
    equal(el.querySelector('.msp-standings__subtitle-text').textContent, '2 of 3 rounds', 'subtitle');
    const rows = standingRows(root);
    equal(rows.map((row) => row.querySelector('.msp-standings__rank').textContent), ['1', '2', '3', '4', '5', '–'], 'ranks');
    equal(rows.slice(0, 4).map((row) => row.querySelector('.msp-standings__rank').className), ['msp-standings__rank msp-standings__rank--1', 'msp-standings__rank msp-standings__rank--2', 'msp-standings__rank msp-standings__rank--3', 'msp-standings__rank'], 'podium');
    equal(rows.map((row) => row.querySelector('.msp-standings__total').textContent), ['40:00', '1:21:40', '1:30:00', '1:40:00', '2:00:00', '25:00'], 'penalised totals');
    equal(rows[1].querySelector('.msp-standings__missing').textContent, '50 pieces missing', 'missing pieces');
    assert(rows[0].querySelector('.msp-standings__missing').style.display === 'none', 'nothing missing = no line');
    equal([...rows[1].querySelectorAll('.msp-standings__cell--round')].map(text), ['21:40*', '1:00:00', '–'], 'rounds: unfinished starred, not counted dashed');
    equal(rows[4].querySelector('.msp-standings__cell--round').textContent, '1:00:00', 'an unknown status shows its score');
    equal(rows[2].querySelector('.msp-standings__sub').textContent, 'Cyril, Eve', 'members');
    equal(rows[5].querySelector('.msp-standings__sub').textContent, '1 of 2 rounds', 'incomplete');
    equal([...rows[2].querySelectorAll('.fi')].map((flag) => flag.className), ['fi fi-cz', 'fi fi-sk'], 'flags');
    const head = el.querySelector('.msp-standings__row--head');
    assert(visible(head), 'head shown with the rounds column');
    equal([...head.querySelectorAll('.msp-standings__cell--round')].map(text), ['R1', 'R2', 'R3'], 'round labels');
    equal(head.querySelector('.msp-standings__total').textContent, 'Total', 'total label');
    // Row and head cells line up: the same fixed widths
    equal(head.querySelector('.msp-standings__cell--total').style.width, rows[0].querySelector('.msp-standings__cell--total').style.width, 'aligned');
});

check('standings: final subtitle, default columns, rows cut, no head without rounds', () => {
    const standing = STANDING([STANDING_ROW('a', 1, 'A', 100), STANDING_ROW('b', 2, 'B', 200), STANDING_ROW('c', 3, 'C', 300)], { provisional: false, final: true, roundsCounted: 3 });
    const { root, node } = render([element('st000001', 'standings', { rows: 2 })], baseState({ standings: { st000001: standing } }));
    const el = node('st000001');
    assert(!visible(el.querySelector('.msp-standings__badge')), 'no badge');
    equal(el.querySelector('.msp-standings__subtitle-text').textContent, 'Final - all 3 rounds', 'final');
    equal(standingRows(root).length, 2, 'rows cut');
    assert(!visible(el.querySelector('.msp-standings__row--head')), 'no head');
    assert(el.querySelector('.msp-standings__cell--round') === null, 'no round cells');
    assert(standingRows(root)[0].querySelector('.msp-standings__missing').style.display === 'none', 'no missing column');
});

check('standings: no standing = whenEmpty (nothing or the own text), a later standing replaces it', () => {
    const { document, root } = dom();
    const elements = [element('st000001', 'standings', { whenEmpty: 'text', emptyText: 'Standings after round 1' }), element('st000002', 'standings')];
    const renderer = createRenderer({ document, root, design: { schema: 1, elements }, competitionId: COMPETITION, kind: 'overlay' });
    renderer.render(baseState({ standings: { st000001: null, st000002: null } }), START);
    const [first, second] = root.querySelectorAll('.msp-el');
    equal(first.querySelector('.msp-standings__empty').textContent, 'Standings after round 1', 'own text');
    assert(!visible(first.querySelector('.msp-standings__title')), 'no title');
    assert(!visible(second.querySelector('.msp-standings__empty')), 'nothing');
    equal(second.querySelectorAll('.msp-standings__rows .msp-standings__row').length, 0, 'no rows');
    // No `standings` in the state at all (an old answer) - the same
    renderer.render(baseState(), START);
    assert(visible(first.querySelector('.msp-standings__empty')), 'still the text');
    renderer.render(baseState({ standings: { st000001: STANDING([STANDING_ROW('a', 1, 'A', 100)]), st000002: null } }), START);
    assert(!visible(first.querySelector('.msp-standings__empty')), 'text gone');
    equal(first.querySelectorAll('.msp-standings__rows .msp-standings__row').length, 1, 'row shown');
    // An unpublished standing afterwards - empty again
    renderer.render(baseState({ standings: { st000001: null, st000002: null } }), START);
    equal(first.querySelectorAll('.msp-standings__rows .msp-standings__row').length, 0, 'rows gone');
    assert(visible(first.querySelector('.msp-standings__empty')), 'text back');
});

check('standings: rows diffed by key (nodes reused and moved), another round count rebuilds the cells', () => {
    const { document, root } = dom();
    const elements = [element('st000001', 'standings', { columns: ['rank', 'name', 'total', 'rounds'] })];
    const renderer = createRenderer({ document, root, design: { schema: 1, elements }, competitionId: COMPETITION, kind: 'screen' });
    const state = (rows, extra = {}) => baseState({ standings: { st000001: STANDING(rows, extra) } });
    renderer.render(state([STANDING_ROW('a', 1, 'A', 100), STANDING_ROW('b', 2, 'B', 200), STANDING_ROW('c', 3, 'C', 300)]), START);
    const before = Object.fromEntries(standingRows(root).map((row) => [row.querySelector('.msp-standings__entrant').textContent, row]));
    renderer.render(state([STANDING_ROW('c', 1, 'C', 90), STANDING_ROW('a', 2, 'A', 100)]), START);
    equal(standingRows(root).map((row) => row.querySelector('.msp-standings__entrant').textContent), ['C', 'A'], 'order');
    assert(standingRows(root)[0] === before.C && standingRows(root)[1] === before.A, 'nodes reused');
    assert(!root.contains(before.B), 'removed');
    equal(standingRows(root)[0].querySelector('.msp-standings__rank').textContent, '1', 'rank updated');
    // A fourth round added to the standing: every row gets its cells again
    const rounds = [...STANDING([]).rounds, { name: 'Solo 4', counted: false }];
    renderer.render(state([STANDING_ROW('c', 1, 'C', 90, { perRound: [SCORE(40), SCORE(50), NOT_COUNTED, NOT_COUNTED] })], { rounds, roundsTotal: 4 }), START);
    equal(standingRows(root)[0].querySelectorAll('.msp-standings__cell--round').length, 4, 'four round cells');
    equal(root.querySelectorAll('.msp-standings__row--head .msp-standings__cell--round').length, 4, 'four labels');
    // The standing changes nothing by itself - no timer for it
    equal(renderer.nextChangeMs(state([STANDING_ROW('c', 1, 'C', 90)]), START), null, 'no next change');
});

check('xss: a standing fed evil data in every string', () => {
    const { document, root, window } = dom();
    const elements = [element('st000001', 'standings', { columns: ['rank', 'flag', 'name', 'members', 'total', 'rounds', 'missing', 'stage'], whenEmpty: 'text', emptyText: EVIL }), element('st000002', 'standings', { whenEmpty: 'text', emptyText: EVIL })];
    const renderer = createRenderer({ document, root, design: { schema: 1, elements }, competitionId: COMPETITION, kind: 'screen' });
    const state = evilState(['st000001']);
    state.standings.st000002 = EVIL;
    renderer.render(state, START);
    renderer.render(baseState(), START);
    renderer.render(state, START + 1_000);
    const problem = injected(root);
    assert(problem === null, problem);
    assert(root.textContent.includes('<img src=x onerror=alert(1)>'), 'shown as text');
    assert(window.document.querySelectorAll('script').length === 0, 'no script');
    equal([...root.querySelectorAll('.fi')].map((flag) => flag.className), ['fi fi-cz', 'fi fi-cz'], 'flags');
});

check('no innerHTML / outerHTML / insertAdjacentHTML / document.write in the display scripts', () => {
    const files = ['../assets/displays/render.js', '../assets/displays/runtime.js', '../assets/displays/schema.js', '../assets/display.js'];

    for (const file of files) {
        const source = readFileSync(new URL(file, import.meta.url), 'utf8');

        if (/\.(?:innerHTML|outerHTML)\b|insertAdjacentHTML\s*\(|document\.write|\beval\(|new Function/.test(source)) {
            return `${file} uses an HTML sink`;
        }
    }

    return true;
});

process.stdout.write(JSON.stringify(results));
