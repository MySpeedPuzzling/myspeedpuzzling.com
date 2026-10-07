// The change builders (assets/participants_sheet/sheet_changes.js): wire format, `from` = what the organiser saw,
// client checks with the server's reason codes, and inverses that undo composite actions exactly.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import {
    addPerson,
    buildAction,
    checkChange,
    clearMember,
    combine,
    deleteTeam,
    invertGroups,
    linkProfile,
    newTeamRow,
    putInTeam,
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
        assert.deepEqual(setPlace(m, 'p-t4', ROUND_TEAMS, 'out').errors.map((e) => e.reason), ['has_result_in_round'], 'the team holds a result');
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
}
