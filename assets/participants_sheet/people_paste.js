/**
 * Adding people by pasting names into the People tab (docs/features/competitions-management/participants-spreadsheet.md
 * §6 "Bigger actions get a preview", D9): a block pasted onto the new-person row (or the rows of a block that reach
 * past the end of the list) is read as one person per line -
 *
 *   name
 *   name ⇥ country
 *   name ⇥ country ⇥ external id
 *
 * - every line is matched by its name key (ParticipantNameKey - case, white space, accents, apostrophes and dash kinds do
 *   not matter) against the event's active people ("already on the list" - left out, listed) and its removed people
 *   ("restore?" - an unticked choice);
 * - a name met earlier in the same paste is listed as a duplicate and added once;
 * - everything else is a new person, ticked - **unless it looks like a mistake** (business review BR9): a name close to
 *   somebody on the list ("Did you mean Kim Example?" - the import's rule: both name keys at least 6 characters, 1-2
 *   edits apart), or a value that is a country code, a number or an e-mail address (columns pasted in another order)
 *   is offered unticked with the reason; a country nobody knows is left out of the line (said in its note), a name or
 *   an external id the server would refuse makes the line not possible;
 * - a first line that is a header (the sheet's own column names) is left out.
 *
 * planNamePaste() reads the block, namePasteAction() turns the organiser's ticks into one action (one undo step, a
 * group per person - one refused name never blocks the others). With `roundId` (the preview's "and put them into ▾ a
 * solo round", BR3) the new and restored people go into that round in their own group, and the people already on the
 * list are put into it too (a group each) - the WJPC way: a group's list pasted in, matched, confirmed once.
 * Pure - pinned by tests/participants-sheet-people-harness.mjs.
 */

import { newClientId } from '../official_results_api.js';
import { foldSearchText } from '../search_fold.js';
import { buildAction } from './sheet_changes.js';
import { EXTERNAL_ID_MAX_LENGTH, NAME_MAX_LENGTH, cleanName, cleanOptionalText, nameKey, textLength } from './sheet_model.js';
import { trimCell } from './tsv.js';

export const LINE_NEW = 'new';
export const LINE_EXISTING = 'existing';
export const LINE_REMOVED = 'removed';
export const LINE_DUPLICATE = 'duplicate';
export const LINE_INVALID = 'invalid';
export const LINE_HEADER = 'header';

// Why a new name is offered unticked (BR9)
export const HINT_CLOSE = 'close';
export const HINT_COUNTRY = 'country';
export const HINT_NUMBER = 'number';
export const HINT_EMAIL = 'email';

// The import's similar-name rule (PlanBuilder, warning.similar_name): keys of at least this length, at most this many edits
export const CLOSE_MIN_LENGTH = 6;
export const CLOSE_MAX_EDITS = 2;

// What a placement into the chosen round means for a line (the preview's notes)
export const INTO_PUT = 'put';
export const INTO_ALREADY = 'already';
export const INTO_AMBIGUOUS = 'ambiguous';

/**
 * @typedef {object} PasteLine
 * @property {string} id                 `l<index>` - the preview's line id
 * @property {number} index              the line of the block (0-based)
 * @property {string} name               cleaned like the server cleans it
 * @property {string|null} country       a CountryCode name, or null
 * @property {string|null} countryText   what was typed when it is not a country we know (left out)
 * @property {string|null} externalId
 * @property {'new'|'existing'|'removed'|'duplicate'|'invalid'|'header'} status
 * @property {string[]} matches          ids of the people the name matches (active ones for `existing`, removed ones for `removed`)
 * @property {string|null} reason        `name_too_long` | `external_id_too_long` | `name_blank` for an invalid line
 * @property {number|null} sameAs        the earlier line of the paste with the same name (duplicates)
 * @property {{kind: 'close'|'country'|'number'|'email', ids?: string[]}|null} hint  why a new name is not ticked (BR9)
 * @property {boolean} tick              ticked by default (new people yes - unless hinted -, restoring no)
 */

/**
 * The edit distance of two strings (code points) when it is at most `max` - else `max + 1` (a banded Levenshtein: a
 * paste of 200 names checked against 2,000 people stays a few milliseconds).
 */
export function boundedDistance(a, b, max = CLOSE_MAX_EDITS) {
    const left = [...a];
    const right = [...b];

    if (Math.abs(left.length - right.length) > max) {
        return max + 1;
    }

    let previous = Array.from({ length: right.length + 1 }, (_, index) => index);

    for (let i = 1; i <= left.length; i++) {
        const current = [i];
        let best = i;

        for (let j = 1; j <= right.length; j++) {
            const value = Math.min(
                previous[j] + 1,
                current[j - 1] + 1,
                previous[j - 1] + (left[i - 1] === right[j - 1] ? 0 : 1),
            );
            current.push(value);
            best = Math.min(best, value);
        }

        if (best > max) {
            return max + 1;
        }

        previous = current;
    }

    return Math.min(previous[right.length], max + 1);
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/u;
const NUMBER = /^[\d\s.,:;'"+\-–/()#%]+$/u;
const LETTERS_2_3 = /^\p{L}{2,3}$/u;

/**
 * A pasted "name" that is no name: an e-mail address, a number (a time, a table number, an id), a country code (columns
 * pasted in another order) - the hint kind, or null.
 *
 * @param {string} name cleaned
 * @param {function(string): (string|null|undefined)} readCountry
 */
export function looksLikeNoName(name, readCountry = () => undefined) {
    if (EMAIL.test(name)) {
        return HINT_EMAIL;
    }

    if (NUMBER.test(name) && /\d/u.test(name)) {
        return HINT_NUMBER;
    }

    if (LETTERS_2_3.test(name)) {
        const code = readCountry(name);

        if (code !== undefined && code !== null) {
            return HINT_COUNTRY;
        }
    }

    return null;
}

/**
 * People of the list whose name is close to `key` (1-2 edits, both keys at least 6 characters) - "Did you mean …?" -
 * the closest first ("Sheet Persn 04" → Sheet Person 04 before Sheet Person 01).
 *
 * @param {string} key a name key
 * @param {Array<{key: string, length: number, ids: string[]}>} known the list's name keys
 * @returns {string[]} person ids
 */
export function closeNames(key, known) {
    const length = [...key].length;

    if (length < CLOSE_MIN_LENGTH) {
        return [];
    }

    const close = [];

    for (const candidate of known) {
        if (candidate.length < CLOSE_MIN_LENGTH || Math.abs(candidate.length - length) > CLOSE_MAX_EDITS || candidate.key === key) {
            continue;
        }

        const distance = boundedDistance(key, candidate.key);

        if (distance <= CLOSE_MAX_EDITS) {
            close.push({ distance, ids: candidate.ids });
        }
    }

    // Stable: equally close names keep the list's order
    return close.sort((a, b) => a.distance - b.distance).flatMap((candidate) => candidate.ids);
}

/**
 * @param {object} model the SheetModel (people incl. removed)
 * @param {string[][]} rows the block, already parsed by tsv.js
 * @param {{readCountry?: function(string): (string|null|undefined), headerNames?: string[]}} [options]
 *        readCountry = a typed country → a code, null (empty) or undefined (unknown); headerNames = the column names a
 *        header line would carry (the page's language)
 * @returns {{lines: PasteLine[], counts: {new: number, existing: number, removed: number, duplicate: number, invalid: number, header: number}}}
 */
export function planNamePaste(model, rows, { readCountry = () => undefined, headerNames = [] } = {}) {
    const active = new Map();
    const removed = new Map();

    for (const person of model.people({ includeRemoved: true })) {
        const key = nameKey(person.name);
        const target = person.removedAt === null ? active : removed;
        target.set(key, [...(target.get(key) ?? []), person.id]);
    }

    const headers = new Set(headerNames.map((name) => foldSearchText(String(name ?? ''))).filter(Boolean));
    const seen = new Map();
    const lines = [];
    const known = [];

    for (const [key, ids] of [...active, ...removed]) {
        known.push({ key, length: [...key].length, ids });
    }

    rows.forEach((cells, index) => {
        const name = cleanName(trimCell(cells?.[0] ?? ''));
        const countryCell = trimCell(cells?.[1] ?? '');
        const externalId = cleanOptionalText(trimCell(cells?.[2] ?? ''));

        if (name === '' && countryCell === '' && externalId === null) {
            // An empty line (a blank row between blocks) is no person
            return;
        }

        const line = {
            id: `l${index}`,
            index,
            name,
            country: null,
            countryText: null,
            externalId,
            status: LINE_NEW,
            matches: [],
            reason: null,
            sameAs: null,
            hint: null,
            tick: false,
        };
        lines.push(line);

        if (lines.length === 1 && headers.size > 0 && headers.has(foldSearchText(name))) {
            line.status = LINE_HEADER;

            return;
        }

        if (countryCell !== '') {
            const code = readCountry(countryCell);

            if (code === undefined) {
                line.countryText = countryCell;
            } else {
                line.country = code;
            }
        }

        if (name === '') {
            line.status = LINE_INVALID;
            line.reason = 'name_blank';

            return;
        }

        if (textLength(name) > NAME_MAX_LENGTH) {
            line.status = LINE_INVALID;
            line.reason = 'name_too_long';

            return;
        }

        if (textLength(externalId ?? '') > EXTERNAL_ID_MAX_LENGTH) {
            line.status = LINE_INVALID;
            line.reason = 'external_id_too_long';

            return;
        }

        const key = nameKey(name);

        if (seen.has(key)) {
            line.status = LINE_DUPLICATE;
            line.sameAs = seen.get(key);

            return;
        }

        seen.set(key, index);

        if (active.has(key)) {
            line.status = LINE_EXISTING;
            line.matches = active.get(key);

            return;
        }

        if (removed.has(key)) {
            line.status = LINE_REMOVED;
            line.matches = removed.get(key);

            return;
        }

        const kind = looksLikeNoName(name, readCountry);

        if (kind !== null) {
            line.hint = { kind };

            return;
        }

        const close = closeNames(key, known);

        if (close.length > 0) {
            line.hint = { kind: HINT_CLOSE, ids: close };

            return;
        }

        line.tick = true;
    });

    const counts = { new: 0, existing: 0, removed: 0, duplicate: 0, invalid: 0, header: 0 };
    lines.forEach((line) => {
        counts[line.status]++;
    });

    return { lines, counts };
}

/**
 * What putting the line's person into `roundId` means (the preview's note): `put` (they go in), `already` (in it
 * already), `ambiguous` (several active people have that name - not placed; chosen in the round's tab), or null (the
 * line puts nobody anywhere - left out, not possible, a duplicate, a header).
 *
 * @param {object} model
 * @param {PasteLine} line
 * @param {string|null} roundId
 */
export function placementOf(model, line, roundId) {
    if (!roundId) {
        return null;
    }

    if (line.status === LINE_NEW) {
        return INTO_PUT;
    }

    if (line.status === LINE_REMOVED) {
        return line.matches.length > 0 && model.placeValue(line.matches[0], roundId) !== 'out' ? INTO_ALREADY : INTO_PUT;
    }

    if (line.status === LINE_EXISTING) {
        if (line.matches.length !== 1) {
            return INTO_AMBIGUOUS;
        }

        return model.placeValue(line.matches[0], roundId) === 'out' ? INTO_PUT : INTO_ALREADY;
    }

    return null;
}

/**
 * The confirmed paste as one action: a `newParticipant` group per ticked new line, a `restore` group per ticked removed
 * line (the first removed person of that name). `ticks` = line id → ticked (the preview's ticks; a line not in it keeps
 * its default). With `options.roundId` (a solo round) every new and restored person is also put into the round (in the
 * same group - atomic), and every person already on the list (one person of that name) who is not in it yet gets a
 * group of its own putting them in. `options.skip` = line ids left out whatever their tick says (refused by the dry run).
 * Returns the action plus which group came from which line (to put the server's dry run answer on its line) and which
 * person is which line's.
 *
 * @param {object} model
 * @param {{lines: PasteLine[]}} plan
 * @param {Object<string, boolean>} [ticks]
 * @param {{newId?: function(): string, countries?: Set<string>|null, roundId?: string|null, skip?: Set<string>|null}} [options]
 * @returns {{action: object, lineOfGroup: Map<string, string>, lineOfPerson: Map<string, string>, peopleIds: string[], placed: string[]}}
 */
export function namePasteAction(model, plan, ticks = {}, { newId = newClientId, countries = null, roundId = null, skip = null } = {}) {
    const groups = [];
    const lineOfPerson = new Map();
    const peopleIds = [];
    const placed = [];
    const into = (personId, from = 'out') => ({ op: 'place', participant: personId, round: roundId, from, to: 'in' });

    for (const line of plan.lines) {
        if (skip?.has(line.id)) {
            continue;
        }

        const ticked = line.id in ticks ? ticks[line.id] === true : line.tick;

        if (line.status === LINE_NEW && ticked) {
            const id = newId();
            const changes = [{ op: 'newParticipant', id, name: line.name, country: line.country, externalId: line.externalId }];

            if (roundId) {
                changes.push(into(id));
                placed.push(id);
            }

            groups.push(changes);
            lineOfPerson.set(id, line.id);
            peopleIds.push(id);
        } else if (line.status === LINE_REMOVED && ticked && line.matches.length > 0) {
            const personId = line.matches[0];
            const changes = [{ op: 'restore', participant: personId }];

            if (placementOf(model, line, roundId) === INTO_PUT) {
                changes.push(into(personId, model.placeValue(personId, roundId)));
                placed.push(personId);
            }

            groups.push(changes);
            lineOfPerson.set(personId, line.id);
            peopleIds.push(personId);
        } else if (line.status === LINE_EXISTING && placementOf(model, line, roundId) === INTO_PUT) {
            const personId = line.matches[0];
            groups.push([into(personId, model.placeValue(personId, roundId))]);
            lineOfPerson.set(personId, line.id);
            placed.push(personId);
        }
    }

    const action = buildAction(model, groups, { label: { key: 'paste' }, newId, countries });
    const lineOfGroup = new Map();

    for (const group of action.groups) {
        const change = group.changes[0];
        lineOfGroup.set(group.id, lineOfPerson.get(change.id ?? change.participant));
    }

    const kept = new Set(action.groups.flatMap((group) => group.changes.map((change) => change.id ?? change.participant)));

    return {
        action,
        lineOfGroup,
        lineOfPerson,
        peopleIds: peopleIds.filter((id) => kept.has(id)),
        placed: placed.filter((id) => kept.has(id)),
    };
}

/**
 * The line the client refusal of `namePasteAction()` belongs to (errors carry the refused change; `lineOfPerson` -
 * namePasteAction()'s - maps the people of the paste, new ones included).
 */
export function lineOfError(error, plan, lineOfPerson = null) {
    const change = error?.change ?? {};
    const personId = change.op === 'newParticipant' ? change.id : change.participant;

    if (lineOfPerson?.has(personId)) {
        return lineOfPerson.get(personId);
    }

    if (change.op === 'restore') {
        return plan.lines.find((line) => line.status === LINE_REMOVED && line.matches[0] === change.participant)?.id ?? null;
    }

    if (change.op === 'place') {
        return plan.lines.find((line) => line.matches[0] === change.participant)?.id ?? null;
    }

    return plan.lines.find((line) => line.status === LINE_NEW && line.name === change.name)?.id ?? null;
}
