import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * PPM (Pieces Per Minute) Validator Controller
 *
 * Validates solving time before form submission and shows a warning modal
 * if the PPM seems suspicious (too fast or too slow).
 */
export default class extends Controller {
    static targets = [
        'timeHours',
        'timeMinutes',
        'timeSeconds',
        'puzzle',
        'puzzlePiecesCount',
        'groupPlayers',
        'modal',
        'warningMessage',
        'timeDisplay',
        'modeInput',
    ];

    static values = {
        activePuzzlePieces: { type: Number, default: 0 },
        tooFastThreshold: { type: Number, default: 40 },
        tooSlowThreshold: { type: Number, default: 1 },
        tooSlowMaxPieces: { type: Number, default: 1100 },
        warningTooFast: { type: String, default: 'Your pace seems very fast. Please double-check your time and confirm it is correct.' },
        warningTooSlow: { type: String, default: 'Your solving time seems quite long. Please verify your time is correct.' },
        labelHours: { type: String, default: 'hours' },
        labelMinutes: { type: String, default: 'minutes' },
        labelSeconds: { type: String, default: 'seconds' },
        confirmed: { type: Boolean, default: false },
    };

    uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    connect() {
        this.element.addEventListener('submit', this.handleSubmit.bind(this));

        // Listen for puzzle pieces count updates from autocomplete
        this.element.addEventListener('ppm:piecesCountUpdated', this.handlePiecesCountUpdate.bind(this));
    }

    disconnect() {
        this.element.removeEventListener('submit', this.handleSubmit.bind(this));
        this.element.removeEventListener('ppm:piecesCountUpdated', this.handlePiecesCountUpdate.bind(this));
    }

    handlePiecesCountUpdate(event) {
        this.selectedPiecesCount = event.detail.piecesCount;
    }

    handleSubmit(event) {
        // Only validate in Speed Puzzling mode
        if (!this.isSpeedPuzzlingMode()) {
            return;
        }

        // If already confirmed, allow submission
        if (this.confirmedValue) {
            this.confirmedValue = false;
            return;
        }

        const piecesCount = this.getPiecesCount();
        const totalSeconds = this.getTotalSeconds();

        // Skip validation if we don't have valid data
        if (!piecesCount || !totalSeconds || piecesCount === 0 || totalSeconds === 0) {
            return;
        }

        const puzzlersCount = this.getPuzzlersCount();
        const ppm = this.calculatePpm(piecesCount, totalSeconds, puzzlersCount);

        const warnings = this.validatePpm(ppm, piecesCount);

        if (warnings.length > 0) {
            event.preventDefault();
            this.showWarningModal(warnings, ppm);
        }
    }

    isSpeedPuzzlingMode() {
        if (!this.hasModeInputTarget) {
            // If no mode input, assume speed puzzling (edit form case)
            return true;
        }

        return this.modeInputTarget.value === 'speed_puzzling';
    }

    getPiecesCount() {
        // Priority 1: New puzzle pieces count input (when creating new puzzle)
        // Check this first because if user is creating a new puzzle, this is the source of truth
        if (this.hasPuzzlePiecesCountTarget && this.puzzlePiecesCountTarget.value) {
            const value = parseInt(this.puzzlePiecesCountTarget.value, 10);
            if (!isNaN(value) && value > 0) {
                return value;
            }
        }

        // Priority 2: Selected puzzle from autocomplete (stored by event)
        // Only use if it's a valid positive number (not null/undefined)
        if (typeof this.selectedPiecesCount === 'number' && this.selectedPiecesCount > 0) {
            return this.selectedPiecesCount;
        }

        // Priority 3: Active puzzle passed from server (edit form case)
        if (this.activePuzzlePiecesValue > 0) {
            return this.activePuzzlePiecesValue;
        }

        return 0;
    }

    getTotalSeconds() {
        const hours = this.hasTimeHoursTarget ? parseInt(this.timeHoursTarget.value, 10) || 0 : 0;
        const minutes = this.hasTimeMinutesTarget ? parseInt(this.timeMinutesTarget.value, 10) || 0 : 0;
        const seconds = this.hasTimeSecondsTarget ? parseInt(this.timeSecondsTarget.value, 10) || 0 : 0;

        return (hours * 3600) + (minutes * 60) + seconds;
    }

    getPuzzlersCount() {
        if (!this.hasGroupPlayersTarget) {
            return 1;
        }

        // Count non-empty group player inputs
        const inputs = this.groupPlayersTarget.querySelectorAll('input[name="group_players[]"]');
        let count = 1; // Include the main player

        inputs.forEach(input => {
            if (input.value.trim() !== '') {
                count++;
            }
        });

        return count;
    }

    calculatePpm(pieces, seconds, puzzlersCount = 1) {
        if (!seconds || !pieces || seconds === 0 || pieces === 0) {
            return 0;
        }

        const minutes = seconds / 60;
        return pieces / minutes / Math.max(1, puzzlersCount);
    }

    validatePpm(ppm, piecesCount) {
        const warnings = [];

        // Too fast warning
        if (ppm > this.tooFastThresholdValue) {
            warnings.push({
                type: 'too_fast',
                message: this.warningTooFastValue,
                ppm: ppm.toFixed(1)
            });
        }

        // Too slow warning (only for smaller puzzles)
        if (ppm > 0 && ppm < this.tooSlowThresholdValue && piecesCount <= this.tooSlowMaxPiecesValue) {
            warnings.push({
                type: 'too_slow',
                message: this.warningTooSlowValue,
                ppm: ppm.toFixed(2)
            });
        }

        return warnings;
    }

    showWarningModal(warnings, ppm) {
        if (!this.hasModalTarget || !this.hasWarningMessageTarget) {
            // If modal not available, just submit
            this.confirmedValue = true;
            this.element.requestSubmit();
            return;
        }

        // Display the entered time
        if (this.hasTimeDisplayTarget) {
            const hours = this.hasTimeHoursTarget ? parseInt(this.timeHoursTarget.value, 10) || 0 : 0;
            const minutes = this.hasTimeMinutesTarget ? parseInt(this.timeMinutesTarget.value, 10) || 0 : 0;
            const seconds = this.hasTimeSecondsTarget ? parseInt(this.timeSecondsTarget.value, 10) || 0 : 0;

            this.timeDisplayTarget.innerHTML = this.buildTimeDisplayHtml(hours, minutes, seconds);
        }

        // Build warning message
        const messages = warnings.map(w => w.message);
        this.warningMessageTarget.innerHTML = messages.join('<br><br>');

        // Show the modal
        const modal = Modal.getOrCreateInstance(this.modalTarget);
        modal.show();
    }

    buildTimeDisplayHtml(hours, minutes, seconds) {
        const parts = [];

        if (hours > 0) {
            parts.push(`
                <div class="text-center">
                    <div class="display-5 fw-bold text-primary">${hours}</div>
                    <div class="small text-muted">${this.labelHoursValue}</div>
                </div>
            `);
            parts.push('<div class="display-5 text-muted mx-1 align-self-start pt-1">:</div>');
        }

        parts.push(`
            <div class="text-center">
                <div class="display-5 fw-bold text-primary">${minutes}</div>
                <div class="small text-muted">${this.labelMinutesValue}</div>
            </div>
        `);

        parts.push('<div class="display-5 text-muted mx-1 align-self-start pt-1">:</div>');

        parts.push(`
            <div class="text-center">
                <div class="display-5 fw-bold text-primary">${seconds}</div>
                <div class="small text-muted">${this.labelSecondsValue}</div>
            </div>
        `);

        return `
            <div class="d-flex justify-content-center align-items-start">
                ${parts.join('')}
            </div>
        `;
    }

    confirmSubmit() {
        // Close modal
        if (this.hasModalTarget) {
            const modal = Modal.getInstance(this.modalTarget);
            if (modal) {
                modal.hide();
            }
        }

        // Reset submit prevention so the new submit isn't blocked
        this.resetSubmitPrevention();

        // Mark as confirmed and submit
        this.confirmedValue = true;
        this.element.requestSubmit();
    }

    cancelSubmit() {
        // Close the modal
        if (this.hasModalTarget) {
            const modal = Modal.getInstance(this.modalTarget);
            if (modal) {
                modal.hide();
            }
        }

        // Reset submit prevention controller so user can submit again
        this.resetSubmitPrevention();
    }

    resetSubmitPrevention() {
        // Find submit button and re-enable it
        const submitButton = this.element.querySelector('[data-submit-prevention-target="submit"]');
        if (submitButton) {
            submitButton.removeAttribute('disabled');
            submitButton.classList.remove('is-loading');
        }

        // Reset the submit prevention controller's state via Stimulus
        const submitPreventionController = this.application.getControllerForElementAndIdentifier(
            this.element,
            'submit-prevention'
        );
        if (submitPreventionController) {
            submitPreventionController.isSubmittingValue = false;
        }
    }
}
