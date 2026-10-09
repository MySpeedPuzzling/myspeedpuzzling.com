/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { chooseTranslation } from '../translation_choice.js';

/**
 * Keeps a button's text in step with the ticked boxes of its form - "Create 3 editions" on "Add several dates"
 * (templates/add_editions.html.twig). The server renders the text for the boxes ticked then; this re-chooses it from the
 * same translation (browser_translation() + chooseTranslation(), so the plural reads as PHP's) whenever a box changes.
 */
export default class extends Controller {
    static targets = ['label'];
    static values = { message: Object };

    update() {
        if (!this.hasLabelTarget) {
            return;
        }

        const count = this.element.querySelectorAll('input[type="checkbox"]:checked').length;
        const text = chooseTranslation(this.messageValue.message, count, this.messageValue.locale);

        if (text !== null) {
            this.labelTarget.textContent = text;
        }
    }
}
