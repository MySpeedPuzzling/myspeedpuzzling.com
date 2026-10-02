import { Controller } from '@hotwired/stimulus';

/**
 * Checks the add/edit time form while it is being filled in: the "first try" tag
 * (docs/features/first-try-integrity.md) and the same time already saved (docs/features/duplicate-results.md,
 * Layer 2). As soon as the puzzle, the time, the date, the co-puzzlers or the tag change, the server answers with
 * the notice a refused submit would show - so nobody learns about it only on save.
 *
 * It never submits, re-renders or navigates the form: every other field and a chosen photo stay as they are.
 * The texts come with the server's answer.
 */
export default class extends Controller {
    static targets = ['checkbox', 'resolution', 'duplicateConfirmed', 'notice', 'puzzle', 'date', 'mode', 'hours', 'minutes', 'seconds'];

    static values = {
        url: String,
        // Fixed puzzle (chosen beforehand, stopwatch, edit) - otherwise the puzzle field decides
        puzzle: { type: String, default: '' },
        // Edit only: the result being edited
        time: { type: String, default: '' },
    };

    uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    connect() {
        this.lastQuery = null;
        this.timer = null;
        this.controller = null;
        // What the form held when "It's another solve" was chosen - the answer is about exactly that
        this.confirmedFor = this.hasDuplicateConfirmedTarget && this.duplicateConfirmedTarget.value === '1' ? this.duplicateKey() : null;

        this.onChange = (event) => {
            // Clicks inside the notice itself are handled by the actions below
            if (this.hasNoticeTarget && this.noticeTarget.contains(event.target)) {
                return;
            }

            // "Make this result my first try" was an answer to what the form held then - never carry it over
            if (!this.hasCheckboxTarget || event.target !== this.checkboxTarget) {
                this.resolutionTarget.value = '';
            }

            this.schedule();
        };

        this.element.addEventListener('change', this.onChange);

        // A refused submit already rendered the notice for exactly what the form holds
        if (this.hasNoticeTarget && this.noticeTarget.innerHTML.trim() !== '') {
            this.lastQuery = this.query();
        } else {
            this.schedule();
        }
    }

    disconnect() {
        this.element.removeEventListener('change', this.onChange);
        clearTimeout(this.timer);
        this.controller?.abort();
    }

    move() {
        this.resolutionTarget.value = 'move';
        this.check();
    }

    undo() {
        this.resolutionTarget.value = '';
        this.check();
    }

    confirmDuplicate() {
        this.duplicateConfirmedTarget.value = '1';
        this.confirmedFor = this.duplicateKey();
        this.check();
    }

    undoDuplicate() {
        this.duplicateConfirmedTarget.value = '';
        this.confirmedFor = null;
        this.check();
    }

    untick() {
        this.checkboxTarget.checked = false;
        this.resolutionTarget.value = '';
        // The same time already saved still has its say
        this.check();
    }

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.check(), 250);
    }

    puzzle() {
        return this.puzzleValue || (this.hasPuzzleTarget ? this.puzzleTarget.value : '');
    }

    totalSeconds() {
        const value = (target) => parseInt(target.value, 10) || 0;

        if (!this.hasHoursTarget || !this.hasMinutesTarget || !this.hasSecondsTarget) {
            return 0;
        }

        return value(this.hoursTarget) * 3600 + value(this.minutesTarget) * 60 + value(this.secondsTarget);
    }

    groupPlayers() {
        const players = [];

        this.element.querySelectorAll('input[name="group_players[]"]').forEach((input) => {
            if (input.value.trim() !== '') {
                players.push(input.value);
            }
        });

        return players;
    }

    // Everything the same-time check compares - another value means another question
    duplicateKey() {
        return [this.puzzle(), this.totalSeconds(), this.hasDateTarget ? this.dateTarget.value : '', ...this.groupPlayers()].join('|');
    }

    query() {
        const params = new URLSearchParams();

        params.set('puzzle', this.puzzle());

        if (this.timeValue) {
            params.set('time', this.timeValue);
        }

        if (this.hasDateTarget && this.dateTarget.value) {
            params.set('date', this.dateTarget.value);
        }

        this.groupPlayers().forEach((player) => params.append('group_players[]', player));

        params.set('first_attempt', this.hasCheckboxTarget && this.checkboxTarget.checked ? '1' : '0');

        const seconds = this.totalSeconds();

        if (seconds > 0) {
            params.set('seconds', String(seconds));
        }

        params.set('resolution', this.resolutionTarget.value);

        if (this.hasDuplicateConfirmedTarget) {
            params.set('duplicate_confirmed', this.duplicateConfirmedTarget.value);
        }

        return params.toString();
    }

    async check() {
        if (this.isOtherMode()) {
            this.resolutionTarget.value = '';
            this.resetDuplicateConfirmation();
            this.clear();

            return;
        }

        // "It's another solve" was an answer to what the form held then
        if (this.confirmedFor !== null && this.confirmedFor !== this.duplicateKey()) {
            this.resetDuplicateConfirmation();
        }

        const ticked = this.hasCheckboxTarget && this.checkboxTarget.checked;

        if (!ticked) {
            this.resolutionTarget.value = '';
        }

        // A new puzzle has no history yet; without the tag only a complete time has something to compare
        if ((!this.timeValue && !this.uuidRegex.test(this.puzzle())) || (!ticked && this.totalSeconds() === 0)) {
            this.clear();

            return;
        }

        const query = this.query();

        if (query === this.lastQuery) {
            return;
        }

        this.lastQuery = query;
        this.controller?.abort();
        this.controller = new AbortController();

        try {
            const response = await fetch(`${this.urlValue}?${query}`, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
                signal: this.controller.signal,
            });

            if (!response.ok) {
                return;
            }

            this.noticeTarget.innerHTML = await response.text();
        } catch (error) {
            // Aborted by a newer check, or offline - the submit checks again anyway
        }
    }

    clear() {
        this.lastQuery = null;
        this.controller?.abort();

        if (this.hasNoticeTarget) {
            this.noticeTarget.innerHTML = '';
        }
    }

    resetDuplicateConfirmation() {
        this.confirmedFor = null;

        if (this.hasDuplicateConfirmedTarget) {
            this.duplicateConfirmedTarget.value = '';
        }
    }

    isOtherMode() {
        return this.hasModeTarget && this.modeTarget.value !== '' && this.modeTarget.value !== 'speed_puzzling';
    }
}
