import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['manufacturer', 'puzzle'];

    static values = {
        currentPuzzleId: String,
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
        }
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
