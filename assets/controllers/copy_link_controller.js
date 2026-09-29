/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "Copy link" in the in-app browser notice: the way out of Instagram's
 * browser on an iPhone, where no link can open Safari by itself. Clipboard
 * API first; where it is missing or refused, the URL field is selected so the
 * system's own copy menu takes over.
 */
export default class extends Controller {
    static targets = ['button', 'field'];

    static values = {
        url: String,
        copied: String,
    };

    async copy() {
        try {
            await navigator.clipboard.writeText(this.urlValue);
            this.confirm();
        } catch (e) {
            this.fieldTarget.hidden = false;
            this.fieldTarget.focus();
            this.fieldTarget.select();
        }
    }

    confirm() {
        const label = this.buttonTarget.querySelector('span') ?? this.buttonTarget;
        const original = label.textContent;

        label.textContent = this.copiedValue;
        window.setTimeout(() => {
            label.textContent = original;
        }, 2500);
    }
}
