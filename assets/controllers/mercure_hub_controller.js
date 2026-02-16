import { Controller } from '@hotwired/stimulus';
import { connectStreamSource, disconnectStreamSource } from '@hotwired/turbo';

export default class extends Controller {
    static values = { mercureUrl: String, topics: Array };

    connect() {
        if (!this.mercureUrlValue || !this.topicsValue.length) return;

        const url = new URL(this.mercureUrlValue);
        this.topicsValue.forEach(topic => url.searchParams.append('topic', topic));

        this._eventSource = new EventSource(url, { withCredentials: true });

        // Create a proxy stream source that only forwards Turbo Stream HTML to Turbo
        this._streamSource = {
            listeners: new Map(),
            addEventListener(type, listener) {
                this.listeners.set(type, listener);
            },
            removeEventListener(type) {
                this.listeners.delete(type);
            },
        };
        connectStreamSource(this._streamSource);

        // Route messages: Turbo Streams to Turbo, JSON to custom events
        this._eventSource.addEventListener('message', (event) => {
            const data = event.data.trim();

            if (data.startsWith('<turbo-stream')) {
                const listener = this._streamSource.listeners.get('message');
                if (listener) {
                    listener(new MessageEvent('message', { data: event.data }));
                }
                return;
            }

            try {
                const parsed = JSON.parse(data);
                document.dispatchEvent(new CustomEvent('mercure:message', { detail: parsed }));
            } catch { /* Unknown message format */ }
        });
    }

    disconnect() {
        if (this._streamSource) {
            disconnectStreamSource(this._streamSource);
            this._streamSource = null;
        }
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
    }
}
