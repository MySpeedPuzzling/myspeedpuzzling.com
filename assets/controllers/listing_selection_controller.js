/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { chooseTranslation } from '../translation_choice.js';
import { createListingSelectionStore } from '../listing_selection_store.js';

/**
 * A buyer ticks listings on somebody's sell/swap list or on the marketplace and asks the sellers for all of them at once
 * (docs/features/marketplace/12-listing-requests.md). The owner's own list keeps collection_selection_controller.js.
 *
 * - The selection lives in sessionStorage (listing_selection_store.js), one key per signed-in player: it stays while the
 *   buyer pages, filters, loads more on the marketplace or moves between a seller's list and the marketplace.
 * - Checkboxes come and go with Live re-renders: each one is set from the store when it connects; their labels are
 *   data-live-ignore, so a re-render of the same card keeps the tick. A checkbox leaving the page never drops an id.
 * - While anything is ticked, a tap on a card toggles it instead of following its links. Esc clears.
 * - The bar's "Ask for these" posts every stored id as `listingIds[]` into the modal frame (the sheet); a successful
 *   Send (a form marked data-listing-selection-form) clears the selection.
 */
export default class extends Controller {
    static targets = ['checkbox', 'bar', 'count', 'inputs'];

    static values = {
        storageKey: String,
        countText: Object,
        sellersText: Object,
        fullText: String,
    };

    connect() {
        let storage = null;

        try {
            storage = window.sessionStorage;
        } catch {
            storage = null;
        }

        this.store = createListingSelectionStore(storage, this.storageKeyValue);
        this.sync();
    }

    checkboxTargetConnected(checkbox) {
        if (this.store === undefined) {
            return;
        }

        checkbox.checked = this.store.has(checkbox.value);
        this.markCard(checkbox);
    }

    toggle(event) {
        this.apply(event.currentTarget, event.currentTarget.checked);
    }

    // While selecting, a tap on a card (outside its checkbox and buttons) toggles the card instead of following its links
    cardClick(event) {
        if (this.store === undefined || this.store.size() === 0) {
            return;
        }

        const card = event.target.closest('[data-listing-selection-card]');

        if (card === null || !this.element.contains(card) || event.target.closest('input, label, button, .dropdown, form')) {
            return;
        }

        const checkbox = card.querySelector('[data-listing-selection-target~="checkbox"]');

        if (checkbox === null) {
            return;
        }

        event.preventDefault();
        checkbox.checked = !checkbox.checked;
        this.apply(checkbox, checkbox.checked);
    }

    escape() {
        // Esc in an open modal closes the modal, not the selection
        if (document.querySelector('.modal.show') === null) {
            this.clear();
        }
    }

    clear() {
        this.store.clear();
        this.sync();
    }

    submitted(event) {
        if (event.detail.success && event.target.matches('[data-listing-selection-form]')) {
            this.clear();
        }
    }

    apply(checkbox, checked) {
        if (checked) {
            if (this.store.add(checkbox.value, checkbox.dataset.sellerId ?? '') === false) {
                checkbox.checked = false;
                this.markCard(checkbox);
                this.notifyFull();

                return;
            }
        } else {
            this.store.remove(checkbox.value);
        }

        this.sync();
    }

    notifyFull() {
        if (this.hasCountTarget && this.fullTextValue !== '') {
            this.countTarget.textContent = this.fullTextValue;
        }
    }

    markCard(checkbox) {
        checkbox.closest('[data-listing-selection-card]')?.classList.toggle('collection-item-selected', checkbox.checked);
    }

    sync() {
        const size = this.store.size();

        this.checkboxTargets.forEach((checkbox) => {
            checkbox.checked = this.store.has(checkbox.value);
            this.markCard(checkbox);
        });

        this.element.classList.toggle('listing-selecting', size > 0);

        if (!this.hasBarTarget) {
            return;
        }

        this.barTarget.hidden = size === 0;

        if (this.hasCountTarget) {
            this.countTarget.textContent = [
                this.plural(this.countTextValue, size),
                this.plural(this.sellersTextValue, this.store.sellerCount()),
            ].filter((part) => part !== '').join(' ');
        }

        if (this.hasInputsTarget) {
            this.inputsTarget.replaceChildren(...this.store.ids().map((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'listingIds[]';
                input.value = id;

                return input;
            }));
        }
    }

    plural(text, count) {
        if (!text || typeof text.message !== 'string') {
            return '';
        }

        return (chooseTranslation(text.message, count, text.locale) ?? '').replace('%count%', String(count));
    }
}
