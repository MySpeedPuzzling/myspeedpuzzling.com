import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/**
 * A <select> whose option values are URLs: picking an option opens its page (the ladders' country typeahead,
 * templates/_ladder_dropdown_countries.html.twig).
 */
export default class extends Controller {
    go(event) {
        const url = event.target.value;

        if (url === '' || url === window.location.pathname + window.location.search) {
            return;
        }

        visit(url);
    }
}
