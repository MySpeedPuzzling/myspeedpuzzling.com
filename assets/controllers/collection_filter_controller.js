import { Controller } from '@hotwired/stimulus';

/**
 * Collection Filter Controller
 *
 * A focused, lightweight filter for collection items.
 * Features: text search, manufacturer dropdown, pieces count radio pills.
 */
export default class extends Controller {
    static targets = [
        "item",           // Each filterable collection item
        "search",         // Text search input
        "manufacturer",   // Manufacturer select dropdown
        "piecesRadio",    // Pieces count radio buttons
        "visibleCount",   // Counter showing visible items
        "noResults"       // No results message
    ];

    static classes = ["hidden"];

    // Pieces count ranges configuration
    static ranges = [
        { value: '0-499', min: 0, max: 499 },
        { value: '500', min: 500, max: 500 },
        { value: '501-999', min: 501, max: 999 },
        { value: '1000', min: 1000, max: 1000 },
        { value: '1001-1499', min: 1001, max: 1499 },
        { value: '1500', min: 1500, max: 1500 },
        { value: '1501-1999', min: 1501, max: 1999 },
        { value: '2000', min: 2000, max: 2000 },
        { value: '2001-', min: 2001, max: Infinity },
    ];

    connect() {
        this.initializeFilters();
        this.updateVisibleCount();
    }

    initializeFilters() {
        const manufacturers = new Set();
        const pieceCounts = new Set();

        // Collect unique values from items
        this.itemTargets.forEach(item => {
            const manufacturer = item.dataset.manufacturer;
            if (manufacturer) {
                manufacturers.add(manufacturer);
            }
            pieceCounts.add(parseInt(item.dataset.piecesCount, 10));
        });

        // Populate manufacturer dropdown
        this.populateManufacturers(Array.from(manufacturers).sort());

        // Show only relevant piece count options
        this.updatePiecesRadioVisibility(Array.from(pieceCounts));
    }

    populateManufacturers(manufacturers) {
        if (!this.hasManufacturerTarget) return;

        manufacturers.forEach(manufacturer => {
            const option = document.createElement('option');
            option.value = manufacturer;
            option.textContent = manufacturer;
            this.manufacturerTarget.appendChild(option);
        });
    }

    updatePiecesRadioVisibility(pieceCounts) {
        if (!this.hasPiecesRadioTarget) return;

        this.piecesRadioTargets.forEach(radio => {
            const rangeValue = radio.dataset.range;
            if (!rangeValue) return; // "All" option - always visible

            const range = this.constructor.ranges.find(r => r.value === rangeValue);
            if (!range) return;

            const hasMatch = pieceCounts.some(count =>
                count >= range.min && count <= range.max
            );

            const wrapper = radio.closest('.form-option');
            if (wrapper) {
                wrapper.style.display = hasMatch ? '' : 'none';
            }
        });
    }

    filter() {
        const searchTerm = this.normalizeString(this.hasSearchTarget ? this.searchTarget.value : '');
        const manufacturer = this.hasManufacturerTarget ? this.manufacturerTarget.value : '';
        const piecesRange = this.getSelectedPiecesRange();

        let visibleCount = 0;

        this.itemTargets.forEach(item => {
            const isVisible = this.itemMatchesFilters(item, searchTerm, manufacturer, piecesRange);
            item.style.display = isVisible ? '' : 'none';
            if (isVisible) visibleCount++;
        });

        this.updateVisibleCount(visibleCount);
        this.updateNoResultsMessage(visibleCount === 0);
    }

    itemMatchesFilters(item, searchTerm, manufacturer, piecesRange) {
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
        if (piecesRange) {
            const piecesCount = parseInt(item.dataset.piecesCount, 10);
            if (!this.matchesPiecesRange(piecesCount, piecesRange)) {
                return false;
            }
        }

        return true;
    }

    getSelectedPiecesRange() {
        if (!this.hasPiecesRadioTarget) return '';

        const checked = this.piecesRadioTargets.find(radio => radio.checked);
        return checked ? (checked.dataset.range || '') : '';
    }

    matchesPiecesRange(count, rangeValue) {
        if (!rangeValue) return true;

        const range = this.constructor.ranges.find(r => r.value === rangeValue);
        if (!range) return true;

        return count >= range.min && count <= range.max;
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
        if (this.hasManufacturerTarget) {
            this.manufacturerTarget.value = '';
        }

        // Reset pieces to "All"
        if (this.hasPiecesRadioTarget) {
            const allRadio = this.piecesRadioTargets.find(r => !r.dataset.range);
            if (allRadio) {
                allRadio.checked = true;
            }
        }

        this.filter();
    }

    normalizeString(str) {
        if (!str) return '';
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }
}
