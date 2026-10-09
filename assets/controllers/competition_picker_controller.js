import { Controller } from '@hotwired/stimulus';

/**
 * Tunes the TomSelect instance of the "Competition / event" picker (add-time, edit-time, stopwatch finish).
 *
 * Placed on the wrapper of the autocomplete input: the Symfony UX Autocomplete controller dispatches
 * a bubbling `autocomplete:pre-connect` event with the TomSelect config right before instantiation,
 * so the config can be patched here. These settings cannot come from PHP (`tom_select_options`)
 * because ux-autocomplete merges its own `maxOptions`/`render`/`shouldLoad` on top of them for
 * `<input>`-based pickers.
 *
 * S1 (docs/features/events-page/high-frequency-series.md "S1 - typing finds editions"): the page offers one-time
 * events and series only - from two typed characters the editions whose name matches are fetched from
 * `editionsUrl` and shown under their series. ux-autocomplete sets `shouldLoad = () => false` for local pickers,
 * so it is overridden here. Fetched editions leave the list again when the search is cleared, when the dropdown
 * closes and when the value changes - except the chosen one: the default list never grows.
 */
export default class extends Controller {
    static values = {
        editionsUrl: { type: String, default: '' },
    };

    initialize() {
        this._onPreConnect = this._onPreConnect.bind(this);
        this._onConnect = this._onConnect.bind(this);
    }

    connect() {
        this.element.addEventListener('autocomplete:pre-connect', this._onPreConnect);
        this.element.addEventListener('autocomplete:connect', this._onConnect);
    }

    disconnect() {
        this.element.removeEventListener('autocomplete:pre-connect', this._onPreConnect);
        this.element.removeEventListener('autocomplete:connect', this._onConnect);
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

        if (this.editionsUrlValue === '') {
            return;
        }

        const url = this.editionsUrlValue;

        options.shouldLoad = (query) => query.trim().length >= 2;
        options.loadThrottle = 300;
        // `this` of TomSelect is not needed: the callback adds the options and their series' optgroups
        options.load = (query, callback) => {
            fetch(url + (url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query.trim()), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : { options: [], optgroups: [] }))
                .then((json) => callback(json.options || [], json.optgroups || []))
                .catch(() => callback([], []));
        };
    }

    _onConnect(event) {
        const tomSelect = event.detail.tomSelect;

        if (!tomSelect || this.editionsUrlValue === '') {
            return;
        }

        // What the page offered: one-time events, series and an included edition (the current one) stay for good
        const offered = new Set(Object.keys(tomSelect.options));
        const forgetFetched = () => {
            const selected = new Set(tomSelect.items);
            let removed = false;

            Object.keys(tomSelect.options).forEach((value) => {
                if (!offered.has(value) && !selected.has(value)) {
                    tomSelect.removeOption(value, true);
                    removed = true;
                }
            });

            // Typing the same words again must fetch again
            tomSelect.loadedSearches = {};

            return removed;
        };

        tomSelect.on('dropdown_close', forgetFetched);
        tomSelect.on('change', forgetFetched);
        tomSelect.on('type', (query) => {
            if (query.trim().length < 2 && forgetFetched() && tomSelect.isOpen) {
                tomSelect.refreshOptions(false);
            }
        });
    }
}
