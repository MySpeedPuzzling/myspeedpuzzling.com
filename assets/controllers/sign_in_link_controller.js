import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the sign-in link form in sync with the address typed into the login form,
 * so "Email me a sign-in link" stays a single click (UX funnel §3/§4) without
 * posting the typed password to a second endpoint.
 *
 * Without JavaScript the hidden field still carries the last attempted address
 * rendered server-side; an empty one simply lands on the sign-in link page.
 */
export default class extends Controller {
    static targets = ['email'];

    mirrorEmail(event) {
        this.emailTargets.forEach((target) => {
            target.value = event.target.value;
        });
    }
}
