/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "I didn't finish within the time limit" in the add / edit-time form
 * (docs/features/competitions-management/unfinished-results-plan.md "Refined 2026-11-16"). On the <form>, beside
 * puzzle-add-form, ppm-validator and first-try-check, which know nothing about it.
 *
 * The server renders the right state (UnfinishedFormState); this keeps it right while the form is filled in, by the same
 * rules:
 *  - the round with a time limit for the form's competition + puzzle + solo/pair/team comes from the hint endpoint
 *    (solving_time_round_hint, the save's rule) - asked again, debounced, when one of them or the date changes; a newer
 *    question aborts an older one, so a slow answer never overwrites a newer one;
 *  - the switch shows while it is on, or when such a round is known and the typed time is empty or over its limit;
 *  - the time over the limit with the switch off: a non-blocking note suggests it;
 *  - on: the pieces placed field ("/ 500 · 21 pieces left" from the known piece count), the time relabelled as the
 *    optional total. Nothing is cleared - off again restores the labels;
 *  - inputs not shown are disabled, so they are never posted.
 *
 * Fail-open: a hint that cannot be fetched answers "no round" for anything but what the page was rendered with - the
 * form then works exactly as without this controller.
 */
export default class extends Controller {
    static targets = ['question', 'roundLine', 'roundText', 'switch', 'nudge', 'details', 'pieces', 'piecesTotal', 'piecesLeft', 'timeLabel', 'timeHelp'];

    static values = {
        hintUrl: String,
        round: Object,
        piecesTotal: { type: Number, default: 0 },
        texts: Object,
    };

    connect() {
        this.round = this.hasRoundValue && this.roundValue && this.roundValue.minutesLimit ? this.roundValue : null;
        this.renderedRound = this.round;
        this.piecesCount = this.piecesTotalValue > 0 ? this.piecesTotalValue : null;
        this.request = null;
        this.timer = null;
        // What the page was rendered for - asked again only once something changes
        this.lastQuery = this.query();
        this.renderedQuery = this.lastQuery;

        this.onFormEvent = (event) => {
            if (this.hasPiecesTarget && event.target === this.piecesTarget) {
                this.render();

                return;
            }

            this.schedule();
            this.render();
        };
        this.onPiecesCount = (event) => {
            const count = event.detail ? event.detail.piecesCount : null;

            this.piecesCount = typeof count === 'number' && count > 0 ? count : null;
            this.render();
        };
        // The mode buttons sit outside the form (puzzle-add-form sets the hidden mode field without an event)
        this.onModeChange = (event) => {
            if (event.target && event.target.name === 'form-mode') {
                setTimeout(() => {
                    this.schedule();
                    this.render();
                }, 0);
            }
        };

        this.element.addEventListener('change', this.onFormEvent);
        this.element.addEventListener('input', this.onFormEvent);
        this.element.addEventListener('ppm:piecesCountUpdated', this.onPiecesCount);
        document.addEventListener('change', this.onModeChange);

        this.render();
    }

    disconnect() {
        this.element.removeEventListener('change', this.onFormEvent);
        this.element.removeEventListener('input', this.onFormEvent);
        this.element.removeEventListener('ppm:piecesCountUpdated', this.onPiecesCount);
        document.removeEventListener('change', this.onModeChange);
        clearTimeout(this.timer);
        this.request?.abort();
    }

    // --- reading the form ---

    field(suffix) {
        return Array.from(this.element.elements).find((element) => element.name && element.name.endsWith('[' + suffix + ']')) || null;
    }

    isSpeedMode() {
        const mode = this.field('mode');

        return !mode || !mode.value || mode.value === 'speed_puzzling';
    }

    typedSeconds() {
        const number = (suffix) => {
            const input = this.field(suffix);

            return input ? parseInt(input.value, 10) || 0 : 0;
        };

        return number('timeHours') * 3600 + number('timeMinutes') * 60 + number('timeSeconds');
    }

    puzzlers() {
        return 1 + Array.from(this.element.querySelectorAll('input[name="group_players[]"]'))
            .filter((input) => !input.disabled && input.value.trim() !== '')
            .length;
    }

    query() {
        const competition = this.field('competition');
        const puzzle = this.field('puzzle');
        const competitionValue = competition ? competition.value.trim() : '';
        const puzzleValue = puzzle ? puzzle.value.trim() : '';

        if (!this.isSpeedMode() || competitionValue === '' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(puzzleValue)) {
            return null;
        }

        const params = new URLSearchParams({
            competition: competitionValue,
            puzzle: puzzleValue,
            puzzlers: String(this.puzzlers()),
        });
        const date = this.field('finishedAt');

        if (date && date.value) {
            params.set('date', date.value);
        }

        return params.toString();
    }

    // --- the hint ---

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 300);
    }

    async refresh() {
        const query = this.query();

        if (query === this.lastQuery) {
            return;
        }

        this.lastQuery = query;
        this.request?.abort();
        this.request = null;

        if (query === null) {
            this.round = null;
            this.render();

            return;
        }

        const request = new AbortController();
        this.request = request;

        try {
            const response = await fetch(this.hintUrlValue + '?' + query, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            });

            if (!response.ok) {
                throw new Error('hint ' + response.status);
            }

            const json = await response.json();

            if (request.signal.aborted) {
                return;
            }

            this.round = json && json.round && json.round.minutesLimit ? json.round : null;
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            // Fail-open: no round - except for exactly what the page was rendered with
            this.round = query === this.renderedQuery ? this.renderedRound : null;
            this.lastQuery = null;
        }

        this.render();
    }

    // --- showing it ---

    render() {
        const speed = this.isSpeedMode();
        const seconds = this.typedSeconds();
        const round = speed ? this.round : null;
        const limitSeconds = round ? round.minutesLimit * 60 : null;
        const on = speed && this.hasSwitchTarget && this.switchTarget.checked;
        const asks = on || (round !== null && (seconds <= 0 || seconds > limitSeconds));

        if (this.hasQuestionTarget) {
            this.questionTarget.classList.toggle('d-none', !asks);
        }

        if (this.hasSwitchTarget) {
            this.switchTarget.disabled = !asks;
        }

        if (this.hasRoundLineTarget) {
            this.roundLineTarget.classList.toggle('d-none', round === null);

            if (round !== null && this.hasRoundTextTarget) {
                this.roundTextTarget.textContent = this.text('round', { '%round%': round.name, '%minutes%': String(round.minutesLimit) });
            }
        }

        if (this.hasNudgeTarget) {
            const suggests = !on && round !== null && seconds > limitSeconds;

            this.nudgeTarget.classList.toggle('d-none', !suggests);

            if (suggests) {
                this.nudgeTarget.textContent = this.text('nudge', {
                    '%time%': this.formatTime(seconds),
                    '%minutes%': String(round.minutesLimit),
                    '%round%': round.name,
                });
            }
        }

        const showDetails = on && asks;

        if (this.hasDetailsTarget) {
            this.detailsTarget.classList.toggle('d-none', !showDetails);
        }

        if (this.hasPiecesTarget) {
            this.piecesTarget.disabled = !showDetails;
        }

        this.renderPieces();
        this.renderTimeLabel(on);
    }

    renderPieces() {
        const total = this.piecesCount;

        if (this.hasPiecesTotalTarget) {
            this.piecesTotalTarget.textContent = total ? '/ ' + total : '';
        }

        if (!this.hasPiecesLeftTarget) {
            return;
        }

        const typed = this.hasPiecesTarget && /^\d{1,6}$/.test(this.piecesTarget.value.trim()) ? parseInt(this.piecesTarget.value.trim(), 10) : null;

        this.piecesLeftTarget.textContent = total && typed !== null && typed > 0 && typed < total
            ? '· ' + this.text('pieces_left', { '%count%': String(total - typed) })
            : '';
    }

    renderTimeLabel(on) {
        if (this.hasTimeLabelTarget) {
            this.timeLabelTarget.textContent = on ? this.text('time_total', {}) : this.text('time', {});
            this.timeLabelTarget.classList.toggle('required', !on);
        }

        if (this.hasTimeHelpTarget) {
            this.timeHelpTarget.classList.toggle('d-none', !on);
        }
    }

    text(key, parameters) {
        let message = this.textsValue[key] || '';

        Object.entries(parameters).forEach(([name, value]) => {
            message = message.split(name).join(value);
        });

        return message;
    }

    formatTime(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const rest = seconds % 60;

        return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
    }
}
