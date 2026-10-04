// Runs assets/search_fold.js (the browser half of SearchText::fold()) for what tests/SearchFoldParityTest.php hands
// over on stdin - texts to fold, typed queries to match against search keys - and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { foldSearchText, searchKeyMatcher } from '../assets/search_fold.js';

const input = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify({
    folded: input.texts.map((text) => foldSearchText(text)),
    matches: input.matches.map(({ query, key }) => searchKeyMatcher(query)(key)),
}));
