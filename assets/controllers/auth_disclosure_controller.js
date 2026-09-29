/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "Continue with email" on /register (auth UX redesign §10): opens the email
 * form right under the button instead of navigating.
 *
 * Progressive: the button is a real link to /register?method=email, which the
 * server renders with the form open - so without JavaScript nothing is lost.
 * Here the click only reveals the form in place, focuses the email field and
 * brings the "Create account" button into view when the screen can show it
 * together with the top of the form.
 */
export default class extends Controller {
    static targets = ['trigger', 'panel', 'submit'];

    open(event) {
        if (!this.hasPanelTarget) {
            return;
        }

        event.preventDefault();

        this.panelTarget.hidden = false;

        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'true');
            this.triggerTarget.hidden = true;
        }

        // A reload (or the back button after a failed step) keeps the form open
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('method', 'email');
            window.history.replaceState(window.history.state, '', url);
        } catch (e) {
            // cosmetic only
        }

        const field = this.panelTarget.querySelector('input[type="email"]');

        if (field) {
            field.focus({ preventScroll: true });
        }

        this.reveal();
    }

    reveal() {
        const header = document.querySelector('header.sticky-top');
        const headerBottom = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
        const panelTop = this.panelTarget.getBoundingClientRect().top;
        const submitBottom = this.hasSubmitTarget ? this.submitTarget.getBoundingClientRect().bottom : panelTop;
        const overflow = submitBottom + 16 - window.innerHeight;

        if (overflow <= 0) {
            return;
        }

        // Scroll just enough to show the button - but never past the top of the
        // form, which must stay visible under the header
        const room = panelTop - headerBottom - 16;
        const distance = Math.min(overflow, Math.max(0, room));

        if (distance > 0) {
            window.scrollBy({ top: distance, behavior: 'auto' });
        }
    }
}
