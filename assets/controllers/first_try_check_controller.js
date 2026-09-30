import { Controller } from '@hotwired/stimulus';

/**
 * Checks the "first try" tag of the add/edit time form while it is being filled in
 * (docs/features/first-try-integrity.md): as soon as the puzzle, the date, the co-puzzlers or the tag change,
 * the server answers with the notice a refused submit would show - so nobody learns about it only on save.
 *
 * It never submits, re-renders or navigates the form: every other field and a chosen photo stay as they are.
 * The texts come with the server's answer.
 */
export default class extends Controller {
    static targets = ['checkbox', 'resolution', 'notice', 'puzzle', 'date', 'mode'];

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

    untick() {
        this.checkboxTarget.checked = false;
        this.resolutionTarget.value = '';
        this.clear();
    }

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.check(), 250);
    }

    query() {
        const params = new URLSearchParams();
        const puzzle = this.puzzleValue || (this.hasPuzzleTarget ? this.puzzleTarget.value : '');

        params.set('puzzle', puzzle);

        if (this.timeValue) {
            params.set('time', this.timeValue);
        }

        if (this.hasDateTarget && this.dateTarget.value) {
            params.set('date', this.dateTarget.value);
        }

        this.element.querySelectorAll('input[name="group_players[]"]').forEach((input) => {
            if (input.value.trim() !== '') {
                params.append('group_players[]', input.value);
            }
        });

        params.set('resolution', this.resolutionTarget.value);

        return params.toString();
    }

    async check() {
        if (!this.hasCheckboxTarget || !this.checkboxTarget.checked || this.isOtherMode()) {
            this.resolutionTarget.value = '';
            this.clear();

            return;
        }

        const puzzle = this.puzzleValue || (this.hasPuzzleTarget ? this.puzzleTarget.value : '');

        // A new puzzle has no history yet
        if (!this.timeValue && !this.uuidRegex.test(puzzle)) {
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

    isOtherMode() {
        return this.hasModeTarget && this.modeTarget.value !== '' && this.modeTarget.value !== 'speed_puzzling';
    }
}
