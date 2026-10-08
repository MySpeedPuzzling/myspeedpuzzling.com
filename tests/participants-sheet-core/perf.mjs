// Bulk actions on a WJPC-sized event (review minor 5): a bulk "in"/"out" over every person is one group per person -
// shown in one model rebuild and one re-render, its answer settled the same way. Re-render counts are exact; the time
// bounds are loose on purpose (CI machines differ) - measured on a laptop: ~20 ms for 400 groups, ~60 ms for 1,000.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { setInRound } from '../../assets/participants_sheet/sheet_changes.js';
import { SheetSaveQueue, DEBOUNCE_MS } from '../../assets/participants_sheet/sheet_save_queue.js';
import { appliedAnswer, clock, fakeServer, ids } from './fixture.mjs';

/** n people, 15 rounds (8 solo, 4 pairs, 3 teams): everybody in a solo group, a pair and a team. */
export function bigState(n) {
    const rounds = [];

    for (let r = 0; r < 15; r++) {
        rounds.push({ id: `r${r}`, name: `R${r}`, category: r < 8 ? 'solo' : (r < 12 ? 'duo' : 'team'), teamSize: null });
    }

    const people = [];
    const places = [];
    const teams = [];

    for (let i = 0; i < n; i++) {
        people.push({ id: `p${i}`, name: `Person ${i}`, country: 'us', externalId: null, note: null, source: 'manual', removedAt: null, connectedAt: null, player: null, registration: null, playerResultRounds: [] });
        places.push({ id: `e${i}a`, participantId: `p${i}`, roundId: `r${i % 4}`, teamId: null });
        places.push({ id: `e${i}b`, participantId: `p${i}`, roundId: 'r8', teamId: `t${Math.floor(i / 2)}` });
        places.push({ id: `e${i}c`, participantId: `p${i}`, roundId: 'r12', teamId: `u${Math.floor(i / 4)}` });
    }

    for (let i = 0; i < n / 2; i++) {
        teams.push({ id: `t${i}`, roundId: 'r8', name: `Pair ${i}` });
    }

    for (let i = 0; i < n / 4; i++) {
        teams.push({ id: `u${i}`, roundId: 'r12', name: `Team ${i}` });
    }

    return { version: 'v1', rounds, people, places, teams };
}

/** A bulk action from building it to the server's answer settled, as the controller and the queue run it. */
export async function bulk(n, { inRound = true } = {}) {
    const time = clock();
    const server = fakeServer();
    const model = new SheetModel(bigState(n), { now: time.now });
    const queue = new SheetSaveQueue({ model, urls: { changes: '/c', state: '/s' }, csrfToken: 'x', request: server.request, newId: ids('cs'), schedule: time.schedule, cancel: time.cancel, now: time.now });
    let renders = 0;
    model.subscribe(() => renders++);
    const people = model.people().map((person) => person.id);
    // "out" of the solo groups everybody is in (r0..r3), "in" a solo group nobody is in (r5)
    const round = inRound ? 'r5' : 'r0';
    const targets = inRound ? people : people.filter((id, index) => index % 4 === 0);

    let started = performance.now();
    const action = setInRound(model, targets, round, inRound);
    const build = performance.now() - started;

    started = performance.now();
    model.batch(() => {
        model.applyLocalMany(action.groups);
        queue.enqueueGroups(action.groups);
    });
    const show = performance.now() - started;
    const shownRenders = renders;

    await time.advance(DEBOUNCE_MS);
    renders = 0;
    started = performance.now();
    await server.reply(appliedAnswer(server.calls[0]));
    const settle = performance.now() - started;

    queue.destroy();

    return { groups: action.groups.length, build, show, settle, total: build + show + settle, shownRenders, settleRenders: renders, pending: model.pending.length, placeOf: (id) => model.placeValue(id, round) };
}

export default function (test) {
    test('400 people, bulk "in": one group each, one re-render to show them, one to settle the answer', async () => {
        const result = await bulk(400);
        assert.equal(result.groups, 400);
        assert.equal(result.shownRenders, 1);
        assert.equal(result.settleRenders, 1);
        assert.equal(result.pending, 0);
        assert.equal(result.placeOf('p399'), 'in');
        assert.ok(result.total < 500, `400 groups took ${result.total.toFixed(0)} ms`);
    });

    test('1,000 people, bulk "in" and bulk "out": well under a second', async () => {
        const into = await bulk(1000);
        assert.equal(into.groups, 1000);
        assert.equal(into.shownRenders, 1);
        assert.equal(into.settleRenders, 1);
        assert.ok(into.total < 2000, `1,000 groups took ${into.total.toFixed(0)} ms`);

        const out = await bulk(1000, { inRound: false });
        assert.equal(out.groups, 250);
        assert.equal(out.placeOf('p0'), 'out');
        assert.ok(out.total < 2000, `bulk out took ${out.total.toFixed(0)} ms`);
    });
}
