import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { mercureUrl: String, topics: Array };

    connect() {
        if (!this.mercureUrlValue || !this.topicsValue.length) return;

        const url = new URL(this.mercureUrlValue);
        this.topicsValue.forEach(topic => url.searchParams.append('topic', topic));

        this._eventSource = new EventSource(url, { withCredentials: true });
        this._eventSource.onmessage = (event) => this._handleMessage(event);
    }

    disconnect() {
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
    }

    _handleMessage(event) {
        const data = event.data;
        if (typeof data === 'string' && data.includes('<turbo-stream')) {
            const template = document.createElement('template');
            template.innerHTML = data.trim();
            template.content.querySelectorAll('turbo-stream').forEach(streamEl => {
                document.documentElement.appendChild(document.importNode(streamEl, true));
            });
        } else {
            try {
                const parsed = JSON.parse(data);
                document.dispatchEvent(new CustomEvent('mercure:message', { detail: parsed }));
            } catch { /* ignore non-JSON, non-turbo-stream messages */ }
        }
    }
}
