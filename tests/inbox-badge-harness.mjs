// Runs assets/inbox_badge.js (the browser twin of InboxBadgeParts) and assets/translation_choice.js for the cases
// tests/Value/InboxBadgeScriptTest.php hands over on stdin, and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { displayCount, inboxBadgeState } from '../assets/inbox_badge.js';
import { chooseTranslation } from '../assets/translation_choice.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(cases.map(({ forYou, conversations, requests, labels }) => {
    const state = inboxBadgeState({ forYou, conversations, requests });
    const label = labels[state.labelKind];

    return {
        total: displayCount(state.parts.total),
        for_you: displayCount(state.parts.for_you),
        messages: displayCount(state.parts.messages),
        target: state.target,
        label: chooseTranslation(label.message, state.labelCount, label.locale),
    };
})));
