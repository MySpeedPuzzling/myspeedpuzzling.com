import { Controller } from '@hotwired/stimulus';

/**
 * Progress bars fill from 0 the first time they scroll into view - 1 s, once per page
 * (docs/features/xp-levels/ux-polish/design-spec.md §5: movement on the number that changed draws the eye, once, so
 * it is never noise). Nothing moves under prefers-reduced-motion, without IntersectionObserver, or for a bar the
 * server rendered at 0.
 *
 * Usage - any bar whose fill is an element with an inline width:
 *
 *   <div data-controller="progress-fill">
 *       <span class="track"><span style="width: 64%" data-progress-fill-target="bar"></span></span>
 *   </div>
 *
 * The server's width is the truth (no-JS visitors, crawlers, screen readers via aria-valuenow on the track): the
 * controller only holds a bar at 0 (`.progress-fill-pending`) until it is seen, then lets it grow back with a CSS
 * transition (`.progress-fill-run`). Bars added later (Turbo, Live Components) are picked up through the target
 * callbacks. Styles: assets/styles/_achievements-shelf.scss.
 *
 * Loaded eagerly (not the lazy fetch) on purpose: it is tiny, and a lazily loaded controller would connect after
 * the first paint, so the bars would visibly jump full → empty → full.
 */
export default class extends Controller {
    static targets = ['bar'];

    initialize() {
        this.enabled = typeof window.IntersectionObserver === 'function'
            && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.observer = null;
    }

    disconnect() {
        if (this.observer !== null) {
            this.observer.disconnect();
            this.observer = null;
        }

        this.barTargets.forEach((bar) => this.reset(bar));
    }

    barTargetConnected(bar) {
        if (!this.enabled || bar.dataset.progressFillDone === 'true' || parseFloat(bar.style.width) === 0) {
            return;
        }

        bar.classList.add('progress-fill-pending');
        this.ensureObserver().observe(bar);
    }

    barTargetDisconnected(bar) {
        if (this.observer !== null) {
            this.observer.unobserve(bar);
        }

        this.reset(bar);
    }

    ensureObserver() {
        if (this.observer === null) {
            this.observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        this.observer.unobserve(entry.target);
                        this.fill(entry.target);
                    }
                });
            }, { threshold: 0.5 });
        }

        return this.observer;
    }

    fill(bar) {
        bar.dataset.progressFillDone = 'true';
        bar.classList.add('progress-fill-run');
        // Read the layout once, so the browser has the bar at 0 before it may grow
        void bar.offsetWidth;
        bar.classList.remove('progress-fill-pending');

        const done = () => bar.classList.remove('progress-fill-run');
        bar.addEventListener('transitionend', done, { once: true });
    }

    reset(bar) {
        bar.classList.remove('progress-fill-pending', 'progress-fill-run');
    }
}
