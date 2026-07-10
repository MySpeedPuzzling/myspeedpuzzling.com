import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * Enhances a <select> bound to a LiveComponent model with Tom Select.
 * Tom Select syncs its value back to the original select and fires a native
 * change event, which the on(change) model modifier picks up.
 *
 * The <option value=""> placeholder MUST stay in the select: LiveComponent
 * writes model values back onto the field after re-renders, and a single
 * select without an empty option cannot represent "no selection" - the
 * browser falls back to the first option, silently activating a bogus
 * filter. Tom Select uses the empty option as placeholder text and does
 * not offer it in the dropdown; clearing happens via the clear button.
 *
 * When optionsUrl/optionsKey values are set, the option list is NOT expected
 * in the initial HTML: it is fetched once from that (cached) endpoint on
 * first focus. Only the currently selected option needs to be pre-rendered.
 *
 * Usage:
 * <div data-live-ignore>
 *     <select data-controller="tomselect-sync" data-model="on(change)|brandId"
 *             data-tomselect-sync-options-url-value="/puzzle-search-filter-options"
 *             data-tomselect-sync-options-key-value="manufacturers">
 *         <option value="">Placeholder</option>
 *     </select>
 * </div>
 */
export default class extends Controller {
    static values = {
        optionsUrl: String,
        optionsKey: String,
    };

    optionsLoaded = false;

    connect() {
        const remoteOptions = this.hasOptionsUrlValue && this.optionsUrlValue !== '';

        this.tomSelect = new TomSelect(this.element, {
            // No sortField override: options keep the order the server produced
            // (brands are intentionally ordered by popularity).
            plugins: {
                dropdown_input: {},
                clear_button: { title: '' },
            },
            ...(remoteOptions ? {
                preload: 'focus',
                shouldLoad: () => !this.optionsLoaded,
                load: (query, callback) => this.loadOptions(callback),
            } : {}),
        });

        this.tomSelect.on('change', () => {
            this.tomSelect.blur();
        });
    }

    async loadOptions(callback) {
        if (this.optionsLoaded) {
            callback();

            return;
        }

        try {
            const response = await fetch(this.optionsUrlValue, {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                callback();

                return;
            }

            const data = await response.json();
            this.optionsLoaded = true;
            callback(data[this.optionsKeyValue] ?? []);
        } catch (e) {
            callback();
        }
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
        }
    }
}
