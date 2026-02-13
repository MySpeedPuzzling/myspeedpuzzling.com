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
            this.connectToMercure();
        }
        this._refreshTimeout = null;
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
        }
        if (this._refreshTimeout) {
            clearTimeout(this._refreshTimeout);
        }
    }

    connectToMercure() {
        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', `/conversations/${this.playerIdValue}`);

        this.eventSource = new EventSource(url);

        this.eventSource.onmessage = () => {
            this.debouncedRefresh();
        };
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
