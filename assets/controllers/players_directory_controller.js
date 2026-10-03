import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/**
 * The directory's filter bar (docs/features/players-page/README.md, "Browse all"). It is a plain GET form; this applies
 * every change at once - a sort, a scope, a filter chip - and lands back on the directory (#players-directory), so on a
 * country page the spotlight above does not push the cards out of view. Empty values stay out of the URL, and the page
 * starts again from its first 24 cards. Without JavaScript the <noscript> button submits the form.
 */
export default class extends Controller {
    apply() {
        const form = this.element;
        const url = new URL(form.getAttribute('action'), window.location.origin);
        const parameters = new URLSearchParams();

        for (const [name, value] of new FormData(form)) {
            if (typeof value === 'string' && value !== '') {
                parameters.append(name, value);
            }
        }

        const query = parameters.toString();

        visit(`${url.pathname}${query === '' ? '' : `?${query}`}#players-directory`);
    }
}
