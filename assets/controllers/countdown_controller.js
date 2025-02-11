import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['days', 'hours', 'minutes', 'seconds']

    connect() {
        // Fetch desired end date/time from the DOM (data attribute)
        // Example data attribute: data-countdown-end-value="2025-02-14T23:59:59"
        this.endValue = this.element.dataset.countdownEndValue;
        this.endDate = new Date(this.endValue);

        this.updateCountdown();
        this.timer = setInterval(() => this.updateCountdown(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    updateCountdown() {
        const now = new Date();
        const distance = this.endDate - now;

        // If time is up or already passed
        if (distance <= 0) {
            this.#showZeros();
            return;
        }

        // Calculate days, hours, minutes, seconds
        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        // Format hours, minutes, seconds with leading zeros if < 10
        const hoursString = hours < 10 ? '0' + hours : hours;
        const minutesString = minutes < 10 ? '0' + minutes : minutes;
        const secondsString = seconds < 10 ? '0' + seconds : seconds;

        // Update DOM
        this.daysTarget.textContent = days;
        this.hoursTarget.textContent = hoursString;
        this.minutesTarget.textContent = minutesString;
        this.secondsTarget.textContent = secondsString;
    }

    #showZeros() {
        this.daysTarget.textContent = '0';
        this.hoursTarget.textContent = '00';
        this.minutesTarget.textContent = '00';
        this.secondsTarget.textContent = '00';
        clearInterval(this.timer);
    }
}
