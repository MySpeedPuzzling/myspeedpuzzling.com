import { Controller } from '@hotwired/stimulus';

/**
 * Sell/Swap List Filter Controller
 *
 * A focused, lightweight filter for sell/swap list items.
 * Features: text search, listing type filter, price range slider, condition filter.
 */
export default class extends Controller {
    static targets = [
        "item",              // Each filterable item
        "search",            // Text search input
        "listingTypeSelect", // Listing type select dropdown
        "condition",         // Condition checkboxes
        "priceMin",          // Price min input
        "priceMax",          // Price max input
        "visibleCount",      // Counter showing visible items
        "noResults"          // No results message
    ];

    static classes = ["hidden"];

    connect() {
        this.updateVisibleCount();
    }

    filter() {
        const searchTerm = this.normalizeString(this.hasSearchTarget ? this.searchTarget.value : '');
        const listingType = this.getSelectedListingType();
        const conditions = this.getSelectedConditions();
        const priceRange = this.getPriceRange();

        let visibleCount = 0;

        this.itemTargets.forEach(item => {
            const isVisible = this.itemMatchesFilters(item, searchTerm, listingType, conditions, priceRange);
            item.style.display = isVisible ? '' : 'none';
            if (isVisible) visibleCount++;
        });

        this.updateVisibleCount(visibleCount);
        this.updateNoResultsMessage(visibleCount === 0);
    }

    itemMatchesFilters(item, searchTerm, listingType, conditions, priceRange) {
        // Text search - matches puzzle name, manufacturer, or seller name
        if (searchTerm) {
            const name = this.normalizeString(item.dataset.puzzleName || '');
            const manufacturer = this.normalizeString(item.dataset.manufacturer || '');
            const seller = this.normalizeString(item.dataset.sellerName || '');

            const matchesSearch = name.includes(searchTerm) ||
                                  manufacturer.includes(searchTerm) ||
                                  seller.includes(searchTerm);
            if (!matchesSearch) return false;
        }

        // Listing type filter
        if (listingType && listingType !== 'all') {
            const itemListingType = item.dataset.listingType;
            // 'both' matches both swap and sell filters
            if (itemListingType !== listingType && itemListingType !== 'both') {
                return false;
            }
        }

        // Condition filter (checkboxes - any selected condition should match)
        if (conditions.length > 0) {
            const itemCondition = item.dataset.condition;
            if (!conditions.includes(itemCondition)) {
                return false;
            }
        }

        // Price range filter
        if (priceRange.min !== null || priceRange.max !== null) {
            const price = parseFloat(item.dataset.price) || 0;
            if (priceRange.min !== null && price < priceRange.min) {
                return false;
            }
            if (priceRange.max !== null && price > priceRange.max) {
                return false;
            }
        }

        return true;
    }

    getSelectedListingType() {
        if (!this.hasListingTypeSelectTarget) return '';

        return this.listingTypeSelectTarget.value || '';
    }

    getSelectedConditions() {
        if (!this.hasConditionTarget) return [];

        return this.conditionTargets
            .filter(checkbox => checkbox.checked)
            .map(checkbox => checkbox.value);
    }

    getPriceRange() {
        return {
            min: this.hasPriceMinTarget && this.priceMinTarget.value ? parseFloat(this.priceMinTarget.value) : null,
            max: this.hasPriceMaxTarget && this.priceMaxTarget.value ? parseFloat(this.priceMaxTarget.value) : null
        };
    }

    updateVisibleCount(count) {
        if (!this.hasVisibleCountTarget) return;

        if (count === undefined) {
            count = this.itemTargets.filter(item => item.style.display !== 'none').length;
        }

        this.visibleCountTarget.textContent = count;
    }

    updateNoResultsMessage(show) {
        if (!this.hasNoResultsTarget) return;
        this.noResultsTarget.classList.toggle('hidden', !show);
    }

    reset() {
        // Reset search
        if (this.hasSearchTarget) {
            this.searchTarget.value = '';
        }

        // Reset listing type to "all"
        if (this.hasListingTypeSelectTarget) {
            this.listingTypeSelectTarget.value = 'all';
        }

        // Reset all condition checkboxes
        if (this.hasConditionTarget) {
            this.conditionTargets.forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        // Reset price range
        if (this.hasPriceMinTarget) {
            this.priceMinTarget.value = '';
        }
        if (this.hasPriceMaxTarget) {
            this.priceMaxTarget.value = '';
        }

        this.filter();
    }

    normalizeString(str) {
        if (!str) return '';
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }
}
