import { Controller } from '@hotwired/stimulus';
import { connectStreamSource, disconnectStreamSource } from '@hotwired/turbo';

export default class extends Controller {
    static values = { mercureUrl: String, topics: Array };

    connect() {
        if (!this.mercureUrlValue || !this.topicsValue.length) return;

        const url = new URL(this.mercureUrlValue);
        this.topicsValue.forEach(topic => url.searchParams.append('topic', topic));

        this._eventSource = new EventSource(url, { withCredentials: true });

        // Let Turbo handle turbo-stream HTML messages natively
        connectStreamSource(this._eventSource);

        // Also dispatch JSON messages as custom events
        this._eventSource.addEventListener('message', (event) => {
            try {
                const parsed = JSON.parse(event.data);
                document.dispatchEvent(new CustomEvent('mercure:message', { detail: parsed }));
            } catch { /* Not JSON - handled by Turbo stream source */ }
        });
    }

    disconnect() {
        if (this._eventSource) {
            disconnectStreamSource(this._eventSource);
            this._eventSource.close();
            this._eventSource = null;
        }
    }
}
