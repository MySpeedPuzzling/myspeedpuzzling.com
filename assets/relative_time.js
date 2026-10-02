// The browser half of Twig `|ago` (src/Services/RelativeTimeFormatter.php): the largest whole unit,
// cut off and never rounded, worded with the raw `time` translations and picked by Symfony's own
// plural rules - the browser's Intl.RelativeTimeFormat words it differently ("před 1 hodinou" vs our
// "před hodinou"). Only for anything younger than 28 days: from there PHP counts calendar months, and
// such a label changes once a month, so it keeps the server's text.
//
// Plain module without imports or DOM: tests/RelativeTimeParityTest.php runs it under node against
// the PHP formatter in every locale.

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
            return choose(messages[unit], count, locale);
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

// Symfony\Contracts\Translation\TranslatorTrait::trans() - explicit intervals first ({1}, [2, Inf],
// ]1,5[), then the locale's plural position among the remaining parts
const INTERVAL = /^(?:(\{\s*(-?\d+(?:\.\d+)?[\s*,\s*\-?\d+(\.\d+)?]*)\s*\})|([[\]])\s*(-Inf|-?\d+(?:\.\d+)?)\s*,\s*(\+?Inf|-?\d+(?:\.\d+)?)\s*([[\]]))\s*([\s\S]*?)$/;

function choose(id, count, locale) {
    if (typeof id !== 'string') {
        return null;
    }

    const parts = /^\|+$/.test(id) ? id.split('|') : (id.match(/(?:\|\||[^|])+/g) ?? []);
    const standardRules = [];

    for (const rawPart of parts) {
        const part = rawPart.replaceAll('||', '|').trim();
        const interval = part.match(INTERVAL);

        if (interval !== null) {
            if (interval[1] !== undefined) {
                if (interval[2].split(',').some((n) => Number(n) === count)) {
                    return fill(interval[7], count);
                }
            } else {
                const left = interval[4] === '-Inf' ? -Infinity : Number(interval[4]);
                const right = Number.isNaN(Number(interval[5])) ? Infinity : Number(interval[5]);

                if ((interval[3] === '[' ? count >= left : count > left) && (interval[6] === ']' ? count <= right : count < right)) {
                    return fill(interval[7], count);
                }
            }
        } else {
            const labelled = part.match(/^\w+:\s*([\s\S]*?)$/);
            standardRules.push(labelled !== null ? labelled[1] : part);
        }
    }

    const rule = standardRules[pluralPosition(count, locale)];

    if (rule !== undefined) {
        return fill(rule, count);
    }

    // PHP throws for a message without a fitting form - the server's text stays
    return parts.length === 1 && standardRules.length === 1 ? fill(standardRules[0], count) : null;
}

function fill(message, count) {
    return message.replaceAll('%count%', String(count));
}

// TranslatorTrait::getPluralizationRule() for the site's locales
function pluralPosition(number, locale) {
    const n = Math.abs(number);
    const language = locale.length > 3 ? locale.slice(0, locale.lastIndexOf('_')) : locale;

    switch (language) {
        case 'de':
        case 'en':
        case 'es':
            return n === 1 ? 0 : 1;
        case 'fr':
            return n < 2 ? 0 : 1;
        case 'cs':
            return n === 1 ? 0 : (n >= 2 && n <= 4 ? 1 : 2);
        default:
            // ja and every locale PHP has no rule for
            return 0;
    }
}
