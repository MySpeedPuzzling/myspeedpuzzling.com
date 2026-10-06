import { Controller } from '@hotwired/stimulus';
import { compressImage, shouldCompress } from '../image_compression.js';

export default class extends Controller {
    static targets = ["submit", "label"];
    static values = {
        isSubmitting: Boolean,
        compressImages: { type: Boolean, default: false },
        compressingText: { type: String, default: 'Compressing images...' },
        savingText: { type: String, default: 'Saving...' },
        // PhotoUploadLimits: above them PHP drops the whole request and everything typed into the form is lost
        maxPhotoBytes: { type: Number, default: 0 },
        maxRequestBytes: { type: Number, default: 0 },
        photoTooLargeText: { type: String, default: 'This photo is too large to upload (%size% MB).' },
        dropFileText: { type: String, default: '' },
    };

    connect() {
        this.isSubmittingValue = false;
        this.processedFiles = new WeakSet();
        this.originalLabelHtml = null;

        this.boundPrevent = this.preventDuplicateSubmission.bind(this);
        this.boundSubmitEnd = this.submitEnded.bind(this);

        this.element.addEventListener('submit', this.boundPrevent);
        this.element.addEventListener('turbo:submit-end', this.boundSubmitEnd);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.boundPrevent);
        this.element.removeEventListener('turbo:submit-end', this.boundSubmitEnd);
    }

    // Turbo fires submit-end as soon as the answer's headers arrive. A successful full-page submit answers with a
    // redirect and Turbo is about to render the next page - unlocking now would let a second tap send the form
    // again while the first one is already saved (docs/features/duplicate-results.md). So the button stays locked
    // until the new page replaces the form. Everything else unlocks: a refused form (422 - Turbo renders it, the
    // new form element starts unlocked anyway), a dropped connection, and modal/frame submits answered with a
    // stream or frame content, where the form stays on the page.
    submitEnded(event) {
        const { success, fetchResponse } = event.detail ?? {};

        if (success && fetchResponse?.redirected) {
            // Turbo has just re-enabled the button it was submitted with - disable it again
            this.disableSubmitButton();
            return;
        }

        this.reset();
    }

    preventDuplicateSubmission(event) {
        if (this.isSubmittingValue) {
            event.preventDefault();
            return;
        }

        if (this.compressImagesValue) {
            const fileInputs = this.element.querySelectorAll('.file-drop-input');
            const filesToCompress = this.findFilesToCompress(fileInputs);

            if (filesToCompress.length > 0) {
                event.preventDefault();
                event.stopImmediatePropagation();

                this.isSubmittingValue = true;
                this.disableSubmitButton();
                this.showCompressingState();

                this.compressAllFiles(filesToCompress).then(() => {
                    this.showSavingState();
                    this.isSubmittingValue = false;
                    this.element.requestSubmit();
                });

                return;
            }
        }

        if (this.removeOversizedPhotos()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.reset();

            return;
        }

        this.isSubmittingValue = true;
        this.disableSubmitButton();
        this.showSavingState();
    }

    /**
     * A photo the browser could not shrink (a 200 MP shot it cannot decode, a HEIC outside Safari) and that is
     * still above the server's limit is taken out of its field, with a note next to it, and the form is not sent:
     * the player keeps everything typed and can pick a smaller photo or save without one.
     * Largest first, until what is left fits into one request.
     */
    removeOversizedPhotos() {
        if (this.maxPhotoBytesValue <= 0) {
            return false;
        }

        const photos = [...this.element.querySelectorAll('.file-drop-input')]
            .filter(input => input.files && input.files[0])
            .map(input => ({ input, size: input.files[0].size }))
            .sort((a, b) => b.size - a.size);

        let total = photos.reduce((sum, photo) => sum + photo.size, 0);
        let removed = null;

        for (const photo of photos) {
            const tooLarge = photo.size > this.maxPhotoBytesValue;
            const requestTooLarge = this.maxRequestBytesValue > 0 && total > this.maxRequestBytesValue;

            if (!tooLarge && !requestTooLarge) {
                continue;
            }

            this.removePhoto(photo.input, photo.size);
            total -= photo.size;
            removed ??= photo.input;
        }

        if (removed === null) {
            return false;
        }

        removed.closest('.file-drop-area')?.scrollIntoView({ behavior: 'smooth', block: 'center' });

        return true;
    }

    removePhoto(input, size) {
        const area = input.closest('.file-drop-area');

        input.value = '';

        if (area) {
            const icon = area.querySelector('.file-drop-preview, .file-drop-icon');
            if (icon) {
                icon.className = 'file-drop-icon';
                icon.innerHTML = '<i class="ci-cloud-upload"></i>';
            }

            const message = area.querySelector('.file-drop-message');
            if (message && this.dropFileTextValue !== '') {
                message.textContent = this.dropFileTextValue;
            }

            area.querySelector('.file-drop-edit-btn')?.remove();
        }

        const anchor = area ?? input;
        anchor.parentElement.querySelectorAll('[data-photo-too-large]').forEach(note => note.remove());

        const note = document.createElement('div');
        note.className = 'invalid-feedback d-block mb-2';
        note.setAttribute('data-photo-too-large', '');
        note.setAttribute('role', 'alert');
        note.textContent = this.photoTooLargeTextValue.replace('%size%', String(Math.round(size / 1000000)));
        anchor.before(note);

        // Picking another photo takes the note away
        input.addEventListener('change', () => note.remove(), { once: true });
    }

    reset() {
        this.isSubmittingValue = false;
        this.enableSubmitButton();
        this.restoreLabel();
    }

    findFilesToCompress(fileInputs) {
        const result = [];

        fileInputs.forEach(input => {
            if (!input.files || !input.files[0]) return;

            const file = input.files[0];
            if (this.processedFiles.has(file)) return;
            if (!shouldCompress(file)) return;

            result.push({ input, file });
        });

        return result;
    }

    async compressAllFiles(filesToCompress) {
        for (const { input, file } of filesToCompress) {
            this.processedFiles.add(file);

            try {
                const compressedFile = await compressImage(file);

                if (compressedFile.size < file.size) {
                    this.processedFiles.add(compressedFile);
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(compressedFile);
                    input.files = dataTransfer.files;
                }
            } catch (error) {
                // Compression failed — submit with original file
            }
        }
    }

    disableSubmitButton() {
        this.submitTarget.setAttribute('disabled', 'disabled');
        this.submitTarget.classList.add('is-loading');
    }

    enableSubmitButton() {
        this.submitTarget.removeAttribute('disabled');
        this.submitTarget.classList.remove('is-loading');
    }

    showCompressingState() {
        if (this.hasLabelTarget) {
            if (this.originalLabelHtml === null) {
                this.originalLabelHtml = this.labelTarget.innerHTML;
            }
            this.labelTarget.textContent = this.compressingTextValue;
        }
    }

    showSavingState() {
        if (this.hasLabelTarget) {
            if (this.originalLabelHtml === null) {
                this.originalLabelHtml = this.labelTarget.innerHTML;
            }
            this.labelTarget.textContent = this.savingTextValue;
        }
    }

    restoreLabel() {
        if (this.hasLabelTarget && this.originalLabelHtml !== null) {
            this.labelTarget.innerHTML = this.originalLabelHtml;
            this.originalLabelHtml = null;
        }
    }
}
