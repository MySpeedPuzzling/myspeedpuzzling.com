import { Controller } from '@hotwired/stimulus';
import { compressedOrOriginal } from '../image_compression.js';
import { PhotoUpload, photoType } from '../photo_upload.js';

// PhotoUploadLimits::MIME_TYPES - never an SVG, a PDF or anything else that only calls itself a picture
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif', 'image/avif'];
// The pictures' area while a file is dragged over the form
const DROP_CLASSES = ['bg-body-secondary', 'rounded'];

/**
 * Screenshots of the feedback form (_feedback_screenshots.html.twig, docs/features/feedback-screenshots.md).
 *
 * A picture is added by "Add screenshot", by pasting it (a screenshot copied to the clipboard) or by dropping it on the
 * form. It is shrunk like every photo (assets/image_compression.js) and uploaded in the background straight to object
 * storage (assets/photo_upload.js); once there, its token goes with the form as `photo_stash[screenshots][]`.
 * One whose upload cannot work goes with the form as a file instead - nothing is worse than the plain file input,
 * which is all a browser without JavaScript sees.
 *
 * Send waits for uploads still running, then sends the form itself.
 */
export default class extends Controller {
    static targets = ['list', 'item', 'input', 'addButton', 'error', 'status', 'pasteHint'];

    static values = {
        max: Number,
        maxFileBytes: Number,
        maxRequestBytes: Number,
        uploadUrl: { type: String, default: '' },
        uploadCsrfToken: { type: String, default: '' },
        reportUrl: { type: String, default: '' },
        texts: { type: Object, default: {} },
    };

    connect() {
        this.form = this.element.closest('form');
        this.entries = new Map();
        this.prepared = false;
        this.waiting = false;

        this.inputTarget.classList.add('d-none');
        this.addButtonTarget.hidden = false;

        // Pasting is a keyboard thing - no hint on a phone
        if (this.hasPasteHintTarget && window.matchMedia('(pointer: fine)').matches) {
            this.pasteHintTarget.hidden = false;
        }

        this.onSubmit = (event) => this.submitting(event);
        this.onPaste = (event) => this.pasted(event);
        this.onDragOver = (event) => this.draggedOver(event);
        this.onDragLeave = () => this.element.classList.remove(...DROP_CLASSES);
        this.onDrop = (event) => this.dropped(event);

        if (this.form) {
            this.form.addEventListener('submit', this.onSubmit);
            this.form.addEventListener('paste', this.onPaste);
            this.form.addEventListener('dragover', this.onDragOver);
            this.form.addEventListener('dragleave', this.onDragLeave);
            this.form.addEventListener('drop', this.onDrop);
        }

        this.sync();
    }

    disconnect() {
        if (this.form) {
            this.form.removeEventListener('submit', this.onSubmit);
            this.form.removeEventListener('paste', this.onPaste);
            this.form.removeEventListener('dragover', this.onDragOver);
            this.form.removeEventListener('dragleave', this.onDragLeave);
            this.form.removeEventListener('drop', this.onDrop);
        }

        for (const entry of this.entries.values()) {
            entry.upload?.abort();
            URL.revokeObjectURL(entry.url);
        }

        this.entries.clear();
    }

    choose() {
        this.inputTarget.click();
    }

    picked() {
        const files = [...(this.inputTarget.files || [])];

        this.inputTarget.value = '';
        this.addFiles(files);
    }

    pasted(event) {
        const data = event.clipboardData;
        const files = [...(data?.files || [])].filter((file) => file.type.startsWith('image/'));

        if (files.length === 0) {
            return;
        }

        // Copied together with text (a part of a web page): the text is pasted, the picture added as well
        if (!data.types.includes('text/plain')) {
            event.preventDefault();
        }

        this.addFiles(files);
    }

    draggedOver(event) {
        if (event.dataTransfer && [...event.dataTransfer.types].includes('Files')) {
            event.preventDefault();
            this.element.classList.add(...DROP_CLASSES);
        }
    }

    dropped(event) {
        if (!event.dataTransfer || event.dataTransfer.files.length === 0) {
            return;
        }

        event.preventDefault();
        this.element.classList.remove(...DROP_CLASSES);
        this.addFiles([...event.dataTransfer.files]);
    }

    addFiles(files) {
        this.showError('');

        for (const file of files) {
            if (!IMAGE_TYPES.includes(photoType(file))) {
                this.showError(this.textsValue.not_image || '');
                continue;
            }

            if (this.itemTargets.length >= this.maxValue) {
                this.showError(this.textsValue.too_many || '');
                break;
            }

            this.add(file);
        }

        this.sync();
    }

    add(file) {
        const item = document.createElement('li');
        item.className = 'feedback-screenshot position-relative border rounded overflow-hidden';
        item.setAttribute('data-feedback-screenshots-target', 'item');

        const image = document.createElement('img');
        image.alt = '';
        image.width = 80;
        image.height = 80;
        image.className = 'd-block object-fit-cover';

        const progress = document.createElement('div');
        progress.className = 'feedback-screenshot-progress position-absolute bottom-0 start-0 bg-primary';
        progress.style.height = '3px';
        progress.style.width = '0';

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn-close position-absolute top-0 end-0 m-1 bg-white rounded-circle p-1';
        remove.setAttribute('aria-label', this.textsValue.remove || '');
        remove.setAttribute('data-action', 'feedback-screenshots#remove');

        item.append(image, progress, remove);
        this.listTarget.append(item);

        const entry = { file: null, url: '', upload: null, item, progress };

        // Shrunk before anything goes out - a 2000 px JPEG, like every photo of the site
        entry.ready = compressedOrOriginal(file).then((prepared) => {
            if (!item.isConnected) {
                return;
            }

            entry.file = prepared;
            entry.url = URL.createObjectURL(prepared);
            image.src = entry.url;

            if (this.maxFileBytesValue > 0 && prepared.size > this.maxFileBytesValue) {
                this.drop(item);
                this.showError(this.textsValue.too_large || '');
                return;
            }

            if (this.uploadUrlValue !== '') {
                entry.upload = new PhotoUpload(prepared, {
                    startUrl: this.uploadUrlValue,
                    csrfToken: this.uploadCsrfTokenValue,
                    reportUrl: this.reportUrlValue,
                    field: 'feedback',
                    onChange: () => this.uploadChanged(entry),
                });
            }
        });

        this.entries.set(item, entry);
    }

    remove(event) {
        const item = event.currentTarget.closest('li');

        if (item) {
            this.drop(item);
        }

        this.showError('');
        this.sync();
    }

    drop(item) {
        const entry = this.entries.get(item);

        if (entry) {
            entry.upload?.abort();
            URL.revokeObjectURL(entry.url);
            this.entries.delete(item);
        }

        item.remove();
        this.sync();
    }

    uploadChanged(entry) {
        const upload = entry.upload;

        entry.progress.style.width = upload.state === 'done' ? '100%' : `${upload.percent}%`;

        if (upload.state === 'done' && !entry.item.querySelector('input[type="hidden"]')) {
            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = 'photo_stash[screenshots][]';
            token.value = upload.token;
            entry.item.append(token);
            entry.progress.remove();
        }

        this.renderStatus();
    }

    /** One line under the pictures - a tile is too small for words */
    renderStatus() {
        if (!this.hasStatusTarget) {
            return;
        }

        const texts = this.textsValue;
        const uploads = [...this.entries.values()].map((entry) => entry.upload).filter(Boolean);
        const running = uploads.filter((upload) => upload.state === 'uploading');
        let line = '';

        if (running.some((upload) => upload.waitingUntil !== null)) {
            line = navigator.onLine === false ? (texts.waiting || '') : (texts.retrying || '');
        } else if (running.length > 0) {
            const loaded = running.reduce((sum, upload) => sum + upload.loaded, 0);
            const total = running.reduce((sum, upload) => sum + upload.total, 0);
            const percent = total > 0 ? Math.min(99, Math.floor((loaded / total) * 100)) : 0;
            const slow = running.some((upload) => upload.elapsedMs > 10000);

            line = ((slow ? texts.uploading_slow : texts.uploading) || '').replace('%percent%', String(percent));
        } else if (uploads.some((upload) => upload.state === 'failed')) {
            line = texts.with_form || '';
        }

        this.statusTarget.textContent = line;
    }

    sync() {
        this.addButtonTarget.disabled = this.itemTargets.length >= this.maxValue;
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = message === '';
    }

    submitting(event) {
        // The form sending itself after waiting for its uploads
        if (this.prepared) {
            this.prepared = false;
            this.attachFiles();
            return;
        }

        const entries = [...this.entries.values()];
        const pending = entries.some((entry) => !entry.file
            || (entry.upload && (entry.upload.state === 'uploading' || (entry.upload.state === 'failed' && entry.upload.retryable))));

        if (!pending) {
            this.attachFiles();
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        if (this.waiting) {
            return;
        }

        this.waiting = true;

        const submitter = event.submitter || null;
        const label = submitter ? submitter.value : '';

        if (submitter) {
            submitter.disabled = true;
            submitter.value = this.textsValue.sending || label;
        }

        // An upload that gives up goes with the form - Send waits for each to finish either way
        Promise.all(entries.map(async (entry) => {
            await entry.ready;

            if (entry.upload) {
                entry.upload.resumeForSubmit();
                await entry.upload.done;
            }
        })).then(() => {
            this.waiting = false;

            if (submitter) {
                submitter.disabled = false;
                submitter.value = label;
            }

            if (!this.form || !this.form.isConnected) {
                return;
            }

            this.prepared = true;
            this.form.requestSubmit(submitter && submitter.isConnected ? submitter : undefined);
        });
    }

    /** Pictures without a token (not uploaded in the background) go with the form as files, as many as one request takes */
    attachFiles() {
        if (typeof DataTransfer === 'undefined') {
            return;
        }

        const transfer = new DataTransfer();
        let bytes = 0;

        for (const entry of this.entries.values()) {
            if (!entry.file || !entry.item.isConnected || entry.item.querySelector('input[type="hidden"]')) {
                continue;
            }

            if (this.maxRequestBytesValue > 0 && bytes + entry.file.size > this.maxRequestBytesValue) {
                continue;
            }

            bytes += entry.file.size;
            transfer.items.add(entry.file);
        }

        try {
            this.inputTarget.files = transfer.files;
        } catch (error) {
            // A browser that cannot set files sends the message without them - never a lost message
        }
    }
}
