import { Controller } from '@hotwired/stimulus';

/**
 * The error state of an Insights lazy frame (docs/features/insights/page.md "Lazy frames"). Eager on purpose: a frame
 * can fail before a lazily loaded controller would be connected.
 *
 * - `turbo:frame-missing` - the answer has no frame with this id (an expired session's 302 to the sign-in page lands
 *   here too): prevented, so Turbo never pastes "Content missing" into the page;
 * - `turbo:fetch-request-error` - the request itself failed (offline, a dropped connection).
 *
 * Either way the frame keeps its sections' heads (the question chips' anchors) and each loading box becomes
 * "Couldn't load this part" with "Try again"; a frame without loading boxes (the top row's cards in the findings grid)
 * gets one card. Every text comes from the server (values). "Try again" puts the placeholder back and sets `src` anew,
 * so Turbo loads the frame again. The frame is `data-live-ignore`, so the Insights live component never touches it.
 */
export default class extends Controller {
    static values = {
        error: String,
        retry: String,
    };

    connect() {
        this.placeholder = this.element.innerHTML;
        this._onMissing = this._onMissing.bind(this);
        this._onError = this._onError.bind(this);
        this.element.addEventListener('turbo:frame-missing', this._onMissing);
        this.element.addEventListener('turbo:fetch-request-error', this._onError);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-missing', this._onMissing);
        this.element.removeEventListener('turbo:fetch-request-error', this._onError);
    }

    retry() {
        const src = this.element.getAttribute('src');

        this.element.innerHTML = this.placeholder;

        if (src) {
            this.element.removeAttribute('src');
            this.element.setAttribute('src', src);
        }
    }

    _onMissing(event) {
        event.preventDefault();
        this._showError();
    }

    _onError() {
        this._showError();
    }

    _showError() {
        this.element.removeAttribute('aria-busy');
        this.element.querySelectorAll('[aria-busy]').forEach((element) => element.removeAttribute('aria-busy'));

        const boxes = this.element.querySelectorAll('.ins-loading-box');

        if (boxes.length > 0) {
            boxes.forEach((box) => box.replaceWith(this._errorBox('ins-loading-box ins-frame-error')));

            return;
        }

        // The top row's cards: one card in the findings grid in place of the skeletons
        this.element.replaceChildren(this._errorBox('ins-finding ins-frame-error'));
    }

    _errorBox(className) {
        const box = document.createElement('div');
        box.className = className;
        box.setAttribute('role', 'alert');
        box.dataset.testid = 'insights-frame-error';

        const text = document.createElement('span');
        text.textContent = this.errorValue;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-primary btn-sm';
        button.textContent = this.retryValue;
        button.addEventListener('click', () => this.retry());

        box.append(text, button);

        return box;
    }
}
