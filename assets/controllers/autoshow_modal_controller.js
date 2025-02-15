import { Controller } from '@hotwired/stimulus';
import * as bootstrap from 'bootstrap';

export default class extends Controller {
    connect() {
        // Check if the modal element exists in the DOM
        if (this.element) {
            // Initialize the Bootstrap modal
            this.myModal = new bootstrap.Modal(this.element, {});

            // Show the modal on page load
            this.myModal.show();
        }
    }
}
