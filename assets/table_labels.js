/**
 * Table labels (docs/features/competitions-management/table-labels-and-plates.md): a round's optional list of names of
 * its table numbers - item i names table number i + 1 ("A1-1", "A1-2", …). The number stays the identity and the wire
 * format; labels are shown and typed. Twin of src/Value/TableLabels.php + TableLabelGenerator.php (shared cases
 * tests/fixtures/table-labels-cases.json - change both).
 *
 * What the judge page, the desk, the seating page and the sheet need to show and read labels - the editor's half (the
 * hall plan generator, validation) is assets/table_labels_editor.js, kept out of the judge page's bundle.
 *
 * Pure functions - tests/table-labels-harness.mjs runs them under node.
 */

export const MAX_LENGTH = 12;
export const MAX_COUNT = 9999;
export const MAX_SEATS = 4;

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
    // The table numbers whose label starts with the token's whole segments ("C3" = C3, C3 L, C3 R)
    const prefixed = (token) => {
        const typed = key(token).split('.');
        const found = [];

        keys.forEach((labelKey, index) => {
            const label = labelKey.split('.');

            if (typed.length <= label.length && typed.every((segment, position) => segment === label[position])) {
                found.push(index + 1);
            }
        });

        return found;
    };

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
            // An end is a label or a whole table ("A1..A5" = from A1's first place to A5's last)
            const fromSet = prefixed(range[0]);
            const toSet = prefixed(range[1]);
            const from = fromSet.length > 0 ? Math.min(...fromSet) : null;
            const to = toSet.length > 0 ? Math.max(...toSet) : null;

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

        const matched = prefixed(token);
        matched.forEach((number) => numbers.add(number));
        const found = matched.length > 0;

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
