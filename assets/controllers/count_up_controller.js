import { Controller } from '@hotwired/stimulus';

/**
 * Animated live counters for the homepage scoreboard.
 *
 * The server renders the real numbers into the HTML (SEO / no-JS visitors).
 * When a counter scrolls into view it counts up from ~85% to its real value;
 * afterwards a small JSON endpoint is polled and any changed number animates
 * from the old value to the new one. Respects prefers-reduced-motion
 * (numbers are set instantly, no animation).
 */
export default class extends Controller {
    static targets = ['number'];

    static values = {
        url: String,
        pollInterval: { type: Number, default: 30000 },
        duration: { type: Number, default: 2600 },
    };

    connect() {
        // Grouping via non-breaking spaces in every locale (design decision),
        // digits via en-US so server (number_format) and client always match.
        this.numberFormatter = new Intl.NumberFormat('en-US');
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.currentValues = new Map();
        this.animationFrames = new Map();

        this.numberTargets.forEach((element) => {
            const value = parseInt(element.dataset.countUpValue, 10) || 0;
            this.currentValues.set(element, value);
            // Re-render server output through the shared formatter so HTML and
            // JS formatting always match (prevents a jump on first animation).
            this.render(element, value);
        });

        this.observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                this.observer.unobserve(entry.target);

                const value = this.currentValues.get(entry.target) ?? 0;
                this.animate(entry.target, Math.floor(value * 0.85), value);
            });
        }, { threshold: 0.3 });

        this.numberTargets.forEach((element) => this.observer.observe(element));

        if (this.hasUrlValue && this.urlValue !== '') {
            this.pollTimer = setInterval(() => this.poll(), this.pollIntervalValue);
        }
    }

    disconnect() {
        this.observer?.disconnect();

        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }

        this.animationFrames.forEach((frame) => cancelAnimationFrame(frame));
        this.animationFrames.clear();
    }

    async poll() {
        if (document.hidden) {
            return;
        }

        let stats;

        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                return;
            }

            stats = await response.json();
        } catch (e) {
            // Offline / flaky connection - just try again on the next tick.
            return;
        }

        this.numberTargets.forEach((element) => {
            const next = Number(stats[element.dataset.countUpKey]);

            if (!Number.isFinite(next)) {
                return;
            }

            const current = this.currentValues.get(element) ?? 0;

            if (next === current) {
                return;
            }

            this.currentValues.set(element, next);
            this.animate(element, current, next);
        });
    }

    render(element, value) {
        const grouped = this.numberFormatter
            .formatToParts(value)
            .map((part) => (part.type === 'group' ? '\u00a0' : part.value))
            .join('');

        element.textContent = grouped + (element.dataset.countUpSuffix ?? '');
    }

    animate(element, from, to) {
        if (this.reducedMotion || from === to) {
            this.render(element, to);

            return;
        }

        const existingFrame = this.animationFrames.get(element);

        if (existingFrame) {
            cancelAnimationFrame(existingFrame);
        }

        const start = performance.now();

        const tick = (now) => {
            const progress = Math.min((now - start) / this.durationValue, 1);
            // ease-out-quint: fast start, long gentle landing - reads smoother
            // on large numbers than cubic.
            const eased = 1 - Math.pow(1 - progress, 5);

            this.render(element, Math.round(from + (to - from) * eased));

            if (progress < 1) {
                this.animationFrames.set(element, requestAnimationFrame(tick));
            } else {
                this.animationFrames.delete(element);
            }
        };

        this.animationFrames.set(element, requestAnimationFrame(tick));
    }
}
