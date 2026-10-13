import { Controller } from '@hotwired/stimulus';
import { loadMenuCounts, TTL_MS } from '../navigation/menu_counts.js';

/**
 * The Menu's lazy numbers (MENU-01, assets/navigation/menu_counts.js) on the page's one #site-menu. The `menu` page
 * shows the Menu at once and loads them on connect; the docked desktop column (NAV-13) too, but at most once per
 * 5 minutes per tab (the answer is kept in sessionStorage - never with each page view); the hidden slot loads nothing.
 * The phone panel (MENU-02b) and the desktop overlay (NAV-13) call refresh() when they show it (`menu-counts:refresh`,
 * detail.maxAge: 0 = fresh; docking the column asks with the 5-minute TTL).
 * Idempotent: the element moves between the slot and the shells, and connects again each time.
 */
export default class extends Controller {
    static values = { url: String, player: String };

    connect() {
        this.refreshListener = (event) => this.refresh(event);
        this.element.addEventListener('menu-counts:refresh', this.refreshListener);

        if (this.element.dataset.surface === 'page') {
            this.refresh();
        } else if (this.docked()) {
            this.refresh({ detail: { maxAge: TTL_MS } });
        }
    }

    disconnect() {
        this.element.removeEventListener('menu-counts:refresh', this.refreshListener);
    }

    /** In its slot, and the slot is shown: the docked column (critical CSS decides) */
    docked() {
        const slot = this.element.parentElement;

        return Boolean(slot) && slot.id === 'menu-slot' && slot.getClientRects().length > 0;
    }

    refresh(event = null) {
        const maxAge = event && event.detail && typeof event.detail.maxAge === 'number' ? event.detail.maxAge : 0;

        return loadMenuCounts(this.element, { url: this.urlValue, player: this.playerValue, maxAge }).catch(() => null);
    }
}
