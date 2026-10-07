import { Controller } from '@hotwired/stimulus';

/**
 * Checks the add/edit time form while it is being filled in: the "first try" tag
 * (docs/features/first-try-integrity.md), the same time already saved (docs/features/duplicate-results.md,
 * Layer 2) and a typed time far off the player's own times (docs/features/suspicious-time-review.md, "Catch it while
 * typing"). As soon as the puzzle, the time, the date, the co-puzzlers or the tag change, the server answers with
 * the notice a refused submit would show - so nobody learns about it only on save.
 *
 * It never submits, re-renders or navigates the form: every other field and a chosen photo stay as they are.
 * The texts come with the server's answer.
 */
export default class extends Controller {
    static targets = ['checkbox', 'resolution', 'duplicateConfirmed', 'paceConfirmed', 'notice', 'puzzle', 'date', 'mode', 'hours', 'minutes', 'seconds'];

    static values = {
        url: String,
        // Fixed puzzle (chosen beforehand, stopwatch, edit) - otherwise the puzzle field decides
        puzzle: { type: String, default: '' },
        // Edit only: the result being edited
        time: { type: String, default: '' },
        // Judge the time against the player's own times - not when a stopwatch measured it
        pace: { type: Boolean, default: false },
    };

    uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    connect() {
        this.lastQuery = null;
        this.timer = null;
        this.controller = null;
        // What the form held when "It's another solve" was chosen - the answer is about exactly that
        this.confirmedFor = this.hasDuplicateConfirmedTarget && this.duplicateConfirmedTarget.value === '1' ? this.duplicateKey() : null;
        // What the form held when "Yes, it's right" was chosen about the time (the field holds the server's key of it)
        this.paceConfirmedFor = this.hasPaceConfirmedTarget && this.paceConfirmedTarget.value !== '' ? this.duplicateKey() : null;
        // What the form held when the notice shown was made
        this.noticeFor = null;

        this.onChange = (event) => {
            // Clicks inside the notice itself are handled by the actions below
            if (this.hasNoticeTarget && this.noticeTarget.contains(event.target)) {
                return;
            }

            this.forgetStaleAnswers();

            // "Make this result my first try" was an answer to what the form held then - never carry it over
            if (!this.hasCheckboxTarget || event.target !== this.checkboxTarget) {
                this.resolutionTarget.value = '';
            }

            this.schedule();
        };

        // Typing changes the time before any change event (and the debounced check) - a submit right away must not
        // carry an answer given about the previous value
        this.onInput = (event) => {
            if (this.hasNoticeTarget && this.noticeTarget.contains(event.target)) {
                return;
            }

            this.forgetStaleAnswers();
        };

        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('input', this.onInput);

        // A refused submit already rendered the notice for exactly what the form holds
        if (this.hasNoticeTarget && this.noticeTarget.innerHTML.trim() !== '') {
            this.lastQuery = this.query();
            this.noticeFor = this.duplicateKey();
        } else {
            this.schedule();
        }
    }

    disconnect() {
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('input', this.onInput);
        clearTimeout(this.timer);
        this.controller?.abort();
    }

    // Right away, not after the debounce: "Yes, it's right" was an answer to the values the form held then, and the
    // notice's judgement of the time (data-pace-checked, which keeps the generic pace modal quiet) was about them too
    forgetStaleAnswers() {
        const key = this.duplicateKey();

        if (this.paceConfirmedFor !== null && this.paceConfirmedFor !== key) {
            this.resetPaceConfirmation();
        }

        if (this.noticeFor !== null && this.noticeFor !== key) {
            this.dropPaceJudgement();
        }
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

    // The notice's button carries the key of the values the server judged - the answer counts only for them
    confirmPace(event) {
        this.paceConfirmedTarget.value = event.params.key || '';
        this.paceConfirmedFor = this.paceConfirmedTarget.value !== '' ? this.duplicateKey() : null;
        this.check();
    }

    undoPace() {
        this.resetPaceConfirmation();
        this.check();
    }

    // "Use 2:49:08" - the time the notice suggests goes into the time fields, then it is checked like a typed one
    useSuggested(event) {
        const total = parseInt(event.params.seconds, 10);

        if (!total || !this.hasHoursTarget || !this.hasMinutesTarget || !this.hasSecondsTarget) {
            return;
        }

        this.hoursTarget.value = String(Math.floor(total / 3600));
        this.minutesTarget.value = String(Math.floor((total % 3600) / 60));
        this.secondsTarget.value = String(total % 60);

        // Whoever else listens to the time fields learns about it too (this controller's own listener schedules
        // the check)
        [this.hoursTarget, this.minutesTarget, this.secondsTarget].forEach((input) => {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        this.resetPaceConfirmation();
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

    // Everything the same-time and the pace check compare - another value means another question
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

        if (this.paceValue) {
            params.set('pace', '1');

            if (this.hasPaceConfirmedTarget) {
                params.set('pace_confirmed', this.paceConfirmedTarget.value);
            }
        }

        return params.toString();
    }

    async check() {
        if (this.isOtherMode()) {
            this.resolutionTarget.value = '';
            this.resetDuplicateConfirmation();
            this.resetPaceConfirmation();
            this.clear();

            return;
        }

        // "It's another solve" was an answer to what the form held then
        if (this.confirmedFor !== null && this.confirmedFor !== this.duplicateKey()) {
            this.resetDuplicateConfirmation();
        }

        // So was "Yes, it's right" - another puzzle, time, day or group is another question
        if (this.paceConfirmedFor !== null && this.paceConfirmedFor !== this.duplicateKey()) {
            this.resetPaceConfirmation();
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
        const askedFor = this.duplicateKey();

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
            this.noticeFor = askedFor;
            // The form changed while the answer was on its way - it is about the values before
            this.forgetStaleAnswers();
        } catch (error) {
            // Aborted by a newer check, or offline - the submit checks again anyway
        }
    }

    clear() {
        this.lastQuery = null;
        this.noticeFor = null;
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

    resetPaceConfirmation() {
        this.paceConfirmedFor = null;

        if (this.hasPaceConfirmedTarget) {
            this.paceConfirmedTarget.value = '';
        }

        this.dropPaceJudgement();
    }

    // Until the next answer arrives nothing has judged the time: the generic pace modal (ppm_validator_controller.js)
    // asks on submit again, the server's own check decides anyway
    dropPaceJudgement() {
        // Whatever the form holds next is asked about again, even the values of the notice before
        this.lastQuery = null;

        if (!this.hasNoticeTarget) {
            return;
        }

        this.noticeTarget.querySelectorAll('[data-pace-checked]').forEach((element) => {
            element.removeAttribute('data-pace-checked');
        });
    }

    isOtherMode() {
        return this.hasModeTarget && this.modeTarget.value !== '' && this.modeTarget.value !== 'speed_puzzling';
    }
}
