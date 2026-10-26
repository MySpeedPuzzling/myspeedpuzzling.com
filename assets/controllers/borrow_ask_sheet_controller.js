/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { chooseTranslation } from '../translation_choice.js';

/**
 * The ask-to-borrow sheet (docs/features/wishlist-availability.md). Each puzzle chip holds the hidden
 * `assignments[<ownerId>][]` input Send reads - nothing else is the source of truth. ✕ takes a chip out; "Ask … instead"
 * puts the server-rendered chip of that puzzle for the other owner into that owner's group. A group without chips is
 * hidden and sends nothing; Send says how many people get a request and is off at none. Nothing here submits.
 */
export default class extends Controller {
    static targets = ['group', 'chips', 'chip', 'chipTemplate', 'submit', 'groupCount'];

    static values = {
        sendText: Object,
    };

    connect() {
        this.update();
    }

    remove(event) {
        event.currentTarget.closest('[data-borrow-ask-sheet-target~="chip"]')?.remove();
        this.update();
    }

    move(event) {
        const { puzzle, owner } = event.params;
        const template = this.chipTemplateTargets.find((candidate) => candidate.dataset.puzzleId === puzzle && candidate.dataset.ownerId === owner);
        const group = this.groupTargets.find((candidate) => candidate.dataset.ownerId === owner);

        if (template === undefined || group === undefined) {
            return;
        }

        event.currentTarget.closest('[data-borrow-ask-sheet-target~="chip"]')?.remove();
        group.querySelector('[data-borrow-ask-sheet-target~="chips"]').appendChild(template.content.cloneNode(true));
        this.update();
    }

    update() {
        let people = 0;

        this.groupTargets.forEach((group) => {
            const count = group.querySelectorAll('[data-borrow-ask-sheet-target~="chip"]').length;
            group.classList.toggle('d-none', count === 0);
            group.querySelectorAll('input[name^="notes["]').forEach((input) => {
                input.disabled = count === 0;
            });

            if (count > 0) {
                people++;
            }
        });

        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = people === 0;

            const text = this.sendTextValue;

            if (text && typeof text.message === 'string') {
                const label = chooseTranslation(text.message, people, text.locale);

                if (label !== null) {
                    this.submitTarget.textContent = label.replace('%count%', String(people));
                }
            }
        }
    }
}
