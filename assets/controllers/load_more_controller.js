import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['spinner', 'text'];

    connect() {
        this.element.addEventListener('click', (event) => {
            this.startLoading();
        });

        // Listen to Turbo Frame load events if you're using Turbo to handle AJAX
        document.addEventListener('turbo:frame-load', () => {
            this.stopLoading();
        });
    }

    startLoading() {
        this.element.classList.add('disabled');
        this.spinnerTarget.classList.remove('d-none');
        this.textTarget.classList.add('d-none');
    }

    stopLoading() {
        this.element.classList.remove('disabled');
        this.spinnerTarget.classList.add('d-none');
        this.textTarget.classList.remove('d-none');
    }
}
