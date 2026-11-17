import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * "Find the puzzle" above a brand + puzzle picker (time_form_autocomplete) - the creation wizard's puzzle pickers
 * (docs/features/competitions-management/creation-wizard.md "Puzzle search"). One field across every brand: a name, a
 * barcode (typed or from a hardware scanner - its Enter never sends the form) or a brand code
 * (PuzzleSearchAutocompleteController, the site's own search). Picking a result hands it to the picker
 * (`puzzle-search-picker:picked`, time_form_autocomplete#pickFound), which selects its brand and puzzle - the form
 * sends exactly what the brand path sends.
 *
 * The brand path (`brandPath`) stays for whoever starts from the brand or adds a new puzzle: hidden while the picker is
 * empty, shown by "Choose the brand…", by a pick (it shows what was picked) and when the search finds nothing. The
 * search field itself has no name - it never reaches the server.
 */
export default class extends Controller {
    static targets = ['input', 'brandPath', 'toggle'];

    static values = {
        url: String,
        noResultsLabel: String,
    };

    connect() {
        this.pendingEnter = false;
        this.onKeyDown = this.onKeyDown.bind(this);

        // The brand path shows whatever it holds - a refused form, a browser restoring the page
        if (this.hasBrandPathTarget && !this.brandPathHasValue()) {
            this.hideBrandPath();
        }

        this.tomSelect = new TomSelect(this.inputTarget, {
            maxItems: 1,
            create: false,
            valueField: 'value',
            labelField: 'name',
            searchField: [],
            // The server's order (best match first) - never re-scored here
            score: () => () => 1,
            loadThrottle: 250,
            shouldLoad: (query) => query.trim().length >= 2,
            load: (query, callback) => this.search(query, callback),
            closeAfterSelect: true,
            render: {
                option: (data) => `<div>${data.text}</div>`,
                item: (data, escape) => `<div>${escape(data.name)}</div>`,
                no_results: () => `<div class="no-results">${this.escape(this.noResultsLabelValue)}</div>`,
                loading: () => '<div class="spinner-border spinner-border-sm m-2" role="status"></div>',
            },
            onChange: (value) => this.picked(value),
        });

        // Capture: before Tom Select and before the form's implicit submission
        this.tomSelect.control_input.addEventListener('keydown', this.onKeyDown, true);
    }

    disconnect() {
        this.tomSelect?.control_input?.removeEventListener('keydown', this.onKeyDown, true);
        this.tomSelect?.destroy();
        this.tomSelect = null;
    }

    async search(query, callback) {
        const url = new URL(this.urlValue, window.location.href);
        url.searchParams.set('query', query);

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = response.ok ? await response.json() : { results: [] };
            const results = Array.isArray(data.results) ? data.results : [];

            // Only this query's results - an earlier query's must not stay listed
            this.tomSelect.clearOptions();
            callback(results);

            if (results.length === 0) {
                this.showBrandPath();
            } else if (this.pendingEnter && results.length === 1) {
                // A scanned barcode (digits + Enter) finds its one puzzle: picked at once
                this.tomSelect.setValue(results[0].value);
            }
        } catch {
            callback();
        } finally {
            this.pendingEnter = false;
        }
    }

    onKeyDown(event) {
        if (event.key !== 'Enter') {
            return;
        }

        // Enter never sends the form from here; with nothing to pick yet it picks the only result once it is in
        if (!this.tomSelect.activeOption) {
            event.preventDefault();
            this.pendingEnter = this.tomSelect.inputValue().trim().length >= 2;
        }
    }

    picked(value) {
        if (!value) {
            return;
        }

        const option = this.tomSelect.options[value];

        if (option && option.brand) {
            this.dispatch('picked', { detail: { option } });
            this.showBrandPath();
        }

        // Ready for the next search - the picker below shows the choice
        this.tomSelect.clear(true);
        this.tomSelect.clearOptions();
        this.tomSelect.setTextboxValue('');
        this.tomSelect.blur();
    }

    showBrand(event) {
        event.preventDefault();
        this.showBrandPath();
        this.brandPathTarget.querySelector('input, select')?.tomselect?.focus();
    }

    showBrandPath() {
        if (!this.hasBrandPathTarget) {
            return;
        }

        this.brandPathTarget.hidden = false;

        if (this.hasToggleTarget) {
            this.toggleTarget.hidden = true;
        }
    }

    hideBrandPath() {
        this.brandPathTarget.hidden = true;

        if (this.hasToggleTarget) {
            this.toggleTarget.hidden = false;
        }
    }

    brandPathHasValue() {
        return Array.from(this.brandPathTarget.querySelectorAll('input[name], select[name]'))
            .some((field) => field.type !== 'hidden' && field.value.trim() !== '')
            || this.brandPathTarget.querySelector('.is-invalid, .invalid-feedback') !== null;
    }

    escape(text) {
        return String(text ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[char]));
    }
}
