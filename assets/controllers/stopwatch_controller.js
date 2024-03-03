import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["hours", "minutes", "seconds"];

    connect() {
        this.status = this.element.getAttribute('data-stopwatch-status');
        this.serverStartTime = new Date(this.element.getAttribute('data-stopwatch-now'));
        this.startTime = new Date(this.element.getAttribute('data-stopwatch-start'));
        this.totalElapsedSeconds = parseInt(this.element.getAttribute('data-stopwatch-total-seconds'), 10);

        this.offset = this.calculateOffset();
        this.timer = setInterval(() => this.calculateTimeElapsed(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    calculateOffset() {
        const clientTime = new Date();
        return clientTime - this.serverStartTime; // Time difference in milliseconds
    }

    pad(number) {
        return number < 10 ? '0' + number : number;
    }

    calculateTimeElapsed() {
        if (this.status !== 'running') {
            return;
        }

        let now;
        if (this.offset > 2000) {
            now = new Date(new Date().getTime() - this.offset + 300);
        } else {
            now = new Date();
        }

        const differenceInSeconds = Math.floor((now - this.startTime) / 1000) + this.totalElapsedSeconds;
        const hours = Math.floor(differenceInSeconds / 3600);
        const minutes = Math.floor((differenceInSeconds % 3600) / 60);
        const seconds = differenceInSeconds % 60;

        this.hoursTarget.textContent = this.pad(hours);
        this.minutesTarget.textContent = this.pad(minutes);
        this.secondsTarget.textContent = this.pad(seconds);
    }
}
