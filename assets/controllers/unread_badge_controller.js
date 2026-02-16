import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        mercureUrl: String,
        topic: String,
    };

    connect() {
        if (!this.mercureUrlValue || !this.topicValue) return;

        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', this.topicValue);

        this._eventSource = new EventSource(url, { withCredentials: true });
        this._eventSource.onmessage = (event) => {
            if (typeof event.data === 'string' && event.data.includes('<turbo-stream')) {
                const template = document.createElement('template');
                template.innerHTML = event.data.trim();
                const streamEl = template.content.querySelector('turbo-stream');

                if (streamEl) {
                    const targetId = streamEl.getAttribute('target');
                    const targetEl = document.getElementById(targetId);
                    const templateContent = streamEl.querySelector('template');

                    if (targetEl && templateContent) {
                        targetEl.replaceWith(templateContent.content.cloneNode(true));
                    }
                }
            }
        };
    }

    disconnect() {
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
    }
}
