import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Submits its form when a filter inside it changes (a country select, a "favorites only" switch) - instead of inline
 * onchange handlers. Put it on the <form>: change events bubble up to it. The form keeps a submit button for
 * visitors without JavaScript (hidden by the template once this controller connects).
 */
export default class extends Controller {
    static targets = ['fallback'];

    connect() {
        this.fallbackTargets.forEach((element) => element.classList.add('d-none'));
    }

    submit() {
        this.element.requestSubmit();
    }
}
