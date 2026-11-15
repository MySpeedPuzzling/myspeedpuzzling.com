import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['messages', 'textarea', 'loadOlder', 'loadOlderSpinner'];

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

        // The full-height chat page resizes the list after the first paint (styles and fonts
        // settling, the phone keyboard opening): stay on the newest message unless the reader
        // scrolled up to read older ones
        this._atBottom = true;
        this._scrollHandler = () => {
            const el = this.messagesTarget;
            this._atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 40;
        };
        this.messagesTarget.addEventListener('scroll', this._scrollHandler, { passive: true });

        if (typeof ResizeObserver !== 'undefined') {
            this.resizeObserver = new ResizeObserver(() => {
                if (this._atBottom) this.scrollToBottom();
            });
            this.resizeObserver.observe(this.messagesTarget);
        }
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
        this.resizeObserver?.disconnect();
        this.messagesTarget.removeEventListener('scroll', this._scrollHandler);
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
        // Prepending older history must not yank the view to the bottom
        if (this._suppressAutoScroll) return;
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }

    async loadOlderMessages(event) {
        if (this._loadingOlder) return;

        const button = event.currentTarget;
        const url = button.dataset.url;
        const before = this._oldestMessageId || button.dataset.oldestId;
        if (!url || !before) return;

        this._loadingOlder = true;
        button.disabled = true;
        if (this.hasLoadOlderSpinnerTarget) {
            this.loadOlderSpinnerTarget.classList.remove('d-none');
        }

        try {
            const response = await fetch(`${url}?before=${encodeURIComponent(before)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return;

            const template = document.createElement('template');
            template.innerHTML = (await response.text()).trim();
            const batch = template.content.firstElementChild;
            if (!batch) return;

            const container = this.messagesTarget;
            const prevScrollHeight = container.scrollHeight;
            const prevScrollTop = container.scrollTop;

            // Insert right below the button: each batch is older than the previous one
            this._suppressAutoScroll = true;
            this.loadOlderTarget.insertAdjacentElement('afterend', batch);
            container.scrollTop = prevScrollTop + (container.scrollHeight - prevScrollHeight);
            requestAnimationFrame(() => {
                this._suppressAutoScroll = false;
            });

            this._oldestMessageId = batch.dataset.oldestId || before;
            if (batch.dataset.hasOlder !== '1') {
                this.loadOlderTarget.classList.add('d-none');
            }
        } catch {
            // Network error - keep the button enabled so the user can retry
        } finally {
            this._loadingOlder = false;
            button.disabled = false;
            if (this.hasLoadOlderSpinnerTarget) {
                this.loadOlderSpinnerTarget.classList.add('d-none');
            }
        }
    }
}
