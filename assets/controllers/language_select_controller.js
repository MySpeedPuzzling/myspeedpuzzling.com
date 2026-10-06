/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * The language of a puzzle name (PuzzleNameLanguageType): a searchable select with flags. Each <option> carries
 * `data-flag` (flag-icons code) and `data-alias` (English name + tag, so "German" or "de" finds "Němčina" on a Czech
 * page) - PuzzleNameLanguageChoices::optionAttributes(). The empty option ("Not known") stays selectable.
 *
 * Code setting the <select>'s value or options itself calls `select.tomselect?.sync()` afterwards (names_editor_controller.js).
 */
export default class extends Controller {
    connect() {
        this.tomSelect = new TomSelect(this.element, {
            allowEmptyOption: true,
            maxOptions: null,
            searchField: ['text', 'alias'],
            render: {
                option: (data, escape) => this.render(data, escape),
                item: (data, escape) => this.render(data, escape),
                no_results: () => '',
            },
        });

        this.tomSelect.wrapper.classList.add('language-select');

        // Rows of the names editor are labelled by aria-label, not by a <label>
        const label = this.element.getAttribute('aria-label');
        if (label) {
            this.tomSelect.control_input.setAttribute('aria-label', label);
        }
    }

    disconnect() {
        this.tomSelect?.destroy();
    }

    render(data, escape) {
        const flag = data.flag ? `<span class="fi fi-${escape(data.flag)} shadow-custom" aria-hidden="true"></span>` : '';

        return `<div class="language-option">${flag}<span>${escape(data.text)}</span></div>`;
    }
}
