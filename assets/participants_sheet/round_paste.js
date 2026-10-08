/**
 * Pasting into a round tab of the participants spreadsheet (docs/features/competitions-management/participants-spreadsheet.md
 * §4 (C), §5 "Same name in a round", §11b; contract D9, O1) - the match-then-confirm planners, pure:
 *
 * - **rows of teams** (`Team ⇥ member ⇥ member …`, anchored on the new-team row or wider than the anchor allows): a team
 *   name equal (cleaned, case-insensitive) to **one** pair/team of the round → that one (its members become exactly the
 *   pasted ones, members it had and the paste does not list go to the tray), to **several** → ambiguous (the preview
 *   asks which one or a new one), to none → a new one - unless the pasted people form exactly one pair/team of the
 *   round already (then that one, renamed); a row without a name whose people form exactly one pair/team → that one
 *   (no duplicate). Each person: the event's active people by name (exact, then folded like ParticipantNameKey); one →
 *   them, several → ambiguous (the preview lists them), none → "add as a new participant" (ticked by default, D9);
 *   somebody of another pair/team of the round moves ("moves from Table 12 · Pinecones").
 * - **positional** (anchored on existing rows): every pasted row writes the columns it covers of the row it lands on
 *   (name → rename, member column → that member; an empty member cell clears it); rows past the end are matched like
 *   rows of teams.
 * - **results** (`name ⇥ result`, or one column onto the Result column): names matched only to entries of this round
 *   (a pair's/team's name or a member's name, a person's name in a solo round; folded) - unknown and not-in-round names
 *   are listed and skipped, results already there are shown as "replaces 1:24:00", unreadable results listed.
 *
 * A plan only describes; buildTeamPasteAction() turns it and the organiser's choices into one action (a group per
 * pasted row - atomic on the server, so one refused row never half-applies) and officialEdits() the results changes.
 * Pinned by tests/participants-sheet-round-harness.mjs.
 */

import { newClientId } from '../official_results_api.js';
import { buildAction } from './sheet_changes.js';
import { IN, OUT, cleanName, cleanTeamName, nameKey, parsePlace, teamPlace } from './sheet_model.js';
import { trimCell } from './tsv.js';
import { parseResultInput, sameValue } from './sheet_results.js';

export const NEW_TEAM = 'new';
export const SKIP = 'skip';
// The results endpoint takes at most this many changes in one set
export const MAX_RESULT_CHANGES = 500;

// ---------------------------------------------------------------- matching people and teams

/**
 * The event's active people a pasted name means: exactly that name first (cleaned like the server), else the
 * ParticipantNameKey fold. Several → the people in the round come first.
 *
 * @returns {{status: 'one'|'several'|'none', ids: string[]}}
 */
export function matchPerson(model, text, roundId = null) {
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

    const rank = (person) => {
        if (roundId === null) {
            return 1;
        }

        const place = parsePlace(model.placeValue(person.id, roundId)).kind;

        return place === IN ? 0 : (place === OUT ? 1 : 2);
    };

    return { status: 'several', ids: found.slice().sort((a, b) => rank(a) - rank(b)).map((person) => person.id) };
}

/** Pairs/teams of the round called `name` (cleaned, case-insensitive). */
export function teamsNamed(model, roundId, name) {
    const wanted = cleanTeamName(name);

    if (wanted === null) {
        return [];
    }

    const lower = wanted.toLowerCase();

    return model.teamsOf(roundId).filter((team) => team.name !== null && team.name.toLowerCase() === lower).map((team) => team.id);
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

// ---------------------------------------------------------------- rows of teams

/**
 * @typedef {object} PastedMember
 * @property {number} column       the block column
 * @property {number|null} slot    positional: the member column it lands on (0 = Member 1)
 * @property {string} text
 * @property {'one'|'several'|'none'|'empty'} status
 * @property {string[]} ids        the people it may mean (one: [id]; several: candidates)
 * @property {string|null} key     name key of a new person (status none)
 *
 * @typedef {object} PastedLine
 * @property {string} id           `t<index>` - the preview line of the row
 * @property {number} index        the block row
 * @property {'existing'|'new'|'ambiguous'|'skip'} match
 * @property {string|null} teamId  existing: the pair/team; positional rows: the row's pair/team
 * @property {string[]} candidates ambiguous: the pairs/teams of that name
 * @property {boolean} positional
 * @property {boolean} nameCovered the block covers the name column
 * @property {string|null} name    the pasted name (cleaned; null = none)
 * @property {boolean} byMembers   matched because its people form exactly that pair/team
 * @property {boolean} membersCovered
 * @property {PastedMember[]} members
 */

/**
 * Plans a paste of pair/team rows.
 *
 * @param {object} model
 * @param {string} roundId
 * @param {string[][]} block
 * @param {{columns: Array<'name'|'member'|'ignore'>, slots?: Array<number|null>, targets?: Array<string|null>,
 *          slotsOf?: function(string): string[]}} layout `columns` = what each block column is; positional pastes give
 *          `slots` (member column of each block column) and `targets` (the pair/team row each block row lands on,
 *          null past the end); `slotsOf(teamId)` = the members as the grid shows them
 * @returns {{roundId: string, lines: PastedLine[], newNames: Array<{key: string, name: string, lines: string[]}>,
 *            counts: object}}
 */
export function planTeamPaste(model, roundId, block, layout) {
    const columns = layout.columns;
    const lines = [];
    const newNames = new Map();
    const seenPeople = new Map();

    block.forEach((values, index) => {
        const cells = columns.map((kind, column) => ({ kind, column, text: trimCell(values[column] ?? '') }));
        const covered = cells.filter((cell) => cell.kind !== 'ignore' && cell.column < values.length);

        if (covered.every((cell) => cell.text === '')) {
            return;
        }

        const target = layout.targets?.[index] ?? null;
        const positional = target !== null;
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
                const key = found.status === 'none' ? nameKey(cell.text) : null;

                if (key !== null) {
                    const entry = newNames.get(key) ?? { key, name: cleanName(cell.text), lines: [] };
                    entry.lines.push(`t${index}`);
                    newNames.set(key, entry);
                }

                return { column: cell.column, slot, text: cleanName(cell.text), status: found.status, ids: found.ids, key };
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

        // The same person on two lines: the later line wins (they move there) - said in the preview
        for (const member of members) {
            if (member.status === 'one') {
                if (seenPeople.has(member.ids[0])) {
                    line.twice.push(member.ids[0]);
                }

                seenPeople.set(member.ids[0], line.id);
            }
        }

        lines.push(line);
    });

    return { roundId, lines, newNames: [...newNames.values()], counts: countTeamPaste(model, roundId, lines, newNames) };
}

function matchTeamLine(model, roundId, line) {
    const known = line.members.filter((member) => member.status === 'one').map((member) => member.ids[0]);
    const allKnown = line.membersCovered && line.members.every((member) => member.status === 'one' || member.status === 'empty');
    const byMembers = allKnown ? teamOfExactly(model, roundId, known) : null;

    if (line.name !== null) {
        const named = teamsNamed(model, roundId, line.name);

        if (named.length === 1) {
            line.match = 'existing';
            line.teamId = named[0];

            return;
        }

        if (named.length > 1) {
            // The people decide when they are exactly one of them; otherwise the organiser does
            if (byMembers !== null && named.includes(byMembers)) {
                line.match = 'existing';
                line.teamId = byMembers;

                return;
            }

            line.match = 'ambiguous';
            line.candidates = named;

            return;
        }
    }

    if (byMembers !== null) {
        line.match = 'existing';
        line.teamId = byMembers;
        line.byMembers = true;
    }
}

function countTeamPaste(model, roundId, lines, newNames) {
    const counts = { newTeams: 0, moves: 0, newPeople: newNames.size, ambiguousTeams: 0, ambiguousPeople: 0, sharedNames: 0, renames: 0 };
    const names = new Map();

    for (const team of model.teamsOf(roundId)) {
        if (team.name !== null) {
            names.set(team.name.toLowerCase(), (names.get(team.name.toLowerCase()) ?? 0) + 1);
        }
    }

    for (const line of lines) {
        if (line.match === NEW_TEAM) {
            counts.newTeams++;

            if (line.name !== null) {
                const key = line.name.toLowerCase();
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
        if (line.match === NEW_TEAM && line.name !== null && (names.get(line.name.toLowerCase()) ?? 0) > 1) {
            counts.sharedNames++;
        }
    }

    return counts;
}

/**
 * The person a pasted member means after the organiser's choices: an id, `{new: key}` for a ticked new person, or
 * null (left out).
 *
 * @param {{choices: object, ticks: object}} selection the preview dialog's answer (line ids: `p<row>:<column>` for an
 *        ambiguous person, `n<key>` for a new name, `t<row>` for an ambiguous pair/team)
 */
export function chosenPerson(line, member, selection) {
    if (member.status === 'one') {
        return member.ids[0];
    }

    if (member.status === 'several') {
        const choice = selection.choices?.[`p${line.index}:${member.column}`] ?? member.ids[0];

        return choice === SKIP ? null : choice;
    }

    if (member.status === 'none') {
        return selection.ticks?.[`n${member.key}`] === false ? null : { new: member.key };
    }

    return null;
}

/** The pair/team a pasted row goes to after the organiser's choices: an id or NEW_TEAM. */
export function chosenTeam(line, selection) {
    if (line.match === 'ambiguous') {
        return selection.choices?.[line.id] ?? line.candidates[0];
    }

    return line.match === 'existing' ? line.teamId : NEW_TEAM;
}

/**
 * One action from a planned paste and the organiser's choices: a group per pasted row (new people, the pair/team, its
 * members - computed on a scratch state row after row, so `from` of a later row sees the earlier ones), one undo step.
 *
 * @param {{choices?: object, ticks?: object}} selection
 * @param {{newId?: function(): string, countries?: Set<string>|null, slotsOf?: function(string): string[],
 *          skip?: Set<string>}} [options] `skip` = line ids left out (refused by the dry run)
 * @returns {object} the action (sheet_changes.js), plus `teams` = new pair/team ids by line id, `lineIds` = the
 *          line of each group, `refusedLines` = [{lineId, error}] of the client refusals
 */
export function buildTeamPasteAction(model, roundId, plan, selection = {}, { newId = newClientId, countries = null, slotsOf = null, skip = new Set() } = {}) {
    const working = model.scratch();
    const now = model.now();
    const created = new Map();
    const groups = [];
    const lineIds = [];
    const teams = {};

    for (const line of plan.lines) {
        if (skip.has(line.id)) {
            // Refused by the server's dry run - not sent again
            continue;
        }

        const target = chosenTeam(line, selection);
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

/**
 * Plans a results paste. `mode: 'names'` = `name ⇥ result` rows matched to the round's entries; `'positional'` = one
 * column landing on the rows' Result cells (`targets` = the entry ref of each block row, null past the end).
 *
 * @param {object} model
 * @param {string} roundId
 * @param {Array<object>} entries round_common.js roundEntries() - only these can get a result
 * @param {string[][]} block
 * @param {{mode: 'names'|'positional', targets?: Array<string|null>, parse?: object}} options
 * @returns {{lines: Array<object>, changes: Array<object>, counts: object}}
 */
export function planResultsPaste(model, roundId, entries, block, { mode, targets = [], parse = {} }) {
    const byRef = new Map(entries.filter((entry) => entry.ref !== null).map((entry) => [entry.ref, entry]));
    const byKey = new Map();

    for (const entry of entries) {
        if (entry.ref === null) {
            continue;
        }

        for (const name of new Set(entry.names.map((candidate) => nameKey(candidate)))) {
            byKey.set(name, [...(byKey.get(name) ?? []), entry.ref]);
        }
    }

    const lines = [];

    block.forEach((row, index) => {
        const name = mode === 'names' ? trimCell(row[0] ?? '') : '';
        const value = trimCell(mode === 'names' ? (row[1] ?? '') : (row[0] ?? ''));
        const id = `r${index}`;

        if (mode === 'names' && name === '' && value === '') {
            return;
        }

        let refs = [];

        if (mode === 'positional') {
            const ref = targets[index] ?? null;

            if (ref === null || !byRef.has(ref)) {
                lines.push({ id, index, name, value, status: 'skip', reason: ref === null ? 'below_list' : 'no_entry', ref: null, candidates: [] });

                return;
            }

            refs = [ref];
        } else {
            refs = [...new Set(byKey.get(nameKey(name)) ?? [])];

            if (refs.length === 0) {
                lines.push({ id, index, name, value, status: 'skip', reason: notFoundReason(model, roundId, name), ref: null, candidates: [] });

                return;
            }
        }

        const parsed = parseResultInput(value, parse);

        if (parsed.kind === 'error') {
            lines.push({ id, index, name, value, status: 'error', reason: 'unreadable', parsed, ref: refs.length === 1 ? refs[0] : null, candidates: refs.length > 1 ? refs : [] });

            return;
        }

        const to = parsed.kind === 'empty' ? null : parsed.result;
        lines.push({ id, index, name, value, status: refs.length > 1 ? 'ambiguous' : 'change', reason: null, ref: refs.length === 1 ? refs[0] : null, candidates: refs.length > 1 ? refs : [], to });
    });

    // The same entry twice: the later line wins
    const last = new Map();

    for (const line of lines) {
        if (line.ref !== null && (line.status === 'change')) {
            const earlier = last.get(line.ref);

            if (earlier) {
                earlier.status = 'skip';
                earlier.reason = 'listed_again';
            }

            last.set(line.ref, line);
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

    return { lines, changes: resultChangesOf(roundId, lines, byRef, {}), counts: countResults(lines) };
}

function notFoundReason(model, roundId, name) {
    const people = model.peopleNamed(name);

    if (people.length === 0) {
        return 'unknown_name';
    }

    if (people.some((person) => model.isWaitlisted(person.id) && model.placeValue(person.id, roundId) !== OUT)) {
        return 'waitlisted';
    }

    return 'not_in_round';
}

function countResults(lines) {
    const counts = { changes: 0, same: 0, replaces: 0, skipped: 0, unreadable: 0, ambiguous: 0 };

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
        } else {
            counts.skipped++;
        }
    }

    return counts;
}

/**
 * The RecordRoundResults changes of a planned results paste and the organiser's choices for ambiguous names
 * (`choices[lineId]` = an entry ref or SKIP) - `from` = what the page showed, at most MAX_RESULT_CHANGES.
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
            ref = choice === SKIP ? null : choice;
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

    return changes.slice(0, MAX_RESULT_CHANGES);
}

/** The results changes after the preview's choices (ambiguous names picked). */
export function chosenResultChanges(roundId, plan, entries, selection = {}) {
    const byRef = new Map(entries.filter((entry) => entry.ref !== null).map((entry) => [entry.ref, entry]));

    return resultChangesOf(roundId, plan.lines, byRef, selection.choices ?? {});
}
