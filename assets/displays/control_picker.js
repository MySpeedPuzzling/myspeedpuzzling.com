// The live control of an event's displays (competition toolkit v2 L1/L2, displays-standings.md §5.3) - pure, no DOM:
// the message rules (the twin of CompetitionDisplayControl::cleanMessage() and its length check), the spotlight picker's
// chips (sorted by table, filtered by table or name, "Just finished") and the optimistic choice of one tap. Used by
// assets/controllers/display_control_controller.js (the live control page and the stopwatch page's card); run under
// node by tests/display-control-harness.mjs.

export const MESSAGE_MAX_LENGTH = 200;
export const JUST_FINISHED = 6;

// Line breaks, tabs and every other control character become one space - a message is one line on a stream
const CONTROL_CHARACTERS = /[\u0000-\u001F\u007F-\u009F]+/g;

/**
 * The message as the server stores it: one line, trimmed.
 */
export function cleanMessage(text) {
    return typeof text === 'string' ? text.replace(CONTROL_CHARACTERS, ' ').trim() : '';
}

/**
 * Why a message cannot be set - null when it can. Characters as the server counts them (code points, mb_strlen).
 */
export function messageProblem(text) {
    return Array.from(cleanMessage(text)).length > MESSAGE_MAX_LENGTH ? 'too_long' : null;
}

/**
 * The recent messages offered as one-tap chips - the one on screen now is left out.
 */
export function recentChips(recentMessages, current) {
    return (Array.isArray(recentMessages) ? recentMessages : []).filter((text) => typeof text === 'string' && text !== '' && text !== current);
}

// ---- spotlight (L2)

function fold(text) {
    return String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

/**
 * "12 · Jane Doe" - the table first, the way the hall finds people.
 */
export function entryLabel(entry) {
    const name = typeof entry?.name === 'string' ? entry.name : '';

    return typeof entry?.table === 'number' ? `${entry.table} · ${name}` : name;
}

/**
 * By table (none last), then by name.
 */
export function sortEntries(entries) {
    return [...(Array.isArray(entries) ? entries : [])].sort((a, b) => {
        const tableA = typeof a.table === 'number' ? a.table : Number.MAX_SAFE_INTEGER;
        const tableB = typeof b.table === 'number' ? b.table : Number.MAX_SAFE_INTEGER;

        return tableA - tableB || fold(a.name).localeCompare(fold(b.name)) || String(a.ref).localeCompare(String(b.ref));
    });
}

/**
 * Digits = tables starting with them ("1" → 1, 12, 15 …); anything else = names (members of a pair/team too), without
 * accents or case.
 */
export function filterEntries(entries, query) {
    const wanted = fold(query);

    if (wanted === '') {
        return entries;
    }

    if (/^\d+$/.test(wanted)) {
        return entries.filter((entry) => typeof entry.table === 'number' && String(entry.table).startsWith(wanted));
    }

    return entries.filter((entry) => [entry.name, ...(Array.isArray(entry.members) ? entry.members : [])]
        .some((name) => fold(name).includes(wanted)));
}

/**
 * The newest results first - the people a stream wants to show right now.
 */
export function justFinished(entries, count = JUST_FINISHED) {
    return (Array.isArray(entries) ? entries : [])
        .filter((entry) => typeof entry.finishedAtMs === 'number')
        .sort((a, b) => b.finishedAtMs - a.finishedAtMs)
        .slice(0, count);
}

/**
 * The spotlight one tap sets: shown at once (optimistic), kept when the server agrees, back to what it was when not.
 * One write at a time - a tap while one is on its way waits and goes next (the last tap wins).
 */
export class SpotlightChoice {
    constructor(current = null) {
        this.confirmed = current;
        this.sending = undefined;
        this.queued = undefined;
    }

    /**
     * What the picker highlights now.
     */
    get shown() {
        if (this.queued !== undefined) {
            return this.queued;
        }

        return this.sending !== undefined ? this.sending : this.confirmed;
    }

    /**
     * A tap on a chip (a ref) or on Clear (null): the ref to send now, or undefined (nothing to send now - it is shown
     * already, or it waits for the write on its way).
     */
    tap(ref) {
        if (ref === this.shown) {
            return undefined;
        }

        if (this.sending !== undefined) {
            this.queued = ref;

            return undefined;
        }

        this.sending = ref;

        return ref;
    }

    /**
     * The write is done (ok = the server took it): the next ref to send, or undefined.
     */
    settle(ok) {
        if (ok) {
            this.confirmed = this.sending;
        }

        this.sending = undefined;
        const next = this.queued;
        this.queued = undefined;

        return next !== undefined && next !== this.confirmed ? this.tap(next) : undefined;
    }

    /**
     * The server's state (a refresh): taken unless a write is on its way.
     */
    sync(current) {
        if (this.sending === undefined && this.queued === undefined) {
            this.confirmed = current;
        }
    }
}
