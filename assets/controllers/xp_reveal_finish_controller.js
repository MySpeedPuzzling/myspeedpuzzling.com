import { Controller } from '@hotwired/stimulus';

/**
 * Launch-reveal "continue" button: persists the one-time-seen marker via the
 * dismiss-hint endpoint, then moves on to the profile.
 */
export default class extends Controller {
    static values = {
        url: String,
        redirect: String,
    };

    async finish() {
        try {
            await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'type=xp_launch_reveal',
            });
        } finally {
            window.location.assign(this.redirectValue);
        }
    }
}
