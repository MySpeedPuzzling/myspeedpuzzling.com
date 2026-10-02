// Runs assets/relative_time.js (the browser half of Twig `|ago`) and assets/translation_choice.js for the cases
// tests/RelativeTimeParityTest.php hands over on stdin, and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { formatRelativeTime, secondsUntilChange } from '../assets/relative_time.js';
import { chooseTranslation } from '../assets/translation_choice.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(cases.map((testCase) => {
    if (testCase.choice !== undefined) {
        return { text: chooseTranslation(testCase.choice, testCase.count, testCase.locale) };
    }

    const { elapsed, messages, locale } = testCase;

    return {
        text: formatRelativeTime(elapsed, messages, locale),
        changesIn: secondsUntilChange(elapsed) === Infinity ? null : secondsUntilChange(elapsed),
    };
})));
