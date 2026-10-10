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

        // Thousands grouped with the separator of the server-rendered number in this element (the template's
        // number_format, the same in every locale), so the spin ends on exactly the text the server printed
        const separator = this.element.textContent.trim().match(/\d(\D+)\d{3}$/)?.[1] ?? '';
        const format = (value) => String(value).replace(/\B(?=(\d{3})+(?!\d))/g, separator);
        const start = performance.now();

        const tick = (now) => {
            const progress = Math.min((now - start) / this.durationValue, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            this.element.textContent = format(Math.round(eased * this.amountValue));

            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        };

        this.element.textContent = format(0);
        requestAnimationFrame(tick);
    }
}
