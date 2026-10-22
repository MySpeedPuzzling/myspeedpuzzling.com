import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';
import TomSelect from 'tom-select';

/**
 * The event picker of the partner finder's gate page (templates/partner_finder/gate.html.twig,
 * docs/features/partner-finder.md "Part D as built"). Each <option> carries its row in `data-data` (name, series, dates,
 * place, flag, logo, "N looking", the finder's URL), nearest event first; TomSelect searches the name, the series - typing a series name finds its
 * editions - and the place. Picking an event opens its finder. Without JavaScript the form's GET `?event=<id>` does the
 * same through a redirect, so the submit button is only hidden once this controller runs.
 */
export default class extends Controller {
    static targets = ['select'];

    connect() {
        this.element.classList.add('pfg-picker-enhanced');

        this.tomSelect = new TomSelect(this.selectTarget, {
            // TomSelect reads it from the option's dataset: 'data' = the `data-data` attribute (not 'data-data')
            dataAttr: 'data',
            maxOptions: null,
            allowEmptyOption: false,
            searchField: ['name', 'series', 'place'],
            placeholder: this.selectTarget.dataset.placeholder || '',
            render: {
                option: (data, escape) => this.renderOption(data, escape),
                item: (data, escape) => this.renderItem(data, escape),
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
        const dates = data.dates ? `<span class="pfg-option-dates">${escape(data.dates)}</span>` : '';

        return `<div class="pfg-option">
            ${this.renderLogo(data, escape)}
            <span class="pfg-option-text">
                <span class="pfg-option-name">${escape(data.name || data.text)}</span>
                <span class="pfg-option-meta">${dates}${place}${looking}</span>
            </span>
        </div>`;
    }

    renderItem(data, escape) {
        const dates = data.dates ? `<span class="pfg-item-dates">${escape(data.dates)}</span>` : '';

        return `<div class="pfg-item">${this.renderLogo(data, escape)}<span class="pfg-item-name">${escape(data.name || data.text)}</span>${dates}</div>`;
    }

    renderLogo(data, escape) {
        return data.logo
            ? `<img class="pfg-logo" src="${escape(data.logo)}" alt="" loading="lazy">`
            : '<span class="pfg-logo pfg-logo-none" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>';
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
