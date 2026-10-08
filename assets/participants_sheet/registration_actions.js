/**
 * Managed registration in the People tab (contract O5, docs/features/competitions-management/registration.md
 * "Organiser tools"): what a person's registration is, which actions it allows, the waitlist order, the counters and the
 * "first in line" hint - all from the people already loaded, no request of their own - and the one way to change a
 * registration: POST `urls.registration` (participants_sheet_registration), which dispatches the existing messages
 * (their rules and e-mails unchanged) and answers the person's row, merged into the model.
 *
 * Rules mirrored from the handlers (they decide; the browser only offers what they would allow):
 * - a row without a status holds a spot (the state already sends it as `reserved`);
 * - Mark paid: a row holding a spot (reserved; again on a row paid before the registration was cancelled);
 *   a waitlisted row only together with a spot (Give a spot and mark paid);
 * - Take back "paid": only from paid;
 * - Give a spot: only from the waitlist (always manual - above the capacity too);
 * - Check in: rows holding a spot, not checked in yet (in-person events only - nobody walks in to an online one);
 *   Undo check-in: a checked-in row.
 *
 * Pure except sendRegistrationAction() (network through official_results_api.js) - pinned by
 * tests/participants-sheet-people-harness.mjs.
 */

import { officialResultsRequest } from '../official_results_api.js';

export const ACTIONS = ['markPaid', 'unmarkPaid', 'promote', 'promoteAndMarkPaid', 'checkIn', 'undoCheckIn'];
export const RESERVED = 'reserved';
export const PAID = 'paid';
export const WAITLISTED = 'waitlisted';

/** reserved | paid | waitlisted - or null when the event does not manage registration (no registration on the row). */
export function registrationStatus(person) {
    const registration = person?.registration ?? null;

    if (registration === null || typeof registration !== 'object') {
        return null;
    }

    return [PAID, WAITLISTED].includes(registration.status) ? registration.status : RESERVED;
}

/** The person holds a spot (reserved or paid, not removed). */
export function holdsSpot(person) {
    const status = registrationStatus(person);

    return status !== null && status !== WAITLISTED && (person.removedAt ?? null) === null;
}

/**
 * "Paid on {date}, before the registration was cancelled": the organiser's record of a payment kept on a row that is
 * not paid now (cancelled and maybe registered again) - the date, else null.
 */
export function paidBefore(person) {
    const registration = person?.registration ?? null;

    if (registration === null || !registration.paidAt) {
        return null;
    }

    return registrationStatus(person) === PAID ? null : registration.paidAt;
}

/**
 * The actions the person's registration allows now, in the order a menu shows them.
 *
 * @param {object} person a state row
 * @param {{checkIn?: boolean}} [options] checkIn = false for an online event (no check-in)
 * @returns {string[]}
 */
export function allowedActions(person, { checkIn = true } = {}) {
    const status = registrationStatus(person);

    if (status === null || (person.removedAt ?? null) !== null) {
        return [];
    }

    const checkedIn = Boolean(person.registration.checkedInAt);
    const actions = [];

    if (status === WAITLISTED) {
        actions.push('promote', 'promoteAndMarkPaid');
    } else if (status === PAID) {
        actions.push('unmarkPaid');
    } else {
        actions.push('markPaid');
    }

    if (checkIn && checkedIn) {
        actions.push('undoCheckIn');
    } else if (checkIn && status !== WAITLISTED) {
        actions.push('checkIn');
    }

    return actions;
}

/** FIFO of the waitlist: registered first (a row without a date first, like the server's `?? ''`), then by id. */
function waitlistOrder(a, b) {
    const atA = a.registration?.registeredAt ?? '';
    const atB = b.registration?.registeredAt ?? '';
    const timeA = atA === '' ? -Infinity : Date.parse(atA);
    const timeB = atB === '' ? -Infinity : Date.parse(atB);

    if (timeA !== timeB) {
        return timeA < timeB ? -1 : 1;
    }

    return a.id < b.id ? -1 : (a.id > b.id ? 1 : 0);
}

/**
 * Map personId → position on the waitlist (1 = first in line), active people only.
 *
 * @param {object[]} people state rows
 */
export function waitlistPositions(people) {
    const waiting = people.filter((person) => registrationStatus(person) === WAITLISTED && (person.removedAt ?? null) === null);
    waiting.sort(waitlistOrder);

    return new Map(waiting.map((person, index) => [person.id, index + 1]));
}

/**
 * The counters above the grid: spots taken (reserved + paid - rows without a status count as reserved), the capacity,
 * free spots, the waitlist, paid, checked in. Active people only.
 *
 * @param {object[]} people
 * @param {number|null} capacity
 */
export function registrationCounts(people, capacity = null) {
    const counts = { reserved: 0, paid: 0, waitlisted: 0, taken: 0, checkedIn: 0, capacity: Number.isInteger(capacity) ? capacity : null, free: null, over: false };

    for (const person of people) {
        if ((person.removedAt ?? null) !== null) {
            continue;
        }

        const status = registrationStatus(person);

        if (status === null) {
            continue;
        }

        counts[status]++;

        if (person.registration.checkedInAt && status !== WAITLISTED) {
            counts.checkedIn++;
        }
    }

    counts.taken = counts.reserved + counts.paid;

    if (counts.capacity !== null) {
        counts.free = Math.max(0, counts.capacity - counts.taken);
        counts.over = counts.taken > counts.capacity;
    }

    return counts;
}

/**
 * The first person on the waitlist when a spot is free (no capacity, or fewer spots taken than it) - the hint "A spot
 * is free - Robin Example is first on the waitlist · Give a spot". null otherwise.
 */
export function firstInLine(people, capacity = null) {
    const counts = registrationCounts(people, capacity);

    if (counts.waitlisted === 0 || (counts.capacity !== null && counts.taken >= counts.capacity)) {
        return null;
    }

    const positions = waitlistPositions(people);
    const firstId = [...positions].find(([, position]) => position === 1)?.[0] ?? null;

    return people.find((person) => person.id === firstId) ?? null;
}

/**
 * The filters of the People tab that read the registration: waitlist, not paid (holding a spot, not paid), checked in,
 * not checked in (holding a spot).
 */
export function matchesRegistrationFilter(person, filter) {
    const status = registrationStatus(person);

    switch (filter) {
        case 'waitlist':
            return status === WAITLISTED;
        case 'not_paid':
            return status === RESERVED;
        case 'checked_in':
            return status !== null && Boolean(person.registration.checkedInAt);
        case 'not_checked_in':
            return status !== null && status !== WAITLISTED && !person.registration.checkedInAt;
        default:
            return true;
    }
}

/**
 * One registration action → POST urls.registration. Resolves (never rejects) to
 * `{ok: true, person, version}` or `{ok: false, kind, error, message}` (`message` = the server's translated refusal,
 * null when the server never answered - the caller says so).
 *
 * @param {{url: string, csrfToken: string, request?: function}} endpoint
 * @param {string} personId
 * @param {string} action one of ACTIONS
 */
export async function sendRegistrationAction({ url, csrfToken, request = officialResultsRequest }, personId, action) {
    if (!ACTIONS.includes(action)) {
        return { ok: false, kind: 'client', error: 'invalid_request', message: null };
    }

    const answer = await request(url, { method: 'POST', body: { participant: personId, action }, csrfToken });

    if (answer.kind === 'ok' && answer.data?.ok === true && answer.data.person && typeof answer.data.person === 'object') {
        return { ok: true, person: answer.data.person, version: answer.data.version ?? null };
    }

    return {
        ok: false,
        kind: answer.kind,
        error: answer.data?.error ?? null,
        message: typeof answer.data?.message === 'string' ? answer.data.message : null,
    };
}

/**
 * The answer of a registration action merged into the model: the person's row as the server has it now. The sheet's
 * version is left to the live updates (the stream / the version check fetch the state when it moved): the answer's
 * version may already contain somebody else's change the page has not seen.
 */
export function applyRegistrationAnswer(model, answer) {
    if (answer?.ok === true) {
        return model.mergePerson(answer.person);
    }

    return null;
}

export const REGISTRATION_MARK_MS = 20000;
const inFlight = new WeakMap();

/**
 * A registration action from the sheet (the grid's menu, the first-in-line hint, the person editor): the cell shows
 * "Saving" (marker `person:<id>:registration`), the answer's row is merged, the result is said aloud; a refusal stays on
 * the cell with the server's reason for a while. One action per person at a time.
 *
 * @param {object} context the view context (model, urls, csrfToken, texts, announce)
 * @param {string} personId
 * @param {string} action
 * @param {{request?: function, schedule?: function}} [options]
 * @returns {Promise<object>} the sendRegistrationAction() answer ({ok: false, kind: 'busy'} while one is on its way)
 */
export async function performRegistrationAction(context, personId, action, { request = officialResultsRequest, schedule = (task, ms) => setTimeout(task, ms) } = {}) {
    const { model } = context;
    const say = (key, params) => context.texts.people.t(key, params);
    const busy = inFlight.get(model) ?? new Set();
    inFlight.set(model, busy);

    if (busy.has(personId)) {
        return { ok: false, kind: 'busy', error: null, message: null };
    }

    const key = `person:${personId}:registration`;
    const name = model.person(personId)?.name ?? '';
    busy.add(personId);
    model.marks.set(key, { state: 'saving' }, { people: [personId] });

    let answer;

    try {
        answer = await sendRegistrationAction({ url: context.urls.registration, csrfToken: context.csrfToken, request }, personId, action);
    } finally {
        busy.delete(personId);
    }

    if (answer.ok) {
        model.marks.set(key, null);
        applyRegistrationAnswer(model, answer);
        context.announce(say(`reg_done_${action}`, { name }));

        return answer;
    }

    let message = answer.message;

    if (message === null) {
        message = say(answer.kind === 'auth' ? 'reg_failed_auth' : (answer.kind === 'offline' || answer.kind === 'server' ? 'reg_failed_offline' : 'reg_failed'));
    }

    model.marks.set(key, { state: 'refused', message }, { people: [personId] });
    schedule(() => {
        if (model.marks.get(key)?.state === 'refused' && model.marks.get(key)?.message === message) {
            model.marks.set(key, null);
        }
    }, REGISTRATION_MARK_MS);
    context.announce(`${name}: ${message}`);

    return { ...answer, message };
}
