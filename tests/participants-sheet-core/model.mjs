// The model (assets/participants_sheet/sheet_model.js): indexes, derived facts, the optimistic overlay and merges.
import assert from 'node:assert/strict';
import { SheetModel, cleanName, cleanTeamName, cleanOptionalText, nameKey, parsePlace } from '../../assets/participants_sheet/sheet_model.js';
import { ROUND_PAIRS, ROUND_SOLO, ROUND_TEAMS, smallState, person, place, team } from './fixture.mjs';

const model = (state = smallState()) => new SheetModel(state, { now: () => Date.parse('2026-10-08T09:00:00Z') });
const idsOf = (people) => people.map((p) => p.id);

export default function (test) {
    test('names are cleaned exactly like the server (Unicode white space collapsed, ASCII trim)', () => {
        assert.equal(cleanName('  Kim  Example 　 Jr. '), 'Kim Example Jr.');
        assert.equal(cleanName(' Pat'), 'Pat');
        assert.equal(cleanName('Zero​Width'), 'Zero​Width');
        assert.equal(cleanTeamName('   '), null);
        assert.equal(cleanTeamName(' Night  Owls '), 'Night Owls');
        assert.equal(cleanOptionalText('  A-1 '), 'A-1');
        assert.equal(cleanOptionalText(' '), null);
        assert.deepEqual(parsePlace('team:t1'), { kind: 'team', teamId: 't1' });
        assert.deepEqual(parsePlace('in'), { kind: 'in', teamId: null });
    });

    test('name keys ignore case, accents, apostrophes and dash kinds (ParticipantNameKey)', () => {
        assert.equal(nameKey('Zoë O’Brien-Novák'), nameKey('zoe o\'brien–novak'));
        assert.notEqual(nameKey('Kim Example'), nameKey('Kim Examples'));
    });

    test('people in state order, removed left out unless asked; places and the tray', () => {
        const m = model();
        assert.equal(m.people().length, 13);
        assert.equal(m.people({ includeRemoved: true }).length, 14);
        assert.equal(m.placeValue('p-kim', ROUND_PAIRS), 'team:t-corners');
        assert.equal(m.placeValue('p-jo', ROUND_PAIRS), 'in');
        assert.equal(m.placeValue('p-ana', ROUND_PAIRS), 'out');
        assert.deepEqual(idsOf(m.trayOf(ROUND_PAIRS)), ['p-jo']);
        // Ola is removed: not a member any more
        assert.deepEqual(idsOf(m.membersOf('t-corners')), ['p-kim', 'p-pat']);
        assert.deepEqual(idsOf(m.peopleIn(ROUND_SOLO)), ['p-kim', 'p-pat']);
        assert.deepEqual(idsOf(m.peopleInNoRound()), ['p-ana']);
        assert.deepEqual(m.teamsOf(ROUND_PAIRS).map((t) => t.id), ['t-corners', 't-corners2', 't-empty']);
    });

    test('sizes: pairs are 2; a team round without a size uses the most common size (the smaller on a tie)', () => {
        const m = model();
        assert.equal(m.expectedSize(ROUND_SOLO), null);
        assert.equal(m.expectedSize(ROUND_PAIRS), 2);
        assert.equal(m.usualTeamSize(ROUND_TEAMS), 3);
        assert.deepEqual(m.sizeStatus('t-edge'), { count: 3, expected: 3, status: 'complete' });
        assert.deepEqual(m.sizeStatus('t-flat'), { count: 4, expected: 3, status: 'too_many' });
        assert.deepEqual(m.sizeStatus('t-empty'), { count: 0, expected: 2, status: 'empty' });

        const sized = model(smallState({ rounds: smallState().rounds.map((r) => (r.id === ROUND_TEAMS ? { ...r, teamSize: 4 } : r)) }));
        assert.equal(sized.sizeStatus('t-edge').status, 'incomplete');
        assert.equal(sized.sizeStatus('t-flat').status, 'complete');
    });

    test('problem counts per round (O7): short + long pairs/teams + people without one; same names only informational', () => {
        const m = model();
        assert.deepEqual(m.problems(ROUND_PAIRS), { incomplete: 0, tooMany: 0, withoutTeam: 1, sameName: 2, total: 1 });
        assert.deepEqual(m.problems(ROUND_TEAMS), { incomplete: 0, tooMany: 1, withoutTeam: 0, sameName: 0, total: 1 });
        assert.deepEqual(m.problems(ROUND_SOLO), { incomplete: 0, tooMany: 0, withoutTeam: 0, sameName: 0, total: 0 });
        assert.deepEqual([...m.sameNameTeams(ROUND_PAIRS).entries()], [['t-corners', ['t-corners2']], ['t-corners2', ['t-corners']]]);
    });

    test('a round of names only (Minnesota) has no size warnings at all', () => {
        const state = smallState({
            places: [],
            teams: [team('n1', ROUND_TEAMS, 'Night Owls'), team('n2', ROUND_TEAMS, 'Sky Pieces')],
        });
        const m = model(state);
        assert.equal(m.isNamesOnly(ROUND_TEAMS), true);
        assert.equal(m.sizeStatus('n1').status, 'names_only');
        assert.equal(m.problems(ROUND_TEAMS).total, 0);
    });

    test('duplicate names and "already on the list"', () => {
        const state = smallState();
        state.people.push(person('p-kim2', 'kim  EXAMPLE'));
        const m = model(state);
        assert.deepEqual([...m.duplicateNames().values()].map(idsOf), [['p-kim', 'p-kim2']]);
        assert.deepEqual(idsOf(m.peopleNamed('KIM example')), ['p-kim', 'p-kim2']);
        assert.deepEqual(m.peopleNamed('Nobody Here'), []);
    });

    test('the results guard knows official data and the linked player\'s own times', () => {
        const m = model();
        assert.equal(m.holdsDataInRound('p-kim', ROUND_SOLO), true);
        assert.equal(m.holdsDataInRound('p-pat', ROUND_SOLO), false);
        assert.equal(m.holdsDataInRound('p-t4', ROUND_TEAMS), true, 'Flat has a result');
        assert.equal(m.holdsDataInEvent('p-jo'), false);
        assert.equal(m.entryRef('p-pat', ROUND_SOLO), 'participant_round:e-pat-solo');
        assert.equal(m.entryRef('p-kim', ROUND_PAIRS), 'team:t-corners');
        assert.equal(m.entryRef('p-ana', ROUND_SOLO), null);
    });

    test('team labels (O1) carry the name, the table and the members', () => {
        assert.deepEqual(model().teamLabel('t-corners'), { name: 'Corners', table: 2, members: ['Kim Example', 'Pat Sample'] });
        assert.deepEqual(model().teamLabel('t-empty'), { name: null, table: null, members: [] });
    });

    test('a local group shows at once with a delta of what changed; reverting it restores everything', () => {
        const m = model();
        const deltas = [];
        m.subscribe((delta) => deltas.push(delta));

        m.applyLocal('g1', [{ op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:t-empty' }]);
        assert.equal(m.placeValue('p-jo', ROUND_PAIRS), 'team:t-empty');
        assert.deepEqual(idsOf(m.trayOf(ROUND_PAIRS)), []);
        assert.deepEqual([...deltas[0].people], ['p-jo']);
        assert.deepEqual([...deltas[0].teams], ['t-empty']);
        assert.deepEqual([...deltas[0].rounds], [ROUND_PAIRS]);

        m.revert('g1');
        assert.equal(m.placeValue('p-jo', ROUND_PAIRS), 'in');
        assert.equal(m.hasPending(), false);
        assert.deepEqual([...deltas[1].people], ['p-jo']);
    });

    test('an unnamed pair emptied by a group goes (the server deletes it); a named one stays; a removal never deletes', () => {
        const m = model();
        m.applyLocal('g1', [{ op: 'newTeam', id: 't-new', round: ROUND_PAIRS, name: null }, { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:t-new' }]);
        assert.notEqual(m.team('t-new'), null);
        m.applyLocal('g2', [{ op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'team:t-new', to: 'in' }]);
        assert.equal(m.team('t-new'), null);

        m.applyLocal('g3', [{ op: 'place', participant: 'p-lee', round: ROUND_PAIRS, from: 'team:t-corners2', to: 'in' }, { op: 'place', participant: 'p-max', round: ROUND_PAIRS, from: 'team:t-corners2', to: 'in' }]);
        assert.notEqual(m.team('t-corners2'), null, 'named teams stay as pre-created teams');

        const n = model();
        n.applyLocal('g1', [{ op: 'newTeam', id: 't-x', round: ROUND_PAIRS, name: null }, { op: 'place', participant: 'p-jo', round: ROUND_PAIRS, from: 'in', to: 'team:t-x' }]);
        n.applyLocal('g2', [{ op: 'remove', participant: 'p-jo' }]);
        assert.notEqual(n.team('t-x'), null, 'teams emptied by remove are never deleted automatically');
    });

    test('confirm folds a group into the base with the teams the server deleted', () => {
        const m = model();
        m.applyLocal('g1', [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Example', to: 'Ana  Examplová ' }]);
        m.confirm('g1', ['t-empty']);
        assert.equal(m.hasPending(), false);
        assert.equal(m.person('p-ana').name, 'Ana Examplová');
        assert.equal(m.team('t-empty'), null);
        // a later fetched state without pending groups replaces it all
        m.replaceState(smallState());
        assert.equal(m.person('p-ana').name, 'Ana Example');
    });

    test('a fetched state keeps the organiser\'s pending values and reports only what changed', () => {
        const m = model();
        m.applyLocal('g1', [{ op: 'field', participant: 'p-ana', field: 'country', from: null, to: 'cz' }]);
        const next = smallState({ version: 'v2' });
        next.people.find((p) => p.id === 'p-jo').name = 'Jo Doe';
        next.people.push(person('p-new', 'Nia Joined', { source: 'self_joined' }));
        const deltas = [];
        m.subscribe((delta) => deltas.push(delta));
        m.replaceState(next);

        assert.equal(m.version, 'v2');
        assert.equal(m.person('p-ana').country, 'cz', 'pending value kept');
        assert.equal(m.person('p-jo').name, 'Jo Doe');
        assert.equal(m.person('p-new').source, 'self_joined');
        assert.deepEqual([...deltas[0].people].sort(), ['p-jo', 'p-new']);
        assert.equal(deltas[0].rows, true);
    });

    test('a fetched state older than a live result does not take the newer result back', () => {
        const m = model();
        m.mergeEntries([{ ref: 'team:t-flat', tableNumber: 4, result: { seconds: 4900 }, qualified: true, enteredAt: '2026-10-08T08:30:00+00:00', enteredBy: { name: 'Bo' } }]);
        assert.deepEqual(m.team('t-flat').result, { seconds: 4900 });
        m.replaceState(smallState());
        assert.deepEqual(m.team('t-flat').result, { seconds: 4900 });
        assert.equal(m.team('t-flat').enteredBy, 'Bo');
    });

    test('live entries merge by ref; an older result never replaces a newer one; unknown refs are reported', () => {
        const m = model();
        const generation = m.resultsGeneration;
        const { unknown, delta } = m.mergeEntries([
            { ref: 'participant_round:e-pat-solo', tableNumber: 7, result: { piecesPlaced: 400 }, qualified: false, enteredAt: '2026-10-08T08:10:00+00:00', enteredBy: { name: 'Eva' } },
            { ref: 'team:t-flat', tableNumber: 3, result: { seconds: 6000 }, qualified: false, enteredAt: '2026-10-08T07:00:00+00:00', enteredBy: { name: 'Old' } },
            { ref: 'participant_round:nope', result: null },
            { ref: 'team:nope', result: null },
        ]);

        assert.deepEqual(unknown, ['participant_round:nope', 'team:nope']);
        assert.deepEqual(m.place('p-pat', ROUND_SOLO).result, { piecesPlaced: 400 });
        assert.equal(m.place('p-pat', ROUND_SOLO).table, 7);
        assert.deepEqual(m.team('t-flat').result, { seconds: 5000 }, 'older result ignored');
        assert.equal(m.team('t-flat').table, null, 'an update about an older result is an old message - its table number neither');
        assert.ok(delta.people.has('p-pat'));
        assert.equal(delta.teams.has('t-flat'), false, 'the old message changed nothing');
        assert.equal(m.resultsGeneration, generation + 1);

        const newer = m.mergeEntries([{ ref: 'team:t-flat', tableNumber: 5, result: { seconds: 4800 }, qualified: true, enteredAt: '2026-10-08T09:00:00+00:00', enteredBy: { name: 'Bo' } }]);
        assert.equal(m.team('t-flat').table, 5);
        assert.equal(m.team('t-flat').qualified, true);
        assert.ok(newer.delta.people.has('p-t4'), 'members of the team re-render');
    });

    test('round overviews update publication and table numbers usage', () => {
        const m = model();
        m.updateRound({ id: ROUND_SOLO, resultsPublished: true, tableNumbersOff: true, name: 'ignored' });
        assert.equal(m.round(ROUND_SOLO).resultsPublished, true);
        assert.equal(m.round(ROUND_SOLO).tableNumbersOff, true);
        assert.equal(m.round(ROUND_SOLO).name, 'Solo');
        m.updateRound({ id: 'unknown', resultsPublished: true });
    });

    test('a profile picked shows its name until the next state; unlinking clears it, the own-time guard stays until the next state', () => {
        const m = model();
        m.applyLocal('g1', [{ op: 'player', participant: 'p-ana', from: null, to: 'pl-ana', _player: { id: 'pl-ana', name: 'Ana E.', code: 'ANA1' } }]);
        assert.equal(m.person('p-ana').player.name, 'Ana E.');
        assert.equal(m.person('p-ana').player.visible, true);
        m.applyLocal('g2', [{ op: 'player', participant: 'p-kim', from: 'pl-kim', to: null }]);
        assert.equal(m.person('p-kim').player, null);
        assert.deepEqual(m.person('p-kim').playerResultRounds, [ROUND_SOLO], 'the server checks the changeset against the player Kim had when it started');
    });

    test('a new person and a new place get local ids (no entry ref until the server named them)', () => {
        const m = model();
        m.applyLocal('g1', [{ op: 'newParticipant', id: 'p-new', name: ' Ny  Person ', country: 'de', externalId: null }, { op: 'place', participant: 'p-new', round: ROUND_SOLO, from: 'out', to: 'in' }]);
        assert.equal(m.person('p-new').name, 'Ny Person');
        assert.equal(m.people().at(-1).id, 'p-new', 'new people at the end');
        assert.equal(m.place('p-new', ROUND_SOLO).local, true);
        assert.equal(m.entryRef('p-new', ROUND_SOLO), null);
    });

    test('team size, rename, delete and restore apply like the server', () => {
        const m = model();
        m.applyLocal('g1', [{ op: 'teamSize', round: ROUND_TEAMS, from: null, to: 4 }]);
        assert.equal(m.expectedSize(ROUND_TEAMS), 4);
        m.applyLocal('g2', [{ op: 'renameTeam', team: 't-edge', from: 'Edge', to: '  ' }]);
        assert.equal(m.team('t-edge').name, null);
        m.applyLocal('g3', [{ op: 'deleteTeam', team: 't-corners' }]);
        assert.equal(m.team('t-corners'), null);
        assert.equal(m.placeValue('p-kim', ROUND_PAIRS), 'in');
        assert.equal(m.place('p-ola', ROUND_PAIRS).teamId, null, 'removed members leave the team too');
        m.applyLocal('g4', [{ op: 'restore', participant: 'p-ola' }]);
        assert.equal(m.isRemoved('p-ola'), false);
    });

    test('markers by key, with the entities that re-render', () => {
        const m = model();
        const deltas = [];
        m.subscribe((delta) => deltas.push(delta));
        m.marks.set('person:p-ana:name', { state: 'saving', groupId: 'g1' }, { people: ['p-ana'] });
        assert.equal(m.marks.get('person:p-ana:name').state, 'saving');
        assert.deepEqual([...deltas[0].people], ['p-ana']);
        m.marks.clearFor('person:p-ana:name', 'other');
        assert.notEqual(m.marks.get('person:p-ana:name'), null, 'a newer group keeps its marker');
        m.marks.clearFor('person:p-ana:name', 'g1');
        assert.equal(m.marks.get('person:p-ana:name'), null);
        assert.deepEqual([...deltas[1].people], ['p-ana']);
        m.marks.set('place:p-jo:r', { state: 'conflict' }, { people: ['p-jo'] });
        assert.equal(m.marks.withPrefix('place:p-jo:').length, 1);
    });

    test('1,000 people x 15 rounds: indexes and a local change stay fast', () => {
        const rounds = Array.from({ length: 15 }, (_, i) => ({ id: `r${i}`, name: `R${i}`, category: i < 6 ? 'solo' : (i < 11 ? 'duo' : 'team'), teamSize: null }));
        const people = Array.from({ length: 1000 }, (_, i) => person(`p${i}`, `Person Example ${i}`));
        const places = [];
        const teams = [];
        for (let r = 0; r < 15; r++) {
            for (let i = 0; i < 1000; i += 3) {
                const teamId = r >= 6 ? `t${r}-${Math.floor(i / 6)}` : null;
                if (teamId && !teams.some((t) => t.id === teamId)) {
                    teams.push(team(teamId, `r${r}`, `Team ${i}`));
                }
                places.push(place(`e${r}-${i}`, `p${i}`, `r${r}`, teamId));
            }
        }
        const started = performance.now();
        const m = model(smallState({ rounds, people, places, teams }));
        rounds.forEach((r) => m.problems(r.id));
        const loaded = performance.now() - started;
        const editStarted = performance.now();
        m.applyLocal('g', [{ op: 'field', participant: 'p5', field: 'name', from: 'Person Example 5', to: 'Renamed' }]);
        rounds.forEach((r) => m.problems(r.id));
        const edit = performance.now() - editStarted;
        assert.ok(loaded < 500, `load ${loaded} ms`);
        assert.ok(edit < 150, `edit ${edit} ms`);
    });

    test('a listener that throws (a view bug) is logged; the other listeners still hear every change', () => {
        const m = model();
        const heard = [];
        const logged = [];
        const original = console.error;
        console.error = (error) => logged.push(error.message);

        try {
            m.subscribe(() => {
                throw new Error('a view bug');
            });
            m.subscribe((delta) => heard.push([...delta.people]));
            m.applyLocal('g1', [{ op: 'field', participant: 'p-ana', field: 'name', from: 'Ana Example', to: 'Ana One' }]);
            m.applyLocal('g2', [{ op: 'field', participant: 'p-jo', field: 'name', from: 'Jo Do', to: 'Jo Two' }]);
            m.revert('g1');
        } finally {
            console.error = original;
        }

        assert.deepEqual(logged, ['a view bug', 'a view bug', 'a view bug']);
        assert.deepEqual(heard, [['p-ana'], ['p-jo'], ['p-ana']]);
        assert.deepEqual(m.pending.map((group) => group.id), ['g2'], 'the revert completed');
    });

    test('bulk: applyLocalMany / confirmMany / revertMany rebuild once and tell once; batch() merges every delta into one', () => {
        const m = model();
        const deltas = [];
        m.subscribe((delta) => deltas.push(delta));
        const groups = ['p-ana', 'p-jo', 'p-lee'].map((id, index) => ({ id: `g${index}`, changes: [{ op: 'place', participant: id, round: ROUND_SOLO, from: 'out', to: 'in' }] }));
        m.applyLocalMany(groups);
        assert.equal(deltas.length, 1);
        assert.deepEqual([...deltas[0].people].sort(), ['p-ana', 'p-jo', 'p-lee']);
        assert.equal(m.peopleIn(ROUND_SOLO).length, 5);

        m.confirmMany([{ groupId: 'g0' }, { groupId: 'g2' }]);
        assert.equal(deltas.length, 1, 'confirmed as shown - nothing to re-render');
        m.revertMany(['g1']);
        assert.equal(deltas.length, 2);
        assert.deepEqual(m.pending, []);
        assert.deepEqual(m.peopleIn(ROUND_SOLO).map((p) => p.id).sort(), ['p-ana', 'p-kim', 'p-lee', 'p-pat']);
        assert.equal(m.revertMany(['nope']).people.size, 0);

        deltas.length = 0;
        m.batch(() => {
            m.applyLocal('b1', [{ op: 'field', participant: 'p-ana', field: 'country', from: null, to: 'cz' }]);
            m.marks.setMany([
                { key: 'person:p-jo:name', mark: { state: 'saving' }, entities: { people: ['p-jo'] } },
                { key: 'team:t-edge:name', mark: { state: 'saving' }, entities: { teams: ['t-edge'] } },
            ]);
            m.batch(() => m.applyLocal('b2', [{ op: 'field', participant: 'p-lee', field: 'country', from: null, to: 'cz' }]));
        });
        assert.equal(deltas.length, 1, 'told once, by the outermost batch');
        assert.deepEqual([...deltas[0].people].sort(), ['p-ana', 'p-jo', 'p-lee']);
        assert.deepEqual([...deltas[0].teams], ['t-edge']);
    });

    test('a profile hidden from the organiser (O9) shows as linked only, even when picked from the search', () => {
        const m = model();
        m.applyLocal('g1', [{ op: 'player', participant: 'p-ana', from: null, to: 'pl-x', _player: { id: 'pl-x', visible: false, name: null } }]);
        assert.deepEqual(m.person('p-ana').player, { id: 'pl-x', visible: false, name: null, code: null, country: null, avatar: null, profileUrl: null });
    });

    test('the copy-on-write state takes a trial back exactly (begin / rollback)', () => {
        const m = model();
        const working = m.scratch();
        const before = JSON.stringify([[...working.people], [...working.places], [...working.teams], [...working.rounds], working.order]);
        working.begin();
        working.apply({ op: 'newParticipant', id: 'p-new', name: 'New', country: null, externalId: null }, 0);
        working.apply({ op: 'place', participant: 'p-new', round: ROUND_PAIRS, from: 'out', to: 'team:t-edge' }, 0);
        working.apply({ op: 'deleteTeam', team: 't-corners' }, 0);
        working.apply({ op: 'teamSize', round: ROUND_TEAMS, from: null, to: 5 }, 0);
        working.apply({ op: 'remove', participant: 'p-jo' }, 0);
        working.rollback();
        assert.equal(JSON.stringify([[...working.people], [...working.places], [...working.teams], [...working.rounds], working.order]), before);
    });
}


