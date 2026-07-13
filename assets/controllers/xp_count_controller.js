import { Controller } from '@hotwired/stimulus';

/**
 * One-shot XP counter spin for the launch reveal page: counts from 0 to the
 * server-rendered value. Reduced motion → the real number shows instantly
 * (it is already in the markup for no-JS visitors anyway).
 */
export default class extends Controller {
    static values = {
        amount: Number,
        duration: { type: Number, default: 1800 },
    };

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || this.amountValue <= 0) {
            return;
        }

        const start = performance.now();
        const formatter = new Intl.NumberFormat('en-US');

        const tick = (now) => {
            const progress = Math.min((now - start) / this.durationValue, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            this.element.textContent = formatter.format(Math.round(eased * this.amountValue));

            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        };

        requestAnimationFrame(tick);
    }
}
