import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Dynamic Modal Controller
 *
 * Handles a global modal that loads content dynamically via Turbo Frames.
 * Opens automatically when the turbo-frame starts fetching content,
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

    connect() {
        this.modal = Modal.getOrCreateInstance(this.element);

        // Open modal when frame starts fetching content
        this.frameTarget.addEventListener('turbo:before-fetch-request', this.handleBeforeFetch);

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
        this.observer?.disconnect();
        document.removeEventListener('keydown', this.handleKeydown);
        document.removeEventListener('modal:close', this.handleClose);
    }

    handleBeforeFetch = () => {
        this.open();
    };

    handleMutation = () => {
        // Close modal if frame content is empty (cleared by Turbo Stream)
        if (this.frameTarget.innerHTML.trim() === '') {
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
        this.modal.show();
        document.body.classList.add('modal-open');
    }

    close() {
        this.modal.hide();
        document.body.classList.remove('modal-open');
        // Clear frame content to reset state
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
