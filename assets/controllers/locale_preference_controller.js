import { Controller } from '@hotwired/stimulus';

/**
 * Remembers the visitor's language choice so the crossroads page at "/"
 * can auto-continue returning visitors to their homepage.
 *
 * - save():   attached to language links (crossroads cards + header switcher)
 * - connect() with the "autoredirect" value (crossroads page only): if a
 *   preference is stored, continue to that locale's homepage immediately.
 */
export default class extends Controller {
    static values = {
        autoredirect: { type: Boolean, default: false },
        urls: { type: Object, default: {} },
    };

    static STORAGE_KEY = 'msp-preferred-locale';

    connect() {
        if (!this.autoredirectValue) {
            return;
        }

        let preferred = null;

        try {
            preferred = window.localStorage.getItem(this.constructor.STORAGE_KEY);
        } catch (e) {
            return;
        }

        if (preferred && this.urlsValue[preferred]) {
            window.location.replace(this.urlsValue[preferred]);
        }
    }

    save(event) {
        const locale = event.params.locale;

        if (!locale) {
            return;
        }

        try {
            window.localStorage.setItem(this.constructor.STORAGE_KEY, locale);
        } catch (e) {
            // Private mode / storage disabled - the click still navigates normally.
        }
    }
}
