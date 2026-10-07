/**
 * Seating of a round - the pure half of the seating page (controllers/round_seating_controller.js): the lists' order,
 * and the table number writes each action sends (one `official_results_assign_table_numbers` call - validated as a
 * whole, so nothing is ever half applied). Runs under node in tests/SeatingJsTest.php.
 * docs/features/competitions-management/seating.md
 *
 * An entry is the official results JSON of a round entry ({ref, id, displayName, tableNumber, members, ...}); `byRef`
 * is a Map of ref => entry; an assignment is {entry: ref, number: int|null} - sent with `from` (withFrom()), the number
 * the page saw, so a number another organiser changed meanwhile refuses the write instead of being overwritten.
 */

export const MAX_TABLE_NUMBER = 9999;

/**
 * Lower case without accents - "Žofie" is found by "zofie".
 */
export function fold(text) {
    return String(text ?? '').normalize('NFKD').replace(/\p{Mn}+/gu, '').toLowerCase().trim();
}

/**
 * By display name (the page's language), then id - the same entries always in the same order.
 */
export function nameComparator(locale) {
    let collator;

    try {
        collator = new Intl.Collator(locale, { sensitivity: 'base', numeric: true });
    } catch (e) {
        collator = new Intl.Collator('en', { sensitivity: 'base', numeric: true });
    }

    return (a, b) => collator.compare(a.displayName ?? '', b.displayName ?? '') || compareText(a.id ?? '', b.id ?? '');
}

function compareText(a, b) {
    if (a === b) {
        return 0;
    }

    return a < b ? -1 : 1;
}

/**
 * The page's two lists: entries without a table first (by name), then the seated ones by table number.
 *
 * @returns {{unseated: string[], seated: string[]}}
 */
export function splitByTable(entries, compareNames) {
    const unseated = entries.filter((entry) => entry.tableNumber === null).sort(compareNames);
    const seated = entries.filter((entry) => entry.tableNumber !== null)
        .sort((a, b) => a.tableNumber - b.tableNumber || compareNames(a, b));

    return { unseated: unseated.map((entry) => entry.ref), seated: seated.map((entry) => entry.ref) };
}

/**
 * Keeps the organiser's own (dragged) order after entries changed elsewhere: entries gone are dropped, new ones join
 * the "No table yet" list on top, or the seated list's end when they came with a table.
 */
export function mergeOrder(unseated, seated, byRef) {
    const known = new Set([...unseated, ...seated]);
    const keep = (ref) => byRef.has(ref);
    const added = [...byRef.values()].filter((entry) => !known.has(entry.ref));

    return {
        unseated: [...added.filter((entry) => entry.tableNumber === null).map((entry) => entry.ref), ...unseated.filter(keep)],
        seated: [...seated.filter(keep), ...added.filter((entry) => entry.tableNumber !== null).map((entry) => entry.ref)],
    };
}

/**
 * Move up / down - the keyboard and phone twin of drag and drop, across both lists: the first seated entry moved up
 * leaves the seated list, the last one without a table moved down joins it.
 */
export function moveInLists(unseated, seated, ref, direction) {
    const nextUnseated = [...unseated];
    const nextSeated = [...seated];
    const seatedIndex = nextSeated.indexOf(ref);
    const unseatedIndex = nextUnseated.indexOf(ref);

    if (seatedIndex !== -1) {
        if (direction === 'up') {
            if (seatedIndex > 0) {
                [nextSeated[seatedIndex - 1], nextSeated[seatedIndex]] = [nextSeated[seatedIndex], nextSeated[seatedIndex - 1]];
            } else {
                nextSeated.shift();
                nextUnseated.push(ref);
            }
        } else if (seatedIndex < nextSeated.length - 1) {
            [nextSeated[seatedIndex + 1], nextSeated[seatedIndex]] = [nextSeated[seatedIndex], nextSeated[seatedIndex + 1]];
        }
    } else if (unseatedIndex !== -1) {
        if (direction === 'down') {
            if (unseatedIndex < nextUnseated.length - 1) {
                [nextUnseated[unseatedIndex + 1], nextUnseated[unseatedIndex]] = [nextUnseated[unseatedIndex], nextUnseated[unseatedIndex + 1]];
            } else {
                nextUnseated.pop();
                nextSeated.unshift(ref);
            }
        } else if (unseatedIndex > 0) {
            [nextUnseated[unseatedIndex - 1], nextUnseated[unseatedIndex]] = [nextUnseated[unseatedIndex], nextUnseated[unseatedIndex - 1]];
        }
    }

    return { unseated: nextUnseated, seated: nextSeated };
}

/**
 * Where "Renumber in this order" starts: the smallest table among the seated list (a second hall numbered from 101
 * stays from 101), else 1.
 */
export function renumberStart(seated, byRef) {
    let smallest = null;

    for (const ref of seated) {
        const number = byRef.get(ref)?.tableNumber ?? null;

        if (number !== null && (smallest === null || number < smallest)) {
            smallest = number;
        }
    }

    return smallest ?? 1;
}

/**
 * "Renumber in this order": the seated list gets start, start + 1, …; entries moved into "No table yet" lose their
 * table. Only entries whose number changes are listed - every other one already has its new number.
 */
export function renumberAssignments(unseated, seated, byRef, start) {
    const assignments = [];

    seated.forEach((ref, index) => {
        const number = start + index;

        if ((byRef.get(ref)?.tableNumber ?? null) !== number) {
            assignments.push({ entry: ref, number });
        }
    });

    unseated.forEach((ref) => {
        if ((byRef.get(ref)?.tableNumber ?? null) !== null) {
            assignments.push({ entry: ref, number: null });
        }
    });

    return assignments;
}

/**
 * Latecomers: the entries without a table get the tables after the highest one, in their listed order.
 */
export function seatRestAssignments(unseated, byRef) {
    const first = highestTable(byRef) + 1;

    return unseated.map((ref, index) => ({ entry: ref, number: first + index }));
}

export function highestTable(byRef) {
    let highest = 0;

    for (const entry of byRef.values()) {
        if (entry.tableNumber !== null && entry.tableNumber > highest) {
            highest = entry.tableNumber;
        }
    }

    return highest;
}

/**
 * Two entries trade tables (one without a table gives its place to the other).
 */
export function swapAssignments(a, b) {
    return [
        { entry: a.ref, number: b.tableNumber ?? null },
        { entry: b.ref, number: a.tableNumber ?? null },
    ];
}

/**
 * The table typed in for one entry is somebody else's: they trade.
 */
export function takeOverAssignments(entry, number, holder) {
    return [
        { entry: entry.ref, number },
        { entry: holder.ref, number: entry.tableNumber ?? null },
    ];
}

export function clearAssignments(byRef) {
    return [...byRef.values()].filter((entry) => entry.tableNumber !== null).map((entry) => ({ entry: entry.ref, number: null }));
}

/**
 * Applying a proposal ({entry, tableNumber} rows): only entries whose table changes.
 */
export function proposalAssignments(rows, byRef) {
    return rows
        .filter((row) => (byRef.get(row.entry)?.tableNumber ?? null) !== row.tableNumber)
        .map((row) => ({ entry: row.entry, number: row.tableNumber }));
}

/**
 * Whether a proposal still numbers exactly the round's entrants - else it is asked for again before applying.
 */
export function proposalCoversEntrants(rows, byRef) {
    if (rows.length !== byRef.size) {
        return false;
    }

    return rows.every((row) => byRef.has(row.entry));
}

/**
 * What undoes a write: every entry it listed back to the table it had before (`before` = Map ref => number) - `from` is
 * the number the write set, so an entry somebody else renumbered since is not undone over their change.
 */
export function undoAssignments(assignments, before) {
    return assignments.map((assignment) => ({ entry: assignment.entry, from: assignment.number, number: before.get(assignment.entry) ?? null }));
}

/**
 * Every assignment with `from` = the number the page shows for the entry now (an assignment that has one keeps it).
 */
export function withFrom(assignments, byRef) {
    return assignments.map((assignment) => ('from' in assignment
        ? assignment
        : { entry: assignment.entry, from: byRef.get(assignment.entry)?.tableNumber ?? null, number: assignment.number }));
}

export function numbersOf(byRef) {
    return new Map([...byRef.values()].map((entry) => [entry.ref, entry.tableNumber ?? null]));
}

/**
 * The entry holding a table number, other than `exceptRef`.
 */
export function holderOf(byRef, number, exceptRef = null) {
    for (const entry of byRef.values()) {
        if (entry.tableNumber === number && entry.ref !== exceptRef) {
            return entry;
        }
    }

    return null;
}

/**
 * A typed table number: '' = no table, else a whole number 1..9999.
 *
 * @returns {{number: number|null} | {error: true}}
 */
export function parseTableNumber(text) {
    const trimmed = String(text ?? '').trim();

    if (trimmed === '') {
        return { number: null };
    }

    if (!/^\d{1,4}$/.test(trimmed)) {
        return { error: true };
    }

    const number = parseInt(trimmed, 10);

    return number >= 1 && number <= MAX_TABLE_NUMBER ? { number } : { error: true };
}

/**
 * A table number typed into the list (round_seating_controller.js): what to do with it. `seen` = the entry's number
 * the input showed when the organiser started typing, `current` = the entry's number now - a live update from another
 * organiser may have changed it meanwhile -, `typed` = the parsed number (null = no table).
 *
 * - `nothing`: the entry has that number already;
 * - `meanwhile`: somebody else changed the number while the organiser was typing - shown with Keep mine / Take theirs,
 *   never written over silently (browser verification BLOCKER 1, the seating page's inline editor);
 * - `write` with `from` = what the organiser saw.
 */
export function typedNumberWrite(seen, current, typed) {
    if (typed === current) {
        return { action: 'nothing' };
    }

    if (seen !== current) {
        return { action: 'meanwhile', current };
    }

    return { action: 'write', from: seen };
}

/**
 * The number an input showed (its `data-seen`, written whenever the page fills the input from the round's data);
 * an input never filled yet showed the entry's number.
 */
export function seenNumber(seenAttribute, current) {
    if (seenAttribute === undefined || seenAttribute === null) {
        return current;
    }

    return seenAttribute === '' ? null : parseInt(seenAttribute, 10);
}

/**
 * The "Find" box: a table number exactly, or a part of the name, a member's name or the #code - accents ignored.
 */
export function matchesQuery(entry, query) {
    const folded = fold(query);

    if (folded === '') {
        return true;
    }

    if (/^\d+$/.test(folded) && entry.tableNumber !== null && String(entry.tableNumber) === folded) {
        return true;
    }

    const words = [
        entry.displayName,
        entry.name,
        entry.playerName,
        entry.playerCode ? `#${entry.playerCode}` : '',
        ...(entry.members ?? []).flatMap((member) => [member.name, member.playerName, member.playerCode ? `#${member.playerCode}` : '']),
    ];

    return words.some((word) => fold(word).includes(folded));
}
