import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["form", "spinner", "submit"];

    initialize() {
        this._onConnect = this._onConnect.bind(this);
    }

    connect() {
        this.addEventListeners();
        this.element.addEventListener('autocomplete:connect', this._onConnect);
    }

    disconnect() {
        this.element.removeEventListener('autocomplete:connect', this._onConnect);
    }

    _onConnect(event) {
        event.detail.tomSelect.on('change', () => this.submitForm());
    }

    addEventListeners() {
        document.addEventListener('turbo:frame-load', () => this.hideSpinner())

        document.addEventListener('turbo:frame-load', (event) => {
            const frame = event.target;
            const form = this.formTarget;

            // Check if the frame load event is a result of the form submission
            if (frame.id === 'search-results') {
                const newUrl = new URL(form.action);
                const formData = new FormData(form);
                formData.forEach((value, key) => newUrl.searchParams.append(key, value));

                // Update the URL without causing a navigation
                history.pushState({}, '', newUrl);
            }
        });

        // Add listeners for general input and change events
        this.formTarget.addEventListener('input', event => {
            if (!event.target.matches('[role="combobox"], input[type="radio"], input[type="checkbox"], .tomselected')) {
                this.debounceSubmitForm();
            }
        });

        // Immediate submission for radio buttons
        this.formTarget.querySelectorAll('input[type=radio], input[type=checkbox]').forEach(input => {
            input.addEventListener('change', () => this.submitForm());
        });
    }

    debounceSubmitForm() {
        if (this.timeout) {
            clearTimeout(this.timeout);
        }

        this.timeout = setTimeout(() => {
            this.showSpinner();
            this.formTarget.requestSubmit();
        }, 250);
    }

    submitForm() {
        this.showSpinner();
        this.formTarget.requestSubmit();
    }

    showSpinner() {
        this.spinnerTarget.classList.remove('invisible');
        this.submitTarget.classList.add('disabled');
    }

    hideSpinner() {
        this.spinnerTarget.classList.add('invisible');
        this.submitTarget.classList.remove('disabled');
    }
}
