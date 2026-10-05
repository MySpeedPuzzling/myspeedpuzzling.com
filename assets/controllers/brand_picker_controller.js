import { Controller } from '@hotwired/stimulus';

// Options built by the server (BrandChoicesBuilder) carry a plain `name` and an HTML `text` escaped where it was built.
// Any other option is text a player typed - a new brand, also when a refused form comes back with it - and is escaped.
const isFromServer = (item) => typeof item.name === 'string';
const renderOption = (item, escape) => `<div>${isFromServer(item) ? item.text : escape(item.text ?? item.value ?? '')}</div>`;

// Compared like the server does (ManufacturerResolver): spacing and case only - "Cech" is not "Čech"
const normalize = (name) => name.trim().replace(/\s+/g, ' ').toLowerCase();

/**
 * The brand picker of "Suggest a change": any brand, or a typed name that is in no brand - how a misspelled brand gets
 * its right name. A typed name equal to a brand's name selects that brand. Unlike the add form, never the only brand
 * starting with the text: the misspelled "Ravensburgerr" would swallow the "Ravensburger" typed to correct it.
 */
export default class extends Controller {
    static values = {
        addNewBrandMessage: String,
    };

    initialize() {
        this._onConnect = this._onConnect.bind(this);
    }

    connect() {
        this.element.addEventListener('autocomplete:pre-connect', this._onConnect);
    }

    disconnect() {
        this.element.removeEventListener('autocomplete:pre-connect', this._onConnect);
    }

    _onConnect(event) {
        const input = event.target;
        const options = event.detail.options;
        const addNewBrandMessage = this.addNewBrandMessageValue || 'Add new brand:';

        options.render.option = renderOption;
        options.render.item = renderOption;
        options.render.option_create = (data, escape) => `<div class="create py-2"><i class="ci-add small"></i> ${escape(addNewBrandMessage)} <strong>${escape(data.input)}</strong></div>`;

        options.create = (typed) => {
            const tom = input.tomselect;
            const wanted = normalize(typed);

            for (const [value, optionData] of Object.entries(tom.options)) {
                if (isFromServer(optionData) && normalize(optionData.name) === wanted) {
                    tom.addItem(value);

                    return false;
                }
            }

            return { value: typed.trim(), text: typed.trim() };
        };
    }
}
