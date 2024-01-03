import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["piecesCount", "puzzleName", "manufacturer", "puzzleItem", "availability", "withTime"];

    connect() {
        this.populateFilters();
    }

    populateFilters() {
        let manufacturers = new Set();

        this.puzzleItemTargets.forEach(puzzle => {
            manufacturers.add(puzzle.dataset.manufacturer);
        });

        this.populateSelect(this.manufacturerTarget, Array.from(manufacturers), "- Výrobce -", false);
    }

    populateSelect(selectElement, options, placeholder, isNumeric) {
        // Add placeholder as the first option
        let placeholderOption = document.createElement('option');
        placeholderOption.value = "";
        placeholderOption.innerHTML = placeholder;
        selectElement.appendChild(placeholderOption);

        // Sort options
        if (isNumeric) {
            options.sort((a, b) => a - b);
        } else {
            options.sort();
        }

        options.forEach(option => {
            let opt = document.createElement('option');
            opt.value = option;
            opt.innerHTML = option;
            selectElement.appendChild(opt);
        });
    }

    filterPuzzles() {
        const piecesCountRange = this.piecesCountTarget.value;
        const searchInput = this.normalizeString(this.puzzleNameTarget.value);
        const manufacturer = this.manufacturerTarget.value;
        const onlyAvailable = this.availabilityTarget.checked;
        const onlyWithTime = this.withTimeTarget.checked;
        let anyVisible = false;

        this.puzzleItemTargets.forEach(puzzle => {
            const puzzlePiecesCount = parseInt(puzzle.dataset.piecesCount, 10);
            const matchesPiecesCount = this.matchesPiecesCount(puzzlePiecesCount, piecesCountRange);
            const puzzleCode = puzzle.dataset.puzzleCode || "";
            const puzzleName = this.normalizeString(puzzle.dataset.puzzleName);
            const puzzleAlternativeName = this.normalizeString(puzzle.dataset.puzzleAlternativeName);
            const matchesManufacturer = manufacturer === "" || puzzle.dataset.manufacturer === manufacturer;
            const matchesAvailability = !onlyAvailable || puzzle.dataset.available === "1";
            const matchesWithTime = !onlyWithTime || puzzle.dataset.hasTime === "1";
            const matchesNameOrCode = searchInput === "" || puzzleName.includes(searchInput) || puzzleAlternativeName.includes(searchInput) || puzzleCode.includes(searchInput);

            const isVisible = matchesPiecesCount && matchesNameOrCode && matchesManufacturer && matchesAvailability && matchesWithTime;

            puzzle.style.display = isVisible ? '' : 'none';
            if (isVisible) {
                anyVisible = true;
            }

            this.updateNoResultsMessage(anyVisible);
        });
    }

    matchesPiecesCount(puzzlePiecesCount, range) {
        if (range === "") return true;

        // Check if the range contains a dash, indicating it's a range
        if (range.includes('-')) {
            const [min, max] = range.split('-').map(Number);
            // If max is not defined (i.e., range like "1001-"), compare only with min
            return puzzlePiecesCount >= min && (max ? puzzlePiecesCount <= max : true);
        } else {
            // If no dash, it's an exact value
            return puzzlePiecesCount === parseInt(range, 10);
        }
    }

    updateNoResultsMessage(anyVisible) {
        const noResultsElement = document.getElementById('filter-no-results');
        if (noResultsElement) {
            if (anyVisible) {
                noResultsElement.classList.add('hidden');
            } else {
                noResultsElement.classList.remove('hidden');
            }
        }
    }

    normalizeString(str) {
        return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }
}
