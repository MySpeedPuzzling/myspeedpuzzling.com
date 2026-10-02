// Runs assets/relative_time.js - the browser half of Twig `|ago` - for the cases
// tests/RelativeTimeParityTest.php hands over on stdin, and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { formatRelativeTime, secondsUntilChange } from '../assets/relative_time.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(cases.map(({ elapsed, messages, locale }) => ({
    text: formatRelativeTime(elapsed, messages, locale),
    changesIn: secondsUntilChange(elapsed) === Infinity ? null : secondsUntilChange(elapsed),
}))));
