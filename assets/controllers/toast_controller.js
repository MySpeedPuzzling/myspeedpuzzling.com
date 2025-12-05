import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap';

export default class extends Controller {
    static targets = ['container'];

    connect() {
        // Listen for custom toast events
        document.addEventListener('toast:show', this.showToast.bind(this));
    }

    disconnect() {
        document.removeEventListener('toast:show', this.showToast.bind(this));
    }

    showToast(event) {
        const { message, type = 'success', duration = 7500 } = event.detail;
        
        // Create toast element
        const toastElement = this.createToastElement(message, type);
        
        // Append to container
        this.containerTarget.appendChild(toastElement);
        
        // Initialize Bootstrap toast
        const toast = Toast.getOrCreateInstance(toastElement, {
            delay: duration,
            autohide: true
        });
        
        // Show toast
        toast.show();
        
        // Remove element after it's hidden
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }

    createToastElement(message, type) {
        const toastElement = document.createElement('div');
        
        const bgClass = type === 'success' ? 'text-bg-success' : 
                       type === 'error' ? 'text-bg-danger' : 
                       'text-bg-primary';

        toastElement.className = `toast align-items-center ${bgClass} border-0 shadow-custom`;
        toastElement.setAttribute('role', 'alert');
        toastElement.setAttribute('aria-live', 'assertive');
        toastElement.setAttribute('aria-atomic', 'true');

        toastElement.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        return toastElement;
    }
}
