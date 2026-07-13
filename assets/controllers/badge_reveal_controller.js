import { Controller } from '@hotwired/stimulus';

/**
 * First-click badge reveal: flips the medallion with a small confetti burst and
 * persists the reveal. Fire-and-forget POST — the flip is optimistic.
 */
export default class extends Controller {
    static values = {
        url: String,
    };

    reveal() {
        if (this.element.classList.contains('is-revealed')) {
            return;
        }

        this.element.classList.add('is-revealed');

        fetch(this.urlValue, { method: 'POST' });

        this.burstConfetti();
    }

    burstConfetti() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const confetti = document.createElement('span');
        confetti.className = 'xp-confetti xp-confetti-burst';
        confetti.setAttribute('aria-hidden', 'true');

        for (let i = 0; i < 10; i++) {
            confetti.appendChild(document.createElement('i'));
        }

        this.element.appendChild(confetti);

        setTimeout(() => confetti.remove(), 3000);
    }
}
