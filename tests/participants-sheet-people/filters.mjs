// The People tab's pure helpers (assets/participants_sheet/views/people_view.js): filters with counts, the search, rows
// kept shown while they have the focus or an open editor, the Columns menu's storage, team labels (O1), the counters.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import {
    COLUMN_OPTIONS,
    FILTERS,
    filterCounts,
    keepOrder,
    matchesFilter,
    matchesRoundFilter,
    matchesSearch,
    offeredFor,
    parseRoundFilter,
    readColumnPrefs,
    readCountry,
    readSortPref,
    registrationSummaryHtml,
    sortPeople,
    teamLabelText,
    typedNumber,
    visiblePeople,
    writeColumnPrefs,
    writeSortPref,
} from '../../assets/participants_sheet/views/people_view.js';
import { person, place, smallState } from '../participants-sheet-core/fixture.mjs';

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
        assert.deepEqual(offeredFor(FILTERS, managed).map((filter) => filter.key), ['all', 'no_round', 'joined', 'waitlist', 'not_paid', 'paid', 'checked_in', 'not_checked_in', 'duplicates', 'removed']);
        assert.ok(!offeredFor(FILTERS, { registrationManaged: true, isOnline: true }).some((filter) => filter.key.includes('checked')));
        assert.deepEqual(offeredFor(COLUMN_OPTIONS, { registrationManaged: false }).map((column) => column.key), ['name', 'country', 'player', 'rounds', 'externalId', 'source', 'joined', 'note']);
        assert.ok(offeredFor(COLUMN_OPTIONS, managed).some((column) => column.key === 'registered'));
        assert.ok(offeredFor(COLUMN_OPTIONS, managed).some((column) => column.key === 'checkedIn'));
        assert.ok(!offeredFor(COLUMN_OPTIONS, { registrationManaged: true, isOnline: true }).some((column) => column.key === 'checkedIn'));
    });

    test('BR4: "In no solo round" (with a round of another kind), "In 2+ solo rounds" (with 2+ solo rounds)', () => {
        const state = smallState();
        state.rounds.splice(1, 0, { id: 'r-solo2', name: 'Group B', category: 'solo', teamSize: null, started: false, tableNumbersOff: false, piecesCount: 500, resultsPublished: false });
        state.places.push(place('e-kim-solo2', 'p-kim', 'r-solo2'), place('e-ana-solo2', 'p-ana', 'r-solo2'));
        const m = new SheetModel(state, { now: () => 0 });
        const which = (filter) => m.people({ includeRemoved: true }).filter((row) => matchesFilter(m, row, filter)).map((row) => row.id);

        assert.deepEqual(offeredFor(FILTERS, m.competition, m.rounds()).map((filter) => filter.key), ['all', 'no_round', 'no_solo', 'multi_solo', 'joined', 'duplicates', 'removed']);
        assert.deepEqual(which('multi_solo'), ['p-kim']);
        assert.ok(which('no_solo').includes('p-jo') && !which('no_solo').includes('p-pat') && !which('no_solo').includes('p-ana'));
        assert.ok(!which('no_solo').includes('p-ola'), 'removed people only under Removed');

        // Only solo rounds: "In no solo round" = "Not in any round" - not offered; one solo round: no "2+"
        const soloOnly = [{ id: 'a', category: 'solo' }, { id: 'b', category: 'solo' }];
        assert.deepEqual(offeredFor(FILTERS, {}, soloOnly).map((filter) => filter.key), ['all', 'no_round', 'multi_solo', 'joined', 'duplicates', 'removed']);
        assert.deepEqual(offeredFor(FILTERS, {}, [{ id: 'a', category: 'solo' }, { id: 'p', category: 'duo' }]).map((filter) => filter.key), ['all', 'no_round', 'no_solo', 'joined', 'duplicates', 'removed']);
    });

    test('BR4: the round select - "In Pairs" (any place in it) / "Not in Pairs"; with the filters and the search', () => {
        const m = model();
        assert.deepEqual(parseRoundFilter('in:r-pairs'), { roundId: 'r-pairs', inRound: true });
        assert.equal(parseRoundFilter(''), null);
        assert.equal(parseRoundFilter('waitlist'), null);
        // Jo is in Pairs without a pair - in the round all the same
        assert.deepEqual(visiblePeople(m, 'all', '', null, 'in:r-pairs'), ['p-jo', 'p-kim', 'p-lee', 'p-max', 'p-pat']);
        assert.ok(!visiblePeople(m, 'all', '', null, 'out:r-pairs').includes('p-kim'));
        assert.deepEqual(visiblePeople(m, 'all', 'kim', null, 'out:r-pairs'), []);
        assert.ok(matchesRoundFilter(m, m.person('p-ana'), 'in:r-gone'), 'a round that is gone filters nothing');
        assert.deepEqual(filterCounts(m, offeredFor(FILTERS, m.competition), '', 'in:r-solo'), { all: 2, no_round: 0, joined: 0, duplicates: 0, removed: 0 });
    });

    test('BR4: sorting by name, country, external ID (numbers as numbers), registered, joined - empty values last both ways', () => {
        const rows = [
            person('a', 'Zoe Last', { country: 'us', externalId: '10', registration: { status: 'reserved', registeredAt: '2026-09-03T10:00:00+00:00' } }),
            person('b', 'ádam First', { country: null, externalId: '9', registration: { status: 'reserved', registeredAt: '2026-09-01T10:00:00+00:00' }, source: 'self_joined', connectedAt: '2026-09-05T10:00:00+00:00' }),
            person('c', 'Mia Middle', { country: 'ca', externalId: null, registration: { status: 'reserved', registeredAt: null }, source: 'self_joined', connectedAt: '2026-09-02T10:00:00+00:00' }),
        ];
        const countries = { us: 'United States', ca: 'Canada' };
        const order = (key, dir = 'asc') => sortPeople(rows, { key, dir }, { countries, locale: 'en' }).map((row) => row.id);

        assert.deepEqual(order('name'), ['b', 'c', 'a'], 'accents and case do not matter');
        assert.deepEqual(order('name', 'desc'), ['a', 'c', 'b']);
        assert.deepEqual(order('country'), ['c', 'a', 'b'], 'Canada, United States, none last');
        assert.deepEqual(order('country', 'desc'), ['a', 'c', 'b'], 'none last when reversed too');
        assert.deepEqual(order('externalId'), ['b', 'a', 'c'], '9 before 10');
        assert.deepEqual(order('registered'), ['b', 'a', 'c']);
        assert.deepEqual(order('joined', 'desc'), ['b', 'c', 'a'], 'only people who joined by themselves have a date');
        assert.deepEqual(sortPeople(rows, null).map((row) => row.id), ['a', 'b', 'c'], 'no sort = the list order');
        assert.deepEqual(sortPeople(rows, { key: 'note', dir: 'asc' }).map((row) => row.id), ['a', 'b', 'c'], 'not a sortable column');
    });

    test('BR4: while the organiser works in the grid the rows keep their places; new rows go where the sort puts them', () => {
        // Kim renamed to "Aaron" while sorted by name: the sorted list puts him first, the shown rows keep him where he was
        assert.deepEqual(keepOrder(['kim', 'ana', 'jo', 'pat'], ['ana', 'jo', 'kim', 'pat']), ['ana', 'jo', 'kim', 'pat']);
        // A new person and a person gone
        assert.deepEqual(keepOrder(['ana', 'bea', 'kim', 'pat'], ['ana', 'jo', 'kim', 'pat']), ['ana', 'bea', 'kim', 'pat']);
        assert.deepEqual(keepOrder(['new', 'ana'], ['ana']), ['new', 'ana']);
        assert.deepEqual(keepOrder(['ana'], []), ['ana']);
    });

    test('BR4: the sort is remembered per event; a storage that throws or holds junk means no sort', () => {
        const store = new Map();
        const storage = { getItem: (key) => store.get(key) ?? null, setItem: (key, value) => store.set(key, value), removeItem: (key) => store.delete(key) };
        writeSortPref(storage, 'c1', { key: 'country', dir: 'desc' });
        assert.deepEqual(readSortPref(storage, 'c1'), { key: 'country', dir: 'desc' });
        assert.equal(readSortPref(storage, 'c2'), null);
        writeSortPref(storage, 'c1', null);
        assert.equal(readSortPref(storage, 'c1'), null);

        const broken = { getItem: () => { throw new Error('SecurityError'); }, setItem: () => { throw new Error('QuotaExceeded'); }, removeItem: () => { throw new Error('x'); } };
        assert.equal(readSortPref(broken, 'c1'), null);
        assert.doesNotThrow(() => writeSortPref(broken, 'c1', { key: 'name', dir: 'asc' }));
        assert.equal(readSortPref({ getItem: () => '{"key":"note","dir":"asc"}' }, 'c1'), null);
        assert.equal(readSortPref({ getItem: () => '{"key":"name","dir":"sideways"}' }, 'c1'), null);
    });

    test('the typed number of the large-removal check: full-width digits and spaces read as digits', () => {
        assert.equal(typedNumber('１２'), '12');
        assert.equal(typedNumber(' 1 2 '), '12');
        assert.equal(typedNumber('12'), '12');
        assert.equal(typedNumber(null), '');
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
        // E-3: a readable button (not white on the theme's light green) that says an e-mail goes out
        assert.ok(html.includes('btn-outline-success sheet-btn-success'), html);
        assert.ok(!html.includes('btn btn-sm btn-success'));
        assert.ok(html.includes('first_in_line_email()'));
        assert.ok(html.includes('aria-describedby="sheet-first-in-line-mail"'));

        const full = registrationSummaryHtml(m, { ...m.competition, capacity: 12 }, say, (key, count, params) => say(key, { count, ...params }));
        assert.ok(!full.includes('data-promote'));
    });
}
