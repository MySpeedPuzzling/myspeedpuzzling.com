// The People tab's pure helpers (assets/participants_sheet/views/people_view.js): filters with counts, the search, rows
// kept shown while they have the focus or an open editor, the Columns menu's storage, team labels (O1), the counters.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import {
    COLUMN_OPTIONS,
    FILTERS,
    filterCounts,
    matchesFilter,
    matchesSearch,
    offeredFor,
    readColumnPrefs,
    readCountry,
    registrationSummaryHtml,
    teamLabelText,
    visiblePeople,
    writeColumnPrefs,
} from '../../assets/participants_sheet/views/people_view.js';
import { person, smallState } from '../participants-sheet-core/fixture.mjs';

const model = (overrides = {}) => new SheetModel(smallState(overrides), { now: () => 0 });

export default function (test) {
    test('the search looks through name, external id, the visible MSP name and #code - folded', () => {
        const kim = model().person('p-kim');
        assert.ok(matchesSearch(kim, 'kim'));
        assert.ok(matchesSearch(kim, 'KÍM exa'));
        assert.ok(matchesSearch(kim, 'kim e.'));
        assert.ok(matchesSearch(kim, '#kim01'));
        assert.ok(matchesSearch(kim, 'KIM01'));
        assert.ok(!matchesSearch(kim, 'pat'));
        assert.ok(matchesSearch(person('x', 'Ann Other', { externalId: 'WJPF-1234' }), 'wjpf-12'));
        // A profile the viewer may not see (O9) is not searched by its name
        assert.ok(!matchesSearch(person('y', 'Ann Other', { player: { id: 'pl', visible: false, name: 'Secret Name', code: 'zzz' } }), 'secret'));
        assert.ok(matchesSearch(kim, '   '));
    });

    test('filters: not in any round, joined by themselves, removed, duplicate names', () => {
        const state = smallState();
        state.people = state.people.map((row) => (row.id === 'p-jo' ? { ...row, source: 'self_joined' } : row));
        state.people.push(person('p-kim2', 'kim  example'));
        const m = new SheetModel(state, { now: () => 0 });
        const which = (filter) => m.people({ includeRemoved: true }).filter((row) => matchesFilter(m, row, filter)).map((row) => row.id);

        assert.deepEqual(which('no_round'), ['p-ana', 'p-kim2']);
        assert.deepEqual(which('joined'), ['p-jo']);
        assert.deepEqual(which('removed'), ['p-ola']);
        assert.deepEqual(which('duplicates'), ['p-kim', 'p-kim2']);
        assert.equal(which('all').length, 14);
        assert.ok(!which('all').includes('p-ola'));
    });

    test('counts per filter follow the search', () => {
        const m = model();
        const filters = offeredFor(FILTERS, m.competition);
        assert.deepEqual(filters.map((filter) => filter.key), ['all', 'no_round', 'joined', 'duplicates', 'removed']);
        assert.deepEqual(filterCounts(m, filters), { all: 13, no_round: 1, joined: 0, duplicates: 0, removed: 1 });
        assert.deepEqual(filterCounts(m, filters, 'example'), { all: 2, no_round: 1, joined: 0, duplicates: 0, removed: 0 });
    });

    test('a managed in-person event offers the registration filters and columns; online: no check-in', () => {
        const managed = { registrationManaged: true, isOnline: false };
        assert.deepEqual(offeredFor(FILTERS, managed).map((filter) => filter.key), ['all', 'no_round', 'joined', 'waitlist', 'not_paid', 'checked_in', 'not_checked_in', 'duplicates', 'removed']);
        assert.ok(!offeredFor(FILTERS, { registrationManaged: true, isOnline: true }).some((filter) => filter.key.includes('checked')));
        assert.deepEqual(offeredFor(COLUMN_OPTIONS, { registrationManaged: false }).map((column) => column.key), ['name', 'country', 'player', 'rounds', 'externalId', 'source', 'joined', 'note']);
        assert.ok(offeredFor(COLUMN_OPTIONS, managed).some((column) => column.key === 'checkedIn'));
        assert.ok(!offeredFor(COLUMN_OPTIONS, { registrationManaged: true, isOnline: true }).some((column) => column.key === 'checkedIn'));
    });

    test('a held row stays shown whatever the filter says (the focused row, the one open in the editor)', () => {
        const m = model();
        assert.deepEqual(visiblePeople(m, 'no_round'), ['p-ana']);
        assert.deepEqual(visiblePeople(m, 'no_round', '', new Set(['p-kim'])), ['p-ana', 'p-kim']);
        assert.deepEqual(visiblePeople(m, 'all', 'pat'), ['p-pat']);
        assert.deepEqual(visiblePeople(m, 'removed'), ['p-ola']);
    });

    test('the Columns menu is remembered per event; a storage that throws or is missing means the defaults', () => {
        const store = new Map();
        const storage = { getItem: (key) => store.get(key) ?? null, setItem: (key, value) => store.set(key, value) };
        writeColumnPrefs(storage, 'c1', { note: true });
        assert.deepEqual(readColumnPrefs(storage, 'c1'), { note: true });
        assert.deepEqual(readColumnPrefs(storage, 'c2'), {});

        const broken = { getItem: () => { throw new Error('SecurityError'); }, setItem: () => { throw new Error('QuotaExceeded'); } };
        assert.deepEqual(readColumnPrefs(broken, 'c1'), {});
        assert.doesNotThrow(() => writeColumnPrefs(broken, 'c1', { note: true }));
        assert.deepEqual(readColumnPrefs(null, 'c1'), {});
        assert.deepEqual(readColumnPrefs({ getItem: () => '{not json' }, 'c1'), {});
    });

    test('a pasted country: a code, a name in the page language, empty = none, else unknown', () => {
        const countries = { cz: 'Czechia', us: 'United States' };
        assert.equal(readCountry(countries, ' CZ '), 'cz');
        assert.equal(readCountry(countries, 'united states'), 'us');
        assert.equal(readCountry(countries, ''), null);
        assert.equal(readCountry(countries, 'Atlantis'), undefined);
    });

    test('a pair/team is labelled "Corners · Table 2 · Kim Example, Pat Sample" (O1)', () => {
        const m = model();
        const t = (key) => (key === 'team_no_name' ? '(no name)' : key);
        const say = (key, params) => (key === 'team_table' ? `Table ${params.table}` : key);
        assert.equal(teamLabelText(m, 't-corners', t, say), 'Corners · Table 2 · Kim Example, Pat Sample');
        assert.equal(teamLabelText(m, 't-corners2', t, say), 'corners · Lee Mock, Max Demo');
        assert.equal(teamLabelText(m, 't-empty', t, say), '(no name)');
    });

    test('the counters and the first-in-line hint come from the people loaded, names escaped', () => {
        const state = smallState({ competition: { id: 'c1', name: 'Open', isOnline: false, registrationManaged: true, capacity: 20 } });
        state.people = state.people.map((row) => ({
            ...row,
            name: row.id === 'p-kim' ? 'Kim <b>Example</b>' : row.name,
            registration: { status: row.id === 'p-kim' ? 'waitlisted' : (row.id === 'p-jo' ? 'paid' : 'reserved'), registeredAt: null, paidAt: null, checkedInAt: null },
        }));
        const m = new SheetModel(state, { now: () => 0 });
        const say = (key, params = {}) => `${key}(${Object.values(params).join(',')})`;
        const html = registrationSummaryHtml(m, m.competition, say, (key, count, params) => say(key, { count, ...params }));
        assert.ok(html.includes('counter_spots_capacity(12,20)'), html);
        assert.ok(html.includes('counter_waitlist(1)'));
        assert.ok(html.includes('counter_paid(1)'));
        assert.ok(html.includes('counter_checked_in(0)'));
        assert.ok(html.includes('first_in_line(8,Kim &lt;b&gt;Example&lt;/b&gt;)'), html);
        assert.ok(html.includes('data-promote="p-kim"'));

        const full = registrationSummaryHtml(m, { ...m.competition, capacity: 12 }, say, (key, count, params) => say(key, { count, ...params }));
        assert.ok(!full.includes('data-promote'));
    });
}
