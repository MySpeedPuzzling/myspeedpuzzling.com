import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Dynamic Modal Controller
 *
 * Handles a global modal that loads content dynamically via Turbo Frames.
 * Opens automatically when frame content is loaded (turbo:frame-load event),
 * closes automatically when the frame becomes empty.
 *
 * Usage:
 * - Add data-turbo-frame="modal-frame" to links that should open in the modal
 * - Server returns content wrapped in <turbo-frame id="modal-frame">
 * - To close: server returns empty turbo-frame or Turbo Stream that clears it
 */
export default class extends Controller {
    static targets = ['frame'];

    modal = null;
    observer = null;
    pendingOpen = false;

    connect() {
        // Disable focus trap to allow interaction with tom-select dropdowns
        // that render outside the modal dialog
        this.modal = Modal.getOrCreateInstance(this.element, {
            focus: false
        });

        // Track when a fetch starts (we want to open modal when content arrives)
        this.frameTarget.addEventListener('turbo:before-fetch-request', this.handleBeforeFetch);

        // Open modal when content arrives
        this.frameTarget.addEventListener('turbo:frame-load', this.handleFrameLoad);

        // Watch for frame becoming empty (close trigger)
        this.observer = new MutationObserver(this.handleMutation);
        this.observer.observe(this.frameTarget, { childList: true, subtree: true });

        // Close modal on Escape key
        document.addEventListener('keydown', this.handleKeydown);

        // Listen for programmatic close events
        document.addEventListener('modal:close', this.handleClose);
    }

    disconnect() {
        this.frameTarget.removeEventListener('turbo:before-fetch-request', this.handleBeforeFetch);
        this.frameTarget.removeEventListener('turbo:frame-load', this.handleFrameLoad);
        this.observer?.disconnect();
        document.removeEventListener('keydown', this.handleKeydown);
        document.removeEventListener('modal:close', this.handleClose);
    }

    handleBeforeFetch = () => {
        // Mark that we want to open the modal when content arrives
        this.pendingOpen = true;
    };

    handleFrameLoad = () => {
        // Content has arrived - open modal if we were waiting for it
        if (this.pendingOpen) {
            this.pendingOpen = false;
            this.open();
        }
    };

    handleMutation = () => {
        // Close modal if frame content is empty (cleared by Turbo Stream)
        if (this.frameTarget.innerHTML.trim() === '' && this.isOpen()) {
            this.close();
        }
    };

    handleKeydown = (event) => {
        if (event.key === 'Escape' && this.isOpen()) {
            this.close();
        }
    };

    handleClose = () => {
        this.close();
    };

    open() {
        // Don't open modal if frame is empty (safety check for edge cases)
        if (this.frameTarget.innerHTML.trim() === '') {
            return;
        }

        // Dynamic modal size: check first child for data-modal-size
        const dialog = this.element.querySelector('.modal-dialog');
        const sizeEl = this.frameTarget.querySelector('[data-modal-size]');
        dialog.classList.remove('modal-sm', 'modal-lg', 'modal-xl');
        if (sizeEl) {
            dialog.classList.add(sizeEl.dataset.modalSize);
        }

        this.modal.show();
        document.body.classList.add('modal-open');
    }

    close() {
        this.pendingOpen = false;
        this.modal.hide();
        document.body.classList.remove('modal-open');
        this.frameTarget.innerHTML = '';
    }

    isOpen() {
        return this.element.classList.contains('show');
    }

    // Allow closing via backdrop click (Bootstrap handles this by default)
    // But we also need to clear the frame when modal is hidden
    backdropClick(event) {
        if (event.target === event.currentTarget) {
            this.close();
        }
    }
}
