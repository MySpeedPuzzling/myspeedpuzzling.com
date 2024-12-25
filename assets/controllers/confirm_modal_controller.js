import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["modal"]

    showModal(event) {
        event.preventDefault();

        // Get the modal ID from the clicked link
        const modalId = event.currentTarget.getAttribute('data-modal-id');

        // Find the corresponding modal by data-id
        const modal = this.modalTargets.find(modal => modal.dataset.id === modalId);

        if (modal) {
            const bootstrapModal = window.bootstrap.Modal.getOrCreateInstance(modal);
            bootstrapModal.show();
        }
    }
}
