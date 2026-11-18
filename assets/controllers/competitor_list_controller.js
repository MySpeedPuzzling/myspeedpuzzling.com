/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { filterCompetitorList } from '../competitor_list_filter.js';

/**
 * The search of the public competitor list (templates/competitor_page/list.html.twig,
 * docs/features/competitions-management/competitor-pages.md "Competitor list"): every row the server rendered carries
 * `data-search`; typing hides the rows without every typed word (`hidden`), a section without a match and updates the
 * section counts; "Nobody matches" when nothing is left. Without JavaScript the field stays hidden and every row shows.
 * Nothing is fetched, nothing is stored.
 */
export default class extends Controller {
    static targets = ['bar', 'search', 'section', 'none'];

    connect() {
        if (this.hasBarTarget) {
            this.barTarget.hidden = false;
        }

        this.apply('');
    }

    disconnect() {
        // Back to the server's markup, so a Turbo snapshot or a reconnect starts from the same state
        this.apply('');

        if (this.hasBarTarget) {
            this.barTarget.hidden = true;
        }
    }

    search() {
        this.apply(this.searchTarget.value);
    }

    // Enter in the search field submits nothing (there is no form) - and closes the phone's keyboard
    keepEnter(event) {
        event.preventDefault();
        this.searchTarget.blur();
    }

    apply(query) {
        const rows = this.sectionTargets.map((section) => [...section.querySelectorAll('[data-search]')]);
        const result = filterCompetitorList(rows.map((items) => items.map((item) => item.dataset.search ?? '')), query);

        rows.forEach((items, index) => {
            items.forEach((item, row) => { item.hidden = !result.shown[index][row]; });

            const section = this.sectionTargets[index];
            section.hidden = result.counts[index] === 0;
            const count = section.querySelector('[data-competitor-list-count]');

            if (count) {
                count.textContent = String(result.counts[index]);
            }
        });

        if (this.hasNoneTarget) {
            this.noneTarget.hidden = result.total > 0;
        }
    }
}
