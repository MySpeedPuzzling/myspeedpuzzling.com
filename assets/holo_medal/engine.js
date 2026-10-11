/**
 * The tilt and the moving light of a Platinum or Diamond medal (the achievement detail sheet,
 * docs/features/xp-levels/ux-polish/design-spec.md §2). Written for MySpeedPuzzling from scratch.
 *
 * A mouse or pen pointer tilts the medal (never a finger: a drag must not fight the sheet's scroll, design-spec §8.3) - up to `maxTilt` degrees - and moves the
 * light; letting go eases it back. On open one light sweep crosses the medal (INTRO_MS, once). Per frame only CSS
 * custom properties on the medal element change (--rx --ry tilt, --lx --ly light, --bgx --bgy foil bands, --act how
 * lit the foil is); every layer is masked and blended by CSS (assets/styles/_achievement-medal.scss).
 *
 * Springs, not tweens: the loop runs while anything moves and stops when everything settled (no rAF while idle).
 * Reduced motion: never started (the sheet keeps a still foil at the resting light). destroy() releases everything.
 */

const INTRO_MS = 1400;
const MAX_STEP_S = 0.05;
const SUBSTEP_S = 1 / 240;
const REST = { x: 0.32, y: 0.24 }; // the house light: top left

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function easeInOut(t) {
    return t < 0.5 ? 4 * t * t * t : 1 - ((-2 * t + 2) ** 3) / 2;
}

class Spring {
    constructor(value, stiffness, damping) {
        this.value = value;
        this.target = value;
        this.velocity = 0;
        this.omega = stiffness;
        this.zeta = damping;
    }

    /** @returns {boolean} still moving */
    step(dt) {
        const steps = Math.ceil(dt / SUBSTEP_S);
        const h = dt / steps;

        for (let i = 0; i < steps; i++) {
            this.velocity += (this.omega * this.omega * (this.target - this.value) - 2 * this.zeta * this.omega * this.velocity) * h;
            this.value += this.velocity * h;
        }

        if (Math.abs(this.target - this.value) < 5e-4 && Math.abs(this.velocity) < 5e-3) {
            this.value = this.target;
            this.velocity = 0;
            return false;
        }

        return true;
    }
}

export class HoloMedalEngine {
    /**
     * @param {HTMLElement} medal  the element carrying the custom properties
     * @param {HTMLElement} surface the element listening to the pointer (the medal button)
     */
    constructor(medal, surface) {
        this.medal = medal;
        this.surface = surface;
        this.maxTilt = 0;
        this.x = new Spring(0.5, 13, 0.8);
        this.y = new Spring(0.5, 13, 0.8);
        this.act = new Spring(0, 7, 1);
        this.introStart = null;
        this.introPending = false;
        this.frame = null;
        this.lastTime = 0;
        this.destroyed = false;

        this.surface.addEventListener('pointermove', this.onPointer);
        this.surface.addEventListener('pointerdown', this.onPointer);
        this.surface.addEventListener('pointerleave', this.onRelease);
        this.surface.addEventListener('pointercancel', this.onRelease);
        this.surface.addEventListener('pointerup', this.onPointerUp);
        this.write();
    }

    /** 0 = still (Bronze to Gold, or an unearned tier): the light rests, nothing listens to the pointer */
    setMaxTilt(degrees) {
        this.maxTilt = degrees;

        if (degrees === 0) {
            this.introStart = null;
            this.x.target = 0.5;
            this.y.target = 0.5;
            this.act.target = 0;
            this.wake();
        }
    }

    playIntro() {
        if (this.maxTilt === 0 || this.destroyed) {
            return;
        }

        this.introStart = null;
        this.introPending = true;
        this.wake();
    }

    destroy() {
        this.destroyed = true;
        this.surface.removeEventListener('pointermove', this.onPointer);
        this.surface.removeEventListener('pointerdown', this.onPointer);
        this.surface.removeEventListener('pointerleave', this.onRelease);
        this.surface.removeEventListener('pointercancel', this.onRelease);
        this.surface.removeEventListener('pointerup', this.onPointerUp);

        if (this.frame !== null) {
            cancelAnimationFrame(this.frame);
            this.frame = null;
        }
    }

    onPointer = (event) => {
        if (this.maxTilt === 0 || event.pointerType === 'touch') {
            return;
        }

        const rect = this.surface.getBoundingClientRect();

        if (rect.width === 0 || rect.height === 0) {
            return;
        }

        this.introPending = false;
        this.introStart = null;
        this.x.target = clamp((event.clientX - rect.left) / rect.width, 0, 1);
        this.y.target = clamp((event.clientY - rect.top) / rect.height, 0, 1);
        this.act.target = 1;
        this.wake();
    };

    onPointerUp = (event) => {
        // A pen leaves the screen: the medal settles; a mouse button up keeps following the pointer
        if (event.pointerType === 'pen') {
            this.onRelease();
        }
    };

    onRelease = () => {
        this.x.target = 0.5;
        this.y.target = 0.5;
        this.act.target = 0;
        this.wake();
    };

    wake() {
        if (this.frame !== null || this.destroyed) {
            return;
        }

        this.lastTime = 0;
        this.frame = requestAnimationFrame(this.tick);
    }

    tick = (time) => {
        const dt = this.lastTime === 0 ? 1 / 60 : Math.min((time - this.lastTime) / 1000, MAX_STEP_S);
        this.lastTime = time;
        let moving = false;

        if (this.introPending) {
            this.introPending = false;
            this.introStart = time;
        }

        if (this.introStart !== null) {
            const p = clamp((time - this.introStart) / INTRO_MS, 0, 1);
            const e = easeInOut(p);
            this.x.target = 0.15 + 0.7 * e;
            this.y.target = 0.28 + 0.36 * e;
            this.act.target = Math.sin(p * Math.PI) * 0.9;

            if (p >= 1) {
                this.introStart = null;
                this.x.target = 0.5;
                this.y.target = 0.5;
                this.act.target = 0;
            }

            moving = true;
        }

        // Every spring steps every frame (no short-circuit)
        const movedX = this.x.step(dt);
        const movedY = this.y.step(dt);
        const movedAct = this.act.step(dt);
        moving = moving || movedX || movedY || movedAct;
        this.write();

        this.frame = moving && !this.destroyed ? requestAnimationFrame(this.tick) : null;
    };

    write() {
        const style = this.medal.style;
        const act = clamp(this.act.value, 0, 1);
        const dx = this.x.value - 0.5;
        const dy = this.y.value - 0.5;
        const lightX = REST.x + (this.x.value - REST.x) * act;
        const lightY = REST.y + (this.y.value - REST.y) * act;

        style.setProperty('--rx', `${(-dy * 2 * this.maxTilt * act).toFixed(2)}deg`);
        style.setProperty('--ry', `${(dx * 2 * this.maxTilt * act).toFixed(2)}deg`);
        style.setProperty('--lx', `${(lightX * 100).toFixed(2)}%`);
        style.setProperty('--ly', `${(lightY * 100).toFixed(2)}%`);
        // The bands slide faster than the pointer moves (diffraction)
        style.setProperty('--bgx', `${(50 + dx * 90 * act).toFixed(2)}%`);
        style.setProperty('--bgy', `${(50 + dy * 70 * act).toFixed(2)}%`);
        style.setProperty('--act', act.toFixed(3));
    }
}
