import { Controller } from '@hotwired/stimulus';

// The review of a merge request: which reported puzzle keeps its address (the survivor - its card is highlighted and
// the summary above the approve button says what happens to the others), and one-click choices that copy a reported
// puzzle's value into a field of the merged puzzle (the choice in use is shown pressed).
// A choice carries data-input (the field's name) and data-value - read as strings, never type-cast.
export default class extends Controller {
    static targets = ['card', 'summary', 'choice', 'survivor'];

    connect() {
        this.survivorChanged();
        this.refreshChoices();
    }

    survivorChanged() {
        const checked = this.survivorTargets.find((radio) => radio.checked);
        const survivorId = checked ? checked.value : null;

        this.cardTargets.forEach((card) => {
            const isSurvivor = card.dataset.puzzleId === survivorId;
            card.classList.toggle('border-success', isSurvivor);
            card.classList.toggle('border-2', isSurvivor);
            card.querySelectorAll('[data-role="survivor-badge"]').forEach((badge) => {
                badge.classList.toggle('d-none', !isSurvivor);
            });
        });

        this.summaryTargets.forEach((summary) => {
            summary.classList.toggle('d-none', summary.dataset.puzzleId !== survivorId);
        });
    }

    use(event) {
        const { input, value } = event.currentTarget.dataset;
        const field = this.fieldOf(input);

        if (!field) {
            return;
        }

        // The brand select is a TomSelect - set it through TomSelect so its control shows the value too
        if (field.tomselect) {
            field.tomselect.setValue(value, true);
        } else {
            field.value = value;
        }

        this.refreshChoices();
    }

    refreshChoices() {
        this.choiceTargets.forEach((choice) => {
            const field = this.fieldOf(choice.dataset.input);
            const active = Boolean(field) && field.value.trim() === choice.dataset.value.trim();

            choice.classList.toggle('active', active);
            choice.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    fieldOf(name) {
        return this.element.querySelector(`[name="${name}"]`);
    }
}
