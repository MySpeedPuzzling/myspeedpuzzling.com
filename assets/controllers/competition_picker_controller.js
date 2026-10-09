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
 * closes, when the value changes and when the next answer arrives - except the chosen one: the default list never
 * grows. They are shown in the server's order (an edition named by every typed word first: "No. 1" is Jam No. 1, not
 * Jam No. 15), after the one-time events and series TomSelect matches itself.
 */

// Below any score TomSelect gives a matching one-time event or series - and above 0, which would hide the edition
const FETCHED_EDITION_SCORE = 0.0001;

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
        // The callback adds the options and their series' optgroups. Each fetched option remembers the words it was
        // fetched for: the server already chose and ranked it for them (whole-token matches first, then nearest to
        // today) - TomSelect's own scoring would drop "#160" or re-sort the answer
        options.load = (query, callback) => {
            const typed = query.trim();

            fetch(url + (url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(typed), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : { options: [], optgroups: [] }))
                .then((json) => {
                    const tomSelect = event.target.tomselect;

                    // An answer to words no longer typed (a slower, older request) is dropped
                    if (tomSelect && tomSelect.inputValue().trim() !== typed) {
                        callback([], []);

                        return;
                    }

                    const fetched = (json.options || []).map((option) => ({ ...option, fetchedFor: typed }));

                    if (tomSelect) {
                        // The previous answer's editions go, so this one's are added - and ordered - as answered
                        this._forgetFetched(tomSelect);
                        // The chosen edition stays and is answered again - it belongs to this answer too (an edition
                        // the page offered keeps TomSelect's own scoring)
                        fetched.forEach((option) => {
                            const kept = tomSelect.options[option.value];

                            if (kept && kept.fetchedFor !== undefined) {
                                kept.fetchedFor = typed;
                            }
                        });
                    }

                    callback(fetched, json.optgroups || []);
                })
                .catch(() => callback([], []));
        };

        // Fetched editions keep the server's choice and order: one equal score for the current answer (TomSelect then
        // sorts by the order options were added), nothing for an older one. One-time events and series are scored by
        // TomSelect as always and come first - the score is below any of theirs
        const scoreOffered = options.score;
        options.score = function (search) {
            const score = scoreOffered ? scoreOffered.call(this, search) : this.getScoreFunction(search);
            const typed = String(search).trim();

            return (item) => {
                if (item.fetchedFor === undefined) {
                    return score(item);
                }

                return item.fetchedFor === typed ? FETCHED_EDITION_SCORE : 0;
            };
        };
    }

    _onConnect(event) {
        const tomSelect = event.detail.tomSelect;

        if (!tomSelect || this.editionsUrlValue === '') {
            return;
        }

        // What the page offered: one-time events, series and an included edition (the current one) stay for good
        this._offered = new Set(Object.keys(tomSelect.options));
        const forgetFetched = () => this._forgetFetched(tomSelect);

        tomSelect.on('dropdown_close', forgetFetched);
        tomSelect.on('change', forgetFetched);
        tomSelect.on('type', (query) => {
            if (query.trim().length < 2 && forgetFetched() && tomSelect.isOpen) {
                tomSelect.refreshOptions(false);
            }
        });
    }

    /**
     * Removes every fetched edition except the chosen one; true when something went
     */
    _forgetFetched(tomSelect) {
        const offered = this._offered;

        if (!offered) {
            return false;
        }

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
    }
}
