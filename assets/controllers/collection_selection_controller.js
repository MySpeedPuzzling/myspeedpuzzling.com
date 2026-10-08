/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { chooseTranslation } from '../translation_choice.js';

/**
 * Select several puzzles on your own collection page (members, docs/features/collections/bulk-actions.md).
 *
 * - Ticking the first checkbox shows the floating bar; while anything is selected a tap anywhere on a card toggles
 *   it instead of opening the puzzle, and the card menus step aside. Shift-click selects a range. Esc clears.
 * - "Select all" picks the cards the filters show (collection_filter_controller.js hides the rest).
 * - The bar's Move / Copy / Remove post the selected ids into the modal frame as hidden inputs - never in the URL.
 * - The selection lives on this page only: a reload or another page starts empty.
 * - After a successful Move / Copy / Remove (a form marked data-collection-selection-form) the selection is cleared;
 *   cards the answer removed simply drop out of the targets.
 * The counter's text comes from Twig (browser_translation()), plural form picked like PHP does.
 */
export default class extends Controller {
    static targets = ['checkbox', 'bar', 'count', 'inputs'];

    static values = {
        countText: Object,
    };

    lastToggled = null;

    connect() {
        this.update();
    }

    checkboxTargetDisconnected() {
        // A card removed by a Turbo Stream (moved, removed) - recount once the DOM settled, the filters' "Total found"
        // too (collection_filter_controller.js only counts when a filter changes)
        queueMicrotask(() => {
            this.update();

            const visibleCount = this.element.querySelector('[data-collection-filter-target~="visibleCount"]');

            if (visibleCount !== null) {
                const cards = this.element.querySelectorAll('[data-collection-filter-target~="item"]');
                visibleCount.textContent = String([...cards].filter((card) => card.style.display !== 'none').length);
            }
        });
    }

    toggle(event) {
        const checkbox = event.currentTarget;

        if (event.shiftKey && this.lastToggled !== null && this.lastToggled !== checkbox) {
            const boxes = this.visibleCheckboxes();
            const from = boxes.indexOf(this.lastToggled);
            const to = boxes.indexOf(checkbox);

            if (from !== -1 && to !== -1) {
                boxes.slice(Math.min(from, to), Math.max(from, to) + 1).forEach((box) => {
                    box.checked = checkbox.checked;
                });
            }
        }

        this.lastToggled = checkbox;
        this.update();
    }

    // While selecting, a tap on a card (outside its checkbox) toggles the card instead of following its links
    cardClick(event) {
        if (!this.selecting()) {
            return;
        }

        const card = event.target.closest('[data-collection-filter-target~="item"]');

        if (card === null || !this.element.contains(card) || event.target.closest('input, .collection-select-check')) {
            return;
        }

        const checkbox = card.querySelector('[data-collection-selection-target~="checkbox"]');

        if (checkbox === null) {
            return;
        }

        event.preventDefault();
        checkbox.checked = !checkbox.checked;
        this.lastToggled = checkbox;
        this.update();
    }

    selectAll() {
        this.visibleCheckboxes().forEach((checkbox) => {
            checkbox.checked = true;
        });

        this.update();
    }

    escape() {
        // Esc in an open modal closes the modal, not the selection
        if (document.querySelector('.modal.show') === null) {
            this.clear();
        }
    }

    clear() {
        if (!this.selecting()) {
            return;
        }

        this.checkboxTargets.forEach((checkbox) => {
            checkbox.checked = false;
        });

        this.lastToggled = null;
        this.update();
    }

    submitted(event) {
        if (event.detail.success && event.target.matches('[data-collection-selection-form]')) {
            this.clear();
        }
    }

    selecting() {
        return this.checkboxTargets.some((checkbox) => checkbox.checked);
    }

    visibleCheckboxes() {
        return this.checkboxTargets.filter((checkbox) => {
            const card = checkbox.closest('[data-collection-filter-target~="item"]');

            return card === null || card.style.display !== 'none';
        });
    }

    update() {
        const selected = this.checkboxTargets.filter((checkbox) => checkbox.checked);
        const count = selected.length;

        this.element.classList.toggle('collection-selecting', count > 0);
        this.checkboxTargets.forEach((checkbox) => {
            checkbox.closest('[data-collection-filter-target~="item"]')?.classList.toggle('collection-item-selected', checkbox.checked);
        });

        if (!this.hasBarTarget) {
            return;
        }

        this.barTarget.hidden = count === 0;

        if (this.hasCountTarget) {
            const text = this.countTextValue;
            this.countTarget.textContent = text && typeof text.message === 'string'
                ? chooseTranslation(text.message, count, text.locale) ?? String(count)
                : String(count);
        }

        if (this.hasInputsTarget) {
            this.inputsTarget.replaceChildren(...selected.map((checkbox) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'puzzleIds[]';
                input.value = checkbox.value;

                return input;
            }));
        }
    }
}
