import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['item', 'count'];

    connect() {
        document.addEventListener('collection:itemMoved', this._handleItemMoved.bind(this));
    }

    disconnect() {
        document.removeEventListener('collection:itemMoved', this._handleItemMoved.bind(this));
    }

    _handleItemMoved(event) {
        const collectionItemId = event.detail.collectionItemId;

        // Find and remove the item element
        const itemElement = this.itemTargets.find(
            item => item.dataset.collectionItemId === collectionItemId
        );

        if (itemElement) {
            itemElement.remove();

            // Update the count
            if (this.hasCountTarget) {
                const currentCount = this.itemTargets.length;
                this.countTarget.textContent = currentCount;
            }
        }
    }
}
