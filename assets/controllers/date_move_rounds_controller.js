/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "Move the rounds too" on the edit event form (templates/edit_competition.html.twig, docs/features/competitions-management/
 * README.md "Moving an event's date"): asked only once the start date differs from the saved one (smart forms - a
 * question that changes nothing is not asked). Without JavaScript the checkbox is simply visible. The date picker
 * (flatpickr) writes the original input and fires its change event, which bubbles here.
 *
 * The end date follows: while it still shows the saved end (or the one this controller put there), a new start moves it
 * by the same number of days - the organiser sees it and can change it. The server saves what was submitted.
 */
export default class extends Controller {
    static targets = ['date', 'end', 'option'];

    static values = {
        original: String,
        originalEnd: String,
    };

    connect() {
        this.followedEnd = this.originalEndValue;
        this.update();
    }

    update() {
        if (!this.hasDateTarget) {
            return;
        }

        const start = this.dateTarget.value.trim();

        if (this.hasOptionTarget) {
            this.optionTarget.hidden = start === this.originalValue;
        }

        this.followEnd(start);
    }

    followEnd(start) {
        if (!this.hasEndTarget || this.originalEndValue === '' || this.endTarget.value.trim() !== this.followedEnd) {
            return;
        }

        const days = daysBetween(this.originalValue, start);

        if (days === null) {
            return;
        }

        const end = addDays(this.originalEndValue, days);

        if (end === null || end === this.endTarget.value.trim()) {
            return;
        }

        this.followedEnd = end;

        if (this.endTarget._flatpickr) {
            // false: no change event - the end date's own listeners have nothing to do with it
            this.endTarget._flatpickr.setDate(end, false, 'd.m.Y');
        } else {
            this.endTarget.value = end;
        }
    }
}

// d.m.Y (the form's format) → UTC midnight in ms, null when unreadable
function parseDay(value) {
    const match = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(value);

    return match ? Date.UTC(Number(match[3]), Number(match[2]) - 1, Number(match[1])) : null;
}

function daysBetween(from, to) {
    const a = parseDay(from);
    const b = parseDay(to);

    return a === null || b === null ? null : Math.round((b - a) / 86400000);
}

function addDays(value, days) {
    const day = parseDay(value);

    if (day === null) {
        return null;
    }

    const moved = new Date(day + days * 86400000);
    const pad = (n) => String(n).padStart(2, '0');

    return `${pad(moved.getUTCDate())}.${pad(moved.getUTCMonth() + 1)}.${moved.getUTCFullYear()}`;
}
