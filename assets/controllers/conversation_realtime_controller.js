import { Controller } from '@hotwired/stimulus';
import mercureManager from '../mercure-manager';

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
            this._unsubscribe = mercureManager.subscribe(
                this.mercureUrlValue,
                [
                    `/messages/${this.conversationIdValue}/user/${this.playerIdValue}`,
                    `/conversation/${this.conversationIdValue}/read`,
                    `/conversation/${this.conversationIdValue}/typing`,
                ],
                (event) => this.handleEvent(event),
            );
        }
        this._lastTypingSent = 0;
        this._typingTimeout = null;
    }

    disconnect() {
        if (this._unsubscribe) {
            this._unsubscribe();
        }
        if (this._typingTimeout) {
            clearTimeout(this._typingTimeout);
        }
    }

    handleEvent(event, isTurboStream) {
        if (isTurboStream) {
            // New message arrived via Turbo Stream (auto-processed by mercure-manager)
            this.hideTypingIndicator();
            return;
        }

        let data;
        try {
            data = JSON.parse(event.data);
        } catch {
            return;
        }

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
        fetch(this.typingUrlValue, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).catch(() => {});
    }
}
