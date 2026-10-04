// Searches the puzzle picker's options the way Tom Select does - its own scoring library (@orchidjs/sifter), the
// form types' weighted `searchField`, diacritics folded, every typed word required, ties in the options' order - for
// what tests/PuzzlePickerRankingTest.php hands over on stdin, and prints each query's matches as JSON.

import { readFileSync } from 'node:fs';
import { Sifter } from '@orchidjs/sifter';

const { options, fields, queries } = JSON.parse(readFileSync(0, 'utf8'));
const sifter = new Sifter(options.map((option, order) => ({ ...option, $order: order })), { diacritics: true });

process.stdout.write(JSON.stringify(queries.map((query) => sifter
    .search(query, { fields, conjunction: 'and', sort: [{ field: '$order' }] })
    .items.map((item) => ({ value: options[item.id].value, score: item.score })))));
