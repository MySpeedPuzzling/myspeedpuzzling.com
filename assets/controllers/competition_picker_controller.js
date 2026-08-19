import { Controller } from '@hotwired/stimulus';

/**
 * Tunes the TomSelect instance of the "Competition / event" picker (add-time + edit-time forms).
 *
 * Placed on the wrapper of the autocomplete input: the Symfony UX Autocomplete controller dispatches
 * a bubbling `autocomplete:pre-connect` event with the TomSelect config right before instantiation,
 * so the config can be patched here. These settings cannot come from PHP (`tom_select_options`)
 * because ux-autocomplete merges its own `maxOptions`/`render` on top of them for `<input>`-based
 * pickers.
 */
export default class extends Controller {
    initialize() {
        this._onPreConnect = this._onPreConnect.bind(this);
    }

    connect() {
        this.element.addEventListener('autocomplete:pre-connect', this._onPreConnect);
    }

    disconnect() {
        this.element.removeEventListener('autocomplete:pre-connect', this._onPreConnect);
    }

    _onPreConnect(event) {
        const options = event.detail.options;

        // ux-autocomplete forces 50 for <input>-based pickers; the whole set must be browsable
        options.maxOptions = null;

        options.render = options.render || {};
        options.render.optgroup_header = (data, escape) =>
            '<div class="optgroup-header d-flex align-items-center fw-semibold">'
            + (data.logo
                ? '<img alt="" class="rounded-1 me-2 competition-optgroup-logo" src="' + escape(data.logo) + '" loading="lazy" width="24" height="24">'
                : '')
            + escape(data.label)
            + '</div>';

        // Blur on select so the dropdown closes and the keyboard goes away on mobile
        options.onChange = () => {
            const tomSelect = event.target.tomselect;

            if (tomSelect) {
                tomSelect.blur();
            }
        };
    }
}
