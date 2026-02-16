import { Controller } from '@hotwired/stimulus';
import mercureManager from '../mercure-manager';

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
            this._unsubscribe = mercureManager.subscribe(
                this.mercureUrlValue,
                [`/conversations/${this.playerIdValue}`],
                () => this.debouncedRefresh(),
            );
        }
        this._refreshTimeout = null;
    }

    disconnect() {
        if (this._unsubscribe) {
            this._unsubscribe();
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
