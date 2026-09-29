/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'msp.lastSignIn';
const METHODS = ['google', 'apple', 'facebook', 'password', 'link'];

/**
 * "Last used" on the sign-in method this device used last - the answer to
 * "did I sign up with Google or with a password?"
 * (docs/features/auth-ux-redesign.md §5.1).
 *
 * Remembered on choice (a click on a provider, submitting the password or the
 * sign-in link form), in localStorage only: no cookie, nothing the server
 * sees, so the auth pages stay session-free and cache-neutral. It reveals which
 * method, never which address. Buttons are never reordered by it.
 *
 * Elements carrying data-last-sign-in-method are the methods; a
 * `.auth-last-used` element inside (or the `badge` target with a matching
 * data-last-sign-in-method) is revealed for the remembered one.
 */
export default class extends Controller {
    static targets = ['badge'];

    connect() {
        this.clearLoading = this.clearLoading.bind(this);
        // Back from the provider via the bfcache: the pressed button must not
        // stay dimmed
        window.addEventListener('pageshow', this.clearLoading);

        const last = this.read();

        if (!last) {
            return;
        }

        this.element.querySelectorAll(`[data-last-sign-in-method="${last}"] .auth-last-used`).forEach((badge) => {
            badge.hidden = false;
        });

        this.badgeTargets
            .filter((badge) => badge.dataset.lastSignInMethod === last)
            .forEach((badge) => {
                badge.hidden = false;
            });
    }

    disconnect() {
        window.removeEventListener('pageshow', this.clearLoading);
    }

    remember(event) {
        const method = event.currentTarget.dataset.lastSignInMethod;

        if (!METHODS.includes(method)) {
            return;
        }

        this.store(method);

        // A provider link that really leaves for the provider (not the in-app
        // browser notice anchor) shows it is working
        const link = event.currentTarget;

        if (link.tagName === 'A' && !link.getAttribute('href').startsWith('#')) {
            link.classList.add('is-loading');
            link.setAttribute('aria-busy', 'true');
        }
    }

    clearLoading() {
        this.element.querySelectorAll('.is-loading').forEach((element) => {
            element.classList.remove('is-loading');
            element.removeAttribute('aria-busy');
        });
    }

    read() {
        try {
            const value = window.localStorage.getItem(STORAGE_KEY);

            return METHODS.includes(value) ? value : null;
        } catch (e) {
            return null;
        }
    }

    store(method) {
        try {
            window.localStorage.setItem(STORAGE_KEY, method);
        } catch (e) {
            // Storage blocked (private mode) - the badge is a convenience only
        }
    }
}
