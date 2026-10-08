// Managed registration in the People tab (assets/participants_sheet/registration_actions.js): actions per state, the
// waitlist order, counters, the first-in-line hint, the endpoint call and merging its answer.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { setField } from '../../assets/participants_sheet/sheet_changes.js';
import {
    allowedActions,
    firstInLine,
    holdsSpot,
    matchesRegistrationFilter,
    paidBefore,
    performRegistrationAction,
    registrationCounts,
    registrationStatus,
    sendRegistrationAction,
    waitlistPositions,
} from '../../assets/participants_sheet/registration_actions.js';
import { clock, fakeServer, person, settle, smallState } from '../participants-sheet-core/fixture.mjs';

const reg = (status, extra = {}) => ({ status, registeredAt: null, paidAt: null, checkedInAt: null, ...extra });

function managedState() {
    const state = smallState({ competition: { id: 'c1', name: 'Example Open', isOnline: false, registrationManaged: true, capacity: 4 } });
    const registrations = {
        'p-ana': reg('reserved', { registeredAt: '2026-09-01T10:00:00+00:00' }),
        'p-jo': reg('paid', { registeredAt: '2026-09-01T10:01:00+00:00', paidAt: '2026-09-02T08:00:00+00:00' }),
        'p-kim': reg('waitlisted', { registeredAt: '2026-09-03T10:00:00+00:00' }),
        'p-lee': reg('waitlisted', { registeredAt: '2026-09-02T10:00:00+00:00' }),
        'p-max': reg('waitlisted', { registeredAt: '2026-09-02T10:00:00+00:00' }),
        'p-ola': reg('reserved'),
        'p-pat': reg('reserved', { paidAt: '2026-09-01T09:00:00+00:00', checkedInAt: '2026-10-08T08:00:00+00:00' }),
    };
    state.people = state.people.map((row) => ({ ...row, registration: registrations[row.id] ?? reg('reserved') }));

    return state;
}

function context(model, server, announced = []) {
    const texts = { t: (key, params = {}) => `${key}${Object.keys(params).length ? ` ${JSON.stringify(params)}` : ''}`, tc: (key, count) => `${key} ${count}` };

    return {
        model,
        urls: { registration: '/en/participants-sheet-api/c1/registration' },
        csrfToken: 'csrf-1',
        texts: { core: texts, people: texts },
        announce: (text) => announced.push(text),
    };
}

export default function (test) {
    test('a row without a status holds a spot; an event without management has no registration', () => {
        assert.equal(registrationStatus(person('a', 'A', { registration: reg(null) })), 'reserved');
        assert.equal(registrationStatus(person('a', 'A', { registration: reg('paid') })), 'paid');
        assert.equal(registrationStatus(person('a', 'A')), null);
        assert.equal(holdsSpot(person('a', 'A', { registration: reg('paid') })), true);
        assert.equal(holdsSpot(person('a', 'A', { registration: reg('waitlisted') })), false);
        assert.equal(holdsSpot(person('a', 'A', { registration: reg('paid'), removedAt: '2026-10-01T00:00:00+00:00' })), false);
    });

    test('the actions each state allows (the handlers decide, the browser offers only those)', () => {
        const at = '2026-10-08T08:00:00+00:00';
        const cases = [
            [reg('reserved'), ['markPaid', 'checkIn']],
            [reg('reserved', { checkedInAt: at }), ['markPaid', 'undoCheckIn']],
            [reg('paid', { paidAt: at }), ['unmarkPaid', 'checkIn']],
            [reg('paid', { paidAt: at, checkedInAt: at }), ['unmarkPaid', 'undoCheckIn']],
            [reg('waitlisted'), ['promote', 'promoteAndMarkPaid']],
            [reg('reserved', { paidAt: at }), ['markPaid', 'checkIn']],
        ];

        for (const [registration, expected] of cases) {
            assert.deepEqual(allowedActions(person('a', 'A', { registration })), expected, JSON.stringify(registration));
        }

        assert.deepEqual(allowedActions(person('a', 'A')), []);
        assert.deepEqual(allowedActions(person('a', 'A', { registration: reg('paid'), removedAt: at })), []);
        // Online event: nobody walks in - no check-in, no undo
        assert.deepEqual(allowedActions(person('a', 'A', { registration: reg('reserved', { checkedInAt: at }) }), { checkIn: false }), ['markPaid']);
        assert.deepEqual(allowedActions(person('a', 'A', { registration: reg('waitlisted') }), { checkIn: false }), ['promote', 'promoteAndMarkPaid']);
    });

    test('"paid on {date}, before the registration was cancelled" only on a row that is not paid now', () => {
        assert.equal(paidBefore(person('a', 'A', { registration: reg('reserved', { paidAt: '2026-09-01T09:00:00+00:00' }) })), '2026-09-01T09:00:00+00:00');
        assert.equal(paidBefore(person('a', 'A', { registration: reg('waitlisted', { paidAt: '2026-09-01T09:00:00+00:00' }) })), '2026-09-01T09:00:00+00:00');
        assert.equal(paidBefore(person('a', 'A', { registration: reg('paid', { paidAt: '2026-09-01T09:00:00+00:00' }) })), null);
        assert.equal(paidBefore(person('a', 'A', { registration: reg('reserved') })), null);
    });

    test('the waitlist is FIFO by registration time, then id; removed people are not on it', () => {
        const people = managedState().people;
        assert.deepEqual([...waitlistPositions(people)], [['p-lee', 1], ['p-max', 2], ['p-kim', 3]]);

        const withoutDate = [...people, person('p-aaa', 'Aaa', { registration: reg('waitlisted') })];
        assert.equal(waitlistPositions(withoutDate).get('p-aaa'), 1);

        const removedFirst = people.map((row) => (row.id === 'p-lee' ? { ...row, removedAt: '2026-10-01T00:00:00+00:00' } : row));
        assert.deepEqual([...waitlistPositions(removedFirst)], [['p-max', 1], ['p-kim', 2]]);
    });

    test('counters: spots taken = reserved + paid (no status = reserved), the capacity, waitlist, checked in', () => {
        const people = managedState().people;
        // Ana, Pat, Tia..Xan reserved (Ola is removed), Jo paid - 10 spots of 4
        const counts = registrationCounts(people, 4);
        assert.equal(counts.reserved, 9);
        assert.equal(counts.paid, 1);
        assert.equal(counts.taken, 10);
        assert.equal(counts.waitlisted, 3);
        assert.equal(counts.checkedIn, 1);
        assert.equal(counts.free, 0);
        assert.equal(counts.over, true);
        assert.equal(registrationCounts(people, null).free, null);
    });

    test('the first in line is offered only while a spot is free (or there is no capacity)', () => {
        const people = managedState().people;
        assert.equal(firstInLine(people, 4), null);
        assert.equal(firstInLine(people, 10), null, 'every spot taken');
        assert.equal(firstInLine(people, 11)?.id, 'p-lee');
        assert.equal(firstInLine(people, null)?.id, 'p-lee');
        assert.equal(firstInLine(people.filter((row) => row.registration.status !== 'waitlisted'), 100), null);
    });

    test('the registration filters', () => {
        const at = '2026-10-08T08:00:00+00:00';
        const rows = {
            waiting: person('w', 'W', { registration: reg('waitlisted') }),
            reserved: person('r', 'R', { registration: reg('reserved') }),
            paid: person('p', 'P', { registration: reg('paid', { paidAt: at }) }),
            here: person('h', 'H', { registration: reg('paid', { paidAt: at, checkedInAt: at }) }),
        };
        const which = (filter) => Object.entries(rows).filter(([, row]) => matchesRegistrationFilter(row, filter)).map(([key]) => key);
        assert.deepEqual(which('waitlist'), ['waiting']);
        assert.deepEqual(which('not_paid'), ['reserved']);
        assert.deepEqual(which('checked_in'), ['here']);
        assert.deepEqual(which('not_checked_in'), ['reserved', 'paid']);
    });

    test('an action is one POST with the participant, the action and the CSRF header; the answer is the person', async () => {
        const server = fakeServer();
        const pending = sendRegistrationAction({ url: '/reg', csrfToken: 'tok', request: server.request }, 'p-ana', 'markPaid');
        await settle();
        assert.equal(server.calls.length, 1);
        assert.deepEqual([server.calls[0].url, server.calls[0].method, server.calls[0].body, server.calls[0].csrfToken], ['/reg', 'POST', { participant: 'p-ana', action: 'markPaid' }, 'tok']);
        await server.reply({ kind: 'ok', status: 200, data: { ok: true, version: 'v9', person: { id: 'p-ana', name: 'Ana Example' } } });
        assert.deepEqual(await pending, { ok: true, person: { id: 'p-ana', name: 'Ana Example' }, version: 'v9' });

        const refused = sendRegistrationAction({ url: '/reg', csrfToken: 'tok', request: server.request }, 'p-kim', 'checkIn');
        await settle();
        await server.reply({ kind: 'client', status: 409, data: { error: 'waitlisted_check_in', message: 'On the waitlist.' } });
        assert.deepEqual(await refused, { ok: false, kind: 'client', error: 'waitlisted_check_in', message: 'On the waitlist.' });

        assert.equal((await sendRegistrationAction({ url: '/reg', csrfToken: 'tok', request: server.request }, 'p-ana', 'delete')).ok, false);
        assert.equal(server.calls.length, 2, 'an unknown action is never sent');
    });

    test('the answer\'s row is merged (pending edits stay on top); the known version is never taken from it', async () => {
        const model = new SheetModel(managedState(), { now: () => 0 });
        const server = fakeServer();
        const time = clock();
        const announced = [];
        const deltas = [];
        model.subscribe((delta) => deltas.push(delta));

        // An edit of Ana's name on its way to the server meanwhile
        const edit = setField(model, 'p-ana', 'name', 'Ana Renamed');
        model.applyLocal(edit.groups[0].id, edit.groups[0].changes);

        const pending = performRegistrationAction(context(model, server, announced), 'p-ana', 'markPaid', { request: server.request, schedule: time.schedule });
        await settle();
        assert.equal(model.marks.get('person:p-ana:registration')?.state, 'saving');

        // A second click while the first is on its way is not sent
        assert.equal((await performRegistrationAction(context(model, server, announced), 'p-ana', 'markPaid', { request: server.request, schedule: time.schedule })).kind, 'busy');
        assert.equal(server.calls.length, 1);

        const row = { ...managedState().people[0], registration: reg('paid', { paidAt: '2026-10-08T09:00:00+00:00' }) };
        await server.reply({ kind: 'ok', status: 200, data: { ok: true, version: 'v-after', person: row } });
        await pending;

        assert.equal(model.person('p-ana').registration.status, 'paid');
        assert.equal(model.person('p-ana').name, 'Ana Renamed', 'the pending edit stays shown');
        assert.equal(model.version, 'v1', 'the version is left to the live updates');
        assert.equal(model.marks.get('person:p-ana:registration'), null);
        assert.ok(deltas.some((delta) => delta.people.has('p-ana')));
        assert.deepEqual(announced, ['reg_done_markPaid {"name":"Ana Renamed"}'], 'the name as shown');

        model.revert(edit.groups[0].id);
        assert.equal(model.person('p-ana').name, 'Ana Example');
        assert.equal(model.person('p-ana').registration.status, 'paid', 'the merged row is the base');
    });

    test('a refusal stays on the cell with the server\'s reason for a while; no answer at all is said too', async () => {
        const model = new SheetModel(managedState(), { now: () => 0 });
        const server = fakeServer();
        const time = clock();
        const announced = [];

        const pending = performRegistrationAction(context(model, server, announced), 'p-kim', 'checkIn', { request: server.request, schedule: time.schedule });
        await settle();
        await server.reply({ kind: 'client', status: 409, data: { error: 'waitlisted_check_in', message: 'Give them a spot first.' } });
        await pending;
        assert.deepEqual(model.marks.get('person:p-kim:registration')?.state, 'refused');
        assert.equal(model.marks.get('person:p-kim:registration')?.message, 'Give them a spot first.');
        assert.equal(model.person('p-kim').registration.status, 'waitlisted');
        await time.advance(20000);
        assert.equal(model.marks.get('person:p-kim:registration'), null);

        const offline = performRegistrationAction(context(model, server, announced), 'p-ana', 'markPaid', { request: server.request, schedule: time.schedule });
        await settle();
        await server.reply({ kind: 'offline' });
        assert.equal((await offline).message, 'reg_failed_offline');
        assert.ok(announced.at(-1).endsWith('reg_failed_offline'));
    });

    test('mergePerson adds a person the page did not know at the end', () => {
        const model = new SheetModel(managedState(), { now: () => 0 });
        const delta = model.mergePerson({ id: 'p-new', name: 'Zed New', registration: reg('reserved') });
        assert.equal(delta.rows, true);
        assert.equal(model.people().at(-1).id, 'p-new');
        assert.equal(model.person('p-new').source, 'manual');
        assert.equal(model.mergePerson(null).people.size, 0);
    });
}
