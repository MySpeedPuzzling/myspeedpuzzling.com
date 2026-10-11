import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * After a leaderboard's gap row or "Show more" revealed rows (templates/leaderboards/_window_announcer.html.twig,
 * docs/features/xp-levels/ux-polish/design-spec.md §8.4): focus moves to the first new row (WCAG 2.4.3) and a polite live
 * region says what came (WCAG 4.1.3). Runs on connect (a Turbo Frame render) and whenever the values change (a Live
 * Component morph).
 */
const LIVE_REGION_ID = 'leaderboard-window-live';

export default class extends Controller {
    static values = { focus: String, announce: String };

    connect() {
        this.run();
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    focusValueChanged() {
        this.run();
    }

    announceValueChanged() {
        this.run();
    }

    run() {
        if (!this.element.isConnected || this.lastRun === `${this.focusValue}|${this.announceValue}`) {
            return;
        }

        this.lastRun = `${this.focusValue}|${this.announceValue}`;

        const row = this.focusValue ? document.getElementById(this.focusValue) : null;

        if (row) {
            if (!row.hasAttribute('tabindex')) {
                row.setAttribute('tabindex', '-1');
            }

            row.focus({ preventScroll: false });
        }

        if (this.announceValue) {
            const region = this.liveRegion();
            region.textContent = '';

            // A changed text is announced; set it after the focus move so both are read
            if (this.timer) {
                clearTimeout(this.timer);
            }

            this.timer = setTimeout(() => {
                region.textContent = this.announceValue;
                this.timer = null;
            }, 100);
        }
    }

    liveRegion() {
        let region = document.getElementById(LIVE_REGION_ID);

        if (!region) {
            region = document.createElement('div');
            region.id = LIVE_REGION_ID;
            region.className = 'visually-hidden';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }

        return region;
    }
}
