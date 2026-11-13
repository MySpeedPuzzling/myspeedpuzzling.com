/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import { browserTimeZone } from '../browser_timezone.js';
import { guessCountry } from '../country_guess.js';

/**
 * The "Nationality" field of the profile settings (templates/_country_field.html.twig): edit profile and finish profile.
 *
 * - Searchable with flags; every <option> carries `data-flag` and `data-alias` (the English name, so "Czechia" finds
 *   "Česko"). Countries of "The most common" are listed again under "All countries" - TomSelect shows such an option
 *   in both groups (`.ts-cloned`), and while the player types the copy is hidden so a result is listed once.
 * - While no country is saved, preselects a guess and says "We think you're from …" - nothing is stored until the
 *   player saves. The guess: the country of the time zone (the saved one, else the browser's - a device's zone says
 *   where somebody lives far better than the browser language, plenty of people browse in en-US), else the browser's
 *   language (assets/country_guess.js). No network call, nothing waits for it.
 */
export default class extends Controller {
    static targets = ['select', 'suggested', 'sentence'];

    static values = {
        zoneCountries: Object,
        savedZone: String,
        suggested: String,
    };

    connect() {
        const guessed = this.preselectGuess();

        this.tomSelect = new TomSelect(this.selectTarget, {
            allowEmptyOption: true,
            openOnFocus: false,
            maxOptions: null,
            searchField: ['text', 'alias'],
            render: {
                option: (data, escape) => this.render(data, escape),
                item: (data, escape) => this.render(data, escape),
                no_results: () => '',
            },
        });

        this.tomSelect.wrapper.classList.add('profile-country');

        this.tomSelect.on('type', (query) => {
            this.tomSelect.wrapper.classList.toggle('profile-country--searching', query !== '');
        });

        this.tomSelect.on('change', () => {
            this.suggestedTarget.hidden = true;
        });

        if (guessed !== null) {
            this.sentenceTarget.textContent = this.suggestedValue.replace('%country%', () => guessed);
            this.suggestedTarget.hidden = false;
        }
    }

    disconnect() {
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }

    /**
     * @returns {string|null} the guessed country's name, or null when nothing was preselected
     */
    preselectGuess() {
        // A value already there is the saved one (or the player's own pick restored by the browser)
        if (this.selectTarget.value !== '') {
            return null;
        }

        // Inside a closed <details> the player would save the country without ever seeing the note
        if (this.element.closest('details:not([open])') !== null) {
            return null;
        }

        const offered = new Set(Array.from(this.selectTarget.options, (option) => option.value));
        offered.delete('');

        const isOffered = (code) => offered.has(code);
        const zone = this.savedZoneValue !== '' ? this.savedZoneValue : browserTimeZone();
        const fromZone = zone !== null && Object.prototype.hasOwnProperty.call(this.zoneCountriesValue, zone)
            ? this.zoneCountriesValue[zone]
            : null;
        const guess = fromZone !== null && isOffered(fromZone) ? fromZone : guessCountry(isOffered);

        if (guess === null) {
            return null;
        }

        this.selectTarget.value = guess;
        const option = this.selectTarget.options[this.selectTarget.selectedIndex];

        return option ? option.textContent.trim() : null;
    }

    render(data, escape) {
        const flag = data.flag ? `<span class="fi fi-${escape(data.flag)} shadow-custom" aria-hidden="true"></span>` : '';

        return `<div class="country-option">${flag}<span>${escape(data.text)}</span></div>`;
    }
}
