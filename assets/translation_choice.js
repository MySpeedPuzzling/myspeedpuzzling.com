// Symfony's choice between the forms of a translation ("1 second ago|%count% seconds ago", "{1}včera|[3, Inf]…") for
// texts that change in the browser, so they read exactly as the same key translated by PHP. Pass the locale of the
// catalogue the message came from - a key only in English is chosen by English rules, as PHP's fallback does
// (BrowserTranslationTwigExtension). Pinned by tests/RelativeTimeParityTest.php.

// Symfony\Contracts\Translation\TranslatorTrait::trans() - explicit intervals first ({1}, [2, Inf],
// ]1,5[), then the locale's plural position among the remaining parts
const INTERVAL = /^(?:(\{\s*(-?\d+(?:\.\d+)?[\s*,\s*\-?\d+(\.\d+)?]*)\s*\})|([[\]])\s*(-Inf|-?\d+(?:\.\d+)?)\s*,\s*(\+?Inf|-?\d+(?:\.\d+)?)\s*([[\]]))\s*([\s\S]*?)$/;

export function chooseTranslation(id, count, locale) {
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
