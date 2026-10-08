// round/round_common.js - labels (O1), where a person is, the pickers' options (D9), sizes (O7), the row order, member slots,
// the round's entries for ranks and results, and when the results columns show (O3).

import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { PendingChanges } from '../../assets/official_results_pending_changes.js';
import {
    CREATE,
    newPersonLine,
    qualifiedCount,
    rankOrder,
    storedResultsColumns,
    takeTeamOutAction,
    keepOrder,
    memberSlots,
    nameCollator,
    personOptions,
    resultsColumnsShown,
    roundEntries,
    roundHasResults,
    sizeInfo,
    sortedPeopleIds,
    sortedTeamIds,
    storeResultsColumns,
    teamLabelText,
    teamOptions,
    usesTables,
    whereInRound,
} from '../../assets/participants_sheet/round/round_common.js';
import { ROUND_PAIRS, ROUND_SOLO, ROUND_TEAMS, person, place, smallState, team } from '../participants-sheet-core/fixture.mjs';

/** Texts that show the key and its parameters - "size_incomplete:1|2". */
const texts = {
    t: (key, params = {}) => [key, Object.values(params).join('|')].filter(Boolean).join(':'),
    tc: (key, count, params = {}) => [key, [count, ...Object.values(params)].join('|')].join(':'),
};

function model(state = smallState()) {
    return new SheetModel(state, { now: () => 0 });
}

function memoryStorage() {
    const values = new Map();

    return { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, String(value)) };
}

export default function (test) {
    test('O1 labels: name · Table n · members; (no name) without a name, no table part without a table', () => {
        const m = model();
        assert.equal(teamLabelText(m, 't-corners', texts), 'Corners · label_table:2 · Kim Example, Pat Sample');
        assert.equal(teamLabelText(m, 't-corners2', texts), 'corners · Lee Mock, Max Demo');
        assert.equal(teamLabelText(m, 't-empty', texts), 'team_no_name');
        assert.equal(teamLabelText(m, 't-corners', texts, { members: false, table: 7 }), 'Corners · label_table:7');
    });

    test('where a person is in a round, in words', () => {
        const m = model();
        assert.deepEqual(whereInRound(m, 'p-kim', ROUND_PAIRS, texts), { kind: 'team', teamId: 't-corners', text: 'Corners · label_table:2' });
        assert.deepEqual(whereInRound(m, 'p-jo', ROUND_PAIRS, texts), { kind: 'tray', teamId: null, text: 'where_tray_pair' });
        assert.deepEqual(whereInRound(m, 'p-t1', ROUND_PAIRS, texts), { kind: 'out', teamId: null, text: 'where_out' });
        assert.equal(whereInRound(m, 'p-pat', ROUND_SOLO, texts).text, 'where_in');
    });

    test('member cell options: exact names first, then the tray, people outside the round, other pairs ("moves from")', () => {
        const m = model();
        const options = personOptions(m, ROUND_PAIRS, 'a', texts, { teamId: 't-corners2' });
        const names = options.map((option) => option.label);
        // Every active person with an "a" - Ola is removed and never offered
        assert.equal(names.includes('Ola Fictive'), false);
        // Kim of another pair says where she moves from
        const kim = options.find((option) => option.personId === 'p-kim');
        assert.equal(kim.moveFrom, 't-corners');
        assert.match(kim.detail, /moves_from:Corners · label_table:2/);
        // Max is in the pair being edited
        assert.match(options.find((option) => option.personId === 'p-max').detail, /where_this_team/);

        const exact = personOptions(m, ROUND_PAIRS, 'jo do', texts);
        assert.equal(exact[0].personId, 'p-jo');
        assert.equal(exact.some((option) => option.create), false);
    });

    test('an empty query lists the round\'s people without a pair; "+ Add … as a new participant" only for a name nobody has', () => {
        const m = model();
        assert.deepEqual(personOptions(m, ROUND_PAIRS, '', texts).map((option) => option.personId), ['p-jo']);

        const unknown = personOptions(m, ROUND_PAIRS, 'Jo Doe', texts);
        const create = unknown[unknown.length - 1];
        assert.equal(create.create, true);
        assert.equal(create.value, CREATE);
        assert.equal(create.name, 'Jo Doe');
        assert.equal(create.label, 'add_new_person:Jo Doe');
        assert.equal(personOptions(m, ROUND_PAIRS, 'Jo Doe', texts, { create: false }).some((option) => option.create), false);
        // Only people outside the round ("Add people to this round")
        const outside = personOptions(m, ROUND_PAIRS, 'a', texts, { only: 'out' });
        assert.equal(outside.filter((option) => !option.create).every((option) => option.where === 'out'), true);
        assert.ok(outside.some((option) => option.personId === 'p-ana'));
    });

    test('waitlisted people may be placed - with the marker (O8)', () => {
        const state = smallState();
        state.people = state.people.map((p) => (p.id === 'p-ana' ? { ...p, registration: { status: 'waitlisted' } } : p));
        const ana = personOptions(model(state), ROUND_PAIRS, 'ana', texts)[0];
        assert.equal(ana.personId, 'p-ana');
        assert.match(ana.detail, /waitlisted/);
    });

    test('team picker options are labelled per O1 and found by name, table or member', () => {
        const m = model();
        assert.deepEqual(teamOptions(m, ROUND_PAIRS, 'pat', texts).map((option) => option.teamId), ['t-corners']);
        assert.deepEqual(teamOptions(m, ROUND_PAIRS, '', texts, { exclude: new Set(['t-empty']) }).map((option) => option.teamId), ['t-corners', 't-corners2']);
    });

    test('sizes in words (O7): complete, incomplete, too many per kind, no members, same names told apart', () => {
        const state = smallState();
        state.places.push(place('e-ana-pairs', 'p-ana', ROUND_PAIRS, 't-corners'));
        const m = model(state);
        assert.equal(sizeInfo(m, 't-corners', texts).text, 'size_too_many_pair:3|2');
        assert.equal(sizeInfo(m, 't-corners', texts).warn, true);
        assert.equal(sizeInfo(m, 't-corners2', texts).text, 'size_complete:2|2');
        assert.equal(sizeInfo(m, 't-corners2', texts).sameAs, 'size_same_name_table:2');
        assert.equal(sizeInfo(m, 't-corners', texts).sameAs, 'size_same_name_pair:Lee Mock, Max Demo');
        assert.equal(sizeInfo(m, 't-empty', texts).text, 'size_no_members');
        assert.equal(sizeInfo(m, 't-empty', texts).warn, false);
        // Teams round: usual size 3 (3 vs 4 → the smaller) - Flat has one too many
        assert.equal(sizeInfo(m, 't-flat', texts).text, 'size_too_many_team:4|3');

        const names = model(smallState({ teams: smallState().teams.filter((t) => t.roundId === ROUND_PAIRS).map((t) => (t.id === 't-corners' ? { ...t, table: null } : t)) }));
        assert.equal(sizeInfo(names, 't-corners2', texts).sameAs, 'size_same_name_pair:Kim Example, Pat Sample');
    });

    test('a round of names only shows no size warnings (Minnesota, O7)', () => {
        const state = smallState({
            places: [],
            teams: [team('n1', ROUND_TEAMS, 'Owls'), team('n2', ROUND_TEAMS, 'Bats')],
        });
        const m = model(state);
        assert.equal(sizeInfo(m, 'n1', texts).text, 'size_no_members');
        assert.equal(sizeInfo(m, 'n1', texts).warn, false);
        assert.equal(m.problems(ROUND_TEAMS).total, 0);
    });

    test('rows by table (none last), then name in the page language, then id; the order holds while working', () => {
        const state = smallState();
        state.teams = state.teams.map((t) => (t.id === 't-corners2' ? { ...t, table: 1 } : t));
        const m = model(state);
        assert.deepEqual(sortedTeamIds(m, ROUND_PAIRS, nameCollator('en')), ['t-corners2', 't-corners', 't-empty']);
        assert.deepEqual(keepOrder(['b', 'a', 'gone'], ['a', 'b', 'new']), ['b', 'a', 'new']);

        const solo = smallState();
        solo.places = solo.places.map((p) => (p.id === 'e-pat-solo' ? { ...p, table: 3 } : p));
        assert.deepEqual(sortedPeopleIds(model(solo), ROUND_SOLO, nameCollator('en')), ['p-pat', 'p-kim']);
    });

    test('member slots: members keep their column, a typed member lands where typed, no gaps', () => {
        assert.deepEqual(memberSlots(null, ['a', 'b']), ['a', 'b']);
        assert.deepEqual(memberSlots(['b', 'a'], ['a', 'b']), ['b', 'a']);
        assert.deepEqual(memberSlots(['a', 'b'], ['a', 'b', 'c'], { personId: 'c', index: 0 }), ['c', 'a', 'b']);
        assert.deepEqual(memberSlots(['a', 'b'], ['b', 'c'], { personId: 'c', index: 0 }), ['c', 'b']);
        assert.deepEqual(memberSlots(['a', 'b', 'c'], ['a', 'c']), ['a', 'c']);
        assert.deepEqual(memberSlots(['a'], ['a', 'z'], { personId: 'z', index: 9 }), ['a', 'z']);
    });

    test('the round\'s entries as shown: unsaved values included, waitlisted solo people left out', () => {
        const state = smallState();
        state.people = state.people.map((p) => (p.id === 'p-pat' ? { ...p, registration: { status: 'waitlisted' } } : p));
        const m = model(state);
        assert.deepEqual(roundEntries(m, ROUND_SOLO, null).map((entry) => entry.personId), ['p-kim']);

        const pending = new PendingChanges();
        pending.set('team:t-edge', 'result', { seconds: 4000 }, null);
        pending.set('team:t-edge', 'table_number', 5, null);
        const edge = roundEntries(m, ROUND_TEAMS, pending).find((entry) => entry.teamId === 't-edge');
        assert.deepEqual(edge.result, { seconds: 4000 });
        assert.equal(edge.serverResult, null);
        assert.equal(edge.table, 5);
        assert.deepEqual(edge.names, ['Edge', 'Tia One', 'Tom Two', 'Tu Three']);
    });

    test('results columns: shown once the round started or holds results, else hidden - the organiser\'s choice wins', () => {
        const m = model();
        assert.equal(roundHasResults(m, ROUND_TEAMS), true);
        assert.equal(roundHasResults(m, ROUND_PAIRS), false);
        assert.equal(resultsColumnsShown(m, ROUND_TEAMS), true);
        assert.equal(resultsColumnsShown(m, ROUND_PAIRS), false);

        const storage = memoryStorage();
        storeResultsColumns(storage, ROUND_PAIRS, true);
        assert.equal(resultsColumnsShown(m, ROUND_PAIRS, storage), true);
        storeResultsColumns(storage, ROUND_TEAMS, false);
        assert.equal(resultsColumnsShown(m, ROUND_TEAMS, storage), false);
        // A storage that throws (private mode) never breaks the page
        const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };
        assert.equal(resultsColumnsShown(m, ROUND_TEAMS, broken), true);
        storeResultsColumns(broken, ROUND_TEAMS, false);

        const started = model(smallState({ rounds: smallState().rounds.map((r) => (r.id === ROUND_PAIRS ? { ...r, started: true } : r)) }));
        assert.equal(resultsColumnsShown(started, ROUND_PAIRS), true);
    });

    test('table numbers: in person and switched on; online events and rounds without them get a plain index', () => {
        const m = model();
        assert.equal(usesTables(m, m.round(ROUND_PAIRS)), true);
        assert.equal(usesTables(m, { ...m.round(ROUND_PAIRS), tableNumbersOff: true }), false);
        const online = model(smallState({ competition: { ...smallState().competition, isOnline: true } }));
        assert.equal(usesTables(online, online.round(ROUND_PAIRS)), false);
    });

    test('a person typed twice in the event: both offered (the preview / list tells them apart by country and place)', () => {
        const m = model(smallState({ people: [...smallState().people, person('p-jo2', 'Jo Do', { country: 'ca' })] }));
        const options = personOptions(m, ROUND_PAIRS, 'jo do', texts, { countries: { ca: 'Canada' } });
        assert.deepEqual(options.map((option) => option.personId), ['p-jo', 'p-jo2']);
        assert.match(options[1].detail, /^Canada · where_out$/);
    });

    test('D-m2: options say when Enter alone may take them - the one exact name that moves nobody', () => {
        const m = model();
        // A partial match is no exact match
        assert.deepEqual(personOptions(m, ROUND_PAIRS, 'Kim Ex', texts).filter((o) => !o.create).map((o) => [o.personId, o.exact, o.moves]), [['p-kim', false, true]]);
        // Kim typed in full: exact, but she is in Corners - picking her moves her
        const kim = personOptions(m, ROUND_PAIRS, 'Kim Example', texts, { teamId: 't-corners2' }).find((o) => o.personId === 'p-kim');
        assert.equal(kim.exact, true);
        assert.equal(kim.moves, true);
        // Jo is in the tray: exact, moves nobody
        const jo = personOptions(m, ROUND_PAIRS, 'jo do', texts, { teamId: 't-corners2' })[0];
        assert.deepEqual([jo.personId, jo.exact, jo.moves], ['p-jo', true, false]);
        // Within her own pair she moves nobody either
        assert.equal(personOptions(m, ROUND_PAIRS, 'Kim Example', texts, { teamId: 't-corners' })[0].moves, false);
        // Two people called that: neither is exact
        const twins = model(smallState({ people: [...smallState().people, person('p-jo2', 'Jo Do')] }));
        assert.deepEqual(personOptions(twins, ROUND_PAIRS, 'jo do', texts).map((o) => o.exact), [false, false]);
    });

    test('pair/team picker: exact only for the one pair/team called what was typed', () => {
        const m = model();
        assert.deepEqual(teamOptions(m, ROUND_PAIRS, 'Corners', texts).map((o) => o.exact), [false, false]);
        assert.deepEqual(teamOptions(m, ROUND_TEAMS, 'edge', texts).map((o) => [o.teamId, o.exact]), [['t-edge', true]]);
        assert.deepEqual(teamOptions(m, ROUND_TEAMS, '', texts).map((o) => o.exact), [false, false]);
    });

    test('BR6: the qualified count and the order by rank (ties and the unranked by table and name)', () => {
        const state = smallState();
        state.teams = state.teams.map((t) => (t.id === 't-edge' ? { ...t, result: { seconds: 4000 }, qualified: true } : t));
        const m = model(state);
        const entries = roundEntries(m, ROUND_TEAMS, null);
        assert.equal(qualifiedCount(entries), 1);
        // Edge 4000 s, Flat 5000 s
        assert.deepEqual(rankOrder(entries, ['t-flat', 't-edge'], (entry) => entry.id), ['t-edge', 't-flat']);
        const pending = new PendingChanges();
        pending.set('team:t-flat', 'result', { seconds: 3000 }, { seconds: 5000 });
        pending.set('team:t-flat', 'qualified', true, false);
        const shown = roundEntries(m, ROUND_TEAMS, pending);
        assert.deepEqual(rankOrder(shown, ['t-edge', 't-flat'], (entry) => entry.id), ['t-flat', 't-edge']);
        assert.equal(qualifiedCount(shown), 2);
        // Nobody ranked: the fallback order
        assert.deepEqual(rankOrder(roundEntries(m, ROUND_PAIRS, null), ['t-empty', 't-corners', 't-corners2'], (entry) => entry.id), ['t-empty', 't-corners', 't-corners2']);
    });

    test('D-m5: taking a whole pair out of the round is one group and its undo gives the table number back', () => {
        const m = model();
        const action = takeTeamOutAction(m, ROUND_PAIRS, 't-corners');
        assert.equal(action.groups.length, 1);
        assert.deepEqual(action.groups[0].changes.map((change) => change.op === 'place' ? `${change.participant}:${change.from}→${change.to}` : change.op), ['deleteTeam', 'p-kim:in→out', 'p-pat:in→out']);
        assert.deepEqual(action.inverseResults, [{ roundId: ROUND_PAIRS, ref: 'team:t-corners', field: 'table_number', from: null, to: 2, inverseOf: action.groups[0].id }]);
        // Its people back in the round, the pair created again with the same id and its people in it
        assert.deepEqual(action.inverse[0].changes.map((change) => change.op === 'place' ? `${change.participant}:${change.from}→${change.to}` : `${change.op}:${change.id}`), [
            'p-pat:out→in', 'p-kim:out→in', 'newTeam:t-corners', 'p-kim:in→team:t-corners', 'p-pat:in→team:t-corners',
        ]);
        // Without a table number: nothing to give back
        assert.equal(takeTeamOutAction(m, ROUND_PAIRS, 't-corners2').inverseResults, undefined);
    });

    test('the organiser\'s own choice of the results columns, and none', () => {
        const storage = memoryStorage();
        assert.equal(storedResultsColumns(storage, ROUND_PAIRS), null);
        storeResultsColumns(storage, ROUND_PAIRS, false);
        assert.equal(storedResultsColumns(storage, ROUND_PAIRS), false);
    });

    test('BR9: a new name\'s preview line says "Did you mean …?" / what it looks like, and is unticked then', () => {
        assert.deepEqual(newPersonLine(texts, { key: 'kim exampel', name: 'Kim Exampel', close: [{ name: 'Kim Example' }], suspicious: null, tick: false }), {
            id: 'nkim exampel',
            text: 'Kim Exampel',
            status: 'warning',
            note: 'paste_new_person_note · paste_did_you_mean:Kim Example',
            tick: { label: 'paste_add_new_person:Kim Exampel', checked: false },
        });
        assert.equal(newPersonLine(texts, { key: 'us', name: 'US', close: [], suspicious: 'country', tick: false }).note, 'paste_new_person_note · paste_looks_like_country');
        assert.equal(newPersonLine(texts, { key: 'zed', name: 'Zed', close: [], suspicious: null, tick: true }).tick.checked, true);
    });
}
