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
 * Several people at once (the bulk bar's Mark paid / Check in): bulkRegistrationPlan() says who the action applies to and
 * how many e-mails it sends, runRegistrationBulk() sends one request after the other through the same endpoint (never a
 * parallel storm), stopping when the organiser is signed out or may not change the event any more.
 *
 * Pure except sendRegistrationAction() / performRegistrationAction() / runRegistrationBulk() (network through
 * official_results_api.js) - pinned by tests/participants-sheet-people-harness.mjs.
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
 * not paid now - cancelled (removed from the event, the status may still say paid) and maybe registered again - the
 * date, else null.
 */
export function paidBefore(person) {
    const registration = person?.registration ?? null;

    if (registration === null || !registration.paidAt) {
        return null;
    }

    return registrationStatus(person) === PAID && (person.removedAt ?? null) === null ? null : registration.paidAt;
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

/** The server's own place in the line (`registration.waitlistPosition`, 1-based, its FIFO order) - null without one. */
function serverPosition(person) {
    const position = person.registration?.waitlistPosition;

    return Number.isInteger(position) && position > 0 ? position : null;
}

/**
 * Map personId → position on the waitlist (1 = first in line), active people only. The server's order
 * (`registration.waitlistPosition` - it compares the registration times to the microsecond, the state only sends them
 * to the second) when the rows carry it, the browser's FIFO otherwise (rows without one after those with one);
 * numbered again from 1, so a person who got a spot meanwhile closes the gap at once.
 *
 * @param {object[]} people state rows
 */
export function waitlistPositions(people) {
    const waiting = people.filter((person) => registrationStatus(person) === WAITLISTED && (person.removedAt ?? null) === null);
    waiting.sort((a, b) => {
        const positionA = serverPosition(a);
        const positionB = serverPosition(b);

        if (positionA !== positionB) {
            if (positionA === null) {
                return 1;
            }

            if (positionB === null) {
                return -1;
            }

            return positionA - positionB;
        }

        return waitlistOrder(a, b);
    });

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
 * The filters of the People tab that read the registration (the old status filter: Reserved / Paid / Waitlist):
 * waitlist, not paid (holding a spot, not paid), paid, checked in, not checked in (holding a spot).
 */
export function matchesRegistrationFilter(person, filter) {
    const status = registrationStatus(person);

    switch (filter) {
        case 'waitlist':
            return status === WAITLISTED;
        case 'not_paid':
            return status === RESERVED;
        case 'paid':
            return status === PAID;
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
// How long an action waits for the organiser's own edits of that person to be saved first
export const WAIT_FOR_SAVES_MS = 15000;
const inFlight = new WeakMap();

/** The organiser's edits of a person still on their way to the server (a new person not saved yet included). */
export function hasPendingFor(model, personId) {
    return (model.pending ?? []).some((group) => group.changes.some((change) => change.participant === personId || (change.op === 'newParticipant' && change.id === personId)));
}

/**
 * Resolves true once nothing of the organiser's own about the person waits to be saved (sent at once - the queue's
 * debounce is not waited for), false when it still waits after `timeoutMs` (offline, a server error being retried): a
 * registration change must never reach the server before the person it is about (a person added on the page) or
 * overtake an edit of them.
 */
export function waitForSaves(context, personId, { timeoutMs = WAIT_FOR_SAVES_MS, schedule = (task, ms) => setTimeout(task, ms), cancel = (id) => clearTimeout(id) } = {}) {
    const { model } = context;

    if (!hasPendingFor(model, personId)) {
        return Promise.resolve(true);
    }

    context.queue?.flushNow?.();

    return new Promise((resolve) => {
        const stops = [];
        let timer = null;
        const finish = (value) => {
            stops.forEach((stop) => stop?.());
            stops.length = 0;
            cancel(timer);
            resolve(value);
        };
        const check = () => {
            if (!hasPendingFor(model, personId)) {
                finish(true);
            }
        };

        // A confirmed group may change nothing the views read (no model delta): the queue's outcome tells it too
        stops.push(model.subscribe(check));

        if (typeof context.queue?.subscribe === 'function') {
            stops.push(context.queue.subscribe(check));
        }

        timer = schedule(() => finish(!hasPendingFor(model, personId)), timeoutMs);
    });
}

/** A refusal's words when the server said nothing itself (no answer, signed out, no rights, busy). */
export function failureText(answer, say) {
    if (answer.kind === 'auth') {
        return say('reg_failed_auth');
    }

    if (answer.kind === 'forbidden') {
        return say('reg_failed_forbidden');
    }

    if (answer.kind === 'server' && answer.busy === true) {
        return say('reg_failed_busy');
    }

    if (answer.kind === 'offline' || answer.kind === 'server') {
        return say('reg_failed_offline');
    }

    return say('reg_failed');
}

/** Shown (a toast when the page has one - `context.notify`) and read out; only read out on a page without toasts. */
function tell(context, text, options) {
    if (typeof context.notify === 'function') {
        context.notify(text, options);
    } else {
        context.announce(text);
    }
}

/**
 * A registration action from the sheet (the grid's menu, the first-in-line hint, the person editor, the bulk bar): the
 * cell shows "Saving" (marker `person:<id>:registration`) - first while the organiser's own edits of that person are
 * saved -, the answer's row is merged, the result is said; a refusal stays on the cell with the server's reason for a
 * while and is shown (`context.notify`). One action per person at a time.
 *
 * @param {object} context the view context (model, queue, urls, csrfToken, texts, announce, notify?)
 * @param {string} personId
 * @param {string} action
 * @param {{request?: function, schedule?: function, cancel?: function, quiet?: boolean, anchor?: object|null}} [options]
 *        quiet = nothing said or shown (the bulk bar sums up itself); anchor = where a refusal's toast points
 * @returns {Promise<object>} the sendRegistrationAction() answer ({ok: false, kind: 'busy'} while one is on its way,
 *          {ok: false, kind: 'unsaved'} when the person's edits could not be saved first)
 */
export async function performRegistrationAction(context, personId, action, { request = officialResultsRequest, schedule = (task, ms) => setTimeout(task, ms), cancel = (id) => clearTimeout(id), quiet = false, anchor = null } = {}) {
    const { model } = context;
    const say = (key, params) => context.texts.people.t(key, params);
    const busy = inFlight.get(model) ?? new Set();
    inFlight.set(model, busy);

    if (busy.has(personId)) {
        return { ok: false, kind: 'busy', error: null, message: null };
    }

    const key = `person:${personId}:registration`;
    busy.add(personId);
    model.marks.set(key, { state: 'saving' }, { people: [personId] });

    let answer;

    try {
        if (await waitForSaves(context, personId, { schedule, cancel })) {
            answer = await sendRegistrationAction({ url: context.urls.registration, csrfToken: context.csrfToken, request }, personId, action);
        } else {
            answer = { ok: false, kind: 'unsaved', error: null, message: say('reg_failed_unsaved', { name: model.person(personId)?.name ?? '' }) };
        }
    } finally {
        busy.delete(personId);
    }

    // The name as shown when the answer came (a rename meanwhile included)
    const name = model.person(personId)?.name ?? '';

    if (answer.ok) {
        model.marks.set(key, null);
        applyRegistrationAnswer(model, answer);

        if (!quiet) {
            context.announce(say(`reg_done_${action}`, { name }));
        }

        return answer;
    }

    const message = answer.message ?? failureText(answer, say);

    model.marks.set(key, { state: 'refused', message }, { people: [personId] });
    schedule(() => {
        if (model.marks.get(key)?.state === 'refused' && model.marks.get(key)?.message === message) {
            model.marks.set(key, null);
        }
    }, REGISTRATION_MARK_MS);

    if (!quiet) {
        tell(context, `${name}: ${message}`, anchor ? { kind: 'error', anchor } : { kind: 'error' });
    }

    return { ...answer, message };
}

// ---------------------------------------------------------------- several people at once (the bulk bar)

/** The actions the bulk bar offers for the selected people of a managed event. */
export const BULK_ACTIONS = ['markPaid', 'checkIn'];

/**
 * Who of `people` the action applies to (their registration allows it now), who is left out, and how many e-mails it
 * sends: Mark paid sends the payment confirmation to every person linked to a MySpeedPuzzling account (the mailer skips
 * the others), Check in sends none.
 *
 * @param {object[]} people
 * @param {string} action markPaid | checkIn
 * @param {{checkIn?: boolean}} [options] checkIn = false for an online event
 * @returns {{eligible: string[], skipped: string[], emails: number}}
 */
export function bulkRegistrationPlan(people, action, { checkIn = true } = {}) {
    const eligible = [];
    const skipped = [];
    let emails = 0;

    for (const person of people) {
        if (BULK_ACTIONS.includes(action) && allowedActions(person, { checkIn }).includes(action)) {
            eligible.push(person.id);

            if (action === 'markPaid' && person.player !== null && person.player !== undefined) {
                emails++;
            }
        } else {
            skipped.push(person.id);
        }
    }

    return { eligible, skipped, emails };
}

/**
 * One request after the other (performRegistrationAction(), quiet) for `personIds`; `onProgress(done, total)` after each
 * answer. Stops for good when the organiser is signed out or may not change the event any more (the rest would be
 * refused the same way), or when `control.stopped` is set (the organiser's Stop).
 *
 * @returns {Promise<{done: string[], failed: Array<{id: string, message: string}>, stopped: null|'auth'|'forbidden'|'user', notSent: string[], stopMessage: string|null}>}
 */
export async function runRegistrationBulk(context, personIds, action, { onProgress = () => {}, control = { stopped: false }, ...options } = {}) {
    const result = { done: [], failed: [], stopped: null, notSent: [], stopMessage: null };

    for (let index = 0; index < personIds.length; index++) {
        if (control.stopped) {
            result.stopped = 'user';
            result.notSent = personIds.slice(index);

            break;
        }

        const id = personIds[index];
        const answer = await performRegistrationAction(context, id, action, { ...options, quiet: true });

        if (answer.ok) {
            result.done.push(id);
        } else {
            result.failed.push({ id, message: answer.message ?? '' });
        }

        onProgress(index + 1, personIds.length);

        if (!answer.ok && (answer.kind === 'auth' || answer.kind === 'forbidden')) {
            result.stopped = answer.kind;
            result.stopMessage = answer.message ?? null;
            result.notSent = personIds.slice(index + 1);

            break;
        }
    }

    return result;
}
