import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/**
 * Bridge between the continuous barcode scanner and the MultiscanTray Live
 * Component (docs/features/multiscan/README.md §7):
 *  - every decoded code becomes a `scan` action, queued so fast scanning never drops one
 *  - a failed request is retried with backoff and never shown as "not found"
 *  - after each render the tray's outcome (found / duplicate / unknown …) drives
 *    feedback on the scanner and a mini-toast; the resolve sheet pauses the camera
 *  - unknown rows are rechecked when the tab comes back (the full add form opens in a new tab)
 */
export default class extends Controller {
    static targets = ['tray', 'scanner', 'eanInput'];

    static values = {
        checkFailedMessage: String,
        duplicateMessage: String,
        invalidMessage: String,
        fullMessage: String,
        recheckedMessage: String,
        forbiddenMessage: String,
    };

    async initialize() {
        this.queue = [];
        this.draining = false;
        this.inflight = null;
        this.lastNoticeSeq = null;

        this._boundScanned = (event) => this.onScanned(event);
        this._boundVisibility = () => this.onVisibilityChange();

        this.component = await getComponent(this.trayTarget);

        this.component.on('render:finished', () => this.onRendered());
        this.component.on('response:error', (backendResponse, controls) => this.onResponseError(backendResponse, controls));
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
        // One request per code, in order; the tray dedupes, so re-queuing the same code is harmless
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
                    await component.action('scan', { ean: job.ean });
                } catch (error) {
                    // Handled in onResponseError (retry); the promise rejecting must not stop the queue
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
                this.scannerFeedback('found');
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
            default:
                break;
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
