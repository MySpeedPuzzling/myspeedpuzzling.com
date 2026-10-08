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
 * - everything else is a new person, ticked; a country nobody knows is left out of the line (said in its note), a
 *   name or an external id the server would refuse makes the line not possible;
 * - a first line that is a header (the sheet's own column names) is left out.
 *
 * planNamePaste() reads the block, namePasteAction() turns the organiser's ticks into one action (one undo step, a
 * group per person - one refused name never blocks the others). Pure - pinned by tests/participants-sheet-people-harness.mjs.
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
 * @property {boolean} tick              ticked by default (new people yes, restoring no)
 */

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

        line.tick = true;
    });

    const counts = { new: 0, existing: 0, removed: 0, duplicate: 0, invalid: 0, header: 0 };
    lines.forEach((line) => {
        counts[line.status]++;
    });

    return { lines, counts };
}

/**
 * The confirmed paste as one action: a `newParticipant` group per ticked new line, a `restore` group per ticked removed
 * line (the first removed person of that name). `ticks` = line id → ticked (the preview's ticks; a line not in it keeps
 * its default). Returns the action plus which group came from which line (to put the server's dry run answer on its
 * line).
 *
 * @param {object} model
 * @param {{lines: PasteLine[]}} plan
 * @param {Object<string, boolean>} [ticks]
 * @param {{newId?: function(): string, countries?: Set<string>|null}} [options]
 * @returns {{action: object, lineOfGroup: Map<string, string>, peopleIds: string[]}}
 */
export function namePasteAction(model, plan, ticks = {}, { newId = newClientId, countries = null } = {}) {
    const groups = [];
    const lineOfChange = new Map();
    const peopleIds = [];

    for (const line of plan.lines) {
        const ticked = line.id in ticks ? ticks[line.id] === true : line.tick;

        if (!ticked) {
            continue;
        }

        if (line.status === LINE_NEW) {
            const id = newId();
            groups.push([{ op: 'newParticipant', id, name: line.name, country: line.country, externalId: line.externalId }]);
            lineOfChange.set(id, line.id);
            peopleIds.push(id);
        } else if (line.status === LINE_REMOVED && line.matches.length > 0) {
            groups.push([{ op: 'restore', participant: line.matches[0] }]);
            lineOfChange.set(line.matches[0], line.id);
            peopleIds.push(line.matches[0]);
        }
    }

    const action = buildAction(model, groups, { label: { key: 'paste' }, newId, countries });
    const lineOfGroup = new Map();

    for (const group of action.groups) {
        const change = group.changes[0];
        lineOfGroup.set(group.id, lineOfChange.get(change.id ?? change.participant));
    }

    return { action, lineOfGroup, peopleIds };
}

/**
 * The line the client refusal of `namePasteAction()` belongs to (errors carry the refused change).
 */
export function lineOfError(error, plan) {
    const change = error?.change ?? {};

    if (change.op === 'restore') {
        return plan.lines.find((line) => line.status === LINE_REMOVED && line.matches[0] === change.participant)?.id ?? null;
    }

    return plan.lines.find((line) => line.status === LINE_NEW && line.name === change.name)?.id ?? null;
}
