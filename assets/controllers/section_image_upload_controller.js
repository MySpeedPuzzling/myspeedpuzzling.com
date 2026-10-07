/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { compressedOrOriginal } from '../image_compression.js';

/**
 * A gallery photo or sponsor logo of a page section form: uploaded right away (UploadPageSectionImageController), the
 * stored path goes into the row's hidden input, the reason of a refusal is shown under the picture. Large JPEG photos
 * are shrunk first; PNG, GIF and WebP stay as they are (a logo keeps its transparency).
 */
export default class extends Controller {
    static targets = ['file', 'path', 'preview', 'progress', 'error'];

    static values = {
        url: String,
        ownerField: String,
        ownerId: String,
        token: String,
        failedMessage: String,
    };

    disconnect() {
        this.revokePreview();
    }

    async upload() {
        const original = this.fileTarget.files[0];

        if (!original) {
            return;
        }

        this.showError(null);
        this.fileTarget.disabled = true;
        this.progressTarget.classList.remove('d-none');

        try {
            const file = original.type === 'image/jpeg' ? await compressedOrOriginal(original) : original;
            const body = new FormData();
            body.append('file', file, file.name);
            body.append(this.ownerFieldValue, this.ownerIdValue);
            body.append('_token', this.tokenValue);

            // A redirect means the session is gone (sign-in page) - never a success
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json' },
                redirect: 'manual',
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok || typeof data.path !== 'string') {
                this.showError(typeof data.error === 'string' && data.error !== '' ? data.error : this.failedMessageValue);

                return;
            }

            this.pathTarget.value = data.path;
            this.revokePreview();
            this.previewUrl = URL.createObjectURL(file);
            this.previewTarget.src = this.previewUrl;
            this.previewTarget.classList.remove('d-none');
        } catch (error) {
            this.showError(this.failedMessageValue);
        } finally {
            this.fileTarget.disabled = false;
            this.fileTarget.value = '';
            this.progressTarget.classList.add('d-none');
        }
    }

    showError(message) {
        this.errorTarget.textContent = message ?? '';
        this.errorTarget.classList.toggle('d-none', message === null);
    }

    revokePreview() {
        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }
    }
}
