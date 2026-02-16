import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        playerId: String,
        mercureUrl: String,
        refreshUrl: String,
        activeTab: String,
    };

    static targets = ['tabContent'];

    connect() {
        if (this.mercureUrlValue && this.playerIdValue) {
            const url = new URL(this.mercureUrlValue);
            url.searchParams.append('topic', `/conversations/${this.playerIdValue}`);

            this._eventSource = new EventSource(url, { withCredentials: true });
            this._eventSource.onmessage = () => this.debouncedRefresh();
            this._eventSource.onerror = () => {
                // EventSource will auto-reconnect
            };
        }
        this._refreshTimeout = null;
    }

    disconnect() {
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
        if (this._refreshTimeout) {
            clearTimeout(this._refreshTimeout);
        }
    }

    debouncedRefresh() {
        if (this._refreshTimeout) {
            clearTimeout(this._refreshTimeout);
        }

        this._refreshTimeout = setTimeout(() => {
            this.refreshTabContent();
        }, 500);
    }

    async refreshTabContent() {
        if (!this.hasTabContentTarget || !this.refreshUrlValue) return;

        const url = new URL(this.refreshUrlValue, window.location.origin);
        if (this.activeTabValue) {
            url.searchParams.set('tab', this.activeTabValue);
        }

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (response.ok) {
                this.tabContentTarget.innerHTML = await response.text();
            }
        } catch {
            // Silently fail - will refresh on next event
        }
    }
}
