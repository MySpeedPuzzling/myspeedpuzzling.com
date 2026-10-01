import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * Collection Filter Controller
 *
 * A unified filter for collection/library items across all pages.
 * Features: text search, manufacturer dropdown, piece-count chips + custom from-to,
 * difficulty tier chips (members), listing type filter, price range filter.
 *
 * All targets are optional - the controller gracefully handles missing elements.
 */
export default class extends Controller {
    static targets = [
        "item",              // Each filterable collection item
        "search",            // Text search input
        "manufacturer",      // Manufacturer select dropdown
        "piecesChip",        // Piece-count chips - data-min / data-max (empty = unbounded) fill the inputs below
        "piecesMin",         // Custom piece-count "from" input - what the filter actually reads
        "piecesMax",         // Custom piece-count "to" input
        "difficultyTier",    // Difficulty tier checkboxes (members) - items carry data-difficulty-tier
        "listingTypeSelect", // Listing type select dropdown (sell-swap)
        "priceMin",          // Price min input (sell-swap)
        "priceMax",          // Price max input (sell-swap)
        "visibleCount",      // Counter showing visible items
        "noResults"          // No results message
    ];

    static classes = ["hidden"];

    connect() {
        this.initializeFilters();
        this.updateVisibleCount();
    }

    initializeFilters() {
        const manufacturerCounts = new Map();
        const pieceCounts = new Set();

        // Collect unique values and counts from items
        this.itemTargets.forEach(item => {
            const manufacturer = item.dataset.manufacturer;
            if (manufacturer) {
                manufacturerCounts.set(manufacturer, (manufacturerCounts.get(manufacturer) || 0) + 1);
            }
            pieceCounts.add(parseInt(item.dataset.piecesCount, 10));
        });

        // Populate manufacturer dropdown with counts and Tom Select
        this.populateManufacturers(manufacturerCounts);

        // Show only relevant piece count options
        this.updatePiecesChipVisibility(Array.from(pieceCounts));
    }

    populateManufacturers(manufacturerCounts) {
        if (!this.hasManufacturerTarget) return;

        // Sort manufacturers by count descending, then alphabetically
        const sorted = Array.from(manufacturerCounts.entries()).sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]));

        sorted.forEach(([manufacturer, count]) => {
            const option = document.createElement('option');
            option.value = manufacturer;
            option.textContent = `${manufacturer} (${count})`;
            this.manufacturerTarget.appendChild(option);
        });

        // Read placeholder from the empty option, then remove it so Tom Select
        // shows the placeholder config instead of treating it as a selected item
        const emptyOption = this.manufacturerTarget.querySelector('option[value=""]');
        const placeholder = emptyOption ? emptyOption.textContent.trim() : '';
        if (emptyOption) emptyOption.remove();

        this.tomSelect = new TomSelect(this.manufacturerTarget, {
            placeholder: placeholder,
            plugins: { clear_button: { title: '' } },
        });

        this.tomSelect.on('change', () => {
            this.tomSelect.blur();
        });
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
            this.tomSelect = null;
        }
    }

    updatePiecesChipVisibility(pieceCounts) {
        this.piecesChipTargets.forEach(chip => {
            const range = this.chipRange(chip);
            if (range.min === null && range.max === null) return; // "All" - always visible

            const hasMatch = pieceCounts.some(count => this.matchesPiecesRange(count, range));

            const wrapper = chip.closest('.form-option');
            if (wrapper) {
                wrapper.style.display = hasMatch ? '' : 'none';
            }
        });
    }

    pickPieces(event) {
        const range = this.chipRange(event.currentTarget);

        if (this.hasPiecesMinTarget) this.piecesMinTarget.value = range.min ?? '';
        if (this.hasPiecesMaxTarget) this.piecesMaxTarget.value = range.max ?? '';

        this.filter();
    }

    customPieces() {
        this.syncActiveChip();
        this.filter();
    }

    // Checks the chip equal to the typed range, none when it is a custom one
    syncActiveChip() {
        const range = this.getSelectedPiecesRange();

        this.piecesChipTargets.forEach(chip => {
            const chipRange = this.chipRange(chip);
            chip.checked = chipRange.min === range.min && chipRange.max === range.max;
        });
    }

    chipRange(chip) {
        return {
            min: this.parsePieces(chip.dataset.min),
            max: this.parsePieces(chip.dataset.max),
        };
    }

    parsePieces(value) {
        const count = parseInt(value, 10);
        return Number.isInteger(count) && count >= 1 ? count : null;
    }

    filter() {
        const searchTerm = this.normalizeString(this.hasSearchTarget ? this.searchTarget.value : '');
        const manufacturer = this.hasManufacturerTarget ? this.manufacturerTarget.value : '';
        const piecesRange = this.getSelectedPiecesRange();
        const difficultyTiers = this.getSelectedDifficultyTiers();
        const listingType = this.getSelectedListingType();
        const priceRange = this.getPriceRange();

        let visibleCount = 0;

        this.itemTargets.forEach(item => {
            const isVisible = this.itemMatchesFilters(item, searchTerm, manufacturer, piecesRange, difficultyTiers, listingType, priceRange);
            item.style.display = isVisible ? '' : 'none';
            if (isVisible) visibleCount++;
        });

        this.updateVisibleCount(visibleCount);
        this.updateNoResultsMessage(visibleCount === 0);
    }

    itemMatchesFilters(item, searchTerm, manufacturer, piecesRange, difficultyTiers, listingType, priceRange) {
        // Text search - matches name, alternative name, code, or EAN
        if (searchTerm) {
            const name = this.normalizeString(item.dataset.puzzleName || '');
            const altName = this.normalizeString(item.dataset.puzzleAlternativeName || '');
            const code = this.normalizeString(item.dataset.puzzleCode || '');
            const ean = this.normalizeString(item.dataset.ean || '');

            const matchesSearch = name.includes(searchTerm) ||
                                  altName.includes(searchTerm) ||
                                  code.includes(searchTerm) ||
                                  ean.includes(searchTerm);
            if (!matchesSearch) return false;
        }

        // Manufacturer filter
        if (manufacturer && item.dataset.manufacturer !== manufacturer) {
            return false;
        }

        // Pieces count filter
        if (!this.matchesPiecesRange(parseInt(item.dataset.piecesCount, 10), piecesRange)) {
            return false;
        }

        // Difficulty tier filter: "0" = not rated yet, which has its own chip, as on the puzzle database.
        // An item without the attribute (re-rendered by a turbo stream) is never hidden by it.
        if (difficultyTiers.size > 0 && item.dataset.difficultyTier !== undefined && !difficultyTiers.has(item.dataset.difficultyTier)) {
            return false;
        }

        // Listing type filter (sell-swap)
        if (listingType && listingType !== 'all') {
            const itemListingType = item.dataset.listingType;
            // 'both' matches swap and sell filters (but not 'free')
            if (itemListingType !== listingType && !(itemListingType === 'both' && (listingType === 'swap' || listingType === 'sell'))) {
                return false;
            }
        }

        // Price range filter (sell-swap)
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

    // Inclusive bounds, null = unbounded; swapped bounds are put in order
    getSelectedPiecesRange() {
        let min = this.hasPiecesMinTarget ? this.parsePieces(this.piecesMinTarget.value) : null;
        let max = this.hasPiecesMaxTarget ? this.parsePieces(this.piecesMaxTarget.value) : null;

        if (min !== null && max !== null && min > max) {
            [min, max] = [max, min];
        }

        return { min, max };
    }

    getSelectedDifficultyTiers() {
        return new Set(this.difficultyTierTargets.filter(checkbox => checkbox.checked).map(checkbox => checkbox.value));
    }

    getSelectedListingType() {
        if (!this.hasListingTypeSelectTarget) return '';
        return this.listingTypeSelectTarget.value || '';
    }

    getPriceRange() {
        return {
            min: this.hasPriceMinTarget && this.priceMinTarget.value ? parseFloat(this.priceMinTarget.value) : null,
            max: this.hasPriceMaxTarget && this.priceMaxTarget.value ? parseFloat(this.priceMaxTarget.value) : null
        };
    }

    matchesPiecesRange(count, range) {
        return (range.min === null || count >= range.min) && (range.max === null || count <= range.max);
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

        // Reset manufacturer
        if (this.hasManufacturerTarget && this.tomSelect) {
            this.tomSelect.setValue('', true);
        }

        // Reset pieces to "All"
        if (this.hasPiecesMinTarget) this.piecesMinTarget.value = '';
        if (this.hasPiecesMaxTarget) this.piecesMaxTarget.value = '';
        this.syncActiveChip();

        this.difficultyTierTargets.forEach(checkbox => {
            checkbox.checked = false;
        });

        // Reset listing type to "all"
        if (this.hasListingTypeSelectTarget) {
            this.listingTypeSelectTarget.value = 'all';
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
