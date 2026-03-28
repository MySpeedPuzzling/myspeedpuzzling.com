import { Controller } from '@hotwired/stimulus';

const COMPRESS_THRESHOLD_BYTES = 500 * 1024;
const MAX_DIMENSION = 2000;
const JPEG_QUALITY = 0.85;

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
        this.compressionDone = false;
        this.originalLabelHtml = null;

        this.boundPrevent = this.preventDuplicateSubmission.bind(this);
        this.boundReset = this.reset.bind(this);

        this.element.addEventListener('submit', this.boundPrevent);
        this.element.addEventListener('turbo:submit-end', this.boundReset);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.boundPrevent);
        this.element.removeEventListener('turbo:submit-end', this.boundReset);
    }

    preventDuplicateSubmission(event) {
        if (this.isSubmittingValue) {
            event.preventDefault();
            return;
        }

        if (this.compressImagesValue && !this.compressionDone) {
            const fileInputs = this.element.querySelectorAll('.file-drop-input');
            const filesToCompress = this.findFilesToCompress(fileInputs);

            if (filesToCompress.length > 0) {
                event.preventDefault();
                event.stopImmediatePropagation();

                this.isSubmittingValue = true;
                this.disableSubmitButton();
                this.showCompressingState();

                this.compressAllFiles(filesToCompress).then(() => {
                    this.compressionDone = true;
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
        this.compressionDone = false;
        this.enableSubmitButton();
        this.restoreLabel();
    }

    findFilesToCompress(fileInputs) {
        const result = [];

        fileInputs.forEach(input => {
            if (!input.files || !input.files[0]) return;

            const file = input.files[0];
            if (file.size <= COMPRESS_THRESHOLD_BYTES) return;
            if (file.type === 'image/gif') return;
            if (!file.type.startsWith('image/')) return;

            result.push({ input, file });
        });

        return result;
    }

    async compressAllFiles(filesToCompress) {
        for (const { input, file } of filesToCompress) {
            try {
                const compressedFile = await this.compressImage(file);

                if (compressedFile.size < file.size) {
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(compressedFile);
                    input.files = dataTransfer.files;
                }
            } catch (error) {
                // Compression failed — submit with original file
            }
        }
    }

    compressImage(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();

            img.onload = () => {
                URL.revokeObjectURL(url);

                try {
                    let { width, height } = this.calculateDimensions(img.naturalWidth, img.naturalHeight);

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;

                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);

                    canvas.toBlob(
                        (blob) => {
                            if (!blob) {
                                reject(new Error('Canvas toBlob returned null'));
                                return;
                            }

                            const fileName = file.name.replace(/\.[^.]+$/, '.jpg');
                            resolve(new File([blob], fileName, {
                                type: 'image/jpeg',
                                lastModified: Date.now(),
                            }));
                        },
                        'image/jpeg',
                        JPEG_QUALITY,
                    );
                } catch (error) {
                    reject(error);
                }
            };

            img.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('Failed to load image'));
            };

            img.src = url;
        });
    }

    calculateDimensions(originalWidth, originalHeight) {
        let width = originalWidth;
        let height = originalHeight;

        if (width <= MAX_DIMENSION && height <= MAX_DIMENSION) {
            return { width, height };
        }

        if (width > height) {
            height = Math.round(height * (MAX_DIMENSION / width));
            width = MAX_DIMENSION;
        } else {
            width = Math.round(width * (MAX_DIMENSION / height));
            height = MAX_DIMENSION;
        }

        return { width, height };
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
