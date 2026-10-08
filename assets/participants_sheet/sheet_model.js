/**
 * The participants spreadsheet's data in the browser (docs/features/competitions-management/participants-spreadsheet.md,
 * "Client architecture (as built)"): the state JSON of `participants_sheet_state` turned into indexes, the derived facts
 * every view needs (members of a pair/team, the "without a pair" tray, sizes, problem counts, same-named teams, people
 * in no round, duplicate names), and the optimistic overlay of the changes not confirmed by the server yet.
 *
 * Two layers:
 * - `base` = what the server said last (the state, plus every group the server applied since - the version protocol of
 *   the contract §3.2 keeps it equal to the server's state at `version`);
 * - `pending` = the groups the organiser made that the server did not answer yet, replayed on top of `base` in order.
 * What views read is base + pending. A refused or conflicting group is dropped from `pending` (= reverted), an applied
 * one folded into `base`. A fetched state replaces `base` and the pending groups are replayed on it again - rows with
 * local changes keep the organiser's values until the server answers them (a real conflict surfaces on save).
 *
 * Official fields (table number, result, qualified - written only through RecordRoundResults / AssignTableNumbers) live
 * on the places of solo rounds and on the teams; live updates (`official_results.entries`) are merged into `base`
 * directly, an older result never replacing a newer one (enteredAt).
 *
 * Pure - no DOM, no network: pinned by tests/participants-sheet-core-harness.mjs.
 */

import { foldSearchText } from '../search_fold.js';

export const OUT = 'out';
export const IN = 'in';
export const TEAM_SIZE_MIN = 2;
export const TEAM_SIZE_MAX = 20;
export const NAME_MAX_LENGTH = 255;
export const NOTE_MAX_LENGTH = 255;
export const EXTERNAL_ID_MAX_LENGTH = 255;
export const TEAM_NAME_MAX_LENGTH = 255;

const DASHES = /[‐-―−]/gu;
// PHP's preg_replace('/\s+/u', ' ') (Unicode white space) and trim() (ASCII) - the server's cleaning, so a value the
// browser shows is exactly the value the server stores (a different spelling would come back as a conflict later)
const WHITE_SPACE = /\p{White_Space}+/gu;
const PHP_TRIM = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;

/** A person's or a team's name as the server stores it: white space runs collapsed, trimmed (CompetitionTeam::cleanName). */
export function cleanName(value) {
    return String(value ?? '').replace(WHITE_SPACE, ' ').replace(PHP_TRIM, '');
}

/** A team name: cleaned, empty = no name (null). */
export function cleanTeamName(value) {
    const name = cleanName(value);

    return name === '' ? null : name;
}

/** External id / note: trimmed, empty = null. */
export function cleanOptionalText(value) {
    if (value === null || value === undefined) {
        return null;
    }

    const text = String(value).replace(PHP_TRIM, '');

    return text === '' ? null : text;
}

/** ParticipantNameKey::of() - case, white space, accents, apostrophes and dash kinds do not matter. */
export function nameKey(name) {
    return foldSearchText(String(name ?? '').replace(DASHES, '-'));
}

/** Length as PHP's mb_strlen() counts it (code points). */
export function textLength(text) {
    return [...String(text ?? '')].length;
}

/** `out` | `in` | `team:<id>` → {kind, teamId}. */
export function parsePlace(value) {
    if (typeof value === 'string' && value.startsWith('team:')) {
        return { kind: 'team', teamId: value.slice(5) };
    }

    return { kind: value === IN ? IN : OUT, teamId: null };
}

export function teamPlace(teamId) {
    return `team:${teamId}`;
}

function placeKey(participantId, roundId) {
    return `${participantId}|${roundId}`;
}

function same(a, b) {
    return JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
}

export function emptyDelta() {
    return { people: new Set(), teams: new Set(), rounds: new Set(), rows: false, all: false };
}

/**
 * A copy-on-write working state: Maps are copied once, records replaced (never mutated) when changed - the base is
 * never touched by replaying pending groups. Also the scratch state sheet_changes.js computes inverses on
 * (SheetModel::scratch()).
 */
export class Working {
    constructor(source) {
        this.people = new Map(source.people);
        this.places = new Map(source.places);
        this.teams = new Map(source.teams);
        this.rounds = new Map(source.rounds);
        this.order = source.order.slice();
        this.journal = null;
        this.journalOrders = null;
        // The pairs/teams the last applyGroup() deleted automatically, as they were before (inverses create them again)
        this.lastAutoDeleted = [];
    }

    /**
     * Starts recording every write, so a trial (the client checks of a group) can be taken back with rollback() - no
     * copy of the whole state per group (a bulk action of 1,000 groups would copy 1,000 x 4,000 records).
     */
    begin() {
        this.journal = [];
        // A Map a record was erased from: its key order before (restored exactly - teams are listed in that order)
        this.journalOrders = new Map();
    }

    /** Takes back every write since begin() - records, and the order of every Map, exactly as they were. */
    rollback() {
        const journal = this.journal ?? [];
        const orders = this.journalOrders ?? new Map();
        this.journal = null;
        this.journalOrders = null;

        for (let index = journal.length - 1; index >= 0; index--) {
            const [map, key, had, value] = journal[index];

            if (map === 'order') {
                this.order.length = key;
            } else if (had) {
                this[map].set(key, value);
            } else {
                this[map].delete(key);
            }
        }

        for (const [map, keys] of orders) {
            this[map] = new Map(keys.filter((key) => this[map].has(key)).map((key) => [key, this[map].get(key)]));
        }
    }

    /** Keeps every write since begin(). */
    commit() {
        this.journal = null;
        this.journalOrders = null;
    }

    write(map, key, value) {
        this.journal?.push([map, key, this[map].has(key), this[map].get(key)]);
        this[map].set(key, value);
    }

    erase(map, key) {
        if (!this[map].has(key)) {
            return;
        }

        if (this.journal) {
            if (!this.journalOrders.has(map)) {
                this.journalOrders.set(map, [...this[map].keys()]);
            }

            this.journal.push([map, key, true, this[map].get(key)]);
        }

        this[map].delete(key);
    }

    appendToOrder(id) {
        this.journal?.push(['order', this.order.length]);
        this.order.push(id);
    }

    person(id) {
        return this.people.get(id) ?? null;
    }

    place(participantId, roundId) {
        return this.places.get(placeKey(participantId, roundId)) ?? null;
    }

    placeValue(participantId, roundId) {
        const place = this.place(participantId, roundId);

        if (place === null) {
            return OUT;
        }

        return place.teamId === null ? IN : teamPlace(place.teamId);
    }

    team(id) {
        return this.teams.get(id) ?? null;
    }

    /** Every place (removed people's included) in a team. */
    placesInTeam(teamId) {
        const list = [];

        for (const place of this.places.values()) {
            if (place.teamId === teamId) {
                list.push(place);
            }
        }

        return list;
    }

    /**
     * Applies one sheet change (contract §3.1) the way the server would when it goes through. `now` stamps removals and
     * connections (the server's time replaces it with the next state).
     */
    apply(change, now) {
        switch (change.op) {
            case 'newParticipant': {
                if (!this.people.has(change.id)) {
                    this.write('people', change.id, {
                        id: change.id,
                        name: cleanName(change.name),
                        country: change.country ?? null,
                        externalId: cleanOptionalText(change.externalId),
                        note: null,
                        source: 'manual',
                        removedAt: null,
                        connectedAt: null,
                        player: null,
                        registration: null,
                        playerResultRounds: [],
                        local: true,
                    });
                    this.appendToOrder(change.id);
                }

                return;
            }

            case 'field': {
                const person = this.person(change.participant);

                if (person === null) {
                    return;
                }

                let value = change.to ?? null;

                if (change.field === 'name') {
                    value = cleanName(value);
                } else if (change.field === 'externalId' || change.field === 'note') {
                    value = cleanOptionalText(value);
                }

                this.write('people', person.id, { ...person, [change.field]: value });

                return;
            }

            case 'player': {
                const person = this.person(change.participant);

                if (person === null) {
                    return;
                }

                // `playerResultRounds` (the own-time guard) stays as it was until the next state says whose times count:
                // the server checks a changeset against the player the person had when it started too (contract §3.1)
                if (change.to === null) {
                    this.write('people', person.id, { ...person, player: null, connectedAt: null });
                } else {
                    // `_player` = what the organiser picked (name, code, avatar) - shown until the next state says more;
                    // a profile hidden from this organiser (O9: blocked, private) shows as "Linked to a profile" only
                    const shown = change._player && change._player.id === change.to ? change._player : null;
                    const visible = shown !== null && shown.visible !== false;
                    this.write('people', person.id, {
                        ...person,
                        player: visible
                            ? { visible: true, name: null, code: null, country: null, avatar: null, profileUrl: null, ...shown, id: change.to }
                            : { id: change.to, visible: false, name: null, code: null, country: null, avatar: null, profileUrl: null },
                        connectedAt: person.player?.id === change.to ? person.connectedAt : new Date(now).toISOString(),
                    });
                }

                return;
            }

            case 'place': {
                const key = placeKey(change.participant, change.round);
                const current = this.places.get(key) ?? null;
                const target = parsePlace(change.to);

                if (target.kind === OUT) {
                    this.erase('places', key);

                    return;
                }

                const teamId = target.kind === 'team' ? target.teamId : null;

                if (current === null) {
                    this.write('places', key, {
                        id: `local:${change.participant}:${change.round}`,
                        participantId: change.participant,
                        roundId: change.round,
                        teamId,
                        table: null,
                        result: null,
                        qualified: false,
                        enteredAt: null,
                        enteredBy: null,
                        local: true,
                    });
                } else if (current.teamId !== teamId) {
                    this.write('places', key, { ...current, teamId });
                }

                return;
            }

            case 'newTeam': {
                if (!this.teams.has(change.id)) {
                    this.write('teams', change.id, {
                        id: change.id,
                        roundId: change.round,
                        name: cleanTeamName(change.name),
                        table: null,
                        result: null,
                        qualified: false,
                        enteredAt: null,
                        enteredBy: null,
                        local: true,
                    });
                }

                return;
            }

            case 'renameTeam': {
                const team = this.team(change.team);

                if (team !== null) {
                    this.write('teams', team.id, { ...team, name: cleanTeamName(change.to) });
                }

                return;
            }

            case 'deleteTeam': {
                // Members stay in the round without a pair/team, the team goes (PR #244 order)
                for (const place of this.placesInTeam(change.team)) {
                    this.write('places', placeKey(place.participantId, place.roundId), { ...place, teamId: null });
                }

                this.erase('teams', change.team);

                return;
            }

            case 'remove': {
                const person = this.person(change.participant);

                if (person !== null && person.removedAt === null) {
                    this.write('people', person.id, { ...person, removedAt: new Date(now).toISOString() });
                }

                return;
            }

            case 'restore': {
                const person = this.person(change.participant);

                if (person !== null && person.removedAt !== null) {
                    this.write('people', person.id, { ...person, removedAt: null });
                }

                return;
            }

            case 'teamSize': {
                const round = this.rounds.get(change.round);

                if (round !== undefined) {
                    this.write('rounds', round.id, { ...round, teamSize: change.to ?? null });
                }

                return;
            }

            default:
                // An op this client does not know changes nothing locally - the server's answer and the next state do
        }
    }

    /**
     * Pair/teams that had active members before a group and have none after it - deleted by the server in the same
     * write when they have no name and no official data (contract §3.1 "Automatic team deletion"). Teams emptied by
     * `remove` are never deleted automatically.
     */
    autoDelete(teamIdsBefore, removedPeople) {
        const deleted = [];
        this.lastAutoDeleted = [];

        for (const [teamId, hadActive] of teamIdsBefore) {
            const team = this.team(teamId);

            if (!hadActive || team === null || team.name !== null || hasOfficialData(team)) {
                continue;
            }

            const places = this.placesInTeam(teamId);
            const active = places.filter((place) => this.person(place.participantId)?.removedAt === null);

            if (active.length > 0) {
                continue;
            }

            if (places.some((place) => removedPeople.has(place.participantId))) {
                continue;
            }

            this.lastAutoDeleted.push(team);
            this.apply({ op: 'deleteTeam', team: teamId });
            deleted.push(teamId);
        }

        return deleted;
    }

    activeMemberCount(teamId) {
        let count = 0;

        for (const place of this.places.values()) {
            if (place.teamId === teamId && this.person(place.participantId)?.removedAt === null) {
                count++;
            }
        }

        return count;
    }

    /**
     * Members who are going: not removed, not on the waitlist of a managed event - the ones a pair's/team's result
     * belongs to (the emptying guard of a team holding official data, contract §3.1 `team_has_result`).
     */
    goingMemberCount(teamId) {
        let count = 0;

        for (const place of this.places.values()) {
            if (place.teamId === teamId && isGoing(this.person(place.participantId))) {
                count++;
            }
        }

        return count;
    }

    /**
     * A group applied like the server does: every change in order, then the automatic deletion of emptied unnamed teams.
     */
    applyGroup(changes, now, visit = null) {
        const touchedTeams = new Map();
        const removedPeople = new Set();

        for (const change of changes) {
            if (change.op === 'place') {
                const before = this.place(change.participant, change.round);

                if (before?.teamId && !touchedTeams.has(before.teamId)) {
                    touchedTeams.set(before.teamId, this.activeMemberCount(before.teamId) > 0);
                }
            }

            if (change.op === 'remove') {
                removedPeople.add(change.participant);
            }
        }

        for (const change of changes) {
            visit?.(change, this);
            this.apply(change, now);
        }

        return this.autoDelete(touchedTeams, removedPeople);
    }
}

/** Active and not on the waitlist (a waitlisted person may be placed - O8 - but a result never belongs to them). */
export function isGoing(person) {
    return person !== null && person !== undefined && person.removedAt === null && person.registration?.status !== 'waitlisted';
}

export function hasOfficialData(entry) {
    return entry !== null && entry !== undefined && ((entry.result !== null && entry.result !== undefined) || entry.qualified === true);
}

/**
 * Cell markers (saving / waiting / conflict / refused / warning) by key - `person:<id>:<field>`,
 * `place:<personId>:<roundId>`, `team:<teamId>:<what>`, `round:<roundId>:teamSize`, `result:<ref>:<field>` - with the
 * entities they belong to, so a view re-renders only the cells whose marker changed. Kept by the save queue.
 */
export class SheetMarks {
    /**
     * @param {function(object): void} [onChange] called with the delta of every change
     * @param {function(function(): void): void} [batch] runs a function with the deltas it causes emitted once
     */
    constructor(onChange = () => {}, batch = (fn) => fn()) {
        this.marks = new Map();
        this.onChange = onChange;
        this.batch = batch;
    }

    /** Several markers at once - views re-render once (a bulk action marks hundreds of cells). */
    setMany(entries) {
        this.batch(() => {
            for (const { key, mark, entities } of entries) {
                this.set(key, mark, entities ?? {});
            }
        });
    }

    /**
     * @param {string} key
     * @param {{state: string, message?: string|null, groupId?: string|null, problemId?: string|null}|null} mark null clears
     * @param {{people?: string[], teams?: string[], rounds?: string[]}} entities
     */
    set(key, mark, entities = {}) {
        const current = this.marks.get(key) ?? null;

        if (mark === null) {
            if (current === null) {
                return;
            }

            this.marks.delete(key);
        } else {
            if (current !== null && same(current, { ...mark, entities: current.entities })) {
                return;
            }

            this.marks.set(key, { message: null, groupId: null, problemId: null, ...mark, key, entities: current?.entities ?? entities });
        }

        const delta = emptyDelta();
        const known = mark === null ? current.entities : (current?.entities ?? entities);
        (known.people ?? []).forEach((id) => delta.people.add(id));
        (known.teams ?? []).forEach((id) => delta.teams.add(id));
        (known.rounds ?? []).forEach((id) => delta.rounds.add(id));
        this.onChange(delta);
    }

    /** Clears the key only when it still belongs to `groupId` (a newer edit of the cell keeps its own marker). */
    clearFor(key, groupId) {
        const current = this.marks.get(key);

        if (current !== undefined && (groupId === undefined || current.groupId === groupId)) {
            this.set(key, null);
        }
    }

    get(key) {
        return this.marks.get(key) ?? null;
    }

    /** Every marker whose key starts with `prefix` (e.g. `person:<id>:` for a row). */
    withPrefix(prefix) {
        const list = [];

        for (const [key, mark] of this.marks) {
            if (key.startsWith(prefix)) {
                list.push(mark);
            }
        }

        return list;
    }

    all() {
        return [...this.marks.values()];
    }
}

export class SheetModel {
    /**
     * @param {object} state the state JSON (contract §4.2)
     * @param {{now?: function(): number}} [options]
     */
    constructor(state, { now = () => Date.now() } = {}) {
        this.now = now;
        this.listeners = new Set();
        this.pending = [];
        this.batchDepth = 0;
        this.batched = null;
        this.marks = new SheetMarks((delta) => this.emit(delta), (fn) => this.batch(fn));
        this.resultsGeneration = 0;
        this.setBase(state);
        this.rebuild();
    }

    // ---------------------------------------------------------------- loading

    setBase(state) {
        this.competition = state.competition ?? {};
        this.version = state.version ?? null;
        this.serverNow = state.serverNow ?? null;
        this.mercure = state.mercure ?? null;
        this.roundOrder = (state.rounds ?? []).map((round) => round.id);

        const people = new Map();
        const order = [];
        for (const person of state.people ?? []) {
            people.set(person.id, {
                note: null,
                externalId: null,
                registration: null,
                player: null,
                playerResultRounds: [],
                removedAt: null,
                connectedAt: null,
                source: 'manual',
                country: null,
                ...person,
            });
            order.push(person.id);
        }

        const places = new Map();
        for (const place of state.places ?? []) {
            places.set(placeKey(place.participantId, place.roundId), {
                teamId: null, table: null, result: null, qualified: false, enteredAt: null, enteredBy: null, ...place,
            });
        }

        const teams = new Map();
        for (const team of state.teams ?? []) {
            teams.set(team.id, { name: null, table: null, result: null, qualified: false, enteredAt: null, enteredBy: null, ...team });
        }

        const rounds = new Map();
        for (const round of state.rounds ?? []) {
            rounds.set(round.id, { teamSize: null, ...round });
        }

        this.base = { people, places, teams, rounds, order };
    }

    /** Recomputes what views read: base + the pending groups in order. */
    rebuild() {
        const working = new Working(this.base);
        const now = this.now();

        for (const group of this.pending) {
            group.autoDeleted = working.applyGroup(group.changes, group.at ?? now);
        }

        this.current = working;
        this.cache = new Map();
    }

    // ---------------------------------------------------------------- events

    /**
     * @param {function({people: Set<string>, teams: Set<string>, rounds: Set<string>, rows: boolean, all: boolean}): void} listener
     * @returns {function(): void} unsubscribe
     */
    subscribe(listener) {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }

    /**
     * Every listener hears every delta: one that throws (a view's bug) is logged and the others still run - a model
     * change half told would leave views showing what is not there.
     */
    emit(delta) {
        if (delta.people.size === 0 && delta.teams.size === 0 && delta.rounds.size === 0 && !delta.rows && !delta.all) {
            return;
        }

        if (this.batchDepth > 0) {
            this.batched = mergeDelta(this.batched ?? emptyDelta(), delta);

            return;
        }

        for (const listener of [...this.listeners]) {
            try {
                listener(delta);
            } catch (error) {
                console.error(error);
            }
        }
    }

    /**
     * Runs `fn` with every delta it causes (model changes, markers) told once at the end, merged - a bulk action, a
     * save answer of 1,000 groups re-render the views once. Nested batches are told by the outermost one.
     */
    batch(fn) {
        this.batchDepth++;

        try {
            return fn();
        } finally {
            this.batchDepth--;

            if (this.batchDepth === 0 && this.batched !== null) {
                const delta = this.batched;
                this.batched = null;
                this.emit(delta);
            }
        }
    }

    // ---------------------------------------------------------------- reading

    rounds() {
        return this.roundOrder.map((id) => this.current.rounds.get(id)).filter(Boolean);
    }

    round(id) {
        return this.current.rounds.get(id) ?? null;
    }

    person(id) {
        return this.current.person(id);
    }

    /** People in the state's order (by name, id), people added on this page at the end. */
    people({ includeRemoved = false } = {}) {
        return this.cached(`people:${includeRemoved}`, () => {
            const list = [];

            for (const id of this.current.order) {
                const person = this.current.people.get(id);

                if (person !== undefined && (includeRemoved || person.removedAt === null)) {
                    list.push(person);
                }
            }

            return list;
        });
    }

    team(id) {
        return this.current.team(id);
    }

    place(participantId, roundId) {
        return this.current.place(participantId, roundId);
    }

    /** `out` | `in` | `team:<id>` - a person's place in a round, as a `place` change compares it. */
    placeValue(participantId, roundId) {
        return this.current.placeValue(participantId, roundId);
    }

    isRemoved(personId) {
        return (this.person(personId)?.removedAt ?? null) !== null;
    }

    isWaitlisted(personId) {
        return this.person(personId)?.registration?.status === 'waitlisted';
    }

    cached(key, compute) {
        if (!this.cache.has(key)) {
            this.cache.set(key, compute());
        }

        return this.cache.get(key);
    }

    /** Map roundId → place[] (removed people's places included). */
    placesByRound() {
        return this.cached('placesByRound', () => {
            const map = new Map(this.roundOrder.map((id) => [id, []]));

            for (const place of this.current.places.values()) {
                if (!map.has(place.roundId)) {
                    map.set(place.roundId, []);
                }

                map.get(place.roundId).push(place);
            }

            return map;
        });
    }

    /** Map personId → Map roundId → place. */
    placesByPerson() {
        return this.cached('placesByPerson', () => {
            const map = new Map();

            for (const place of this.current.places.values()) {
                if (!map.has(place.participantId)) {
                    map.set(place.participantId, new Map());
                }

                map.get(place.participantId).set(place.roundId, place);
            }

            return map;
        });
    }

    placesOf(personId) {
        return this.placesByPerson().get(personId) ?? new Map();
    }

    /** Place by its entry id (`participant_round:<id>` refs). */
    placeById(id) {
        return this.cached('placesById', () => new Map([...this.current.places.values()].map((place) => [place.id, place]))).get(id) ?? null;
    }

    /** Active people of a round (not removed), in the people order. */
    peopleIn(roundId) {
        return this.cached(`in:${roundId}`, () => {
            const placed = new Set((this.placesByRound().get(roundId) ?? []).map((place) => place.participantId));

            return this.people().filter((person) => placed.has(person.id));
        });
    }

    /** Teams of a round in the state's order, teams created on this page at the end. */
    teamsOf(roundId) {
        return this.cached(`teams:${roundId}`, () => [...this.current.teams.values()].filter((team) => team.roundId === roundId));
    }

    /** Map teamId → active members (people order). */
    membersByTeam() {
        return this.cached('members', () => {
            const map = new Map();

            for (const team of this.current.teams.values()) {
                map.set(team.id, []);
            }

            const rank = this.peopleRank();

            for (const place of this.current.places.values()) {
                if (place.teamId === null) {
                    continue;
                }

                const person = this.person(place.participantId);

                if (person === null || person.removedAt !== null) {
                    continue;
                }

                if (!map.has(place.teamId)) {
                    map.set(place.teamId, []);
                }

                map.get(place.teamId).push(person);
            }

            for (const members of map.values()) {
                members.sort((a, b) => (rank.get(a.id) ?? 0) - (rank.get(b.id) ?? 0));
            }

            return map;
        });
    }

    peopleRank() {
        return this.cached('rank', () => new Map(this.current.order.map((id, index) => [id, index])));
    }

    membersOf(teamId) {
        return this.membersByTeam().get(teamId) ?? [];
    }

    /** Active people in the round without a pair/team (the "Without a pair" tray). */
    trayOf(roundId) {
        return this.cached(`tray:${roundId}`, () => {
            const ids = new Set((this.placesByRound().get(roundId) ?? []).filter((place) => place.teamId === null).map((place) => place.participantId));

            return this.people().filter((person) => ids.has(person.id));
        });
    }

    /** The most common size of the round's pairs/teams with at least one member - the smaller on a tie, at least 2 (PlanBuilder::usualTeamSize). */
    usualTeamSize(roundId) {
        const counts = new Map();

        for (const team of this.teamsOf(roundId)) {
            const size = this.membersOf(team.id).length;

            if (size > 0) {
                counts.set(size, (counts.get(size) ?? 0) + 1);
            }
        }

        if (counts.size === 0) {
            return 2;
        }

        let usual = null;
        for (const size of [...counts.keys()].sort((a, b) => a - b)) {
            if (usual === null || counts.get(size) > counts.get(usual)) {
                usual = size;
            }
        }

        return Math.max(2, usual);
    }

    /** Expected size: 2 for pairs, the stored size of a team round (else its usual size), null for solo rounds. */
    expectedSize(roundId) {
        const round = this.round(roundId);

        if (round === null || round.category === 'solo') {
            return null;
        }

        if (round.category === 'duo') {
            return 2;
        }

        return Number.isInteger(round.teamSize) ? round.teamSize : this.usualTeamSize(roundId);
    }

    /** O7: every pair/team of the round without members (team names only, a Minnesota-style night). */
    isNamesOnly(roundId) {
        const teams = this.teamsOf(roundId);

        return teams.length > 0 && teams.every((team) => this.membersOf(team.id).length === 0);
    }

    /**
     * @returns {{count: number, expected: number|null, status: 'complete'|'incomplete'|'too_many'|'empty'|'names_only'}}
     */
    sizeStatus(teamId) {
        const team = this.team(teamId);
        const count = this.membersOf(teamId).length;

        if (team === null) {
            return { count, expected: null, status: 'empty' };
        }

        const expected = this.expectedSize(team.roundId);

        if (this.isNamesOnly(team.roundId)) {
            return { count, expected, status: 'names_only' };
        }

        if (count === 0) {
            return { count, expected, status: 'empty' };
        }

        if (expected !== null && count < expected) {
            return { count, expected, status: 'incomplete' };
        }

        if (expected !== null && count > expected) {
            return { count, expected, status: 'too_many' };
        }

        return { count, expected, status: 'complete' };
    }

    /** Map teamId → the other teams of its round with the same name (a fold of it). */
    sameNameTeams(roundId) {
        return this.cached(`sameName:${roundId}`, () => {
            const byKey = new Map();

            for (const team of this.teamsOf(roundId)) {
                if (team.name === null) {
                    continue;
                }

                const key = foldSearchText(team.name);
                byKey.set(key, [...(byKey.get(key) ?? []), team.id]);
            }

            const result = new Map();

            for (const ids of byKey.values()) {
                if (ids.length > 1) {
                    ids.forEach((id) => result.set(id, ids.filter((other) => other !== id)));
                }
            }

            return result;
        });
    }

    /**
     * Problem counts of a round (O7): pairs/teams with a member below / above the expected size, people in the round
     * without a pair/team, same-named teams (informational, not in `total`).
     */
    problems(roundId) {
        return this.cached(`problems:${roundId}`, () => {
            const round = this.round(roundId);
            const counts = { incomplete: 0, tooMany: 0, withoutTeam: 0, sameName: 0, total: 0 };

            if (round === null || round.category === 'solo') {
                return counts;
            }

            for (const team of this.teamsOf(roundId)) {
                const { status } = this.sizeStatus(team.id);

                if (status === 'incomplete') {
                    counts.incomplete++;
                } else if (status === 'too_many') {
                    counts.tooMany++;
                }
            }

            counts.withoutTeam = this.trayOf(roundId).length;
            counts.sameName = this.sameNameTeams(roundId).size;
            counts.total = counts.incomplete + counts.tooMany + counts.withoutTeam;

            return counts;
        });
    }

    /** Active people in no round at all. */
    peopleInNoRound() {
        return this.people().filter((person) => this.placesOf(person.id).size === 0);
    }

    /** Map name key → active people sharing it (only keys with 2+ people) - "probably the same person". */
    duplicateNames() {
        return this.cached('duplicates', () => {
            const byKey = new Map();

            for (const person of this.people()) {
                const key = nameKey(person.name);
                byKey.set(key, [...(byKey.get(key) ?? []), person]);
            }

            return new Map([...byKey].filter(([, people]) => people.length > 1));
        });
    }

    /** Active people whose name key equals `name`'s (a new name typed in: "already on the list"). */
    peopleNamed(name) {
        const key = nameKey(name);

        return this.cached('byNameKey', () => {
            const map = new Map();

            for (const person of this.people()) {
                const personKey = nameKey(person.name);
                map.set(personKey, [...(map.get(personKey) ?? []), person]);
            }

            return map;
        }).get(key) ?? [];
    }

    /**
     * What a team is called wherever one is picked (O1): `Corners · Table 2 · Kim Example, Pat Sample` - the parts,
     * the view joins and translates them (`name` null = "(no name)", `table` null = left out).
     */
    teamLabel(teamId) {
        const team = this.team(teamId);

        return {
            name: team?.name ?? null,
            table: team?.table ?? null,
            members: this.membersOf(teamId).map((person) => person.name),
        };
    }

    /** `participant_round:<placeId>` of a solo round, `team:<teamId>` of a pair/team round - the RecordRoundResults ref. */
    entryRef(personId, roundId) {
        const place = this.place(personId, roundId);

        if (place === null) {
            return null;
        }

        if (place.teamId !== null) {
            return `team:${place.teamId}`;
        }

        return place.local ? null : `participant_round:${place.id}`;
    }

    /**
     * The person holds data in the round (the results guard, contract §3.1 `has_result_in_round`): their solo
     * entry's result or qualified mark, their pair's/team's, or their linked player's own time in the round.
     */
    holdsDataInRound(personId, roundId) {
        const place = this.place(personId, roundId);

        if (place !== null && (hasOfficialData(place) || (place.teamId !== null && hasOfficialData(this.team(place.teamId))))) {
            return true;
        }

        return (this.person(personId)?.playerResultRounds ?? []).includes(roundId);
    }

    holdsDataInEvent(personId) {
        return this.roundOrder.some((roundId) => this.holdsDataInRound(personId, roundId));
    }

    // ---------------------------------------------------------------- the optimistic overlay

    /**
     * The organiser's group, shown at once. Returns the delta (also emitted).
     */
    applyLocal(groupId, changes) {
        return this.applyLocalMany([{ id: groupId, changes }]);
    }

    /**
     * Several groups of one action shown at once: one rebuild, one delta (a bulk action of 1,000 groups costs one
     * replay, not 1,000).
     *
     * @param {Array<{id: string, changes: Array<object>}>} groups
     */
    applyLocalMany(groups) {
        if (groups.length === 0) {
            return emptyDelta();
        }

        const before = this.current;
        const at = this.now();

        for (const group of groups) {
            this.pending.push({ id: group.id, changes: group.changes, at });
        }

        this.rebuild();

        return this.emitDiff(before);
    }

    hasPending(groupId = null) {
        return groupId === null ? this.pending.length > 0 : this.pending.some((group) => group.id === groupId);
    }

    pendingGroup(groupId) {
        return this.pending.find((group) => group.id === groupId) ?? null;
    }

    /**
     * The server applied the group (or found it done): it becomes part of the base, with the pairs/teams the server
     * deleted automatically (`deletedTeams` - the server's word, not the browser's guess).
     */
    confirm(groupId, deletedTeams = []) {
        return this.confirmMany([{ groupId, deletedTeams }]);
    }

    /**
     * Several answered groups folded into the base in the order given (the order they were sent): one rebuild, one
     * delta.
     *
     * @param {Array<{groupId: string, deletedTeams?: string[]}>} answers
     */
    confirmMany(answers) {
        if (answers.length === 0) {
            return emptyDelta();
        }

        const before = this.current;
        const working = new Working(this.base);
        const positions = new Map(this.pending.map((group, index) => [group.id, index]));
        const confirmed = new Set();

        for (const { groupId, deletedTeams = [] } of answers) {
            const index = positions.get(groupId);

            if (index !== undefined && !confirmed.has(groupId)) {
                confirmed.add(groupId);

                for (const change of this.pending[index].changes) {
                    working.apply(change, this.pending[index].at);
                }
            }

            for (const teamId of deletedTeams ?? []) {
                if (working.teams.has(teamId)) {
                    working.apply({ op: 'deleteTeam', team: teamId });
                }
            }
        }

        this.pending = this.pending.filter((group) => !confirmed.has(group.id));
        this.base = working;
        this.rebuild();

        return this.emitDiff(before);
    }

    /**
     * The server refused the group or found a conflict: nothing of it was applied - the organiser's values go.
     */
    revert(groupId) {
        return this.revertMany([groupId]);
    }

    /** Several groups reverted: one rebuild, one delta. */
    revertMany(groupIds) {
        const ids = new Set(groupIds);
        const remaining = this.pending.filter((group) => !ids.has(group.id));

        if (remaining.length === this.pending.length) {
            return emptyDelta();
        }

        const before = this.current;
        this.pending = remaining;
        this.rebuild();

        return this.emitDiff(before);
    }

    emitDiff(before) {
        const delta = diff(before, this.current);
        this.emit(delta);

        return delta;
    }

    /**
     * A scratch copy of what views show - sheet_changes.js runs groups on it to compute their exact inverse.
     */
    scratch() {
        return new Working(this.current);
    }

    // ---------------------------------------------------------------- merging the server's news

    /**
     * A fetched state replaces the base; the pending groups are replayed on it. Official fields of an entry stay when
     * the page already holds a newer result (enteredAt) than the state - a live update can be newer than a state that
     * was on its way. Returns the delta (also emitted).
     */
    replaceState(state) {
        const previous = this.current;
        const previousBase = this.base;
        this.setBase(state);

        for (const [key, place] of this.base.places) {
            const old = previousBase.places.get(key);

            if (old !== undefined && old.id === place.id && newer(old, place)) {
                this.base.places.set(key, { ...place, ...officialFields(old) });
            }
        }

        for (const [id, team] of this.base.teams) {
            const old = previousBase.teams.get(id);

            if (old !== undefined && newer(old, team)) {
                this.base.teams.set(id, { ...team, ...officialFields(old) });
            }
        }

        this.rebuild();

        return this.emitDiff(previous);
    }

    /**
     * `official_results.entries` of a round: table numbers, results and qualified marks of entries by ref. Returns the
     * refs the page does not know (a new entry - the caller fetches the state).
     */
    mergeEntries(entries) {
        const unknown = [];
        const delta = emptyDelta();
        let changed = false;
        let placeKeys = null;

        for (const entry of entries ?? []) {
            const ref = String(entry?.ref ?? '');
            const incoming = {
                table: entry.tableNumber ?? null,
                result: entry.result ?? null,
                qualified: entry.qualified === true,
                enteredAt: entry.enteredAt ?? null,
                enteredBy: entry.enteredBy?.name ?? null,
            };

            if (ref.startsWith('team:')) {
                const id = ref.slice(5);
                const team = this.base.teams.get(id);

                if (team === undefined) {
                    unknown.push(ref);
                    continue;
                }

                const merged = mergeOfficial(team, incoming);

                if (merged !== team) {
                    this.base.teams.set(id, merged);
                    delta.teams.add(id);
                    delta.rounds.add(team.roundId);
                    for (const place of this.base.places.values()) {
                        if (place.teamId === id) {
                            delta.people.add(place.participantId);
                        }
                    }
                    changed = true;
                }
            } else if (ref.startsWith('participant_round:')) {
                placeKeys ??= new Map([...this.base.places].map(([key, place]) => [place.id, key]));
                const entryKey = placeKeys.get(ref.slice('participant_round:'.length));

                if (entryKey === undefined) {
                    unknown.push(ref);
                    continue;
                }

                const place = this.base.places.get(entryKey);
                const merged = mergeOfficial(place, incoming);

                if (merged !== place) {
                    this.base.places.set(entryKey, merged);
                    delta.people.add(place.participantId);
                    delta.rounds.add(place.roundId);
                    changed = true;
                }
            } else {
                unknown.push(ref);
            }
        }

        if (changed) {
            this.resultsGeneration++;
            this.base = new Working(this.base);
            this.rebuild();
            this.emit(delta);
        }

        return { unknown, delta };
    }

    /** `official_results.round`: what changed about a round (publication, table numbers usage, started). */
    updateRound(overview) {
        if (!overview || typeof overview.id !== 'string' || !this.base.rounds.has(overview.id)) {
            return;
        }

        const round = this.base.rounds.get(overview.id);
        const next = { ...round };

        for (const key of ['resultsPublished', 'tableNumbersOff', 'started', 'piecesCount']) {
            if (key in overview) {
                next[key] = overview[key];
            }
        }

        if (!same(next, round)) {
            this.base.rounds.set(round.id, next);
            this.rebuild();
            const delta = emptyDelta();
            delta.rounds.add(round.id);
            this.emit(delta);
        }
    }
}

function officialFields(entry) {
    return { table: entry.table, result: entry.result, qualified: entry.qualified, enteredAt: entry.enteredAt, enteredBy: entry.enteredBy };
}

function newer(current, incoming) {
    return current.enteredAt && incoming.enteredAt && Date.parse(incoming.enteredAt) < Date.parse(current.enteredAt);
}

/**
 * An entry's official fields from a live update. An update about an older result than the page holds is an old message
 * (it arrived late): none of its fields - result, table number, qualified mark - replaces what the page knows.
 */
function mergeOfficial(entry, incoming) {
    if (newer(entry, incoming)) {
        return entry;
    }

    const next = { ...entry, table: incoming.table, qualified: incoming.qualified, result: incoming.result, enteredAt: incoming.enteredAt, enteredBy: incoming.enteredBy };

    return same(officialFields(next), officialFields(entry)) ? entry : next;
}

/** Two deltas as one (a batch). */
export function mergeDelta(into, delta) {
    delta.people.forEach((id) => into.people.add(id));
    delta.teams.forEach((id) => into.teams.add(id));
    delta.rounds.forEach((id) => into.rounds.add(id));
    into.rows = into.rows || delta.rows;
    into.all = into.all || delta.all;

    return into;
}

/** What differs between two working states, as a delta. */
function diff(before, after) {
    const delta = emptyDelta();

    for (const [id, person] of after.people) {
        const old = before.people.get(id);

        if (old === undefined) {
            delta.rows = true;
            delta.people.add(id);
        } else if (old !== person && !same(old, person)) {
            delta.people.add(id);

            if ((old.removedAt === null) !== (person.removedAt === null)) {
                delta.rows = true;
            }
        }
    }

    for (const id of before.people.keys()) {
        if (!after.people.has(id)) {
            delta.rows = true;
            delta.people.add(id);
        }
    }

    const placeKeys = new Set([...before.places.keys(), ...after.places.keys()]);

    for (const key of placeKeys) {
        const old = before.places.get(key) ?? null;
        const place = after.places.get(key) ?? null;

        if (old === place || same(old, place)) {
            continue;
        }

        const any = place ?? old;
        delta.people.add(any.participantId);
        delta.rounds.add(any.roundId);

        for (const teamId of [old?.teamId, place?.teamId]) {
            if (teamId) {
                delta.teams.add(teamId);
            }
        }
    }

    const teamIds = new Set([...before.teams.keys(), ...after.teams.keys()]);

    for (const id of teamIds) {
        const old = before.teams.get(id) ?? null;
        const team = after.teams.get(id) ?? null;

        if (old === team || same(old, team)) {
            continue;
        }

        delta.teams.add(id);
        delta.rounds.add((team ?? old).roundId);

        if (old === null || team === null) {
            delta.rows = true;
        }
    }

    for (const [id, round] of after.rounds) {
        const old = before.rounds.get(id);

        if (old === undefined || !same(old, round)) {
            delta.rounds.add(id);
            delta.all = delta.all || old === undefined;
        }
    }

    if (before.rounds.size !== after.rounds.size) {
        delta.all = true;
    }

    return delta;
}
