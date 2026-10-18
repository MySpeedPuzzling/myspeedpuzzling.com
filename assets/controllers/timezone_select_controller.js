/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import { browserTimeZone } from '../browser_timezone.js';

/**
 * The "Time zone" field of the profile settings (docs/features/player-timezone.md, templates/_timezone_field.html.twig).
 *
 * - Searchable (TomSelect keeps the region groups of the native select).
 * - While no zone is saved, preselects the browser's one and shows "Detected from your browser" - nothing is stored
 *   until the player saves the form. Browsers may report an old name (Chrome: Asia/Calcutta); `aliases` maps those to
 *   the zone the list carries.
 * - Opened through `#timezone` (the "Complete your profile" panel links here): scrolls the field into view below the
 *   sticky header and focuses it.
 */
export default class extends Controller {
    static targets = ['select', 'detected'];

    static values = {
        aliases: Object,
    };

    connect() {
        this.preselectDetected();

        this.tomSelect = new TomSelect(this.selectTarget, {
            allowEmptyOption: true,
            openOnFocus: false,
            maxOptions: null,
        });

        this.tomSelect.on('change', () => {
            if (this.hasDetectedTarget) {
                this.detectedTarget.hidden = true;
            }
        });

        if (window.location.hash === `#${this.selectTarget.id}`) {
            requestAnimationFrame(() => {
                this.element.scrollIntoView({ block: 'start' });
                this.tomSelect.focus();
            });
        }
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
            this.tomSelect = null;
        }
    }

    preselectDetected() {
        // A value already there is the saved one (or the player's own pick restored by the browser)
        if (this.selectTarget.value !== '') {
            return;
        }

        const reported = browserTimeZone();

        if (reported === null) {
            return;
        }

        const zone = Object.prototype.hasOwnProperty.call(this.aliasesValue, reported) ? this.aliasesValue[reported] : reported;
        const offered = Array.from(this.selectTarget.options).some((option) => option.value === zone);

        if (!offered) {
            return;
        }

        this.selectTarget.value = zone;

        if (this.hasDetectedTarget) {
            this.detectedTarget.hidden = false;
        }
    }
}
