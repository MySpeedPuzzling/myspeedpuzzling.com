import { Controller } from '@hotwired/stimulus';

/**
 * Counts down to the next full hour. Auto-resets every hour.
 * Used for the recalculation countdown on the methodology page.
 *
 * Usage:
 *   <span data-controller="next-hour-countdown">
 *     <span data-next-hour-countdown-target="display">--:--</span>
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
        const mins = 59 - now.getMinutes();
        const secs = 59 - now.getSeconds();
        this.displayTarget.textContent =
            String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }
}
