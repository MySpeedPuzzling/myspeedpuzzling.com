/**
 * Table labels (docs/features/competitions-management/table-labels-and-plates.md): a round's optional list of names of
 * its table numbers - item i names table number i + 1 ("A1-1", "A1-2", …). The number stays the identity and the wire
 * format; labels are shown and typed. Twin of src/Value/TableLabels.php + TableLabelGenerator.php (shared cases
 * tests/fixtures/table-labels-cases.json - change both).
 *
 * Pure functions - tests/table-labels-harness.mjs runs them under node.
 */

export const MAX_LENGTH = 12;
export const MAX_COUNT = 9999;
export const MAX_SEATS = 4;
const LABEL_PATTERN = /^[A-Za-z0-9](?:[A-Za-z0-9 .\-/]*[A-Za-z0-9])?$/;

export function normalise(label) {
    return String(label ?? '').replace(/\s+/gu, ' ').trim();
}

/**
 * The folded key: letter runs and digit runs are segments, upper-cased, digit runs without leading zeros, joined by
 * `.` - "a1 1" → `A.1.1`, "A1L" → `A.1.L`, "B07" → `B.7`.
 */
export function segmentsOf(text) {
    return (String(text ?? '').match(/[A-Za-z]+|[0-9]+/g) ?? []).map((segment) => (
        /^[0-9]+$/.test(segment) ? (segment.replace(/^0+/, '') || '0') : segment.toUpperCase()
    ));
}

export function key(text) {
    return segmentsOf(text).join('.');
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

/**
 * A round's labels as a lookup: `labelOf(n)` (the number itself beyond the list, '' never), `numberOf(typed)` (exact
 * key) and the entries' keys for searching. `labels` null/empty = plain numbers: null.
 */
export function tableLabels(labels) {
    if (!Array.isArray(labels) || labels.length === 0) {
        return null;
    }

    const numbers = new Map();
    const keys = labels.map((label, index) => {
        const folded = key(label);

        if (!numbers.has(folded)) {
            numbers.set(folded, index + 1);
        }

        return folded;
    });

    return {
        labels,
        count: labels.length,
        keyOf: (number) => keys[number - 1] ?? String(number),
        labelOf: (number) => (typeof labels[number - 1] === 'string' && labels[number - 1] !== '' ? labels[number - 1] : String(number)),
        numberOf: (typed) => {
            const folded = key(typed);

            return folded === '' ? null : (numbers.get(folded) ?? null);
        },
        names: (number) => Number.isInteger(number) && number >= 1 && number <= labels.length,
    };
}

/**
 * What a table number reads as: its label on a labelled round (`lookup` from tableLabels()), else the number; '' for
 * none.
 */
export function tableText(lookup, number) {
    if (typeof number !== 'number') {
        return '';
    }

    return lookup === null || lookup === undefined ? String(number) : lookup.labelOf(number);
}

/**
 * Whether a label (its key) continues what was typed (its key): the typed segments are the label's first segments,
 * the last typed one may be the start of the label's segment - `A.1` continues `A.1.1` and `A.12`, not `B.1`.
 */
export function continues(typedKey, labelKey) {
    if (typedKey === '') {
        return false;
    }

    const typed = typedKey.split('.');
    const label = labelKey.split('.');

    if (typed.length > label.length) {
        return false;
    }

    for (let index = 0; index < typed.length - 1; index++) {
        if (typed[index] !== label[index]) {
            return false;
        }
    }

    return label[typed.length - 1].startsWith(typed[typed.length - 1]);
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

/**
 * "My tables" on a labelled round (judge page, live_entry_my_tables.js): `A, B` = every label starting with segment A /
 * B (a whole column), `C3` = the labels of table C3 (C3-1, C3-2) or C3 itself, `A1-1..A5-2` (also ` to ` or an en/em
 * dash) = a range in list order. → `{ranges: [[from, to], …]}` over the table numbers (merged, sorted) or `{error:
 * 'syntax'|'unknown', token}`. `lenient`: a token naming nothing in this round names nothing (another round's text).
 */
export function labelRanges(text, lookup, { lenient = false } = {}) {
    const source = String(text ?? '').trim();

    if (source === '' || lookup === null) {
        return { ranges: [] };
    }

    const keys = lookup.labels.map((label) => key(label));
    const numbers = new Set();

    for (const raw of source.replace(/\s*(?:\.\.|[–—]|\bto\b)\s*/giu, '..').split(/[,;]+/)) {
        const token = raw.trim();

        if (token === '') {
            continue;
        }

        const range = token.split('..');

        if (range.length > 2 || range.some((part) => key(part) === '')) {
            if (lenient) {
                continue;
            }

            return { error: 'syntax', token };
        }

        if (range.length === 2) {
            const from = lookup.numberOf(range[0]);
            const to = lookup.numberOf(range[1]);

            if (from === null || to === null) {
                if (lenient) {
                    continue;
                }

                return { error: 'unknown', token: from === null ? range[0].trim() : range[1].trim() };
            }

            for (let number = Math.min(from, to); number <= Math.max(from, to); number++) {
                numbers.add(number);
            }

            continue;
        }

        const typed = key(token).split('.');
        let found = false;

        keys.forEach((labelKey, index) => {
            const label = labelKey.split('.');

            if (typed.length <= label.length && typed.every((segment, position) => segment === label[position])) {
                numbers.add(index + 1);
                found = true;
            }
        });

        if (!found && !lenient) {
            return { error: 'unknown', token };
        }
    }

    const sorted = [...numbers].sort((a, b) => a - b);
    const ranges = [];

    for (const number of sorted) {
        const last = ranges[ranges.length - 1];

        if (last !== undefined && number === last[1] + 1) {
            last[1] = number;
        } else {
            ranges.push([number, number]);
        }
    }

    return { ranges };
}
