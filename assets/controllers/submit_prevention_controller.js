import { Controller } from '@hotwired/stimulus';
import { compressImage, shouldCompress } from '../image_compression.js';
import { PhotoUpload, reportPhotoUpload } from '../photo_upload.js';
import { photoMetadataOf, readPhotoMetadata, rememberPhotoMetadata } from '../photo_metadata.js';
import { describeError, isEmptyPhoto, probePhoto, saveFailureKind } from '../photo_readability.js';

/**
 * Locks a form's submit button while it saves, shrinks photos in the browser, and - where the form opts in with an
 * upload URL (the add/edit-time form) - uploads every photo in the background the moment it is picked, so the save
 * itself carries only small tokens (docs/features/photo-uploads/README.md):
 *
 * - picked → shrunk (2000 px, JPEG 0.85 - assets/image_compression.js) → uploaded straight to object storage, with a
 *   status line under the photo (progress, slow connection, waiting for the connection, uploaded, goes with the form)
 * - Save while a photo is still uploading waits for it, with the progress on the button
 * - a photo whose upload could not work is sent with the form, as before
 * - what a shrunk (or edited) photo's EXIF said is read before the canvas drops it (assets/photo_metadata.js) and goes
 *   with the upload's start and as `photo_metadata[<field>]` with the form (docs/features/upload-image-metadata.md)
 * - a save that never got an answer (dropped connection, the edge's plain-text 502/504) is tried once more by itself
 *   when it carried no photo bytes (time_id / new_puzzle_id make a resend safe), then said out loud
 * - a photo the phone hands over empty, or that the browser cannot read, never blocks a save: it is taken out of the
 *   form with a note next to its field - when it is picked, when the upload's start calls it empty, and once more right
 *   before the request (assets/photo_readability.js, docs/features/photo-uploads/README.md "A photo the phone cannot
 *   read"). Every one of these checks is wrapped: should it fail, the form does what it did before
 * - a save that died at once with a photo in it is not blamed on the connection: "Save without the photo"
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
        // A photo the phone cannot read (assets/photo_readability.js)
        photoUnreadableText: { type: String, default: 'This photo could not be read from your device, so it is not attached. You can save without it, or pick it again.' },
        photoUnreadableRequiredText: { type: String, default: 'This photo could not be read from your device. Please pick it again.' },
        saveFailedPhotoText: { type: String, default: '' },
        saveFailedEitherText: { type: String, default: '' },
        saveWithoutPhotoText: { type: String, default: '' },
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
        // Photos taken out because the phone could not read them - told with every save report from then on
        this.photosDropped = 0;
        this.lastDropOn = null;

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

        // A photo of 0 bytes (a phone handing over a file it cannot read): never uploaded, never sent with the form
        if (this.dropIfEmpty(input, file, 'pick')) {
            return;
        }

        // Can the browser read it at all? Asked for one byte now, answered before the upload starts - never throws
        const probing = this.probe(file);

        let prepared = file;

        // Shrunk now rather than on Save - the same 2000 px / JPEG 0.85 as before
        if (this.compressImagesValue && shouldCompress(file) && !this.processedFiles.has(file)) {
            this.processedFiles.add(file);
            this.renderUploadStatus(input, null, this.uploadTextsValue.preparing || '');

            // Read before shrinking - the canvas drops every EXIF tag. Never fails (null = nothing to send)
            const reading = this.metadataOfOriginal(file);

            try {
                const compressed = await compressImage(file);

                if (compressed.size < file.size) {
                    prepared = compressed;
                }
            } catch (error) {
                // A browser that cannot decode it (some HEIC) uploads the original - the server shrinks it
            }

            const metadata = await reading;

            // Another photo was picked (or the editor applied) meanwhile - that one has its own upload
            if (!input.files || input.files[0] !== file) {
                return;
            }

            if (prepared !== file) {
                this.processedFiles.add(prepared);
                rememberPhotoMetadata(prepared, metadata);
                const transfer = new DataTransfer();
                transfer.items.add(prepared);
                input.files = transfer.files;
            }
        }

        // A photo the browser shrank was read; any other one waits for the one-byte read (bounded, PROBE_TIMEOUT_MS)
        if (prepared === file) {
            const probe = await probing;

            // Another photo was picked meanwhile - that one has its own check
            if (!input.files || input.files[0] !== file) {
                return;
            }

            if (probe.unreadable !== null && this.dropUnreadablePhoto(input, 'pick', probe.unreadable, probe.error, file)) {
                return;
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
            // Only for a photo the browser changed (shrunk, or edited) - a photo sent as picked has its own EXIF
            metadata: photoMetadataOf(prepared),
            onChange: (changed) => this.uploadChanged(input, changed),
        });

        this.uploads.set(input, { file: prepared, upload });
    }

    uploadChanged(input, upload) {
        const entry = this.uploads.get(input);

        if (!entry || entry.upload !== upload) {
            return;
        }

        // The server called it empty: sending it with the form instead would only make the save die in the browser
        if (upload.state === 'failed' && upload.refusal === 'empty' && this.dropUnreadablePhoto(input, 'start', 'empty', null, entry.file)) {
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

    /** The photo as picked - or, for the image editor's result, the photo it was made from (photo_metadata.js) */
    metadataOfOriginal(file) {
        const carried = photoMetadataOf(file);

        return carried ? Promise.resolve(carried) : readPhotoMetadata(file);
    }

    /**
     * `photo_metadata[<field>]` with the form: what the browser read from the photo it sends before shrinking or
     * editing it - none for a photo sent as picked (the server reads its EXIF itself) or one it could not read
     */
    syncMetadataInput(input) {
        const current = input.files && input.files[0];
        const metadata = current ? photoMetadataOf(current) : null;
        const name = 'photo_metadata[' + this.fieldOf(input) + ']';
        let hidden = [...this.element.querySelectorAll('input[type="hidden"]')].find(element => element.name === name);

        if (!metadata) {
            hidden?.remove();
            return;
        }

        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = name;
            (input.closest('.file-drop-area') || input.parentElement).append(hidden);
        }

        hidden.value = JSON.stringify(metadata);
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

            this.syncMetadataInput(input);

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

    // ------------------------------------------------------------------ a photo the phone cannot read

    /** Never rejects, never waits longer than the probe's own timeout - "readable" when the check itself fails */
    probe(file) {
        try {
            return probePhoto(file).catch(() => ({ unreadable: null, error: null }));
        } catch (error) {
            return Promise.resolve({ unreadable: null, error: null });
        }
    }

    /** true when the photo was taken out */
    dropIfEmpty(input, file, detectedOn) {
        try {
            return isEmptyPhoto(file) && this.dropUnreadablePhoto(input, detectedOn, 'empty', null, file);
        } catch (error) {
            return false;
        }
    }

    /**
     * Takes the photo out of its field - it is neither uploaded nor sent with the form - and says so next to the field.
     * The form stays as it is otherwise; Save works without the photo.
     *
     * @param {string} detectedOn `pick`, `start` (the upload's start called it empty), `save` (right before the
     *        request), `save_without` (the player's "Save without the photo")
     * @returns {boolean} false when it could not be done - the caller then goes on as before
     */
    dropUnreadablePhoto(input, detectedOn, reason, errorText = null, file = null) {
        try {
            const field = this.fieldOf(input);

            input.value = '';

            // A browser that keeps the file after `value = ''` - the photo is still in the form: not taken out
            if (input.files && input.files.length > 0) {
                return false;
            }

            this.uploads.get(input)?.upload.abort();
            this.uploads.delete(input);
            this.photosDropped += 1;
            this.lastDropOn = detectedOn;

            try {
                this.forgetUploadedToken(input);
                this.renderUploadStatus(input, null);
                this.showDropAreaEmpty(input);

                if (detectedOn !== 'save_without') {
                    this.showPhotoUnreadable(input, field);
                }
            } catch (error) {
                // The photo is out of the form - that is what matters
            }

            reportPhotoUpload(this.reportUrlValue, {
                event: 'photo_dropped',
                reason,
                detectedOn,
                field,
                bytes: file && typeof file.size === 'number' ? file.size : undefined,
                error: errorText || undefined,
            });

            return true;
        } catch (error) {
            return false;
        }
    }

    /** A token of ours from an earlier try of this form must not bring back the photo this one replaced */
    forgetUploadedToken(input) {
        const name = 'photo_stash[' + this.fieldOf(input) + ']';

        for (const hidden of this.element.querySelectorAll('input[type="hidden"][data-photo-upload-token]')) {
            if (hidden.name === name) {
                hidden.value = '';
            }
        }
    }

    showDropAreaEmpty(input) {
        const area = input.closest('.file-drop-area');

        if (!area) {
            return;
        }

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

    showPhotoUnreadable(input, field) {
        const anchor = input.closest('.file-drop-area') ?? input;
        // A new puzzle needs its box photo - there "save without it" would promise too much
        const text = field === 'puzzlePhoto' ? this.photoUnreadableRequiredTextValue : this.photoUnreadableTextValue;

        anchor.parentElement.querySelectorAll('[data-photo-unreadable], [data-photo-too-large]').forEach(note => note.remove());

        if (text === '') {
            return;
        }

        const note = document.createElement('div');
        note.className = 'alert alert-warning small py-2 px-3 mb-2';
        note.setAttribute('data-photo-unreadable', '');
        note.setAttribute('role', 'alert');
        note.textContent = text;
        anchor.before(note);

        // Picking another photo takes the note away
        input.addEventListener('change', () => note.remove(), { once: true });
    }

    /** Photos that would travel inside the request as files: every picked one that was not uploaded in the background */
    photosGoingWithForm() {
        const photos = [];

        for (const input of this.element.querySelectorAll('.file-drop-input')) {
            const file = input.files && input.files[0];

            if (!file || input.disabled) {
                continue;
            }

            const entry = this.uploads.get(input);

            if (entry && entry.file === file && entry.upload.state === 'done' && entry.upload.token) {
                continue;
            }

            photos.push({ input, file });
        }

        return photos;
    }

    countPhotosGoingWithForm() {
        try {
            return this.photosGoingWithForm().length;
        } catch (error) {
            return 0;
        }
    }

    /** Right before the request, without waiting: a photo of 0 bytes still in the form (an old page, another path) */
    dropEmptyPhotos(detectedOn) {
        try {
            for (const { input, file } of this.photosGoingWithForm()) {
                this.dropIfEmpty(input, file, detectedOn);
            }
        } catch (error) {
            // As before: the photo goes with the form
        }
    }

    /** The same for a photo the browser can no longer read - one byte asked of each, all at once, bounded */
    async dropUnreadablePhotos(detectedOn) {
        try {
            const photos = this.photosGoingWithForm();
            const probes = await Promise.all(photos.map(photo => this.probe(photo.file)));

            photos.forEach(({ input, file }, index) => {
                const probe = probes[index];

                if (probe && probe.unreadable !== null && input.files && input.files[0] === file) {
                    this.dropUnreadablePhoto(input, detectedOn, probe.unreadable, probe.error, file);
                }
            });
        } catch (error) {
            // As before: the photo goes with the form
        }
    }

    /** "Save without the photo": every photo that would travel as a file is taken out, then the form is sent */
    saveWithoutPhotos(event) {
        event?.preventDefault();

        try {
            for (const { input, file } of this.photosGoingWithForm()) {
                this.dropUnreadablePhoto(input, 'save_without', 'not_sent', null, file);
            }
        } catch (error) {
            // Sent as it is then
        }

        this.removeSaveFailed();

        if (!this.isSubmittingValue) {
            this.element.requestSubmit();
        }
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
        // A full-page submit answered 200 without a redirect: Turbo drops the answer and leaves no result at all (no
        // success, no response, no error) - the player tapped Save and nothing happened (2026-11-07..09, the form
        // posted to the page before). Lost too, but sending it again to the same place would not help
        const discarded = success === undefined && !fetchResponse && !error;
        const lost = discarded || Boolean(error) || (fetchResponse && success === false && !fetchResponse.isHTML);

        // Once more by itself, when the request was small (no photo bytes) and the answer simply never came - a deploy's
        // moment, a connection that dropped and came back. A resend of a saved form is answered as that result
        if (lost && !discarded && !this.autoRetried && this.sending && this.sending.inForm === 0 && (error || [0, 502, 503, 504].includes(status))) {
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

        // Not every lost save is the connection: one that died at once with a photo in it never left the browser
        const failure = lost ? this.failureKind(status, discarded) : null;

        this.reportSave(success ? 'saved' : (lost ? 'failed' : 'refused'), status, discarded ? 'discarded' : null, { error, failure });
        this.reset();

        if (lost) {
            this.showSaveFailed(failure);
        }
    }

    /** `photo` / `either` / `network` (assets/photo_readability.js) - `network`, the old message, when it cannot tell */
    failureKind(status, discarded) {
        try {
            const sending = this.sending;

            if (!sending) {
                return 'network';
            }

            return saveFailureKind({
                status,
                durationMs: Date.now() - sending.startedAt,
                online: navigator.onLine,
                photosInForm: sending.inForm,
                discarded,
            });
        } catch (error) {
            return 'network';
        }
    }

    reportSave(outcome, status, reason = null, details = {}) {
        const sending = this.sending;
        this.sending = null;

        if (!sending || this.reportUrlValue === '') {
            return;
        }

        // Every lost save; the others only when photos were part of it - or were taken out as unreadable
        if (outcome !== 'failed' && sending.direct === 0 && sending.inForm === 0 && !(sending.dropped > 0)) {
            return;
        }

        reportPhotoUpload(this.reportUrlValue, {
            event: 'save',
            outcome,
            reason,
            status,
            durationMs: Date.now() - sending.startedAt,
            waitedMs: sending.waitedMs,
            photosDirect: sending.direct,
            photosInForm: sending.inForm,
            retried: sending.retried,
            // The browser's own words for a lost save ("TypeError: Failed to fetch"), and which message it got
            error: outcome === 'failed' ? (describeError(details.error) || undefined) : undefined,
            failure: details.failure || undefined,
            // Photos taken out of this form because the phone could not read them, and where the last one was noticed
            photosDropped: sending.dropped > 0 ? sending.dropped : undefined,
            droppedOn: sending.dropped > 0 ? (sending.droppedOn || undefined) : undefined,
        });
    }

    /**
     * @param {null|string} failure `photo` / `either` = a photo in the request may be what stopped it: another text and
     *        "Save without the photo". Anything else, or a text missing: the message as it always was
     */
    showSaveFailed(failure = null) {
        let text = this.saveFailedTextValue;
        let withoutPhoto = false;

        try {
            const other = failure === 'photo' ? this.saveFailedPhotoTextValue : (failure === 'either' ? this.saveFailedEitherTextValue : '');

            if (other !== '' && this.saveWithoutPhotoTextValue !== '' && this.countPhotosGoingWithForm() > 0) {
                text = other;
                withoutPhoto = true;
            }
        } catch (error) {
            text = this.saveFailedTextValue;
            withoutPhoto = false;
        }

        if (text === '') {
            return;
        }

        this.removeSaveFailed();

        const note = document.createElement('div');
        note.className = 'alert alert-danger mb-3';
        note.setAttribute('data-save-failed', '');
        note.setAttribute('role', 'alert');
        note.textContent = text;

        if (withoutPhoto) {
            try {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-outline-danger btn-sm d-block mt-2';
                button.setAttribute('data-save-without-photo', '');
                button.textContent = this.saveWithoutPhotoTextValue;
                button.addEventListener('click', (event) => this.saveWithoutPhotos(event));
                note.append(button);
            } catch (error) {
                // The message alone then
            }
        }

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

            // A photo of 0 bytes still in the form would make the request die in the browser - out it goes, the save goes on
            this.dropEmptyPhotos('save');

            const fileInputs = this.element.querySelectorAll('.file-drop-input');
            const filesToCompress = this.compressImagesValue ? this.findFilesToCompress(fileInputs) : [];
            const uploads = this.pendingUploads();
            // Photos that travel inside the request are asked for one byte first (prepare) - none when every photo was
            // uploaded in the background, the usual case
            const photosToCheck = this.countPhotosGoingWithForm();

            if (filesToCompress.length > 0 || uploads.length > 0 || photosToCheck > 0) {
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
            this.sending = { ...counts, startedAt: Date.now(), waitedMs: this.waitedMs || 0, retried: false, dropped: this.photosDropped, droppedOn: this.lastDropOn };
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

        // What still goes with the form as a file (an upload that gave up included): can the browser read it? A photo
        // it cannot read is taken out with a note and the save goes on - never rejects, bounded by the probe's timeout
        this.showSavingState();
        await this.dropUnreadablePhotos('save');
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
            const reading = this.metadataOfOriginal(file);

            try {
                const compressedFile = await compressImage(file);

                if (compressedFile.size < file.size) {
                    this.processedFiles.add(compressedFile);
                    rememberPhotoMetadata(compressedFile, await reading);
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
