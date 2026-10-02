import { Controller } from '@hotwired/stimulus';

/**
 * The puzzle page's compact bar (thumbnail, name, pieces · brand, ⋯ menu): it slides in under the site header once
 * the puzzle's own buttons have scrolled away above it, and out again when they come back - the leaderboard below
 * can be hundreds of rows long. While hidden it is `inert`, so neither Tab nor a screen reader lands in it.
 */
export default class extends Controller {
    static targets = ['head', 'bar'];

    connect() {
        this.publishHeight();

        if (typeof ResizeObserver !== 'undefined') {
            // Its height follows the name (one or two lines), the root font size and the viewport
            this.resizeObserver = new ResizeObserver(() => this.publishHeight());
            this.resizeObserver.observe(this.barTarget);
        }

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
        this.resizeObserver?.disconnect();
        window.removeEventListener('resize', this.onResize);

        // Turbo keeps <html> between pages - no other page has the bar
        document.documentElement.style.removeProperty('--puzzle-bar-height');
    }

    /**
     * `--puzzle-bar-height` on <html>: anchors on the page ("Jump to me" in the leaderboard) land below the bar,
     * which is always shown by the time they are reached. Measured while hidden too - only translated, never collapsed.
     */
    publishHeight() {
        const height = Math.round(this.barTarget.getBoundingClientRect().height);

        if (height > 0) {
            document.documentElement.style.setProperty('--puzzle-bar-height', `${height}px`);
        }
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
