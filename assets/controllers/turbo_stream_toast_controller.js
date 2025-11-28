import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap';

/**
 * Controller that initializes Bootstrap toasts when they are added via Turbo Streams.
 * Attach this controller to the toast container element.
 *
 * Usage in Turbo Stream:
 * <turbo-stream action="append" target="toast-container">
 *     <template>
 *         <div class="toast" data-controller="turbo-stream-toast" data-turbo-stream-toast-type-value="success">
 *             ...
 *         </div>
 *     </template>
 * </turbo-stream>
 */
export default class extends Controller {
    static values = {
        type: { type: String, default: 'success' },
        delay: { type: Number, default: 3000 }
    };

    connect() {
        // Initialize and show the toast
        const toast = Toast.getOrCreateInstance(this.element, {
            delay: this.delayValue,
            autohide: true
        });

        toast.show();

        // Remove element after it's hidden
        this.element.addEventListener('hidden.bs.toast', () => {
            this.element.remove();
        });
    }
}
