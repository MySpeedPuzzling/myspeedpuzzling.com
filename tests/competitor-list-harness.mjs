// Runs assets/competitor_list_filter.js for what tests/CompetitorListScriptTest.php hands over on stdin - cases of
// sections (rows' `data-search`) and a typed query - and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { filterCompetitorList } from '../assets/competitor_list_filter.js';

const input = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(input.cases.map(({ sections, query }) => filterCompetitorList(sections, query))));
