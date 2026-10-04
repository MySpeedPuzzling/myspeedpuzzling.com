/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { chooseTranslation } from '../translation_choice.js';
import { foldSearchText } from '../search_fold.js';

/**
 * "What will you bring?" (templates/marketplace/event_offers_picker.html.twig): filters the seller's offers in the
 * browser - no request per keystroke - and keeps the "N selected" counter. "Select all" and "Clear" act on the rows the
 * search shows, so nothing hidden changes behind the seller's back. A row's `data-search` holds every name of the
 * puzzle, its brand and piece count folded by the server; the typed words are folded the same way (search_fold.js).
 * The counter's text comes from Twig (browser_translation()), plural form picked like PHP does.
 */
export default class extends Controller {
    static targets = ['search', 'row', 'checkbox', 'count', 'noMatch'];

    static values = {
        selectedText: Object,
    };

    connect() {
        this.update();
    }

    filter() {
        const words = foldSearchText(this.searchTarget.value).split(' ').filter((word) => word !== '');
        let shown = 0;

        this.rowTargets.forEach((row) => {
            const text = row.dataset.search ?? '';
            const matches = words.every((word) => text.includes(word));

            row.hidden = !matches;

            if (matches) {
                shown += 1;
            }
        });

        if (this.hasNoMatchTarget) {
            this.noMatchTarget.hidden = shown > 0;
        }
    }

    ignoreEnter(event) {
        event.preventDefault();
    }

    selectAll() {
        this.setVisible(true);
    }

    clear() {
        this.setVisible(false);
    }

    setVisible(checked) {
        this.checkboxTargets.forEach((checkbox) => {
            const row = checkbox.closest('[data-event-offers-picker-target~="row"]');

            if (row === null || row.hidden === false) {
                checkbox.checked = checked;
            }
        });

        this.update();
    }

    update() {
        if (!this.hasCountTarget) {
            return;
        }

        const count = this.checkboxTargets.filter((checkbox) => checkbox.checked).length;
        const text = this.selectedTextValue;
        const label = text && typeof text.message === 'string' ? chooseTranslation(text.message, count, text.locale) : null;

        if (label !== null) {
            this.countTarget.textContent = label;
        }
    }
}
