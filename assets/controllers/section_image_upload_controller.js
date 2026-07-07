import { Controller } from '@hotwired/stimulus';

/**
 * Uploads a page-section image to S3 via the upload endpoint and stores the
 * returned storage path in a hidden input.
 */
export default class extends Controller {
    static targets = ['file', 'path', 'preview'];
    static values = {
        url: String,
        ownerType: String,
        ownerId: String,
    };

    async upload() {
        const file = this.fileTarget.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append(this.ownerTypeValue === 'competition' ? 'competitionId' : 'seriesId', this.ownerIdValue);

        this.fileTarget.disabled = true;

        try {
            const response = await fetch(this.urlValue, { method: 'POST', body: formData });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                alert(error.error || 'Upload failed');
                return;
            }

            const data = await response.json();
            this.pathTarget.value = data.path;

            if (this.hasPreviewTarget) {
                this.previewTarget.src = URL.createObjectURL(file);
                this.previewTarget.classList.remove('d-none');
            }
        } catch (e) {
            alert('Upload failed — check your connection.');
        } finally {
            this.fileTarget.disabled = false;
        }
    }
}
