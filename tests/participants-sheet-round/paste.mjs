// round_paste.js - rows of pairs/teams (match by name, by members, ambiguous names, ambiguous people, new people,
// moves), positional pastes onto existing rows, and results pastes matched only to the round's entries.

import assert from 'node:assert/strict';
import { SheetModel, Working } from '../../assets/participants_sheet/sheet_model.js';
import { parseClipboardText } from '../../assets/participants_sheet/tsv.js';
import {
    CHOOSE,
    NEW_TEAM,
    SKIP,
    buildSoloPasteAction,
    buildTeamPasteAction,
    chosenResultChanges,
    closeNames,
    headerWordSet,
    looksLikeHeader,
    looksLikeResults,
    matchPerson,
    newTeamsOfResults,
    notANameKind,
    planResultsPaste,
    planSoloPaste,
    planTeamPaste,
    snapshotModel,
    teamOfExactly,
    teamsNamed,
    undecidedChoices,
    undecidedSoloChoices,
} from '../../assets/participants_sheet/round_paste.js';
import { roundEntries } from '../../assets/participants_sheet/round/round_common.js';
import { ROUND_PAIRS, ROUND_SOLO, ROUND_TEAMS, ids, person, place, smallState, team } from '../participants-sheet-core/fixture.mjs';

const HEADER_WORDS = headerWordSet(['Table', 'Pair name', 'Member 1', 'Member 2', 'Result', 'team, name, member, partner, player, result, time']);

const rows = (columns) => ({ columns });
const NAME_MEMBERS = rows(['name', 'member', 'member']);

function model(overrides = {}) {
    return new SheetModel(smallState(overrides), { now: () => 0 });
}

/** The places of a round as `person → place value` (what a round looks like after applying groups). */
function placesOf(state, roundId, people) {
    return Object.fromEntries(people.map((id) => [id, state.placeValue(id, roundId)]));
}

function applyAll(base, groups) {
    const working = new Working(base);

    for (const group of groups) {
        working.applyGroup(group.changes, 0);
    }

    return working;
}

export default function (test) {
    test('people by name: exact first, then the ParticipantNameKey fold; several = ambiguous, in-round people first', () => {
        const m = model();
        assert.deepEqual(matchPerson(m, '  Kim   Example '), { status: 'one', ids: ['p-kim'] });
        assert.deepEqual(matchPerson(m, 'kím EXAMPLE'), { status: 'one', ids: ['p-kim'] });
        assert.deepEqual(matchPerson(m, 'Nobody Here'), { status: 'none', ids: [] });
        // Removed people are never matched (they cannot be placed)
        assert.deepEqual(matchPerson(m, 'Ola Fictive'), { status: 'none', ids: [] });

        const twins = model({ people: [...smallState().people, person('p-jo2', 'Jo Do')] });
        assert.deepEqual(matchPerson(twins, 'jo do', ROUND_PAIRS), { status: 'several', ids: ['p-jo', 'p-jo2'] });
    });

    test('teams by name (case-insensitive) and by exactly their members', () => {
        const m = model();
        assert.deepEqual(teamsNamed(m, ROUND_PAIRS, 'CORNERS').sort(), ['t-corners', 't-corners2']);
        assert.deepEqual(teamsNamed(m, ROUND_TEAMS, 'edge'), ['t-edge']);
        assert.equal(teamOfExactly(m, ROUND_PAIRS, ['p-pat', 'p-kim']), 't-corners');
        assert.equal(teamOfExactly(m, ROUND_PAIRS, ['p-kim']), null);
        assert.equal(teamOfExactly(m, ROUND_PAIRS, ['p-jo']), null);
    });

    test('a row with a new name and known people → a new pair; people move from where they are', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Night Owls', 'Jo Do', 'Ana Example']], NAME_MEMBERS);
        assert.equal(plan.lines[0].match, NEW_TEAM);
        assert.equal(plan.counts.newTeams, 1);

        const action = buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() });
        assert.equal(action.groups.length, 1);
        const teamId = action.teams.t0;
        assert.deepEqual(action.groups[0].changes, [
            { op: 'newTeam', id: teamId, round: ROUND_PAIRS, name: 'Night Owls' },
            { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: `team:${teamId}` },
            { op: 'place', participant: 'p-ana', round: ROUND_PAIRS, from: 'out', to: `team:${teamId}` },
        ]);
        assert.deepEqual(action.lineIds, ['t0']);
    });

    test('a name matching one pair → that pair: its members become exactly the pasted ones, the rest go to the tray', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_TEAMS, [['edge', 'Tia One', 'Tom Two', 'Ana Example']], rows(['name', 'member', 'member', 'member']));
        assert.equal(plan.lines[0].match, 'existing');
        assert.equal(plan.lines[0].teamId, 't-edge');

        const action = buildTeamPasteAction(m, ROUND_TEAMS, plan, {}, { newId: ids() });
        const after = applyAll(m.scratch(), action.groups);
        assert.deepEqual(placesOf(after, ROUND_TEAMS, ['p-t1', 'p-t2', 'p-t3', 'p-ana']), { 'p-t1': 'team:t-edge', 'p-t2': 'team:t-edge', 'p-t3': 'in', 'p-ana': 'team:t-edge' });
        // Named "edge" in the paste, "Edge" stored: the name stays as it is
        assert.equal(action.groups[0].changes.some((change) => change.op === 'renameTeam'), false);
    });

    test('a name of several pairs is ambiguous - unless the pasted people are exactly one of them', () => {
        const m = model();
        const ambiguous = planTeamPaste(m, ROUND_PAIRS, [['Corners', 'Jo Do', 'Ana Example']], NAME_MEMBERS);
        assert.equal(ambiguous.lines[0].match, 'ambiguous');
        assert.deepEqual(ambiguous.lines[0].candidates.sort(), ['t-corners', 't-corners2']);
        assert.equal(ambiguous.counts.ambiguousTeams, 1);

        // The organiser picks "a new pair"
        const asNew = buildTeamPasteAction(m, ROUND_PAIRS, ambiguous, { choices: { t0: NEW_TEAM } }, { newId: ids() });
        assert.equal(asNew.groups[0].changes[0].op, 'newTeam');
        // … or the second Corners
        const second = buildTeamPasteAction(m, ROUND_PAIRS, ambiguous, { choices: { t0: 't-corners2' } }, { newId: ids() });
        const after = applyAll(m.scratch(), second.groups);
        assert.deepEqual(placesOf(after, ROUND_PAIRS, ['p-jo', 'p-ana', 'p-lee', 'p-max']), { 'p-jo': 'team:t-corners2', 'p-ana': 'team:t-corners2', 'p-lee': 'in', 'p-max': 'in' });

        const byMembers = planTeamPaste(m, ROUND_PAIRS, [['corners', 'Max Demo', 'Lee Mock']], NAME_MEMBERS);
        assert.equal(byMembers.lines[0].match, 'existing');
        assert.equal(byMembers.lines[0].teamId, 't-corners2');
    });

    test('a row without a name whose people form exactly one pair → that pair, no duplicate', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_PAIRS, [['', 'Pat Sample', 'Kim Example']], NAME_MEMBERS);
        assert.equal(plan.lines[0].match, 'existing');
        assert.equal(plan.lines[0].teamId, 't-corners');
        assert.equal(buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() }).groups.length, 0);

        // A new name with the people of exactly one pair: that pair, renamed
        const renamed = planTeamPaste(m, ROUND_PAIRS, [['Pinecones', 'Pat Sample', 'Kim Example']], NAME_MEMBERS);
        assert.equal(renamed.lines[0].byMembers, true);
        assert.deepEqual(buildTeamPasteAction(m, ROUND_PAIRS, renamed, {}, { newId: ids() }).groups[0].changes, [{ op: 'renameTeam', team: 't-corners', from: 'Corners', to: 'Pinecones' }]);
    });

    test('several people of one name: the preview asks; left out = the cell stays out of the paste', () => {
        const m = model({ people: [...smallState().people, person('p-jo2', 'Jo Do')] });
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Duo', 'Jo Do', 'Ana Example']], NAME_MEMBERS);
        assert.equal(plan.lines[0].members[0].status, 'several');
        assert.equal(plan.counts.ambiguousPeople, 1);

        const picked = buildTeamPasteAction(m, ROUND_PAIRS, plan, { choices: { 'p0:1': 'p-jo2' } }, { newId: ids() });
        assert.ok(picked.groups[0].changes.some((change) => change.op === 'place' && change.participant === 'p-jo2'));
        const skipped = buildTeamPasteAction(m, ROUND_PAIRS, plan, { choices: { 'p0:1': SKIP } }, { newId: ids() });
        assert.equal(skipped.groups[0].changes.filter((change) => change.op === 'place').length, 1);
    });

    test('names not on the list: one new person per name (D9, ticked by default), unticked = left out', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_PAIRS, [['A', 'Zed New', 'Jo Do'], ['B', 'zed  new', 'Ana Example']], NAME_MEMBERS);
        assert.equal(plan.newNames.length, 1);
        assert.equal(plan.counts.newPeople, 1);

        const action = buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() });
        const created = action.groups.flatMap((group) => group.changes).filter((change) => change.op === 'newParticipant');
        assert.equal(created.length, 1);
        assert.equal(created[0].name, 'Zed New');
        // Zed is in the first pair, then moves to the second one (the later line counts) - one person, not two
        const after = applyAll(m.scratch(), action.groups);
        assert.equal(after.placeValue(created[0].id, ROUND_PAIRS), `team:${action.teams.t1}`);
        assert.deepEqual(plan.lines[1].twice, []);

        const unticked = buildTeamPasteAction(m, ROUND_PAIRS, plan, { ticks: { [`n${plan.newNames[0].key}`]: false } }, { newId: ids() });
        assert.equal(unticked.groups.flatMap((group) => group.changes).some((change) => change.op === 'newParticipant'), false);
    });

    test('a person of another pair moves - counted, the later line wins when listed twice', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Owls', 'Kim Example', 'Jo Do'], ['Bats', 'Kim Example', 'Ana Example']], NAME_MEMBERS);
        assert.equal(plan.counts.moves, 2);
        assert.deepEqual(plan.lines[1].twice, ['p-kim']);

        const action = buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() });
        // The second line's from = where the first line put Kim (a scratch state row after row)
        const second = action.groups[1].changes.find((change) => change.participant === 'p-kim');
        assert.equal(second.from, `team:${action.teams.t0}`);
    });

    test('positional: the covered columns of the rows it lands on - rename, replace a member, an empty cell clears', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Pinecones', '', 'Jo Do'], ['', 'Ana Example']], {
            columns: ['name', 'member', 'member'],
            slots: [null, 0, 1],
            targets: ['t-corners', 't-corners2'],
            slotsOf: (teamId) => m.membersOf(teamId).map((p) => p.id),
        });
        assert.equal(plan.lines.every((line) => line.positional), true);

        const action = buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids(), slotsOf: (teamId) => m.membersOf(teamId).map((p) => p.id) });
        const after = applyAll(m.scratch(), action.groups);
        assert.equal(after.team('t-corners').name, 'Pinecones');
        // Corners: Kim (slot 0) cleared, Pat (slot 1) replaced by Jo
        assert.deepEqual(placesOf(after, ROUND_PAIRS, ['p-kim', 'p-pat', 'p-jo']), { 'p-kim': 'in', 'p-pat': 'in', 'p-jo': 'team:t-corners' });
        // corners2: its name cleared (an empty name cell), Lee (slot 0) replaced by Ana
        assert.equal(after.team('t-corners2').name, null);
        assert.deepEqual(placesOf(after, ROUND_PAIRS, ['p-lee', 'p-max', 'p-ana']), { 'p-lee': 'in', 'p-max': 'team:t-corners2', 'p-ana': 'team:t-corners2' });
    });

    test('rows past the end of a positional paste are matched like rows of teams', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Pinecones', 'Kim Example'], ['Edge Two', 'Jo Do']], { columns: ['name', 'member'], slots: [null, 0], targets: ['t-corners', null] });
        assert.equal(plan.lines[0].positional, true);
        assert.equal(plan.lines[1].positional, false);
        assert.equal(plan.lines[1].match, NEW_TEAM);
    });

    test('every pasted row is its own group and the action undoes exactly', () => {
        const m = model();
        const block = parseClipboardText('Owls\tJo Do\tAna Example\r\ncorners\tMax Demo\tKim Example\r\nNew Pair\tZed New\tTia One\r\n');
        const plan = planTeamPaste(m, ROUND_PAIRS, block, NAME_MEMBERS);
        const action = buildTeamPasteAction(m, ROUND_PAIRS, plan, { choices: { t1: 't-corners2' } }, { newId: ids() });
        assert.equal(action.groups.length, 3);

        const people = ['p-jo', 'p-ana', 'p-max', 'p-lee', 'p-kim', 'p-pat', 'p-t1'];
        const before = placesOf(m.scratch(), ROUND_PAIRS, people);
        const forward = applyAll(m.scratch(), action.groups);
        assert.notDeepEqual(placesOf(forward, ROUND_PAIRS, people), before);
        const back = applyAll(forward, action.inverse);
        assert.deepEqual(placesOf(back, ROUND_PAIRS, people), before);
        assert.deepEqual([...back.teams.keys()].sort(), [...m.scratch().teams.keys()].sort());
    });

    test('a removed person in a row is refused in the browser, the other rows go on', () => {
        const m = model();
        // Ola is removed: as a typed name she is "not on the list" - a new person; placed by id she is refused
        const plan = planTeamPaste(m, ROUND_PAIRS, [['X', 'Jo Do'], ['Y', 'Ana Example']], rows(['name', 'member']));
        plan.lines[0].members[0] = { ...plan.lines[0].members[0], ids: ['p-ola'] };
        const action = buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() });
        assert.deepEqual(action.errors.map((error) => error.reason), ['participant_removed']);
        assert.deepEqual(action.refusedLines.map((refusal) => refusal.lineId), ['t0']);
        assert.deepEqual(action.lineIds, ['t1']);
    });

    test('a block of name ⇥ result looks like results; name ⇥ person does not', () => {
        assert.equal(looksLikeResults([['Corners', '1:23:45'], ['Edge', 'DNS'], ['Flat', '']]), true);
        assert.equal(looksLikeResults([['Corners', 'Kim Example']]), false);
        assert.equal(looksLikeResults([['Corners', '']]), false);
        assert.equal(looksLikeResults([['Corners', '1:00:00', 'x']]), false);
        // One unreadable value among results stays a results paste (the preview lists it) - a person's name never
        const m = model();
        assert.equal(looksLikeResults([['Edge', '1:00:00'], ['Flat', 'soon'], ['Owls', '59:00']], {}, m), true);
        assert.equal(looksLikeResults([['Edge', '1:00:00'], ['Flat', 'Jo Do']], {}, m), false);
        assert.equal(looksLikeResults([['Edge', 'soon'], ['Flat', 'later'], ['Owls', '59:00']], {}, m), false);
    });

    test('results by name: only entries of this round; unknown, not-in-round, unreadable listed; replaces shown', () => {
        const m = model();
        const entries = roundEntries(m, ROUND_TEAMS, null);
        const block = [['Edge', '1:10:00'], ['flat', '1:23:20'], ['Tia One', '58:00'], ['Kim Example', '1:00:00'], ['Nobody', '1:00:00'], ['Edge', 'soon']];
        const plan = planResultsPaste(m, ROUND_TEAMS, entries, block, { mode: 'names' });
        const byIndex = Object.fromEntries(plan.lines.map((line) => [line.index, line]));

        // Edge again on line 3 (through its member Tia): the later line counts; an unreadable result is listed
        assert.equal(byIndex[0].status, 'skip');
        assert.equal(byIndex[0].reason, 'listed_again');
        assert.equal(byIndex[5].status, 'error');
        assert.equal(byIndex[5].reason, 'unreadable');
        // Flat already has 1:23:20 → saved already
        assert.equal(byIndex[1].status, 'same');
        // A member's name finds the team
        assert.equal(byIndex[2].ref, 'team:t-edge');
        assert.equal(byIndex[2].status, 'change');
        assert.equal(byIndex[3].reason, 'not_in_round');
        // A pair/team round: no pair, team or person of the round has the name (the solo wording is "nobody of the event")
        assert.equal(byIndex[4].reason, 'unknown_entry');
        assert.deepEqual(plan.changes, [{ roundId: ROUND_TEAMS, ref: 'team:t-edge', field: 'result', from: null, to: { seconds: 3480 } }]);
    });

    test('results replace what the page shows (from), ambiguous names are picked in the preview', () => {
        const state = smallState();
        state.teams = state.teams.map((t) => (t.id === 't-corners2' ? { ...t, result: { seconds: 4000 } } : t));
        const m = new SheetModel(state, { now: () => 0 });
        const entries = roundEntries(m, ROUND_PAIRS, null);
        const plan = planResultsPaste(m, ROUND_PAIRS, entries, [['Corners', '1:00:00'], ['Max Demo', '1:05:00']], { mode: 'names' });
        assert.equal(plan.lines[0].status, 'ambiguous');
        assert.equal(plan.lines[1].status, 'change');
        assert.deepEqual(plan.lines[1].from, { seconds: 4000 });

        const chosen = chosenResultChanges(ROUND_PAIRS, plan, entries, { choices: { r0: 'team:t-corners' } });
        assert.deepEqual(chosen.map((change) => [change.ref, change.from, change.to]), [
            ['team:t-corners', null, { seconds: 3600 }],
            ['team:t-corners2', { seconds: 4000 }, { seconds: 3900 }],
        ]);
    });

    test('a solo round: names of its people; waitlisted people and people not in it are left out', () => {
        const state = smallState();
        state.people = state.people.map((p) => (p.id === 'p-pat' ? { ...p, registration: { status: 'waitlisted' } } : p));
        state.places.push(place('e-jo-solo', 'p-jo', ROUND_SOLO));
        const m = new SheetModel(state, { now: () => 0 });
        const entries = roundEntries(m, ROUND_SOLO, null);
        assert.deepEqual(entries.map((entry) => entry.ref).sort(), ['participant_round:e-jo-solo', 'participant_round:e-kim-solo']);

        const plan = planResultsPaste(m, ROUND_SOLO, entries, [['Jo Do', '58:12'], ['Pat Sample', '1:00:00'], ['Ana Example', '1:00:00'], ['kim example', '-']], { mode: 'names' });
        assert.deepEqual(plan.lines.map((line) => line.reason ?? line.status), ['change', 'waitlisted', 'not_in_round', 'change']);
        assert.deepEqual(plan.changes.map((change) => [change.ref, change.from, change.to]), [
            ['participant_round:e-jo-solo', null, { seconds: 3492 }],
            ['participant_round:e-kim-solo', { seconds: 3600 }, { didNotStart: true }],
        ]);
    });

    test('a results column onto the Result cells: positional, rows below the list left out', () => {
        const m = model();
        const entries = roundEntries(m, ROUND_TEAMS, null);
        const plan = planResultsPaste(m, ROUND_TEAMS, entries, [['1:01:01'], ['DNS'], ['59:59']], { mode: 'positional', targets: ['team:t-edge', 'team:t-flat', null] });
        assert.deepEqual(plan.lines.map((line) => line.status), ['change', 'change', 'skip']);
        assert.equal(plan.lines[2].reason, 'below_list');
        assert.deepEqual(plan.changes.map((change) => change.to), [{ seconds: 3661 }, { didNotStart: true }]);
        assert.deepEqual(plan.changes[1].from, { seconds: 5000 });
    });

    test('team names of new pairs equal to existing ones are counted as shared', () => {
        const m = new SheetModel(smallState({ teams: [...smallState().teams, team('t-solo-name', ROUND_TEAMS, 'Owls')] }), { now: () => 0 });
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Edge', 'Jo Do'], ['Owls', 'Ana Example'], ['Owls', 'Tia One']], rows(['name', 'member']));
        // Edge / Owls are names of the teams round, not of this one: new pairs; the two new Owls share a name
        assert.deepEqual(plan.lines.map((line) => line.match), [NEW_TEAM, NEW_TEAM, NEW_TEAM]);
        assert.equal(plan.counts.sharedNames, 2);
    });

    // ---------------------------------------------------------------- review D, round 2 (reproductions s1, s2)

    test('D-M1 (s1): a name of several pairs is never picked for the organiser - "Choose…", the pair holding a pasted person first', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Corners', 'Lee Mock', 'Jo Do']], NAME_MEMBERS);
        assert.equal(plan.lines[0].match, 'ambiguous');
        // Lee is in the second "corners" - it is listed first
        assert.deepEqual(plan.lines[0].candidates, ['t-corners2', 't-corners']);
        assert.deepEqual(undecidedChoices(plan, { choices: { t0: CHOOSE } }), ['t0']);

        // Nothing chosen: the row is not built (before: Lee moved into the first Corners, Kim and Pat out)
        const undecided = buildTeamPasteAction(m, ROUND_PAIRS, plan, { choices: { t0: CHOOSE } }, { newId: ids() });
        assert.deepEqual(undecided.groups, []);
        assert.deepEqual(undecided.undecided, ['t0']);
        assert.deepEqual(buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() }).groups, []);

        const chosen = buildTeamPasteAction(m, ROUND_PAIRS, plan, { choices: { t0: 't-corners2' } }, { newId: ids() });
        assert.equal(chosen.groups.length, 1);
        assert.deepEqual(undecidedChoices(plan, { choices: { t0: 't-corners2' } }), []);
    });

    test('D-M1 (s1): a person of several people is never picked for the organiser either', () => {
        const twins = model({ people: [...smallState().people, person('p-jo2', 'Jo Do', { country: 'de' })] });
        const plan = planTeamPaste(twins, ROUND_PAIRS, [['Owls', 'Jo Do', 'Ana Example']], NAME_MEMBERS);
        assert.equal(plan.lines[0].members[0].status, 'several');
        assert.deepEqual(undecidedChoices(plan, {}), ['p0:1']);
        assert.deepEqual(buildTeamPasteAction(twins, ROUND_PAIRS, plan, { choices: { 'p0:1': CHOOSE } }, { newId: ids() }).groups, []);
        const picked = buildTeamPasteAction(twins, ROUND_PAIRS, plan, { choices: { 'p0:1': 'p-jo2' } }, { newId: ids() });
        assert.ok(picked.groups[0].changes.some((change) => change.op === 'place' && change.participant === 'p-jo2'));
    });

    test('D-M1 (s2): the round decides between namesakes when exactly one combination is a pair of it', () => {
        // Corners = Kim + Jo; a second "Jo Do" of the event is in no round
        const base = smallState();
        const m = new SheetModel(smallState({
            people: [...base.people, person('p-jo2', 'Jo Do', { country: 'de' })],
            places: base.places.filter((p) => p.id !== 'e-pat-pairs' && p.id !== 'e-jo-pairs').concat([place('e-jo-pairs', 'p-jo', ROUND_PAIRS, 't-corners')]),
        }), { now: () => 0 });

        for (const row of [['Corners', 'Kim Example', 'Jo Do'], ['', 'Kim Example', 'Jo Do']]) {
            const plan = planTeamPaste(m, ROUND_PAIRS, [row], NAME_MEMBERS);
            assert.equal(plan.lines[0].match, 'existing', row.join('|'));
            assert.equal(plan.lines[0].teamId, 't-corners');
            assert.deepEqual(plan.lines[0].members[1].ids, ['p-jo']);
            assert.equal(plan.lines[0].members[1].resolved, true);
            // Already exactly that pair: nothing to change (before: the other Jo replaced her)
            assert.deepEqual(buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() }).groups, []);
        }

        // A third person with the pair: the namesake already in the pair is listed first, still to choose
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Corners', 'Kim Example', 'Jo Do', 'Ana Example']], rows(['name', 'member', 'member', 'member']));
        assert.equal(plan.lines[0].members[1].status, 'several');
        assert.deepEqual(plan.lines[0].members[1].ids, ['p-jo', 'p-jo2']);
    });

    test('teams by name fold accents like the round\'s "same name" rule', () => {
        const m = model({ teams: [...smallState().teams, team('t-cafe', ROUND_PAIRS, 'Café')] });
        assert.deepEqual(teamsNamed(m, ROUND_PAIRS, 'cafe'), ['t-cafe']);
        assert.deepEqual(teamsNamed(m, ROUND_PAIRS, 'CAFÉ'), ['t-cafe']);
    });

    test('D-m8: a first row of column headings is left out ("Pair name ⇥ Member 1 ⇥ Member 2")', () => {
        const m = model();
        assert.equal(looksLikeHeader(['Pair name', 'Member 1', 'Member 2'], HEADER_WORDS), true);
        assert.equal(looksLikeHeader(['Team', 'Partner'], HEADER_WORDS), true);
        assert.equal(looksLikeHeader(['Owls', 'Jo Do'], HEADER_WORDS), false);

        const plan = planTeamPaste(m, ROUND_PAIRS, [['Pair name', 'Member 1', 'Member 2'], ['Owls', 'Jo Do', 'Ana Example']], { columns: ['name', 'member', 'member'], headerWords: HEADER_WORDS });
        assert.deepEqual(plan.header, { id: 't0', index: 0, text: 'Pair name · Member 1 · Member 2' });
        assert.deepEqual(plan.lines.map((line) => line.id), ['t1']);
        assert.equal(plan.newNames.length, 0);
        // Only the first row can be headings, and never one naming a pair of the round
        const named = planTeamPaste(m, ROUND_PAIRS, [['Corners', 'Kim Example', 'Pat Sample']], { columns: ['name', 'member', 'member'], headerWords: headerWordSet(['corners, kim, example, pat, sample']) });
        assert.equal(named.header, null);
    });

    test('BR8: a partner column (Kim ⇥ Pat, then Pat ⇥ Kim) is one pair, counted once, no "on another line" warning', () => {
        const m = model();
        const plan = planTeamPaste(m, ROUND_PAIRS, [['Jo Do', 'Ana Example'], ['Ana Example', 'Jo Do'], ['Zed New', 'Lee Mock'], ['Lee Mock', 'zed new']], rows(['member', 'member']));
        assert.deepEqual(plan.lines.map((line) => line.id), ['t0', 't2']);
        assert.deepEqual(plan.duplicates.map((duplicate) => [duplicate.id, duplicate.of]), [['t1', 't0'], ['t3', 't2']]);
        assert.deepEqual(plan.lines.map((line) => line.twice), [[], []]);
        assert.equal(plan.counts.newTeams, 2);
        assert.equal(plan.counts.newPeople, 1);

        const action = buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() });
        assert.equal(action.groups.length, 2);
        assert.equal(action.groups.flatMap((group) => group.changes).filter((change) => change.op === 'newParticipant').length, 1);

        // A later row naming the pair gives it the name; different names are two pairs, the warning stays
        const named = planTeamPaste(m, ROUND_PAIRS, [['', 'Jo Do', 'Ana Example'], ['Owls', 'Ana Example', 'Jo Do']], NAME_MEMBERS);
        assert.equal(named.lines.length, 1);
        assert.equal(named.lines[0].name, 'Owls');
        const different = planTeamPaste(m, ROUND_PAIRS, [['Owls', 'Jo Do', 'Ana Example'], ['Bats', 'Ana Example', 'Jo Do']], NAME_MEMBERS);
        assert.equal(different.lines.length, 2);
        assert.deepEqual(different.lines[1].twice.sort(), ['p-ana', 'p-jo']);
    });

    test('BR9: a new name is not pre-ticked when a close name exists or it looks like a code, a number or an e-mail', () => {
        const m = model();
        assert.deepEqual(closeNames(m, 'Kim Exampel').map((p) => p.name), ['Kim Example']);
        assert.deepEqual(closeNames(m, 'Example Kim').map((p) => p.name), ['Kim Example']);
        assert.deepEqual(closeNames(m, 'Kim A. Example').map((p) => p.name), ['Kim Example']);
        assert.deepEqual(closeNames(m, 'Zed New'), []);
        assert.equal(notANameKind('kim@example.test'), 'email');
        assert.equal(notANameKind('1:23:45'), 'number');
        assert.equal(notANameKind('#12'), 'number');
        assert.equal(notANameKind('US', new Set(['us'])), 'country');
        assert.equal(notANameKind('ca', new Set(['us', 'ca'])), 'country');
        assert.equal(notANameKind('GER'), 'country');
        assert.equal(notANameKind('Jo'), null);
        assert.equal(notANameKind('Zed New'), null);

        const plan = planTeamPaste(m, ROUND_PAIRS, [['A', 'Kim Exampel', 'Zed New'], ['B', 'US', 'kim@example.test']], { columns: ['name', 'member', 'member'], countryCodes: new Set(['us']) });
        const byName = Object.fromEntries(plan.newNames.map((entry) => [entry.name, entry]));
        assert.equal(byName['Kim Exampel'].tick, false);
        assert.deepEqual(byName['Kim Exampel'].close.map((p) => p.id), ['p-kim']);
        assert.equal(byName['Zed New'].tick, true);
        assert.equal(byName.US.suspicious, 'country');
        assert.equal(byName['kim@example.test'].suspicious, 'email');

        // Unticked by default = left out unless the organiser ticks it
        const action = buildTeamPasteAction(m, ROUND_PAIRS, plan, {}, { newId: ids() });
        assert.deepEqual(action.groups.flatMap((group) => group.changes).filter((change) => change.op === 'newParticipant').map((change) => change.name), ['Zed New']);
        const ticked = buildTeamPasteAction(m, ROUND_PAIRS, plan, { ticks: { [`n${byName['Kim Exampel'].key}`]: true } }, { newId: ids() });
        assert.deepEqual(ticked.groups.flatMap((group) => group.changes).filter((change) => change.op === 'newParticipant').map((change) => change.name), ['Kim Exampel', 'Zed New']);
    });

    test('D-m4: a paste is built on the page as it was when pasted - a live change meanwhile is a conflict, not overwritten', () => {
        const m = model();
        const snapshot = snapshotModel(m);
        const plan = planTeamPaste(snapshot, ROUND_PAIRS, [['Owls', 'Kim Example', 'Jo Do']], NAME_MEMBERS);
        // Meanwhile another organiser puts Kim into the second "corners"
        m.applyLocal('g-other', [{ op: 'place', participant: 'p-kim', round: ROUND_PAIRS, from: 'team:t-corners', to: 'team:t-corners2' }]);

        const action = buildTeamPasteAction(snapshot, ROUND_PAIRS, plan, {}, { newId: ids() });
        const kim = action.groups[0].changes.find((change) => change.participant === 'p-kim');
        assert.equal(kim.from, 'team:t-corners');
        assert.equal(snapshot.placeValue('p-kim', ROUND_PAIRS), 'team:t-corners');
        assert.equal(m.placeValue('p-kim', ROUND_PAIRS), 'team:t-corners2');
    });

    test('BR3: a column of names into a solo round - in, already in, namesakes to choose, new people, removed, listed twice', () => {
        const state = smallState({ people: [...smallState().people, person('p-jo2', 'Jo Do', { country: 'de' })] });
        const m = new SheetModel(state, { now: () => 0 });
        const block = [['Name'], ['Ana Example'], ['Kim Example'], ['Jo Do'], ['Zed New'], ['Kim Exampel'], ['Ola Fictive'], ['ana example'], ['US']];
        const plan = planSoloPaste(m, ROUND_SOLO, block, { headerWords: HEADER_WORDS, countryCodes: new Set(['us']) });
        assert.deepEqual(plan.header, { id: 's0', index: 0, text: 'Name' });
        assert.deepEqual(plan.lines.map((line) => [line.name, line.status]), [
            ['Ana Example', 'one'],
            ['Kim Example', 'in'],
            ['Jo Do', 'several'],
            ['Zed New', 'none'],
            ['Kim Exampel', 'none'],
            ['Ola Fictive', 'removed'],
            ['ana example', 'duplicate'],
            ['US', 'none'],
        ]);
        assert.deepEqual(plan.counts, { into: 1, already: 1, newPeople: 3, ambiguous: 1, skipped: 2 });
        assert.equal(plan.ignoredColumns, false);
        assert.deepEqual(undecidedSoloChoices(plan, {}), ['s3']);
        assert.deepEqual(plan.newNames.map((entry) => [entry.name, entry.tick]), [['Zed New', true], ['Kim Exampel', false], ['US', false]]);

        const action = buildSoloPasteAction(m, ROUND_SOLO, plan, { choices: { s3: 'p-jo2' } }, { newId: ids() });
        assert.deepEqual(action.groups.map((group) => group.changes.map((change) => change.op === 'place' ? `${change.participant}:${change.from}→${change.to}` : `${change.op}:${change.name}`)), [
            ['p-ana:out→in'],
            ['p-jo2:out→in'],
            ['newParticipant:Zed New', 'id1:out→in'],
        ]);
        assert.equal(action.label.key, 'paste');
        // Undone in one step: Zed removed, Ana and the second Jo out again
        assert.equal(action.inverse.length, 3);
        // Still to choose: the namesake's line waits
        assert.deepEqual(buildSoloPasteAction(m, ROUND_SOLO, plan, {}, { newId: ids() }).undecided, ['s3']);
        assert.equal(planSoloPaste(m, ROUND_SOLO, [['Zed New', 'US']], {}).ignoredColumns, true);
    });

    test('D-m3: a blank result in a name ⇥ result paste is left out - a paste never clears a saved result', () => {
        const m = model();
        const entries = roundEntries(m, ROUND_SOLO, null);
        const plan = planResultsPaste(m, ROUND_SOLO, entries, [['Kim Example', ''], ['Pat Sample', '58:12']], { mode: 'names' });
        assert.deepEqual(plan.lines.map((line) => [line.name, line.status, line.reason]), [['Kim Example', 'skip', 'empty'], ['Pat Sample', 'change', null]]);
        assert.deepEqual(plan.changes.map((change) => change.ref), ['participant_round:e-pat-solo']);
        const column = planResultsPaste(m, ROUND_SOLO, entries, [[''], ['58:12']], { mode: 'positional', targets: ['participant_round:e-kim-solo', 'participant_round:e-pat-solo'] });
        assert.deepEqual(column.lines.map((line) => line.reason ?? line.status), ['empty', 'change']);
    });

    test('D-m7: rows that cannot get a result say why - on the waitlist, not saved yet, below the list', () => {
        const state = smallState({ competition: { ...smallState().competition, registrationManaged: true } });
        state.people = state.people.map((p) => (p.id === 'p-pat' ? { ...p, registration: { status: 'waitlisted' } } : p));
        state.places.push({ ...place('local:1', 'p-jo', ROUND_SOLO), local: true });
        const m = new SheetModel(state, { now: () => 0 });
        const entries = roundEntries(m, ROUND_SOLO, null);
        const positional = planResultsPaste(m, ROUND_SOLO, entries, [['1:00:01'], ['1:00:02'], ['1:00:03'], ['1:00:04']], {
            mode: 'positional',
            targets: ['participant_round:e-kim-solo', { skip: 'waitlisted' }, { skip: 'no_entry' }, null],
        });
        assert.deepEqual(positional.lines.map((line) => line.reason ?? line.status), ['change', 'waitlisted', 'no_entry', 'below_list']);

        const names = planResultsPaste(m, ROUND_SOLO, entries, [['Pat Sample', '1:00:00'], ['Jo Do', '1:00:00'], ['Ana Example', '1:00:00'], ['Nobody', '1:00:00']], { mode: 'names' });
        assert.deepEqual(names.lines.map((line) => line.reason), ['waitlisted', 'no_entry', 'not_in_round', 'unknown_name']);
    });

    test('BR5: table numbers (when every number of the column is a table of the round) and #code of a linked player', () => {
        const state = smallState();
        state.places = state.places.map((p) => (p.id === 'e-kim-solo' ? { ...p, table: 12 } : (p.id === 'e-pat-solo' ? { ...p, table: 3 } : p)));
        const m = new SheetModel(state, { now: () => 0 });
        const entries = roundEntries(m, ROUND_SOLO, null);
        assert.deepEqual(entries.find((entry) => entry.personId === 'p-kim').codes, ['kim01']);

        const byTable = planResultsPaste(m, ROUND_SOLO, entries, [['12', '1:23:45'], ['3', 'DNS']], { mode: 'names' });
        assert.equal(byTable.byTable, true);
        assert.deepEqual(byTable.changes.map((change) => [change.ref, change.to]), [['participant_round:e-kim-solo', { seconds: 5025 }], ['participant_round:e-pat-solo', { didNotStart: true }]]);

        // One number that is no table of the round: an id column, never read as tables
        const ids_ = planResultsPaste(m, ROUND_SOLO, entries, [['12', '1:23:45'], ['4711', 'DNS']], { mode: 'names' });
        assert.equal(ids_.byTable, false);
        assert.deepEqual(ids_.changes, []);
        // A round without table numbers never reads them
        assert.equal(planResultsPaste(m, ROUND_SOLO, entries, [['12', '1:23:45']], { mode: 'names', tables: false }).byTable, false);

        const byCode = planResultsPaste(m, ROUND_SOLO, entries, [['#KIM01', '58:12'], ['#nobody', '1:00:00']], { mode: 'names' });
        assert.deepEqual(byCode.lines.map((line) => line.reason ?? line.ref), ['participant_round:e-kim-solo', 'unknown_code']);
    });

    test('a results paste is not cut at 500 - the save queue sends the rest in further requests', () => {
        const people = [];
        const places = [];

        for (let i = 0; i < 620; i++) {
            people.push(person(`p${i}`, `Person ${i}`));
            places.push(place(`e${i}`, `p${i}`, ROUND_SOLO));
        }

        const m = new SheetModel(smallState({ people, places, teams: [] }), { now: () => 0 });
        const block = people.map((p) => [p.name, '1:00:00']);
        const plan = planResultsPaste(m, ROUND_SOLO, roundEntries(m, ROUND_SOLO, null), block, { mode: 'names' });
        assert.equal(plan.changes.length, 620);
    });

    test('a heading row of a results paste is left out', () => {
        const m = model();
        const plan = planResultsPaste(m, ROUND_TEAMS, roundEntries(m, ROUND_TEAMS, null), [['Team', 'Time'], ['Edge', '1:00:00']], { mode: 'names', headerWords: HEADER_WORDS });
        assert.equal(plan.header.text, 'Team · Time');
        assert.deepEqual(plan.lines.map((line) => line.name), ['Edge']);
    });

    test('BR16: in a round of team names only, unknown names of a results paste become new teams (ticked per name)', () => {
        const m = new SheetModel(smallState({ places: [], teams: [team('n1', ROUND_TEAMS, 'Owls')] }), { now: () => 0 });
        const entries = roundEntries(m, ROUND_TEAMS, null);
        const block = [['Owls', '1:00:00'], ['Bats', '1:10:00'], ['Ravens', 'soon'], ['bats', '1:05:00'], ['Kim Example', '59:00']];
        const plan = planResultsPaste(m, ROUND_TEAMS, entries, block, { mode: 'names', createTeams: true });
        assert.deepEqual(plan.lines.map((line) => [line.name, line.status, line.reason]), [
            ['Owls', 'change', null],
            ['Bats', 'skip', 'listed_again'],
            ['Ravens', 'error', 'unreadable'],
            ['bats', 'new_team', null],
            // A person of the event is not a team name: "not in this round"
            ['Kim Example', 'skip', 'not_in_round'],
        ]);
        assert.equal(plan.counts.newTeams, 1);

        const created = newTeamsOfResults(m, ROUND_TEAMS, plan, {}, { newId: ids('t') });
        assert.deepEqual(created.teams.groups.map((group) => group.changes), [[{ op: 'newTeam', id: 't1', round: ROUND_TEAMS, name: 'bats' }]]);
        assert.deepEqual(created.results, [{ roundId: ROUND_TEAMS, ref: 'team:t1', field: 'result', from: null, to: { seconds: 3900 } }]);
        // Unticked: no team, no result
        assert.deepEqual(newTeamsOfResults(m, ROUND_TEAMS, plan, { ticks: { r3: false } }), { teams: null, results: [] });
        // Without createTeams the name is listed as unknown
        assert.equal(planResultsPaste(m, ROUND_TEAMS, entries, [['Bats', '1:00:00']], { mode: 'names' }).lines[0].reason, 'unknown_entry');
    });
}
