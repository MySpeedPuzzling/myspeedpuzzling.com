import { Controller } from '@hotwired/stimulus';

/**
 * Membership page plan picker (templates/membership/_subscription_plans.html.twig): the one button follows
 * the picked plan card - the checkout URL on the web, the store product in the apps - and says its price.
 */
export default class extends Controller {
    static targets = ['button', 'label'];

    pick(event) {
        const input = event.currentTarget;

        if (this.hasLabelTarget && input.dataset.label) {
            this.labelTarget.textContent = input.dataset.label;
        }

        if (!this.hasButtonTarget) {
            return;
        }

        if (input.dataset.url) {
            this.buttonTarget.setAttribute('href', input.dataset.url);
        }

        if (input.dataset.product) {
            this.buttonTarget.setAttribute('data-native-billing-product-param', input.dataset.product);
        }
    }
}
