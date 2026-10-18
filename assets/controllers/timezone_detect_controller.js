/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { browserTimeZone } from '../browser_timezone.js';

/**
 * The player's time zone at sign-up (docs/features/player-timezone.md).
 *
 * - On a hidden input (registration form, social sign-up confirmation): fills it with the browser's zone, unless it
 *   already holds one.
 * - With a `url` value (the welcome screen, rendered only while the new account has no zone): posts the zone once.
 *   The server only fills an empty value, so this can never change a zone the player chose.
 */
export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        const zone = browserTimeZone();

        if (zone === null) {
            return;
        }

        if (this.element instanceof HTMLInputElement) {
            if (this.element.value === '') {
                this.element.value = zone;
            }

            return;
        }

        if (this.urlValue !== '') {
            fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ timezone: zone }),
                credentials: 'same-origin',
            }).catch(() => {});
        }
    }
}
