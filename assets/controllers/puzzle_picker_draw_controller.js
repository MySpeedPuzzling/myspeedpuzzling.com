import { Controller } from '@hotwired/stimulus';

/**
 * The "drumroll" of the puzzle picker: before the card shows, the thumbnails of the drawn
 * puzzles cycle slot-machine style - fast at first, slowing down - and stop on the chosen one
 * with a little pop. Pure presentation: the HTML already contains the result (no-JS and tests see
 * the card right away); this only hides it for a few seconds.
 *
 * Plays once per draw URL (sessionStorage, so back/forward and reloads go straight to the card),
 * never with prefers-reduced-motion, and a tap anywhere on the stage skips it.
 *
 * Values: duration (ms, default 3800), key (the draw seed), play (server decides: signed-in
 * draws only), captions (3 strings, switched at 0 / 35 / 70 % of the duration), done (final caption).
 */
export default class extends Controller {
    static targets = ['stage', 'result', 'image', 'name', 'caption', 'candidates'];
    static values = {
        duration: { type: Number, default: 3800 },
        key: String,
        play: Boolean,
        captions: Array,
        done: String,
    };

    connect() {
        if (!this.playValue || !this.hasStageTarget || !this.hasResultTarget || !this.hasCandidatesTarget) {
            return;
        }

        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const storageKey = `puzzle-picker-drawn:${this.keyValue}`;

        try {
            if (window.sessionStorage.getItem(storageKey)) {
                return;
            }

            window.sessionStorage.setItem(storageKey, '1');
        } catch (e) {
            // Storage unavailable (private mode): play anyway
        }

        let candidates = [];

        try {
            candidates = JSON.parse(this.candidatesTarget.textContent || '[]');
        } catch (e) {
            return;
        }

        if (candidates.length < 2) {
            return;
        }

        this.candidates = candidates;
        this.finished = false;
        this.index = 0;
        this.resultTarget.classList.add('d-none');
        this.stageTarget.classList.remove('d-none');
        this.show(0);
        this.setCaption(0);
        this.startedAt = performance.now();
        this.schedule(70);
    }

    disconnect() {
        window.clearTimeout(this.timer);
    }

    schedule(delay) {
        this.timer = window.setTimeout(() => this.tick(delay), delay);
    }

    tick(previousDelay) {
        if (this.finished) {
            return;
        }

        const elapsed = performance.now() - this.startedAt;
        const progress = Math.min(1, elapsed / this.durationValue);
        const nextDelay = Math.round(previousDelay * 1.17);

        this.setCaption(progress < 0.35 ? 0 : (progress < 0.7 ? 1 : 2));

        if (elapsed + nextDelay >= this.durationValue) {
            this.finish();
            return;
        }

        this.index = (this.index + 1) % this.candidates.length;
        this.show(this.index);
        this.pulse();
        this.schedule(nextDelay);
    }

    show(index) {
        const candidate = this.candidates[index];

        if (this.hasImageTarget) {
            this.imageTarget.src = candidate.image;
            this.imageTarget.alt = candidate.name;
        }

        if (this.hasNameTarget) {
            this.nameTarget.textContent = candidate.name;
        }
    }

    pulse() {
        this.stageTarget.classList.add('is-ticking');
        window.setTimeout(() => this.stageTarget.classList.remove('is-ticking'), 90);
    }

    setCaption(step) {
        if (this.hasCaptionTarget && this.captionsValue[step] !== undefined) {
            this.captionTarget.textContent = this.captionsValue[step];
        }
    }

    finish() {
        if (this.finished) {
            return;
        }

        this.finished = true;
        window.clearTimeout(this.timer);
        this.show(0);
        this.stageTarget.classList.add('is-done');

        if (this.hasCaptionTarget && this.doneValue) {
            this.captionTarget.textContent = this.doneValue;
        }

        window.setTimeout(() => this.revealResult(), 800);
    }

    skip() {
        this.finished = true;
        window.clearTimeout(this.timer);
        this.revealResult();
    }

    revealResult() {
        this.stageTarget.classList.add('d-none');
        this.resultTarget.classList.remove('d-none');
        this.resultTarget.classList.add('puzzle-picker-result-in');
    }
}
