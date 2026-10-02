// The browser half of Twig `|ago` (src/Services/RelativeTimeFormatter.php): the largest whole unit,
// cut off and never rounded, worded with the raw `time` translations and picked by Symfony's own
// plural rules - the browser's Intl.RelativeTimeFormat words it differently ("před 1 hodinou" vs our
// "před hodinou"). Only for anything younger than 28 days: from there PHP counts calendar months, and
// such a label changes once a month, so it keeps the server's text.
//
// No DOM: tests/RelativeTimeParityTest.php runs it under node against the PHP formatter in every locale.

import { chooseTranslation } from './translation_choice.js';

export const MAX_SECONDS = 28 * 86400;

const UNITS = [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

/**
 * @param {number} elapsedSeconds seconds since the moment; below zero (a visitor's clock running
 *     slow) reads "now" - the server never renders a feed item from the future
 * @param {{second: string, minute: string, hour: string, day: string, empty: string}} messages
 *     the raw catalogue entries diff.ago.* and diff.empty
 * @param {string} locale
 * @returns {string|null} null when the server's text has to stay
 */
export function formatRelativeTime(elapsedSeconds, messages, locale) {
    const seconds = Math.floor(elapsedSeconds);

    if (seconds >= MAX_SECONDS) {
        return null;
    }

    for (const [unit, size] of UNITS) {
        const count = Math.floor(seconds / size);

        if (count > 0) {
            return chooseTranslation(messages[unit], count, locale);
        }
    }

    return messages.empty;
}

/**
 * Seconds until formatRelativeTime() returns a different text, Infinity once it returns null.
 */
export function secondsUntilChange(elapsedSeconds) {
    if (elapsedSeconds >= MAX_SECONDS) {
        return Infinity;
    }

    // "now" - also while a clock running slow puts the moment in the future - until one whole second has passed
    if (elapsedSeconds < 1) {
        return 1 - elapsedSeconds;
    }

    const size = UNITS.find(([, unitSize]) => elapsedSeconds >= unitSize)?.[1] ?? 1;

    return (Math.floor(elapsedSeconds / size) + 1) * size - elapsedSeconds;
}
