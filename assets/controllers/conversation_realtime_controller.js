import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        conversationId: String,
        playerId: String,
        mercureUrl: String,
        typingUrl: String,
    };

    static targets = ['messages', 'typingIndicator'];

    connect() {
        if (this.mercureUrlValue && this.conversationIdValue && this.playerIdValue) {
            this.connectToMercure();
        }
        this._lastTypingSent = 0;
        this._typingTimeout = null;
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
        }
        if (this._typingTimeout) {
            clearTimeout(this._typingTimeout);
        }
    }

    connectToMercure() {
        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', `/messages/${this.conversationIdValue}/user/${this.playerIdValue}`);
        url.searchParams.append('topic', `/conversation/${this.conversationIdValue}/read`);
        url.searchParams.append('topic', `/conversation/${this.conversationIdValue}/typing`);

        this.eventSource = new EventSource(url);

        this.eventSource.onmessage = (event) => {
            let data;
            try {
                data = JSON.parse(event.data);
            } catch {
                // Not JSON - treat as Turbo Stream HTML
                this.handleTurboStream(event.data);
                return;
            }

            if (data.type === 'read') {
                this.handleReadReceipt();
            } else if (data.type === 'typing') {
                this.handleTypingIndicator(data);
            }
        };
    }

    handleTurboStream(html) {
        if (!this.hasMessagesTarget) return;

        // Parse the turbo-stream and extract the template content
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const template = doc.querySelector('turbo-stream template');

        if (template) {
            const fragment = document.importNode(template.content, true);
            this.messagesTarget.appendChild(fragment);
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

    handleInput() {
        if (!this.typingUrlValue) return;

        const now = Date.now();
        if (now - this._lastTypingSent < 2000) return;

        this._lastTypingSent = now;
        fetch(this.typingUrlValue, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).catch(() => {});
    }
}
