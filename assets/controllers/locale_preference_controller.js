import { Controller } from '@hotwired/stimulus';

/**
 * Remembers the visitor's language choice and suggests it back.
 *
 * - save():  attached to language links (header switcher + suggestion banner)
 * - banner mode (homepage at "/" only): if a stored preference exists,
 *   differs from the current locale and its homepage URL differs from the
 *   current path, reveal a small dismissible banner linking to that locale's
 *   homepage (native-language label). No stored preference = no banner.
 */
export default class extends Controller {
    static targets = ['banner', 'link'];

    static values = {
        banner: { type: Boolean, default: false },
        urls: { type: Object, default: {} },
        labels: { type: Object, default: {} },
    };

    static STORAGE_KEY = 'msp-preferred-locale';

    static DISMISS_KEY = 'msp-locale-banner-dismissed';

    connect() {
        if (!this.bannerValue || !this.hasBannerTarget || !this.hasLinkTarget) {
            return;
        }

        let preferred = null;
        let dismissed = null;

        try {
            preferred = window.localStorage.getItem(this.constructor.STORAGE_KEY);
            dismissed = window.sessionStorage.getItem(this.constructor.DISMISS_KEY);
        } catch (e) {
            return;
        }

        if (dismissed !== null || !preferred) {
            return;
        }

        if (preferred === document.documentElement.lang) {
            return;
        }

        const url = this.urlsValue[preferred];
        const label = this.labelsValue[preferred];

        if (!url || !label || url === window.location.pathname) {
            return;
        }

        this.linkTarget.href = url;
        this.linkTarget.textContent = `${label} →`;
        this.linkTarget.dataset.localePreferenceLocaleParam = preferred;
        this.bannerTarget.classList.remove('d-none');
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

    dismiss() {
        if (this.hasBannerTarget) {
            this.bannerTarget.classList.add('d-none');
        }

        try {
            window.sessionStorage.setItem(this.constructor.DISMISS_KEY, '1');
        } catch (e) {
            // Storage disabled - the banner is hidden for this page view anyway.
        }
    }
}
