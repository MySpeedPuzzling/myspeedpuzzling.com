import { Controller } from '@hotwired/stimulus';

/**
 * Dismissal of the sign-in migration notice (issue #147). Per browser, not per
 * user: the notice rides along on pages that are shared-cacheable for anonymous
 * visitors (#164), so it must never touch the session or the server.
 *
 * The storage key arrives from the template because it changes with the
 * migration phase (Stage B swaps the wording, and the new wording must show
 * once even to someone who dismissed the old one). The matching inline script
 * in base.html.twig hides the banner before first paint on later page loads -
 * this controller only handles the click.
 */
export default class extends Controller {
    static values = {
        storageKey: { type: String, default: 'sign-in-changes-notice-dismissed' },
    };

    dismiss() {
        try {
            window.localStorage.setItem(this.storageKeyValue, '1');
        } catch (error) {
            // Private mode or storage disabled: the notice simply comes back
        }

        this.element.remove();
    }
}
