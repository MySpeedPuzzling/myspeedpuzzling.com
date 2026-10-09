/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The line under the "Competition / event" picker once a series (or an edition) is chosen
 * (docs/features/events-page/high-frequency-series.md "The preview line and S2"): what MySpeedPuzzling will link the
 * time to, or - when it cannot tell - the series' short edition list, open, nothing preselected.
 *
 * Idle without a `series:` / `edition:` value - the 97 % of forms that never choose a series fetch nothing. Refreshes
 * (debounced) when the value, the puzzle, the date or the co-puzzlers change: the server answers with the fragment
 * (competition_picker_series_preview) - every text comes with it. A list row adds its edition to the picker
 * (`edition:<uuid>`, an explicit pick); "Let MySpeedPuzzling match it" sets the series back (`series:<uuid>`).
 *
 * It never submits, re-renders or navigates the form: the list's search field is no form field (no name), Enter in it
 * does nothing and its change events stay here.
 */
export default class extends Controller {
    static targets = ['field', 'output', 'list', 'search', 'items'];

    static values = {
        url: String,
    };

    connect() {
        this.form = this.element.closest('form');
        this.timer = null;
        this.searchTimer = null;
        this.request = null;
        this.searchRequest = null;
        this.lastQuery = null;

        this.onFormChange = (event) => {
            // The fragment's own controls are handled by the actions below
            if (this.hasOutputTarget && this.outputTarget.contains(event.target)) {
                return;
            }

            this.schedule();
        };

        this.form?.addEventListener('change', this.onFormChange);

        // A refused submit, the edit form or a deep link may hold a series already
        this.refresh();
    }

    disconnect() {
        this.form?.removeEventListener('change', this.onFormChange);
        clearTimeout(this.timer);
        clearTimeout(this.searchTimer);
        this.request?.abort();
        this.searchRequest?.abort();
    }

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 250);
    }

    fieldValue() {
        return this.hasFieldTarget ? this.fieldTarget.value.trim() : '';
    }

    formField(suffix) {
        if (!this.form) {
            return null;
        }

        // The form's own fields by name (puzzle_add_form[puzzle], edit_puzzle_solving_time_form[finishedAt], ...)
        return Array.from(this.form.elements).find((element) => element.name && element.name.endsWith('[' + suffix + ']')) || null;
    }

    people() {
        if (!this.form) {
            return 0;
        }

        return Array.from(this.form.querySelectorAll('input[name="group_players[]"]'))
            .filter((input) => input.value.trim() !== '')
            .length;
    }

    params() {
        const value = this.fieldValue();
        const params = new URLSearchParams();

        if (value.startsWith('series:')) {
            params.set('series', value.slice('series:'.length));
        } else if (value.startsWith('edition:')) {
            params.set('edition', value.slice('edition:'.length));
        } else {
            return null;
        }

        // Only the speed puzzling form has a competition
        const mode = this.formField('mode');

        if (mode && mode.value && mode.value !== 'speed_puzzling') {
            return null;
        }

        const puzzle = this.formField('puzzle');

        if (puzzle && puzzle.value) {
            params.set('puzzle', puzzle.value);
        }

        const date = this.formField('finishedAt');

        if (date && date.value) {
            params.set('date', date.value);
        }

        params.set('people', String(this.people()));

        return params;
    }

    async refresh() {
        const params = this.params();

        if (params === null) {
            this.lastQuery = null;
            this.request?.abort();
            this.outputTarget.innerHTML = '';

            return;
        }

        const query = params.toString();

        if (query === this.lastQuery) {
            return;
        }

        this.lastQuery = query;
        this.request?.abort();
        this.searchRequest?.abort();

        const request = new AbortController();
        this.request = request;

        try {
            const response = await fetch(this.urlValue + '?' + query, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
                signal: request.signal,
            });

            if (!response.ok) {
                this.lastQuery = null;

                return;
            }

            const html = await response.text();

            if (!request.signal.aborted) {
                this.outputTarget.innerHTML = html;
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.lastQuery = null;
            }
        }
    }

    tomSelect() {
        return this.hasFieldTarget ? this.fieldTarget.tomselect : null;
    }

    // A row of the short list: its edition becomes the explicit pick
    pick(event) {
        const tomSelect = this.tomSelect();
        const option = event.params.option;
        const optgroup = event.params.optgroup;

        if (!tomSelect || !option || typeof option !== 'object' || !option.value) {
            return;
        }

        if (optgroup && typeof optgroup === 'object' && optgroup.value && !tomSelect.optgroups[optgroup.value]) {
            tomSelect.addOptionGroup(optgroup.value, optgroup);
        }

        tomSelect.addOption(option);
        tomSelect.setValue(option.value);
    }

    // "Let MySpeedPuzzling match it": the series again
    matchAutomatically(event) {
        const tomSelect = this.tomSelect();
        const value = event.params.value;

        if (tomSelect && value && tomSelect.options[value]) {
            tomSelect.setValue(value);
        }
    }

    toggleList(event) {
        if (!this.hasListTarget) {
            return;
        }

        const open = this.listTarget.classList.toggle('d-none') === false;
        event.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open && this.hasSearchTarget) {
            this.searchTarget.focus();
        }
    }

    search(event) {
        // Typing here is no change of the time the form describes
        event.stopPropagation();
        clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => this.fetchList(), 250);
    }

    async fetchList() {
        const params = this.params();

        if (params === null || !this.hasItemsTarget || !this.hasSearchTarget) {
            return;
        }

        // The list belongs to the series of the line (an explicitly picked edition's too)
        const body = this.outputTarget.querySelector('[data-series-preview-series]');
        const seriesId = body ? body.dataset.seriesPreviewSeries : '';

        if (!seriesId) {
            return;
        }

        params.delete('edition');
        params.set('series', seriesId);
        params.set('part', 'list');
        params.set('q', this.searchTarget.value.trim());

        this.searchRequest?.abort();
        const request = new AbortController();
        this.searchRequest = request;

        try {
            const response = await fetch(this.urlValue + '?' + params.toString(), {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
                signal: request.signal,
            });

            if (!response.ok) {
                return;
            }

            const html = await response.text();

            if (!request.signal.aborted && this.hasItemsTarget) {
                this.itemsTarget.innerHTML = html;
            }
        } catch (error) {
            // A newer search or a newer preview took over - nothing to do
        }
    }

    // The search field is no form field: its change must not look like the form changed (first-try check, preview)
    stop(event) {
        event.stopPropagation();
    }

    // Enter in the search field must not submit the form
    ignoreEnter(event) {
        event.preventDefault();
    }
}
