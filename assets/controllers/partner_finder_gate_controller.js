import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';
import TomSelect from 'tom-select';

/**
 * The event picker of the partner finder's gate page (templates/partner_finder/gate.html.twig,
 * docs/features/partner-finder.md "Part D as built"). Each <option> carries its row in `data-data` (name, series, dates,
 * place, flag, "N looking", the finder's URL); TomSelect searches the name, the series - typing a series name finds its
 * editions - and the place. Picking an event opens its finder. Without JavaScript the form's GET `?event=<id>` does the
 * same through a redirect, so the submit button is only hidden once this controller runs.
 */
export default class extends Controller {
    static targets = ['select'];

    connect() {
        this.element.classList.add('pfg-picker-enhanced');

        this.tomSelect = new TomSelect(this.selectTarget, {
            dataAttr: 'data-data',
            maxOptions: null,
            allowEmptyOption: false,
            searchField: ['name', 'series', 'place'],
            placeholder: this.selectTarget.dataset.placeholder || '',
            render: {
                option: (data, escape) => this.renderOption(data, escape),
                item: (data, escape) => `<div>${escape(data.name || data.text)}</div>`,
                no_results: (data, escape) => `<div class="no-results">${escape(this.element.dataset.noResults || '')}</div>`,
            },
            onChange: (value) => this.go(value),
        });
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
            this.tomSelect = null;
        }

        this.element.classList.remove('pfg-picker-enhanced');
    }

    renderOption(data, escape) {
        const flag = data.flag ? `<span class="${escape(data.flag)} pfg-flag" aria-hidden="true"></span>` : '';
        const place = data.place ? `<span class="pfg-option-place">${flag}${escape(data.place)}</span>` : '';
        const looking = data.looking ? `<span class="pfg-option-looking">${escape(data.looking)}</span>` : '';

        return `<div class="pfg-option">
            <span class="pfg-option-name">${escape(data.name || data.text)}</span>
            <span class="pfg-option-meta"><span class="pfg-option-dates">${escape(data.dates || '')}</span>${place}${looking}</span>
        </div>`;
    }

    go(value) {
        if (!value) {
            return;
        }

        const option = this.tomSelect.options[value];

        this.tomSelect.blur();

        if (option && option.url) {
            visit(option.url);

            return;
        }

        this.element.requestSubmit();
    }
}
