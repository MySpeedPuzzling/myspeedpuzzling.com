/**
 * The results desk's unsaved changes - one cell per entry field (result, table number, qualified) - and what the
 * server said about them (docs/features/competitions-management/results-desk.md). Pinned by
 * tests/OfficialResultsDeskHelpersTest.php.
 *
 * A cell shows the organiser's value (`to`) until the server answered `applied` or `unchanged` for exactly that
 * value; nothing else ever counts as saved - a lost connection, a login redirect, a 5xx or a refused change keep the
 * cell (and the desk shows it as not saved). `base` is the server value the organiser saw when they changed the field:
 * it is sent as `from`, so a change somebody else saved meanwhile comes back as a conflict instead of being
 * overwritten.
 *
 * Statuses: queued (to send) · sending · conflict (somebody else saved another value) · error (refused, `message`).
 *
 * Groups (competition toolkit A4): cells set with the same `group` - a `pick` round entry's `result` and `puzzles` -
 * travel in one request or not at all (the server applies them together, A rule 7): take() sends a group only when every
 * cell of it is queued and fits the request, and keep mine / try again / discard on one cell act on its whole group.
 * A cell without a group behaves exactly as before.
 *
 * The functions before the class are the inline editors' half (openEditor … saveEditor): what the organiser saw when
 * an editor opened is the `from` of its save.
 */

export function sameValue(a, b) {
    return JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
}

/**
 * An inline editor of the desk (a result, a table number) opened on a field: `seen` is the value the organiser saw
 * when they started changing it - their own unsaved value, else the server's. It is what the save sends as `from`,
 * never the server's value at save time: a live update arriving while the editor is open is somebody else's change
 * and must come back as a conflict, not be overwritten (browser verification, BLOCKER 1).
 */
export function openEditor(pending, ref, field, serverValue) {
    return { ref, field, seen: pending.value(ref, field, serverValue) };
}

/**
 * What somebody else saved while the editor was open - shown next to it ("Saved meanwhile by … · Keep mine / Take
 * theirs") - or null. `server` = {value, enteredBy} of the field as the desk has it now.
 */
export function savedMeanwhile(pending, editor, server) {
    const cell = pending.get(editor.ref, editor.field);

    if (cell !== null) {
        // The organiser's own earlier value is on its way or waits - only a refusal as a conflict is news
        return cell.status === 'conflict' ? { current: cell.conflict.current, enteredBy: cell.conflict.enteredBy ?? null } : null;
    }

    if (sameValue(server.value, editor.seen)) {
        return null;
    }

    return { current: server.value ?? null, enteredBy: server.enteredBy ?? null };
}

/**
 * "Keep mine" next to the editor: the organiser has seen the other value - their save goes over it.
 */
export function keepMineInEditor(pending, editor, serverValue) {
    const cell = pending.get(editor.ref, editor.field);

    if (cell !== null && cell.status === 'conflict') {
        const theirs = cell.conflict.current ?? null;
        pending.keepMine(editor.ref, editor.field);

        return { ...editor, seen: theirs };
    }

    return { ...editor, seen: serverValue };
}

/**
 * The editor's value saved: `from` is what the organiser saw (an existing cell keeps its own base).
 */
export function saveEditor(pending, editor, to) {
    pending.set(editor.ref, editor.field, to, editor.seen);
}

export class PendingChanges {
    constructor() {
        this.cells = new Map();
    }

    static key(ref, field) {
        return `${ref}|${field}`;
    }

    get(ref, field) {
        return this.cells.get(PendingChanges.key(ref, field)) ?? null;
    }

    /**
     * What the desk shows for a field: the organiser's unsaved value, else the server's.
     */
    value(ref, field, serverValue) {
        const cell = this.get(ref, field);

        return cell === null ? serverValue : cell.to;
    }

    /**
     * The organiser changed a field. `serverValue` = the field as the desk has it from the server right now. `group`
     * (optional) = the cells that must be sent together with this one (see the header).
     */
    set(ref, field, to, serverValue, group = null) {
        const key = PendingChanges.key(ref, field);
        const cell = this.cells.get(key);

        if (cell === undefined) {
            if (!sameValue(to, serverValue)) {
                this.cells.set(key, { ref, field, base: serverValue, to, status: 'queued', changeId: null, inFlight: null, conflict: null, message: null, ...(group !== null ? { group } : {}) });
            }

            return;
        }

        if (group !== null) {
            cell.group = group;
        }

        if (cell.status === 'conflict' && cell.inFlight === null) {
            // A new value typed over a conflict: the organiser has seen what somebody else saved
            cell.base = cell.conflict.current;
        }

        cell.to = to;
        cell.conflict = null;
        cell.message = null;

        if (cell.inFlight !== null) {
            // Sent already - the answer decides; the new value follows it
            return;
        }

        if (sameValue(to, cell.base)) {
            this.cells.delete(key);

            return;
        }

        cell.status = 'queued';
    }

    /**
     * The changes to send now (queued cells), marked as in flight. A cell sent again with the same value keeps its
     * change id - a replay of the same change.
     *
     * @param {() => string} newId
     * @param {number} [limit]
     * @param {function(object): boolean} [accept] only the queued cells it accepts (the participants sheet's queue
     *        keeps a cell back until the sheet change it depends on was saved)
     * @returns {Array<{clientChangeId: string, entry: string, field: string, from: *, to: *}>}
     */
    take(newId, limit = 500, accept = null) {
        const changes = [];
        const sendable = (cell) => cell.status === 'queued' && cell.inFlight === null && (accept === null || accept(cell));

        for (const cell of this.cells.values()) {
            if (changes.length >= limit) {
                break;
            }

            if (cell.inFlight !== null || !sendable(cell)) {
                continue;
            }

            if (cell.group === undefined) {
                changes.push(this.send(cell, newId));

                continue;
            }

            // A group goes whole or waits: every cell of it queued (none on its way, refused or in conflict) and room
            // for all of them in this request (a group bigger than a request still goes alone)
            const members = this.groupOf(cell);

            if (members[0] !== cell || !members.every(sendable)) {
                continue;
            }

            if (changes.length > 0 && changes.length + members.length > limit) {
                break;
            }

            members.forEach((member) => changes.push(this.send(member, newId)));
        }

        return changes;
    }

    /** One cell marked as in flight - its change as sent. A cell sent again with the same value keeps its change id. */
    send(cell, newId) {
        if (cell.changeId === null || !sameValue(cell.changeTo, cell.to) || !sameValue(cell.changeFrom, cell.base)) {
            cell.changeId = newId();
            cell.changeTo = cell.to;
            cell.changeFrom = cell.base;
        }

        cell.inFlight = { clientChangeId: cell.changeId, to: cell.to };
        cell.status = 'sending';

        return { clientChangeId: cell.changeId, entry: cell.ref, field: cell.field, from: cell.base, to: cell.to };
    }

    /** The cells sent together with `cell` (itself included, in the order they were set) - just itself without a group. */
    groupOf(cell) {
        if (cell.group === undefined) {
            return [cell];
        }

        return [...this.cells.values()].filter((candidate) => candidate.group === cell.group);
    }

    /**
     * The server's outcome of one sent change (official_results_record).
     */
    settle(outcome) {
        const cell = [...this.cells.values()].find((candidate) => candidate.inFlight?.clientChangeId === outcome.clientChangeId);

        if (cell === undefined) {
            return;
        }

        const sentTo = cell.inFlight.to;
        cell.inFlight = null;

        if (outcome.status === 'applied' || outcome.status === 'unchanged') {
            if (sameValue(cell.to, sentTo)) {
                this.cells.delete(PendingChanges.key(cell.ref, cell.field));

                return;
            }

            // Changed again while the first value was on its way
            cell.base = sentTo;
            cell.status = 'queued';

            return;
        }

        if (outcome.status === 'conflict') {
            cell.status = 'conflict';
            cell.conflict = {
                current: outcome.current ?? null,
                enteredBy: outcome.enteredBy ?? null,
                enteredAt: outcome.enteredAt ?? null,
            };

            return;
        }

        cell.status = 'error';
        cell.message = outcome.message ?? null;
        cell.reason = outcome.reason ?? null;
    }

    /**
     * The whole request did not get through (offline, 5xx, signed out): nothing of it was saved, send it again later.
     */
    retryInFlight() {
        for (const cell of this.cells.values()) {
            if (cell.inFlight !== null) {
                cell.inFlight = null;
                cell.status = 'queued';
            }
        }
    }

    /**
     * The whole request was refused (4xx): nothing of it was saved, the organiser decides what to do.
     */
    failInFlight(message) {
        for (const cell of this.cells.values()) {
            if (cell.inFlight !== null) {
                cell.inFlight = null;
                cell.status = 'error';
                cell.message = message;
            }
        }
    }

    /**
     * Keep mine: the organiser's value goes again, now knowing what somebody else saved.
     */
    keepMine(ref, field) {
        const cell = this.get(ref, field);

        if (cell === null || cell.status !== 'conflict') {
            return;
        }

        // Its group goes again with it: a partner in conflict keeps the organiser's value too, a refused one is retried
        for (const member of this.groupOf(cell)) {
            if (member === cell) {
                continue;
            }

            if (member.status === 'conflict') {
                this.keepOne(member);
            } else if (member.status === 'error') {
                member.status = 'queued';
                member.message = null;
            }
        }

        this.keepOne(cell);
    }

    keepOne(cell) {
        const { ref, field } = cell;
        cell.base = cell.conflict.current;
        cell.conflict = null;

        if (sameValue(cell.to, cell.base)) {
            this.cells.delete(PendingChanges.key(ref, field));

            return;
        }

        cell.status = 'queued';
    }

    /**
     * Take theirs / discard: forget the organiser's value (never a cell that is on its way).
     */
    discard(ref, field) {
        const cell = this.get(ref, field);

        if (cell === null || cell.inFlight !== null) {
            return;
        }

        // A group is never half kept: its partners go too (unless one is on its way - its answer decides)
        for (const member of this.groupOf(cell)) {
            if (member.inFlight === null) {
                this.cells.delete(PendingChanges.key(member.ref, member.field));
            }
        }
    }

    /**
     * An error cell sent once more as it is.
     */
    retry(ref, field) {
        const cell = this.get(ref, field);

        if (cell === null || cell.status !== 'error') {
            return;
        }

        for (const member of this.groupOf(cell)) {
            if (member.status === 'error') {
                member.status = 'queued';
                member.message = null;
            }
        }
    }

    list() {
        return [...this.cells.values()];
    }

    counts() {
        const counts = { queued: 0, sending: 0, conflict: 0, error: 0, total: this.cells.size };

        for (const cell of this.cells.values()) {
            counts[cell.status]++;
        }

        return counts;
    }

    hasQueued() {
        return this.list().some((cell) => cell.status === 'queued' && cell.inFlight === null);
    }

    isEmpty() {
        return this.cells.size === 0;
    }
}
