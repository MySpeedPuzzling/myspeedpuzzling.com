/**
 * Pasting into a round tab of the participants spreadsheet (docs/features/competitions-management/participants-spreadsheet.md
 * §4 (C), §5 "Same name in a round", §11b and "The round tabs"; contract D9, O1) - the match-then-confirm planners, pure:
 *
 * - **rows of teams** (`Team ⇥ member ⇥ member …`, anchored on the new-team row or wider than the anchor allows): a team
 *   name equal (cleaned, case and accents ignored) to **one** pair/team of the round → that one (its members become
 *   exactly the pasted ones, members it had and the paste does not list go to the tray), to **several** → ambiguous (the
 *   preview asks which one or a new one - nothing is picked for the organiser), to none → a new one - unless the pasted
 *   people form exactly one pair/team of the round already (then that one, renamed); a row without a name whose people
 *   form exactly one pair/team → that one (no duplicate). Each person: the event's active people by name (exact, then
 *   folded like ParticipantNameKey); one → them, several → the people of the round decide when exactly one combination
 *   of them is a pair/team of the round, else the preview asks (people already in that pair/team, or paired with the
 *   other pasted people, listed first); none → "add as a new participant" - ticked unless a close name exists ("Did you
 *   mean Kim Example?") or the value looks like a country code, a number or an e-mail (BR9). Somebody of another
 *   pair/team of the round moves ("moves from Table 12 · Pinecones"). A first row of column headings is left out;
 *   rows naming the same people (a partner column: `Kim ⇥ Pat` and `Pat ⇥ Kim`) are one pair, counted once (BR8).
 * - **positional** (anchored on existing rows): every pasted row writes the columns it covers of the row it lands on
 *   (name → rename, member column → that member; an empty member cell clears it); rows past the end are matched like
 *   rows of teams.
 * - **names into a solo round** (BR3): one person per line, matched like the members above; already in the round =
 *   nothing to do, the rest go into the round (new people as new participants).
 * - **results** (`name ⇥ result`, or one column onto the Result column): the first column is an entry of this round -
 *   a pair's/team's name or a member's name, a person's name in a solo round (folded), `#code` of a linked player, or a
 *   table number when every number of the column is a table of this round (BR5). Unknown and not-in-round names are
 *   listed and skipped (in a round of team names only, unknown names may become new teams - BR16), a blank result is
 *   left out (a paste never clears a saved result), results already there are shown as "replaces 1:24:00".
 *
 * A plan only describes; the builders turn it and the organiser's choices into one action (a group per pasted row -
 * atomic on the server, so one refused row never half-applies). Plans and actions are computed against a snapshot of
 * the page taken when the organiser pasted (snapshotModel()) - a live change arriving while the preview is open comes
 * back from the server as a conflict instead of being overwritten. Pinned by tests/participants-sheet-round-harness.mjs.
 */

import { newClientId } from '../official_results_api.js';
import { foldSearchText } from '../search_fold.js';
import { buildAction } from './sheet_changes.js';
import { IN, OUT, SheetModel, cleanName, cleanTeamName, nameKey, parsePlace, teamPlace } from './sheet_model.js';
import { trimCell } from './tsv.js';
import { parseResultInput, sameValue } from './sheet_results.js';

export const NEW_TEAM = 'new';
export const SKIP = 'skip';
// An ambiguous match not decided yet ("Choose…") - the preview's Confirm waits for it
export const CHOOSE = '';
// The builders' answer for a pasted row whose choice is still open: the row is left out until it is decided
export const UNDECIDED = Symbol('undecided');
// The results endpoint takes at most this many changes in one request - the save queue sends more in several
export const MAX_RESULT_CHANGES = 500;
// Ambiguous names of one row tried against the round's pairs/teams (2 people of 3 names each = 9)
export const MAX_COMBINATIONS = 64;

// ---------------------------------------------------------------- the page when the organiser pasted

/**
 * A read-only copy of what the page shows now (the organiser's pending groups included) - the model a paste is planned,
 * checked by the server's dry run and finally built on. Only public API: `scratch()` (a copy-on-write Working) turned
 * back into a state.
 */
export function snapshotModel(model) {
    const working = model.scratch();

    return new SheetModel({
        competition: model.competition,
        version: model.version,
        rounds: model.rounds(),
        people: working.order.map((id) => working.people.get(id)).filter(Boolean),
        places: [...working.places.values()],
        teams: [...working.teams.values()],
    }, { now: model.now });
}

// ---------------------------------------------------------------- matching people and teams

/**
 * The event's active people a pasted name means: exactly that name first (cleaned like the server), else the
 * ParticipantNameKey fold. Several → ordered by how likely they are meant (rankCandidates()).
 *
 * @param {{targetTeamId?: string|null, partners?: string[]}} [context]
 * @returns {{status: 'one'|'several'|'none', ids: string[]}}
 */
export function matchPerson(model, text, roundId = null, context = {}) {
    const name = cleanName(text);

    if (name === '') {
        return { status: 'none', ids: [] };
    }

    const exact = model.people().filter((person) => cleanName(person.name) === name);
    const found = exact.length > 0 ? exact : model.peopleNamed(name);

    if (found.length === 1) {
        return { status: 'one', ids: [found[0].id] };
    }

    if (found.length === 0) {
        return { status: 'none', ids: [] };
    }

    return { status: 'several', ids: rankCandidates(model, roundId, found.map((person) => person.id), context) };
}

/**
 * Same-named people, the likely one first: already in the pair/team the row goes to, or in one pair/team with another
 * person of the row ("forming a pair with the other pasted people"), then in the round without a pair/team, then in
 * another pair/team of the round, then not in the round. The order only - the organiser still chooses.
 */
export function rankCandidates(model, roundId, ids, { targetTeamId = null, partners = [] } = {}) {
    if (roundId === null) {
        return ids.slice();
    }

    const partnerTeams = new Set(partners
        .map((id) => parsePlace(model.placeValue(id, roundId)).teamId)
        .filter((teamId) => teamId !== null));
    const rank = (id) => {
        const place = parsePlace(model.placeValue(id, roundId));

        if (place.teamId !== null && (place.teamId === targetTeamId || partnerTeams.has(place.teamId))) {
            return 0;
        }

        if (place.kind === IN) {
            return 1;
        }

        return place.kind === 'team' ? 2 : 3;
    };

    return ids.slice().sort((a, b) => rank(a) - rank(b));
}

/** Pairs/teams of the round called `name` - folded like the round's "same name" rule (case and accents ignored). */
export function teamsNamed(model, roundId, name) {
    const wanted = cleanTeamName(name);

    if (wanted === null) {
        return [];
    }

    const key = foldSearchText(wanted);

    return model.teamsOf(roundId).filter((team) => team.name !== null && foldSearchText(team.name) === key).map((team) => team.id);
}

/** The pair/team of the round whose active members are exactly these people - or null. */
export function teamOfExactly(model, roundId, personIds) {
    const wanted = [...new Set(personIds)];

    if (wanted.length === 0) {
        return null;
    }

    const first = parsePlace(model.placeValue(wanted[0], roundId));

    if (first.kind !== 'team') {
        return null;
    }

    const members = model.membersOf(first.teamId).map((person) => person.id);

    return members.length === wanted.length && wanted.every((id) => members.includes(id)) ? first.teamId : null;
}

/**
 * The pairs/teams of the round the people of a row may be (several = the candidates of a same-named person tried in
 * every combination, at most MAX_COMBINATIONS): `[{teamId, picks: Map(member → personId)}]`.
 */
function teamsByCombinations(model, roundId, members) {
    const known = members.filter((member) => member.status === 'one').map((member) => member.ids[0]);
    const several = members.filter((member) => member.status === 'several');

    if (members.some((member) => member.status !== 'one' && member.status !== 'several')) {
        return [];
    }

    const combinations = several.reduce((total, member) => total * member.ids.length, 1);

    if (combinations > MAX_COMBINATIONS) {
        return [];
    }

    const found = new Map();
    const visit = (index, picks) => {
        if (index === several.length) {
            const ids = [...known, ...picks.values()];

            if (new Set(ids).size !== ids.length) {
                return;
            }

            const teamId = teamOfExactly(model, roundId, ids);

            if (teamId !== null && !found.has(teamId)) {
                found.set(teamId, new Map(picks));
            }

            return;
        }

        for (const id of several[index].ids) {
            picks.set(several[index], id);
            visit(index + 1, picks);
        }

        picks.delete(several[index]);
    };
    visit(0, new Map());

    return [...found].map(([teamId, picks]) => ({ teamId, picks }));
}

// ---------------------------------------------------------------- what a pasted value is

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/u;
const NUMBER = /^[#+]?[\d\s.,:/()+-]+$/u;

/**
 * Pasted text that is probably not a person's name (BR9) - offered as a new participant, never pre-ticked:
 * `email` (an address), `number` (digits and punctuation - a table, an id, a time), `country` (a code like US, CZ, GER).
 *
 * @param {Set<string>|null} countryCodes the event's country codes (lower case)
 * @returns {'email'|'number'|'country'|null}
 */
export function notANameKind(text, countryCodes = null) {
    const value = String(text ?? '').trim();

    if (EMAIL.test(value)) {
        return 'email';
    }

    if (NUMBER.test(value) && /\d/.test(value)) {
        return 'number';
    }

    // US, CZ - or us, cz when the event knows the code; "Jo" (a name written like one) is not
    if (/^[A-Z]{2}$/.test(value) || (/^[a-z]{2}$/.test(value) && (countryCodes?.has(value) ?? false))) {
        return 'country';
    }

    return /^[A-Z]{3}$/.test(value) ? 'country' : null;
}

function distance(a, b, limit) {
    if (Math.abs(a.length - b.length) > limit) {
        return limit + 1;
    }

    let previous = Array.from({ length: b.length + 1 }, (_, index) => index);

    for (let i = 1; i <= a.length; i++) {
        const current = [i];
        let best = i;

        for (let j = 1; j <= b.length; j++) {
            current[j] = Math.min(previous[j] + 1, current[j - 1] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
            best = Math.min(best, current[j]);
        }

        if (best > limit) {
            return limit + 1;
        }

        previous = current;
    }

    return previous[b.length];
}

/**
 * Active people whose name is close to a new name (a typo, the words swapped, a middle name more or less) - "Did you
 * mean Kim Example?" (BR9). People called exactly that are not close, they are the same name (matchPerson()).
 *
 * @returns {Array<{id: string, name: string}>} at most `limit`
 */
export function closeNames(model, text, { limit = 3 } = {}) {
    const key = nameKey(text);

    if (key.length < 3) {
        return [];
    }

    const words = key.split(' ').filter(Boolean);
    const sorted = words.slice().sort().join(' ');
    const allowed = key.length >= 8 ? 2 : 1;
    const close = [];

    for (const person of model.people()) {
        const other = nameKey(person.name);

        if (other === key) {
            continue;
        }

        const otherWords = other.split(' ').filter(Boolean);
        const swapped = otherWords.slice().sort().join(' ') === sorted;
        const middle = words.length >= 2 && otherWords.length >= 2 && words.length !== otherWords.length
            && words[0] === otherWords[0] && words[words.length - 1] === otherWords[otherWords.length - 1];

        if (swapped || middle || distance(key, other, allowed) <= allowed) {
            close.push({ id: person.id, name: person.name });

            if (close.length >= limit) {
                break;
            }
        }
    }

    return close;
}

/** A name nobody of the event has: one new participant per name key, ticked unless BR9 says otherwise. */
function newNameEntry(model, text, countryCodes) {
    const close = closeNames(model, text);
    const suspicious = notANameKind(text, countryCodes);

    return { key: nameKey(text), name: cleanName(text), lines: [], close, suspicious, tick: close.length === 0 && suspicious === null };
}

// ---------------------------------------------------------------- column headings

/** The words a heading row is made of: the grid's column labels and the texts' list, folded. */
export function headerWordSet(words) {
    const set = new Set();

    for (const word of words ?? []) {
        for (const part of foldWords(word)) {
            set.add(part);
        }
    }

    return set;
}

function foldWords(text) {
    return foldSearchText(String(text ?? '')).replace(/[#.,;:()\d/-]+/gu, ' ').split(' ').filter(Boolean);
}

/** Every cell is made of heading words only ("Team ⇥ Member 1 ⇥ Member 2", "Name ⇥ Result"). */
export function looksLikeHeader(cells, headerWords) {
    const texts = cells.map((cell) => trimCell(cell ?? '')).filter((text) => text !== '');

    if (headerWords.size === 0 || texts.length === 0) {
        return false;
    }

    return texts.every((text) => {
        const words = foldWords(text);

        return words.length > 0 && words.every((word) => headerWords.has(word));
    });
}

// ---------------------------------------------------------------- rows of teams

/**
 * @typedef {object} PastedMember
 * @property {number} column       the block column
 * @property {number|null} slot    positional: the member column it lands on (0 = Member 1)
 * @property {string} text
 * @property {'one'|'several'|'none'|'empty'} status
 * @property {string[]} ids        the people it may mean (one: [id]; several: candidates, the likely one first)
 * @property {string|null} key     name key of a new person (status none)
 * @property {boolean} [tick]      a new person: ticked by default (BR9)
 * @property {boolean} [resolved]  several names, decided by the pair/team the row's people form
 *
 * @typedef {object} PastedLine
 * @property {string} id           `t<index>` - the preview line of the row
 * @property {number} index        the block row
 * @property {'existing'|'new'|'ambiguous'} match
 * @property {string|null} teamId  existing: the pair/team; positional rows: the row's pair/team
 * @property {string[]} candidates ambiguous: the pairs/teams of that name (the likely one first)
 * @property {boolean} positional
 * @property {boolean} nameCovered the block covers the name column
 * @property {string|null} name    the pasted name (cleaned; null = none)
 * @property {boolean} byMembers   matched because its people form exactly that pair/team
 * @property {boolean} membersCovered
 * @property {PastedMember[]} members
 * @property {string[]} twice      people listed on an earlier line too (the later line counts)
 */

/**
 * Plans a paste of pair/team rows.
 *
 * @param {object} model           the snapshot the paste is planned on (snapshotModel())
 * @param {string} roundId
 * @param {string[][]} block
 * @param {{columns: Array<'name'|'member'|'ignore'>, slots?: Array<number|null>, targets?: Array<string|null>,
 *          headerWords?: Set<string>, countryCodes?: Set<string>|null}} layout `columns` = what each block column is;
 *          positional pastes give `slots` (member column of each block column) and `targets` (the pair/team row each
 *          block row lands on, null past the end); `headerWords` = headerWordSet() of the page's column names
 * @returns {{roundId: string, lines: PastedLine[], newNames: Array<object>, counts: object, header: object|null,
 *            duplicates: Array<{id: string, index: number, of: string, text: string}>}}
 */
export function planTeamPaste(model, roundId, block, layout) {
    const columns = layout.columns;
    const headerWords = layout.headerWords ?? new Set();
    const lines = [];
    const newNames = new Map();
    const seenPeople = new Map();
    const bySet = new Map();
    const duplicates = [];
    let header = null;

    block.forEach((values, index) => {
        const cells = columns.map((kind, column) => ({ kind, column, text: trimCell(values[column] ?? '') }));
        const covered = cells.filter((cell) => cell.kind !== 'ignore' && cell.column < values.length);

        if (covered.every((cell) => cell.text === '')) {
            return;
        }

        const target = layout.targets?.[index] ?? null;
        const positional = target !== null;

        if (lines.length === 0 && duplicates.length === 0 && header === null && !positional
            && looksLikeHeader(covered.map((cell) => cell.text), headerWords) && !namesSomething(model, roundId, covered)) {
            header = { id: `t${index}`, index, text: covered.map((cell) => cell.text).filter(Boolean).join(' · ') };

            return;
        }

        const nameCell = covered.find((cell) => cell.kind === 'name') ?? null;
        const name = nameCell === null ? null : cleanTeamName(nameCell.text);
        const members = covered
            .filter((cell) => cell.kind === 'member')
            .map((cell) => {
                const slot = layout.slots?.[cell.column] ?? null;

                if (cell.text === '') {
                    return { column: cell.column, slot, text: '', status: 'empty', ids: [], key: null };
                }

                const found = matchPerson(model, cell.text, roundId);

                return { column: cell.column, slot, text: cleanName(cell.text), status: found.status, ids: found.ids, key: found.status === 'none' ? nameKey(cell.text) : null };
            });
        const membersCovered = members.some((member) => member.status !== 'empty');
        const line = {
            id: `t${index}`,
            index,
            match: NEW_TEAM,
            teamId: null,
            candidates: [],
            positional,
            nameCovered: nameCell !== null,
            name,
            byMembers: false,
            membersCovered,
            members,
            twice: [],
        };

        if (positional) {
            line.match = 'existing';
            line.teamId = target;
        } else {
            matchTeamLine(model, roundId, line);
        }

        if (line.match === NEW_TEAM && name === null && !membersCovered) {
            return;
        }

        // Same-named people still to choose: the likely one first (the row's pair/team, a pasted partner's)
        const known = members.filter((member) => member.status === 'one').map((member) => member.ids[0]);

        for (const member of members) {
            if (member.status === 'several') {
                member.ids = rankCandidates(model, roundId, member.ids, { targetTeamId: line.teamId, partners: known });
            }
        }

        // Rows of the same people (a partner column lists every pair twice) are one pair (BR8)
        const setKey = positional ? null : peopleSetKey(members);

        if (setKey !== null && bySet.has(setKey)) {
            const first = bySet.get(setKey);

            if (name === null || first.name === null || foldSearchText(name) === foldSearchText(first.name)) {
                if (first.name === null && name !== null) {
                    first.name = name;
                    first.nameCovered = true;
                }

                duplicates.push({ id: line.id, index, of: first.id, text: [name, ...members.filter((member) => member.status !== 'empty').map((member) => member.text)].filter(Boolean).join(' · ') });

                return;
            }
        }

        for (const member of members) {
            if (member.status === 'none') {
                if (!newNames.has(member.key)) {
                    newNames.set(member.key, newNameEntry(model, member.text, layout.countryCodes ?? null));
                }

                const entry = newNames.get(member.key);
                entry.lines.push(line.id);
                member.tick = entry.tick;
            }
        }

        // The same person on two lines: the later line wins (they move there) - said in the preview
        for (const member of members) {
            if (member.status === 'one') {
                if (seenPeople.has(member.ids[0])) {
                    line.twice.push(member.ids[0]);
                }

                seenPeople.set(member.ids[0], line.id);
            }
        }

        if (setKey !== null) {
            bySet.set(setKey, line);
        }

        lines.push(line);
    });

    return {
        roundId,
        lines,
        newNames: [...newNames.values()],
        counts: countTeamPaste(model, roundId, lines, newNames),
        header,
        duplicates,
    };
}

/** A heading row never names a pair/team of the round or a person of the event. */
function namesSomething(model, roundId, cells) {
    return cells.some((cell) => cell.text !== '' && (cell.kind === 'name'
        ? teamsNamed(model, roundId, cell.text).length > 0
        : matchPerson(model, cell.text).status !== 'none'));
}

/** The people of a row as a set (order does not matter) - null while a name is still to choose. */
function peopleSetKey(members) {
    const keys = [];

    for (const member of members) {
        if (member.status === 'one') {
            keys.push(`p:${member.ids[0]}`);
        } else if (member.status === 'none') {
            keys.push(`n:${member.key}`);
        } else if (member.status === 'several') {
            return null;
        }
    }

    return keys.length === 0 ? null : [...new Set(keys)].sort().join('|');
}

function matchTeamLine(model, roundId, line) {
    const members = line.members.filter((member) => member.status !== 'empty');
    const known = members.filter((member) => member.status === 'one').map((member) => member.ids[0]);
    // The people decide when exactly one combination of the same-named ones is a pair/team of the round
    const found = line.membersCovered ? teamsByCombinations(model, roundId, members) : [];
    const byMembers = found.length === 1 ? found[0] : null;
    const resolve = () => {
        for (const [member, personId] of byMembers.picks) {
            member.status = 'one';
            member.ids = [personId];
            member.resolved = true;
        }
    };

    if (line.name !== null) {
        const named = teamsNamed(model, roundId, line.name);

        if (named.length === 1) {
            line.match = 'existing';
            line.teamId = named[0];

            if (byMembers !== null && byMembers.teamId === named[0]) {
                resolve();
            }

            return;
        }

        if (named.length > 1) {
            if (byMembers !== null && named.includes(byMembers.teamId)) {
                line.match = 'existing';
                line.teamId = byMembers.teamId;
                resolve();

                return;
            }

            // The organiser decides; the pair/team holding most of the pasted people is listed first
            const holds = (teamId) => known.filter((personId) => parsePlace(model.placeValue(personId, roundId)).teamId === teamId).length;
            line.match = 'ambiguous';
            line.candidates = named.slice().sort((a, b) => holds(b) - holds(a));

            return;
        }
    }

    if (byMembers !== null) {
        line.match = 'existing';
        line.teamId = byMembers.teamId;
        line.byMembers = true;
        resolve();
    }
}

function countTeamPaste(model, roundId, lines, newNames) {
    const counts = { newTeams: 0, moves: 0, newPeople: newNames.size, ambiguousTeams: 0, ambiguousPeople: 0, sharedNames: 0, renames: 0 };
    const names = new Map();

    for (const team of model.teamsOf(roundId)) {
        if (team.name !== null) {
            const key = foldSearchText(team.name);
            names.set(key, (names.get(key) ?? 0) + 1);
        }
    }

    for (const line of lines) {
        if (line.match === NEW_TEAM) {
            counts.newTeams++;

            if (line.name !== null) {
                const key = foldSearchText(line.name);
                names.set(key, (names.get(key) ?? 0) + 1);
            }
        } else if (line.match === 'ambiguous') {
            counts.ambiguousTeams++;
        }

        for (const member of line.members) {
            if (member.status === 'several') {
                counts.ambiguousPeople++;
            }

            if (member.status === 'one' && line.match !== 'ambiguous') {
                const from = parsePlace(model.placeValue(member.ids[0], roundId));

                if (from.kind === 'team' && from.teamId !== line.teamId) {
                    counts.moves++;
                }
            }
        }

        if (line.match === 'existing') {
            const team = model.team(line.teamId);

            if (line.nameCovered && line.name !== null && team !== null && line.name !== team.name && (line.positional || line.byMembers)) {
                counts.renames++;
            }
        }
    }

    for (const line of lines) {
        if (line.match === NEW_TEAM && line.name !== null && (names.get(foldSearchText(line.name)) ?? 0) > 1) {
            counts.sharedNames++;
        }
    }

    return counts;
}

/**
 * The person a pasted member means after the organiser's choices: an id, `{new: key}` for a ticked new person, null
 * (left out) or UNDECIDED (a same-named person not chosen yet - "Choose…").
 *
 * @param {{choices: object, ticks: object}} selection the preview dialog's answer (line ids: `p<row>:<column>` for an
 *        ambiguous person, `n<key>` for a new name, `t<row>` for an ambiguous pair/team)
 */
export function chosenPerson(line, member, selection) {
    if (member.status === 'one') {
        return member.ids[0];
    }

    if (member.status === 'several') {
        const choice = selection.choices?.[`p${line.index}:${member.column}`];

        if (choice === undefined || choice === CHOOSE) {
            return UNDECIDED;
        }

        return choice === SKIP ? null : choice;
    }

    if (member.status === 'none') {
        const tick = selection.ticks?.[`n${member.key}`] ?? member.tick ?? true;

        return tick === false ? null : { new: member.key };
    }

    return null;
}

/** The pair/team a pasted row goes to after the organiser's choices: an id, NEW_TEAM or UNDECIDED. */
export function chosenTeam(line, selection) {
    if (line.match === 'ambiguous') {
        const choice = selection.choices?.[line.id];

        return choice === undefined || choice === CHOOSE ? UNDECIDED : choice;
    }

    return line.match === 'existing' ? line.teamId : NEW_TEAM;
}

/** The preview line ids still to choose ("Choose…") under a selection - Confirm waits for them. */
export function undecidedChoices(plan, selection) {
    const open = [];

    for (const line of plan.lines) {
        if (line.match === 'ambiguous' && chosenTeam(line, selection) === UNDECIDED) {
            open.push(line.id);
        }

        for (const member of line.members) {
            if (member.status === 'several' && chosenPerson(line, member, selection) === UNDECIDED) {
                open.push(`p${line.index}:${member.column}`);
            }
        }
    }

    return open;
}

/**
 * One action from a planned paste and the organiser's choices: a group per pasted row (new people, the pair/team, its
 * members - computed on a scratch state row after row, so `from` of a later row sees the earlier ones), one undo step.
 * A row with a choice still open is left out (`undecided`).
 *
 * @param {object} model the snapshot the plan was made on
 * @param {{choices?: object, ticks?: object}} selection
 * @param {{newId?: function(): string, countries?: Set<string>|null, slotsOf?: function(string): string[],
 *          skip?: Set<string>}} [options] `skip` = line ids left out (refused by the dry run)
 * @returns {object} the action (sheet_changes.js), plus `teams` = new pair/team ids by line id, `lineIds` = the
 *          line of each group, `refusedLines` = [{lineId, error}] of the client refusals, `undecided` = line ids
 */
export function buildTeamPasteAction(model, roundId, plan, selection = {}, { newId = newClientId, countries = null, slotsOf = null, skip = new Set() } = {}) {
    const working = model.scratch();
    const now = model.now();
    const created = new Map();
    const groups = [];
    const lineIds = [];
    const teams = {};
    const undecided = [];

    for (const line of plan.lines) {
        if (skip.has(line.id)) {
            // Refused by the server's dry run - not sent again
            continue;
        }

        const target = chosenTeam(line, selection);

        if (target === UNDECIDED || line.members.some((member) => chosenPerson(line, member, selection) === UNDECIDED)) {
            undecided.push(line.id);
            continue;
        }

        const changes = [];
        const personOf = (member) => {
            const chosen = chosenPerson(line, member, selection);

            if (chosen === null || typeof chosen === 'string') {
                return chosen;
            }

            if (!created.has(chosen.new)) {
                const id = newId();
                const name = plan.newNames.find((entry) => entry.key === chosen.new)?.name ?? member.text;
                created.set(chosen.new, id);
                changes.push({ op: 'newParticipant', id, name, country: null, externalId: null });
            }

            return created.get(chosen.new);
        };

        if (line.positional && target !== NEW_TEAM) {
            positionalChanges(working, roundId, line, target, personOf, changes, slotsOf);
        } else {
            let teamId = target;

            if (teamId === NEW_TEAM) {
                const wanted = line.members.map(personOf).filter(Boolean);

                if (line.name === null && wanted.length === 0) {
                    continue;
                }

                teamId = newId();
                teams[line.id] = teamId;
                changes.push({ op: 'newTeam', id: teamId, round: roundId, name: line.name });
            } else if (line.nameCovered && line.name !== null && line.byMembers && working.team(teamId)?.name !== line.name) {
                changes.push({ op: 'renameTeam', team: teamId, from: working.team(teamId)?.name ?? null, to: line.name });
            }

            const wanted = [...new Set(line.members.map(personOf).filter(Boolean))];

            if (line.membersCovered) {
                for (const personId of wanted) {
                    const from = working.placeValue(personId, roundId);

                    if (from !== teamPlace(teamId)) {
                        changes.push({ op: 'place', participant: personId, round: roundId, from, to: teamPlace(teamId) });
                    }
                }

                // Members the paste does not list go to the tray - "its members become exactly the pasted ones"
                if (target !== NEW_TEAM) {
                    for (const place of working.placesInTeam(teamId)) {
                        if (!wanted.includes(place.participantId) && working.person(place.participantId)?.removedAt === null) {
                            changes.push({ op: 'place', participant: place.participantId, round: roundId, from: teamPlace(teamId), to: IN });
                        }
                    }
                }
            }
        }

        if (changes.length === 0) {
            continue;
        }

        working.applyGroup(changes, now);
        groups.push(changes);
        lineIds.push(line.id);
    }

    const action = buildAction(model, groups, { label: { key: 'paste' }, newId, countries });
    // Which pasted row each group (and each client refusal) belongs to - the preview says it on that row
    const lineOf = new Map(groups.map((changes, index) => [changes, lineIds[index]]));

    return {
        ...action,
        teams,
        undecided,
        lineIds: action.groups.map((group) => lineOf.get(group.changes) ?? null),
        refusedLines: action.errors.map((error) => ({ lineId: lineIds[groups.findIndex((changes) => changes.includes(error.change))] ?? null, error })),
    };
}

/** A pasted row landing on an existing pair/team row: the covered name and member columns are written. */
function positionalChanges(working, roundId, line, teamId, personOf, changes, slotsOf) {
    const team = working.team(teamId);

    if (team === null) {
        return;
    }

    if (line.nameCovered && line.name !== team.name) {
        changes.push({ op: 'renameTeam', team: teamId, from: team.name, to: line.name });
    }

    const active = working.placesInTeam(teamId).filter((place) => working.person(place.participantId)?.removedAt === null).map((place) => place.participantId);
    const slots = (slotsOf ? slotsOf(teamId) : active).filter((id) => active.includes(id));
    const leaving = new Set();
    const joining = [];

    for (const member of line.members) {
        const current = member.slot !== null ? (slots[member.slot] ?? null) : null;
        const personId = member.status === 'empty' ? null : personOf(member);

        if (member.status !== 'empty' && personId === null) {
            // An ambiguous name left out, a new name not ticked: the cell stays as it is
            continue;
        }

        if (personId === current) {
            continue;
        }

        if (current !== null) {
            leaving.add(current);
        }

        if (personId !== null) {
            joining.push(personId);
        }
    }

    for (const personId of [...new Set(joining)]) {
        leaving.delete(personId);
        const from = working.placeValue(personId, roundId);

        if (from !== teamPlace(teamId)) {
            changes.push({ op: 'place', participant: personId, round: roundId, from, to: teamPlace(teamId) });
        }
    }

    for (const personId of leaving) {
        changes.push({ op: 'place', participant: personId, round: roundId, from: teamPlace(teamId), to: IN });
    }
}

// ---------------------------------------------------------------- names into a solo round (BR3)

/**
 * A column of names pasted into a solo round: each line is one person of the event (`in` = in the round already,
 * `one` = goes into it), `several` (the organiser chooses), `none` (a new participant - ticked unless BR9 says
 * otherwise), `removed` (only a removed person has this name - restored on the People tab), `duplicate` (listed
 * earlier). Only the first column is read; `ignoredColumns` says when there were more.
 *
 * @param {{headerWords?: Set<string>, countryCodes?: Set<string>|null}} [options]
 */
export function planSoloPaste(model, roundId, block, { headerWords = new Set(), countryCodes = null } = {}) {
    const lines = [];
    const newNames = new Map();
    const seen = new Map();
    let header = null;
    const removedKeys = new Set(model.people({ includeRemoved: true }).filter((person) => person.removedAt !== null).map((person) => nameKey(person.name)));

    block.forEach((row, index) => {
        const text = trimCell(row[0] ?? '');

        if (text === '') {
            return;
        }

        const id = `s${index}`;

        if (lines.length === 0 && header === null && looksLikeHeader([text], headerWords) && matchPerson(model, text).status === 'none') {
            header = { id, index, text };

            return;
        }

        const name = cleanName(text);
        const found = matchPerson(model, name, roundId);
        const line = { id, index, name, status: found.status, ids: found.ids, key: null, sameAs: null };

        if (found.status === 'one') {
            line.status = model.placeValue(found.ids[0], roundId) === OUT ? 'one' : 'in';
        } else if (found.status === 'none') {
            if (removedKeys.has(nameKey(name))) {
                line.status = 'removed';
            } else {
                line.key = nameKey(name);

                if (!newNames.has(line.key)) {
                    newNames.set(line.key, newNameEntry(model, name, countryCodes));
                }

                newNames.get(line.key).lines.push(id);
            }
        }

        const sameKey = line.status === 'one' || line.status === 'in' ? `p:${line.ids[0]}` : (line.key !== null ? `n:${line.key}` : null);

        if (sameKey !== null && seen.has(sameKey)) {
            line.sameAs = seen.get(sameKey);
            line.status = 'duplicate';
        } else if (sameKey !== null) {
            seen.set(sameKey, id);
        }

        lines.push(line);
    });

    const counts = { into: 0, already: 0, newPeople: newNames.size, ambiguous: 0, skipped: 0 };

    for (const line of lines) {
        if (line.status === 'one') {
            counts.into++;
        } else if (line.status === 'in') {
            counts.already++;
        } else if (line.status === 'several') {
            counts.ambiguous++;
        } else if (line.status === 'removed' || line.status === 'duplicate') {
            counts.skipped++;
        }
    }

    return {
        roundId,
        lines,
        newNames: [...newNames.values()],
        header,
        ignoredColumns: block.some((row) => row.slice(1).some((cell) => trimCell(cell ?? '') !== '')),
        counts,
    };
}

/** The preview line ids of a solo names paste still to choose. */
export function undecidedSoloChoices(plan, selection) {
    return plan.lines.filter((line) => line.status === 'several' && [undefined, CHOOSE].includes(selection.choices?.[line.id])).map((line) => line.id);
}

/**
 * The action of a solo names paste: a group per person put into the round (a new participant and its place in one
 * group), one undo step. `skip` = line ids the dry run refused.
 */
export function buildSoloPasteAction(model, roundId, plan, selection = {}, { newId = newClientId, countries = null, skip = new Set() } = {}) {
    const groups = [];
    const lineIds = [];
    const undecided = [];
    const created = new Set();
    const placed = new Set();

    for (const line of plan.lines) {
        if (skip.has(line.id)) {
            continue;
        }

        let personId = null;

        if (line.status === 'one') {
            personId = line.ids[0];
        } else if (line.status === 'several') {
            const choice = selection.choices?.[line.id];

            if (choice === undefined || choice === CHOOSE) {
                undecided.push(line.id);
                continue;
            }

            personId = choice === SKIP ? null : choice;
        } else if (line.status === 'none') {
            const entry = plan.newNames.find((candidate) => candidate.key === line.key);
            const tick = selection.ticks?.[`n${line.key}`] ?? entry?.tick ?? true;

            if (tick !== false && !created.has(line.key)) {
                created.add(line.key);
                const id = newId();
                groups.push([
                    { op: 'newParticipant', id, name: entry?.name ?? line.name, country: null, externalId: null },
                    { op: 'place', participant: id, round: roundId, from: OUT, to: IN },
                ]);
                lineIds.push(line.id);
            }

            continue;
        }

        if (personId === null || placed.has(personId) || model.placeValue(personId, roundId) !== OUT) {
            continue;
        }

        placed.add(personId);
        groups.push([{ op: 'place', participant: personId, round: roundId, from: OUT, to: IN }]);
        lineIds.push(line.id);
    }

    const action = buildAction(model, groups, { label: { key: 'paste' }, newId, countries });
    const lineOf = new Map(groups.map((changes, index) => [changes, lineIds[index]]));

    return {
        ...action,
        undecided,
        lineIds: action.groups.map((group) => lineOf.get(group.changes) ?? null),
        refusedLines: action.errors.map((error) => ({ lineId: lineIds[groups.findIndex((changes) => changes.includes(error.change))] ?? null, error })),
    };
}

// ---------------------------------------------------------------- results

/**
 * Does a block look like `name ⇥ result`? Two columns, most filled second cells read as results and none of them is
 * the name of somebody of the event (`model`) - a pair's `name ⇥ member` block never does. An unreadable value among
 * results is listed by the preview instead of turning the whole paste into something else.
 */
export function looksLikeResults(block, parseOptions = {}, model = null) {
    if (block.length === 0 || block.some((row) => row.length !== 2)) {
        return false;
    }

    const values = block.map((row) => trimCell(row[1] ?? '')).filter((value) => value !== '');
    const results = values.filter((value) => parseResultInput(value, parseOptions).kind === 'result');

    if (results.length === 0 || results.length * 2 < values.length) {
        return false;
    }

    return model === null || values.every((value) => results.includes(value) || matchPerson(model, value).status === 'none');
}

/** A first cell read as a linked player's code (`#kim01` - the live entry's rule). */
function codeOf(text) {
    return /^#\S+$/u.test(text) ? text.slice(1).toLowerCase() : null;
}

/**
 * Plans a results paste. `mode: 'names'` = `who ⇥ result` rows matched to the round's entries (a name, `#code`, or
 * table numbers - when every number of the first column is a table of this round); `'positional'` = one column landing
 * on the rows' Result cells (`targets` = the entry ref of each block row, `{skip: reason}` for a row that cannot get a
 * result - waitlisted, not saved yet -, null past the end).
 *
 * @param {object} model
 * @param {string} roundId
 * @param {Array<object>} entries round/round_common.js roundEntries() - only these can get a result
 * @param {string[][]} block
 * @param {{mode: 'names'|'positional', targets?: Array<string|{skip: string}|null>, parse?: object, tables?: boolean,
 *          createTeams?: boolean}} options `tables` = the round uses table numbers; `createTeams` = unknown names may
 *          become new pairs/teams (a round of team names only - BR16)
 * @returns {{lines: Array<object>, changes: Array<object>, counts: object, header: object|null, byTable: boolean}}
 */
export function planResultsPaste(model, roundId, entries, block, { mode, targets = [], parse = {}, tables = true, createTeams = false, headerWords = new Set() }) {
    const byRef = new Map(entries.filter((entry) => entry.ref !== null).map((entry) => [entry.ref, entry]));
    const byKey = new Map();
    const byCode = new Map();
    const byTable = new Map();

    for (const entry of entries) {
        if (entry.ref === null) {
            continue;
        }

        for (const name of new Set(entry.names.map((candidate) => nameKey(candidate)))) {
            byKey.set(name, [...(byKey.get(name) ?? []), entry.ref]);
        }

        for (const code of entry.codes ?? []) {
            byCode.set(code, [...(byCode.get(code) ?? []), entry.ref]);
        }

        if (entry.table !== null && entry.table !== undefined) {
            byTable.set(entry.table, [...(byTable.get(entry.table) ?? []), entry.ref]);
        }
    }

    const team = model.round(roundId)?.category !== 'solo';
    const firsts = mode === 'names' ? block.map((row) => trimCell(row[0] ?? '')).filter((value) => value !== '') : [];
    const numbers = firsts.filter((value) => /^\d{1,4}$/.test(value));
    // `12 ⇥ 1:23:45`: only when every number of the column is a table of this round (an id column never is)
    const tableMode = tables && numbers.length > 0 && numbers.length === firsts.length && numbers.every((value) => byTable.has(Number(value)));
    const lines = [];
    let header = null;

    block.forEach((row, index) => {
        const name = mode === 'names' ? trimCell(row[0] ?? '') : '';
        const value = trimCell(mode === 'names' ? (row[1] ?? '') : (row[0] ?? ''));
        const id = `r${index}`;
        const skip = (reason) => lines.push({ id, index, name, value, status: 'skip', reason, ref: null, candidates: [] });

        if (mode === 'names' && name === '' && value === '') {
            return;
        }

        if (mode === 'names' && lines.length === 0 && header === null && looksLikeHeader([name, value], headerWords)) {
            header = { id, index, text: [name, value].filter(Boolean).join(' · ') };

            return;
        }

        let refs = [];
        let newTeam = null;

        if (mode === 'positional') {
            const target = targets[index] ?? null;

            if (target === null || typeof target === 'object') {
                skip(target?.skip ?? 'below_list');

                return;
            }

            if (!byRef.has(target)) {
                skip('no_entry');

                return;
            }

            refs = [target];
        } else if (tableMode) {
            refs = [...new Set(byTable.get(Number(name)) ?? [])];
        } else if (codeOf(name) !== null) {
            refs = [...new Set(byCode.get(codeOf(name)) ?? [])];

            if (refs.length === 0) {
                skip('unknown_code');

                return;
            }
        } else {
            refs = [...new Set(byKey.get(nameKey(name)) ?? [])];

            if (refs.length === 0) {
                const reason = notFoundReason(model, roundId, name, team);

                if (reason === 'unknown_entry' && createTeams && cleanTeamName(name) !== null) {
                    newTeam = cleanTeamName(name);
                } else {
                    skip(reason);

                    return;
                }
            }
        }

        if (value === '') {
            // A paste never clears a saved result - Delete does
            skip('empty');

            return;
        }

        const parsed = parseResultInput(value, parse);

        if (parsed.kind === 'error') {
            lines.push({ id, index, name, value, status: 'error', reason: 'unreadable', parsed, ref: refs.length === 1 ? refs[0] : null, candidates: refs.length > 1 ? refs : [], newTeam });

            return;
        }

        if (newTeam !== null) {
            lines.push({ id, index, name, value, status: 'new_team', reason: null, ref: null, candidates: [], newTeam, newKey: foldSearchText(newTeam), to: parsed.result });

            return;
        }

        lines.push({ id, index, name, value, status: refs.length > 1 ? 'ambiguous' : 'change', reason: null, ref: refs.length === 1 ? refs[0] : null, candidates: refs.length > 1 ? refs : [], to: parsed.result });
    });

    // The same entry (or the same new pair/team) twice: the later line wins
    const last = new Map();

    for (const line of lines) {
        const key = line.status === 'change' ? line.ref : (line.status === 'new_team' ? `new:${line.newKey}` : null);

        if (key !== null) {
            const earlier = last.get(key);

            if (earlier) {
                earlier.status = 'skip';
                earlier.reason = 'listed_again';
            }

            last.set(key, line);
        }
    }

    for (const line of lines) {
        if (line.status === 'change') {
            const entry = byRef.get(line.ref);
            line.from = entry.result ?? null;

            if (sameValue(line.from, line.to)) {
                line.status = 'same';
            }
        }
    }

    return { lines, changes: resultChangesOf(roundId, lines, byRef, {}), counts: countResults(lines), header, byTable: tableMode };
}

/**
 * Why a pasted name finds no entry: `waitlisted` (in the round, on the waitlist), `no_entry` (in the round, not saved
 * yet), `not_in_round`, else `unknown_name` (a solo round: nobody of the event) / `unknown_entry` (a pair/team round:
 * no pair, team or person of the round).
 */
function notFoundReason(model, roundId, name, team) {
    const people = model.peopleNamed(name);

    if (people.length === 0) {
        return team ? 'unknown_entry' : 'unknown_name';
    }

    const inRound = people.filter((person) => model.placeValue(person.id, roundId) !== OUT);

    if (inRound.some((person) => model.isWaitlisted(person.id))) {
        return 'waitlisted';
    }

    if (inRound.some((person) => model.entryRef(person.id, roundId) === null)) {
        return 'no_entry';
    }

    return 'not_in_round';
}

function countResults(lines) {
    const counts = { changes: 0, same: 0, replaces: 0, skipped: 0, unreadable: 0, ambiguous: 0, newTeams: 0 };

    for (const line of lines) {
        if (line.status === 'change') {
            counts.changes++;

            if (line.from !== null && line.from !== undefined) {
                counts.replaces++;
            }
        } else if (line.status === 'same') {
            counts.same++;
        } else if (line.status === 'error') {
            counts.unreadable++;
        } else if (line.status === 'ambiguous') {
            counts.ambiguous++;
        } else if (line.status === 'new_team') {
            counts.newTeams++;
        } else {
            counts.skipped++;
        }
    }

    return counts;
}

/**
 * The RecordRoundResults changes of a planned results paste and the organiser's choices for ambiguous names
 * (`choices[lineId]` = an entry ref or SKIP) - `from` = what the page showed. Any number: the save queue sends them
 * in requests of MAX_RESULT_CHANGES.
 */
export function resultChangesOf(roundId, lines, byRef, choices = {}) {
    const changes = [];
    const seen = new Set();

    for (const line of [...lines].reverse()) {
        let ref = null;

        if (line.status === 'change') {
            ref = line.ref;
        } else if (line.status === 'ambiguous') {
            const choice = choices[line.id] ?? SKIP;
            ref = choice === SKIP || choice === CHOOSE ? null : choice;
        }

        if (ref === null || seen.has(ref) || !byRef.has(ref)) {
            continue;
        }

        seen.add(ref);
        const from = byRef.get(ref).result ?? null;

        if (!sameValue(from, line.to)) {
            changes.unshift({ roundId, ref, field: 'result', from, to: line.to });
        }
    }

    return changes;
}

/** The results changes after the preview's choices (ambiguous names picked). */
export function chosenResultChanges(roundId, plan, entries, selection = {}) {
    const byRef = new Map(entries.filter((entry) => entry.ref !== null).map((entry) => [entry.ref, entry]));

    return resultChangesOf(roundId, plan.lines, byRef, selection.choices ?? {});
}

/**
 * BR16: the pairs/teams a results paste creates (ticked "Create the team" lines - `ticks[lineId]`, ticked unless
 * unticked) and their results: `{teams: action|null, results: changes}` - the teams' action is performed first (a
 * result never overtakes the group creating its pair/team).
 */
export function newTeamsOfResults(model, roundId, plan, selection = {}, { newId = newClientId, countries = null } = {}) {
    const groups = [];
    const results = [];

    for (const line of plan.lines) {
        if (line.status !== 'new_team' || selection.ticks?.[line.id] === false) {
            continue;
        }

        const id = newId();
        groups.push([{ op: 'newTeam', id, round: roundId, name: line.newTeam }]);
        results.push({ roundId, ref: `team:${id}`, field: 'result', from: null, to: line.to });
    }

    if (groups.length === 0) {
        return { teams: null, results: [] };
    }

    const teams = buildAction(model, groups, { label: { key: 'paste' }, newId, countries });
    const made = new Set(teams.groups.map((group) => group.changes[0].id));

    return { teams, results: results.filter((change) => made.has(change.ref.slice('team:'.length))) };
}
