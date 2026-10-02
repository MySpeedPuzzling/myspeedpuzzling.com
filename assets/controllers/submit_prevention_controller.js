import { Controller } from '@hotwired/stimulus';
import { compressImage, shouldCompress } from '../image_compression.js';

export default class extends Controller {
    static targets = ["submit", "label"];
    static values = {
        isSubmitting: Boolean,
        compressImages: { type: Boolean, default: false },
        compressingText: { type: String, default: 'Compressing images...' },
        savingText: { type: String, default: 'Saving...' },
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

        this.isSubmittingValue = true;
        this.disableSubmitButton();
        this.showSavingState();
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
