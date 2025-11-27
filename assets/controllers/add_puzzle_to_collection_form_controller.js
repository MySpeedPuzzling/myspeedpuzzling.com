import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['brand', 'puzzle', 'newPuzzle', 'collection', 'newCollection'];
    static values = {
        systemId: String
    }

    uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    initialBrandValue = '';
    initialPuzzleValue = '';

    initialize() {
        this._onBrandConnect = this._onBrandConnect.bind(this);
        this._onPuzzleConnect = this._onPuzzleConnect.bind(this);
        this._onCollectionConnect = this._onCollectionConnect.bind(this);
    }

    connect() {
        this.initialBrandValue = this.brandTarget.value;
        this.initialPuzzleValue = this.puzzleTarget.value;

        this.brandTarget.addEventListener('autocomplete:pre-connect', this._onBrandConnect);
        this.puzzleTarget.addEventListener('autocomplete:pre-connect', this._onPuzzleConnect);

        if (this.hasCollectionTarget) {
            this.collectionTarget.addEventListener('autocomplete:pre-connect', this._onCollectionConnect);

            if (this.collectionTarget.tomselect) {
                this._configureExistingCollectionTomselect();
            }
        }
    }

    disconnect() {
        this.brandTarget.removeEventListener('autocomplete:pre-connect', this._onBrandConnect);
        this.puzzleTarget.removeEventListener('autocomplete:pre-connect', this._onPuzzleConnect);

        if (this.hasCollectionTarget) {
            this.collectionTarget.removeEventListener('autocomplete:pre-connect', this._onCollectionConnect);
        }
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

                if (normalizedOption.includes(normalizedInput)) {
                    tom.addItem(value);
                    return false;
                }
            }

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

    _onCollectionConnect(event) {
        this._configureCollectionTomselect(event.detail.options);
    }

    _configureCollectionTomselect(options) {
        options.render.option_create = function (data, escape) {
            return '<div class="create py-2"><i class="ci-add small"></i> Create new collection: <strong>' + escape(data.input) + '</strong></div>';
        };

        options.onChange = (value) => {
            this._handleCollectionChange(value);
        };

        options.onInitialize = () => {
            this._handleCollectionInitialValue();
        };
    }

    _configureExistingCollectionTomselect() {
        const tomselect = this.collectionTarget.tomselect;

        tomselect.on('change', (value) => {
            this._handleCollectionChange(value);
        });

        this._handleCollectionInitialValue();
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
        if (this.hasNewPuzzleTarget) {
            if (value) {
                if (this.uuidRegex.test(value)) {
                    this.newPuzzleTarget.classList.add('d-none');
                } else {
                    this.newPuzzleTarget.classList.remove('d-none');
                }
            } else {
                this.newPuzzleTarget.classList.add('d-none');
            }
        }

        this.puzzleTarget.tomselect.blur();
    }

    _handleCollectionChange(value) {
        if (!this.hasNewCollectionTarget) {
            return;
        }

        if (value) {
            if (this.uuidRegex.test(value) || value === this.systemIdValue) {
                this.newCollectionTarget.classList.add('d-none');
            } else {
                this.newCollectionTarget.classList.remove('d-none');
            }
        } else {
            this.newCollectionTarget.classList.add('d-none');
        }

        if (this.hasCollectionTarget && this.collectionTarget.tomselect) {
            this.collectionTarget.tomselect.blur();
        }
    }

    _handleCollectionInitialValue() {
        if (!this.hasCollectionTarget || !this.hasNewCollectionTarget) {
            return;
        }

        const currentValue = this.collectionTarget.value;
        if (currentValue && !this.uuidRegex.test(currentValue) && currentValue !== this.systemIdValue) {
            this.newCollectionTarget.classList.remove('d-none');
        } else {
            this.newCollectionTarget.classList.add('d-none');
        }
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
        if (this.hasNewPuzzleTarget) {
            this.newPuzzleTarget.classList.remove('d-none');
        }
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

        if (this.hasNewPuzzleTarget) {
            this.newPuzzleTarget.classList.add('d-none');
        }
    }

    removeDiacritics(str) {
        return str.normalize('NFD').replace(/\p{M}/gu, '');
    }
}
