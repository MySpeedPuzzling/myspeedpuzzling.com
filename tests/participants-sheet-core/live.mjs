// Live updates (assets/participants_sheet/sheet_live.js): the sheet topic's version, the rounds' result updates,
// version polling while visible, catching up when the tab returns, tokens from every state.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { SheetSaveQueue } from '../../assets/participants_sheet/sheet_save_queue.js';
import { SheetLive, VERSION_POLL_MS } from '../../assets/participants_sheet/sheet_live.js';
import { setField } from '../../assets/participants_sheet/sheet_changes.js';
import { ROUND_SOLO, ROUND_TEAMS, appliedAnswer, clock, fakeServer, ids, smallState } from './fixture.mjs';

function setup({ visible = true } = {}) {
    const time = clock();
    const server = fakeServer();
    const model = new SheetModel(smallState(), { now: time.now });
    const urls = { changes: '/changes', state: '/state', version: '/version', record: '/r/__ROUND__', tables: '/t/__ROUND__' };
    const queue = new SheetSaveQueue({ model, urls, csrfToken: 'x', request: server.request, newId: ids('cs'), schedule: time.schedule, cancel: time.cancel, now: time.now });
    const stream = { started: false, updates: [], suspended: 0, closed: false, options: null };
    const view = { visible };
    const handled = [];
    const foreign = [];
    const live = new SheetLive({
        model,
        queue,
        urls,
        request: server.request,
        isVisible: () => view.visible,
        schedule: time.schedule,
        cancel: time.cancel,
        now: time.now,
        onMessage: (data) => handled.push(data.type),
        onForeignChange: (data) => foreign.push(data.version),
        eventsFactory: (options) => {
            stream.options = options;

            return {
                start: () => { stream.started = true; },
                update: (subscription) => stream.updates.push(subscription),
                suspend: () => { stream.suspended++; },
                close: () => { stream.closed = true; },
                isLive: () => true,
            };
        },
    });
    live.start();

    return { time, server, model, queue, live, stream, view, handled, foreign };
}

export default function (test) {
    test('the stream starts with the page\'s token; its catch-up fetch is the queue\'s fetch', async () => {
        const { stream, server, time } = setup();
        assert.equal(stream.started, true);
        assert.equal(stream.options.subscription.token, 'tok');
        const kind = stream.options.refresh();
        await time.advance(0);
        assert.equal(server.calls[0].url, '/state');
        await server.reply({ kind: 'ok', status: 200, data: smallState({ mercure: { url: '/hub', topics: ['/x'], token: 'fresh', expiresIn: 3600 } }) });
        assert.equal(await kind, 'ok');
        assert.equal(stream.updates.at(-1).token, 'fresh', 'every state renews the token');
    });

    test('a sheet change with another version fetches the state; our own known version does nothing', async () => {
        const { live, server, time, handled } = setup();
        live.handle({ type: 'participants_sheet.changed', competitionId: 'c1', version: 'v1' });
        await time.advance(0);
        assert.equal(server.calls.length, 0);
        live.handle({ type: 'participants_sheet.changed', competitionId: 'c1', version: 'v9' });
        await time.advance(0);
        assert.equal(server.calls[0].url, '/state');
        assert.deepEqual(handled, ['participants_sheet.changed', 'participants_sheet.changed']);
    });

    test('our own save announced before its answer arrives: no fetch when the answer brings that version', async () => {
        const { live, server, time, model, queue } = setup();
        const action = setField(model, 'p-ana', 'name', 'Ana One');
        action.groups.forEach((group) => model.applyLocal(group.id, group.changes));
        queue.enqueueGroups(action.groups);
        await time.advance(800);
        live.handle({ type: 'participants_sheet.changed', version: 'v2' });
        await server.reply(appliedAnswer(server.calls[0], { versionBefore: 'v1', versionAfter: 'v2' }));
        await time.advance(5000);
        assert.equal(server.calls.length, 1);
    });

    test('result updates merge by ref; an unknown entry fetches the state; other events\' rounds are ignored', async () => {
        const { live, server, time, model } = setup();
        live.handle({ type: 'official_results.entries', roundId: ROUND_TEAMS, entries: [{ ref: 'team:t-edge', tableNumber: 8, result: { seconds: 4500 }, qualified: true, enteredAt: '2026-10-08T09:10:00+00:00', enteredBy: { name: 'Eva' } }], round: { id: ROUND_TEAMS, resultsPublished: true } });
        assert.equal(model.team('t-edge').table, 8);
        assert.equal(model.round(ROUND_TEAMS).resultsPublished, true);
        await time.advance(0);
        assert.equal(server.calls.length, 0);
        live.handle({ type: 'official_results.entries', roundId: ROUND_SOLO, entries: [{ ref: 'participant_round:new-one', result: null }] });
        await time.advance(0);
        assert.equal(server.calls.length, 1);
        live.handle({ type: 'official_results.entries', roundId: 'other-event-round', entries: [{ ref: 'team:x' }] });
        live.handle({ type: 'official_results.refresh', roundId: 'other-event-round' });
        live.handle({ type: 'official_results.round', roundId: ROUND_SOLO, round: { id: ROUND_SOLO, tableNumbersOff: true } });
        assert.equal(model.round(ROUND_SOLO).tableNumbersOff, true);
        live.handle(null);
        live.handle({ type: 'something.else' });
        await time.advance(0);
        assert.equal(server.calls.length, 1);
    });

    test('the version is checked every 30 s while visible and idle; a difference fetches the state', async () => {
        const { server, time, view } = setup();
        await time.advance(VERSION_POLL_MS);
        assert.equal(server.calls[0].url, '/version');
        await server.reply({ kind: 'ok', status: 200, data: { version: 'v1' } });
        await time.advance(VERSION_POLL_MS);
        assert.equal(server.calls[1].url, '/version');
        await server.reply({ kind: 'ok', status: 200, data: { version: 'v5' } });
        await time.advance(0);
        assert.equal(server.calls[2].url, '/state');
        await server.reply({ kind: 'ok', status: 200, data: smallState({ version: 'v5' }) });
        view.visible = false;
        await time.advance(VERSION_POLL_MS * 3);
        assert.equal(server.calls.length, 3, 'nothing while hidden');
    });

    test('signed out on a version check: the stream stops, the pill says so', async () => {
        const { server, time, queue, stream } = setup();
        await time.advance(VERSION_POLL_MS);
        await server.reply({ kind: 'auth', status: 401 });
        assert.equal(stream.suspended, 1);
        assert.equal(queue.status().state, 'auth');
    });

    test('back after more than 10 s hidden: fetch; a short glance: nothing', async () => {
        const { live, server, time, view } = setup();
        view.visible = false;
        live.visibilityChanged();
        await time.advance(5000);
        view.visible = true;
        live.visibilityChanged();
        await time.advance(0);
        assert.equal(server.calls.length, 0);
        view.visible = false;
        live.visibilityChanged();
        await time.advance(11000);
        view.visible = true;
        live.visibilityChanged();
        await time.advance(0);
        assert.equal(server.calls[0].url, '/state');
    });

    test('a state answering signed out suspends the stream; close() stops polling', async () => {
        const { live, server, time, stream, queue } = setup();
        queue.refetch();
        await time.advance(0);
        await server.reply({ kind: 'auth', status: 401 });
        assert.equal(stream.suspended, 1);
        live.close();
        assert.equal(stream.closed, true);
        await time.advance(VERSION_POLL_MS * 2);
        assert.equal(server.calls.length, 1);
    });

    test('nit: our own save\'s echo is never "another organiser changed the sheet" - on its way, adopted, or late', async () => {
        const { live, server, time, model, queue, foreign } = setup();
        const save = (name, versionBefore, versionAfter) => async (echoFirst) => {
            const action = setField(model, 'p-ana', 'name', name);
            model.applyLocalMany(action.groups);
            queue.enqueueGroups(action.groups);
            await time.advance(800);

            if (echoFirst) {
                live.handle({ type: 'participants_sheet.changed', version: versionAfter });
            }

            await server.reply(appliedAnswer(server.calls.at(-1), { versionBefore, versionAfter }));

            if (!echoFirst) {
                live.handle({ type: 'participants_sheet.changed', version: versionAfter });
            }

            await time.advance(0);
        };
        await save('Ana One', 'v1', 'v2')(true);
        await save('Ana Two', 'v2', 'v3')(false);
        assert.equal(server.calls.length, 2, 'nothing fetched');
        live.handle({ type: 'participants_sheet.changed', version: 'v2' });
        await time.advance(0);
        assert.deepEqual(foreign, [], 'a late echo of v2 is fetched but not news');
        await server.reply({ kind: 'ok', status: 200, data: smallState({ version: 'v3' }) });

        live.handle({ type: 'participants_sheet.changed', version: 'v-theirs' });
        await time.advance(0);
        assert.deepEqual(foreign, ['v-theirs']);
    });

    test('nit: somebody else\'s save that arrives while ours is on its way is told once our answer is in', async () => {
        const { live, server, time, model, queue, foreign } = setup();
        const action = setField(model, 'p-ana', 'name', 'Ana One');
        model.applyLocalMany(action.groups);
        queue.enqueueGroups(action.groups);
        await time.advance(800);
        live.handle({ type: 'participants_sheet.changed', version: 'v-theirs' });
        assert.deepEqual(foreign, []);
        await server.reply(appliedAnswer(server.calls[0], { versionBefore: 'v1', versionAfter: 'v2' }));
        await time.advance(0);
        assert.deepEqual(foreign, ['v-theirs']);
    });
}
