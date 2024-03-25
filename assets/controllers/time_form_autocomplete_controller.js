import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['brand', 'puzzle', 'newPuzzle'];

    initialize() {
        this._onBrandConnect = this._onBrandConnect.bind(this);
        this._onPuzzleConnect = this._onPuzzleConnect.bind(this);
    }

    connect() {
        this.brandTarget.addEventListener('autocomplete:pre-connect', this._onBrandConnect);
        this.puzzleTarget.addEventListener('autocomplete:pre-connect', this._onPuzzleConnect);
    }

    disconnect() {
        // Remove listeners when the controller is disconnected to avoid side-effects
        this.brandTarget.removeEventListener('autocomplete:pre-connect', this._onBrandConnect);
        this.puzzleTarget.removeEventListener('autocomplete:pre-connect', this._onPuzzleConnect);
    }

    _onBrandConnect(event) {
        event.detail.options.onChange = (value) => {
            this.onBrandValueChanged(value);
        };
    }

    _onPuzzleConnect(event) {
        event.detail.options.onChange = (value) => {
            this.onPuzzleValueChanged(value);
        };

        event.detail.options.onInitialize = () => {
            this.handleInitialPuzzleValue();
        };
    }

    onBrandValueChanged(value) {
        this.puzzleTarget.tomselect.clear();

        // Regular expression to validate UUID format
        const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

        if (value) {
            this.puzzleTarget.tomselect.enable();
            this.puzzleTarget.tomselect.settings.placeholder = this.puzzleTarget.dataset.choosePuzzlePlaceholder;
            this.puzzleTarget.tomselect.inputState();

            if (uuidRegex.test(value)) {
                this.fetchPuzzleOptions(value);
            } else {
                // Value is not a UUID, treat it as a valid input for a new entry
                this.onNewBrandCreated();
            }
        } else {
            // No value entered, disable the second field
            this.disablePuzzleField();
        }
    }

    onPuzzleValueChanged(value) {
        // Regular expression to validate UUID format
        // Validates UUIDs like 123e4567-e89b-12d3-a456-426614174000
        const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

        if (value) {
            if (uuidRegex.test(value)) {
                this.newPuzzleTarget.classList.add('d-none');
            } else {
                this.newPuzzleTarget.classList.remove('d-none');
            }
        } else {
            // No value entered, disable the second field
            this.newPuzzleTarget.classList.add('d-none');
        }

        this.puzzleTarget.tomselect.blur();
    }

    fetchPuzzleOptions(brandValue) {
        const fetchUrl = this.brandTarget.getAttribute('data-fetch-url');
        const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

        if (uuidRegex.test(brandValue)) {
            // Proceed with fetch if value is a valid UUID

            fetch(`${fetchUrl}?brand=${brandValue}`)
                .then(response => {
                    if (response.status === 404) {
                        this.onNewBrandCreated();
                        return null;
                    }

                    if (!response.ok) {
                        console.error('Network response was not ok');
                        return null;
                    }

                    return response.json();
                })
                .then(data => {
                    if (data) {
                        const existingValue = this.puzzleTarget.tomselect.getValue();

                        this.updatePuzzleSelectValues(data);

                        if (existingValue && this.puzzleTarget.tomselect.getOption(existingValue)) {
                            this.puzzleTarget.tomselect.setValue(existingValue);
                        }
                    }
                })
                .catch(error => {
                    console.error('There has been a problem with your fetch operation:', error);
                });
        }
    }

    updatePuzzleSelectValues(data) {
        const puzzleTomSelect = this.puzzleTarget.tomselect;
        puzzleTomSelect.clear();
        puzzleTomSelect.clearOptions();
        puzzleTomSelect.addOptions(data);
        puzzleTomSelect.refreshOptions(true);
    }

    onNewBrandCreated() {
        this.newPuzzleTarget.classList.remove('d-none');
    }

    handleInitialPuzzleValue() {
        if (this.brandTarget.value) {
            this.fetchPuzzleOptions(this.brandTarget.value);
        } else {
            this.disablePuzzleField();
        }
    }

    disablePuzzleField() {
        const puzzleTomSelect = this.puzzleTarget.tomselect;
        puzzleTomSelect.clearOptions();
        puzzleTomSelect.disable();
        puzzleTomSelect.settings.placeholder = this.puzzleTarget.dataset.chooseBrandPlaceholder;
        puzzleTomSelect.inputState();

        this.newPuzzleTarget.classList.add('d-none');
    }
}
