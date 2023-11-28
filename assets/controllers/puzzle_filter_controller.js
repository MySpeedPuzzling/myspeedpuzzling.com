import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["piecesCount", "puzzleName", "manufacturer", "puzzleItem"];

    connect() {
        this.populateFilters();
    }

    populateFilters() {
        let piecesCounts = new Set();
        let manufacturers = new Set();

        this.puzzleItemTargets.forEach(puzzle => {
            piecesCounts.add(puzzle.dataset.piecesCount);
            manufacturers.add(puzzle.dataset.manufacturer);
        });

        this.populateSelect(this.piecesCountTarget, Array.from(piecesCounts), "- Dílků -", true);
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
        const piecesCount = this.piecesCountTarget.value;
        const puzzleNameInput = this.normalizeString(this.puzzleNameTarget.value);
        const manufacturer = this.manufacturerTarget.value;

        this.puzzleItemTargets.forEach(puzzle => {
            const matchesPiecesCount = piecesCount === "" || puzzle.dataset.piecesCount === piecesCount;
            const puzzleName = this.normalizeString(puzzle.dataset.puzzleName);
            const puzzleAlternativeName = this.normalizeString(puzzle.dataset.puzzleAlternativeName);
            const matchesName = puzzleNameInput === "" || puzzleName.includes(puzzleNameInput) || puzzleAlternativeName.includes(puzzleNameInput);
            const matchesManufacturer = manufacturer === "" || puzzle.dataset.manufacturer === manufacturer;

            puzzle.style.display = matchesPiecesCount && matchesName && matchesManufacturer ? '' : 'none';
        });
    }

    normalizeString(str) {
        return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }
}
