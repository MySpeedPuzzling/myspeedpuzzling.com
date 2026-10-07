import { Controller } from '@hotwired/stimulus';
import { clearPhoto, hasPhoto } from '../photo_drop_area.js';

// The review of a merge request: which reported puzzle keeps its address (the survivor - its card is highlighted and
// the summary above the approve button says what happens to the others), and one-click choices that copy a reported
// puzzle's value into a field of the merged puzzle (the choice in use is shown pressed).
// A choice carries data-input (the field's name) and data-value - read as strings, never type-cast.
// A new photo in the image card's drop area (chosen, or kept from a refused submit) is used instead of the puzzles'
// images: none of them stays picked and they are dimmed. Picking one again drops the photo; removing a kept photo
// picks the image picked before it (or the survivor's).
export default class extends Controller {
    static targets = ['card', 'summary', 'choice', 'survivor', 'imageOption', 'imageRadio', 'photoArea'];
    static values = {
        dropText: String,
    };

    connect() {
        this.survivorChanged();
        this.refreshChoices();
        this.refreshImage();
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

    imageChosen(event) {
        if (this.hasPhotoAreaTarget) {
            clearPhoto(this.photoAreaTarget, this.dropTextValue);
        }

        this.pickedImage = event.target.value;
        this.refreshImage();
    }

    photoRemoved() {
        const survivor = this.survivorTargets.find((radio) => radio.checked);
        const picked = this.imageRadioTargets.find((radio) => radio.value === this.pickedImage)
            || this.imageRadioTargets.find((radio) => survivor && radio.value === survivor.value)
            || this.imageRadioTargets[0];

        if (picked) {
            picked.checked = true;
        }

        this.refreshImage();
    }

    refreshImage() {
        const photo = this.hasPhotoAreaTarget && hasPhoto(this.photoAreaTarget);

        if (photo) {
            this.imageRadioTargets.filter((radio) => radio.checked).forEach((radio) => {
                this.pickedImage = radio.value;
                radio.checked = false;
            });
        }

        this.imageOptionTargets.forEach((option) => option.classList.toggle('opacity-50', photo));
    }

    fieldOf(name) {
        return this.element.querySelector(`[name="${name}"]`);
    }
}
