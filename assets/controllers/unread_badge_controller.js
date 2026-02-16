import { Controller } from '@hotwired/stimulus';
import mercureManager from '../mercure-manager';

export default class extends Controller {
    static values = {
        playerId: String,
        mercureUrl: String,
    };

    connect() {
        if (this.mercureUrlValue && this.playerIdValue) {
            this._unsubscribe = mercureManager.subscribe(
                this.mercureUrlValue,
                [`/unread-count/${this.playerIdValue}`],
                () => {},
            );
        }
    }

    disconnect() {
        if (this._unsubscribe) {
            this._unsubscribe();
        }
    }
}
