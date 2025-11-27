import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['item', 'count'];

    connect() {
        document.addEventListener('collection:itemMoved', this._handleItemMoved.bind(this));
        document.addEventListener('wishlist:itemRemoved', this._handleWishlistItemRemoved.bind(this));
    }

    disconnect() {
        document.removeEventListener('collection:itemMoved', this._handleItemMoved.bind(this));
        document.removeEventListener('wishlist:itemRemoved', this._handleWishlistItemRemoved.bind(this));
    }

    _handleItemMoved(event) {
        const collectionItemId = event.detail.collectionItemId;

        // Find and remove the item element
        const itemElement = this.itemTargets.find(
            item => item.dataset.collectionItemId === collectionItemId
        );

        if (itemElement) {
            itemElement.remove();
            this._updateCount();
        }
    }

    _handleWishlistItemRemoved(event) {
        const puzzleId = event.detail.puzzleId;

        // Find and remove the item element by puzzle ID
        const itemElement = this.itemTargets.find(
            item => item.dataset.puzzleId === puzzleId
        );

        if (itemElement) {
            itemElement.remove();
            this._updateCount();
        }
    }

    _updateCount() {
        if (this.hasCountTarget) {
            const currentCount = this.itemTargets.length;
            this.countTarget.textContent = currentCount;
        }
    }
}
