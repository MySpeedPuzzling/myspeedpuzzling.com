import { Controller } from '@hotwired/stimulus';
import { guessCountry } from '../country_guess.js';

/**
 * "Add your country" (docs/features/players-page/README.md, templates/components/Players/CountryNudge.html.twig).
 *
 * Preselects the country the browser suggests (assets/country_guess.js) - the player still has to press Save - and
 * names whichever country is picked in the sentence: "…to appear among Czechia's puzzlers…". Both sentences come
 * from the server as values, so they stay translatable; this only ever writes text, never markup.
 */
export default class extends Controller {
    static targets = ['select', 'sentence'];

    static values = {
        named: String,
        unnamed: String,
    };

    connect() {
        // A value already there (restored by the browser) is the player's own choice
        if (this.selectTarget.value === '') {
            const offered = new Set(Array.from(this.selectTarget.options, (option) => option.value));
            offered.delete('');

            const guess = guessCountry((code) => offered.has(code));

            if (guess !== null) {
                this.selectTarget.value = guess;
            }
        }

        this.update();
    }

    update() {
        const option = this.selectTarget.options[this.selectTarget.selectedIndex];

        if (option === undefined || option.value === '') {
            this.sentenceTarget.textContent = this.unnamedValue;

            return;
        }

        this.sentenceTarget.textContent = this.namedValue.replace('%country%', () => option.textContent.trim());
    }
}
