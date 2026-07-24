import { Controller } from '@hotwired/stimulus';

/**
 * Fills the password field with a strong generated password (UX funnel §5).
 * The value stays visible, editable and copyable on purpose - a readonly field
 * would lock out everyone who prefers their own password, and a password manager
 * captures whatever is submitted either way.
 */
export default class extends Controller {
    static targets = ['field'];

    suggest() {
        if (!this.hasFieldTarget) {
            return;
        }

        this.fieldTarget.type = 'text';
        this.fieldTarget.value = this.#generatePassword();
        this.fieldTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.fieldTarget.focus();
    }

    #generatePassword() {
        // Ambiguous characters (0/O, 1/l/I) left out - the value is meant to be
        // readable and re-typable on a phone if the manager does not catch it
        const alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!?@#$%&*+-=';
        const length = 20;
        const randomValues = new Uint32Array(length);

        crypto.getRandomValues(randomValues);

        return Array.from(randomValues, (value) => alphabet[value % alphabet.length]).join('');
    }
}
