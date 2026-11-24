/**
 * The "Table names" editor's half of assets/table_labels.js (table-labels-and-plates.md): the hall plan generator and
 * the server's validation rules - twins of src/Value/TableLabelGenerator.php and TableLabels::fromList() (shared cases
 * tests/fixtures/table-labels-cases.json). Only the seating page loads it.
 *
 * Pure functions - tests/table-labels-harness.mjs runs them under node.
 */

import { key, MAX_COUNT, MAX_LENGTH, MAX_SEATS, tableLabels, tableText } from './table_labels.js';

const LABEL_PATTERN = /^[A-Za-z0-9](?:[A-Za-z0-9 .\-/]*[A-Za-z0-9])?$/;

export function normalise(label) {
    return String(label ?? '').replace(/\s+/gu, ' ').trim();
}

export function problemOf(label) {
    if (label === '') {
        return 'empty';
    }

    if ([...label].length > MAX_LENGTH) {
        return 'too_long';
    }

    return LABEL_PATTERN.test(label) ? null : 'characters';
}

/**
 * The organiser's list → `{labels}` (normalised) or `{problems: [{label, reason, first?}]}` - the server's rules.
 */
export function validate(list) {
    if (!Array.isArray(list) || list.length === 0) {
        return { problems: [{ label: '', reason: 'empty_list' }] };
    }

    if (list.length > MAX_COUNT) {
        return { problems: [{ label: '', reason: 'too_many' }] };
    }

    const problems = [];
    const seen = new Map();
    const labels = list.map(normalise);

    for (const label of labels) {
        const reason = problemOf(label);

        if (reason !== null) {
            problems.push({ label, reason });
            continue;
        }

        const folded = key(label);

        if (seen.has(folded)) {
            problems.push({ label, reason: 'duplicate', first: seen.get(folded) });
            continue;
        }

        seen.set(folded, label);
    }

    return problems.length > 0 ? { problems: problems.slice(0, 20) } : { labels };
}

/**
 * The editor's text: one label per line, or separated by commas / semicolons / tabs. Empty items dropped.
 */
export function parseList(text) {
    return String(text ?? '').split(/[\n\r,;\t]+/).map(normalise).filter((label) => label !== '');
}

function letterList(text) {
    const source = String(text ?? '').trim().toUpperCase().replace(/\s*[-–—]\s*/g, '-');

    if (source === '') {
        return [];
    }

    const letters = [];

    for (const token of source.split(/\s*[,;]\s*|\s+/)) {
        if (token === '') {
            continue;
        }

        const match = /^([A-Z])(?:-([A-Z]))?$/.exec(token);

        if (match === null) {
            throw { label: token, reason: 'letters' };
        }

        const from = match[1].charCodeAt(0);
        const to = (match[2] ?? match[1]).charCodeAt(0);

        if (to < from) {
            throw { label: token, reason: 'letters' };
        }

        for (let code = from; code <= to; code++) {
            const letter = String.fromCharCode(code);

            if (!letters.includes(letter)) {
                letters.push(letter);
            }
        }
    }

    return letters;
}

function numberList(text) {
    const source = String(text ?? '').trim().replace(/\s*[-–—]\s*/g, '-');
    const numbers = [];
    const seen = new Set();

    for (const token of source.split(/\s*[,;]\s*|\s+/)) {
        if (token === '') {
            continue;
        }

        const match = /^(\d{1,4})(?:-(\d{1,4}))?$/.exec(token);

        if (match === null) {
            throw { label: token, reason: 'numbers' };
        }

        const from = Number(match[1]);
        const to = match[2] === undefined ? from : Number(match[2]);

        if (from < 1 || to < from || to > MAX_COUNT) {
            throw { label: token, reason: 'numbers' };
        }

        for (let number = from; number <= to; number++) {
            if (!seen.has(number)) {
                seen.add(number);
                numbers.push(number);
            }
        }
    }

    if (numbers.length === 0) {
        throw { label: source, reason: 'numbers' };
    }

    return numbers;
}

function seatLabel(table, seat, style) {
    if (style === 'letter') {
        return table + String.fromCharCode(96 + seat);
    }

    if (style === 'side') {
        return `${table} ${seat === 1 ? 'L' : 'R'}`;
    }

    return `${table}-${seat}`;
}

/**
 * The hall plan generator (TableLabelGenerator::generate()): `{letters: 'A-H', numbers: '1-22', seats: 2, seatStyle:
 * 'dash'|'letter'|'side', order: 'letters'|'numbers', skip: 'H22'}` → `{labels}` or `{problems: [{label, reason}]}`.
 */
export function generate({ letters = '', numbers = '', seats = 1, seatStyle = 'dash', order = 'letters', skip = '' } = {}) {
    let letterValues;
    let numberValues;

    try {
        letterValues = letterList(letters);
        numberValues = numberList(numbers);
    } catch (problem) {
        return { problems: [problem] };
    }

    const seatCount = Number(seats);

    if (!Number.isInteger(seatCount) || seatCount < 1 || seatCount > MAX_SEATS) {
        return { problems: [{ label: String(seats), reason: 'seats' }] };
    }

    if (!['dash', 'letter', 'side'].includes(seatStyle) || (seatStyle === 'side' && seatCount > 2)) {
        return { problems: [{ label: String(seatStyle), reason: 'seat_style' }] };
    }

    const skipped = new Set(String(skip ?? '').trim().split(/[\s,;]+/u).filter((token) => token !== '').map(key));
    const tables = [];

    if (letterValues.length === 0) {
        tables.push(...numberValues.map(String));
    } else if (order === 'numbers') {
        for (const number of numberValues) {
            for (const letter of letterValues) {
                tables.push(letter + number);
            }
        }
    } else {
        for (const letter of letterValues) {
            for (const number of numberValues) {
                tables.push(letter + number);
            }
        }
    }

    const labels = [];

    for (const table of tables) {
        if (skipped.has(key(table))) {
            continue;
        }

        for (let seat = 1; seat <= seatCount; seat++) {
            const label = seatCount === 1 ? table : seatLabel(table, seat, seatStyle);

            if (!skipped.has(key(label))) {
                labels.push(label);
            }
        }

        if (labels.length > MAX_COUNT) {
            return { problems: [{ label: '', reason: 'too_many' }] };
        }
    }

    return { labels };
}

/**
 * What saving a new list (null = plain numbers) does to a round's seated entries (the editor's warning): `renamed` =
 * the entries whose table reads differently afterwards (`{name, from, to}`, in table order), `highest` = the highest
 * table number somebody sits at - a list shorter than that is refused.
 */
export function labelChanges(entries, oldList, newList) {
    const before = tableLabels(oldList);
    const after = tableLabels(newList);
    const renamed = [];
    let highest = 0;

    for (const entry of [...(entries ?? [])].sort((a, b) => (a.tableNumber ?? 0) - (b.tableNumber ?? 0))) {
        if (!Number.isInteger(entry.tableNumber)) {
            continue;
        }

        highest = Math.max(highest, entry.tableNumber);
        const from = tableText(before, entry.tableNumber);
        const to = tableText(after, entry.tableNumber);

        if (from !== to) {
            renamed.push({ name: entry.displayName ?? entry.name ?? '', from, to });
        }
    }

    return { renamed, highest };
}
