/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { filterCompetitorList } from '../competitor_list_filter.js';

/**
 * The search of the public competitor list (templates/competitor_page/list.html.twig,
 * docs/features/competitions-management/competitor-pages.md "Competitor list"): every row the server rendered carries
 * `data-search`; typing hides the rows without every typed word (`hidden`), a section without a match and updates the
 * section counts; "Nobody matches" when nothing is left. Without JavaScript the field stays hidden and every row shows.
 * Nothing is fetched, nothing is stored.
 *
 * Tables (competitor-pages.md "Competitor list - search by table"): a row's tables are its `[data-competitor-list-table]`
 * hints (the key in the attribute, "Table A1 L · <round>" rendered by the server, hidden). A row matched by a table
 * shows the hints of the tables that matched (instead of its plain `[data-competitor-list-tables]` line) and the rows
 * are reordered - best table match first; an empty search puts back the server's order (`data-competitor-list-index`,
 * set on connect, kept on the row).
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
        const rows = this.sectionTargets.map((section) => {
            const items = [...section.querySelectorAll('[data-search]')];

            // The server's order, kept on the row across reorders
            items.forEach((item, index) => {
                if (item.dataset.competitorListIndex === undefined) {
                    item.dataset.competitorListIndex = String(index);
                }
            });

            return items.sort((a, b) => Number(a.dataset.competitorListIndex) - Number(b.dataset.competitorListIndex));
        });
        const result = filterCompetitorList(rows.map((items) => items.map((item) => ({
            search: item.dataset.search ?? '',
            tables: [...item.querySelectorAll('[data-competitor-list-table]')].map((hint) => hint.dataset.competitorListTable ?? ''),
        }))), query);

        rows.forEach((items, index) => {
            items.forEach((item, row) => {
                item.hidden = !result.shown[index][row];

                const matched = result.tables[index][row];
                item.querySelectorAll('[data-competitor-list-table]').forEach((hint) => {
                    hint.hidden = !matched.includes(hint.dataset.competitorListTable ?? '');
                });
                const plain = item.querySelector('[data-competitor-list-tables]');
                if (plain) {
                    plain.hidden = matched.length > 0;
                }
            });

            // Moved only when the order changes (a table match, or back after one) - else the markup stays as it is
            const list = items[0]?.parentElement;
            const ordered = result.order[index].map((row) => items[row]);
            if (list && ordered.some((item, position) => list.children[position] !== item)) {
                ordered.forEach((item) => list.appendChild(item));
            }

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
