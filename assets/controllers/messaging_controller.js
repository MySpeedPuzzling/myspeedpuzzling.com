import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['messages'];

    connect() {
        this.scrollToBottom();
        this.observer = new MutationObserver(() => this.scrollToBottom());
        this.observer.observe(this.messagesTarget, { childList: true });
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
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

    scrollToBottom() {
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }
}
