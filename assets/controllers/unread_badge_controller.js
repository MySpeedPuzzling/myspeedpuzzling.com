import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        playerId: String,
        mercureUrl: String,
    };

    static targets = ['count'];

    connect() {
        if (this.mercureUrlValue && this.playerIdValue) {
            this.connectToMercure();
        }
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
        }
    }

    connectToMercure() {
        const topic = `/unread-count/${this.playerIdValue}`;
        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', topic);

        this.eventSource = new EventSource(url);

        this.eventSource.onmessage = (event) => {
            const data = JSON.parse(event.data);
            const count = data.count || 0;

            this.updateBadge(count);
        };
    }

    updateBadge(count) {
        if (!this.hasCountTarget) return;

        this.countTarget.textContent = count;

        if (count > 0) {
            this.countTarget.classList.remove('bg-secondary', 'text-dark');
            this.element.querySelector('.navbar-tool-icon-box')?.classList.add('bg-secondary');
        } else {
            this.countTarget.classList.add('bg-secondary', 'text-dark');
            this.element.querySelector('.navbar-tool-icon-box')?.classList.remove('bg-secondary');
        }
    }
}
