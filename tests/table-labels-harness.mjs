// Runs assets/table_labels.js for the cases tests/TableLabelsScriptTest.php hands over on stdin and prints each result
// as JSON (table-labels-and-plates.md).

import { readFileSync } from 'node:fs';
import * as labels from '../assets/table_labels.js';
import { buildSearchIndex, searchEntries, uniqueTable } from '../assets/official_results_live.js';

const entriesAt = (list) => list.map((label, index) => ({ ref: `p:${index + 1}`, name: `Entrant ${index + 1}`, members: [], tableNumber: index + 1 }));

const operations = {
    key: ({ text }) => labels.key(text),
    validate: ({ list }) => labels.validate(list),
    generate: ({ settings }) => labels.generate(settings),
    parseList: ({ text }) => labels.parseList(text),
    lookup: ({ list, number, typed }) => {
        const lookup = labels.tableLabels(list);

        return { label: labels.tableText(lookup, number), number: lookup?.numberOf(typed) ?? null };
    },
    continues: ({ typed, label }) => labels.continues(labels.key(typed), labels.key(label)),
    ranges: ({ text, list }) => labels.labelRanges(text, labels.tableLabels(list)),
    // The judge's find box over a round with these labels (every label seated): which entry opens without Enter for
    // each typed text, and the exact table Enter opens
    typing: ({ list, typed }) => {
        const lookup = labels.tableLabels(list);
        const index = buildSearchIndex(entriesAt(list), lookup);

        return typed.map((text) => {
            const unique = uniqueTable(index, text, { labels: lookup });
            const found = searchEntries(index, text, { labels: lookup });

            return {
                typed: text,
                opens: unique === null ? null : lookup.labelOf(unique.tableNumber),
                exact: found.exactTable === null ? null : lookup.labelOf(found.exactTable.tableNumber),
                first: found.matches.slice(0, 3).map((entry) => lookup.labelOf(entry.tableNumber)),
            };
        });
    },
};

const cases = JSON.parse(readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify(cases.map(({ op, ...input }) => operations[op](input))));
