// Runs assets/official_results_ranking.js (the results desk's ranking) for the round entries
// tests/OfficialResultsRankingParityTest.php hands over on stdin, and prints every round's order and ranks as JSON.

import { readFileSync } from 'node:fs';
import { rankEntries } from '../assets/official_results_ranking.js';

const { rounds } = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(rounds.map((entries) => rankEntries(entries).map((entry) => ({
    id: entry.id,
    rank: entry.rank,
})))));
