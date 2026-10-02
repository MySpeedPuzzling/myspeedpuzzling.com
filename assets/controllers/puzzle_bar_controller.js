import { Controller } from '@hotwired/stimulus';

/**
 * The puzzle page's compact bar (thumbnail, name, pieces · brand, ⋯ menu): it slides in under the site header once
 * the puzzle's own buttons have scrolled away above it, and out again when they come back - the leaderboard below
 * can be hundreds of rows long. While hidden it is `inert`, so neither Tab nor a screen reader lands in it.
 */
export default class extends Controller {
    static targets = ['head', 'bar'];

    connect() {
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        this.observe();

        // The site header changes height (menu, fonts, rotation) - the line the buttons leave behind moves with it
        this.onResize = () => this.observe();
        window.addEventListener('resize', this.onResize, { passive: true });
    }

    disconnect() {
        this.observer?.disconnect();
        window.removeEventListener('resize', this.onResize);
    }

    observe() {
        this.observer?.disconnect();

        const headerHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header-height'), 10) || 0;

        this.observer = new IntersectionObserver(([entry]) => {
            // Only once the buttons are above the fold line, not while the page loads with them below it
            this.toggle(entry.isIntersecting === false && entry.boundingClientRect.top < headerHeight);
        }, { rootMargin: `-${headerHeight}px 0px 0px 0px` });

        this.observer.observe(this.headTarget);
    }

    toggle(visible) {
        this.barTarget.classList.toggle('is-visible', visible);
        this.barTarget.inert = !visible;

        // A menu left open in the bar closes with it
        if (!visible) {
            this.barTarget.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((toggle) => {
                window.bootstrap?.Dropdown.getInstance(toggle)?.hide();
            });
        }
    }
}
