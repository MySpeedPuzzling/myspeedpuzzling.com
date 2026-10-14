/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { foldSearchText } from '../search_fold.js';

/**
 * The filter box of "Where did it go?" (docs/features/navigation LAUNCH-02, templates/where_did_it_go/index.html.twig).
 * Everything is in the HTML: every row carries `data-search` - its old name and new place, folded with
 * SearchText::fold() - and the box folds what was typed the same way (foldSearchText()). A row shows while every typed
 * word is in it; a section without a row left hides, and "Nothing matches" appears when nothing is left. Without
 * JavaScript the box stays hidden and every row shows. Nothing is fetched, nothing is stored.
 */
export default class extends Controller {
    static targets = ['bar', 'input', 'section', 'row', 'none'];

    connect() {
        this.barTarget.hidden = false;
        this.filter();
    }

    disconnect() {
        // Back to the server's markup, so a Turbo snapshot starts with every row
        this.apply([]);
        this.barTarget.hidden = true;
    }

    filter() {
        this.apply(foldSearchText(this.inputTarget.value).split(' ').filter((word) => word !== ''));
    }

    // Enter submits nothing (there is no form) - keep the focus in the box
    keepEnter(event) {
        event.preventDefault();
    }

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

        this.noneTarget.hidden = shown > 0;
    }
}
