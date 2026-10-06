import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['manufacturer', 'puzzle', 'scanMessage'];

    static values = {
        currentPuzzleId: String,
        // puzzle_by_ean_search with __EAN__ for the scanned code
        eanSearchUrl: String,
        notFoundMessage: String,
        samePuzzleMessage: String,
        multiplePuzzlesMessage: String,
        searchFailedMessage: String,
    };

    uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    initialize() {
        this._onManufacturerConnect = this._onManufacturerConnect.bind(this);
        this._onPuzzleConnect = this._onPuzzleConnect.bind(this);
    }

    connect() {
        this.manufacturerTarget.addEventListener('autocomplete:pre-connect', this._onManufacturerConnect);
        this.puzzleTarget.addEventListener('autocomplete:pre-connect', this._onPuzzleConnect);
    }

    disconnect() {
        this.manufacturerTarget.removeEventListener('autocomplete:pre-connect', this._onManufacturerConnect);
        this.puzzleTarget.removeEventListener('autocomplete:pre-connect', this._onPuzzleConnect);
    }

    _onManufacturerConnect(event) {
        event.detail.options.onChange = (value) => {
            this.onManufacturerValueChanged(value);
        };
    }

    _onPuzzleConnect(event) {
        event.detail.options.onChange = (value) => {
            this.onPuzzleValueChanged(value);
        };

        // Initialize puzzle field state after TomSelect is ready
        event.detail.options.onInitialize = () => {
            this.handleInitialState();
        };
    }

    onManufacturerValueChanged(value) {
        const puzzleTom = this.puzzleTarget.tomselect;
        if (!puzzleTom) return;

        puzzleTom.clear();
        puzzleTom.clearOptions();

        if (value && this.uuidRegex.test(value)) {
            puzzleTom.enable();
            puzzleTom.settings.placeholder = this.puzzleTarget.dataset.choosePuzzlePlaceholder;
            puzzleTom.inputState();

            this.fetchPuzzleOptions(value);
        } else {
            this.disablePuzzleField();
        }
    }

    onPuzzleValueChanged(value) {
        const puzzleTom = this.puzzleTarget.tomselect;
        if (value && puzzleTom) {
            puzzleTom.blur();
            this.hideScanMessage();
        }
    }

    /**
     * The other box's barcode (`barcode-scanner:scanned`): the puzzles carrying it become the puzzle list - one is
     * picked, several are offered, whatever brand they are under. Their brand shows when they share one. This puzzle
     * itself never counts; nothing found leaves the search to the brand and puzzle pickers.
     */
    async scanned(event) {
        const ean = event.detail?.code;

        if (!ean || !this.hasEanSearchUrlValue) {
            return;
        }

        this.hideScanMessage();

        let data;
        try {
            const response = await fetch(this.eanSearchUrlValue.replace('__EAN__', encodeURIComponent(ean)));
            if (!response.ok) {
                throw new Error(`EAN search answered ${response.status}`);
            }
            data = await response.json();
        } catch (error) {
            console.error('Error searching puzzle by EAN:', error);
            this.showScanMessage(this.searchFailedMessageValue, ean);
            return;
        }

        const others = data.puzzles.filter(puzzle => puzzle.id !== this.currentPuzzleIdValue);

        if (others.length === 0) {
            this.showScanMessage(data.puzzles.length > 0 ? this.samePuzzleMessageValue : this.notFoundMessageValue, ean);

            // Not found: the search stays manual, but a brand known by the code's prefix is picked already
            if (data.puzzles.length === 0 && data.brands.length === 1) {
                this.selectManufacturer(data.brands[0].id);
            }
            return;
        }

        const manufacturerTom = this.manufacturerTarget.tomselect;
        const puzzleTom = this.puzzleTarget.tomselect;
        if (!manufacturerTom || !puzzleTom) return;

        // Silently: a brand change would load the brand's whole list over the scanned puzzles
        const brandIds = [...new Set(others.map(puzzle => puzzle.brandId))];
        if (brandIds.length === 1 && manufacturerTom.options[brandIds[0]]) {
            manufacturerTom.setValue(brandIds[0], true);
        } else {
            manufacturerTom.clear(true);
        }

        puzzleTom.clear(true);
        puzzleTom.clearOptions();
        puzzleTom.enable();
        puzzleTom.settings.placeholder = this.puzzleTarget.dataset.choosePuzzlePlaceholder;
        puzzleTom.inputState();
        puzzleTom.addOptions(others.map(puzzle => puzzle.option));

        if (others.length === 1) {
            puzzleTom.setValue(others[0].id);
            return;
        }

        // Several puzzles carry the code: only those are offered (choosing a brand brings back its whole list)
        puzzleTom.refreshOptions(true);
        puzzleTom.focus();
        this.showScanMessage(this.multiplePuzzlesMessageValue, ean);
    }

    selectManufacturer(manufacturerId) {
        const manufacturerTom = this.manufacturerTarget.tomselect;
        if (!manufacturerTom) return;

        // Selecting another brand loads its puzzles (onManufacturerValueChanged); the same brand loads them again,
        // an earlier scan may have left only the scanned puzzles in the list
        if (manufacturerTom.getValue() !== manufacturerId) {
            manufacturerTom.setValue(manufacturerId);
        } else {
            this.fetchPuzzleOptions(manufacturerId);
        }
    }

    showScanMessage(message, ean) {
        if (!this.hasScanMessageTarget) return;

        this.scanMessageTarget.textContent = message.replace('%ean%', ean);
        this.scanMessageTarget.classList.remove('d-none');
    }

    hideScanMessage() {
        if (!this.hasScanMessageTarget) return;

        this.scanMessageTarget.classList.add('d-none');
    }

    fetchPuzzleOptions(manufacturerId) {
        const fetchUrl = this.manufacturerTarget.getAttribute('data-fetch-url');
        const currentPuzzleId = this.currentPuzzleIdValue;

        fetch(`${fetchUrl}?brand=${manufacturerId}`)
            .then(response => {
                if (!response.ok) {
                    console.error('Network response was not ok');
                    return null;
                }
                return response.json();
            })
            .then(data => {
                if (data && data.results) {
                    // Filter out current puzzle from options
                    const filteredResults = data.results.filter(
                        puzzle => puzzle.value !== currentPuzzleId
                    );
                    this.updatePuzzleSelectValues(filteredResults);
                }
            })
            .catch(error => {
                console.error('Error fetching puzzle options:', error);
            });
    }

    updatePuzzleSelectValues(data) {
        const puzzleTomSelect = this.puzzleTarget.tomselect;
        if (!puzzleTomSelect) return;

        puzzleTomSelect.clearOptions();
        puzzleTomSelect.addOptions(data);
        puzzleTomSelect.refreshOptions(true);
    }

    handleInitialState() {
        // Check if manufacturer already has a value (shouldn't normally happen on fresh form)
        const manufacturerValue = this.manufacturerTarget.value;
        if (manufacturerValue && this.uuidRegex.test(manufacturerValue)) {
            this.fetchPuzzleOptions(manufacturerValue);
        } else {
            this.disablePuzzleField();
        }
    }

    disablePuzzleField() {
        const puzzleTomSelect = this.puzzleTarget.tomselect;
        if (!puzzleTomSelect) return;

        puzzleTomSelect.clearOptions();
        puzzleTomSelect.disable();
        puzzleTomSelect.settings.placeholder = this.puzzleTarget.dataset.chooseManufacturerPlaceholder;
        puzzleTomSelect.inputState();
    }
}
