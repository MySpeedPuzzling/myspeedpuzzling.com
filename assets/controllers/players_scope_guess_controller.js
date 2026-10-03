import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/**
 * The Players page's scope switch (docs/features/players-page/README.md).
 *
 * - "Another country…" opens the page on the picked country as soon as it is picked (the <noscript> button covers
 *   browsers without JavaScript).
 * - Guests get a one-tap chip for the country their browser suggests. The guess happens here and not on the server
 *   because guest HTML is shared-cached: the region of a browser language (de-AT → Austria) first, then a language
 *   spoken in one country only (cs → Czechia). A guess is only offered, never applied, and only for countries that
 *   have puzzlers.
 */
const ONE_COUNTRY_LANGUAGES = {
    bg: 'bg', cs: 'cz', da: 'dk', el: 'gr', et: 'ee', fi: 'fi', hr: 'hr', hu: 'hu', is: 'is', ja: 'jp', ko: 'kr',
    lt: 'lt', lv: 'lv', nb: 'no', nn: 'no', no: 'no', pl: 'pl', ro: 'ro', sk: 'sk', sl: 'si', sv: 'se', uk: 'ua',
};

export default class extends Controller {
    static targets = ['chip', 'flag', 'name'];

    static values = {
        countries: Object,
        current: String,
        skip: Boolean,
        url: String,
    };

    connect() {
        if (this.skipValue || !this.hasChipTarget) {
            return;
        }

        const guess = this.guess();

        if (guess === null || guess === this.currentValue) {
            return;
        }

        this.chipTarget.href = this.urlFor(guess);
        this.flagTarget.classList.add(`fi-${guess}`);
        this.nameTarget.textContent = this.countriesValue[guess];
        this.chipTarget.hidden = false;
    }

    pick(event) {
        const code = event.target.value;

        if (code === '') {
            return;
        }

        visit(this.urlFor(code));
    }

    guess() {
        const languages = Array.isArray(navigator.languages) && navigator.languages.length > 0
            ? navigator.languages
            : [navigator.language || ''];

        for (const language of languages) {
            const region = (language.split('-')[1] || '').toLowerCase();

            if (region.length === 2 && region in this.countriesValue) {
                return region;
            }
        }

        for (const language of languages) {
            const country = ONE_COUNTRY_LANGUAGES[language.split('-')[0].toLowerCase()];

            if (country && country in this.countriesValue) {
                return country;
            }
        }

        return null;
    }

    urlFor(code) {
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('scope', code);

        return url.pathname + url.search;
    }
}
