import { Controller } from '@hotwired/stimulus';
import * as bootstrap from 'bootstrap';

export default class extends Controller {
    static targets = ['brand', 'puzzle', 'competition', 'newPuzzle', 'scannerModal', 'scannerMessage', 'eanInput', 'hideOptions'];

    static values = {
        eanSearchUrl: String,
        notFoundMessage: String,
        multipleBrandsMessage: String,
        multiplePuzzlesMessage: String,
        addNewBrandMessage: String,
        addNewPuzzleMessage: String,
        puzzleRequiredMessage: String,
    };

    uuidRegex= /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    initialBrandValue = '';
    initialPuzzleValue = '';
    _puzzleOptionsFetchPromise = null; // Track the current fetch promise for puzzle options

    initialize() {
        this._onCompetitionConnect = this._onCompetitionConnect.bind(this);
        this._onBrandConnect = this._onBrandConnect.bind(this);
        this._onPuzzleConnect = this._onPuzzleConnect.bind(this);
        this._handleBarcodeScanned = this._handleBarcodeScanned.bind(this);
        this._onFormSubmit = this._onFormSubmit.bind(this);
    }

    connect() {
        // The controller element is inside the form on the solving-time form, but
        // wraps the form on the add-puzzle-to-round page - support both layouts
        this.formElement = this.element.closest('form') ?? this.element.querySelector('form');
        // initialBrandValue/initialPuzzleValue are captured in handleInitialValues()
        // once Tom Select initializes - capturing here would race browser form restore
        this.brandTarget.addEventListener('autocomplete:pre-connect', this._onBrandConnect);
        this.puzzleTarget.addEventListener('autocomplete:pre-connect', this._onPuzzleConnect);

        if (this.hasCompetitionTarget) {
            this.initialCompetitionValue = this.competitionTarget.value;
            this.competitionTarget.addEventListener('autocomplete:pre-connect', this._onCompetitionConnect);
        }

        // Listen for barcode scanner events
        document.addEventListener('barcode-scanner:scanned', this._handleBarcodeScanned);

        // Capture phase so this runs before Turbo, submit-prevention and ppm-validator:
        // a submit must never go out while the puzzle input is disabled or empty
        document.addEventListener('submit', this._onFormSubmit, true);
    }

    disconnect() {
        // Remove listeners when the controller is disconnected to avoid side-effects
        this.brandTarget.removeEventListener('autocomplete:pre-connect', this._onBrandConnect);
        this.puzzleTarget.removeEventListener('autocomplete:pre-connect', this._onPuzzleConnect);

        if (this.hasCompetitionTarget) {
            this.competitionTarget.removeEventListener('autocomplete:pre-connect', this._onCompetitionConnect);
        }

        document.removeEventListener('barcode-scanner:scanned', this._handleBarcodeScanned);
        document.removeEventListener('submit', this._onFormSubmit, true);
    }

    _onBrandConnect(event) {
        const addNewBrandMessage = this.addNewBrandMessageValue || 'Add new brand:';
        event.detail.options.render.option_create = function (data, escape) {
            return '<div class="create py-2"><i class="ci-add small"></i> ' + addNewBrandMessage + ' <strong>' + escape(data.input) + '</strong></div>';
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
        const addNewPuzzleMessage = this.addNewPuzzleMessageValue || 'Add new puzzle:';
        event.detail.options.render.option_create = function(data, escape) {
            return '<div class="create py-2"><i class="ci-add small"></i> ' + addNewPuzzleMessage + ' <strong>' + escape(data.input) + '</strong></div>';
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
        // Puzzle Tom Select may not be initialized yet (autocomplete is a lazy-loaded
        // controller) - handleInitialValues() picks up the current brand value on init
        if (!this.puzzleTarget.tomselect) {
            return;
        }

        if (value !== this.initialBrandValue) {
            this.puzzleTarget.tomselect.clear();
            this.initialBrandValue = value;
        }

        if (value) {
            this.enablePuzzleField();

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
            this._clearPuzzleRequiredError();

            if (this.uuidRegex.test(value)) {
                this.newPuzzleTarget.classList.add('d-none');

                // Dispatch event with pieces count for PPM validation
                const option = this.puzzleTarget.tomselect.options[value];
                if (option && option.piecesCount) {
                    this.dispatchPiecesCountEvent(option.piecesCount);
                } else {
                    // Existing puzzle but no pieces count data
                    this.dispatchPiecesCountEvent(null);
                }
            } else {
                this.newPuzzleTarget.classList.remove('d-none');
                // New puzzle - pieces count will come from input field
                this.dispatchPiecesCountEvent(null);
            }
        } else {
            this.newPuzzleTarget.classList.add('d-none');
            // No puzzle selected - clear pieces count
            this.dispatchPiecesCountEvent(null);
        }

        this.puzzleTarget.tomselect.blur();
    }

    dispatchPiecesCountEvent(piecesCount) {
        // Dispatch to parent form for PPM validator to listen
        const form = this.element.closest('form');
        if (form) {
            form.dispatchEvent(new CustomEvent('ppm:piecesCountUpdated', {
                detail: { piecesCount: piecesCount },
                bubbles: true
            }));
        }
    }

    onCompetitionValueChanged(value) {
        this.competitionTarget.tomselect.blur();
    }

    fetchPuzzleOptions(brandValue, openDropdown) {
        const fetchUrl = this.brandTarget.getAttribute('data-fetch-url');

        if (this.uuidRegex.test(brandValue)) {
            // Store the promise so it can be awaited by barcode scan handler
            this._puzzleOptionsFetchPromise = fetch(`${fetchUrl}?brand=${brandValue}`)
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
                        } else if (existingValue && !this.uuidRegex.test(existingValue)) {
                            // User typed a new puzzle name while the options were being
                            // fetched - recreate it instead of silently dropping the value
                            this.puzzleTarget.tomselect.createItem(existingValue);
                        }

                        if (this.initialPuzzleValue && !this.puzzleTarget.tomselect.getOption(this.initialPuzzleValue) && !this.uuidRegex.test(this.initialPuzzleValue)) {
                            this.puzzleTarget.tomselect.createItem(this.initialPuzzleValue);
                        }

                        this.initialPuzzleValue = '';
                    }
                    return data;
                })
                .catch(error => {
                    console.error('There has been a problem with your fetch operation:', error);
                })
                .finally(() => {
                    this._puzzleOptionsFetchPromise = null;
                });

            return this._puzzleOptionsFetchPromise;
        }

        return Promise.resolve(null);
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
        // Re-read the live input values: the browser may have restored form state
        // (back navigation, tab discard/restore) after connect() captured them,
        // without firing any change event. Deciding on the stale captured values
        // used to disable the puzzle field even though a brand was selected.
        this.initialBrandValue = this.brandTarget.value;
        this.initialPuzzleValue = this.puzzleTarget.value;

        if (this.initialBrandValue) {
            if (!this.brandTarget.tomselect.getOption(this.initialBrandValue) && !this.uuidRegex.test(this.initialBrandValue)) {
                this.brandTarget.tomselect.createItem(this.initialBrandValue);

                if (this.initialPuzzleValue) {
                    this.puzzleTarget.tomselect.createItem(this.initialPuzzleValue);
                }
            } else if (this.uuidRegex.test(this.initialBrandValue)) {
                this.fetchPuzzleOptions(this.brandTarget.value, false);
            } else {
                // Non-UUID brand (new brand name): no options to fetch, show the new-puzzle fields
                this.onNewBrandCreated();
            }
        } else {
            this.disablePuzzleField();
        }
    }

    enablePuzzleField() {
        const puzzleTomSelect = this.puzzleTarget.tomselect;
        puzzleTomSelect.enable();
        puzzleTomSelect.settings.placeholder = this.puzzleTarget.dataset.choosePuzzlePlaceholder;
        puzzleTomSelect.inputState();
    }

    disablePuzzleField() {
        const puzzleTomSelect = this.puzzleTarget.tomselect;
        puzzleTomSelect.clearOptions();
        puzzleTomSelect.disable();
        puzzleTomSelect.settings.placeholder = this.puzzleTarget.dataset.chooseBrandPlaceholder;
        puzzleTomSelect.inputState();

        this.newPuzzleTarget.classList.add('d-none');
    }

    _onFormSubmit(event) {
        if (event.target !== this.formElement) {
            return;
        }

        const brandTomSelect = this.brandTarget.tomselect;
        const puzzleTomSelect = this.puzzleTarget.tomselect;

        // Tom Select not initialized (lazy chunk not loaded yet) - the plain inputs
        // are untouched, so native and server-side validation handle this submit
        if (!brandTomSelect || !puzzleTomSelect) {
            return;
        }

        // Browser form restore (back navigation, tab discard) can set the hidden
        // brand input without Tom Select noticing - sync the visible control.
        // Silently: a change event here would cascade into onBrandValueChanged
        // and clear the (possibly also restored) puzzle value.
        if (this.brandTarget.value && !brandTomSelect.getValue()) {
            if (!brandTomSelect.getOption(this.brandTarget.value)) {
                brandTomSelect.addOption({ value: this.brandTarget.value, text: this.brandTarget.value });
            }
            brandTomSelect.addItem(this.brandTarget.value, true);
        }

        if (this.puzzleTarget.value && !this.puzzleTarget.disabled) {
            return;
        }

        // A disabled input is excluded from the submit entirely, so no puzzle would
        // reach the server. Block the submit (before Turbo, submit-prevention and
        // ppm-validator see it) and repair the field instead - the user keeps
        // everything they already filled in, including uploaded photos.
        event.preventDefault();
        event.stopImmediatePropagation();

        // The blocked submit may have been re-fired programmatically by
        // submit-prevention (image compression path) with the button already in
        // "Saving..." state - reset it so the form is not stranded
        this._resetSubmitPrevention();

        if (!this.brandTarget.value) {
            brandTomSelect.focus();
            return;
        }

        this.enablePuzzleField();

        if (this.puzzleTarget.value) {
            // The field was wrongly disabled but still holds the user's choice.
            // Resubmit deferred: a nested requestSubmit() during submit event
            // dispatch is a no-op per the HTML spec ("firing submission events").
            setTimeout(() => this.formElement.requestSubmit(), 0);
            return;
        }

        // Same flow as a fresh brand selection: enable + fetch options / show new-puzzle fields
        this.onBrandValueChanged(this.brandTarget.value);

        this._showPuzzleRequiredError();
        puzzleTomSelect.focus();
    }

    _resetSubmitPrevention() {
        const submitButton = this.formElement.querySelector('[data-submit-prevention-target="submit"]');
        if (submitButton) {
            submitButton.removeAttribute('disabled');
            submitButton.classList.remove('is-loading');
        }

        const submitPreventionController = this.application.getControllerForElementAndIdentifier(
            this.formElement,
            'submit-prevention'
        );
        if (submitPreventionController) {
            submitPreventionController.reset();
        }
    }

    _showPuzzleRequiredError() {
        this._clearPuzzleRequiredError();

        const error = document.createElement('div');
        error.className = 'invalid-feedback d-block js-puzzle-required-error';
        error.textContent = this.puzzleRequiredMessageValue || 'This field is required!';

        const anchor = this.puzzleTarget.tomselect.wrapper;
        anchor.insertAdjacentElement('afterend', error);
        anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    _clearPuzzleRequiredError() {
        this.element.querySelectorAll('.js-puzzle-required-error').forEach((element) => element.remove());
    }

    toggleHideOptions(event) {
        if (this.hasHideOptionsTarget) {
            if (event.target.checked) {
                this.hideOptionsTarget.classList.remove('d-none');
            } else {
                this.hideOptionsTarget.classList.add('d-none');
            }
        }
    }

    removeDiacritics(str) {
        // normalize and remove "diacritics" characters (e.g. é, ü, etc.)
        return str.normalize('NFD').replace(/\p{M}/gu, '');
    }

    // === Barcode Scanner Methods ===

    openScanner(event) {
        event.preventDefault();

        if (!this.hasScannerModalTarget) {
            console.error('Scanner modal target not found');
            return;
        }

        // Hide any previous messages
        if (this.hasScannerMessageTarget) {
            this.scannerMessageTarget.classList.add('d-none');
        }

        // Open the modal
        const modal = new bootstrap.Modal(this.scannerModalTarget);
        modal.show();

        // Stop camera when modal is closed (by any means: X button, Escape, clicking outside)
        this.scannerModalTarget.addEventListener('hidden.bs.modal', () => {
            window.dispatchEvent(new CustomEvent('barcode-scan:close'));
        }, { once: true });

        // Start the scanner (barcode-scanner controller will handle this via toggle button click)
        const toggleButton = this.scannerModalTarget.querySelector('[data-barcode-scanner-target="toggleButton"]');
        if (toggleButton && !toggleButton.classList.contains('active')) {
            toggleButton.click();
        }
    }

    _handleBarcodeScanned(event) {
        // Only handle if the scanner modal is open
        if (!this.hasScannerModalTarget) {
            return;
        }

        const modalInstance = bootstrap.Modal.getInstance(this.scannerModalTarget);
        if (!modalInstance || !this.scannerModalTarget.classList.contains('show')) {
            return;
        }

        const { code } = event.detail;
        this._searchPuzzleByEan(code);
    }

    async _searchPuzzleByEan(ean) {
        if (!this.hasEanSearchUrlValue) {
            console.error('EAN search URL not configured');
            return;
        }

        const url = this.eanSearchUrlValue.replace('__EAN__', encodeURIComponent(ean));

        try {
            const response = await fetch(url);
            const data = await response.json();

            // Close the scanner modal
            const modalInstance = bootstrap.Modal.getInstance(this.scannerModalTarget);
            if (modalInstance) {
                modalInstance.hide();
            }

            // Handle response based on number of puzzles and brands found
            if (data.puzzles.length === 1) {
                // Single puzzle found - auto-select
                this._handlePuzzleFound(data.puzzles[0], data.brands[0]);
            } else if (data.puzzles.length > 1) {
                // Multiple puzzles found - let user choose from dropdown
                this._handleMultiplePuzzlesFound(data.puzzles, data.brands, ean);
            } else if (data.brands.length === 1) {
                // No puzzle but single brand found - auto-select brand
                this._handlePuzzleNotFound(ean, data.brands[0]);
            } else if (data.brands.length > 1) {
                // No puzzle but multiple brands found - let user choose from dropdown
                this._handleMultipleBrandsFound(data.brands, ean);
            } else {
                // Nothing found - show new puzzle form
                this._handlePuzzleNotFound(ean, null);
            }
        } catch (error) {
            console.error('Error searching puzzle by EAN:', error);
        }
    }

    async _handlePuzzleFound(puzzle, brand) {
        const brandTom = this.brandTarget.tomselect;
        const puzzleTom = this.puzzleTarget.tomselect;

        // Add brand option if not exists
        if (!brandTom.getOption(brand.id)) {
            brandTom.addOption({ value: brand.id, text: brand.name });
        }

        // Set brand value - this triggers fetchPuzzleOptions via onBrandValueChanged
        brandTom.setValue(brand.id);

        // Wait for puzzle options to be loaded
        if (this._puzzleOptionsFetchPromise) {
            await this._puzzleOptionsFetchPromise;
        }

        // Small delay to ensure Tom Select has finished processing the options
        await new Promise(resolve => setTimeout(resolve, 50));

        // Select the puzzle using setValue (works with options data, not DOM)
        puzzleTom.setValue(puzzle.id);
    }

    _handlePuzzleNotFound(ean, brand) {
        // If brand was matched by EAN prefix, auto-select it
        if (brand) {
            const brandTom = this.brandTarget.tomselect;

            // Add brand option if not exists, then select it
            if (!brandTom.getOption(brand.id)) {
                brandTom.addOption({ value: brand.id, text: brand.name });
            }

            // Setting brand value triggers the normal flow
            brandTom.setValue(brand.id);
        }

        // Show new puzzle fields
        this.newPuzzleTarget.classList.remove('d-none');

        // Prefill EAN field
        if (this.hasEanInputTarget) {
            this.eanInputTarget.value = ean;
        }

        // Show "not found" message
        if (this.hasScannerMessageTarget && this.hasNotFoundMessageValue) {
            const message = this.notFoundMessageValue.replace('%ean%', ean);
            this.scannerMessageTarget.textContent = message;
            this.scannerMessageTarget.classList.remove('d-none');
        }
    }

    async _handleMultiplePuzzlesFound(puzzles, brands, ean) {
        const brandTom = this.brandTarget.tomselect;
        const puzzleTom = this.puzzleTarget.tomselect;
        const brand = brands[0];

        // Add brand option if not exists, then select it
        if (!brandTom.getOption(brand.id)) {
            brandTom.addOption({ value: brand.id, text: brand.name });
        }
        brandTom.setValue(brand.id);

        // Wait for puzzle options to be loaded
        if (this._puzzleOptionsFetchPromise) {
            await this._puzzleOptionsFetchPromise;
        }

        // Small delay to ensure Tom Select has finished processing
        await new Promise(resolve => setTimeout(resolve, 50));

        const matchingPuzzleIds = new Set(puzzles.map(p => p.id));
        this._filterOptionsTemporarily(puzzleTom, matchingPuzzleIds, this.multiplePuzzlesMessageValue, ean);
    }

    _handleMultipleBrandsFound(brands, ean) {
        const brandTom = this.brandTarget.tomselect;
        const matchingBrandIds = new Set(brands.map(b => b.id));
        this._filterOptionsTemporarily(brandTom, matchingBrandIds, this.multipleBrandsMessageValue, ean);
    }

    _filterOptionsTemporarily(tomSelect, matchingIds, messageValue, ean) {
        // Save all options to restore later
        const allOptions = { ...tomSelect.options };

        // Temporarily remove non-matching options
        Object.keys(tomSelect.options).forEach(key => {
            if (!matchingIds.has(key)) {
                tomSelect.removeOption(key, true); // silent = true
            }
        });

        // Open dropdown with only matching options
        tomSelect.focus();
        tomSelect.refreshOptions(true);

        // Restore all options when user selects or types
        const restoreOptions = () => {
            tomSelect.off('change', restoreOptions);
            tomSelect.off('type', restoreOptions);

            Object.entries(allOptions).forEach(([key, option]) => {
                if (!tomSelect.options[key]) {
                    tomSelect.addOption(option, true); // silent = true
                }
            });
        };

        tomSelect.on('change', restoreOptions);
        tomSelect.on('type', restoreOptions);

        // Prefill EAN field
        if (this.hasEanInputTarget) {
            this.eanInputTarget.value = ean;
        }

        // Show message
        if (this.hasScannerMessageTarget && messageValue) {
            const message = messageValue.replace('%ean%', ean);
            this.scannerMessageTarget.textContent = message;
            this.scannerMessageTarget.classList.remove('d-none');
        }
    }
}
