import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['item', 'count'];

    connect() {
        document.addEventListener('collection:itemRemoved', this._handleItemRemoved.bind(this));
        document.addEventListener('sellswaplist:itemRemoved', this._handleItemRemoved.bind(this));
    }

    disconnect() {
        document.removeEventListener('collection:itemRemoved', this._handleItemRemoved.bind(this));
        document.removeEventListener('sellswaplist:itemRemoved', this._handleItemRemoved.bind(this));
    }

    _handleItemRemoved(event) {
        const puzzleId = event.detail.puzzleId;

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
