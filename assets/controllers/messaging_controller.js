import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['messages', 'textarea'];

    connect() {
        // Delay scroll to ensure DOM layout is fully computed
        requestAnimationFrame(() => {
            this.scrollToBottom();
        });

        // For modals: also scroll after the modal transition completes
        this.modalElement = this.element.closest('.modal');
        if (this.modalElement) {
            this._modalShownHandler = () => this.scrollToBottom();
            this.modalElement.addEventListener('shown.bs.modal', this._modalShownHandler);
        }

        this.observer = new MutationObserver(() => this.scrollToBottom());
        this.observer.observe(this.messagesTarget, { childList: true });
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
        if (this.modalElement && this._modalShownHandler) {
            this.modalElement.removeEventListener('shown.bs.modal', this._modalShownHandler);
        }
    }

    submitOnEnter(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            const form = event.target.closest('form');
            if (form && event.target.value.trim() !== '') {
                form.requestSubmit();
            }
        }
    }

    onSubmitEnd(event) {
        if (event.detail.success && this.hasTextareaTarget) {
            this.textareaTarget.value = '';
            this.textareaTarget.focus();
        }

        // Re-enable submit button
        const button = this.element.querySelector('form button[type=submit]');
        if (button) button.disabled = false;
    }

    scrollToBottom() {
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }
}
