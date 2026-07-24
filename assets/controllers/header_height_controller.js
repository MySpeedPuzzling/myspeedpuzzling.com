import { Controller } from '@hotwired/stimulus';

/**
 * Publishes the real height of the site header as the `--header-height` custom
 * property on <html>.
 *
 * Everything that has to sit right under the header - the global search panel,
 * toasts, sticky filter bars, the player profile bar, the locale banner - used
 * to hardcode ~97px. That silently breaks whenever the header is a different
 * height than the guess: the Spanish navbar wraps to 107px, and an announcement
 * strip above the topbar adds another 28-64px depending on the language. The
 * page flow itself needs none of this (the header is `position: sticky`, so it
 * takes its own space); only viewport-fixed overlays do.
 *
 * The SCSS keeps a static fallback, so those overlays are merely a few pixels
 * off - never underneath the header - if this never runs.
 */
export default class extends Controller {
    connect() {
        this.publish();

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        // The header changes height on its own: the mobile menu expands, the
        // announcement strip is dismissed, fonts swap in, the viewport rotates
        this.observer = new ResizeObserver(() => this.publish());
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
    }

    publish() {
        const height = Math.round(this.element.getBoundingClientRect().height);

        if (height > 0) {
            document.documentElement.style.setProperty('--header-height', `${height}px`);
        }
    }
}
