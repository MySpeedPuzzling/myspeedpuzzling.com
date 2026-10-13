import { Controller } from '@hotwired/stimulus';
import { scrollActiveIntoRow } from '../navigation/scroll_active_into_row.js';

/* stimulusFetch: 'lazy' */

/**
 * The leaderboards pill row (templates/_leaderboards_nav.html.twig) scrolls sideways on phones: the active pill is
 * brought into view on load - horizontally only, the page itself never moves (scrollActiveIntoRow, shared with the
 * section chips' row, docs/features/navigation COM-02).
 *
 * On the MSP Rating page (liveFilters) the filters change without a reload (its Live Component keeps them in the URL),
 * so the pills built by the server may be stale: at the click, each pill takes the country and favorites from the
 * current URL again - only those its data attributes say the target accepts for this viewer.
 */
export default class extends Controller {
    static targets = ['active'];

    static values = { liveFilters: Boolean };

    connect() {
        if (this.hasActiveTarget) {
            scrollActiveIntoRow(this.element, this.activeTarget);
        }
    }

    carryFilters(event) {
        if (!this.liveFiltersValue) {
            return;
        }

        const pill = event.target.closest('a.leaderboards-nav-pill');

        if (!pill || !pill.dataset.base) {
            return;
        }

        const current = new URLSearchParams(window.location.search);
        const country = (current.get('country') || '').toLowerCase();
        const validCountry = /^[a-z]{2}$/.test(country) ? country : null;
        const favorites = ['1', 'true'].includes(current.get('onlyFavorites') || '');

        let url;

        if (pill.dataset.countryPattern && validCountry) {
            url = new URL(pill.dataset.countryPattern.replace('__country__', validCountry), window.location.origin);
        } else {
            url = new URL(pill.dataset.base, window.location.origin);
        }

        if (pill.dataset.countryParam && validCountry) {
            url.searchParams.set(pill.dataset.countryParam, validCountry);
        }

        if (pill.dataset.favoritesParam && favorites) {
            url.searchParams.set(pill.dataset.favoritesParam, '1');
        }

        pill.href = url.pathname + url.search;
    }
}
