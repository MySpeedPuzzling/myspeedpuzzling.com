import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import { compressedOrOriginal } from '../image_compression.js';

/**
 * Bridge between the continuous barcode scanner and the MultiscanTray Live
 * Component (docs/features/multiscan/README.md §7):
 *  - every decoded code becomes a `scan` action, queued so fast scanning never drops one
 *  - a failed request is retried with backoff and never shown as "not found"
 *  - after each render the tray's outcome (found / duplicate / unknown …) drives
 *    feedback on the scanner and a mini-toast; the resolve sheet pauses the camera
 *  - unknown rows are rechecked when the tab comes back (the full add form opens in a new tab)
 *  - the tray is mirrored to sessionStorage and restored after a reload - on phones opening the
 *    camera for a box photo can make the browser drop the page
 *  - quick-add photos are kept here (shrunk, one per code), never only in the file input: a failed
 *    upload or a re-render must not make anybody retake the photo
 */
export default class extends Controller {
    static targets = ['tray', 'scanner', 'eanInput', 'typedRow', 'typedToggle'];

    static values = {
        checkFailedMessage: String,
        duplicateMessage: String,
        invalidMessage: String,
        fullMessage: String,
        recheckedMessage: String,
        forbiddenMessage: String,
        connectionLostMessage: String,
        restoredMessage: String,
        createFailedMessage: String,
        storageKey: String,
    };

    static STALL_MS = 12000;
    static CREATE_STALL_MS = 60000;
    static STORAGE_MAX_AGE_MS = 24 * 60 * 60 * 1000;

    async initialize() {
        this.queue = [];
        this.draining = false;
        this.inflight = null;
        this.lastNoticeSeq = null;
        this.photos = new Map();
        this.creating = false;

        this._boundScanned = (event) => this.onScanned(event);
        this._boundVisibility = () => this.onVisibilityChange();

        this.component = await getComponent(this.trayTarget);

        this.component.on('render:finished', () => this.onRendered());
        this.component.on('response:error', (backendResponse, controls) => this.onResponseError(backendResponse, controls));

        this.restoreFromStorage();
    }

    connect() {
        this.element.addEventListener('barcode-scanner:scanned', this._boundScanned);
        document.addEventListener('visibilitychange', this._boundVisibility);
    }

    disconnect() {
        this.element.removeEventListener('barcode-scanner:scanned', this._boundScanned);
        document.removeEventListener('visibilitychange', this._boundVisibility);
    }

    onScanned(event) {
        const code = event.detail && event.detail.code;
        if (!code) {
            return;
        }

        this.enqueue(String(code));
    }

    toggleTyped(event) {
        if (event) {
            event.preventDefault();
        }

        if (!this.hasTypedRowTarget) {
            return;
        }

        const show = this.typedRowTarget.classList.contains('d-none');
        this.typedRowTarget.classList.toggle('d-none', !show);

        if (this.hasTypedToggleTarget) {
            this.typedToggleTarget.classList.toggle('active', show);
            this.typedToggleTarget.setAttribute('aria-expanded', show ? 'true' : 'false');
        }

        if (show && this.hasEanInputTarget) {
            this.eanInputTarget.focus();
        }
    }

    /**
     * Storage form of a code (leading zeros dropped, UPC-A / zero-indicator GTIN-14 folded into
     * EAN-13), mirroring Value\Ean::normalized() - enough to recognise a code already in the tray
     * without a round trip. Anything odd still goes to the server, which validates properly.
     */
    normalize(ean) {
        let digits = String(ean).replace(/\D+/g, '');
        if (digits.length === 12) {
            digits = '0' + digits;
        } else if (digits.length === 14 && digits.startsWith('0')) {
            digits = digits.substring(1);
        }

        return digits.replace(/^0+/, '');
    }

    /**
     * Codes currently in the tray, read from the last render (no request needed to know them)
     */
    trayCodes() {
        const raw = this.trayTarget.getAttribute('data-multiscan-eans') || '';

        return new Map(raw.split(' ').filter(Boolean).map((ean) => [this.normalize(ean), ean]));
    }

    alreadyInTray(ean) {
        const rowEan = this.trayCodes().get(this.normalize(ean));

        if (rowEan === undefined) {
            return false;
        }

        // Friendly, not an error: the row it already has pulses, a short buzz and note
        const row = document.getElementById('multiscan-row-' + rowEan);
        if (row) {
            row.classList.remove('is-pulse');
            void row.offsetWidth;
            row.classList.add('is-pulse');
            window.setTimeout(() => row.classList.remove('is-pulse'), 1200);
            const name = row.querySelector('.fw-medium');
            this.toast(this.duplicateMessageValue.replace('%name%', name ? name.textContent.trim() : ean), 'info', 1800);
        } else {
            this.toast(this.duplicateMessageValue.replace('%name%', ean), 'info', 1800);
        }

        this.scannerFeedback('duplicate');

        return true;
    }

    submitTyped(event) {
        if (event) {
            event.preventDefault();
        }

        if (!this.hasEanInputTarget) {
            return;
        }

        const value = this.eanInputTarget.value.trim();
        if (value === '') {
            return;
        }

        this.eanInputTarget.value = '';
        this.enqueue(value);
    }

    enqueue(ean) {
        // A code already in the tray, or already waiting in the queue, never costs a request
        if (this.alreadyInTray(ean)) {
            return;
        }

        const normalized = this.normalize(ean);
        if (this.queue.some((job) => this.normalize(job.ean) === normalized) || (this.inflight && this.normalize(this.inflight.ean) === normalized)) {
            this.scannerFeedback('duplicate');
            return;
        }

        this.queue.push({ ean, attempts: 0 });
        this.drain();
    }

    async drain() {
        if (this.draining) {
            return;
        }

        this.draining = true;

        try {
            while (this.queue.length > 0) {
                const job = this.queue.shift();
                this.inflight = job;
                const component = this.component || await getComponent(this.trayTarget);

                try {
                    // A failed fetch (connection dropped) leaves the Live Component with a request
                    // pending forever and every later action silently queued behind it. Do not hang
                    // the queue on it: tell the person to reload instead of scanning into the void.
                    await Promise.race([
                        component.action('scan', { ean: job.ean }),
                        new Promise((_, reject) => window.setTimeout(() => reject(new Error('stall')), this.constructor.STALL_MS)),
                    ]);
                } catch (error) {
                    if (error && error.message === 'stall') {
                        this.queue = [];
                        this.toast(this.connectionLostMessageValue, 'error', 8000);
                        this.scannerFeedback('error');
                    }
                    // HTTP errors are handled in onResponseError (retry); a rejection must not stop the queue
                }

                this.inflight = null;
            }
        } finally {
            this.draining = false;
        }
    }

    onResponseError(backendResponse, controls) {
        // Never render the framework's error modal on a scanning page
        controls.displayError = false;

        const status = backendResponse && backendResponse.response ? backendResponse.response.status : 0;

        if (this.creating) {
            // The tray and the photo are untouched - "Add puzzle" simply works again
            this.toast(this.createFailedMessageValue, 'error', 6000);
            return;
        }

        if (status === 403) {
            this.toast(this.forbiddenMessageValue, 'error');
            this.queue = [];
            return;
        }

        const job = this.inflight;

        if (job && job.attempts < 3) {
            job.attempts += 1;
            const delay = 500 * Math.pow(2, job.attempts - 1);
            // Put it back in front of the queue after a short backoff - the code is never lost
            window.setTimeout(() => {
                this.queue.unshift(job);
                this.drain();
            }, delay);
            return;
        }

        this.toast(this.checkFailedMessageValue, 'error');
        this.scannerFeedback('error');
    }

    onRendered() {
        this.saveToStorage();
        this.syncPhotos();

        const tray = this.trayTarget;
        const notice = tray.getAttribute('data-multiscan-notice') || '';
        const ean = tray.getAttribute('data-multiscan-notice-ean') || '';
        const name = tray.getAttribute('data-multiscan-notice-name') || '';
        const sheetOpen = tray.getAttribute('data-multiscan-sheet-open') === '1';

        if (sheetOpen) {
            this.scannerPause();
        } else {
            this.scannerResume();
        }

        if (notice === '') {
            return;
        }

        // Model updates re-render with the last notice still set: the server bumps a sequence
        // number for every new notice, so each one is reacted to exactly once
        const seq = tray.getAttribute('data-multiscan-notice-seq') || '';
        if (seq === this.lastNoticeSeq) {
            return;
        }
        this.lastNoticeSeq = seq;

        switch (notice) {
            case 'found':
            case 'chosen':
            case 'linked':
            case 'created':
                this.forgetPhoto(ean);
                this.scannerFeedback('found');
                this.highlightRow(ean);
                break;
            case 'duplicate':
                this.scannerFeedback('duplicate');
                this.toast(this.duplicateMessageValue.replace('%name%', name || ean), 'info', 1800);
                break;
            case 'unknown':
            case 'ambiguous':
                this.scannerFeedback('unknown');
                break;
            case 'invalid':
                this.scannerFeedback('invalid');
                this.toast(this.invalidMessageValue.replace('%ean%', ean), 'error', 2500);
                break;
            case 'full':
                this.toast(this.fullMessageValue, 'error', 2500);
                break;
            case 'rechecked':
                this.toast(this.recheckedMessageValue.replace('%count%', name), 'success', 2500);
                break;
            case 'restored':
                this.toast(this.restoredMessageValue.replace('%count%', name), 'success', 3500);
                break;
            default:
                break;
        }
    }

    /**
     * The freshly added row is the success signal: green sweep, and on screen even on a phone
     */
    highlightRow(ean) {
        const row = document.getElementById('multiscan-row-' + ean);
        if (!row) {
            return;
        }

        row.classList.remove('is-added');
        void row.offsetWidth;
        row.classList.add('is-added');
        window.setTimeout(() => row.classList.remove('is-added'), 1700);

        const rect = row.getBoundingClientRect();
        if (rect.top < 0 || rect.bottom > window.innerHeight - 120) {
            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    onVisibilityChange() {
        if (document.visibilityState !== 'visible' || !this.component) {
            return;
        }

        const unknown = parseInt(this.trayTarget.getAttribute('data-multiscan-unknown-count') || '0', 10);
        if (unknown > 0) {
            this.component.action('recheckUnknown');
        }
    }

    // ------------------------------------------------------------------ quick-add photo

    async photoPicked(event) {
        const input = event.currentTarget;
        const file = input.files && input.files[0];
        const box = input.closest('.multiscan-photo');
        const ean = box ? box.getAttribute('data-multiscan-photo-for') : '';

        if (!file || !ean) {
            return;
        }

        this.renderPhoto(box, null, true);
        const prepared = await compressedOrOriginal(file);
        input.value = '';

        this.forgetPhoto(ean);
        this.photos.set(ean, { file: prepared, url: URL.createObjectURL(prepared) });
        this.renderPhoto(box, this.photos.get(ean), false);
    }

    async createPuzzle(event) {
        event.preventDefault();

        if (this.creating || !this.component) {
            return;
        }

        const ean = this.trayTarget.getAttribute('data-multiscan-sheet-ean') || '';
        const photo = this.photos.get(ean);

        if (!photo) {
            // No photo yet: the button takes it - the camera opens, nothing is sent
            const input = this.trayTarget.querySelector('.multiscan-photo input[type="file"]');
            if (input) {
                input.click();
            }
            return;
        }

        // A throw-away input per attempt: the Live Component empties the input it uploaded from after
        // every answer, even a failed one - the photo itself stays here for the next try
        const upload = document.createElement('input');
        upload.type = 'file';
        const transfer = new DataTransfer();
        transfer.items.add(photo.file);
        upload.files = transfer.files;

        this.creating = true;
        const button = event.currentTarget;
        button.disabled = true;
        this.component.files('photo', upload);

        try {
            await Promise.race([
                this.component.action('createPuzzle'),
                new Promise((_, reject) => window.setTimeout(() => reject(new Error('stall')), this.constructor.CREATE_STALL_MS)),
            ]);
        } catch (error) {
            if (error && error.message === 'stall') {
                // The request hangs (connection dropped): after a reload the tray comes back from storage
                this.toast(this.connectionLostMessageValue, 'error', 8000);
            }
        } finally {
            this.creating = false;
            // After a success the sheet is gone; after a failure the button must work again
            if (button.isConnected) {
                button.disabled = false;
            }
        }
    }

    /**
     * Re-renders replace the quick-add markup; put the kept photo back into it, and let go of
     * photos whose row is gone
     */
    syncPhotos() {
        const inTray = new Set((this.trayTarget.getAttribute('data-multiscan-eans') || '').split(' ').filter(Boolean));

        for (const ean of Array.from(this.photos.keys())) {
            if (!inTray.has(ean)) {
                this.forgetPhoto(ean);
            }
        }

        const box = this.trayTarget.querySelector('.multiscan-photo');
        if (box) {
            const ean = box.getAttribute('data-multiscan-photo-for') || '';
            this.renderPhoto(box, this.photos.get(ean) || null, false);
        }
    }

    renderPhoto(box, photo, working) {
        const empty = box.querySelector('.multiscan-photo-empty');
        const taken = box.querySelector('.multiscan-photo-taken');
        const spinner = box.querySelector('.multiscan-photo-working');
        const preview = box.querySelector('.multiscan-photo-preview');

        if (spinner) {
            spinner.classList.toggle('d-none', !working);
        }
        if (empty) {
            empty.classList.toggle('d-none', Boolean(photo) || working);
        }
        if (taken) {
            taken.classList.toggle('d-none', !photo || working);
            taken.classList.toggle('d-flex', Boolean(photo) && !working);
        }
        if (preview && photo && preview.getAttribute('src') !== photo.url) {
            preview.setAttribute('src', photo.url);
        }
    }

    forgetPhoto(ean) {
        const photo = this.photos.get(ean);
        if (photo) {
            URL.revokeObjectURL(photo.url);
            this.photos.delete(ean);
        }
    }

    // ------------------------------------------------------------------ tray persistence

    storage() {
        try {
            return this.hasStorageKeyValue && this.storageKeyValue !== '' ? window.sessionStorage : null;
        } catch (error) {
            return null;
        }
    }

    saveToStorage() {
        const storage = this.storage();
        if (!storage || !this.component) {
            return;
        }

        try {
            const rows = (this.component.getData('rows') || []).map((row) => ({ ean: row.ean, puzzleId: row.puzzleId }));

            if (rows.length === 0) {
                storage.removeItem(this.storageKeyValue);
                return;
            }

            const tray = this.trayTarget;
            const sheetEan = tray.getAttribute('data-multiscan-sheet-ean') || '';

            storage.setItem(this.storageKeyValue, JSON.stringify({
                savedAt: Date.now(),
                rows,
                action: this.component.getData('action'),
                collectionId: this.component.getData('collectionId'),
                sheet: sheetEan === '' ? null : {
                    ean: sheetEan,
                    quickAddOpen: tray.getAttribute('data-multiscan-quick-add-open') === '1',
                    quickAddId: tray.getAttribute('data-multiscan-quick-add-id') || null,
                    name: this.component.getData('newName'),
                    pieces: this.component.getData('newPiecesCount'),
                    brand: this.component.getData('newBrand'),
                    brandName: this.component.getData('newBrandName'),
                },
            }));
        } catch (error) {
            // Storage full or blocked: the tray still works, it just cannot survive a reload
        }
    }

    restoreFromStorage() {
        const storage = this.storage();
        if (!storage || !this.component || (this.trayTarget.getAttribute('data-multiscan-eans') || '') !== '') {
            return;
        }

        try {
            const raw = storage.getItem(this.storageKeyValue);
            if (!raw) {
                return;
            }

            const saved = JSON.parse(raw);
            if (!saved || !Array.isArray(saved.rows) || saved.rows.length === 0
                || Date.now() - (saved.savedAt || 0) > this.constructor.STORAGE_MAX_AGE_MS) {
                storage.removeItem(this.storageKeyValue);
                return;
            }

            this.component.action('restore', { state: raw });
        } catch (error) {
            // Unreadable leftovers: start with an empty tray
        }
    }

    scannerController() {
        if (!this.hasScannerTarget) {
            return null;
        }

        return this.application.getControllerForElementAndIdentifier(this.scannerTarget, 'barcode-scanner');
    }

    scannerFeedback(kind) {
        const scanner = this.scannerController();
        if (scanner && typeof scanner.feedback === 'function') {
            scanner.feedback(kind);
        }
    }

    scannerPause() {
        window.dispatchEvent(new CustomEvent('barcode-scan:pause'));
    }

    scannerResume() {
        window.dispatchEvent(new CustomEvent('barcode-scan:resume'));
    }

    toast(message, type = 'info', duration = 2500) {
        if (!message) {
            return;
        }

        document.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message, type, duration },
        }));
    }
}
