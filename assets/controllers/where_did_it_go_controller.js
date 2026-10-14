/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { foldSearchText } from '../search_fold.js';
import { chooseTranslation } from '../translation_choice.js';
import { reportsZero, ZERO_IDLE_MS, zeroResultQuery } from '../navigation/nav_search.js';
import { SEARCH_ZERO_EVENT } from '../nav_instrumentation.js';

/** The page search's field this one reports as (NavClickController::SEARCH_SURFACES) */
export const WDIG_SURFACE = 'wdig';

/**
 * The filter box of "Where did it go?" (docs/features/navigation LAUNCH-02, templates/where_did_it_go/index.html.twig).
 * Everything is in the HTML: every row carries `data-search` - its old name and new place, folded with
 * SearchText::fold() - and the box folds what was typed the same way (foldSearchText()). A row shows while every typed
 * word is in it; a section without a row left hides. Without JavaScript the box stays hidden and every row shows.
 *
 * The status line under the box is a live region that stays in the page (LAUNCH review F5): empty while nothing is
 * typed, the number of matching rows while something is (where_did_it_go.matches, chosen like PHP -
 * browser_translation() + translation_choice.js), "Nothing matches" when nothing is left - written only when the text
 * changes, so a screen reader hears each change once.
 *
 * A filter that leaves nothing is reported like a page search without results (LAUNCH review Q3): once the typing
 * paused, a real query, once per query and a few per page view (nav_search.js's rule) - `msp:search-zero` {surface:
 * 'wdig', q} for the P0-03 beacon. Nothing is fetched, nothing is stored.
 */
export default class extends Controller {
    static targets = ['bar', 'input', 'section', 'row', 'status'];

    static values = {
        none: String,
        count: Object,
    };

    connect() {
        this.reported = new Set();
        this.zeroTimer = null;
        this.barTarget.hidden = false;
        this.filter();
    }

    disconnect() {
        clearTimeout(this.zeroTimer);
        // Back to the server's markup, so a Turbo snapshot starts with every row
        this.apply([]);
        this.barTarget.hidden = true;
    }

    filter() {
        const query = this.inputTarget.value;
        const shown = this.apply(foldSearchText(query).split(' ').filter((word) => word !== ''));

        clearTimeout(this.zeroTimer);

        if (shown === 0) {
            this.zeroTimer = setTimeout(() => this.reportZero(query), ZERO_IDLE_MS);
        }
    }

    // Enter submits nothing (there is no form) - keep the focus in the box
    keepEnter(event) {
        event.preventDefault();
    }

    /** Shows the rows holding every word; returns how many show */
    apply(words) {
        let shown = 0;

        this.rowTargets.forEach((row) => {
            const text = row.dataset.search ?? '';
            row.hidden = !words.every((word) => text.includes(word));
            shown += row.hidden ? 0 : 1;
        });

        this.sectionTargets.forEach((section) => {
            section.hidden = section.querySelector('[data-where-did-it-go-target="row"]:not([hidden])') === null;
        });

        this.setStatus(words.length === 0 ? '' : this.statusText(shown));

        return shown;
    }

    statusText(shown) {
        if (shown === 0) {
            return this.noneValue;
        }

        const count = this.countValue;

        return chooseTranslation(count.message, shown, count.locale) ?? String(shown);
    }

    setStatus(text) {
        if (this.hasStatusTarget && this.statusTarget.textContent !== text) {
            this.statusTarget.textContent = text;
        }
    }

    reportZero(query) {
        if (this.inputTarget.value !== query || !reportsZero(query, 0, true, this.reported)) {
            return;
        }

        const q = zeroResultQuery(query);
        this.reported.add(q);
        document.dispatchEvent(new CustomEvent(SEARCH_ZERO_EVENT, { detail: { surface: WDIG_SURFACE, q } }));
    }
}
