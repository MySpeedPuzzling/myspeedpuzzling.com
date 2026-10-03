import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';
import { guessCountry } from '../country_guess.js';

/**
 * The Players page's scope switch (docs/features/players-page/README.md).
 *
 * - "Another country…" opens the page on the picked country as soon as it is picked (the <noscript> button covers
 *   browsers without JavaScript).
 * - Guests get a one-tap chip for the country their browser suggests (assets/country_guess.js). The guess happens here
 *   and not on the server because guest HTML is shared-cached. A guess is only offered, never applied, and only for
 *   countries that have puzzlers.
 */
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
        return guessCountry((code) => Object.prototype.hasOwnProperty.call(this.countriesValue, code));
    }

    urlFor(code) {
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('scope', code);

        return url.pathname + url.search;
    }
}
