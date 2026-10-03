// The browser's guess at a visitor's country (docs/features/players-page/README.md): the region of a browser language
// first (de-AT → Austria), then a language spoken in one country only (cs → Czechia). Shared by the Players page's
// scope switch (players_scope_guess_controller.js) and "Add your country" (players_country_nudge_controller.js).
// A guess is only ever offered or preselected, never applied.
//
// The guess happens in the browser and not on the server because guest HTML is shared-cached.

const ONE_COUNTRY_LANGUAGES = {
    bg: 'bg', cs: 'cz', da: 'dk', el: 'gr', et: 'ee', fi: 'fi', hr: 'hr', hu: 'hu', is: 'is', ja: 'jp', ko: 'kr',
    lt: 'lt', lv: 'lv', nb: 'no', nn: 'no', no: 'no', pl: 'pl', ro: 'ro', sk: 'sk', sl: 'si', sv: 'se', uk: 'ua',
};

export function browserLanguages() {
    return Array.isArray(navigator.languages) && navigator.languages.length > 0
        ? navigator.languages
        : [navigator.language || ''];
}

/**
 * @param {function(string): boolean} isOffered whether a lowercase country code (`cz`) may be suggested
 * @param {string[]} languages BCP 47 tags, the browser's own by default
 * @returns {string|null} the lowercase country code, or null without a guess
 */
export function guessCountry(isOffered, languages = browserLanguages()) {
    for (const language of languages) {
        const region = (language.split('-')[1] || '').toLowerCase();

        if (region.length === 2 && isOffered(region)) {
            return region;
        }
    }

    for (const language of languages) {
        const base = language.split('-')[0].toLowerCase();
        const country = Object.prototype.hasOwnProperty.call(ONE_COUNTRY_LANGUAGES, base) ? ONE_COUNTRY_LANGUAGES[base] : null;

        if (country !== null && isOffered(country)) {
            return country;
        }
    }

    return null;
}
