/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { chooseTranslation } from '../translation_choice.js';

/**
 * The sell/swap request sheet (docs/features/marketplace/12-listing-requests.md). Each listing row holds the hidden
 * `listings[<sellerId>][]` input Send reads - nothing else is the source of truth. ✕ takes a row out; a group without
 * rows is hidden and sends nothing (its note and offers are disabled), a group without a swap left loses its offers.
 * The seller's total follows the rows left - one seller has one currency. Send says how many sellers get a request and
 * is off at none. Nothing here submits.
 */
export default class extends Controller {
    static targets = ['group', 'row', 'total', 'offers', 'submit'];

    static values = {
        sendText: Object,
    };

    connect() {
        this.update();
    }

    remove(event) {
        event.currentTarget.closest('[data-listing-ask-sheet-target~="row"]')?.remove();
        this.update();
    }

    offerChanged(event) {
        const group = event.currentTarget.closest('[data-listing-ask-sheet-target~="group"]');
        const ticked = group?.querySelectorAll('input[name^="offers["]:checked') ?? [];

        // At most 10 offered puzzles per seller (AskForListings::MAX_OFFERS_PER_SELLER)
        if (ticked.length > 10) {
            event.currentTarget.checked = false;
        }
    }

    update() {
        let sellers = 0;

        this.groupTargets.forEach((group) => {
            const rows = [...group.querySelectorAll('[data-listing-ask-sheet-target~="row"]')];
            const empty = rows.length === 0;
            const swap = rows.some((row) => row.dataset.swap === '1');

            group.classList.toggle('d-none', empty);
            group.querySelectorAll('textarea[name^="notes["]').forEach((input) => {
                input.disabled = empty;
            });

            const offers = group.querySelector('[data-listing-ask-sheet-target~="offers"]');

            if (offers !== null) {
                offers.classList.toggle('d-none', empty || !swap);
                offers.querySelectorAll('input').forEach((input) => {
                    input.disabled = empty || !swap;
                });
            }

            const total = group.querySelector('[data-listing-ask-sheet-target~="total"]');

            if (total !== null && total.dataset.currency !== undefined) {
                const sum = rows.reduce((carry, row) => carry + (row.dataset.price === '' ? 0 : Number(row.dataset.price)), 0);
                const amount = total.querySelector('strong');

                total.classList.toggle('d-none', sum === 0);

                if (amount !== null) {
                    amount.textContent = `${Math.round(sum * 100) / 100} ${total.dataset.currency}`.trim();
                }
            }

            if (!empty) {
                sellers++;
            }
        });

        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = sellers === 0;

            const text = this.sendTextValue;

            if (text && typeof text.message === 'string') {
                const label = chooseTranslation(text.message, sellers, text.locale);

                if (label !== null) {
                    this.submitTarget.textContent = label.replace('%count%', String(sellers));
                }
            }
        }
    }
}
