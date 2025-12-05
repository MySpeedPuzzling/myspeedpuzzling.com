import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['collection', 'newCollection'];
    static values = {
        systemId: String
    }

    uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    connect() {
        // Only set up if collection target exists (it may not exist when puzzle is already in collection for non-members)
        if (this.hasCollectionTarget) {
            this.collectionTarget.addEventListener('autocomplete:pre-connect', this._onAutocompleteConnect.bind(this));

            // Handle existing tomselect instance after re-render
            if (this.collectionTarget.tomselect) {
                this._configureExistingTomselect();
            }
        }
    }

    disconnect() {
        if (this.hasCollectionTarget) {
            this.collectionTarget.removeEventListener('autocomplete:pre-connect', this._onAutocompleteConnect.bind(this));
        }
    }

    _onAutocompleteConnect(event) {
        this._configureTomselect(event.detail.options);
    }

    _configureTomselect(options) {
        // Customize the "create new" option display
        options.render.option_create = function (data, escape) {
            return '<div class="create py-2"><i class="ci-add small"></i> Create new collection: <strong>' + escape(data.input) + '</strong></div>';
        };

        // Handle value changes to show/hide new collection fields
        options.onChange = (value) => {
            this._handleCollectionChange(value);
        };

        // Handle initial value when tomselect initializes
        options.onInitialize = () => {
            this._handleInitialValue();
        };
    }

    _configureExistingTomselect() {
        const tomselect = this.collectionTarget.tomselect;
        
        // Set up change handler for existing instance
        tomselect.on('change', (value) => {
            this._handleCollectionChange(value);
        });

        // Handle initial value
        this._handleInitialValue();
    }

    _handleCollectionChange(value) {
        if (!this.hasNewCollectionTarget) {
            return;
        }

        if (value) {
            if (this.uuidRegex.test(value) || value === this.systemIdValue) {
                // Existing collection selected (UUID or system collection) - hide new collection fields
                this.newCollectionTarget.classList.add('d-none');
            } else {
                // New collection name entered - show new collection fields
                this.newCollectionTarget.classList.remove('d-none');
            }
        } else {
            // No selection - hide new collection fields
            this.newCollectionTarget.classList.add('d-none');
        }

        // Blur the tomselect to close dropdown
        if (this.hasCollectionTarget && this.collectionTarget.tomselect) {
            this.collectionTarget.tomselect.blur();
        }
    }

    _handleInitialValue() {
        if (!this.hasCollectionTarget || !this.hasNewCollectionTarget) {
            return;
        }

        const currentValue = this.collectionTarget.value;
        if (currentValue && !this.uuidRegex.test(currentValue) && currentValue !== this.systemIdValue) {
            // If there's an initial value that's not a UUID or system collection, show new collection fields
            this.newCollectionTarget.classList.remove('d-none');
        } else {
            this.newCollectionTarget.classList.add('d-none');
        }
    }
}
