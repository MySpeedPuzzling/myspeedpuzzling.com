/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Show/hide button inside a password field (docs/features/auth-ux-redesign.md).
 * Sits on the <button> itself; the field is the one named by aria-controls.
 *
 * The button ships `hidden` and is revealed here, so without JavaScript nobody
 * sees a control that does nothing. Its accessible name stays "Show password"
 * and aria-pressed carries the state (a toggle button must not also rename
 * itself). Focus stays on the button.
 *
 * The field goes back to type=password before its form submits: password
 * managers decide whether to offer "save" by looking for a password field.
 */
export default class extends Controller {
    connect() {
        this.input = document.getElementById(this.element.getAttribute('aria-controls'));

        if (!this.input) {
            return;
        }

        this.form = this.input.form;
        this.conceal = this.conceal.bind(this);
        this.form?.addEventListener('submit', this.conceal);

        this.element.hidden = false;
    }

    disconnect() {
        this.form?.removeEventListener('submit', this.conceal);
    }

    toggle() {
        if (!this.input) {
            return;
        }

        this.render(this.input.type === 'password');
    }

    conceal() {
        this.render(false);
    }

    render(visible) {
        this.input.type = visible ? 'text' : 'password';
        this.element.setAttribute('aria-pressed', visible ? 'true' : 'false');

        const icon = this.element.querySelector('i');

        if (icon) {
            icon.classList.toggle('bi-eye', !visible);
            icon.classList.toggle('bi-eye-slash', visible);
        }
    }
}
