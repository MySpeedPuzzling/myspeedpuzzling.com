/**
 * The participants spreadsheet's changes (contract §3.1, docs/features/competitions-management/participants-spreadsheet.md
 * "Client architecture (as built)"): one builder per user action, each returning an **action**
 *
 *   {label, groups: [{id, changes}], inverse: [{id, inverseOf, changes}], errors: [{reason, change}], results?: []}
 *
 * - a group is atomic on the server (every change of it goes through, or none); an action = one undo step and may hold
 *   several groups when its parts are independent (a bulk "Solo: in" over 40 rows = 40 groups, one refused row does not
 *   block the others);
 * - every change carries `from` = the value the organiser saw (what the model shows, the organiser's own pending values
 *   included), so somebody else's change in between comes back as a conflict instead of being overwritten. An editor
 *   that was open while the model changed passes what it showed when it OPENED as `options.from` (SheetGrid's
 *   `seenValue` → `commit(…, {seen})`): never the model's value at commit time, which may be somebody else's change
 *   that arrived meanwhile and would be reverted silently. A value equal to that `from` is no change at all;
 * - `inverse` is computed against the model before the action, change by change on a scratch state, so composite
 *   actions undo exactly (a deleted team is created again with the same id and its members put back; a pair/team the
 *   server deletes automatically when emptied is created again first);
 * - `errors` = changes refused already in the browser by the rules that need no server data (blank name, lengths,
 *   unknown country, the results guard on what the page knows, a profile linked elsewhere...) - those never leave the
 *   browser; the reason codes are the server's (`participants_sheet_server.reason.<code>`).
 *
 * Keys starting with `_` on a change are hints for the optimistic display (e.g. `_player`: who was picked) and are
 * stripped before sending (wireChange()). Pure - pinned by tests/participants-sheet-core-harness.mjs.
 */

import { newClientId } from '../official_results_api.js';
import {
    IN,
    OUT,
    isGoing,
    EXTERNAL_ID_MAX_LENGTH,
    NAME_MAX_LENGTH,
    NOTE_MAX_LENGTH,
    TEAM_NAME_MAX_LENGTH,
    TEAM_SIZE_MAX,
    TEAM_SIZE_MIN,
    cleanName,
    cleanOptionalText,
    cleanTeamName,
    hasOfficialData,
    parsePlace,
    teamPlace,
    textLength,
    Working,
} from './sheet_model.js';

export const OPS = ['newParticipant', 'field', 'player', 'place', 'newTeam', 'renameTeam', 'deleteTeam', 'remove', 'restore', 'teamSize'];
export const FIELDS = ['name', 'country', 'externalId', 'note'];
// Request limits of the endpoint (contract §3.1)
export const MAX_GROUPS = 1000;
export const MAX_CHANGES = 5000;
export const MAX_CHANGES_PER_GROUP = 500;

/**
 * The `from` of a change: `options.from` when the caller passes what the organiser saw (an editor opened before the
 * model changed), else the model's value. `options.from` is one value, or - for builders over several people - a Map
 * or a function personId → value (undefined = the model's).
 */
export function fromFor(options, key, modelValue) {
    const given = options?.from;

    if (given === undefined) {
        return modelValue;
    }

    let value = given;

    if (given instanceof Map) {
        value = given.has(key) ? given.get(key) : undefined;
    } else if (typeof given === 'function') {
        value = given(key);
    }

    return value === undefined ? modelValue : value;
}

/** Options without `from` - passed on to buildAction, which knows nothing of it. */
function actionOptions(options) {
    const { from, ...rest } = options ?? {};

    return rest;
}

/** The change as the server reads it - display hints (`_…`) left out. */
export function wireChange(change) {
    return Object.fromEntries(Object.entries(change).filter(([key]) => !key.startsWith('_')));
}

export function wireGroups(groups) {
    return groups.map((group) => ({ id: group.id, changes: group.changes.map(wireChange) }));
}

/** A field's value cleaned like the server cleans it (what the server stores and later compares `from` with). */
export function cleanFieldValue(field, value) {
    if (field === 'name') {
        return cleanName(value);
    }

    if (field === 'country') {
        return value === null || value === undefined || String(value).trim() === '' ? null : String(value).trim().toLowerCase();
    }

    return cleanOptionalText(value);
}

/**
 * Where a change shows: its marker key and the entities whose cells re-render when the marker changes.
 *
 * @returns {{key: string, people: string[], teams: string[], rounds: string[]}}
 */
export function changeTarget(change, model = null) {
    switch (change.op) {
        case 'newParticipant':
            return { key: `person:${change.id}:name`, people: [change.id], teams: [], rounds: [] };
        case 'field':
            return { key: `person:${change.participant}:${change.field}`, people: [change.participant], teams: [], rounds: [] };
        case 'player':
            return { key: `person:${change.participant}:player`, people: [change.participant], teams: [], rounds: [] };
        case 'remove':
        case 'restore':
            return { key: `person:${change.participant}:removed`, people: [change.participant], teams: [], rounds: [] };
        case 'place': {
            const teams = [parsePlace(change.from).teamId, parsePlace(change.to).teamId].filter(Boolean);

            return { key: `place:${change.participant}:${change.round}`, people: [change.participant], teams, rounds: [change.round] };
        }
        case 'newTeam':
            return { key: `team:${change.id}:name`, people: [], teams: [change.id], rounds: [change.round] };
        case 'renameTeam':
        case 'deleteTeam': {
            const roundId = model?.team(change.team)?.roundId;

            return {
                key: `team:${change.team}:${change.op === 'renameTeam' ? 'name' : 'delete'}`,
                people: model ? model.membersOf(change.team).map((person) => person.id) : [],
                teams: [change.team],
                rounds: roundId ? [roundId] : [],
            };
        }
        case 'teamSize':
            return { key: `round:${change.round}:teamSize`, people: [], teams: [], rounds: [change.round] };
        default:
            return { key: `change:${change.op}`, people: [], teams: [], rounds: [] };
    }
}

/**
 * The rules of contract §3.1 the browser can check on its own data - the reason code (the server's) or null.
 * `countries` = the known CountryCode names (Set); without it any two-letter code passes.
 *
 * @param {object} change
 * @param {import('./sheet_model.js').SheetModel|import('./sheet_model.js').Working} state what the change applies to
 * @param {{countries?: Set<string>|null}} [options]
 */
export function checkChange(change, state, { countries = null } = {}) {
    const person = (id) => (typeof state.person === 'function' ? state.person(id) : null);
    const removed = (id) => (person(id)?.removedAt ?? null) !== null;

    switch (change.op) {
        case 'newParticipant': {
            const name = cleanName(change.name);

            if (name === '') {
                return 'name_blank';
            }

            if (textLength(name) > NAME_MAX_LENGTH) {
                return 'name_too_long';
            }

            if (change.country !== null && change.country !== undefined && !knownCountry(change.country, countries)) {
                return 'invalid_country';
            }

            const externalId = cleanOptionalText(change.externalId);

            if (textLength(externalId ?? '') > EXTERNAL_ID_MAX_LENGTH) {
                return 'external_id_too_long';
            }

            return externalId !== null && externalIdTaken(state, externalId, change.id) ? 'external_id_taken' : null;
        }

        case 'field': {
            if (person(change.participant) === null) {
                return 'participant_not_found';
            }

            if (removed(change.participant)) {
                return 'participant_removed';
            }

            const value = cleanFieldValue(change.field, change.to);

            switch (change.field) {
                case 'name':
                    if (value === '') {
                        return 'name_blank';
                    }

                    return textLength(value) > NAME_MAX_LENGTH ? 'name_too_long' : null;
                case 'country':
                    return value === null || knownCountry(value, countries) ? null : 'invalid_country';
                case 'externalId':
                    if (textLength(value ?? '') > EXTERNAL_ID_MAX_LENGTH) {
                        return 'external_id_too_long';
                    }

                    // Another active participant of the event has it (the import's rule, ParticipantRules)
                    return value !== null && externalIdTaken(state, value, change.participant) ? 'external_id_taken' : null;
                case 'note':
                    return textLength(value ?? '') > NOTE_MAX_LENGTH ? 'note_too_long' : null;
                default:
                    return 'invalid_change';
            }
        }

        case 'player': {
            if (person(change.participant) === null) {
                return 'participant_not_found';
            }

            if (removed(change.participant)) {
                return 'participant_removed';
            }

            return change.to !== null && linkedElsewhere(state, change.to, change.participant) ? 'player_linked_elsewhere' : null;
        }

        case 'place': {
            if (person(change.participant) === null) {
                return 'participant_not_found';
            }

            const round = roundOf(state, change.round);

            if (round === null) {
                return 'round_not_found';
            }

            if (removed(change.participant)) {
                return 'participant_removed';
            }

            const target = parsePlace(change.to);

            if (target.kind === 'team') {
                if (round.category === 'solo') {
                    return 'not_a_team_round';
                }

                const team = state.team(target.teamId);

                if (team !== null && team.roundId !== change.round) {
                    return 'team_of_another_round';
                }
            }

            // Out of the round: refused when the person's own data is in it (their solo entry's result or qualified mark,
            // their linked player's own time). A pair's/team's result does not hold its members - leaving it is allowed
            // while it keeps a going member (checkGroup(): `team_has_result` when a group would leave it without one)
            if (target.kind === OUT && parsePlace(change.from).kind !== OUT && ownDataInRound(state, change.participant, change.round)) {
                return 'has_result_in_round';
            }

            return null;
        }

        case 'newTeam': {
            const round = roundOf(state, change.round);

            if (round === null) {
                return 'round_not_found';
            }

            if (round.category === 'solo') {
                return 'not_a_team_round';
            }

            return textLength(cleanTeamName(change.name) ?? '') > TEAM_NAME_MAX_LENGTH ? 'team_name_too_long' : null;
        }

        case 'renameTeam':
            if (state.team(change.team) === null) {
                return 'team_not_found';
            }

            return textLength(cleanTeamName(change.to) ?? '') > TEAM_NAME_MAX_LENGTH ? 'team_name_too_long' : null;

        case 'deleteTeam':
            return hasOfficialData(state.team(change.team)) ? 'team_has_result' : null;

        case 'remove':
            if (person(change.participant) === null) {
                return 'participant_not_found';
            }

            return !removed(change.participant) && holdsDataInEvent(state, change.participant) ? 'has_result_in_event' : null;

        case 'restore': {
            const restored = person(change.participant);

            if (restored === null) {
                return 'participant_not_found';
            }

            return restored.removedAt !== null && restored.player && linkedElsewhere(state, restored.player.id, restored.id) ? 'player_linked_elsewhere' : null;
        }

        case 'teamSize': {
            const round = roundOf(state, change.round);

            if (round === null) {
                return 'round_not_found';
            }

            if (round.category !== 'team') {
                return 'not_a_team_round';
            }

            return change.to === null || (Number.isInteger(change.to) && change.to >= TEAM_SIZE_MIN && change.to <= TEAM_SIZE_MAX) ? null : 'invalid_team_size';
        }

        default:
            return 'invalid_change';
    }
}

function knownCountry(code, countries) {
    if (countries instanceof Set && countries.size > 0) {
        return countries.has(code);
    }

    return /^[a-z]{2}$/.test(code);
}

function roundOf(state, roundId) {
    if (typeof state.round === 'function') {
        return state.round(roundId);
    }

    return state.rounds?.get(roundId) ?? null;
}

function peopleOf(state) {
    return typeof state.people === 'function' ? state.people({ includeRemoved: true }) : [...state.people.values()];
}

function linkedElsewhere(state, playerId, personId) {
    return peopleOf(state).some((other) => other.id !== personId && other.removedAt === null && other.player?.id === playerId);
}

function externalIdTaken(state, externalId, personId) {
    return peopleOf(state).some((other) => other.id !== personId && other.removedAt === null && (other.externalId ?? null) === externalId);
}

/** The person's own data in the round - not their pair's/team's (a member may leave a team holding a result). */
function ownDataInRound(state, personId, roundId) {
    const place = state.place(personId, roundId);

    if (place !== null && hasOfficialData(place)) {
        return true;
    }

    return (state.person(personId)?.playerResultRounds ?? []).includes(roundId);
}

function holdsDataInRound(state, personId, roundId) {
    if (typeof state.holdsDataInRound === 'function') {
        return state.holdsDataInRound(personId, roundId);
    }

    const place = state.place(personId, roundId);

    if (place !== null && (hasOfficialData(place) || (place.teamId !== null && hasOfficialData(state.team(place.teamId))))) {
        return true;
    }

    return (state.person(personId)?.playerResultRounds ?? []).includes(roundId);
}

function holdsDataInEvent(state, personId) {
    if (typeof state.holdsDataInEvent === 'function') {
        return state.holdsDataInEvent(personId);
    }

    for (const place of state.places.values()) {
        if (place.participantId === personId && holdsDataInRound(state, personId, place.roundId)) {
            return true;
        }
    }

    return (state.person(personId)?.playerResultRounds ?? []).length > 0;
}

// ---------------------------------------------------------------- refusals in words

/**
 * What a client refusal says - the server's text for the same refusal (`participants_sheet_server.reason.<key>`, the
 * cause variants included: `has_result_in_round_own_time`, `team_has_result_emptied`, …) and its parameters, read
 * from what the page knows. A `null` team name is "(no name)" - the caller words it.
 *
 * @param {{reason: string, change?: object, cause?: string, teamId?: string}} error an action's error
 * @param {object} state the model (or a Working)
 * @returns {{key: string, params: object}}
 */
export function refusalDetails(error, state) {
    const reason = error?.reason ?? 'invalid_change';
    const change = error?.change ?? {};
    const personOf = (id) => (typeof state?.person === 'function' && id ? state.person(id) : null);
    const roundName = (id) => (id ? roundOf(state, id)?.name ?? '' : '');
    const team = (id) => (typeof state?.team === 'function' && id ? state.team(id) : null);
    const name = personOf(change.participant ?? change.id)?.name ?? (typeof change.name === 'string' ? cleanName(change.name) : '');

    switch (reason) {
        case 'participant_removed':
            return { key: reason, params: { name } };
        case 'name_too_long':
            return { key: reason, params: { max: NAME_MAX_LENGTH } };
        case 'external_id_too_long':
            return { key: reason, params: { max: EXTERNAL_ID_MAX_LENGTH } };
        case 'note_too_long':
            return { key: reason, params: { max: NOTE_MAX_LENGTH } };
        case 'team_name_too_long':
            return { key: reason, params: { max: TEAM_NAME_MAX_LENGTH } };
        case 'invalid_country':
            return { key: reason, params: { country: String(change.op === 'newParticipant' ? change.country ?? '' : change.to ?? '') } };
        case 'invalid_team_size':
            return { key: reason, params: { min: TEAM_SIZE_MIN, max: TEAM_SIZE_MAX } };
        case 'external_id_taken': {
            const id = cleanOptionalText(change.op === 'newParticipant' ? change.externalId : change.to);
            const other = peopleOf(state).find((candidate) => candidate.id !== (change.participant ?? change.id) && candidate.removedAt === null && (candidate.externalId ?? null) === id);

            return { key: reason, params: { id: id ?? '', other: other?.name ?? '' } };
        }
        case 'player_linked_elsewhere': {
            const playerId = change.op === 'restore' ? personOf(change.participant)?.player?.id : change.to;
            const other = peopleOf(state).find((candidate) => candidate.id !== change.participant && candidate.removedAt === null && candidate.player?.id === playerId);

            return { key: reason, params: { other: other?.name ?? '' } };
        }
        case 'not_a_team_round':
            return { key: reason, params: { round: roundName(change.round) } };
        case 'team_of_another_round': {
            const target = team(parsePlace(change.to).teamId);

            return { key: reason, params: { team: target?.name ?? null, round: roundName(target?.roundId) } };
        }
        case 'has_result_in_round': {
            const own = !officialDataInRound(state, change.participant, change.round);

            return { key: own ? 'has_result_in_round_own_time' : reason, params: { name, round: roundName(change.round) } };
        }
        case 'has_result_in_event': {
            const rounds = roundIds(state);
            const official = rounds.find((roundId) => officialDataInRound(state, change.participant, roundId));
            const ownTime = rounds.find((roundId) => (personOf(change.participant)?.playerResultRounds ?? []).includes(roundId));

            return official !== undefined || ownTime === undefined
                ? { key: reason, params: { name, round: roundName(official ?? rounds[0]) } }
                : { key: 'has_result_in_event_own_time', params: { name, round: roundName(ownTime) } };
        }
        case 'team_has_result': {
            const teamId = error.teamId ?? change.team ?? null;
            const record = team(teamId);
            const key = error.cause === 'emptied' || error.cause === 'waitlisted_only' ? `team_has_result_${error.cause}` : reason;

            return { key, params: { team: record?.name ?? null, round: roundName(record?.roundId) } };
        }
        default:
            return { key: reason, params: {} };
    }
}

function roundIds(state) {
    if (typeof state?.rounds === 'function') {
        return state.rounds().map((round) => round.id);
    }

    return [...(state?.rounds?.keys?.() ?? [])];
}

/** Official data of the person's own entry or of their pair/team in the round (not their own time). */
function officialDataInRound(state, personId, roundId) {
    const place = typeof state?.place === 'function' ? state.place(personId, roundId) : null;

    return place !== null && (hasOfficialData(place) || (place.teamId !== null && hasOfficialData(state.team(place.teamId))));
}

// ---------------------------------------------------------------- inverting

/**
 * One change undone, read against the state right before it.
 */
function invertChange(change, before) {
    switch (change.op) {
        case 'newParticipant':
            return before.person(change.id) === null ? [{ op: 'remove', participant: change.id }] : [];
        case 'field':
            return [{ op: 'field', participant: change.participant, field: change.field, from: cleanFieldValue(change.field, change.to), to: change.from }];
        case 'player': {
            const previous = before.person(change.participant)?.player ?? null;
            const inverse = { op: 'player', participant: change.participant, from: change.to, to: change.from };

            if (change.from !== null && previous !== null && previous.id === change.from) {
                inverse._player = previous;
            }

            return [inverse];
        }
        case 'place':
            return [{ op: 'place', participant: change.participant, round: change.round, from: change.to, to: change.from }];
        case 'newTeam':
            return before.team(change.id) === null ? [{ op: 'deleteTeam', team: change.id }] : [];
        case 'renameTeam':
            return [{ op: 'renameTeam', team: change.team, from: cleanTeamName(change.to), to: change.from }];
        case 'deleteTeam': {
            const team = before.team(change.team);

            if (team === null) {
                return [];
            }

            const members = before.placesInTeam(change.team).filter((place) => (before.person(place.participantId)?.removedAt ?? null) === null);

            return [
                { op: 'newTeam', id: team.id, round: team.roundId, name: team.name },
                ...members.map((place) => ({ op: 'place', participant: place.participantId, round: team.roundId, from: IN, to: teamPlace(team.id) })),
            ];
        }
        case 'remove':
            return before.person(change.participant)?.removedAt === null ? [{ op: 'restore', participant: change.participant }] : [];
        case 'restore':
            return (before.person(change.participant)?.removedAt ?? null) !== null ? [{ op: 'remove', participant: change.participant }] : [];
        case 'teamSize':
            return [{ op: 'teamSize', round: change.round, from: change.to, to: change.from }];
        default:
            return [];
    }
}

/**
 * The groups that undo `groups` (in reverse order, each `inverseOf` its forward group), computed against `model` as
 * it is before `groups` apply. Pairs/teams the server will delete automatically when a forward group empties them are
 * created again first in the inverse.
 */
export function invertGroups(groups, model, newId = newClientId) {
    const working = model.scratch();
    const now = model.now();
    const inverse = [];

    for (const group of groups) {
        const parts = [];
        working.applyGroup(group.changes, now, (change, before) => {
            parts.push(invertChange(change, before));
        });
        const recreated = working.lastAutoDeleted.map((team) => ({ op: 'newTeam', id: team.id, round: team.roundId, name: team.name }));
        const changes = [...recreated, ...parts.reverse().flat()];

        if (changes.length > 0) {
            inverse.unshift({ id: newId(), inverseOf: group.id, changes });
        }
    }

    return inverse;
}

// ---------------------------------------------------------------- actions

/**
 * An action from groups of changes: client checks first (a group with a refused change is left out and its refusal
 * reported), then the inverse of what remains.
 *
 * @param {object} model
 * @param {Array<Array<object>>} groupsOfChanges
 * @param {{label?: object|null, newId?: function(): string, countries?: Set<string>|null}} options
 */
export function buildAction(model, groupsOfChanges, { label = null, newId = newClientId, countries = null } = {}) {
    const errors = [];
    const groups = [];
    const working = model.scratch();
    const now = model.now();

    for (const changes of groupsOfChanges) {
        if (changes.length === 0) {
            continue;
        }

        const refused = checkGroup(changes, working, { countries, now });

        if (refused !== null) {
            errors.push(refused);
            continue;
        }

        working.applyGroup(changes, now);
        groups.push({ id: newId(), changes });
    }

    return { label, groups, inverse: invertGroups(groups, model, newId), errors };
}

/** One place change taking the last going member out of a pair/team that holds official data. */
function leavesTeamWithoutGoingMember(change, working) {
    if (change.op !== 'place') {
        return false;
    }

    const teamId = working.place(change.participant, change.round)?.teamId ?? null;

    if (teamId === null || parsePlace(change.to).teamId === teamId || !hasOfficialData(working.team(teamId))) {
        return false;
    }

    return working.goingMemberCount(teamId) - (isGoing(working.person(change.participant)) ? 1 : 0) <= 0;
}

/**
 * The client checks of one group (atomic on the server): every change against the state it applies to (the earlier
 * changes of the group included), then the rules about the group as a whole - a pair/team holding official data must
 * keep a going member (`team_has_result`), at most MAX_CHANGES_PER_GROUP changes (`too_many_changes`). `working` is
 * left exactly as it was (the trial is rolled back).
 *
 * @param {Array<object>} changes
 * @param {Working} working
 * @param {{countries?: Set<string>|null, now?: number}} [options]
 * @returns {{reason: string, change: object}|null}
 */
export function checkGroup(changes, working, { countries = null, now = Date.now() } = {}) {
    if (changes.length > MAX_CHANGES_PER_GROUP) {
        return { reason: 'too_many_changes', change: changes[0] };
    }

    if (changes.length === 1) {
        // One change (a bulk action is hundreds of these): checked without a trial
        const [change] = changes;
        const reason = checkChange(change, working, { countries });

        if (reason !== null) {
            return { reason, change };
        }

        if (leavesTeamWithoutGoingMember(change, working)) {
            const teamId = working.place(change.participant, change.round).teamId;
            // Somebody else stays, but only people on the waitlist
            const cause = working.activeMemberCount(teamId) > (working.person(change.participant)?.removedAt === null ? 1 : 0) ? 'waitlisted_only' : 'emptied';

            return { reason: 'team_has_result', change, cause, teamId };
        }

        return null;
    }

    working.begin();
    let refused = null;
    // teamId → the change that took a member out of it (the last one)
    const left = new Map();

    try {
        for (const change of changes) {
            const reason = checkChange(change, working, { countries });

            if (reason !== null) {
                refused = { reason, change };
                break;
            }

            if (change.op === 'place') {
                const teamId = working.place(change.participant, change.round)?.teamId ?? null;

                if (teamId !== null && parsePlace(change.to).teamId !== teamId) {
                    left.set(teamId, change);
                }
            }

            working.apply(change, now);
        }

        if (refused === null) {
            for (const [teamId, change] of left) {
                if (hasOfficialData(working.team(teamId)) && working.goingMemberCount(teamId) === 0) {
                    refused = { reason: 'team_has_result', change, cause: working.activeMemberCount(teamId) > 0 ? 'waitlisted_only' : 'emptied', teamId };
                    break;
                }
            }
        }
    } finally {
        working.rollback();
    }

    return refused;
}

/** Actions joined into one undo step (a paste touching several columns) - sheet groups and results changes alike. */
export function combine(label, ...actions) {
    const list = actions.filter(Boolean);

    return {
        label,
        groups: list.flatMap((action) => action.groups),
        inverse: list.slice().reverse().flatMap((action) => action.inverse),
        errors: list.flatMap((action) => action.errors),
        results: list.flatMap((action) => action.results ?? []),
        inverseResults: list.slice().reverse().flatMap((action) => action.inverseResults ?? []),
    };
}

export function isEmpty(action) {
    return action.groups.length === 0 && (action.results ?? []).length === 0;
}

// ---------------------------------------------------------------- builders: people
//
// Every builder takes `options` = {newId?, countries?, label?, from?}: `from` = what the organiser saw when the edit
// started (fromFor()) - the `from` sent instead of the model's value; the client checks still run on the model.

/**
 * Name / country / external id / note of a person. A value equal to what is shown (or to `options.from`, what the
 * editor showed when it opened) = an empty action.
 */
export function setField(model, personId, field, value, options = {}) {
    const person = model.person(personId);

    if (person === null) {
        return buildAction(model, [], actionOptions(options));
    }

    const to = cleanFieldValue(field, value);
    const from = fromFor(options, personId, person[field] ?? null);

    if (to === (person[field] ?? null) || to === from) {
        return buildAction(model, [], actionOptions(options));
    }

    return buildAction(model, [[{ op: 'field', participant: personId, field, from, to }]], { label: { key: 'field', field }, ...actionOptions(options) });
}

/**
 * One field of several people (fill down, paste of a column) - a group per person, one undo step.
 *
 * @param {Array<{personId: string, value: *, from?: *}>} values `from` = what the organiser saw (an open editor's row)
 */
export function setFields(model, field, values, options = {}) {
    const groups = [];

    for (const { personId, value, from: seen } of values) {
        const person = model.person(personId);

        if (person === null) {
            continue;
        }

        const to = cleanFieldValue(field, value);
        const from = seen !== undefined ? seen : fromFor(options, personId, person[field] ?? null);

        if (to !== (person[field] ?? null) && to !== from) {
            groups.push([{ op: 'field', participant: personId, field, from, to }]);
        }
    }

    return buildAction(model, groups, { label: { key: 'field', field }, ...actionOptions(options) });
}

/**
 * Link a person to an MSP profile (`player` = {id, name, code, avatar, country, profileUrl, visible?} from the player
 * search - `visible: false` for a profile hidden from this organiser, O9) or unlink it (null). `options.from` = the
 * player id the cell showed (or null).
 */
export function linkProfile(model, personId, player, options = {}) {
    const person = model.person(personId);
    const shown = person?.player?.id ?? null;
    const from = fromFor(options, personId, shown);
    const to = player === null ? null : player.id;

    if (person === null || shown === to || from === to) {
        return buildAction(model, [], actionOptions(options));
    }

    const change = { op: 'player', participant: personId, from, to };

    if (player !== null) {
        change._player = { visible: true, ...player };
    }

    return buildAction(model, [[change]], { label: { key: to === null ? 'unlink' : 'link' }, ...actionOptions(options) });
}

/**
 * A new person of the event (`source = manual`) with a client id - `person` = {name, country?, externalId?, id?}.
 */
export function addPerson(model, person, options = {}) {
    const newId = options.newId ?? newClientId;
    const change = newPersonChange(person, newId);

    return { ...buildAction(model, [[change]], { label: { key: 'add_person' }, ...actionOptions(options) }), personId: change.id };
}

function newPersonChange(person, newId) {
    const country = person.country === null || person.country === undefined || String(person.country).trim() === '' ? null : String(person.country).trim().toLowerCase();

    return {
        op: 'newParticipant',
        id: person.id ?? newId(),
        name: cleanName(person.name),
        country,
        externalId: cleanOptionalText(person.externalId ?? null),
    };
}

/** People taken off the event (soft delete) - a group per person. */
export function removePeople(model, personIds, options = {}) {
    const groups = personIds
        .filter((id) => model.person(id) !== null && !model.isRemoved(id))
        .map((id) => [{ op: 'remove', participant: id }]);

    return buildAction(model, groups, { label: { key: 'remove', count: groups.length }, ...actionOptions(options) });
}

export function restorePeople(model, personIds, options = {}) {
    const groups = personIds
        .filter((id) => model.isRemoved(id))
        .map((id) => [{ op: 'restore', participant: id }]);

    return buildAction(model, groups, { label: { key: 'restore', count: groups.length }, ...actionOptions(options) });
}

// ---------------------------------------------------------------- builders: rounds

/**
 * A person's place in a round: `out` | `in` | `team:<id>`. `options.from` = the place the organiser saw.
 */
export function setPlace(model, personId, roundId, to, options = {}) {
    const shown = model.placeValue(personId, roundId);
    const from = fromFor(options, personId, shown);

    if (shown === to || from === to) {
        return buildAction(model, [], actionOptions(options));
    }

    return buildAction(model, [[{ op: 'place', participant: personId, round: roundId, from, to }]], { label: { key: 'place', to: parsePlace(to).kind }, ...actionOptions(options) });
}

/**
 * Several people in / out of a (solo) round - a group per person, one undo step. Taking out keeps a person who is
 * already in a pair/team of a team round out of this helper's reach: `in` never moves anybody out of a team.
 * `options.from` = the place each person showed (a value for all, or a Map / function by person id).
 */
export function setInRound(model, personIds, roundId, inRound, options = {}) {
    const groups = [];

    for (const personId of personIds) {
        const shown = model.placeValue(personId, roundId);
        const from = fromFor(options, personId, shown);

        if (inRound && from === OUT && shown === OUT) {
            groups.push([{ op: 'place', participant: personId, round: roundId, from, to: IN }]);
        } else if (!inRound && from !== OUT && shown !== OUT) {
            groups.push([{ op: 'place', participant: personId, round: roundId, from, to: OUT }]);
        }
    }

    return buildAction(model, groups, { label: { key: inRound ? 'round_in' : 'round_out', count: groups.length }, ...actionOptions(options) });
}

/**
 * "Type a pair into the new row": a new pair/team with its name and members in one group - members are people of the
 * event (ids, moved here from wherever they are in the round) or new people ({name, country}, D9). `options.from` =
 * where each member was when the organiser picked them (a Map / function by person id - "moves from Table 12
 * Pinecones").
 *
 * @param {{id?: string, name?: string|null, members?: Array<string|{name: string, country?: string|null, id?: string}>}} row
 */
export function newTeamRow(model, roundId, row, options = {}) {
    const newId = options.newId ?? newClientId;
    const teamId = row.id ?? newId();
    const changes = [];
    const members = row.members ?? [];
    const name = cleanTeamName(row.name ?? null);

    if (name === null && members.length === 0) {
        return { ...buildAction(model, [], actionOptions(options)), teamId: null };
    }

    const memberIds = [];
    const created = new Set();

    for (const member of members) {
        if (typeof member === 'string') {
            memberIds.push(member);
        } else {
            const change = newPersonChange(member, newId);
            changes.push(change);
            memberIds.push(change.id);
            created.add(change.id);
        }
    }

    changes.push({ op: 'newTeam', id: teamId, round: roundId, name });

    for (const personId of [...new Set(memberIds)]) {
        const from = created.has(personId) ? OUT : fromFor(options, personId, model.placeValue(personId, roundId));
        changes.push({ op: 'place', participant: personId, round: roundId, from, to: teamPlace(teamId) });
    }

    return { ...buildAction(model, [changes], { label: { key: 'new_team' }, ...actionOptions(options) }), teamId };
}

/**
 * Put a person (an id, or a new person {name, country}) into a pair/team - moving them from wherever they are in the
 * round ("moves from Table 12 Pinecones"). `options.from` = the place the organiser saw for that person.
 */
export function putInTeam(model, roundId, teamId, member, options = {}) {
    const newId = options.newId ?? newClientId;
    const changes = [];
    let personId = member;
    let from;

    if (typeof member !== 'string') {
        const change = newPersonChange(member, newId);
        changes.push(change);
        personId = change.id;
        from = OUT;
    } else {
        from = fromFor(options, personId, model.placeValue(personId, roundId));
    }

    if (from === teamPlace(teamId) || model.placeValue(personId, roundId) === teamPlace(teamId)) {
        return buildAction(model, [], actionOptions(options));
    }

    changes.push({ op: 'place', participant: personId, round: roundId, from, to: teamPlace(teamId) });

    return { ...buildAction(model, [changes], { label: { key: 'put_in_team' }, ...actionOptions(options) }), personId };
}

/** Clear a member cell: the person stays in the round without a pair/team (the tray). `options.from` = the pair/team shown. */
export function clearMember(model, roundId, personId, options = {}) {
    const from = fromFor(options, personId, model.placeValue(personId, roundId));

    if (parsePlace(from).kind !== 'team') {
        return buildAction(model, [], actionOptions(options));
    }

    return setPlace(model, personId, roundId, IN, { label: { key: 'clear_member' }, ...options, from });
}

/** `options.from` = the name the organiser saw. */
export function renameTeam(model, teamId, name, options = {}) {
    const team = model.team(teamId);
    const to = cleanTeamName(name);
    const from = team === null ? null : fromFor(options, teamId, team.name);

    if (team === null || to === team.name || to === from) {
        return buildAction(model, [], actionOptions(options));
    }

    return buildAction(model, [[{ op: 'renameTeam', team: teamId, from, to }]], { label: { key: 'rename_team' }, ...actionOptions(options) });
}

/**
 * Delete a pair/team: its members stay in the round without one. Its table number goes with it (it lives on the
 * entry) - the undo creates the pair again and gives the number back (`inverseResults`, sent after the group that
 * creates it - the save queue never lets a results change overtake a sheet group queued before it).
 */
export function deleteTeam(model, teamId, options = {}) {
    const team = model.team(teamId);

    if (team === null) {
        return buildAction(model, [], actionOptions(options));
    }

    const action = buildAction(model, [[{ op: 'deleteTeam', team: teamId }]], { label: { key: 'delete_team' }, ...actionOptions(options) });

    if (action.groups.length === 0 || team.table === null || team.table === undefined) {
        return action;
    }

    return {
        ...action,
        inverseResults: [{ roundId: team.roundId, ref: `team:${teamId}`, field: 'table_number', from: null, to: team.table, inverseOf: action.groups[0].id }],
    };
}

/** The expected size of a team round (null = not set). `options.from` = the size shown. */
export function setTeamSize(model, roundId, size, options = {}) {
    const round = model.round(roundId);
    const shown = round?.teamSize ?? null;
    const from = round === null ? null : fromFor(options, roundId, shown);
    const to = size === null || size === '' ? null : Number(size);

    if (round === null || shown === to || from === to) {
        return buildAction(model, [], actionOptions(options));
    }

    return buildAction(model, [[{ op: 'teamSize', round: roundId, from, to }]], { label: { key: 'team_size' }, ...actionOptions(options) });
}

// ---------------------------------------------------------------- results (RecordRoundResults, contract O3)

/**
 * Official fields typed in a round tab - `{roundId, ref, field: result|table_number|qualified, from, to}` - as an
 * action whose undo sends the swapped change (three-way checked like any other).
 */
export function resultsAction(changes, label = { key: 'results' }) {
    const results = changes.filter((change) => JSON.stringify(change.from ?? null) !== JSON.stringify(change.to ?? null));

    return {
        label,
        groups: [],
        inverse: [],
        errors: [],
        results,
        inverseResults: results.slice().reverse().map((change) => ({ ...change, from: change.to, to: change.from })),
    };
}
