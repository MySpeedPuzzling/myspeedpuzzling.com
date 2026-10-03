import { Controller } from '@hotwired/stimulus';

/**
 * Turns a country <select> (templates/players/_country_select.html.twig) into a typeahead: a flag before every name, the
 * number of puzzlers after it, in the order the server rendered - World first, then the countries with the most
 * puzzlers. Typing filters, best match first. TomSelect is loaded on first use, and the original <select> keeps firing
 * its change event, so whatever listens to it (the scope switch, the directory form) keeps working. Without JavaScript
 * it stays a plain select.
 */
export default class extends Controller {
    static values = {
        noResults: String,
    };

    connect() {
        import('tom-select').then(({ default: TomSelect }) => {
            if (!this.element.isConnected || this.tomSelect) {
                return;
            }

            const icon = (data) => data.$option ? data.$option.getAttribute('data-icon') : null;
            const count = (data) => data.$option ? data.$option.getAttribute('data-count') : null;

            this.tomSelect = new TomSelect(this.element, {
                maxOptions: null,
                // World submits '' in the directory
                allowEmptyOption: true,
                searchField: ['text'],
                sortField: [{ field: '$score' }, { field: '$order' }],
                closeAfterSelect: true,
                render: {
                    option: (data, escape) => {
                        const flag = icon(data);
                        const number = count(data);

                        return `<div class="country-option">${flag ? `<i class="${escape(flag)} shadow-custom" aria-hidden="true"></i>` : ''}<span class="country-option-name">${escape(data.text)}</span>${number ? `<span class="country-option-count">${escape(number)}</span>` : ''}</div>`;
                    },
                    item: (data, escape) => {
                        const flag = icon(data);

                        return `<div class="country-option">${flag ? `<i class="${escape(flag)} shadow-custom" aria-hidden="true"></i>` : ''}<span class="country-option-name">${escape(data.text)}</span></div>`;
                    },
                    no_results: (data, escape) => `<div class="no-results">${escape(this.noResultsValue)}</div>`,
                },
                onChange: () => this.tomSelect.blur(),
            });
        });
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
            this.tomSelect = null;
        }
    }
}
