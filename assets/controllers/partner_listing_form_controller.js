import { Controller } from '@hotwired/stimulus';

/*
 * The partner finder listing form (templates/partner_finder/_listing_form.html.twig): the group fields only for a group,
 * "a pair partner" off for a group (a group of two is a pair already - the server refuses it too), the note's counter.
 */
export default class extends Controller {
    static targets = ['iAm', 'groupFields', 'kind', 'pairHint', 'note', 'counter'];

    connect() {
        this.update();
        this.count();
    }

    update() {
        const checked = this.iAmTargets.find((input) => input.checked);
        const group = checked !== undefined && checked.value === 'group';

        if (this.hasGroupFieldsTarget) {
            this.groupFieldsTarget.hidden = !group;
        }

        this.kindTargets.forEach((input) => {
            if (input.dataset.kind !== 'pair') {
                return;
            }

            if (group) {
                input.checked = false;
            }

            input.disabled = group;
        });

        if (this.hasPairHintTarget) {
            this.pairHintTarget.hidden = !group || !this.kindTargets.some((input) => input.dataset.kind === 'pair');
        }
    }

    count() {
        if (!this.hasNoteTarget || !this.hasCounterTarget) {
            return;
        }

        const max = Number(this.noteTarget.getAttribute('maxlength')) || 200;
        this.counterTarget.textContent = `${this.noteTarget.value.length} / ${max}`;
    }
}
