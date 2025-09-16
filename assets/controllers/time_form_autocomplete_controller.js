import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['brand', 'puzzle', 'competition', 'newPuzzle'];

    uuidRegex= /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    initialBrandValue = '';
    initialPuzzleValue = '';

    initialize() {
        this._onCompetitionConnect = this._onCompetitionConnect.bind(this);
        this._onBrandConnect = this._onBrandConnect.bind(this);
        this._onPuzzleConnect = this._onPuzzleConnect.bind(this);
    }

    connect() {
        this.initialBrandValue = this.brandTarget.value;
        this.initialPuzzleValue = this.puzzleTarget.value;
        this.initialCompetitionValue = this.competitionTarget.value;
        this.brandTarget.addEventListener('autocomplete:pre-connect', this._onBrandConnect);
        this.puzzleTarget.addEventListener('autocomplete:pre-connect', this._onPuzzleConnect);
        this.competitionTarget.addEventListener('autocomplete:pre-connect', this._onCompetitionConnect);
    }

    disconnect() {
        // Remove listeners when the controller is disconnected to avoid side-effects
        this.brandTarget.removeEventListener('autocomplete:pre-connect', this._onBrandConnect);
        this.puzzleTarget.removeEventListener('autocomplete:pre-connect', this._onPuzzleConnect);
        this.competitionTarget.removeEventListener('autocomplete:pre-connect', this._onCompetitionConnect);
    }

    _onBrandConnect(event) {
        event.detail.options.render.option_create = function (data, escape) {
            return '<div class="create py-2"><i class="ci-add small"></i> Add new brand: <strong>' + escape(data.input) + '</strong></div>';
        };

        event.detail.options.onChange = (value) => {
            this.onBrandValueChanged(value);
        };


        event.detail.options.create = (input) => {
            const tom = this.brandTarget.tomselect;

            const normalizedInput = this.removeDiacritics(input).toLowerCase();

            for (const [value, optionData] of Object.entries(tom.options)) {
                const normalizedOption = this.removeDiacritics(optionData.text).toLowerCase();

                // Partial matching: e.g. "ravensbur" matches "Ravensburger (499)"
                if (normalizedOption.includes(normalizedInput)) {
                    // Instead of creating a new brand, select the existing one
                    tom.addItem(value);

                    return false;
                }
            }

            // Otherwise, if no partial match found, create a new item
            // This is the original behaviour
            return {
                value: input,
                text: input,
            };
        };
    }

    _onPuzzleConnect(event) {
        event.detail.options.render.option_create = function(data, escape) {
            return '<div class="create py-2"><i class="ci-add small"></i> Add new puzzle: <strong>' + escape(data.input) + '</strong></div>';
        };

        event.detail.options.onChange = (value) => {
            this.onPuzzleValueChanged(value);
        };

        event.detail.options.onInitialize = () => {
            this.handleInitialValues();
        };
    }

    _onCompetitionConnect(event) {
        event.detail.options.onChange = (value) => {
            this.onCompetitionValueChanged(value);
        };
    }

    onBrandValueChanged(value) {
        if (value !== this.initialBrandValue) {
            this.puzzleTarget.tomselect.clear();
            this.initialBrandValue = value;
        }

        if (value) {
            this.puzzleTarget.tomselect.enable();
            this.puzzleTarget.tomselect.settings.placeholder = this.puzzleTarget.dataset.choosePuzzlePlaceholder;
            this.puzzleTarget.tomselect.inputState();

            if (this.uuidRegex.test(value)) {
                this.fetchPuzzleOptions(value, true);
            } else {
                this.onNewBrandCreated();
            }
        } else {
            this.disablePuzzleField();
        }
    }

    onPuzzleValueChanged(value) {
        if (value) {
            if (this.uuidRegex.test(value)) {
                this.newPuzzleTarget.classList.add('d-none');
            } else {
                this.newPuzzleTarget.classList.remove('d-none');
            }
        } else {
            this.newPuzzleTarget.classList.add('d-none');
        }

        this.puzzleTarget.tomselect.blur();
    }

    onCompetitionValueChanged(value) {
        this.competitionTarget.tomselect.blur();
    }

    fetchPuzzleOptions(brandValue, openDropdown) {
        const fetchUrl = this.brandTarget.getAttribute('data-fetch-url');

        if (this.uuidRegex.test(brandValue)) {
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

                        this.updatePuzzleSelectValues(data, openDropdown);

                        if (existingValue && this.puzzleTarget.tomselect.getOption(existingValue)) {
                            this.puzzleTarget.tomselect.setValue(existingValue);
                        }

                        if (this.initialPuzzleValue && !this.puzzleTarget.tomselect.getOption(this.initialBrandValue) && !this.uuidRegex.test(this.initialPuzzleValue)) {
                            this.puzzleTarget.tomselect.createItem(this.initialPuzzleValue);
                        }

                        this.initialPuzzleValue = '';
                    }
                })
                .catch(error => {
                    console.error('There has been a problem with your fetch operation:', error);
                });
        }
    }

    updatePuzzleSelectValues(data, openDropdown) {
        const puzzleTomSelect = this.puzzleTarget.tomselect;
        puzzleTomSelect.clear();
        puzzleTomSelect.clearOptions();
        puzzleTomSelect.addOptions(data);
        puzzleTomSelect.refreshOptions(openDropdown);
    }

    onNewBrandCreated() {
        this.newPuzzleTarget.classList.remove('d-none');
    }

    handleInitialValues() {
        if (this.initialBrandValue) {
            if (!this.brandTarget.tomselect.getOption(this.initialBrandValue) && !this.uuidRegex.test(this.initialBrandValue)) {
                this.brandTarget.tomselect.createItem(this.initialBrandValue);

                if (this.initialPuzzleValue) {
                    this.puzzleTarget.tomselect.createItem(this.initialPuzzleValue);
                }
            } else {
                this.fetchPuzzleOptions(this.brandTarget.value, false);
            }
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

    removeDiacritics(str) {
        // normalize and remove "diacritics" characters (e.g. é, ü, etc.)
        return str.normalize('NFD').replace(/\p{M}/gu, '');
    }
}
