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
        const { message, type = 'success', duration = 5000 } = event.detail;
        
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
        toastElement.className = 'toast';
        toastElement.setAttribute('role', 'alert');
        toastElement.setAttribute('aria-live', 'assertive');
        toastElement.setAttribute('aria-atomic', 'true');

        const iconClass = type === 'success' ? 'ci-check-circle text-success' : 
                         type === 'error' ? 'ci-x-circle text-danger' : 
                         'ci-info-circle text-info';

        const bgClass = type === 'success' ? 'bg-success' : 
                       type === 'error' ? 'bg-danger' : 
                       'bg-info';

        toastElement.innerHTML = `
            <div class="toast-header ${bgClass} text-white">
                <i class="${iconClass} me-2"></i>
                <strong class="me-auto">
                    ${type === 'success' ? 'Success' : 
                      type === 'error' ? 'Error' : 'Info'}
                </strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        `;

        return toastElement;
    }
}