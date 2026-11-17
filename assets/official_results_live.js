/**
 * Pure helpers of the live result entry (docs/features/competitions-management/live-results.md) - finding an
 * entrant by table / name / #code, comparing and describing official values, the "recent entries" list (its live
 * updates stream is official_results_events.js, shared with the other organiser pages). No DOM, no network: pinned by
 * tests/LiveResultsScriptsTest.php under node.
 */

import { formatResultTime } from './official_results_time.js';

// Letters NFD does not take apart
const SPECIAL_LETTERS = { ß: 'ss', ł: 'l', đ: 'd', ð: 'd', ø: 'o', æ: 'ae', œ: 'oe', þ: 'th', ı: 'i' };

/**
 * Lower case without accents: "Kateřina Šťastná" → "katerina stastna".
 */
export function foldText(text) {
    return String(text ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[ßłđðøæœþı]/g, (letter) => SPECIAL_LETTERS[letter] ?? letter);
}

/**
 * One comparable key of a field's value: results {seconds} / {piecesPlaced} / {didNotStart} / null, table numbers,
 * qualified marks.
 */
export function valueKey(value) {
    if (value === null || value === undefined) {
        return '';
    }

    // A pick round's puzzle record (competition toolkit C10): its items in solving order
    if (Array.isArray(value)) {
        return value.length === 0 ? '' : `z:${JSON.stringify(value.map((item) => [String(item.roundPuzzleId).toLowerCase(), item.done === true, item.atSeconds ?? null, item.piecesPlaced ?? null]))}`;
    }

    if (typeof value === 'object') {
        if (typeof value.seconds === 'number') {
            return `s:${value.seconds}`;
        }

        if (typeof value.piecesPlaced === 'number') {
            return `p:${value.piecesPlaced}`;
        }

        if (value.didNotStart === true) {
            return 'dns';
        }

        return '';
    }

    return String(value);
}

export function sameValue(a, b) {
    return valueKey(a) === valueKey(b);
}

/**
 * The server's value of one field of an entry.
 */
export function entryValue(entry, field) {
    if (field === 'result') {
        return entry.result ?? null;
    }

    if (field === 'table_number') {
        return entry.tableNumber ?? null;
    }

    if (field === 'puzzles') {
        return entry.puzzles ?? null;
    }

    // Finished with pieces missing (competition toolkit T6) - the key only on an entry that has a count
    if (field === 'missing_pieces') {
        return entry.missingPieces ?? null;
    }

    return entry.qualified === true;
}

/**
 * A result as text - "1:23:45", "479 / 1000 pcs", "Did not start", "–" (texts from the page's translations).
 */
export function describeResult(result, piecesCount, texts) {
    if (result === null || result === undefined) {
        return texts.noResult;
    }

    if (typeof result.seconds === 'number') {
        return formatResultTime(result.seconds);
    }

    if (typeof result.piecesPlaced === 'number') {
        if (piecesCount) {
            return texts.piecesPlacedOf
                .replace('%placed%', String(result.piecesPlaced))
                .replace('%pieces%', String(piecesCount));
        }

        return texts.piecesPlaced.replace('%placed%', String(result.piecesPlaced));
    }

    if (result.didNotStart === true) {
        return texts.didNotStart;
    }

    return texts.noResult;
}

/**
 * The search index of a round's entries - built once per state change, searched on every key stroke.
 */
export function buildSearchIndex(entries) {
    return entries.map((entry) => {
        const members = Array.isArray(entry.members) ? entry.members : [];
        const names = [entry.name, entry.displayName, entry.playerName, ...members.map((member) => member.name), ...members.map((member) => member.playerName)]
            .filter((name) => typeof name === 'string' && name !== '');
        const codes = [entry.playerCode, ...members.map((member) => member.playerCode)]
            .filter((code) => typeof code === 'string' && code !== '')
            .map((code) => code.toLowerCase());
        const haystack = foldText(names.join(' '));

        return {
            entry,
            table: typeof entry.tableNumber === 'number' ? entry.tableNumber : null,
            display: foldText(entry.displayName ?? entry.name ?? ''),
            haystack,
            words: haystack.split(/[^\p{L}\p{N}]+/u).filter((word) => word !== ''),
            codes,
        };
    });
}

function byTableThenName(a, b) {
    if (a.table !== b.table) {
        if (a.table === null) {
            return 1;
        }

        if (b.table === null) {
            return -1;
        }

        return a.table - b.table;
    }

    return a.display.localeCompare(b.display);
}

/**
 * Entries for what the referee typed: an exact table number first (`12` → table 12, then 120…129 and names with
 * "12"), `#code` → the linked player's code, anything else → every word of the query starts a word of the entry's
 * name, its members' names or player names (accents ignored); a name merely containing the text comes last.
 * With table numbers switched off the round is searched by name only.
 *
 * @returns {{exactTable: object|null, matches: object[]}} entries, best first
 */
export function searchEntries(index, query, { tableNumbersOff = false, limit = 30 } = {}) {
    const text = String(query ?? '').trim();

    if (text === '') {
        return { exactTable: null, matches: [] };
    }

    if (text.startsWith('#')) {
        const code = text.slice(1).trim().toLowerCase();

        if (code === '') {
            return { exactTable: null, matches: [] };
        }

        const found = index
            .filter((item) => item.codes.some((candidate) => candidate.startsWith(code)))
            .sort((a, b) => Number(!a.codes.includes(code)) - Number(!b.codes.includes(code)) || byTableThenName(a, b));

        return { exactTable: null, matches: found.slice(0, limit).map((item) => item.entry) };
    }

    const scored = [];
    let exactTable = null;

    if (/^\d+$/.test(text) && !tableNumbersOff) {
        const number = parseInt(text, 10);

        for (const item of index) {
            if (item.table === null) {
                continue;
            }

            if (item.table === number) {
                exactTable = exactTable ?? item.entry;
                scored.push({ item, score: 0 });
            } else if (String(item.table).startsWith(text)) {
                scored.push({ item, score: 1 });
            }
        }
    }

    const folded = foldText(text);
    const tokens = folded.split(/\s+/).filter((token) => token !== '');

    for (const item of index) {
        if (scored.some((found) => found.item === item)) {
            continue;
        }

        if (item.display.startsWith(folded)) {
            scored.push({ item, score: 2 });
        } else if (tokens.every((token) => item.words.some((word) => word.startsWith(token)))) {
            scored.push({ item, score: 3 });
        } else if (tokens.every((token) => item.haystack.includes(token))) {
            scored.push({ item, score: 4 });
        }
    }

    scored.sort((a, b) => a.score - b.score || byTableThenName(a.item, b.item));

    return { exactTable, matches: scored.slice(0, limit).map((found) => found.item.entry) };
}

/**
 * What Enter opens: the exact table, else the only match.
 */
export function entryForEnter(found) {
    if (found.exactTable !== null) {
        return found.exactTable;
    }

    return found.matches.length === 1 ? found.matches[0] : null;
}

/**
 * The table that opens without Enter (competition toolkit C7, C-judge-page.md §2.1): the typed digits are a table
 * number of exactly one entry and no other table number of the round starts with them - with tables 1-20, `3` opens
 * table 3, `1` waits for `1x`, `12` opens 12, `2` waits, `20` opens. Deterministic, no timer. Null otherwise (names,
 * `#codes`, table numbers off, nobody or two entries at that table).
 */
export function uniqueTable(index, query, { tableNumbersOff = false } = {}) {
    const text = String(query ?? '').trim();

    if (tableNumbersOff || !/^[1-9]\d*$/.test(text)) {
        return null;
    }

    const number = parseInt(text, 10);
    let found = null;

    for (const item of index) {
        if (item.table === null) {
            continue;
        }

        if (item.table === number) {
            if (found !== null) {
                return null;
            }

            found = item.entry;
        } else if (String(item.table).startsWith(text)) {
            return null;
        }
    }

    return found;
}

/**
 * The confirm step's guard (C-judge-page.md §4.2): Save ignores a tap that started within this long after the
 * confirm step appeared - a nervous double tap on "Finished now" (Save shows up under the same thumb) never saves a
 * value nobody saw.
 */
export const CONFIRM_GUARD_MS = 350;

export function confirmTapAllowed(shownAt, tappedAt, guardMs = CONFIRM_GUARD_MS) {
    if (typeof shownAt !== 'number' || typeof tappedAt !== 'number') {
        return true;
    }

    return tappedAt - shownAt >= guardMs;
}

/**
 * The outbox's changes per entry (saving order kept) - grouped once per render instead of a scan of every change
 * for every row.
 *
 * @returns {Map<string, object[]>}
 */
export function outboxByEntry(outboxItems) {
    const byEntry = new Map();

    for (const item of outboxItems) {
        const list = byEntry.get(item.entryRef);

        if (list === undefined) {
            byEntry.set(item.entryRef, [item]);
        } else {
            list.push(item);
        }
    }

    return byEntry;
}

/**
 * The entries with this device's waiting table numbers on top (a refused one is the entry's problem, not its table),
 * and a map by ref - one lookup per entry, whatever the number of entries and changes.
 *
 * @returns {{list: object[], byRef: Map<string, object>}}
 */
export function entriesWithWaitingTables(entries, outboxItems) {
    const tables = new Map();

    for (const item of outboxItems) {
        if (item.field === 'table_number' && item.state === 'pending') {
            const known = tables.get(item.entryRef);

            if (known === undefined || item.seq > known.seq) {
                tables.set(item.entryRef, item);
            }
        }
    }

    const list = tables.size === 0
        ? entries
        : entries.map((entry) => (tables.has(entry.ref) ? { ...entry, tableNumber: tables.get(entry.ref).to } : entry));

    return { list, byRef: new Map(list.map((entry) => [entry.ref, entry])) };
}

/**
 * The data of the find view's rows (what the referee typed, else the recent entries): each row's entry, whether it
 * is the exact table, the result this device shows and its sync mark - `problem` (conflict/refused), `waiting`
 * (unsent), `saved`, or ''. No HTML: the page renders it, the node harness times it (300 entries + 300 changes).
 *
 * @returns {{typed: boolean, rows: {entry: object, exact: boolean, result: {value: *, item: object|null}, sync: string}[]}}
 */
export function findRows(index, query, outboxItems, { tableNumbersOff = false, entries = [], roundId = null } = {}) {
    const byEntry = outboxByEntry(outboxItems);
    const typed = String(query ?? '').trim() !== '';
    let picked;

    if (typed) {
        const found = searchEntries(index, query, { tableNumbersOff });
        picked = found.matches.map((entry) => ({ entry, exact: entry === found.exactTable }));
    } else {
        const ofRound = roundId === null ? outboxItems : outboxItems.filter((item) => item.roundId === roundId);
        picked = recentEntries(entries, ofRound).map(({ entry }) => ({ entry, exact: false }));
    }

    return { typed, rows: rowsOf(picked, byEntry) };
}

/**
 * Rows of the given entries: the result this device shows and the sync mark (see findRows()).
 *
 * @param {{entry: object, exact?: boolean}[]} picked
 * @param {Map<string, object[]>} byEntry  outboxByEntry()
 */
export function rowsOf(picked, byEntry) {
    return picked.map(({ entry, exact = false }) => {
        const own = byEntry.get(entry.ref) ?? [];
        let sync = '';

        if (own.some((item) => item.state === 'conflict' || item.state === 'rejected')) {
            sync = 'problem';
        } else if (own.length > 0) {
            sync = 'waiting';
        } else if (entry.result !== null && entry.result !== undefined) {
            sync = 'saved';
        }

        return { entry, exact, result: shownValue(entry, 'result', own), sync };
    });
}

/**
 * The value a field shows on this device: the latest of the device's own changes still in the outbox (waiting,
 * in conflict or refused), else the server's.
 *
 * @returns {{value: *, item: object|null}}
 */
export function shownValue(entry, field, outboxItems) {
    let latest = null;

    for (const item of outboxItems) {
        if (item.entryRef === entry.ref && item.field === field && (latest === null || item.seq > latest.seq)) {
            latest = item;
        }
    }

    return latest !== null ? { value: latest.to, item: latest } : { value: entryValue(entry, field), item: null };
}

/**
 * The "recent entries" list: results entered on any device (the server's `enteredAt`) and this device's own
 * unsent ones, newest first, one row per entry.
 *
 * @returns {{entry: object, at: number}[]}
 */
export function recentEntries(entries, outboxItems, limit = 15) {
    const latest = new Map();

    for (const entry of entries) {
        if (entry.enteredAt) {
            const at = Date.parse(entry.enteredAt);

            if (!Number.isNaN(at)) {
                latest.set(entry.ref, { entry, at });
            }
        }
    }

    const byRef = new Map(entries.map((entry) => [entry.ref, entry]));

    for (const item of outboxItems) {
        const entry = byRef.get(item.entryRef);

        if (entry === undefined || item.field !== 'result') {
            continue;
        }

        const known = latest.get(item.entryRef);

        if (known === undefined || known.at < item.createdAt) {
            latest.set(item.entryRef, { entry, at: item.createdAt });
        }
    }

    return [...latest.values()].sort((a, b) => b.at - a.at).slice(0, limit);
}

/**
 * The entry of a participant in the round: their own (solo round) or their pair's/team's.
 */
export function entryOfParticipant(entries, participantId) {
    const id = String(participantId ?? '').toLowerCase();

    if (id === '') {
        return null;
    }

    return entries.find((entry) => (entry.participantId ?? '').toLowerCase() === id
        || (Array.isArray(entry.members) && entry.members.some((member) => (member.participantId ?? '').toLowerCase() === id))) ?? null;
}

/**
 * A referee's live update withholds every private linked player (`playerWithheld` - the update has no viewer,
 * OfficialResultsLiveUpdates::refereesTopic()): the entry keeps what this device's own state showed of that person -
 * a private player who lets this referee see them stays visible. Nothing is kept that the state did not show.
 */
export function keepWithheldPlayers(before, after) {
    if (!before || !after) {
        return after;
    }

    let merged = after;

    if (after.playerWithheld === true && before.playerId && (before.participantId ?? null) === (after.participantId ?? null)) {
        merged = { ...merged, playerId: before.playerId, playerCode: before.playerCode ?? null, playerName: before.playerName ?? null };
    }

    if (Array.isArray(after.members) && Array.isArray(before.members) && after.members.some((member) => member.playerWithheld === true)) {
        const known = new Map(before.members.map((member) => [member.participantId, member]));

        merged = {
            ...merged,
            members: after.members.map((member) => {
                const previous = known.get(member.participantId);

                return member.playerWithheld === true && previous?.playerId
                    ? { ...member, playerId: previous.playerId, playerCode: previous.playerCode ?? null, playerName: previous.playerName ?? null }
                    : member;
            }),
        };
    }

    return merged;
}

/**
 * The round a device lands on through the event link (`auto`): the server's current round, unless the device picked
 * another round by hand in the last 12 hours that is still running or not started (parallel halls).
 */
export function preferredRound(currentRoundId, remembered, rounds, now) {
    if (!remembered || typeof remembered.roundId !== 'string' || remembered.roundId === currentRoundId) {
        return currentRoundId;
    }

    if (typeof remembered.at !== 'number' || now - remembered.at > 12 * 3600 * 1000) {
        return currentRoundId;
    }

    const round = rounds.find((candidate) => candidate.id === remembered.roundId);

    if (!round || (round.stopwatch && round.stopwatch.status === 'stopped')) {
        return currentRoundId;
    }

    return round.id;
}
