import { Controller } from '@hotwired/stimulus';

/**
 * Appends further puzzle-search result pages from the puzzle_search_items
 * endpoint without re-rendering the already visible items.
 *
 * The grid and the footer carry data-skip-morph, so any live re-render
 * (filter/sort change) wholesale-replaces their content with fresh page-one
 * server HTML - appended items and client-side state are correctly discarded.
 * To keep that self-healing, this controller must only ever mutate INSIDE
 * those containers (LiveComponents re-applies external attribute changes on
 * elements it morphs): the next-page URL therefore lives on the button, and
 * reaching the end of the list empties the footer instead of hiding it.
 */
export default class extends Controller {
    static targets = ['grid', 'footer', 'button', 'spinner', 'label', 'remaining'];

    loading = false;

    async load() {
        const url = this.buttonTarget.dataset.nextUrl;

        if (this.loading || !url) {
            return;
        }

        this.loading = true;
        this.buttonTarget.disabled = true;
        this.spinnerTarget.classList.remove('d-none');
        this.labelTarget.classList.add('d-none');

        try {
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            this.gridTarget.insertAdjacentHTML('beforeend', data.html);

            if (data.hasMore) {
                this.buttonTarget.dataset.nextUrl = data.nextUrl;
                this.remainingTarget.textContent = data.remainingLabel;
            } else {
                this.footerTarget.replaceChildren();
            }
        } finally {
            this.loading = false;

            if (this.hasButtonTarget) {
                this.buttonTarget.disabled = false;
                this.spinnerTarget.classList.add('d-none');
                this.labelTarget.classList.remove('d-none');
            }
        }
    }
}
