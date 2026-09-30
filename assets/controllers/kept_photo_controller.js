import { Controller } from '@hotwired/stimulus';

/**
 * A photo kept from a refused submit (FormPhotoStash): shown in its drop area and sent again as a token.
 * Choosing another file replaces it, "Remove" drops it.
 */
export default class extends Controller {
    static targets = ['token', 'preview', 'note', 'message'];

    static values = {
        dropText: String,
    };

    connect() {
        this.input = this.element.querySelector('.file-drop-input');
        this.onFileChosen = () => this.forget();
        this.input?.addEventListener('change', this.onFileChosen);
    }

    disconnect() {
        this.input?.removeEventListener('change', this.onFileChosen);
    }

    remove() {
        this.forget();

        if (this.hasPreviewTarget) {
            const icon = this.previewTarget.closest('.file-drop-icon');

            this.previewTarget.remove();

            if (icon) {
                icon.className = 'file-drop-icon';
                icon.innerHTML = '<i class="ci-cloud-upload"></i>';
            }
        }

        if (this.hasMessageTarget) {
            this.messageTarget.textContent = this.dropTextValue;
        }
    }

    forget() {
        if (this.hasTokenTarget) {
            this.tokenTarget.value = '';
        }

        if (this.hasNoteTarget) {
            this.noteTarget.remove();
        }
    }
}
