import { Controller } from '@hotwired/stimulus';

/**
 * Counts down to the next quarter-hour mark (:00, :15, :30, :45).
 * Auto-resets every 15 minutes.
 * Used for the recalculation countdown on the methodology and ELO ladder pages.
 *
 * Usage:
 *   <span data-controller="next-quarter-countdown">
 *     <span data-next-quarter-countdown-target="display">--:--</span>
 *   </span>
 */
export default class extends Controller {
    static targets = ['display']

    connect() {
        this.update();
        this.timer = setInterval(() => this.update(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    update() {
        const now = new Date();
        const minutesInQuarter = now.getMinutes() % 15;
        const mins = 14 - minutesInQuarter;
        const secs = 59 - now.getSeconds();
        this.displayTarget.textContent =
            String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }
}
