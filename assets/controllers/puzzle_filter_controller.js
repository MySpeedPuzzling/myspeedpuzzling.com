import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["piecesCount", "puzzleName", "manufacturer", "puzzleItem", "availability", "withTime", "tagFilter"];

    connect() {
        this.populateFilters();

        if (this.hasTagFilterTarget) {
            this.populateTagFilter();
        }
    }

    populateFilters() {
        let manufacturers = new Set();
        let pieceCounts = new Set();

        this.puzzleItemTargets.forEach(puzzle => {
            manufacturers.add(puzzle.dataset.manufacturer);
            pieceCounts.add(parseInt(puzzle.dataset.piecesCount, 10));
        });

        this.populateSelect(this.manufacturerTarget, Array.from(manufacturers), false);
        this.populatePiecesCountFilter(Array.from(pieceCounts));
    }

    populatePiecesCountFilter(pieceCounts) {
        // Define ranges with their labels
        const ranges = [
            { value: '0-499', min: 0, max: 499, label: '< 500' },
            { value: '500', min: 500, max: 500, label: '500' },
            { value: '501-999', min: 501, max: 999, label: '501-999' },
            { value: '1000', min: 1000, max: 1000, label: '1000' },
            { value: '1001-1499', min: 1001, max: 1499, label: '1001-1499' },
            { value: '1500', min: 1500, max: 1500, label: '1500' },
            { value: '1501-1999', min: 1501, max: 1999, label: '1501-1999' },
            { value: '2000', min: 2000, max: 2000, label: '2000' },
            { value: '2001-', min: 2001, max: Infinity, label: '> 2000' },
        ];

        // Clear existing options except the first placeholder
        while (this.piecesCountTarget.options.length > 1) {
            this.piecesCountTarget.remove(1);
        }

        // Add only ranges that have matching puzzles
        ranges.forEach(range => {
            const hasMatch = pieceCounts.some(count =>
                count >= range.min && count <= range.max
            );

            if (hasMatch) {
                const option = new Option(range.label, range.value);
                this.piecesCountTarget.add(option);
            }
        });
    }

    populateTagFilter() {
        const allTags = this.puzzleItemTargets.flatMap(puzzle =>
            JSON.parse(puzzle.dataset.tags.replace(/&quot;/g, '"'))
        );

        const uniqueTags = Array.from(new Set(allTags.map(tag => tag.name))).sort();

        uniqueTags.forEach(tagName => {
            const option = new Option(tagName, tagName);
            this.tagFilterTarget.add(option);
        });
    }

    populateSelect(selectElement, options, isNumeric) {
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
        const onlyAvailable = this.hasAvailabilityTarget && this.availabilityTarget.checked;
        const onlyWithTime = this.hasWithTimeTarget && this.withTimeTarget.checked;
        const selectedTag = this.hasTagFilterTarget ? this.tagFilterTarget.value : null;

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
            const tags = JSON.parse(puzzle.dataset.tags.replace(/&quot;/g, '"') || '[]');
            const matchesTag = !selectedTag || tags.some(tag => tag.name === selectedTag);

            const isVisible = matchesTag && matchesPiecesCount && matchesNameOrCode && matchesManufacturer && matchesAvailability && matchesWithTime;

            puzzle.style.display = isVisible ? '' : 'none';
        });

        this.updateNoResultsMessage();
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

    updateNoResultsMessage() {
        document.querySelectorAll('.filter-no-results').forEach(noResultsElement => {
            // Find the common ancestor element that contains both the noResultsElement and puzzle items
            let commonAncestor = noResultsElement.parentElement;
            while (commonAncestor && !commonAncestor.querySelector('[data-puzzle-filter-target="puzzleItem"]')) {
                commonAncestor = commonAncestor.parentElement;
            }

            if (commonAncestor) {
                const puzzleItems = commonAncestor.querySelectorAll('[data-puzzle-filter-target="puzzleItem"]');
                const anyVisible = Array.from(puzzleItems).some(item => item.style.display !== 'none');
                noResultsElement.classList.toggle('hidden', anyVisible);
            }
        });
    }

    normalizeString(str) {
        return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }
}
