import { Controller } from '@hotwired/stimulus';
import { loadMenuCounts } from '../navigation/menu_counts.js';

/**
 * The Menu's lazy numbers (MENU-01, assets/navigation/menu_counts.js) on the page's one #site-menu. The `menu` page
 * shows the Menu at once and loads them on connect; the hidden slot loads nothing - the phone panel (MENU-02b) and the
 * desktop column/overlay (NAV-13) call refresh() when they show it (`menu-counts:refresh`, detail.maxAge for the column).
 * Idempotent: the element moves between the slot and the shells, and connects again each time.
 */
export default class extends Controller {
    static values = { url: String, player: String };

    connect() {
        this.refreshListener = (event) => this.refresh(event);
        this.element.addEventListener('menu-counts:refresh', this.refreshListener);

        if (this.element.dataset.surface === 'page') {
            this.refresh();
        }
    }

    disconnect() {
        this.element.removeEventListener('menu-counts:refresh', this.refreshListener);
    }

    refresh(event = null) {
        const maxAge = event && event.detail && typeof event.detail.maxAge === 'number' ? event.detail.maxAge : 0;

        return loadMenuCounts(this.element, { url: this.urlValue, player: this.playerValue, maxAge }).catch(() => null);
    }
}
