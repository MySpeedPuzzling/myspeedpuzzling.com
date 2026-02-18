import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        conversationId: String,
        playerId: String,
        typingUrl: String,
        mercureUrl: String,
    };

    static targets = ['messages', 'typingIndicator'];

    connect() {
        this._lastTypingSent = 0;
        this._typingTimeout = null;
        this._hasNewMessages = false;

        // If mercureUrl is provided, create own EventSource (for modals where global hub lacks these topics)
        if (this.hasMercureUrlValue && this.mercureUrlValue && this.hasConversationIdValue) {
            const url = new URL(this.mercureUrlValue);
            url.searchParams.append('topic', '/conversation/' + this.conversationIdValue + '/typing');
            url.searchParams.append('topic', '/conversation/' + this.conversationIdValue + '/read/' + this.playerIdValue);

            this._ownEventSource = new EventSource(url, { withCredentials: true });
            this._ownEventSource.addEventListener('message', (event) => {
                try {
                    const parsed = JSON.parse(event.data.trim());
                    this.handleEvent(parsed);
                } catch { /* ignore non-JSON */ }
            });
        } else {
            // Fallback: listen for global mercure:message events (full-page view with mercure-hub)
            this._onMercureMessage = (event) => this.handleEvent(event.detail);
            document.addEventListener('mercure:message', this._onMercureMessage);
        }

        // Listen for turbo stream renders to hide typing indicator on new messages
        this._onStreamRender = (event) => {
            const target = event.target?.getAttribute?.('target');
            if (target === 'messages-container') {
                this.hideTypingIndicator();
                this._hasNewMessages = true;
            }
        };
        document.addEventListener('turbo:before-stream-render', this._onStreamRender);
    }

    disconnect() {
        if (this._ownEventSource) {
            this._ownEventSource.close();
            this._ownEventSource = null;
        }
        if (this._onMercureMessage) {
            document.removeEventListener('mercure:message', this._onMercureMessage);
        }
        if (this._typingTimeout) {
            clearTimeout(this._typingTimeout);
        }
        if (this._onStreamRender) {
            document.removeEventListener('turbo:before-stream-render', this._onStreamRender);
        }
    }

    handleEvent(data) {
        if (data.type === 'read') {
            this.handleReadReceipt();
        } else if (data.type === 'typing') {
            this.handleTypingIndicator(data);
        }
    }

    handleReadReceipt() {
        // Update all single-check icons to double-check (green)
        const icons = this.element.querySelectorAll('.message-status-icon.bi-check2');
        icons.forEach(icon => {
            icon.classList.remove('bi-check2');
            icon.classList.add('bi-check2-all', 'text-success');
        });
    }

    handleTypingIndicator(data) {
        if (data.playerId === this.playerIdValue) return;
        if (!this.hasTypingIndicatorTarget) return;

        this.typingIndicatorTarget.classList.remove('d-none');

        if (this._typingTimeout) {
            clearTimeout(this._typingTimeout);
        }

        this._typingTimeout = setTimeout(() => {
            this.typingIndicatorTarget.classList.add('d-none');
        }, 3000);

        // Auto-scroll if near bottom
        if (this.hasMessagesTarget) {
            const el = this.messagesTarget;
            const isNearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 100;
            if (isNearBottom) {
                requestAnimationFrame(() => {
                    el.scrollTop = el.scrollHeight;
                });
            }
        }
    }

    hideTypingIndicator() {
        if (this.hasTypingIndicatorTarget) {
            this.typingIndicatorTarget.classList.add('d-none');
        }
        if (this._typingTimeout) {
            clearTimeout(this._typingTimeout);
            this._typingTimeout = null;
        }
    }

    handleInput() {
        if (!this.typingUrlValue) return;

        const now = Date.now();
        if (now - this._lastTypingSent < 2000) return;

        this._lastTypingSent = now;

        const headers = {
            'X-Requested-With': 'XMLHttpRequest',
        };

        if (this._hasNewMessages) {
            headers['X-Mark-As-Read'] = '1';
            this._hasNewMessages = false;
        }

        fetch(this.typingUrlValue, {
            method: 'POST',
            headers,
        }).catch(() => {});
    }
}
