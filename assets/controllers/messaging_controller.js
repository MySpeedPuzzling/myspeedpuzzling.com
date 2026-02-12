import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        conversationId: String,
        mercureUrl: String,
    };

    connect() {
        this.scrollToBottom();

        if (this.mercureUrlValue) {
            this.connectToMercure();
        }
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
        }
    }

    connectToMercure() {
        const topic = `/messages/${this.conversationIdValue}`;
        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', topic);

        this.eventSource = new EventSource(url);

        this.eventSource.onmessage = (event) => {
            const data = JSON.parse(event.data);

            if (data.html) {
                this.element.insertAdjacentHTML('beforeend', data.html);
                this.scrollToBottom();
            }
        };
    }

    scrollToBottom() {
        this.element.scrollTop = this.element.scrollHeight;
    }
}
