import { Controller } from '@hotwired/stimulus';

/**
 * The creation wizard's Puzzles grid (EventWizardPuzzlesController): one brand + puzzle picker per round
 * (time_form_autocomplete, W1's picker) - without each of them carrying every brand.
 *
 * - The brands come once, as this controller's value, and are handed to every brand picker when it connects
 *   (`autocomplete:pre-connect` bubbles up here). Not lazy on purpose: it must listen before the pickers' lazy chunks
 *   connect.
 * - "+ another puzzle" shows the row's next picker (a `<template>` - not even connected before it is wanted) and the
 *   row's "each competitor solves K"; once no picker is left it submits as it does without JavaScript (the grid is
 *   saved, then shown again at the row).
 */
export default class extends Controller {
    static values = { brands: Array };

    initialize() {
        this.onPreConnect = this.onPreConnect.bind(this);
    }

    connect() {
        this.element.addEventListener('autocomplete:pre-connect', this.onPreConnect);
    }

    disconnect() {
        this.element.removeEventListener('autocomplete:pre-connect', this.onPreConnect);
    }

    onPreConnect(event) {
        if (event.target?.dataset?.timeFormAutocompleteTarget !== 'brand') {
            return;
        }

        event.detail.options.options = this.brandsValue;
    }

    another(event) {
        const row = event.currentTarget.closest('[data-event-wizard-puzzles-row]');
        const template = row?.querySelector('template[data-event-wizard-puzzles-slot]');

        if (!template) {
            return;
        }

        event.preventDefault();
        template.replaceWith(template.content.cloneNode(true));
        row.querySelectorAll('[data-event-wizard-puzzles-pick]').forEach((element) => { element.hidden = false; });
    }
}
