import { Controller } from '@hotwired/stimulus';
import { compressImage, shouldCompress } from '../image_compression.js';
import { PhotoUpload, reportPhotoUpload } from '../photo_upload.js';

/**
 * Locks a form's submit button while it saves, shrinks photos in the browser, and - where the form opts in with an
 * upload URL (the add/edit-time form) - uploads every photo in the background the moment it is picked, so the save
 * itself carries only small tokens (docs/features/photo-uploads/README.md):
 *
 * - picked → shrunk (2000 px, JPEG 0.85 - assets/image_compression.js) → uploaded straight to object storage, with a
 *   status line under the photo (progress, slow connection, waiting for the connection, uploaded, goes with the form)
 * - Save while a photo is still uploading waits for it, with the progress on the button
 * - a photo whose upload could not work is sent with the form, as before
 * - a save that never got an answer (dropped connection, the edge's plain-text 502/504) is tried once more by itself
 *   when it carried no photo bytes (time_id / new_puzzle_id make a resend safe), then said out loud
 */
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
        saveFailedText: { type: String, default: '' },
        // Background uploads (PhotoUploadStartController) - empty = photos go with the form
        uploadUrl: { type: String, default: '' },
        uploadCsrfToken: { type: String, default: '' },
        reportUrl: { type: String, default: '' },
        uploadTexts: { type: Object, default: {} },
        uploadingText: { type: String, default: 'Uploading photos... %percent% %' },
        retryingText: { type: String, default: 'Saving...' },
        stillSavingText: { type: String, default: '' },
    };

    static AUTO_RETRY_DELAY_MS = 2500;
    static STILL_SAVING_AFTER_MS = 20000;
    static SLOW_UPLOAD_AFTER_MS = 10000;

    connect() {
        this.isSubmittingValue = false;
        this.processedFiles = new WeakSet();
        this.originalLabelHtml = null;
        // input → {file, upload} of the photo it holds (background uploads)
        this.uploads = new Map();
        this.disabledInputs = new Set();
        this.prepared = false;
        this.autoRetried = false;
        this.sending = null;

        this.boundPrevent = this.preventDuplicateSubmission.bind(this);
        this.boundSubmitEnd = this.submitEnded.bind(this);
        this.boundPhotoChosen = this.photoChosen.bind(this);

        this.element.addEventListener('submit', this.boundPrevent);
        this.element.addEventListener('turbo:submit-end', this.boundSubmitEnd);

        if (this.uploadUrlValue !== '') {
            this.element.addEventListener('change', this.boundPhotoChosen);
        }
    }

    disconnect() {
        this.element.removeEventListener('submit', this.boundPrevent);
        this.element.removeEventListener('turbo:submit-end', this.boundSubmitEnd);
        this.element.removeEventListener('change', this.boundPhotoChosen);
        window.clearTimeout(this.stillSavingTimer);

        // The form is gone (saved, or replaced by the refused one - whose photos the server kept)
        for (const { upload } of this.uploads.values()) {
            upload.abort();
        }
        this.uploads.clear();
    }

    // ------------------------------------------------------------------ background uploads

    async photoChosen(event) {
        const input = event.target;

        if (!(input instanceof HTMLInputElement) || !input.classList.contains('file-drop-input')) {
            return;
        }

        const previous = this.uploads.get(input) || null;
        if (previous) {
            previous.upload.abort();
            this.uploads.delete(input);
        }

        const file = input.files && input.files[0];

        if (!file) {
            this.renderUploadStatus(input, null);
            return;
        }

        let prepared = file;

        // Shrunk now rather than on Save - the same 2000 px / JPEG 0.85 as before
        if (this.compressImagesValue && shouldCompress(file) && !this.processedFiles.has(file)) {
            this.processedFiles.add(file);
            this.renderUploadStatus(input, null, this.uploadTextsValue.preparing || '');

            try {
                const compressed = await compressImage(file);

                if (compressed.size < file.size) {
                    prepared = compressed;
                }
            } catch (error) {
                // A browser that cannot decode it (some HEIC) uploads the original - the server shrinks it
            }

            // Another photo was picked (or the editor applied) meanwhile - that one has its own upload
            if (!input.files || input.files[0] !== file) {
                return;
            }

            if (prepared !== file) {
                this.processedFiles.add(prepared);
                const transfer = new DataTransfer();
                transfer.items.add(prepared);
                input.files = transfer.files;
            }
        }

        // Too large for the server either way - Save takes it out with a note (removeOversizedPhotos)
        if (this.maxPhotoBytesValue > 0 && prepared.size > this.maxPhotoBytesValue) {
            this.renderUploadStatus(input, null);
            return;
        }

        const upload = new PhotoUpload(prepared, {
            startUrl: this.uploadUrlValue,
            csrfToken: this.uploadCsrfTokenValue,
            reportUrl: this.reportUrlValue,
            field: this.fieldOf(input),
            replaces: previous ? previous.upload.token : null,
            onChange: (changed) => this.uploadChanged(input, changed),
        });

        this.uploads.set(input, { file: prepared, upload });
    }

    uploadChanged(input, upload) {
        const entry = this.uploads.get(input);

        if (!entry || entry.upload !== upload) {
            return;
        }

        this.renderUploadStatus(input, upload);

        if (this.waitingForUploads) {
            this.showUploadingState();
        }
    }

    renderUploadStatus(input, upload, text = null) {
        const anchor = input.closest('.file-drop-area') || input;
        let status = anchor.parentElement.querySelector(':scope > [data-photo-upload-status]');

        if (upload === null && text === null) {
            status?.remove();
            return;
        }

        if (!status) {
            status = document.createElement('div');
            status.className = 'small mt-2 mb-2';
            status.setAttribute('data-photo-upload-status', '');
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            anchor.after(status);
        }

        const texts = this.uploadTextsValue;
        let line = text ?? '';
        let tone = 'text-muted';
        let progress = null;

        if (upload !== null) {
            const percent = String(upload.percent);

            if (upload.state === 'done') {
                line = texts.done || '';
                tone = 'text-success';
            } else if (upload.state === 'failed') {
                line = texts.with_form || '';
            } else if (upload.waitingUntil !== null) {
                line = navigator.onLine === false ? (texts.waiting || '') : (texts.retrying || '');
                tone = 'text-warning-emphasis';
            } else {
                const slow = upload.elapsedMs > this.constructor.SLOW_UPLOAD_AFTER_MS;
                line = (slow ? texts.uploading_slow : texts.uploading) || '';
                line = line.replace('%percent%', percent);
                progress = upload.percent;
            }
        }

        status.className = 'small mt-2 mb-2 ' + tone;
        status.textContent = line;

        if (progress !== null) {
            const bar = document.createElement('div');
            bar.className = 'progress mt-1';
            bar.style.height = '4px';
            bar.setAttribute('aria-hidden', 'true');
            const fill = document.createElement('div');
            fill.className = 'progress-bar';
            fill.style.width = progress + '%';
            bar.append(fill);
            status.append(bar);
        }
    }

    /** "puzzlePhoto" of `puzzle_add_form[puzzlePhoto]` - the name FormPhotoStash knows the field by */
    fieldOf(input) {
        const match = /\[([^\][]+)\]$/.exec(input.name || '');

        return match ? match[1] : (input.name || '');
    }

    /** The hidden `photo_stash[<field>]` the form sends the token in - the kept photo's own, or one of ours */
    tokenInput(input) {
        const name = 'photo_stash[' + this.fieldOf(input) + ']';
        const existing = [...this.element.querySelectorAll('input[type="hidden"]')].find(hidden => hidden.name === name);

        if (existing) {
            return existing;
        }

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = name;
        hidden.setAttribute('data-photo-upload-token', '');
        (input.closest('.file-drop-area') || input.parentElement).append(hidden);

        return hidden;
    }

    /** Uploads of photos still in their input: those Save has to wait for, resumed when they gave up on the connection */
    pendingUploads() {
        const pending = [];

        for (const [input, entry] of this.uploads) {
            if (!input.isConnected || !input.files || input.files[0] !== entry.file) {
                continue;
            }

            const { upload } = entry;

            if (upload.state === 'uploading' || (upload.state === 'failed' && upload.retryable && !entry.resumed)) {
                pending.push(entry);
            }
        }

        return pending;
    }

    /**
     * Right before the request: an uploaded photo goes as its token (its file input left out of the request), every
     * other photo goes with the form as a file
     */
    applyUploads() {
        let direct = 0;
        let inForm = 0;

        for (const input of this.element.querySelectorAll('.file-drop-input')) {
            const entry = this.uploads.get(input);
            const current = input.files && input.files[0];

            if (entry && current === entry.file && entry.upload.state === 'done' && entry.upload.token) {
                this.tokenInput(input).value = entry.upload.token;
                input.disabled = true;
                this.disabledInputs.add(input);
                direct += 1;
                continue;
            }

            if (entry) {
                const hidden = this.tokenInput(input);

                // A token of ours must not stand in for a photo that goes as a file now
                if (hidden.hasAttribute('data-photo-upload-token')) {
                    hidden.value = '';
                }
            }

            if (current) {
                inForm += 1;
            }
        }

        return { direct, inForm };
    }

    enableFileInputs() {
        for (const input of this.disabledInputs) {
            input.disabled = false;
        }
        this.disabledInputs.clear();
    }

    // ------------------------------------------------------------------ the submit

    // Turbo fires submit-end as soon as the answer's headers arrive. A successful full-page submit answers with a
    // redirect and Turbo is about to render the next page - unlocking now would let a second tap send the form
    // again while the first one is already saved (docs/features/duplicate-results.md). So the button stays locked
    // until the new page replaces the form. Everything else unlocks: a refused form (422 - Turbo renders it, the
    // new form element starts unlocked anyway), a dropped connection, and modal/frame submits answered with a
    // stream or frame content, where the form stays on the page.
    submitEnded(event) {
        const { success, fetchResponse, error } = event.detail ?? {};
        const status = fetchResponse ? fetchResponse.statusCode : 0;

        window.clearTimeout(this.stillSavingTimer);
        this.removeStillSaving();

        if (success && fetchResponse?.redirected) {
            this.reportSave('saved', status);
            // Turbo has just re-enabled the button it was submitted with - disable it again
            this.disableSubmitButton();
            return;
        }

        // Turbo renders a failed answer only when it is HTML. A dropped connection (error) or the edge giving up on a
        // slow upload (Traefik's plain-text 502/504 after 60 s) leaves the form as it was without a word - the player
        // saw the progress bar fill and nothing happen (2026-10-14, 8 tries on a ~10 KB/s upload)
        const lost = Boolean(error) || (fetchResponse && success === false && !fetchResponse.isHTML);

        // Once more by itself, when the request was small (no photo bytes) and the answer simply never came - a deploy's
        // moment, a connection that dropped and came back. A resend of a saved form is answered as that result
        if (lost && !this.autoRetried && this.sending && this.sending.inForm === 0 && (error || [0, 502, 503, 504].includes(status))) {
            this.autoRetried = true;
            this.sending.retried = true;
            this.showLabel(this.retryingTextValue);

            window.setTimeout(() => {
                if (!this.element.isConnected) {
                    return;
                }

                this.isSubmittingValue = false;
                this.prepared = true;
                this.element.requestSubmit();
            }, this.constructor.AUTO_RETRY_DELAY_MS);

            return;
        }

        this.reportSave(success ? 'saved' : (lost ? 'failed' : 'refused'), status);
        this.reset();

        if (lost) {
            this.showSaveFailed();
        }
    }

    reportSave(outcome, status) {
        const sending = this.sending;
        this.sending = null;

        if (!sending || this.reportUrlValue === '') {
            return;
        }

        // Every lost save; the others only when photos were part of it
        if (outcome !== 'failed' && sending.direct === 0 && sending.inForm === 0) {
            return;
        }

        reportPhotoUpload(this.reportUrlValue, {
            event: 'save',
            outcome,
            status,
            durationMs: Date.now() - sending.startedAt,
            waitedMs: sending.waitedMs,
            photosDirect: sending.direct,
            photosInForm: sending.inForm,
            retried: sending.retried,
        });
    }

    showSaveFailed() {
        if (this.saveFailedTextValue === '') {
            return;
        }

        this.removeSaveFailed();

        const note = document.createElement('div');
        note.className = 'alert alert-danger mb-3';
        note.setAttribute('data-save-failed', '');
        note.setAttribute('role', 'alert');
        note.textContent = this.saveFailedTextValue;
        this.submitTarget.before(note);
        note.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    removeSaveFailed() {
        this.element.querySelectorAll('[data-save-failed]').forEach(note => note.remove());
    }

    showStillSaving() {
        if (this.stillSavingTextValue === '' || this.element.querySelector('[data-still-saving]')) {
            return;
        }

        const note = document.createElement('div');
        note.className = 'alert alert-info mb-3';
        note.setAttribute('data-still-saving', '');
        note.setAttribute('role', 'status');
        note.textContent = this.stillSavingTextValue;
        this.submitTarget.before(note);
    }

    removeStillSaving() {
        this.element.querySelectorAll('[data-still-saving]').forEach(note => note.remove());
    }

    preventDuplicateSubmission(event) {
        if (this.isSubmittingValue) {
            event.preventDefault();
            return;
        }

        // A tap of the player (not the form sending itself after waiting for its photos, nor the automatic retry)
        if (!this.prepared) {
            this.removeSaveFailed();
            this.autoRetried = false;
            this.waitedMs = 0;

            const fileInputs = this.element.querySelectorAll('.file-drop-input');
            const filesToCompress = this.compressImagesValue ? this.findFilesToCompress(fileInputs) : [];
            const uploads = this.pendingUploads();

            if (filesToCompress.length > 0 || uploads.length > 0) {
                event.preventDefault();
                event.stopImmediatePropagation();

                this.isSubmittingValue = true;
                this.disableSubmitButton();

                this.prepare(filesToCompress, uploads).then(() => {
                    if (!this.element.isConnected) {
                        return;
                    }

                    this.isSubmittingValue = false;
                    this.prepared = true;
                    this.element.requestSubmit();
                });

                return;
            }
        }

        const retry = this.prepared && this.sending !== null && this.sending.retried;
        this.prepared = false;

        const counts = this.applyUploads();

        if (this.removeOversizedPhotos()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.reset();

            return;
        }

        if (!retry) {
            this.sending = { ...counts, startedAt: Date.now(), waitedMs: this.waitedMs || 0, retried: false };
        }

        this.isSubmittingValue = true;
        this.disableSubmitButton();

        if (!retry) {
            this.showSavingState();
        }

        window.clearTimeout(this.stillSavingTimer);
        this.stillSavingTimer = window.setTimeout(() => this.showStillSaving(), this.constructor.STILL_SAVING_AFTER_MS);
    }

    async prepare(filesToCompress, uploads) {
        if (filesToCompress.length > 0) {
            this.showCompressingState();
            await this.compressAllFiles(filesToCompress);
        }

        if (uploads.length > 0) {
            const startedAt = Date.now();

            for (const entry of uploads) {
                entry.resumed = true;
                entry.upload.resumeForSubmit();
            }

            this.waitingForUploads = true;
            this.showUploadingState();

            // An upload that gives up goes with the form - Save waits for each to finish either way
            await Promise.all(uploads.map(entry => entry.upload.done));

            this.waitingForUploads = false;
            this.waitedMs = Date.now() - startedAt;
        }

        this.showSavingState();
    }

    showUploadingState() {
        let loaded = 0;
        let total = 0;

        for (const [input, entry] of this.uploads) {
            if (!input.isConnected || !input.files || input.files[0] !== entry.file) {
                continue;
            }

            total += entry.upload.total;
            loaded += entry.upload.state === 'uploading' ? entry.upload.loaded : entry.upload.total;
        }

        const percent = total > 0 ? Math.min(99, Math.floor((loaded / total) * 100)) : 0;
        this.showLabel(this.uploadingTextValue.replace('%percent%', String(percent)));
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
            .filter(input => !input.disabled && input.files && input.files[0])
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
        this.uploads.get(input)?.upload.abort();
        this.uploads.delete(input);
        this.renderUploadStatus(input, null);

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
        this.prepared = false;
        this.waitingForUploads = false;
        window.clearTimeout(this.stillSavingTimer);
        this.removeStillSaving();
        this.enableFileInputs();
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

    showLabel(text) {
        if (this.hasLabelTarget) {
            if (this.originalLabelHtml === null) {
                this.originalLabelHtml = this.labelTarget.innerHTML;
            }
            this.labelTarget.textContent = text;
        }
    }

    showCompressingState() {
        this.showLabel(this.compressingTextValue);
    }

    showSavingState() {
        this.showLabel(this.savingTextValue);
    }

    restoreLabel() {
        if (this.hasLabelTarget && this.originalLabelHtml !== null) {
            this.labelTarget.innerHTML = this.originalLabelHtml;
            this.originalLabelHtml = null;
        }
    }
}
