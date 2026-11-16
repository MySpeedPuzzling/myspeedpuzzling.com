import { Controller } from '@hotwired/stimulus';

/*
 * "Check sheet" on the organization's Results import page (docs/features/event-results-import/results-import-page.md):
 * reading the sheet from Google takes seconds, so the moment the form is sent the button says what is happening, a
 * moving bar and a running count of seconds show it is alive, and after a while a line says a big sheet takes longer.
 * Nothing here is needed for the check itself - without the script the form is sent as before.
 */
export default class extends Controller {
    static targets = ['button', 'status', 'elapsed', 'slow'];

    static values = {
        busy: String,
        slowAfter: { type: Number, default: 8 },
    };

    connect() {
        this.restore = this.restore.bind(this);
        this.ended = this.ended.bind(this);
        this.originalLabel = this.hasButtonTarget ? this.buttonTarget.innerHTML : '';
        window.addEventListener('pageshow', this.restore);
        this.element.addEventListener('turbo:submit-end', this.ended);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.restore);
        this.element.removeEventListener('turbo:submit-end', this.ended);
        this.stopTimer();
    }

    start(event) {
        if (this.running) {
            event.preventDefault();

            return;
        }

        this.running = true;
        this.startedAt = Date.now();

        if (this.hasButtonTarget) {
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-2';
            spinner.setAttribute('aria-hidden', 'true');
            this.buttonTarget.replaceChildren(spinner, document.createTextNode(this.busyValue));
            // Turbo switches the button off itself - a form sent without Turbo still sends its token first
            setTimeout(() => {
                this.buttonTarget.disabled = true;
            }, 0);
        }

        if (this.hasStatusTarget) {
            this.statusTarget.hidden = false;
        }

        this.tick();
        this.timer = setInterval(() => this.tick(), 1000);
    }

    tick() {
        const seconds = Math.floor((Date.now() - this.startedAt) / 1000);

        if (this.hasElapsedTarget) {
            this.elapsedTarget.textContent = String(seconds);
        }

        if (this.hasSlowTarget && seconds >= this.slowAfterValue) {
            this.slowTarget.hidden = false;
        }
    }

    // The answer is a redirect to the page with the new check - only a failed request comes back to this form
    ended(event) {
        if (event.detail && event.detail.success === false) {
            this.restore();
        }
    }

    restore() {
        this.running = false;
        this.stopTimer();

        if (this.hasButtonTarget) {
            this.buttonTarget.innerHTML = this.originalLabel;
            this.buttonTarget.disabled = false;
        }

        if (this.hasStatusTarget) {
            this.statusTarget.hidden = true;
        }

        if (this.hasSlowTarget) {
            this.slowTarget.hidden = true;
        }
    }

    stopTimer() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }
}
