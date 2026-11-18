// Divisions on the round tabs (shared-puzzles-and-divisions.md §3.3, §3.4): the browser twin of DivisionMatch
// (accents, case, whitespace, short label, id), the `division` change - `from` = what the cell showed, `to` = '' clears,
// a division the round does not use refused in the browser with the server's code -, its exact inverse, "Fill empty
// with …" (one group per entry), the model's optimistic overlay, and the Division column on the solo / pair tabs and
// the phone list - only for a round that uses divisions (every other tab keeps its columns).
import assert from 'node:assert/strict';
import { tick } from '../participants-sheet-core/dom.mjs';
import { ROUND_PAIRS, ROUND_SOLO, ROUND_TEAMS, smallState } from '../participants-sheet-core/fixture.mjs';
import { mount } from './views.mjs';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { buildAction, checkChange, invertGroups, setDivision, setDivisions, emptyDivisionTargets, wireGroups } from '../../assets/participants_sheet/sheet_changes.js';
import { matchDivision, divisionOptions, withoutDivision } from '../../assets/participants_sheet/sheet_divisions.js';
import { fillEmptyAction } from '../../assets/participants_sheet/round/round_divisions.js';

const REGULAR = { id: 'd-reg', name: 'Regular', shortName: null };
const SENIOR = { id: 'd-sen', name: 'Senior 55+', shortName: 'S' };
const JUNIOR = { id: 'd-jun', name: 'Junior U16', shortName: 'J' };

/** St. Paul's shape on the small event: Solo uses all three, Pairs Regular + Senior, Teams none. */
function divisionsState() {
    const state = smallState();
    state.rounds = state.rounds.map((round) => {
        if (round.id === ROUND_SOLO) {
            return { ...round, divisions: [REGULAR, SENIOR, JUNIOR] };
        }

        return round.id === ROUND_PAIRS ? { ...round, divisions: [REGULAR, SENIOR] } : round;
    });
    state.places = state.places.map((place) => {
        if (place.roundId !== ROUND_SOLO) {
            return place;
        }

        return { ...place, division: place.id === 'e-kim-solo' ? 'd-reg' : null };
    });
    state.teams = state.teams.map((team) => (team.roundId === ROUND_PAIRS ? { ...team, division: team.id === 't-corners' ? 'd-sen' : null } : team));

    return state;
}

function newIds() {
    let counter = 0;

    return () => `g${++counter}`;
}

export default function (test) {
    test('a typed division is matched like the server matches it: accents, case, spaces, short label, id', () => {
        const usable = [REGULAR, SENIOR];
        assert.equal(matchDivision('  seniór   55+ ', usable).division.id, 'd-sen');
        assert.equal(matchDivision('s', usable).division.id, 'd-sen');
        assert.equal(matchDivision('D-REG', usable).division.id, 'd-reg');
        assert.deepEqual(matchDivision('   ', usable), { none: true });
        assert.equal(matchDivision('Junior U16', usable, [REGULAR, SENIOR, JUNIOR]).reason, 'not_in_round');
        assert.equal(matchDivision('Senor', usable, [REGULAR, SENIOR, JUNIOR]).reason, 'unknown_division');
    });

    test('the editor lists the round\'s divisions and "No division"; the typed one is the exact option', () => {
        const model = new SheetModel(divisionsState(), { now: () => 0 });
        assert.deepEqual(divisionOptions(model, ROUND_PAIRS, '', 'none').map((option) => option.value), ['d-reg', 'd-sen', '']);
        assert.deepEqual(divisionOptions(model, ROUND_PAIRS, 'senior 55+', 'none').map((option) => [option.value, option.exact]), [['d-sen', true]]);
        assert.deepEqual(divisionOptions(model, ROUND_TEAMS, '', 'none').map((option) => option.value), ['']);
    });

    test('a solo entry\'s division: from = shown, to = the id; the same value is no change; the inverse swaps them', () => {
        const model = new SheetModel(divisionsState(), { now: () => 0 });
        const action = setDivision(model, ROUND_SOLO, { participant: 'p-pat' }, 'd-jun', { newId: newIds() });
        assert.deepEqual(wireGroups(action.groups), [{ id: 'g1', changes: [{ op: 'division', round: ROUND_SOLO, participant: 'p-pat', from: '', to: 'd-jun' }] }]);
        assert.deepEqual(wireGroups(action.inverse)[0].changes, [{ op: 'division', round: ROUND_SOLO, participant: 'p-pat', from: 'd-jun', to: '' }]);
        assert.equal(setDivision(model, ROUND_SOLO, { participant: 'p-kim' }, 'd-reg').groups.length, 0);

        // `from` = what the editor showed when it opened, never the model's value at commit time
        const stale = setDivision(model, ROUND_SOLO, { participant: 'p-kim' }, '', { from: 'd-sen', newId: newIds() });
        assert.deepEqual(stale.groups[0].changes[0], { op: 'division', round: ROUND_SOLO, participant: 'p-kim', from: 'd-sen', to: '' });
    });

    test('the browser refuses with the server\'s codes: not in the round, a pair\'s division by a member, nobody in the round', () => {
        const model = new SheetModel(divisionsState(), { now: () => 0 });
        assert.equal(checkChange({ op: 'division', round: ROUND_PAIRS, team: 't-corners', from: 'd-sen', to: 'd-jun' }, model), 'not_in_round');
        assert.equal(checkChange({ op: 'division', round: ROUND_PAIRS, participant: 'p-kim', from: '', to: 'd-reg' }, model), 'division_of_team');
        assert.equal(checkChange({ op: 'division', round: ROUND_SOLO, participant: 'p-ana', from: '', to: 'd-reg' }, model), 'division_not_entered');
        assert.equal(checkChange({ op: 'division', round: ROUND_SOLO, team: 't-corners', from: '', to: 'd-reg' }, model), 'not_a_team_round');
        assert.equal(checkChange({ op: 'division', round: ROUND_PAIRS, team: 't-corners', from: 'd-sen', to: '' }, model), null);

        const refused = buildAction(model, [[{ op: 'division', round: ROUND_PAIRS, team: 't-corners', from: 'd-sen', to: 'd-jun' }]]);
        assert.deepEqual(refused.errors.map((error) => error.reason), ['not_in_round']);
    });

    test('shown at once: a pair\'s division on the team, a solo entry\'s on the place - one delta', () => {
        const model = new SheetModel(divisionsState(), { now: () => 0 });
        const deltas = [];
        model.subscribe((delta) => deltas.push(delta));
        model.applyLocal('g1', [{ op: 'division', round: ROUND_PAIRS, team: 't-corners2', from: '', to: 'd-reg' }]);
        model.applyLocal('g2', [{ op: 'division', round: ROUND_SOLO, participant: 'p-kim', from: 'd-reg', to: '' }]);
        assert.equal(model.team('t-corners2').division, 'd-reg');
        assert.equal(model.place('p-kim', ROUND_SOLO).division, null);
        assert.ok(deltas[0].teams.has('t-corners2'));
        assert.ok(deltas[1].people.has('p-kim'));
        model.revert('g1');
        assert.equal(model.team('t-corners2').division, null);
    });

    test('"Fill empty with …": one group per entry without a division, the others untouched, one undo step', () => {
        const model = new SheetModel(divisionsState(), { now: () => 0 });
        assert.deepEqual(emptyDivisionTargets(model, ROUND_PAIRS), [{ team: 't-corners2' }, { team: 't-empty' }]);
        assert.equal(withoutDivision(model, ROUND_SOLO), 1);
        const action = fillEmptyAction(model, ROUND_PAIRS, 'd-reg', { newId: newIds() });
        assert.deepEqual(action.groups.map((group) => group.changes[0].team), ['t-corners2', 't-empty']);
        assert.equal(action.inverse.length, 2);
        assert.equal(setDivisions(model, ROUND_PAIRS, [], 'd-reg').groups.length, 0);
        assert.equal(invertGroups(action.groups, model, newIds()).length, 2);
    });

    test('the Division column only on a round that uses divisions - solo and pair tabs', async () => {
        const plain = await mount('solo', { roundId: ROUND_SOLO });
        assert.ok(!plain.view.columns.some((column) => column.key === 'division'));
        plain.view.destroy();

        const teams = await mount('team', { state: divisionsState(), roundId: ROUND_TEAMS });
        assert.ok(!teams.view.columns.some((column) => column.key === 'division'), 'Teams uses no division');
        teams.view.destroy();

        const solo = await mount('solo', { state: divisionsState(), roundId: ROUND_SOLO });
        assert.ok(solo.view.columns.some((column) => column.key === 'division'));
        assert.equal(solo.view.cell('p-kim', 'division').text, 'Regular');
        assert.equal(solo.view.cell('p-pat', 'division').text, '');
        assert.match(solo.root.textContent, /division_without 1/);
        solo.view.destroy();
    });

    test('solo tab: a typed division (accents, case) is saved with what the cell showed as `from`; an unknown one is the editor\'s error', async () => {
        const { view, changes } = await mount('solo', { state: divisionsState(), roundId: ROUND_SOLO });
        view.editStart('p-pat', 'division');
        view.commit('p-pat', 'division', { text: 'JUNIOR u16', option: null });
        assert.deepEqual(changes(), [{ op: 'division', round: ROUND_SOLO, participant: 'p-pat', from: '', to: 'd-jun' }]);

        view.editStart('p-kim', 'division');
        const refused = view.commit('p-kim', 'division', { text: 'Senor', option: null });
        assert.match(refused.error, /division_unknown/);
        assert.equal(changes().length, 1);
        view.destroy();
    });

    test('pair tab: the pair gets the picked division; a change meanwhile keeps the `from` the editor opened with', async () => {
        const { view, model, changes } = await mount('team', { state: divisionsState(), roundId: ROUND_PAIRS });
        assert.equal(view.cell('t-corners', 'division').text, 'Senior 55+');
        view.captureSeen('t-corners', 'division');
        // Somebody else set Regular meanwhile
        model.applyLocal('other', [{ op: 'division', round: ROUND_PAIRS, team: 't-corners', from: 'd-sen', to: 'd-reg' }]);
        await tick();
        const option = view.suggest('t-corners', 'division', '', {}).options.find((candidate) => candidate.value === '');
        view.commit('t-corners', 'division', { text: '', option }, { seen: 'd-sen' });
        assert.deepEqual(changes().at(-1), { op: 'division', round: ROUND_PAIRS, team: 't-corners', from: 'd-sen', to: '' });

        // A division of the event this round does not use: refused in words
        view.captureSeen('t-corners2', 'division');
        assert.match(view.commit('t-corners2', 'division', { text: 'junior u16', option: null }, {}).error, /division_not_in_round/);
        view.destroy();
    });

    test('phone list: the division line with "Change" only on a round that uses divisions', async () => {
        const plain = await mount('cards', { roundId: ROUND_PAIRS, phone: true });
        assert.equal(plain.root.querySelector('.sheet-card-division'), null);
        plain.view.destroy();

        const cards = await mount('cards', { state: divisionsState(), roundId: ROUND_PAIRS, phone: true });
        const line = cards.root.querySelector('[data-key="division:t-corners"]')?.closest('.sheet-card-division');
        assert.ok(line);
        assert.match(line.textContent, /Senior 55\+/);
        cards.view.destroy();
    });
}
