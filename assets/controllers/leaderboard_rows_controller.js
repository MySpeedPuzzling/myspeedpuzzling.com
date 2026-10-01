import { Controller } from '@hotwired/stimulus';

/*
 * Puzzle leaderboard: a row opens its details (date, PPM, the player's other times) - a tap anywhere on the row
 * or on the chevron under the rank. Links and buttons inside the row keep doing their own thing.
 * The details are the `tr[data-details-of="<row id>"]` rows right below it.
 */
export default class extends Controller {
    toggle(event) {
        if (event.target.closest('a, button:not([data-leaderboard-rows-toggle]), input, label, select')) {
            return;
        }

        // Selecting text is not a tap
        if (window.getSelection && window.getSelection().toString() !== '') {
            return;
        }

        const row = event.currentTarget;
        const button = row.querySelector('[data-leaderboard-rows-toggle]');
        const open = button.getAttribute('aria-expanded') !== 'true';

        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        row.classList.toggle('lb-open', open);

        this.element.querySelectorAll(`tr[data-details-of="${row.id}"]`).forEach((details) => {
            details.hidden = !open;
        });
    }
}
