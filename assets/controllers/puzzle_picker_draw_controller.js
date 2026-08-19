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
 * Values: duration (ms, default 5000), key (the draw seed), play (server decides: signed-in
 * draws only), captions (3 strings, switched at 0 / 35 / 70 % of the duration), done (final caption).
 */
export default class extends Controller {
    static targets = ['stage', 'result', 'image', 'name', 'caption', 'candidates'];
    static values = {
        duration: { type: Number, default: 5000 },
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
        this.pulse(progress);
        this.schedule(nextDelay);
    }

    show(index) {
        const candidate = this.candidates[index];

        if (this.hasImageTarget) {
            // Crossfade: dip the opacity, swap the source, fade back in (CSS transition)
            this.imageTarget.classList.add('is-swapping');
            this.imageTarget.src = candidate.image;
            this.imageTarget.alt = candidate.name;
            window.setTimeout(() => this.imageTarget.classList.remove('is-swapping'), 70);
        }

        if (this.hasNameTarget) {
            this.nameTarget.textContent = candidate.name;
        }
    }

    /**
     * One tick of motion: the frame nudges and tilts to alternating sides, and a glow ring
     * builds up with the progress (CSS variable), so the slowdown is felt, not just seen.
     */
    pulse(progress) {
        this.side = this.side === 'left' ? 'right' : 'left';
        this.stageTarget.style.setProperty('--picker-progress', String(progress));
        this.stageTarget.classList.remove('is-ticking-left', 'is-ticking-right');
        this.stageTarget.classList.add(`is-ticking-${this.side}`);
        window.setTimeout(() => this.stageTarget.classList.remove('is-ticking-left', 'is-ticking-right'), 110);
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
        this.stageTarget.style.setProperty('--picker-progress', '1');
        this.stageTarget.classList.add('is-done');
        this.confetti();

        if (this.hasCaptionTarget && this.doneValue) {
            this.captionTarget.textContent = this.doneValue;
        }

        window.setTimeout(() => this.revealResult(), 900);
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

    /**
     * A modest confetti burst from the frame when the draw lands - a few dozen pieces in the
     * site palette, ~1.8 s, on a canvas laid over the controller element so it keeps raining
     * onto the card once the stage is gone. Dependency-free, pointer-events: none.
     */
    confetti() {
        const frame = this.stageTarget.querySelector('.puzzle-picker-draw-frame');

        if (!frame || typeof document.createElement('canvas').getContext !== 'function') {
            return;
        }

        const host = this.element;
        const hostBox = host.getBoundingClientRect();
        const frameBox = frame.getBoundingClientRect();
        const padTop = 80;
        const width = hostBox.width;
        const height = hostBox.height + padTop;
        const ratio = window.devicePixelRatio || 1;
        const canvas = document.createElement('canvas');

        canvas.className = 'puzzle-picker-confetti';
        canvas.style.top = `-${padTop}px`;
        canvas.style.height = `${height}px`;
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
        host.classList.add('puzzle-picker-confetti-host');
        host.appendChild(canvas);

        const context = canvas.getContext('2d');
        context.scale(ratio, ratio);

        const origin = {
            x: frameBox.left - hostBox.left + frameBox.width / 2,
            y: frameBox.top - hostBox.top + frameBox.height / 2 + padTop,
        };
        const colors = ['#fe696a', '#ffb648', '#4e9f7f', '#5a8dee', '#f8d5d5', '#7e57c2'];
        const pieces = Array.from({ length: 70 }, () => {
            const angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 0.9;
            const speed = 5 + Math.random() * 6;

            return {
                x: origin.x,
                y: origin.y,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed,
                size: 5 + Math.random() * 5,
                rotation: Math.random() * Math.PI,
                spin: (Math.random() - 0.5) * 0.3,
                color: colors[Math.floor(Math.random() * colors.length)],
                round: Math.random() < 0.3,
                life: 1,
            };
        });
        const duration = 1800;
        const started = performance.now();

        const step = (now) => {
            const elapsed = now - started;
            context.clearRect(0, 0, width, height);

            pieces.forEach((piece) => {
                piece.vy += 0.22;
                piece.vx *= 0.99;
                piece.x += piece.vx;
                piece.y += piece.vy;
                piece.rotation += piece.spin;
                piece.life = Math.max(0, 1 - Math.max(0, elapsed - 900) / 900);

                context.save();
                context.globalAlpha = piece.life;
                context.fillStyle = piece.color;
                context.translate(piece.x, piece.y);
                context.rotate(piece.rotation);

                if (piece.round) {
                    context.beginPath();
                    context.arc(0, 0, piece.size / 2, 0, Math.PI * 2);
                    context.fill();
                } else {
                    context.fillRect(-piece.size / 2, -piece.size / 4, piece.size, piece.size / 2);
                }

                context.restore();
            });

            if (elapsed < duration) {
                window.requestAnimationFrame(step);
            } else {
                canvas.remove();
            }
        };

        window.requestAnimationFrame(step);
    }
}
