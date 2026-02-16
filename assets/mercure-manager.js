import { renderStreamMessage } from '@hotwired/turbo';

/**
 * Shared Mercure EventSource manager.
 *
 * Consolidates all Mercure subscriptions into a single EventSource connection
 * per page to avoid hitting browser HTTP/1.1 connection limits (especially Safari).
 *
 * Turbo Stream HTML payloads (starting with `<turbo-stream`) are automatically
 * processed via Turbo.renderStreamMessage(). Callbacks still receive the event
 * with an extra `isTurboStream` flag so they can react (e.g. hide typing indicator).
 */
class MercureManager {
    constructor() {
        this._subscriptions = [];
        this._topics = new Set();
        this._eventSource = null;
        this._mercureUrl = null;
        this._reconnectAttempts = 0;
        this._reconnectTimeout = null;
    }

    subscribe(mercureUrl, topics, callback) {
        this._mercureUrl = mercureUrl;

        let needsReconnect = false;
        for (const topic of topics) {
            if (!this._topics.has(topic)) {
                this._topics.add(topic);
                needsReconnect = true;
            }
        }

        const subscription = { topics: new Set(topics), callback };
        this._subscriptions.push(subscription);

        if (needsReconnect || !this._eventSource) {
            this._connect();
        }

        // Return unsubscribe function
        return () => {
            this._subscriptions = this._subscriptions.filter(s => s !== subscription);
        };
    }

    _connect() {
        if (this._eventSource) {
            this._eventSource.close();
        }

        const url = new URL(this._mercureUrl);
        for (const topic of this._topics) {
            url.searchParams.append('topic', topic);
        }

        this._eventSource = new EventSource(url);

        this._eventSource.onopen = () => {
            this._reconnectAttempts = 0;
        };

        this._eventSource.onmessage = (event) => {
            const isTurboStream = typeof event.data === 'string'
                && event.data.trimStart().startsWith('<turbo-stream');

            if (isTurboStream) {
                renderStreamMessage(event.data);
            }

            for (const sub of this._subscriptions) {
                try {
                    sub.callback(event, isTurboStream);
                } catch {
                    // Ignore errors in individual callbacks
                }
            }
        };

        this._eventSource.onerror = () => {
            if (this._eventSource.readyState === EventSource.CLOSED) {
                this._reconnectAttempts++;
                const delay = Math.min(1000 * Math.pow(2, this._reconnectAttempts), 30000);
                this._reconnectTimeout = setTimeout(() => this._connect(), delay);
            }
        };
    }

    disconnect() {
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
        if (this._reconnectTimeout) {
            clearTimeout(this._reconnectTimeout);
        }
        this._subscriptions = [];
        this._topics.clear();
    }
}

// Singleton per page - survives across Stimulus controller connect/disconnect cycles
if (!window._mercureManager) {
    window._mercureManager = new MercureManager();
}

export default window._mercureManager;
