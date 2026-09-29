/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "Send a new link" on the check-your-email screens: held back for a few
 * seconds after a mail went out, so an impatient second tap doesn't fire
 * before the first mail could possibly arrive. The server-side rate limit is
 * the real limit - this only saves people from spending it by accident.
 *
 * Rendered enabled (works without JavaScript); disabled here with a visible
 * countdown. Texts come from data attributes (translations stay in Twig).
 */
export default class extends Controller {
    static targets = ['button', 'label'];

    static values = {
        seconds: { type: Number, default: 45 },
        waiting: String,
    };

    connect() {
        this.idleLabel = this.labelTarget.textContent;
        this.remaining = this.secondsValue;

        if (this.remaining <= 0) {
            return;
        }

        this.buttonTarget.disabled = true;
        this.render();
        this.timer = window.setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        window.clearInterval(this.timer);
    }

    tick() {
        this.remaining -= 1;

        if (this.remaining <= 0) {
            window.clearInterval(this.timer);
            this.buttonTarget.disabled = false;
            this.labelTarget.textContent = this.idleLabel;

            return;
        }

        this.render();
    }

    render() {
        const minutes = Math.floor(this.remaining / 60);
        const seconds = String(this.remaining % 60).padStart(2, '0');

        this.labelTarget.textContent = this.waitingValue.replace('%time%', `${minutes}:${seconds}`);
    }
}
