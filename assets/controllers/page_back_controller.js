import { Controller } from '@hotwired/stimulus';

/**
 * The round back button of templates/_page_crumbs.html.twig when it goes to `?return=`
 * (docs/features/xp-levels/ux-polish/design-spec.md §8.7): when that URL is the page we just came from, a tap goes
 * back in history instead of opening it again - the previous page comes back like with the browser's Back (app.js
 * turns Turbo's restoration visit into a real page load) and Back never ping-pongs between the two pages.
 * Anything else, or no JS: the plain link.
 *
 * "The page we came from": document.referrer only describes the last full page load - Turbo Drive swaps the body and
 * never changes it - so every Turbo visit records the URL it leaves.
 */
let previousUrl = document.referrer;

document.addEventListener('turbo:before-visit', () => {
    previousUrl = window.location.href;
});

const pathAndQuery = (url) => url.pathname + url.search;

export default class extends Controller {
    back(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        if (window.history.length < 2 || !previousUrl) {
            return;
        }

        let previous;
        let target;

        try {
            previous = new URL(previousUrl);
            target = new URL(this.element.getAttribute('href'), window.location.origin);
        } catch {
            return;
        }

        if (previous.origin !== window.location.origin || pathAndQuery(previous) !== pathAndQuery(target)) {
            return;
        }

        event.preventDefault();
        window.history.back();
    }
}
