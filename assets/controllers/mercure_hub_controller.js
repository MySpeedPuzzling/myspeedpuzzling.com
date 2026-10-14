import { Controller } from '@hotwired/stimulus';
import { connectStreamSource, disconnectStreamSource } from '@hotwired/turbo';

/**
 * The signed-in player's Mercure subscription (the inbox badge, notifications): Turbo Streams go to Turbo, JSON messages
 * become `mercure:message` events.
 *
 * GEST-14 (docs/features/navigation README §4.13 G9, decision 64): the EventSource closes when the page is hidden
 * (`pagehide`) - an open connection would keep the page out of the browser's back/forward cache - and opens again when
 * the page comes back from that cache (`pageshow` with `persisted`).
 *
 * GEST-15 (e): the subscription outlives a Turbo render. The old body's controller disconnects and the new body's
 * connects in the same task, so a page subscribing to the same topics takes the connection over instead of closing it
 * and opening another (~10 ms of every visit and Back, and a new request to the hub each time); a connection nobody
 * takes over closes in the next task.
 */
let parked = null;

function subscribe(url) {
    const subscription = {
        url,
        timer: null,
        eventSource: new EventSource(url, { withCredentials: true }),
        // A proxy stream source that only forwards Turbo Stream HTML to Turbo
        streamSource: {
            listeners: new Map(),
            addEventListener(type, listener) {
                this.listeners.set(type, listener);
            },
            removeEventListener(type) {
                this.listeners.delete(type);
            },
        },
    };

    connectStreamSource(subscription.streamSource);

    // Route messages: Turbo Streams to Turbo, JSON to custom events
    subscription.eventSource.addEventListener('message', (event) => {
        const data = event.data.trim();

        if (data.startsWith('<turbo-stream')) {
            const listener = subscription.streamSource.listeners.get('message');
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

    return subscription;
}

function unsubscribe(subscription) {
    disconnectStreamSource(subscription.streamSource);
    subscription.eventSource.close();
}

export default class extends Controller {
    static values = { mercureUrl: String, topics: Array };

    connect() {
        this.onPageHide = () => this.close();
        this.onPageShow = (event) => {
            if (event.persisted) {
                this.open();
            }
        };

        window.addEventListener('pagehide', this.onPageHide);
        window.addEventListener('pageshow', this.onPageShow);

        this.open();
    }

    disconnect() {
        window.removeEventListener('pagehide', this.onPageHide);
        window.removeEventListener('pageshow', this.onPageShow);

        const subscription = this.subscription;
        this.subscription = null;

        if (!subscription) {
            return;
        }

        // Parked for the next body (above); closed in the next task when it does not take it
        if (parked) {
            unsubscribe(parked);
        }

        parked = subscription;
        subscription.timer = window.setTimeout(() => {
            if (parked === subscription) {
                parked = null;
                unsubscribe(subscription);
            }
        }, 0);
    }

    open() {
        if (!this.mercureUrlValue || !this.topicsValue.length || this.subscription) return;

        const url = new URL(this.mercureUrlValue);
        this.topicsValue.forEach(topic => url.searchParams.append('topic', topic));
        const key = url.toString();

        if (parked && parked.url === key) {
            window.clearTimeout(parked.timer);
            this.subscription = parked;
            parked = null;

            return;
        }

        this.subscription = subscribe(key);
    }

    close() {
        if (this.subscription) {
            unsubscribe(this.subscription);
            this.subscription = null;
        }
    }
}
