import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        refreshUrl: String,
        activeTab: String,
    };

    static targets = ['tabContent'];

    connect() {
        this._refreshTimeout = null;

        this._onMercureMessage = () => this.debouncedRefresh();
        document.addEventListener('mercure:message', this._onMercureMessage);
    }

    disconnect() {
        if (this._onMercureMessage) {
            document.removeEventListener('mercure:message', this._onMercureMessage);
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
