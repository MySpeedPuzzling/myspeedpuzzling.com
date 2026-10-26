import { Controller } from '@hotwired/stimulus';

/**
 * The bell's dropdown: the newest notifications load into their frame the first time it opens (nothing on page views
 * that never open it). The answer has marked everything read, so the bell's count goes to 0 - like on the
 * notifications page. A failed load is tried again on the next open.
 */
export default class extends Controller {
    static targets = ['frame', 'count', 'box', 'toggle'];

    static values = {
        src: String,
    };

    connect() {
        this.onShow = this.load.bind(this);
        this.onLoaded = this.loaded.bind(this);
        this.onFailed = this.failed.bind(this);

        this.element.addEventListener('show.bs.dropdown', this.onShow);
        this.frameTarget.addEventListener('turbo:frame-load', this.onLoaded);
        this.frameTarget.addEventListener('turbo:fetch-request-error', this.onFailed);
        this.frameTarget.addEventListener('turbo:frame-missing', this.onFailed);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.dropdown', this.onShow);
        this.frameTarget.removeEventListener('turbo:frame-load', this.onLoaded);
        this.frameTarget.removeEventListener('turbo:fetch-request-error', this.onFailed);
        this.frameTarget.removeEventListener('turbo:frame-missing', this.onFailed);
    }

    load() {
        if (this.frameTarget.getAttribute('src') !== null) {
            return;
        }

        this.frameTarget.setAttribute('src', this.srcValue);
    }

    loaded() {
        this.countTarget.textContent = '0';
        this.countTarget.classList.add('bg-secondary', 'text-dark');
        this.countTarget.classList.remove('navbar-tool-label-wide');
        this.boxTarget.classList.remove('bg-secondary');
    }

    close() {
        window.bootstrap?.Dropdown.getInstance(this.toggleTarget)?.hide();
    }

    failed(event) {
        // Never Turbo's "Content missing" in the menu: keep the spinner, ask again next time
        if (event.type === 'turbo:frame-missing') {
            event.preventDefault();
        }

        this.frameTarget.removeAttribute('src');
    }
}
