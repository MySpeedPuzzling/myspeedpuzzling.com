// Runs the Analytics Studio's browser modules (assets/analytics-studio/*.js, docs/features/competitions-management/
// analytics-studio.md §7 + §0a) through their scenarios: the model (search with diacritics folding, team names,
// countries, table numbers; filters; sorts; R8 - live results never ordered; compare cap 4 and best values), the URL
// round trip, every key of the keymap (ignored while typing except Esc/↑/↓/Enter), the refresh planner with a fake clock
// (push jitter 0-1 s, reveal at nextRevealAtMs + 300 ms + jitter, polling 10 s / 60 s ±20 %, backoff to 5 min,
// visibleHash change → analytics + open entrant + predictions), formatting, and render.js in jsdom (every panel, the
// empty states, a hidden slot without puzzle data, the XSS corpus, a source scan for HTML sinks).
// Prints {"<scenario>": "ok" | "<what failed>"} as JSON (tests/AnalyticsStudioScriptTest.php).

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import {
    activeSlot,
    applyAction,
    availableFilters,
    availableSorts,
    clockPhase,
    compareTable,
    DEFAULT_SORT,
    entrantRows,
    initialView,
    MAX_COMPARE,
    officialRanking,
    predictionOf,
    resultOf,
    slotsOf,
    stepSlot,
    toggleCompare,
    visibleRows,
} from '../assets/analytics-studio/model.js';
import { decodeView, encodeView, resolveView } from '../assets/analytics-studio/url.js';
import { keyAction, KEYMAP } from '../assets/analytics-studio/keys.js';
import {
    AnalyticsStudioRefresh,
    GONE_POLL_MS,
    IDLE_POLL_MS,
    isLiveRound,
    LIVE_POLL_MS,
    MAX_POLL_MS,
    pollDelay,
    PUSH_JITTER_MS,
    REVEAL_GRACE_MS,
    revealDelay,
} from '../assets/analytics-studio/refresh.js';
import {
    flagOf, formatClock, formatCount, formatDate, formatDelta, formatDuration, formatRange, formatStartsAt, interpolate,
} from '../assets/analytics-studio/format.js';
import { createAnalyticsStudioRenderer, LABEL_NAMES, safeImageUrl, safePath } from '../assets/analytics-studio/render.js';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const fixture = (name) => JSON.parse(readFileSync(new URL(`./fixtures/analytics-studio-${name}.json`, import.meta.url), 'utf8'));
const STATE = fixture('state');
const ANALYTICS = fixture('analytics');
const ENTRANT = fixture('entrant');
const PREDICTIONS = fixture('predictions');
const NOW = STATE.serverNowMs;
const ROUND = STATE.round.id;
const A = STATE.slots[0].puzzle.key;
const B = STATE.slots[1].puzzle.key;
const E = (n) => `018d0040-0000-7000-8000-0000000000e${n}`;

// English texts of every label - the list S3 adds to messages.en.yml as analytics_studio.<name>
export const ENGLISH = {
    search_placeholder: 'Search name, country, table (/)',
    sort: 'Sort',
    'sort.name': 'Name',
    'sort.table': 'Table',
    'sort.solves': 'Solved before',
    'sort.best': 'Best on this puzzle',
    'sort.rating': 'MSP Rating',
    'sort.rank': 'Official rank',
    'filter.all': 'All',
    'filter.solved': 'Solved this puzzle',
    'filter.never': 'Never solved',
    'filter.nohistory': 'No MSP history',
    'tab.entrants': 'Entrants',
    'tab.puzzle': 'Puzzle',
    'tab.live': 'Live',
    'clock.idle': 'Not started',
    'clock.scheduled': 'Starts in %time%',
    'clock.running': 'Running',
    'clock.times_up': "Time's up",
    'clock.stopped': 'Stopped',
    'clock.left': '%time% left',
    'live.count': 'Live: %count% confirmed',
    'official.count': '%count% official results',
    'slot.hidden': 'Revealed at the start',
    'slot.reveals_in': 'Revealed in %time%',
    'slot.image_later': 'Picture shown later',
    compare: 'Compare %count%/%max%',
    'compare.clear': 'Clear',
    'compare.full': 'At most %max% entrants can be compared',
    'compare.add': 'Add to compare',
    'compare.remove': 'Remove from compare',
    'compare.pick_more': 'Pick one more entrant to compare',
    'column.table': 'Table',
    'column.solves': 'Solved',
    'column.best': 'Best',
    'column.rating': 'Rating',
    'column.result': 'Result',
    'empty.no_entrants': 'No entrants yet',
    'empty.no_match': 'Nobody matches the search',
    'empty.no_puzzle': 'No puzzle in this round yet',
    'empty.no_history': 'No public MySpeedPuzzling history',
    'empty.first_time': 'First time on this puzzle',
    'empty.nobody_logged': 'Nobody has logged this puzzle yet',
    'empty.not_rated': 'Not rated yet',
    'empty.select': 'Pick an entrant to see their numbers',
    'empty.no_results': 'No results yet',
    loading: 'Loading…',
    opted_out: "Not shown at the player's wish",
    private_puzzler: 'Private puzzler',
    profile: 'Profile',
    table: 'Table %number%',
    close: 'Close',
    'result.official': 'Official result',
    'result.live': 'Confirmed',
    'result.live_note': 'Confirmed by the judge, not official',
    'result.qualified': 'Q',
    'result.unfinished': '%placed%/%total% pieces',
    now: 'now',
    before_round: 'Before this round',
    rating: 'MSP Rating',
    rating_place: '#%place%',
    skill: 'Skill',
    solved_total: 'Puzzles solved',
    best500: 'Best 500',
    'skill.enthusiast': 'Enthusiast',
    'skill.apprentice': 'Apprentice',
    'skill.proficient': 'Proficient',
    'skill.advanced': 'Advanced',
    'skill.expert': 'Expert',
    'skill.master': 'Master',
    'skill.legend': 'Legend',
    'history.solves': 'Solved before',
    'history.first': 'First',
    'history.best': 'Best',
    'history.last': 'Last',
    'history.first_try': 'First try',
    'history.place': '#%place% of %count%',
    'history.unfinished': 'Unfinished',
    'history.as_group': 'In a pair or team',
    'history.lineup': 'Line-up %count%×',
    'history.members_solved': 'members: %solved% of %total% solved',
    prediction: 'Prediction',
    'prediction.solo': 'Solo prediction',
    'prediction.range': '(%range%)',
    'prediction.method.personal': 'from their own times',
    'prediction.method.general': 'from similar puzzlers',
    'similar.brand': 'Same brand, %pieces% pieces',
    'similar.size': 'All %pieces% pieces',
    'similar.count': 'Solved',
    'similar.median': 'median %time%',
    'similar.best': 'best %time%',
    attempts: 'Attempts',
    'attempt.first': 'first try',
    'attempt.unboxed': 'unboxed',
    'attempt.solo': 'solo',
    'attempt.pair': 'pair',
    'attempt.team': 'team',
    'attempt.unfinished': 'unfinished (%placed% pieces)',
    recent_similar: 'Recent similar puzzles',
    recent_form: 'Recent form at %pieces% pieces',
    'recent_form.median': 'median %time%',
    earlier_rounds: 'Earlier rounds at this event',
    'puzzle.pieces': '%count% pieces',
    'puzzle.brand': 'Brand',
    difficulty: 'Difficulty',
    'difficulty.very_easy': 'Very easy',
    'difficulty.easy': 'Easy',
    'difficulty.average': 'Average',
    'difficulty.challenging': 'Challenging',
    'difficulty.hard': 'Hard',
    'difficulty.very_hard': 'Very hard',
    'difficulty.confidence': 'confidence: %confidence%',
    'stats.solvers': 'Solvers',
    'stats.times_logged': 'Times logged',
    'stats.median': 'Median',
    'stats.percentiles': 'Percentiles',
    'stats.histogram': 'Distribution of best times',
    'stats.first_tries': 'First tries',
    'stats.first_try_median': 'First-try median',
    'stats.fastest_first_try': 'Fastest first try',
    'stats.top': 'Top 10',
    'stats.in_round': 'In this round',
    'stats.group': 'Pairs/teams solved',
    'stats.group_median': 'Pairs/teams median',
    'stats.fastest': 'Fastest',
    pick_format: 'Pick %count% of %total%',
    help: 'Keyboard shortcuts',
    'help.title': 'Keyboard shortcuts',
    'keys.focus_search': 'Search',
    'keys.escape': 'Clear the search / close',
    'keys.move': 'Move in the list',
    'keys.open': 'Open the entrant',
    'keys.compare': 'Add to or remove from compare (max 4)',
    'keys.clear_compare': 'Clear compare',
    'keys.slot': 'Previous / next puzzle',
    'keys.help': 'This help',
};

const clone = (value) => structuredClone(value);

function rowsFor(state = STATE, analytics = ANALYTICS, label = 'A') {
    const slot = activeSlot(state, label);

    return { slot, rows: entrantRows(state, analytics, slot?.visible ? slot.key : null) };
}

function shownKeys(options, state = STATE, analytics = ANALYTICS, label = 'A') {
    const { slot, rows } = rowsFor(state, analytics, label);

    return visibleRows(rows, { state, slot, ...options }).map((row) => row.key);
}

// A pairs round built from the fixture: two pairs, one with a line-up history
function pairState() {
    const state = clone(STATE);
    state.round.category = 'pair';
    state.entrants = [
        { key: E(7), isTeam: true, teamName: 'Žraloci', table: 4, members: [
            { name: 'Petr Dvořák', country: 'cz', profile: '/en/player-profile/p7', analysable: true },
            { name: 'Eva Malá', country: 'sk', profile: null, analysable: false },
        ] },
        { key: E(8), isTeam: true, teamName: null, table: 9, members: [
            { name: 'Lea Roth', country: 'de', profile: '/en/player-profile/p8a', analysable: true },
            { name: 'Max Roth', country: 'de', profile: '/en/player-profile/p8b', analysable: true },
        ] },
    ];
    state.results = [];
    const analytics = clone(ANALYTICS);
    analytics.entrants = {
        [E(7)]: { members: [{ ...ANALYTICS.entrants[E(1)].members[0] }, {}], lineup: null },
        [E(8)]: {
            members: [{ ...ANALYTICS.entrants[E(5)].members[0] }, { ...ANALYTICS.entrants[E(6)].members[0] }],
            lineup: { perPuzzle: { [A]: { solves: 2, bestSeconds: 1500, firstSeconds: 1900 } } },
        },
    };

    return { state, analytics };
}

function publishedState() {
    const state = clone(STATE);
    state.round.published = true;
    state.round.liveResults = false;
    state.round.stopwatch = { ...state.round.stopwatch, status: 'stopped', stoppedAtMs: NOW - 1000 };
    state.results = [
        { key: E(6), state: 'official', seconds: 2900, piecesPlaced: null, piecesCount: null, rank: 2, qualified: true },
        { key: E(1), state: 'official', seconds: 2475, piecesPlaced: null, piecesCount: null, rank: 1, qualified: true },
        { key: E(5), state: 'official', seconds: null, piecesPlaced: 412, piecesCount: 500, rank: 3, qualified: false },
    ];

    return state;
}

const scenarios = {};
const scenario = (name, run) => {
    scenarios[name] = run;
};

// ---- format

scenario('format: durations, deltas, ranges, counts, clock, flags, placeholders', () => {
    assert.equal(formatDuration(2467), '41:07');
    assert.equal(formatDuration(3725), '1:02:05');
    assert.equal(formatDuration(59), '0:59');
    assert.equal(formatDuration(null), '—');
    assert.equal(formatDuration(-5), '—');
    assert.equal(formatDelta(2600, 2467), '+2:13');
    assert.equal(formatDelta(2422, 2467), '−0:45');
    assert.equal(formatDelta(10, 10), '±0:00');
    assert.equal(formatDelta(null, 10), null);
    assert.equal(formatRange(2400, 2820), '40:00–47:00');
    assert.equal(formatRange(2400, null), null);
    assert.equal(formatCount(5623, 'en'), '5,623');
    assert.equal(formatCount(null), '—');
    assert.equal(formatClock(2472_000), '0:41:12');
    assert.equal(flagOf('cz'), '🇨🇿');
    assert.equal(flagOf('<x'), '');
    assert.equal(interpolate('#%place% of %count%', { place: 3, count: '5,623' }), '#3 of 5,623');
    assert.equal(interpolate('%unknown% stays', {}), '%unknown% stays');
});

scenario('format: a date shows only the precision it was logged with (R5)', () => {
    const ms = Date.UTC(2026, 8, 12);
    assert.equal(formatDate(ms, 'day', 'en'), 'Sep 12, 2026');
    assert.equal(formatDate(ms, 'month', 'en'), 'Sep 2026');
    assert.equal(formatDate(ms, 'year', 'en'), '2026');
    assert.ok(!formatDate(ms, 'month', 'en').includes('12'));
    assert.equal(formatDate(null), '—');
});

// ---- model

scenario('model: slots - a hidden slot is a label and a moment only; the active slot falls back to the first visible', () => {
    const slots = slotsOf(STATE);
    assert.deepEqual(slots.map((slot) => [slot.label, slot.visible]), [['A', true], ['B', true], ['C', false]]);
    assert.equal(slots[2].key, null);
    assert.equal(slots[2].revealsAtMs, 1800001800000);
    assert.equal(activeSlot(STATE, 'B').label, 'B');
    assert.equal(activeSlot(STATE, 'Z').label, 'A');
    const allHidden = clone(STATE);
    allHidden.slots.forEach((slot) => { slot.puzzle = null; });
    assert.equal(activeSlot(allHidden, null).label, 'A');
    assert.equal(activeSlot({ slots: [] }, null), null);
    assert.equal(stepSlot(STATE, 'A', 1), 'B');
    assert.equal(stepSlot(STATE, 'C', 1), 'A');
    assert.equal(stepSlot(STATE, 'A', -1), 'C');
});

scenario('model: rows merge state + analytics for the active slot', () => {
    const { rows } = rowsFor();
    const byKey = Object.fromEntries(rows.map((row) => [row.key, row]));
    assert.equal(rows.length, 6);
    assert.equal(byKey[E(1)].solves, 3);
    assert.equal(byKey[E(1)].bestSeconds, 2467);
    assert.equal(byKey[E(1)].rating, 1834);
    assert.equal(byKey[E(2)].rankingOptedOut, true);
    assert.equal(byKey[E(2)].rating, null);
    assert.equal(byKey[E(3)].hasHistory, false);
    assert.equal(byKey[E(3)].solves, null);
    assert.equal(byKey[E(5)].solves, 0);
    assert.equal(byKey[E(5)].result.piecesPlaced, 412);

    // Slot B: nobody of them solved it
    const b = rowsFor(STATE, ANALYTICS, 'B').rows;
    assert.ok(b.every((row) => !row.solved));

    // Hidden slot: no puzzle numbers at all
    const c = rowsFor(STATE, ANALYTICS, 'C').rows;
    assert.ok(c.every((row) => row.solves === null && row.bestSeconds === null && row.membersSolved === null));

    // Analytics not loaded yet: nothing claimed
    const none = entrantRows(STATE, null, A);
    assert.ok(none.every((row) => row.solves === null && !row.analyticsLoaded));
});

scenario('search: diacritics folded both ways, several words, country codes', () => {
    assert.deepEqual(shownKeys({ query: 'sarka' }), [E(1)]);
    assert.deepEqual(shownKeys({ query: 'ŠÁRKA nov' }), [E(1)]);
    assert.deepEqual(shownKeys({ query: 'angstrom' }), [E(6)]);
    assert.deepEqual(shownKeys({ query: 'zoe' }), [E(6)]);
    assert.deepEqual(shownKeys({ query: 'martinez' }), [E(4)]);
    assert.deepEqual(shownKeys({ query: 'gb' }), [E(2)]);
    // A country code is a word like any other: "cz" also finds Kowalczyk
    assert.deepEqual(shownKeys({ query: 'cz' }).sort(), [E(1), E(5)].sort());
    assert.deepEqual(shownKeys({ query: 'anna pl' }), [E(5)]);
    assert.deepEqual(shownKeys({ query: 'anna gb' }), []);
    assert.equal(shownKeys({ query: '' }).length, 6);
    assert.equal(shownKeys({ query: '   ' }).length, 6);
});

scenario('search: table numbers match that table exactly (12 is not 112)', () => {
    assert.deepEqual(shownKeys({ query: '12' }), [E(1)]);
    assert.deepEqual(shownKeys({ query: '#112' }), [E(5)]);
    assert.deepEqual(shownKeys({ query: '3' }), [E(2)]);
    assert.deepEqual(shownKeys({ query: '99' }), []);
});

scenario('search: team names and members of pairs/teams', () => {
    const { state, analytics } = pairState();
    assert.deepEqual(shownKeys({ query: 'zraloci' }, state, analytics), [E(7)]);
    assert.deepEqual(shownKeys({ query: 'eva' }, state, analytics), [E(7)]);
    assert.deepEqual(shownKeys({ query: 'roth' }, state, analytics), [E(8)]);
    assert.deepEqual(shownKeys({ query: 'de' }, state, analytics), [E(8)]);
    const { rows } = rowsFor(state, analytics);
    const pair = rows.find((row) => row.key === E(8));
    assert.equal(pair.name, 'Lea Roth / Max Roth');
    assert.equal(pair.solves, 2, 'line-up solves');
    assert.equal(pair.bestSeconds, 1500);
    assert.equal(pair.membersSolved, 1, 'Max solved it once on his own');
    assert.equal(pair.rating, 1650, 'the better member rating sorts');
    const sharks = rows.find((row) => row.key === E(7));
    assert.equal(sharks.name, 'Žraloci');
    assert.equal(sharks.subtitle, 'Petr Dvořák / Eva Malá');
    assert.equal(sharks.solves, null, 'no line-up history: a member is not public');
    assert.equal(sharks.solved, true, 'a member solved it');
});

scenario('filters: all / solved / never / no MSP history; the puzzle ones only for a visible slot', () => {
    assert.deepEqual(shownKeys({ filter: 'solved' }).sort(), [E(1), E(2), E(6)].sort());
    assert.deepEqual(shownKeys({ filter: 'never' }), [E(5)]);
    assert.deepEqual(shownKeys({ filter: 'nohistory' }).sort(), [E(3), E(4)].sort());
    assert.equal(shownKeys({ filter: 'all' }).length, 6);
    const hidden = activeSlot(STATE, 'C');
    assert.deepEqual(availableFilters(hidden), ['all', 'nohistory']);
    assert.equal(shownKeys({ filter: 'solved' }, STATE, ANALYTICS, 'C').length, 6, 'falls back to all');
    // Analytics not there yet: "never" claims nobody
    assert.deepEqual(shownKeys({ filter: 'never' }, STATE, null), []);
});

scenario('sorts: name, table, solves, best, rating; nulls last; puzzle sorts only for a visible slot', () => {
    assert.deepEqual(shownKeys({ sort: 'name' }), [E(5), E(2), E(4), E(3), E(1), E(6)]);
    assert.deepEqual(shownKeys({ sort: 'table' }), [E(2), E(3), E(4), E(1), E(5), E(6)]);
    assert.deepEqual(shownKeys({ sort: 'best' }).slice(0, 2), [E(1), E(6)]);
    assert.deepEqual(shownKeys({ sort: 'solves' }).slice(0, 1), [E(1)]);
    assert.deepEqual(shownKeys({ sort: 'rating' }).slice(0, 3), [E(1), E(6), E(5)]);
    assert.deepEqual(availableSorts(STATE, activeSlot(STATE, 'C')), ['name', 'table', 'rating']);
    assert.deepEqual(shownKeys({ sort: 'best' }, STATE, ANALYTICS, 'C'), shownKeys({ sort: 'name' }), 'hidden slot: by name');
    assert.equal(DEFAULT_SORT, 'name');
});

scenario('R8: unpublished confirmed results are never a sort and never ordered; published sort by official rank', () => {
    assert.ok(!availableSorts(STATE, activeSlot(STATE, 'A')).includes('rank'));
    assert.deepEqual(shownKeys({ sort: 'rank' }), shownKeys({ sort: 'name' }));
    // A live result never carries a rank or a qualified mark, even if one came along
    const leaky = clone(STATE);
    leaky.results[0].rank = 1;
    leaky.results[0].qualified = true;
    assert.equal(resultOf(leaky, E(1)).rank, null);
    assert.equal(resultOf(leaky, E(1)).qualified, false);
    assert.deepEqual(shownKeys({ sort: 'rank' }, leaky), shownKeys({ sort: 'name' }, leaky));
    assert.deepEqual(officialRanking(leaky, rowsFor(leaky).rows), []);
    // Published: official ranking
    const published = publishedState();
    assert.ok(availableSorts(published, activeSlot(published, 'A')).includes('rank'));
    assert.deepEqual(shownKeys({ sort: 'rank' }, published).slice(0, 3), [E(1), E(6), E(5)]);
    assert.deepEqual(officialRanking(published, rowsFor(published).rows).map((row) => row.key), [E(1), E(6), E(5)]);
    // Unknown result states are ignored
    const odd = clone(STATE);
    odd.results = [{ key: E(1), state: 'did_not_start', seconds: null }];
    assert.equal(resultOf(odd, E(1)), null);
});

scenario('compare: at most 4, a fifth is refused, toggling removes', () => {
    let compare = [];

    for (const n of [1, 2, 3, 5]) {
        ({ compare } = toggleCompare(compare, E(n)));
    }

    assert.equal(compare.length, MAX_COMPARE);
    const refused = toggleCompare(compare, E(6));
    assert.equal(refused.refused, true);
    assert.deepEqual(refused.compare, compare);
    const removed = toggleCompare(compare, E(2));
    assert.deepEqual(removed.compare, [E(1), E(3), E(5)]);
    assert.equal(toggleCompare(removed.compare, E(6)).compare.length, 4);
    assert.deepEqual(toggleCompare([], null).compare, []);
});

scenario('compare table: aligned rows, best value marked, nothing marked with fewer than two known', () => {
    const { rows } = rowsFor();
    const pick = [E(1), E(6), E(5), E(2)].map((key) => rows.find((row) => row.key === key));
    const table = Object.fromEntries(compareTable(pick, A, { predictions: PREDICTIONS }).map((line) => [line.id, line]));
    assert.deepEqual(table.solves.values, [3, 1, 0, 1]);
    assert.deepEqual(table.solves.best, [0]);
    assert.deepEqual(table.best.values, [2467, 3302, null, null]);
    assert.deepEqual(table.best.best, [0]);
    assert.deepEqual(table.rating.best, [0]);
    assert.deepEqual(table.prediction.values, [2580, null, 3810, null]);
    assert.deepEqual(table.prediction.best, [0]);
    assert.deepEqual(table.skill.values, ['expert', 'advanced', 'advanced', null]);
    assert.deepEqual(table.skill.best, [0]);
    assert.deepEqual(table.first_try.best, [0]);
    // Live results are never compared (R8)
    assert.deepEqual(table.result.best, []);
    // Hidden slot: no puzzle values
    const hidden = Object.fromEntries(compareTable(pick, null, {}).map((line) => [line.id, line]));
    assert.ok(hidden.best.values.every((value) => value === null));
    assert.ok(hidden.solves.values.every((value) => value === null));
    // Published: the official rank has a best
    const published = publishedState();
    const prow = rowsFor(published).rows;
    const ptable = Object.fromEntries(compareTable([E(6), E(1)].map((key) => prow.find((row) => row.key === key)), A, {}).map((line) => [line.id, line]));
    assert.deepEqual(ptable.result.best, [1]);
});

scenario('predictions: from the entrant answer, else the predictions answer; opted out, none, pending', () => {
    assert.equal(predictionOf({ entrant: ENTRANT }, E(1), 0, A).value.seconds, 2580);
    assert.equal(predictionOf({ predictions: PREDICTIONS }, E(5), 0, A).value.seconds, 3810);
    assert.equal(predictionOf({ predictions: PREDICTIONS }, E(5), 0, B).status, 'none');
    assert.equal(predictionOf({ predictions: PREDICTIONS }, E(2), 0, A).status, 'optedOut');
    assert.equal(predictionOf({ predictions: PREDICTIONS }, E(3), 0, A).status, 'none');
    assert.equal(predictionOf({ predictions: PREDICTIONS }, E(6), 0, A).status, 'pending');
    assert.equal(predictionOf({}, E(1), 0, A).status, 'pending');
    assert.equal(predictionOf({ entrant: ENTRANT }, E(1), 0, null).status, 'none');
});

// ---- URL

scenario('url: round trip of puzzle, e, sort, q; defaults left out; no cmp', () => {
    const view = { puzzle: 'B', e: E(1), sort: 'best', q: 'Šárka & co' };
    const encoded = encodeView(view);
    assert.ok(encoded.startsWith('?'));
    assert.ok(!encoded.includes('cmp'));
    assert.deepEqual(decodeView(encoded), view);
    assert.equal(encodeView({ puzzle: null, e: null, sort: 'name', q: '' }), '');
    assert.equal(encodeView({ sort: 'name', q: '  ' }), '');
    assert.deepEqual(decodeView(''), { puzzle: null, e: null, sort: null, q: '' });
    assert.deepEqual(decodeView(encodeView(decodeView('?puzzle=A&q=12'))), { puzzle: 'A', e: null, sort: null, q: '12' });
});

scenario('url: unknown or malformed values are ignored', () => {
    const decoded = decodeView('?puzzle=<script>&e=not-a-uuid&sort=prediction&cmp=x,y&density=compact');
    assert.deepEqual(decoded, { puzzle: null, e: null, sort: null, q: '' });
    assert.equal(decodeView(`?q=${'x'.repeat(500)}`).q.length, 100);
    assert.equal(decodeView(`?e=${E(1).toUpperCase()}`).e, E(1));
    const resolved = resolveView(decodeView(`?puzzle=Z&e=${E(9)}`), STATE);
    assert.equal(resolved.puzzle, null);
    assert.equal(resolved.e, null);
    const known = resolveView(decodeView(`?puzzle=C&e=${E(3)}&sort=rating`), STATE);
    assert.deepEqual(known, { puzzle: 'C', e: E(3), sort: 'rating', q: '' });
    assert.equal(initialView(known).open, true);
    assert.equal(initialView(decodeView('')).open, false);
});

// ---- keys

scenario('keys: every key of the keymap maps to its action', () => {
    const map = (key, context = {}) => keyAction({ key }, context);
    assert.deepEqual(map('/'), { type: 'focusSearch' });
    assert.deepEqual(map('Escape'), { type: 'escape' });
    assert.deepEqual(map('ArrowDown'), { type: 'move', delta: 1 });
    assert.deepEqual(map('j'), { type: 'move', delta: 1 });
    assert.deepEqual(map('ArrowUp'), { type: 'move', delta: -1 });
    assert.deepEqual(map('k'), { type: 'move', delta: -1 });
    assert.deepEqual(map('Enter'), { type: 'open' });
    assert.deepEqual(map('c'), { type: 'toggleCompare' });
    assert.deepEqual(map('x'), { type: 'clearCompare' });
    assert.deepEqual(map('['), { type: 'stepSlot', step: -1 });
    assert.deepEqual(map(']'), { type: 'stepSlot', step: 1 });
    assert.deepEqual(map('?'), { type: 'toggleHelp' });
    // Cut in R10
    for (const key of ['s', 'S', 'p', 'l', 'd', '1', '9']) {
        assert.equal(map(key), null, key);
    }
    assert.equal(keyAction({ key: 'c', ctrlKey: true }), null);
    assert.equal(keyAction({ key: '/', metaKey: true }), null);
    assert.equal(keyAction({ key: 'j', altKey: true }), null);
    assert.equal(keyAction(null), null);
    assert.deepEqual(KEYMAP.map((entry) => entry.action), ['focus_search', 'escape', 'move', 'open', 'compare', 'clear_compare', 'slot', 'help']);
});

scenario('keys: while typing only Esc, ↑, ↓ and Enter act; Esc clears, then blurs', () => {
    const typing = (key, query = 'ann') => keyAction({ key }, { typing: true, query });
    for (const key of ['/', 'j', 'k', 'c', 'x', '[', ']', '?', 'a', ' ']) {
        assert.equal(typing(key), null, key);
    }
    assert.deepEqual(typing('Escape'), { type: 'clearSearch' });
    assert.deepEqual(typing('Escape', ''), { type: 'blurSearch' });
    assert.deepEqual(typing('ArrowDown'), { type: 'move', delta: 1 });
    assert.deepEqual(typing('ArrowUp'), { type: 'move', delta: -1 });
    assert.deepEqual(typing('Enter'), { type: 'open' });
});

scenario('keys: with the help open only Esc and ? (close it)', () => {
    assert.deepEqual(keyAction({ key: 'Escape' }, { help: true }), { type: 'closeHelp' });
    assert.deepEqual(keyAction({ key: '?' }, { help: true }), { type: 'closeHelp' });
    assert.equal(keyAction({ key: 'j' }, { help: true }), null);
});

scenario('view: the actions of the keymap applied to the view', () => {
    const keys = [E(1), E(2), E(3)];
    const context = { keys, state: STATE };
    let view = initialView({});
    const apply = (action) => {
        const result = applyAction(view, action, context);
        view = result.view;

        return result.effect;
    };

    assert.equal(apply(keyAction({ key: '/' })), 'focusSearch');
    apply({ type: 'search', query: 'an' });
    assert.equal(view.query, 'an');
    apply(keyAction({ key: 'Escape' }, { typing: true, query: view.query }));
    assert.equal(view.query, '');
    assert.equal(apply(keyAction({ key: 'Escape' }, { typing: true, query: view.query })), 'blurSearch');

    apply(keyAction({ key: 'j' }));
    assert.equal(view.selected, E(1));
    apply(keyAction({ key: 'ArrowDown' }));
    apply(keyAction({ key: 'ArrowDown' }));
    apply(keyAction({ key: 'ArrowDown' }));
    assert.equal(view.selected, E(3), 'stays at the end');
    apply(keyAction({ key: 'k' }));
    assert.equal(view.selected, E(2));
    assert.equal(view.open, false);
    apply(keyAction({ key: 'Enter' }));
    assert.equal(view.open, true);
    apply(keyAction({ key: 'Escape' }));
    assert.equal(view.open, false);

    apply(keyAction({ key: 'c' }));
    assert.deepEqual(view.compare, [E(2)]);
    apply(keyAction({ key: 'c' }));
    assert.deepEqual(view.compare, []);
    view = { ...view, compare: [E(1), E(3), E(4), E(5)] };
    apply(keyAction({ key: 'c' }));
    assert.equal(view.compare.length, 4);
    assert.equal(view.notice, 'compare_full');
    apply(keyAction({ key: 'x' }));
    assert.deepEqual(view.compare, []);
    assert.equal(view.notice, null);

    apply(keyAction({ key: ']' }));
    assert.equal(view.puzzle, 'B');
    apply(keyAction({ key: ']' }));
    assert.equal(view.puzzle, 'C');
    apply(keyAction({ key: '[' }));
    apply(keyAction({ key: '[' }));
    apply(keyAction({ key: '[' }));
    assert.equal(view.puzzle, 'C', 'wraps');

    apply(keyAction({ key: '?' }));
    assert.equal(view.help, true);
    apply(keyAction({ key: 'Escape' }, { help: view.help }));
    assert.equal(view.help, false);
    apply(keyAction({ key: '?' }));
    apply(keyAction({ key: 'Escape' }));
    assert.equal(view.help, false, 'escape closes help first');

    // Nothing in the list: the selection stays
    view = applyAction(view, { type: 'move', delta: 1 }, { keys: [] }).view;
    assert.equal(view.selected, E(2));
    apply({ type: 'sort', sort: 'nonsense' });
    assert.equal(view.sort, 'name');
    apply({ type: 'filter', filter: 'never' });
    assert.equal(view.filter, 'never');
    apply({ type: 'tab', tab: 'live' });
    assert.equal(view.tab, 'live');
    apply({ type: 'select', key: E(3) });
    assert.equal(view.selected, E(3));
    assert.equal(view.open, true);
});

// ---- refresh

async function settle() {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

function stopwatch(status, { startedAtMs = NOW - 600_000, stoppedAtMs = null, asOfMs = NOW } = {}) {
    return { roundId: ROUND, status, startedAt: null, stoppedAt: null, minutesLimit: 90, startedAtMs, stoppedAtMs, asOfMs, stateHash: 'h' };
}

function setup({ state = clone(STATE), skew = 0, random = () => 0.5 } = {}) {
    const device = { now: NOW - skew };
    const timers = [];
    const server = { state, status: 'ok', analyticsStatus: 'ok', fetches: [], analytics: [], entrants: [], predictions: [], rttMs: 0, delayEntrant: 0 };
    const seen = { states: [], analytics: [], entrants: [], predictions: [], gone: 0 };
    const events = { options: null, updates: [], started: false, suspended: false, closed: false };
    const pending = [];

    const refresh = new AnalyticsStudioRefresh({
        serverNowMs: NOW,
        fetchState: async () => {
            const sentAtMs = device.now;
            server.fetches.push(device.now);
            await Promise.resolve();

            if (server.status !== 'ok') {
                return { status: server.status };
            }

            return { status: 'ok', state: { ...clone(server.state), serverNowMs: device.now + skew }, sentAtMs, receivedAtMs: sentAtMs + server.rttMs };
        },
        fetchAnalytics: async (hash) => {
            server.analytics.push({ hash, at: device.now });

            return server.analyticsStatus === 'ok' ? { status: 'ok', analytics: { ...clone(ANALYTICS), visibleHash: hash } } : { status: 'error' };
        },
        fetchEntrant: (key, hash) => {
            server.entrants.push({ key, hash, at: device.now });

            if (server.delayEntrant > 0) {
                return new Promise((resolve) => pending.push(() => resolve({ status: 'ok', entrant: { ...clone(ENTRANT), key, visibleHash: hash } })));
            }

            return Promise.resolve({ status: 'ok', entrant: { ...clone(ENTRANT), key, visibleHash: hash } });
        },
        fetchPredictions: async (keys, hash) => {
            server.predictions.push({ keys, hash, at: device.now });

            return { status: 'ok', predictions: { ...clone(PREDICTIONS), visibleHash: hash } };
        },
        onState: (current) => seen.states.push(current),
        onAnalytics: (analytics) => seen.analytics.push(analytics),
        onEntrant: (entrant) => seen.entrants.push(entrant),
        onPredictions: (predictions) => seen.predictions.push(predictions),
        onGone: () => { seen.gone += 1; },
        now: () => device.now,
        schedule: (task, ms) => {
            const timer = { task, at: device.now + ms, ms };
            timers.push(timer);

            return timer;
        },
        cancel: (timer) => {
            const index = timers.indexOf(timer);

            if (index >= 0) {
                timers.splice(index, 1);
            }
        },
        random,
        createEvents: (options) => {
            events.options = options;

            return {
                start() { events.started = true; },
                update(subscription) { events.updates.push(subscription); },
                suspend() { events.suspended = true; },
                close() { events.closed = true; },
            };
        },
    });

    async function advance(ms) {
        const until = device.now + ms;

        for (;;) {
            timers.sort((a, b) => a.at - b.at);
            const next = timers[0];

            if (next === undefined || next.at > until) {
                break;
            }

            timers.shift();
            device.now = Math.max(device.now, next.at);
            next.task();
            await settle();
        }

        device.now = until;
        await settle();
    }

    const sinceStart = () => server.fetches.map((at) => at - NOW);

    return { refresh, device, timers, server, seen, events, advance, sinceStart, pending };
}

scenario('refresh: pure plan - live / idle polls ±20 %, backoff doubling to 5 min, reveal and push timing', () => {
    assert.equal(pollDelay({ live: true, random: () => 0.5 }), LIVE_POLL_MS);
    assert.equal(LIVE_POLL_MS, 10_000);
    assert.equal(IDLE_POLL_MS, 60_000);
    assert.equal(pollDelay({ live: true, random: () => 0 }), 8_000);
    assert.equal(pollDelay({ live: true, random: () => 1 }), 12_000);
    assert.equal(pollDelay({ live: false, random: () => 0.5 }), 60_000);
    assert.equal(pollDelay({ live: true, errors: 1, random: () => 0.5 }), 20_000);
    assert.equal(pollDelay({ live: true, errors: 2, random: () => 0.5 }), 40_000);
    assert.equal(pollDelay({ live: false, errors: 3, random: () => 0.5 }), MAX_POLL_MS);
    assert.equal(pollDelay({ live: true, errors: 50, random: () => 0.5 }), MAX_POLL_MS);
    assert.equal(MAX_POLL_MS, 300_000);
    assert.equal(revealDelay({ nextRevealAtMs: NOW + 5000 }, NOW, () => 0), 5000 + REVEAL_GRACE_MS);
    assert.equal(revealDelay({ nextRevealAtMs: NOW + 5000 }, NOW, () => 1), 6300);
    assert.equal(revealDelay({ nextRevealAtMs: NOW - 5000 }, NOW, () => 0), REVEAL_GRACE_MS, 'passed: at once');
    assert.equal(revealDelay({ nextRevealAtMs: null }, NOW), null);
    assert.equal(PUSH_JITTER_MS, 1000);
});

scenario('refresh: live means counting down, running, time up, or stopped while not published', () => {
    const at = (sw, published = false) => isLiveRound({ round: { stopwatch: sw, published } }, NOW);
    assert.equal(at(stopwatch(null, { startedAtMs: null })), false);
    assert.equal(at(stopwatch('scheduled', { startedAtMs: NOW + 60_000 })), true);
    assert.equal(at(stopwatch('running')), true);
    assert.equal(at(stopwatch('running', { startedAtMs: NOW - 99 * 60_000 })), true, 'time up');
    assert.equal(at(stopwatch('stopped', { stoppedAtMs: NOW - 1000 })), true);
    assert.equal(at(stopwatch('stopped', { stoppedAtMs: NOW - 1000 }), true), false);
    assert.equal(isLiveRound(null, NOW), false);
});

scenario('refresh: start fetches the state, then analytics?v=hash; the stream follows the public stopwatch topic', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const { refresh, server, seen, events } = setup({ state });
    refresh.start();
    await settle();
    assert.equal(server.fetches.length, 1);
    assert.equal(seen.states.length, 1);
    assert.deepEqual(server.analytics.map((call) => call.hash), [STATE.visibleHash]);
    assert.equal(seen.analytics.length, 1);
    assert.equal(events.started, true);
    assert.deepEqual(events.options.subscription, { url: STATE.mercure.hubUrl, topics: STATE.mercure.topics, public: true });
    assert.equal(server.entrants.length, 0, 'nothing selected');
    assert.equal(server.predictions.length, 0, 'nothing compared');
});

scenario('refresh: polls every 10 s ±20 % while live, every 60 s otherwise', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const live = setup({ state });
    live.refresh.start();
    await settle();
    await live.advance(35_000);
    assert.deepEqual(live.sinceStart(), [0, 10_000, 20_000, 30_000]);
    assert.equal(live.server.analytics.length, 1, 'same hash: analytics not asked again');

    const idle = clone(state);
    idle.round.stopwatch = stopwatch(null, { startedAtMs: null });
    const quiet = setup({ state: idle });
    quiet.refresh.start();
    await settle();
    await quiet.advance(130_000);
    assert.deepEqual(quiet.sinceStart(), [0, 60_000, 120_000]);

    const published = publishedState();
    published.nextRevealAtMs = null;
    const done = setup({ state: published });
    done.refresh.start();
    await settle();
    await done.advance(61_000);
    assert.deepEqual(done.sinceStart(), [0, 60_000]);

    const spread = setup({ state, random: () => 0 });
    spread.refresh.start();
    await settle();
    await spread.advance(17_000);
    assert.deepEqual(spread.sinceStart(), [0, 8_000, 16_000]);
});

scenario('refresh: failures back off doubling to 5 minutes, a success resets', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const { refresh, server, advance, sinceStart } = setup({ state });
    server.status = 'error';
    refresh.start();
    await settle();
    await advance(10 * 60_000);
    // 0, +20 s, +40 s, +80 s, +160 s, +300 s
    assert.deepEqual(sinceStart().slice(0, 6), [0, 20_000, 60_000, 140_000, 300_000, 600_000]);
    server.status = 'ok';
    await advance(300_000);
    const last = sinceStart().at(-1);
    await advance(10_000);
    assert.equal(sinceStart().at(-1), last + 10_000, 'back to 10 s');
});

scenario('refresh: a push applies the newer stopwatch at once and asks for the state within 0-1 s; an older one only asks', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const { refresh, server, seen, events, advance, sinceStart } = setup({ state, random: () => 0.25 });
    refresh.start();
    await settle();
    const before = seen.states.length;
    events.options.onMessage(stopwatch('stopped', { stoppedAtMs: NOW + 100, asOfMs: NOW + 100 }));
    assert.equal(seen.states.length, before + 1);
    assert.equal(seen.states.at(-1).round.stopwatch.status, 'stopped');
    await advance(249);
    assert.equal(server.fetches.length, 1);
    await advance(1);
    assert.deepEqual(sinceStart(), [0, 250]);
    // The fetch answered with the older stopwatch: the push is kept
    assert.equal(seen.states.at(-1).round.stopwatch.status, 'stopped');

    // An older push changes nothing but still asks
    const count = seen.states.length;
    events.options.onMessage(stopwatch('running', { asOfMs: NOW - 5000 }));
    assert.equal(seen.states.length, count);
    // Another round's push is not applied; a message without status is not a stopwatch
    events.options.onMessage({ ...stopwatch('stopped', { asOfMs: NOW + 9999 }), roundId: 'other' });
    events.options.onMessage({ type: 'results' });
    assert.equal(seen.states.length, count);

    // A burst of pushes: one fetch
    const fetches = server.fetches.length;
    for (let i = 0; i < 20; i++) {
        events.options.onMessage(stopwatch('stopped', { asOfMs: NOW + 200 + i }));
    }
    await advance(1000);
    assert.equal(server.fetches.length, fetches + 1);
});

scenario('refresh: push jitter spreads within 0-1 s', async () => {
    for (const [random, expected] of [[0, 0], [0.999, 999]]) {
        const state = clone(STATE);
        state.nextRevealAtMs = null;
        const { refresh, events, advance, sinceStart } = setup({ state, random: () => random });
        refresh.start();
        await settle();
        events.options.onMessage(stopwatch('running', { asOfMs: NOW + 1 }));
        await advance(1000);
        assert.equal(sinceStart()[1], expected);
    }
});

scenario('refresh: the state again at nextRevealAtMs + 300 ms + 0-1 s; a new visibleHash refetches analytics, the open entrant and the predictions', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = NOW + 5000;
    state.round.stopwatch = stopwatch(null, { startedAtMs: null });
    const { refresh, server, seen, advance, sinceStart } = setup({ state, random: () => 0.5 });
    refresh.start();
    await settle();
    refresh.select(E(1));
    refresh.setCompare([E(1), E(5)]);
    await settle();
    assert.equal(server.entrants.length, 1);
    assert.equal(server.predictions.length, 1);
    assert.deepEqual(server.predictions[0].keys, [E(1), E(5)]);

    // The reveal: the server's next answer carries another hash
    server.state = { ...clone(state), visibleHash: 'feedfacecafebeef', nextRevealAtMs: null };
    await advance(5799);
    assert.equal(server.fetches.length, 1);
    await advance(1);
    assert.deepEqual(sinceStart(), [0, 5800]);
    assert.deepEqual(server.analytics.map((call) => call.hash), [STATE.visibleHash, 'feedfacecafebeef']);
    assert.deepEqual(server.entrants.map((call) => call.hash), [STATE.visibleHash, 'feedfacecafebeef']);
    assert.deepEqual(server.predictions.map((call) => call.hash), [STATE.visibleHash, 'feedfacecafebeef']);
    assert.equal(seen.analytics.at(-1).visibleHash, 'feedfacecafebeef');
    assert.equal(seen.entrants.at(-1).visibleHash, 'feedfacecafebeef');
    assert.equal(seen.predictions.at(-1).visibleHash, 'feedfacecafebeef');

    // Same hash again: nothing refetched
    await advance(60_000);
    assert.equal(server.analytics.length, 2);
    assert.equal(server.entrants.length, 2);
    assert.equal(server.predictions.length, 2);
});

scenario('refresh: R3 - predictions only for the compare set; selection changes fetch only what is missing', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const { refresh, server, advance } = setup({ state });
    refresh.start();
    await settle();
    refresh.setCompare([]);
    await settle();
    assert.equal(server.predictions.length, 0);
    refresh.setCompare([E(1), E(2), E(3), E(5), E(6)]);
    await settle();
    assert.deepEqual(server.predictions[0].keys, [E(1), E(2), E(3), E(5)], 'at most 4');
    refresh.setCompare([E(5), E(3), E(2), E(1)]);
    await settle();
    assert.equal(server.predictions.length, 1, 'the same set in another order');
    refresh.select(E(1));
    refresh.select(E(1));
    await settle();
    assert.equal(server.entrants.length, 1);
    refresh.select(null);
    await advance(30_000);
    assert.equal(server.entrants.length, 1);
});

scenario('refresh: an answer for an entrant no longer selected is dropped', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const { refresh, server, seen, pending } = setup({ state });
    refresh.start();
    await settle();
    server.delayEntrant = 1;
    refresh.select(E(1));
    await settle();
    refresh.select(E(2));
    await settle();
    assert.deepEqual(server.entrants.map((call) => call.key), [E(1), E(2)]);
    pending.shift()();
    await settle();
    assert.equal(seen.entrants.length, 0, 'E1 answered late: dropped');
    pending.shift()();
    await settle();
    assert.deepEqual(seen.entrants.map((entrant) => entrant.key), [E(2)]);
});

scenario('refresh: a failed analytics fetch is asked again with the next state', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const { refresh, server, seen, advance } = setup({ state });
    server.analyticsStatus = 'error';
    refresh.start();
    await settle();
    assert.equal(seen.analytics.length, 0);
    server.analyticsStatus = 'ok';
    await advance(10_000);
    assert.equal(server.analytics.length, 2);
    assert.equal(seen.analytics.length, 1);
});

scenario('refresh: the clock follows the server (hint, then the shortest round trip)', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const { refresh, server, device } = setup({ state, skew: 7000 });
    assert.equal(refresh.serverNow(), NOW, 'hint from the shell');
    server.rttMs = 400;
    refresh.start();
    await settle();
    assert.equal(refresh.serverNow() - device.now, 6800);
});

scenario('refresh: gone (404) - stream suspended, a look every 5 minutes; close() leaves nothing behind', async () => {
    const state = clone(STATE);
    state.nextRevealAtMs = null;
    const { refresh, server, seen, events, advance, timers, sinceStart } = setup({ state });
    refresh.start();
    await settle();
    server.status = 'gone';
    await advance(10_000);
    assert.equal(events.suspended, true);
    assert.equal(seen.gone, 1);
    await advance(GONE_POLL_MS);
    assert.deepEqual(sinceStart(), [0, 10_000, 10_000 + GONE_POLL_MS]);
    refresh.close();
    assert.equal(timers.length, 0);
    assert.equal(events.closed, true);
});

// ---- render

function dom() {
    const window = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>').window;

    return { window, document: window.document, root: window.document.getElementById('root') };
}

function renderModel(overrides = {}, labels = ENGLISH) {
    const { window, document, root } = dom();
    const renderer = createAnalyticsStudioRenderer({ document, root, labels, locale: 'en' });
    const model = {
        state: STATE,
        analytics: ANALYTICS,
        entrant: null,
        predictions: null,
        nowMs: NOW,
        ...overrides,
        view: { ...initialView({}), ...(overrides.view ?? {}) },
    };
    const out = renderer.render(model);

    return { window, document, root, renderer, out, model };
}

const text = (node) => (node === null ? '' : node.textContent);

scenario('clock: an idle stopwatch says "Not started" only before the round\'s start, after it the start itself', () => {
    // Pure
    assert.equal(clockPhase('idle', NOW + 60_000, NOW), 'idle');
    assert.equal(clockPhase('idle', NOW, NOW), 'past');
    assert.equal(clockPhase('idle', NOW - 86_400_000, NOW), 'past');
    assert.equal(clockPhase('idle', null, NOW), 'idle');
    assert.equal(clockPhase('running', NOW - 60_000, NOW), 'running');
    assert.equal(clockPhase('stopped', NOW - 60_000, NOW), 'stopped');
    assert.equal(formatStartsAt(Date.UTC(2026, 10, 14, 9, 30), 'en', 'UTC'), 'Nov 14, 2026, 9:30 AM');
    assert.equal(formatStartsAt(Date.UTC(2026, 10, 14, 9, 30), 'cs', 'UTC'), '14. 11. 2026 9:30');
    assert.equal(formatStartsAt(null), '');

    // Rendered: a past round whose stopwatch was never used
    const past = clone(STATE);
    past.round.stopwatch = stopwatch(null, { startedAtMs: null });
    past.round.startsAtMs = NOW - 86_400_000;
    const { root, renderer } = renderModel({ state: past });
    assert.equal(text(root.querySelector('.as-clock__status')), formatStartsAt(NOW - 86_400_000, 'en'));
    assert.notEqual(text(root.querySelector('.as-clock__status')), 'Not started');
    assert.ok(root.querySelector('.as-clock').classList.contains('as-clock--past'));
    assert.equal(text(root.querySelector('.as-clock__time')), '');

    // Before the start: "Not started"
    const ahead = clone(past);
    ahead.round.startsAtMs = NOW + 3_600_000;
    renderer.render({ state: ahead, analytics: ANALYTICS, entrant: null, predictions: null, nowMs: NOW, view: initialView({}) });
    assert.equal(text(root.querySelector('.as-clock__status')), 'Not started');
    assert.ok(root.querySelector('.as-clock').classList.contains('as-clock--idle'));
});

scenario('render: a solo row stays one line (name + flag), a pair/team row keeps its members line', () => {
    const { root } = renderModel();
    const solo = root.querySelector(`.as-row[data-as-entry="${E(1)}"] .as-row__name`);
    assert.ok(!solo.classList.contains('as-row__name--team'));
    assert.ok(solo.querySelector('.as-row__player') !== null);

    const { state, analytics } = pairState();
    const { root: pairs } = renderModel({ state, analytics });
    const team = pairs.querySelector('.as-row__name--team');
    assert.ok(team !== null);
    assert.ok(team.querySelector('.as-row__members') !== null);
});

scenario('render: the laptop layout - top bar, list, detail, puzzle, live; phone tabs', () => {
    const { root } = renderModel({ view: { selected: E(1), open: true } });
    assert.ok(root.classList.contains('as'));
    assert.equal(text(root.querySelector('.as-top__round')), 'Individual D');
    assert.equal(text(root.querySelector('.as-top__format')), 'Pick 2 of 3');
    assert.deepEqual([...root.querySelectorAll('.as-slot')].map((node) => node.getAttribute('data-as-slot')), ['A', 'B', 'C']);
    assert.ok(root.querySelector('.as-slot[data-as-slot="C"]').classList.contains('as-slot--hidden'));
    assert.equal(text(root.querySelector('.as-clock__status')), 'Running');
    assert.equal(text(root.querySelector('.as-clock__time')), '0:10:00');
    assert.equal(text(root.querySelector('.as-clock__left')), '1:20:00 left');
    assert.equal(text(root.querySelector('.as-top__live')), 'Live: 2 confirmed');
    assert.equal(root.querySelectorAll('.as-row').length, 6);
    assert.ok(root.querySelector(`.as-row[data-as-entry="${E(1)}"]`).classList.contains('as-row--selected'));
    assert.equal(text(root.querySelector(`.as-row[data-as-entry="${E(1)}"] .as-row__solves`)), '3×');
    assert.equal(text(root.querySelector(`.as-row[data-as-entry="${E(1)}"] .as-row__best`)), '41:07');
    assert.equal(text(root.querySelector(`.as-row[data-as-entry="${E(1)}"] .as-row__table`)), 'Table 12');
    assert.equal(text(root.querySelector(`.as-row[data-as-entry="${E(5)}"] .as-result`)), '412/500 pieces');
    assert.equal(root.getAttribute('data-as-tab'), 'entrants');
    assert.ok(root.classList.contains('as--sheet-open'));
    assert.deepEqual([...root.querySelectorAll('.as-tabs__tab')].map((node) => node.getAttribute('data-as-tab')), ['entrants', 'puzzle', 'live']);
    assert.deepEqual([...root.querySelectorAll('.as-sort option')].map((node) => node.value), ['name', 'table', 'solves', 'best', 'rating']);
    assert.deepEqual([...root.querySelectorAll('.as-chip')].map((node) => node.getAttribute('data-as-filter')), ['all', 'solved', 'never', 'nohistory']);
    // Puzzle panel
    const puzzle = root.querySelector('.as-puzzle');
    assert.equal(text(puzzle.querySelector('.as-puzzle__name')), 'London Postcard');
    assert.equal(text(puzzle.querySelector('.as-puzzle__meta')), 'Trefl · 500 pieces');
    assert.equal(puzzle.querySelector('img').getAttribute('src'), STATE.slots[0].puzzle.imageUrl);
    assert.ok(text(puzzle).includes('5,623'));
    assert.ok(text(puzzle).includes('Hard'));
    assert.equal(puzzle.querySelectorAll('.as-histogram__bar').length, 8);
    assert.equal(puzzle.querySelectorAll('.as-top10__item').length, 3);
    assert.equal(puzzle.querySelectorAll('.as-top10__item--in-round').length, 1);
    assert.ok(text(puzzle).includes('Private puzzler'));
});

scenario('render: entrant detail - history before the round, now values, prediction pending then filled, lists, earlier rounds', () => {
    const pendingView = renderModel({ view: { selected: E(1) } });
    const detail = pendingView.root.querySelector('.as-entrant');
    assert.ok(text(detail).includes('Šárka Nováková'));
    assert.equal(detail.querySelector('a').getAttribute('href'), STATE.entrants[0].members[0].profile);
    assert.equal(detail.querySelector('a').getAttribute('rel'), 'nofollow noopener');
    assert.ok(text(detail).includes('1,834'));
    assert.ok(text(detail).includes('#41'));
    assert.ok(text(detail).includes('Expert'));
    assert.ok(text(detail).includes('Before this round'));
    assert.ok(text(detail).includes('#3 of 5,623'));
    assert.ok(text(detail).includes('Sep'), 'last solve date');
    assert.ok(detail.querySelector('.as-fact--prediction .as-spinner'), 'prediction pending');
    assert.ok(text(detail).includes('Same brand, 500 pieces'));
    assert.ok(text(detail).includes('Confirmed by the judge, not official'));

    const loaded = renderModel({ view: { selected: E(1) }, entrant: ENTRANT });
    const full = loaded.root.querySelector('.as-entrant');
    assert.equal(full.querySelector('.as-fact--prediction .as-spinner'), null);
    assert.ok(text(full).includes('≈ 43:00'));
    assert.ok(text(full).includes('(40:00–47:00)'));
    assert.ok(text(full).includes('from their own times'));
    assert.equal(full.querySelectorAll('.as-attempt').length, 4);
    assert.ok(text(full.querySelector('.as-attempts')).includes('pair'));
    assert.ok(text(full.querySelector('.as-attempts')).includes('first try'));
    // R5: the month-only attempt has no day
    const dates = [...full.querySelectorAll('.as-attempt .as-date')].map(text);
    assert.equal(dates[2], 'Dec 2025');
    assert.equal(full.querySelectorAll('.as-recent__item').length, 2);
    assert.equal(full.querySelectorAll('.as-form__item').length, 10);
    assert.ok(text(full.querySelector('.as-earlier')).includes('Qualification 1'));
    assert.ok(text(full.querySelector('.as-earlier')).includes('#4'));
});

scenario('render: empty states of §7.5', () => {
    // No public history - private and unlinked the same words
    for (const key of [E(3), E(4)]) {
        const { root } = renderModel({ view: { selected: key } });
        assert.ok(text(root.querySelector('.as-entrant')).includes('No public MySpeedPuzzling history'), key);
        assert.equal(root.querySelector('.as-entrant a'), null, 'no profile link');
    }

    // First time on this puzzle
    assert.ok(text(renderModel({ view: { selected: E(5) } }).root.querySelector('.as-entrant')).includes('First time on this puzzle'));

    // Opted out: "—" with the tooltip, rating and skill and prediction
    const optedOut = renderModel({ view: { selected: E(2) }, predictions: PREDICTIONS }).root;
    const marks = [...optedOut.querySelectorAll('.as-entrant .as-opted-out')];
    assert.ok(marks.length >= 3);
    assert.ok(marks.every((node) => node.getAttribute('title') === "Not shown at the player's wish"));
    assert.equal(optedOut.querySelector(`.as-row[data-as-entry="${E(2)}"] .as-opted-out`).getAttribute('title'), "Not shown at the player's wish");

    // Nobody logged the puzzle
    const b = renderModel({ view: { puzzle: 'B' } }).root.querySelector('.as-puzzle');
    assert.ok(text(b).includes('Nobody has logged this puzzle yet'));
    assert.ok(text(b).includes('Not rated yet'));
    assert.ok(text(b).includes('Picture shown later'));
    assert.equal(b.querySelector('img'), null);

    // No entrants; no puzzle
    const empty = renderModel({ state: { ...clone(STATE), entrants: [], results: [], slots: [] } }).root;
    assert.equal(text(empty.querySelector('.as-list')), 'No entrants yet');
    assert.equal(text(empty.querySelector('.as-puzzle')), 'No puzzle in this round yet');
    assert.equal(text(empty.querySelector('.as-live')), 'LiveNo results yet');

    // No match; nothing selected
    const none = renderModel({ view: { query: 'nobody-here' } }).root;
    assert.equal(text(none.querySelector('.as-list')), 'Nobody matches the search');
    assert.equal(text(none.querySelector('.as-detail')), 'Pick an entrant to see their numbers');

    // Analytics still loading
    const loading = renderModel({ analytics: null, view: { selected: E(1) } }).root;
    assert.ok(loading.querySelector('.as-puzzle .as-spinner'));
    assert.ok(loading.querySelector('.as-entrant .as-spinner'));
});

scenario('render: a hidden slot shows its label and a countdown - no puzzle data anywhere', () => {
    const state = clone(STATE);
    // Whatever a broken answer might add next to a hidden slot is never read
    Object.assign(state.slots[2], { name: 'Secret Garden', brand: 'Schmidt', pieces: 1000, imageUrl: 'https://img.example/secret.jpg', key: 'secret-key' });
    const { root, renderer } = renderModel({ state, view: { puzzle: 'C', selected: E(1), compare: [E(1), E(6)] }, entrant: ENTRANT, predictions: PREDICTIONS });
    const puzzle = root.querySelector('.as-puzzle');
    assert.equal(text(puzzle.querySelector('.as-puzzle__label')), 'C');
    assert.ok(text(puzzle).includes('Revealed at the start'));
    assert.equal(text(puzzle.querySelector('.as-countdown')), 'Revealed in 0:30:00');
    assert.equal(puzzle.querySelector('img'), null);
    assert.equal(puzzle.querySelector('.as-facts'), null);
    const all = text(root);

    for (const secret of ['Secret Garden', 'Schmidt', '1000', 'secret']) {
        assert.ok(!all.includes(secret), secret);
    }

    assert.equal(root.querySelectorAll('img').length, 0);
    // No puzzle numbers in the list, compare shows only rating / skill / result
    assert.ok([...root.querySelectorAll('.as-row__solves, .as-row__best')].every((node) => text(node) === ''));
    assert.deepEqual([...root.querySelectorAll('.as-compare__row')].map((node) => node.className.replace('as-compare__row as-compare__row--', '')), ['rating', 'skill', 'result']);
    renderer.renderClock(NOW + 60_000);
    assert.equal(text(root.querySelector('.as-countdown')), 'Revealed in 0:29:00');

    // The detail without compare: no history, no prediction, no similar for the hidden slot
    const detail = renderModel({ state, view: { puzzle: 'C', selected: E(1) }, entrant: ENTRANT }).root.querySelector('.as-entrant');
    assert.equal(detail.querySelector('.as-history, .as-fact--prediction, .as-similar, .as-attempts'), null);
    assert.ok(!text(detail).includes('London Postcard'));
});

scenario('render: compare 4 - columns aligned, best values bold, columns past the second marked for phones', () => {
    const { root } = renderModel({ view: { compare: [E(1), E(6), E(5), E(2)] }, predictions: PREDICTIONS });
    const table = root.querySelector('.as-compare');
    assert.ok(table);
    assert.ok(root.classList.contains('as--comparing'));
    assert.equal(table.querySelectorAll('thead .as-compare__col').length, 4);
    assert.equal(table.querySelectorAll('thead .as-compare__col--extra').length, 2);
    const bestRow = table.querySelector('.as-compare__row--best');
    assert.deepEqual([...bestRow.querySelectorAll('td')].map(text), ['41:07', '55:02', '—', '—']);
    assert.ok(bestRow.querySelectorAll('td')[0].classList.contains('as-compare__cell--best'));
    const prediction = table.querySelector('.as-compare__row--prediction');
    assert.equal(text(prediction.querySelectorAll('td')[0]), '43:00');
    assert.ok(prediction.querySelectorAll('td')[1].querySelector('.as-spinner'), 'E6 predictions pending');
    assert.ok(prediction.querySelectorAll('td')[3].querySelector('.as-opted-out'));
    assert.equal(text(root.querySelector('.as-compare-bar__count')), 'Compare 4/4');
    // The compare view's head: its title, Clear and the sheet's way out on phones
    const compareHead = root.querySelector('.as-detail .as-compare-head');
    assert.equal(text(compareHead.querySelector('.as-compare-head__title')), 'Compare 4/4');
    assert.equal(compareHead.querySelector('.as-compare-head__clear').getAttribute('data-as-action'), 'clearCompare');
    assert.equal(compareHead.querySelector('.as-entrant__close').getAttribute('data-as-action'), 'close');

    const one = renderModel({ view: { compare: [E(1)], selected: E(1) } }).root;
    assert.equal(one.querySelector('.as-compare'), null);
    assert.ok(text(one.querySelector('.as-detail')).includes('Pick one more entrant to compare'));

    const full = renderModel({ view: { compare: [E(1), E(2), E(5), E(6)], notice: 'compare_full' } }).root;
    assert.equal(text(full.querySelector('.as-notice')), 'At most 4 entrants can be compared');
});

scenario('render: R8 - unpublished confirmed results are a count + note, never an ordered list; published ranks in order', () => {
    const live = renderModel().root.querySelector('.as-live');
    assert.equal(live.querySelector('.as-live__list'), null);
    assert.equal(text(live.querySelector('.as-live__count')), 'Live: 2 confirmed');
    assert.equal(text(live.querySelector('.as-live__note')), 'Confirmed by the judge, not official');
    assert.equal(live.querySelectorAll('.as-result__rank').length, 0);

    const off = clone(STATE);
    off.round.liveResults = false;
    off.results = [];
    assert.equal(text(renderModel({ state: off }).root.querySelector('.as-live')), 'LiveNo results yet');

    const published = renderModel({ state: publishedState() }).root;
    const items = [...published.querySelectorAll('.as-live__item')];
    assert.deepEqual(items.map((item) => item.getAttribute('data-as-entry')), [E(1), E(6), E(5)]);
    assert.equal(text(items[0].querySelector('.as-result__rank')), '#1');
    assert.equal(text(items[0].querySelector('.as-result__qualified')), 'Q');
    assert.equal(text(published.querySelector('.as-top__live')), '3 official results');
    assert.deepEqual([...published.querySelectorAll('.as-sort option')].map((node) => node.value).at(-1), 'rank');
});

scenario('render: pairs - team name, members line, line-up and members solved, solo prediction label', () => {
    const { state, analytics } = pairState();
    const { root } = renderModel({ state, analytics, view: { selected: E(8) } });
    const row = root.querySelector(`.as-row[data-as-entry="${E(8)}"]`);
    assert.equal(text(row.querySelector('.as-row__team')), 'Lea Roth / Max Roth');
    assert.ok(text(row.querySelector('.as-row__solves')).startsWith('Line-up 2×'));
    assert.ok(text(row.querySelector('.as-row__solves')).includes('members: 1 of 2 solved'));
    const detail = root.querySelector('.as-entrant');
    assert.equal(detail.querySelectorAll('.as-member').length, 2);
    assert.ok(text(detail).includes('Solo prediction'));
    assert.ok(text(detail.querySelector('.as-lineup')).includes('25:00'));
    const sharks = renderModel({ state, analytics, view: { selected: E(7) } }).root.querySelector('.as-entrant');
    assert.ok(text(sharks).includes('No public MySpeedPuzzling history'), 'Eva');
    assert.ok(text(sharks).includes('Žraloci'));
});

scenario('render: help overlay lists every key; search keeps what is typed', () => {
    const closed = renderModel().root;
    assert.equal(closed.querySelector('.as-help').hidden, true);
    const { root, renderer, model, document } = renderModel({ view: { help: true, query: 'ann' } });
    assert.equal(root.querySelector('.as-help').hidden, false);
    assert.equal(root.querySelectorAll('.as-help__row').length, KEYMAP.length);
    assert.ok(text(root.querySelector('.as-help')).includes('Add to or remove from compare (max 4)'));
    assert.equal(renderer.search.value, 'ann');
    // While typing, a render never overwrites the box
    renderer.search.focus();
    renderer.search.value = 'anna';
    renderer.render({ ...model, view: { ...model.view, query: 'ann' } });
    assert.equal(document.activeElement, renderer.search);
    assert.equal(renderer.search.value, 'anna');
});

scenario('render: every label name is listed (LABEL_NAMES) and has an English text', () => {
    const source = readFileSync(new URL('../assets/analytics-studio/render.js', import.meta.url), 'utf8');
    const literal = [...source.matchAll(/\bt\('([a-z0-9_.]+)'/g)].map((match) => match[1]);
    const mapped = [...source.matchAll(/:\s*'((?:history|prediction|similar|rating|skill|column)[a-z0-9_.]*)'/g)].map((match) => match[1]);
    const dynamic = [
        ...['name', 'table', 'solves', 'best', 'rating', 'rank'].map((name) => `sort.${name}`),
        ...['all', 'solved', 'never', 'nohistory'].map((name) => `filter.${name}`),
        ...['entrants', 'puzzle', 'live'].map((name) => `tab.${name}`),
        ...['idle', 'running', 'times_up', 'stopped'].map((name) => `clock.${name}`),
        ...['enthusiast', 'apprentice', 'proficient', 'advanced', 'expert', 'master', 'legend'].map((name) => `skill.${name}`),
        ...['very_easy', 'easy', 'average', 'challenging', 'hard', 'very_hard'].map((name) => `difficulty.${name}`),
        ...['solo', 'pair', 'team'].map((name) => `attempt.${name}`),
        ...['personal', 'general'].map((name) => `prediction.method.${name}`),
        ...KEYMAP.map((entry) => `keys.${entry.action}`),
    ];

    for (const name of [...literal, ...mapped, ...dynamic]) {
        assert.ok(LABEL_NAMES.includes(name), `${name} missing from LABEL_NAMES`);
    }

    assert.deepEqual(LABEL_NAMES.filter((name) => !(name in ENGLISH)), []);
    assert.deepEqual(Object.keys(ENGLISH).filter((name) => !LABEL_NAMES.includes(name)), []);
    assert.equal(new Set(LABEL_NAMES).size, LABEL_NAMES.length);
});

// ---- XSS

const EVIL = '<img src=x onerror=alert(1)>';
const EVIL2 = '"><script>alert(1)</script>';
const BAD_PATHS = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', '//evil.example/x', '/\\evil.example', '\\\\evil.example', 'https://evil.example/', ' /en/x', '/en/x\n', 'data:text/html,<script>', '', null, 42];

scenario('xss: only same-origin "/…" paths become links; only http(s) or same-origin images', () => {
    for (const path of BAD_PATHS) {
        assert.equal(safePath(path), null, String(path));
    }
    assert.equal(safePath('/en/player-profile/abc'), '/en/player-profile/abc');
    assert.equal(safePath('/en/x?a=1#b'), '/en/x?a=1#b');
    assert.equal(safeImageUrl('javascript:alert(1)'), null);
    assert.equal(safeImageUrl('data:image/png;base64,AAAA'), null);
    assert.equal(safeImageUrl('//evil.example/a.png'), null);
    assert.equal(safeImageUrl('https://img.example/a.png'), 'https://img.example/a.png');
    assert.equal(safeImageUrl('/uploads/a.png'), '/uploads/a.png');
});

function evilModel() {
    const state = clone(STATE);
    state.round.name = EVIL2;
    state.slots[0].label = 'A';
    state.slots[0].puzzle = { ...state.slots[0].puzzle, name: EVIL, brand: EVIL2, imageUrl: 'javascript:alert(1)' };
    state.slots[1].puzzle = { ...state.slots[1].puzzle, name: EVIL2, imageUrl: '"><img src=x onerror=alert(1)>' };
    state.entrants = state.entrants.map((entrant, index) => ({
        ...entrant,
        teamName: EVIL2,
        isTeam: index === 3,
        members: entrant.members.map((member) => ({ ...member, name: index % 2 ? EVIL : EVIL2, country: '"><x', profile: BAD_PATHS[index % BAD_PATHS.length] })),
    }));
    // One honest profile path stays a link
    state.entrants[0].members[0].profile = '/en/player-profile/ok';
    const analytics = clone(ANALYTICS);
    analytics.puzzles[A].top = [
        { place: 1, name: EVIL, country: '<b>', profile: 'javascript:alert(1)', seconds: 1, inRound: EVIL2 },
        { place: EVIL, name: EVIL2, country: 'cz', profile: '//evil.example', seconds: EVIL, inRound: null },
    ];
    analytics.puzzles[A].difficulty = { score: EVIL, tier: EVIL2, confidence: EVIL };
    analytics.entrants[E(1)].members[0].skill = { [A]: EVIL };
    const entrant = clone(ENTRANT);
    entrant.members[0].attempts[A][0].kind = EVIL;
    entrant.members[0].recentSimilar[A][0] = { puzzleName: EVIL, brand: EVIL2, pieces: EVIL, seconds: EVIL, solvedAtMs: EVIL };
    entrant.earlierRounds[0].roundName = EVIL;
    entrant.members[0].prediction[A].method = EVIL;
    const predictions = clone(PREDICTIONS);
    const labels = Object.fromEntries(LABEL_NAMES.map((name) => [name, `${EVIL} %count% ${EVIL2}`]));

    return { state, analytics, entrant, predictions, labels };
}

function injected(root) {
    for (const node of root.querySelectorAll('*')) {
        if (['SCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'B', 'X'].includes(node.tagName)) {
            return `element ${node.tagName}`;
        }

        for (const attribute of node.attributes) {
            if (/^on/i.test(attribute.name)) {
                return `attribute ${attribute.name}`;
            }
        }

        if (node.tagName === 'A' && !/^\/[^/\\]/.test(node.getAttribute('href') ?? '')) {
            return `a href ${node.getAttribute('href')}`;
        }

        if (node.tagName === 'IMG' && !/^https?:\/\/|^\/[^/\\]/.test(node.getAttribute('src') ?? '')) {
            return `img src ${node.getAttribute('src')}`;
        }

        if (node.tagName === 'IMG' && node.parentElement?.className !== 'as-panel as-puzzle') {
            return 'an img outside the puzzle picture';
        }
    }

    return null;
}

for (const [name, view] of Object.entries({
    list: {},
    detail: { selected: E(1), open: true },
    'detail of a team with bad paths': { selected: E(4) },
    compare: { compare: [E(1), E(2), E(5), E(6)] },
    'slot B': { puzzle: 'B' },
    help: { help: true, query: EVIL },
    'published-like tab': { tab: 'live' },
})) {
    scenario(`xss corpus through render.js: ${name}`, () => {
        const { state, analytics, entrant, predictions, labels } = evilModel();
        const { root, window } = renderModel({ state, analytics, entrant, predictions, view }, labels);
        const problem = injected(root);
        assert.equal(problem, null, problem);
        assert.equal(window.document.querySelectorAll('script').length, 0);
        assert.ok(root.textContent.includes(EVIL) || root.textContent.includes(EVIL2), 'shown as text');
        const links = [...root.querySelectorAll('a')].map((node) => node.getAttribute('href'));
        assert.ok(links.every((href) => href === '/en/player-profile/ok'), JSON.stringify(links));
        assert.ok([...root.querySelectorAll('a')].every((node) => node.getAttribute('rel').includes('nofollow')));
    });
}

scenario('xss: no HTML sink in the studio modules', () => {
    for (const file of ['model', 'url', 'keys', 'refresh', 'format', 'render']) {
        const source = readFileSync(new URL(`../assets/analytics-studio/${file}.js`, import.meta.url), 'utf8');

        if (/\.(?:innerHTML|outerHTML)\b|insertAdjacentHTML\s*\(|document\.write|\beval\(|new Function|createContextualFragment|DOMParser/.test(source)) {
            throw new Error(`${file}.js uses an HTML sink`);
        }
    }
});

scenario('reuse: the clock, the server clock and the stream are imported, never copied', () => {
    const refresh = readFileSync(new URL('../assets/analytics-studio/refresh.js', import.meta.url), 'utf8');
    const render = readFileSync(new URL('../assets/analytics-studio/render.js', import.meta.url), 'utf8');
    assert.match(refresh, /import \{ OfficialResultsEvents \} from '\.\.\/official_results_events\.js'/);
    assert.match(refresh, /createServerClock/);
    assert.match(render, /import \{ stopwatchView \} from '\.\.\/round_stopwatch_clock\.js'/);
    assert.ok(!/function createServerClock|function stopwatchView|class EventStreamParser/.test(refresh + render));
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
