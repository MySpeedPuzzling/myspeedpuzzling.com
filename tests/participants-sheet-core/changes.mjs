// The change builders (assets/participants_sheet/sheet_changes.js): wire format, `from` = what the organiser saw,
// client checks with the server's reason codes, and inverses that undo composite actions exactly.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import {
    MAX_CHANGES_PER_GROUP,
    addPerson,
    buildAction,
    checkChange,
    checkGroup,
    clearMember,
    combine,
    deleteTeam,
    invertGroups,
    linkProfile,
    newTeamRow,
    putInTeam,
    refusalDetails,
    removePeople,
    renameTeam,
    restorePeople,
    resultsAction,
    setField,
    setFields,
    setInRound,
    setPlace,
    setTeamSize,
    wireChange,
    wireGroups,
} from '../../assets/participants_sheet/sheet_changes.js';
import { ROUND_PAIRS, ROUND_SOLO, ROUND_TEAMS, ids, smallState } from './fixture.mjs';

const model = () => new SheetModel(smallState(), { now: () => 0 });
const changesOf = (action) => action.groups.map((group) => group.changes.map(wireChange));

/** What views show, comparable: people (name, country, player, removed), places and teams. */
function snapshot(m) {
    return JSON.stringify({
        // A person added and taken back is removed, never deleted - not on the list any more is what counts
        people: m.people().map((p) => [p.id, p.name, p.country, p.externalId, p.player?.id ?? null]),
        places: m.people().flatMap((p) => m.rounds().map((r) => `${p.id}@${r.id}=${m.placeValue(p.id, r.id)}`)),
        teams: m.rounds().flatMap((r) => m.teamsOf(r.id).map((t) => [t.id, t.name])),
        sizes: m.rounds().map((r) => r.teamSize),
    });
}

/** Applies an action's groups, then its inverse - the model must look exactly as before. */
function roundTrip(m, action) {
    const before = snapshot(m);
    action.groups.forEach((group) => m.applyLocal(group.id, group.changes));
    const after = snapshot(m);
    action.inverse.forEach((group) => m.applyLocal(group.id, group.changes));
    assert.equal(snapshot(m), before, 'the inverse restores the sheet');

    return after;
}

export default function (test) {
    test('a field change carries what the organiser saw as `from` and the cleaned value as `to`', () => {
        const m = model();
        const action = setField(m, 'p-pat', 'name', '  Pat   Sample-Novak ', { newId: ids('g') });
        assert.deepEqual(changesOf(action), [[{ op: 'field', participant: 'p-pat', field: 'name', from: 'Pat Sample', to: 'Pat Sample-Novak' }]]);
        assert.equal(action.groups[0].id, 'g1');
        assert.deepEqual(changesOf({ groups: action.inverse }), [[{ op: 'field', participant: 'p-pat', field: 'name', from: 'Pat Sample-Novak', to: 'Pat Sample' }]]);
        assert.equal(action.inverse[0].inverseOf, 'g1');
        assert.equal(setField(m, 'p-pat', 'name', 'Pat  Sample').groups.length, 0, 'the same value after cleaning is no change');
        assert.deepEqual(changesOf(setField(m, 'p-pat', 'country', 'CZ')), [[{ op: 'field', participant: 'p-pat', field: 'country', from: 'ca', to: 'cz' }]]);
        assert.deepEqual(changesOf(setField(m, 'p-pat', 'country', '')), [[{ op: 'field', participant: 'p-pat', field: 'country', from: 'ca', to: null }]]);
        assert.deepEqual(changesOf(setField(m, 'p-ana', 'externalId', '  ')), [], 'empty = null = unchanged');
    });

    test('a second edit of a cell still waiting chains on the first (`from` = the organiser\'s own value)', () => {
        const m = model();
        const first = setField(m, 'p-ana', 'name', 'Ana One');
        m.applyLocal(first.groups[0].id, first.groups[0].changes);
        const second = setField(m, 'p-ana', 'name', 'Ana Two');
        assert.equal(second.groups[0].changes[0].from, 'Ana One');
    });

    test('client checks refuse with the server\'s reason codes - nothing invalid leaves the browser', () => {
        const m = model();
        const countries = new Set(['cz', 'us', 'ca']);
        assert.deepEqual(setField(m, 'p-ana', 'name', '   ').errors.map((e) => e.reason), ['name_blank']);
        assert.deepEqual(setField(m, 'p-ana', 'name', 'x'.repeat(256)).errors.map((e) => e.reason), ['name_too_long']);
        assert.equal(setField(m, 'p-ana', 'name', 'ř'.repeat(255)).errors.length, 0, 'length in characters, not bytes');
        assert.deepEqual(setField(m, 'p-ana', 'country', 'xx', { countries }).errors.map((e) => e.reason), ['invalid_country']);
        assert.deepEqual(setField(m, 'p-ana', 'note', 'n'.repeat(256)).errors.map((e) => e.reason), ['note_too_long']);
        assert.deepEqual(setField(m, 'p-ana', 'externalId', 'e'.repeat(256)).errors.map((e) => e.reason), ['external_id_too_long']);
        assert.deepEqual(setField(m, 'p-ola', 'name', 'Ola New').errors.map((e) => e.reason), ['participant_removed']);
        assert.equal(setField(m, 'p-ana', 'name', '   ').groups.length, 0);
    });

    test('the results guard and the connect rule are checked on what the page knows', () => {
        const m = model();
        assert.deepEqual(setPlace(m, 'p-kim', ROUND_SOLO, 'out').errors.map((e) => e.reason), ['has_result_in_round']);
        assert.equal(setPlace(m, 'p-t4', ROUND_TEAMS, 'out').errors.length, 0, 'the team holds a result, Flat keeps three going members');
        assert.equal(setPlace(m, 'p-pat', ROUND_SOLO, 'out').errors.length, 0);
        assert.deepEqual(removePeople(m, ['p-kim', 'p-jo']).errors.map((e) => e.reason), ['has_result_in_event']);
        assert.equal(removePeople(m, ['p-kim', 'p-jo']).groups.length, 1, 'the others go on');
        assert.deepEqual(linkProfile(m, 'p-ana', { id: 'pl-kim', name: 'Kim E.' }).errors.map((e) => e.reason), ['player_linked_elsewhere']);
        assert.deepEqual(setPlace(m, 'p-ana', ROUND_SOLO, 'team:t-edge').errors.map((e) => e.reason), ['not_a_team_round']);
        assert.deepEqual(setPlace(m, 'p-ana', ROUND_PAIRS, 'team:t-edge').errors.map((e) => e.reason), ['team_of_another_round']);
        assert.deepEqual(deleteTeam(m, 't-flat').errors.map((e) => e.reason), ['team_has_result']);
        assert.deepEqual(setTeamSize(m, ROUND_PAIRS, 3).errors.map((e) => e.reason), ['not_a_team_round']);
        assert.deepEqual(setTeamSize(m, ROUND_TEAMS, 21).errors.map((e) => e.reason), ['invalid_team_size']);
        assert.deepEqual(setTeamSize(m, ROUND_TEAMS, 1).errors.map((e) => e.reason), ['invalid_team_size']);
        assert.equal(checkChange({ op: 'unknownOp' }, m), 'invalid_change');
    });

    test('linking a profile carries the pick for display only; the wire has op/participant/from/to', () => {
        const m = model();
        const action = linkProfile(m, 'p-ana', { id: 'pl-ana', name: 'Ana E.', code: 'ANA1', avatar: null });
        assert.equal(action.groups[0].changes[0]._player.name, 'Ana E.');
        assert.deepEqual(wireGroups(action.groups)[0].changes, [{ op: 'player', participant: 'p-ana', from: null, to: 'pl-ana' }]);
        roundTrip(m, action);
        const unlink = linkProfile(m, 'p-kim', null);
        assert.deepEqual(changesOf(unlink), [[{ op: 'player', participant: 'p-kim', from: 'pl-kim', to: null }]]);
        assert.equal(unlink.inverse[0].changes[0]._player.name, 'Kim E.', 'undo shows the profile again at once');
        roundTrip(m, unlink);
    });

    test('"type a pair into the new row" is one group: new people, the team, every member placed', () => {
        const m = model();
        const action = newTeamRow(m, ROUND_PAIRS, { name: ' Night  Owls ', members: ['p-jo', 'p-ana', { name: 'Ny Person', country: 'de' }] }, { newId: ids() });
        assert.equal(action.groups.length, 1);
        assert.deepEqual(changesOf(action)[0], [
            { op: 'newParticipant', id: 'id2', name: 'Ny Person', country: 'de', externalId: null },
            { op: 'newTeam', id: 'id1', round: ROUND_PAIRS, name: 'Night Owls' },
            { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:id1' },
            { op: 'place', participant: 'p-ana', round: ROUND_PAIRS, from: 'out', to: 'team:id1' },
            { op: 'place', participant: 'id2', round: ROUND_PAIRS, from: 'out', to: 'team:id1' },
        ]);
        assert.equal(action.teamId, 'id1');
        assert.deepEqual(changesOf({ groups: action.inverse })[0], [
            { op: 'place', participant: 'id2', round: ROUND_PAIRS, from: 'team:id1', to: 'out' },
            { op: 'place', participant: 'p-ana', round: ROUND_PAIRS, from: 'team:id1', to: 'out' },
            { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'team:id1', to: 'in' },
            { op: 'deleteTeam', team: 'id1' },
            { op: 'remove', participant: 'id2' },
        ]);
        assert.equal(newTeamRow(m, ROUND_PAIRS, { name: '  ', members: [] }).groups.length, 0);
    });

    test('moving a member from another pair: `from` names the old pair; undo puts them back', () => {
        const m = model();
        const action = putInTeam(m, ROUND_PAIRS, 't-corners2', 'p-kim');
        assert.deepEqual(changesOf(action), [[{ op: 'place', participant: 'p-kim', round: ROUND_PAIRS, from: 'team:t-corners', to: 'team:t-corners2' }]]);
        roundTrip(m, action);
        // (into a named pair: an unnamed one emptied again by the undo is deleted by the server - its rule)
        const fresh = putInTeam(m, ROUND_PAIRS, 't-corners2', { name: 'Jo Do Two', country: null }, { newId: ids('n') });
        assert.equal(fresh.personId, 'n1');
        roundTrip(m, fresh);
    });

    test('clearing the last member of an unnamed pair: its undo creates the pair again first', () => {
        const m = model();
        const create = newTeamRow(m, ROUND_PAIRS, { members: ['p-jo'] }, { newId: ids('t') });
        create.groups.forEach((group) => m.applyLocal(group.id, group.changes));
        m.confirm(create.groups[0].id);

        const clear = clearMember(m, ROUND_PAIRS, 'p-jo', { newId: ids('g') });
        assert.deepEqual(changesOf(clear), [[{ op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'team:t1', to: 'in' }]]);
        assert.deepEqual(changesOf({ groups: clear.inverse }), [[
            { op: 'newTeam', id: 't1', round: ROUND_PAIRS, name: null },
            { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:t1' },
        ]]);
        roundTrip(m, clear);
    });

    test('deleting a pair: undo creates it with the same id and puts its active members back', () => {
        const m = model();
        const action = deleteTeam(m, 't-corners');
        assert.deepEqual(changesOf({ groups: action.inverse }), [[
            { op: 'newTeam', id: 't-corners', round: ROUND_PAIRS, name: 'Corners' },
            { op: 'place', participant: 'p-kim', round: ROUND_PAIRS, from: 'in', to: 'team:t-corners' },
            { op: 'place', participant: 'p-pat', round: ROUND_PAIRS, from: 'in', to: 'team:t-corners' },
        ]], 'Ola is removed - never placed back');
    });

    test('rename, team size, remove/restore, new person: exact inverses', () => {
        const m = model();
        roundTrip(m, renameTeam(m, 't-corners', 'Corner Pieces'));
        roundTrip(m, renameTeam(m, 't-corners', ''));
        roundTrip(m, setTeamSize(m, ROUND_TEAMS, 4));
        roundTrip(m, removePeople(m, ['p-jo', 'p-ana']));
        roundTrip(m, restorePeople(m, ['p-ola']));
        roundTrip(m, setInRound(m, ['p-ana', 'p-jo', 'p-pat'], ROUND_SOLO, true));
        roundTrip(m, setInRound(m, ['p-pat'], ROUND_SOLO, false));
        const add = addPerson(m, { name: 'Ny Person', country: 'cz' }, { newId: ids('p') });
        assert.equal(add.personId, 'p1');
        assert.deepEqual(changesOf({ groups: add.inverse }), [[{ op: 'remove', participant: 'p1' }]]);
    });

    test('bulk actions are one group per row (independent), one undo step', () => {
        const m = model();
        const action = setInRound(m, ['p-ana', 'p-jo', 'p-pat', 'p-kim'], ROUND_SOLO, true);
        assert.equal(action.groups.length, 2, 'Pat and Kim are in already');
        assert.equal(action.inverse.length, 2);
        assert.deepEqual(action.inverse.map((group) => group.inverseOf), action.groups.map((group) => group.id).reverse());
        const fill = setFields(m, 'country', [{ personId: 'p-ana', value: 'cz' }, { personId: 'p-pat', value: 'CA' }, { personId: 'p-jo', value: 'cz' }]);
        assert.equal(fill.groups.length, 2);
    });

    test('inverting a sequence of groups reads each against the state the earlier ones left', () => {
        const m = model();
        const groups = [
            { id: 'a', changes: [{ op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:t-empty' }] },
            { id: 'b', changes: [{ op: 'deleteTeam', team: 't-empty' }] },
        ];
        const inverse = invertGroups(groups, m, ids('i'));
        assert.deepEqual(inverse.map((group) => group.inverseOf), ['b', 'a']);
        assert.deepEqual(inverse[0].changes.map(wireChange), [
            { op: 'newTeam', id: 't-empty', round: ROUND_PAIRS, name: null },
            { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:t-empty' },
        ]);
    });

    test('combine joins actions into one undo step; buildAction skips empty groups', () => {
        const m = model();
        const joined = combine({ key: 'paste' }, setField(m, 'p-ana', 'name', 'Ana Two'), setField(m, 'p-jo', 'country', 'cz'));
        assert.equal(joined.groups.length, 2);
        assert.equal(joined.inverse[0].inverseOf, joined.groups[1].id);
        assert.equal(buildAction(m, [[], []]).groups.length, 0);
    });

    test('results changes undo by swapping from and to', () => {
        const action = resultsAction([
            { roundId: ROUND_SOLO, ref: 'participant_round:e-pat-solo', field: 'result', from: null, to: { seconds: 61 } },
            { roundId: ROUND_SOLO, ref: 'participant_round:e-pat-solo', field: 'qualified', from: false, to: false },
        ]);
        assert.equal(action.results.length, 1);
        assert.deepEqual(action.inverseResults, [{ roundId: ROUND_SOLO, ref: 'participant_round:e-pat-solo', field: 'result', from: { seconds: 61 }, to: null }]);
    });

    test('B1: every builder takes `options.from` (what the editor showed when it opened) as the change\'s `from`', () => {
        const m = model();
        // Somebody else's values arrived meanwhile - the model shows them, the organiser's editor showed the old ones
        m.applyLocalMany([{ id: 'theirs', changes: [
            { op: 'field', participant: 'p-kim', field: 'name', from: 'Kim Example', to: 'Kimberly Example' },
            { op: 'field', participant: 'p-kim', field: 'country', from: 'us', to: 'ca' },
            { op: 'player', participant: 'p-pat', from: null, to: 'pl-pat' },
            { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:t-empty' },
            { op: 'renameTeam', team: 't-edge', from: 'Edge', to: 'Edges' },
            { op: 'teamSize', round: ROUND_TEAMS, from: null, to: 4 },
            { op: 'place', participant: 'p-ana', round: ROUND_SOLO, from: 'out', to: 'in' },
        ] }]);

        assert.deepEqual(changesOf(setField(m, 'p-kim', 'name', 'Kim Two', { from: 'Kim Example' })), [[{ op: 'field', participant: 'p-kim', field: 'name', from: 'Kim Example', to: 'Kim Two' }]]);
        assert.deepEqual(changesOf(setFields(m, 'country', [{ personId: 'p-kim', value: 'cz', from: 'us' }, { personId: 'p-pat', value: 'cz' }])), [
            [{ op: 'field', participant: 'p-kim', field: 'country', from: 'us', to: 'cz' }],
            [{ op: 'field', participant: 'p-pat', field: 'country', from: 'ca', to: 'cz' }],
        ], 'per item; the others what the model shows');
        assert.deepEqual(changesOf(linkProfile(m, 'p-pat', { id: 'pl-other', name: 'O' }, { from: null })), [[{ op: 'player', participant: 'p-pat', from: null, to: 'pl-other' }]]);
        assert.deepEqual(changesOf(linkProfile(m, 'p-pat', null, { from: 'pl-pat' })), [[{ op: 'player', participant: 'p-pat', from: 'pl-pat', to: null }]]);
        assert.deepEqual(changesOf(setPlace(m, 'p-jo', ROUND_PAIRS, 'out', { from: 'in' })), [[{ op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'out' }]]);
        assert.deepEqual(changesOf(setInRound(m, ['p-ana', 'p-pat'], ROUND_SOLO, false, { from: new Map([['p-ana', 'in']]) })), [
            [{ op: 'place', participant: 'p-ana', round: ROUND_SOLO, from: 'in', to: 'out' }],
            [{ op: 'place', participant: 'p-pat', round: ROUND_SOLO, from: 'in', to: 'out' }],
        ]);
        assert.deepEqual(changesOf(putInTeam(m, ROUND_PAIRS, 't-corners2', 'p-jo', { from: 'in' })), [[{ op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:t-corners2' }]]);
        assert.deepEqual(changesOf(clearMember(m, ROUND_PAIRS, 'p-jo', { from: 'team:t-corners' })), [[{ op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'team:t-corners', to: 'in' }]]);
        assert.deepEqual(changesOf(renameTeam(m, 't-edge', 'Edge Two', { from: 'Edge' })), [[{ op: 'renameTeam', team: 't-edge', from: 'Edge', to: 'Edge Two' }]]);
        assert.deepEqual(changesOf(setTeamSize(m, ROUND_TEAMS, 3, { from: null })), [[{ op: 'teamSize', round: ROUND_TEAMS, from: null, to: 3 }]]);
        const row = newTeamRow(m, ROUND_PAIRS, { name: 'Owls', members: ['p-jo'] }, { newId: ids('n'), from: (personId) => (personId === 'p-jo' ? 'in' : undefined) });
        assert.equal(changesOf(row)[0][1].from, 'in', 'where the member was when picked');
        assert.equal(setField(m, 'p-kim', 'name', 'Kim Two', { from: 'Kim Example' }).inverse[0].changes[0].to, 'Kim Example', 'undo restores what the organiser saw');
    });

    test('B1: a value equal to what the editor showed when it opened is no change - no group, no undo step', () => {
        const m = model();
        m.applyLocalMany([{ id: 'theirs', changes: [{ op: 'field', participant: 'p-kim', field: 'name', from: 'Kim Example', to: 'Kimberly Example' }] }]);
        assert.equal(setField(m, 'p-kim', 'name', '  Kim  Example ', { from: 'Kim Example' }).groups.length, 0);
        assert.equal(setField(m, 'p-kim', 'name', 'Kimberly Example', { from: 'Kim Example' }).groups.length, 0, 'typed what they have: agreed');
        assert.equal(setFields(m, 'name', [{ personId: 'p-kim', value: 'Kim Example', from: 'Kim Example' }]).groups.length, 0);
        assert.equal(linkProfile(m, 'p-kim', null, { from: null }).groups.length, 0);
        assert.equal(setPlace(m, 'p-kim', ROUND_SOLO, 'out', { from: 'out' }).groups.length, 0);
        assert.equal(renameTeam(m, 't-edge', 'Edge', { from: 'Edge' }).groups.length, 0);
        assert.equal(setTeamSize(m, ROUND_TEAMS, null, { from: null }).groups.length, 0);
    });

    test('a member may leave a pair/team holding a result while it keeps a going member - never empty it (waitlisted members do not count)', () => {
        const state = smallState();
        state.competition = { ...state.competition, registrationManaged: true };
        // Flat (a result) = t4..t7; Corners2 gets a result, Lee + Max (Max on the waitlist)
        state.people = state.people.map((p) => (p.id === 'p-max' ? { ...p, registration: { status: 'waitlisted' } } : p));
        state.teams = state.teams.map((t) => (t.id === 't-corners2' ? { ...t, result: { seconds: 900 } } : t));
        const m = new SheetModel(state, { now: () => 0 });

        assert.equal(setPlace(m, 'p-t4', ROUND_TEAMS, 'in').errors.length, 0, 'to the tray');
        assert.equal(setPlace(m, 'p-t4', ROUND_TEAMS, 'team:t-edge').errors.length, 0, 'to another team');
        assert.equal(setPlace(m, 'p-t4', ROUND_TEAMS, 'out').errors.length, 0, 'out of the round');
        const three = buildAction(m, [['p-t4', 'p-t5', 'p-t6'].map((id) => ({ op: 'place', participant: id, round: ROUND_TEAMS, from: `team:t-flat`, to: 'in' }))]);
        assert.equal(three.errors.length, 0, 'Tom keeps it');
        const all = buildAction(m, [['p-t4', 'p-t5', 'p-t6', 'p-t7'].map((id) => ({ op: 'place', participant: id, round: ROUND_TEAMS, from: `team:t-flat`, to: 'in' }))]);
        assert.deepEqual(all.errors.map((e) => [e.reason, e.change.participant]), [['team_has_result', 'p-t7']], 'the last going member');
        assert.equal(buildAction(m, [[
            { op: 'place', participant: 'p-t4', round: ROUND_TEAMS, from: 'team:t-flat', to: 'in' },
            { op: 'place', participant: 'p-t5', round: ROUND_TEAMS, from: 'team:t-flat', to: 'in' },
            { op: 'place', participant: 'p-t6', round: ROUND_TEAMS, from: 'team:t-flat', to: 'in' },
            { op: 'place', participant: 'p-t7', round: ROUND_TEAMS, from: 'team:t-flat', to: 'in' },
            { op: 'place', participant: 'p-t1', round: ROUND_TEAMS, from: 'team:t-edge', to: 'team:t-flat' },
        ]]).errors.length, 0, 'the group as a whole decides (a new line-up in one go)');

        assert.deepEqual(setPlace(m, 'p-lee', ROUND_PAIRS, 'in').errors.map((e) => e.reason), ['team_has_result'], 'Max is on the waitlist - not a going member');
        assert.equal(setPlace(m, 'p-max', ROUND_PAIRS, 'in').errors.length, 0, 'the waitlisted one may go');
        assert.deepEqual(setPlace(m, 'p-kim', ROUND_SOLO, 'out').errors.map((e) => e.reason), ['has_result_in_round'], 'an own result still holds');
        assert.deepEqual(removePeople(m, ['p-t4']).errors.map((e) => e.reason), ['has_result_in_event'], 'removal unchanged');
    });

    test('an external id another active participant of the event has is refused (external_id_taken)', () => {
        const state = smallState();
        state.people = state.people.map((p) => (p.id === 'p-ana' ? { ...p, externalId: 'A-1' } : (p.id === 'p-ola' ? { ...p, externalId: 'O-1' } : p)));
        const m = new SheetModel(state, { now: () => 0 });
        assert.deepEqual(setField(m, 'p-jo', 'externalId', ' A-1 ').errors.map((e) => e.reason), ['external_id_taken']);
        assert.equal(setField(m, 'p-jo', 'externalId', 'O-1').errors.length, 0, 'a removed participant\'s id is free');
        assert.equal(setField(m, 'p-jo', 'externalId', 'a-1').errors.length, 0, 'compared exactly, like the import');
        assert.deepEqual(addPerson(m, { name: 'Ny', externalId: 'A-1' }).errors.map((e) => e.reason), ['external_id_taken']);
        assert.equal(checkChange({ op: 'field', participant: 'p-ana', field: 'externalId', from: 'A-1', to: 'A-1' }, m), null, 'their own');
    });

    test('a group of more than MAX_CHANGES_PER_GROUP changes never leaves the browser; checkGroup leaves the state as it was', () => {
        const m = model();
        const changes = Array.from({ length: MAX_CHANGES_PER_GROUP + 1 }, (_, index) => ({ op: 'newParticipant', id: `n${index}`, name: `N ${index}`, country: null, externalId: null }));
        const action = buildAction(m, [changes]);
        assert.deepEqual(action.errors.map((e) => e.reason), ['too_many_changes']);
        assert.equal(action.groups.length, 0);

        const working = m.scratch();
        const before = JSON.stringify([[...working.people.keys()], [...working.places.keys()], [...working.teams.keys()], working.order]);
        const refused = checkGroup([
            { op: 'newParticipant', id: 'x1', name: 'X', country: null, externalId: null },
            { op: 'place', participant: 'x1', round: ROUND_SOLO, from: 'out', to: 'in' },
            { op: 'field', participant: 'x1', field: 'name', from: 'X', to: ' ' },
        ], working, { now: 0 });
        assert.equal(refused.reason, 'name_blank');
        assert.equal(JSON.stringify([[...working.people.keys()], [...working.places.keys()], [...working.teams.keys()], working.order]), before, 'rolled back');
        assert.equal(working.journal, null);
    });

    test('a new person\'s country is lower-cased like any other country', () => {
        const m = model();
        assert.equal(addPerson(m, { name: 'Ny', country: ' CZ ' }).groups[0].changes[0].country, 'cz');
        assert.equal(newTeamRow(m, ROUND_PAIRS, { members: [{ name: 'Jo New', country: 'DE' }] }).groups[0].changes[0].country, 'de');
    });

    test('a deleted pair\'s undo gives its table number back, tied to the group that deletes it; combine keeps results undo', () => {
        const m = model();
        const action = deleteTeam(m, 't-corners', { newId: ids('d') });
        assert.deepEqual(action.inverseResults, [{ roundId: ROUND_PAIRS, ref: 'team:t-corners', field: 'table_number', from: null, to: 2, inverseOf: 'd1' }]);
        assert.equal(deleteTeam(m, 't-corners2').inverseResults, undefined, 'no table, nothing to give back');

        const joined = combine({ key: 'paste' },
            resultsAction([{ roundId: ROUND_PAIRS, ref: 'team:t-corners', field: 'result', from: null, to: { seconds: 3000 } }]),
            resultsAction([{ roundId: ROUND_PAIRS, ref: 'team:t-corners2', field: 'result', from: null, to: { seconds: 3100 } }]));
        assert.equal(joined.results.length, 2);
        assert.deepEqual(joined.inverseResults.map((change) => [change.ref, change.from, change.to]), [
            ['team:t-corners2', { seconds: 3100 }, null],
            ['team:t-corners', { seconds: 3000 }, null],
        ]);
    });

    test('a client refusal is worded like the server\'s (its cause variant and parameters from the page)', () => {
        const state = smallState();
        state.competition = { ...state.competition, registrationManaged: true };
        state.people = state.people.map((p) => {
            if (p.id === 'p-ana') {
                return { ...p, externalId: 'A-1' };
            }

            if (p.id === 'p-lee') {
                return { ...p, playerResultRounds: [ROUND_PAIRS] };
            }

            return p.id === 'p-max' ? { ...p, registration: { status: 'waitlisted' } } : p;
        });
        state.teams = state.teams.map((t) => (t.id === 't-corners2' ? { ...t, result: { seconds: 900 } } : t));
        const m = new SheetModel(state, { now: () => 0 });
        const details = (action) => refusalDetails(action.errors[0], m);

        assert.deepEqual(details(setField(m, 'p-ola', 'name', 'Ola Two')), { key: 'participant_removed', params: { name: 'Ola Fictive' } });
        assert.deepEqual(details(setField(m, 'p-jo', 'externalId', 'A-1')), { key: 'external_id_taken', params: { id: 'A-1', other: 'Ana Example' } });
        assert.deepEqual(details(setPlace(m, 'p-kim', ROUND_SOLO, 'out')), { key: 'has_result_in_round', params: { name: 'Kim Example', round: 'Solo' } });
        assert.deepEqual(refusalDetails({ reason: 'has_result_in_round', change: { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'out' } }, new SheetModel({ ...state, people: state.people.map((p) => (p.id === 'p-jo' ? { ...p, playerResultRounds: [ROUND_PAIRS] } : p)) })),
            { key: 'has_result_in_round_own_time', params: { name: 'Jo Do', round: 'Pairs' } });
        assert.deepEqual(details(setPlace(m, 'p-lee', ROUND_PAIRS, 'in')), { key: 'team_has_result_waitlisted_only', params: { team: 'corners', round: 'Pairs' } });
        const emptied = buildAction(m, [['p-t4', 'p-t5', 'p-t6', 'p-t7'].map((id) => ({ op: 'place', participant: id, round: ROUND_TEAMS, from: 'team:t-flat', to: 'in' }))]);
        assert.deepEqual(details(emptied), { key: 'team_has_result_emptied', params: { team: 'Flat', round: 'Teams' } });
        assert.deepEqual(details(deleteTeam(m, 't-flat')), { key: 'team_has_result', params: { team: 'Flat', round: 'Teams' } });
        assert.deepEqual(details(setTeamSize(m, ROUND_TEAMS, 30)), { key: 'invalid_team_size', params: { min: 2, max: 20 } });
        assert.deepEqual(details(linkProfile(m, 'p-jo', { id: 'pl-kim' })), { key: 'player_linked_elsewhere', params: { other: 'Kim Example' } });
        assert.deepEqual(details(setField(m, 'p-jo', 'name', 'x'.repeat(300))), { key: 'name_too_long', params: { max: 255 } });
    });

    test('round tabs word a client refusal like the core, with their own team label (O1)', async () => {
        const { roundRefusalText } = await import('../../assets/participants_sheet/round/round_common.js');
        const m = model();
        const roundTexts = { t: (key, params = {}) => (key === 'label_table' ? `Table ${params.table}` : key), tc: (key) => key, has: () => true };
        const context = {
            texts: { core: { has: (key) => key === 'reason_team_has_result' } },
            reasonText: (code, params) => `${code}: ${JSON.stringify(params)}`,
        };
        assert.equal(roundRefusalText(context, m, roundTexts, deleteTeam(m, 't-flat').errors[0]), 'team_has_result: {"team":"Flat","round":"Teams"}');
        const corners = { ...deleteTeam(m, 't-flat').errors[0], change: { op: 'deleteTeam', team: 't-corners' } };
        assert.equal(roundRefusalText(context, m, roundTexts, corners), 'team_has_result: {"team":"Corners · Table 2","round":"Pairs"}');
    });
}
