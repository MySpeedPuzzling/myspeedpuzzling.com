// Runs assets/official_results_puzzles.js (the per-puzzle record of a `pick` round entry in the browser) for the cases
// tests/OfficialResultsPuzzlesParityTest.php hands over on stdin, and prints what it says about each as JSON.

import { readFileSync } from 'node:fs';
import { consistencyRefusal, duration, itemsRefusal, total, unfinishedMaximum, validate } from '../assets/official_results_puzzles.js';

const { cases } = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(cases.map(({ items, round, result }) => ({
    items: itemsRefusal(items, round),
    consistency: consistencyRefusal(result, items, round),
    validate: validate(items, round, result),
    total: total(items, round),
    durations: (items ?? []).map((item, index) => duration(items, index)),
    maximum: unfinishedMaximum(round),
}))));
