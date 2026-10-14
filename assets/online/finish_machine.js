/**
 * The finish rule (docs/features/online-competitions/README.md §5.7 "Rules"): per-frame hands-up signals in, finish
 * candidates out. Signals come from the detector worker (hands_up_signal.js), one per analysed frame, in capture
 * order: `push({captureMs, raised, score})` → the events that frame produced.
 *
 * The rules (binding, README §5.7) and how this machine reads them:
 *
 * - **A raise held for ≥ `holdMs` (0.5 s) becomes a candidate.** Held = the latest raised frame was captured at least
 *   holdMs after the first one (so it takes two raised frames at least). The candidate's moment `atMs` is the
 *   **capture time of the first raised frame** - neither the hold nor the inference delays it; `detectedAtMs` is the
 *   frame that completed the hold (when the phone knew).
 * - **The raised window** `windowFromMs`-`windowToMs` is where the page picks the sharpest photo (hands away from the
 *   puzzle): the candidate carries the window so far, `raise_end` the final one when the hands go down.
 * - **10 s cooldown** (`cooldownMs`, from the candidate's moment - `cooldownFrom`), then a later raise is a new
 *   candidate. A raise that is still going on is one raise - however long the hands stay up, it is one candidate.
 * - **Modes:** `off` - never a candidate (push() returns nothing, keeps nothing - the detector is not consulted); `shadow`
 *   - candidates flagged `shadow: true` (stored, never shown: the button is the official moment); `on` - candidates.
 *   An unknown mode is `off`: the button always works.
 * - **Time only from the frames' capture times** - no timers, no Date. A frame not later than the previous one is
 *   ignored (counted).
 *
 * Decided here - each a tunable, with its reason:
 *
 * - **Arming** (stream P2F): a raise that was already going on when the machine began to look is never a candidate -
 *   nobody saw the hands go up, so nobody knows when they did. The machine starts **unarmed** (construction, reset())
 *   and is unarmed again after every gap (the frame that ended the gap included) and after interrupt() (the page
 *   stopped looking - a Finished tap, a paused run); while unarmed a raised frame is ignored (counted: `unarmed`) and
 *   no raise starts. It **arms** once the hands were seen down for at least `armMs` (600 ms) of consecutive frames
 *   that are not raised, and at least `armFrames` (3) of them: a hold is 0.5 s, so "down" must be at least as sure as
 *   "up" - at 4-5 fps that is 4 frames, at 1 fps 3 frames over 2 s; a single frame between two raised ones (a
 *   flicker, the lowest point of a wave) never arms it. One rule covers GO (the page feeds frames from GO + 1.5 s, the
 *   hands may be up already), a hidden page shown again, a camera restarted or taken over, a stale frame answered
 *   after a reset and the set-up test right before GO. Once armed it stays armed through raises and cooldowns; a raise
 *   kept across a gap (`gapPolicy: 'bridge'`) keeps it armed - that raise began armed. `armMs: 0, armFrames: 0` = armed
 *   from the start (the bench's comparison - the P2I machine).
 * - **A gap** (two frames more than `maxGapMs` = 1.5 s apart): the pacing never goes below 1 fps (detector_pacing.js),
 *   so a longer silence is not the detector backing off but the page not looking - the tab hidden, the camera muted
 *   (iOS), the worker stalled. Nobody saw what the hands did meanwhile, so a raise that was **holding** restarts
 *   after the gap (`gapPolicy: 'restart'`; `'bridge'` keeps it - for the bench to compare): a candidate rests on frames
 *   that were seen, and the hold is only 0.5 s - restarting costs at most the gap, which the ±5 s burst covers. A raise
 *   that already **is** a candidate ends at a gap (`raise_end`, `endReason: 'gap'`). Every gap is reported (`gap`) -
 *   the review's flags.
 * - **A dropped frame inside a raise** (one frame not raised among raised ones): pose landmarks flicker - motion blur
 *   while the arms go up, a sleeve, a visibility dip - while lowering the hands takes several frames at 4-5 fps. Up to
 *   `maxDropFrames` (1) frames in a row are tolerated, if the raised frames around them are at most `maxDropMs`
 *   (0.7 s) apart (at 2 fps one dropped frame is a whole second unseen - then it is not a flicker). A tolerated drop
 *   stays inside the hold (the hold counts from the first raised frame); more ends the raise where the hands were last
 *   seen up (`raise_short`, or `raise_end` after a candidate).
 * - **A raise straddling the cooldown** (it began inside the cooldown, the hands are still up when it ends):
 *   `straddle: 'whole'` - it becomes a candidate once it is held past the cooldown's end, at its own first raised
 *   frame. The participant saw "Finish recorded", found a gap, fixed it within 10 s and raised again - holding the
 *   hands up through the cooldown's end is a deliberate "done again", and its first frame is the moment (the
 *   alternatives: `'from_end'` - the moment is the first raised frame after the cooldown, an artefact of the rule;
 *   `'suppress'` - never; the participant must lower and raise again). A raise that **ends** inside the cooldown is
 *   never a candidate (a celebration's second wave); it is reported once (`raise_suppressed`), so the page can say
 *   "raise again in a moment, or tap Finished".
 * - **`minScore`** (0): a frame counts as raised when the signal says `raised` and its score is at least this - the
 *   signal's own thresholds decide `raised`, minScore is the bench's knob to trade misses against false raises
 *   without running the models again. A signal without a score counts as 1.
 *
 * Events (each a plain object with `type`):
 * - `candidate` {id, atMs, detectedAtMs, heldMs, windowFromMs, windowToMs, upFrames, droppedFrames, score (mean),
 *   minScore, peakScore, straddled, mode, shadow, version}
 * - `raise_end` {candidateId, windowFromMs, windowToMs, upFrames, droppedFrames, endReason: 'lowered' | 'gap' |
 *   'interrupted'}
 * - `raise_short` {atMs, toMs, heldMs, upFrames, endReason} - a raise that ended before it was held long enough
 * - `raise_suppressed` {atMs, untilMs, reason: 'cooldown'} - once per raise held during the cooldown
 * - `gap` {fromMs, toMs, gapMs}
 * - `armed` {atMs, downFromMs} - the hands were seen down long enough: from here on a raise may become a candidate
 *
 * Pure - pinned by tests/online-finish-machine-harness.mjs; scored on footage by tools/online-bench/score.mjs.
 */

export const MODE_OFF = 'off';
export const MODE_SHADOW = 'shadow';
export const MODE_ON = 'on';
export const MODES = [MODE_OFF, MODE_SHADOW, MODE_ON];

// Bumped whenever the rules change what a candidate is - stored with every candidate, so the accuracy report can tell
// detector generations apart. 2 = arming (stream P2F): a raise going on when the machine began to look is never one
export const FINISH_MACHINE_VERSION = 2;

export const HOLD_MS = 500;
export const COOLDOWN_MS = 10_000;
export const MAX_GAP_MS = 1500;
export const MAX_DROP_FRAMES = 1;
export const MAX_DROP_MS = 700;
export const MIN_SCORE = 0;
export const ARM_MS = 600;
export const ARM_FRAMES = 3;

export const GAP_RESTART = 'restart';
export const GAP_BRIDGE = 'bridge';
export const STRADDLE_WHOLE = 'whole';
export const STRADDLE_FROM_END = 'from_end';
export const STRADDLE_SUPPRESS = 'suppress';
export const COOLDOWN_FROM_CANDIDATE = 'candidate';
export const COOLDOWN_FROM_RAISE_END = 'raise_end';

export const PHASE_IDLE = 'idle';
export const PHASE_HOLDING = 'holding';
export const PHASE_RAISED = 'raised';

export const DEFAULT_RULES = Object.freeze({
    mode: MODE_ON,
    holdMs: HOLD_MS,
    cooldownMs: COOLDOWN_MS,
    cooldownFrom: COOLDOWN_FROM_CANDIDATE,
    maxGapMs: MAX_GAP_MS,
    gapPolicy: GAP_RESTART,
    maxDropFrames: MAX_DROP_FRAMES,
    maxDropMs: MAX_DROP_MS,
    minScore: MIN_SCORE,
    straddle: STRADDLE_WHOLE,
    armMs: ARM_MS,
    armFrames: ARM_FRAMES,
});

/**
 * @param {Partial<typeof DEFAULT_RULES>} [options]
 */
export function createFinishMachine(options = {}) {
    return new FinishMachine(options);
}

/**
 * The rules with every unknown or invalid value replaced by its default (an unknown mode is `off`).
 */
export function resolveRules(options = {}) {
    const o = options ?? {};
    const number = (value, fallback, { min = 0, integer = false } = {}) => (
        Number.isFinite(value) && value >= min && (!integer || Number.isInteger(value)) ? value : fallback
    );
    const choice = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);

    return Object.freeze({
        mode: o.mode === undefined ? DEFAULT_RULES.mode : choice(o.mode, MODES, MODE_OFF),
        holdMs: number(o.holdMs, HOLD_MS),
        cooldownMs: number(o.cooldownMs, COOLDOWN_MS),
        cooldownFrom: choice(o.cooldownFrom, [COOLDOWN_FROM_CANDIDATE, COOLDOWN_FROM_RAISE_END], COOLDOWN_FROM_CANDIDATE),
        maxGapMs: number(o.maxGapMs, MAX_GAP_MS, { min: 1 }),
        gapPolicy: choice(o.gapPolicy, [GAP_RESTART, GAP_BRIDGE], GAP_RESTART),
        maxDropFrames: number(o.maxDropFrames, MAX_DROP_FRAMES, { integer: true }),
        maxDropMs: number(o.maxDropMs, MAX_DROP_MS),
        minScore: number(o.minScore, MIN_SCORE),
        straddle: choice(o.straddle, [STRADDLE_WHOLE, STRADDLE_FROM_END, STRADDLE_SUPPRESS], STRADDLE_WHOLE),
        armMs: number(o.armMs, ARM_MS),
        armFrames: number(o.armFrames, ARM_FRAMES, { integer: true }),
    });
}

export class FinishMachine {
    constructor(options = {}) {
        this.rules = resolveRules(options);
        this.reset();
    }

    /**
     * Back to the start: no raise, no cooldown, no candidates, unarmed (a new round, the practice run again).
     */
    reset() {
        this.lastMs = null;
        this.raise = null;
        this.cooldownUntilMs = null;
        this.lastCandidate = null;
        this.nextId = 1;
        this.disarm();
        this.counts = { frames: 0, upFrames: 0, gaps: 0, candidates: 0, ignored: 0, unarmed: 0 };
    }

    /**
     * The page stopped looking for a while (a Finished tap, the round's detector paused): a raise going on ends
     * (`endReason: 'interrupted'`) and the machine is unarmed until the hands are seen down again - the cooldown, the
     * candidates and their ids stay. Returns the events (a `raise_end` closes the candidate's photo window).
     *
     * @returns {object[]}
     */
    interrupt() {
        if (this.rules.mode === MODE_OFF) {
            return [];
        }

        const events = [];

        if (this.raise !== null) {
            this.endRaise(events, 'interrupted');
        }

        this.disarm();

        return events;
    }

    /**
     * One frame's signal.
     *
     * @param {{captureMs?: number, captureTimeMs?: number, raised: boolean, score?: number}} signal
     * @returns {object[]} the events this frame produced, in order
     */
    push(signal) {
        if (this.rules.mode === MODE_OFF) {
            return [];
        }

        const t = captureOf(signal);

        if (!Number.isFinite(t) || (this.lastMs !== null && t <= this.lastMs)) {
            this.counts.ignored += 1;

            return [];
        }

        const events = [];

        if (this.lastMs !== null && t - this.lastMs > this.rules.maxGapMs) {
            events.push({ type: 'gap', fromMs: this.lastMs, toMs: t, gapMs: t - this.lastMs });
            this.counts.gaps += 1;

            if (this.raise !== null && (this.raise.candidate !== null || this.rules.gapPolicy === GAP_RESTART)) {
                this.endRaise(events, 'gap');
            }

            // Nobody saw the hands meanwhile: unarmed - this frame included (a raise kept across the gap began armed)
            if (this.raise === null) {
                this.disarm();
            }
        }

        this.lastMs = t;
        this.counts.frames += 1;
        const raised = signal.raised === true && scoreOf(signal) >= this.rules.minScore;

        if (this.raise === null && !this.armed) {
            this.arming(events, t, raised);

            return events;
        }

        if (raised) {
            this.counts.upFrames += 1;
            this.up(events, t, scoreOf(signal));
        } else {
            this.down(events, t);
        }

        return events;
    }

    /**
     * Where the machine stands - for the page ("hold your hands up…", "raise again in 3 s") and the bench.
     */
    state() {
        const raise = this.raise;
        const inCooldown = this.cooldownUntilMs !== null && this.lastMs !== null && this.lastMs < this.cooldownUntilMs;

        return {
            mode: this.rules.mode,
            version: FINISH_MACHINE_VERSION,
            armed: this.armed,
            // The hands seen down since (null: armed, or not seen down yet)
            armingFromMs: this.armRun?.fromMs ?? null,
            phase: raise === null ? PHASE_IDLE : (raise.candidate !== null ? PHASE_RAISED : PHASE_HOLDING),
            holdFromMs: raise?.fromMs ?? null,
            heldMs: raise === null ? 0 : raise.lastUpMs - raise.fromMs,
            suppressed: raise?.suppressed ?? false,
            cooldownUntilMs: inCooldown ? this.cooldownUntilMs : null,
            lastFrameMs: this.lastMs,
            lastCandidate: this.lastCandidate === null ? null : { ...this.lastCandidate },
            ...this.counts,
        };
    }

    // ---- internals

    /**
     * Unarmed: frames down count towards arming (armMs and armFrames of them in a row), a raised one starts the count
     * again and is ignored - no raise starts.
     */
    arming(events, t, raised) {
        if (raised) {
            this.counts.unarmed += 1;
            this.armRun = null;

            return;
        }

        if (this.armRun === null) {
            this.armRun = { fromMs: t, frames: 0 };
        }

        this.armRun.frames += 1;

        if (t - this.armRun.fromMs >= this.rules.armMs && this.armRun.frames >= this.rules.armFrames) {
            events.push({ type: 'armed', atMs: t, downFromMs: this.armRun.fromMs });
            this.armed = true;
            this.armRun = null;
        }
    }

    /**
     * Unarmed until the hands are seen down again - unless the rules arm at once (armMs and armFrames 0).
     */
    disarm() {
        this.armed = this.rules.armMs <= 0 && this.rules.armFrames <= 0;
        this.armRun = null;
    }

    up(events, t, score) {
        let raise = this.raise;

        if (raise !== null && raise.downRun > 0) {
            if (t - raise.lastUpMs > this.rules.maxDropMs) {
                // Down longer than a flicker: that raise ended where the hands were last seen up
                this.endRaise(events, 'lowered');
                raise = null;
            } else {
                raise.dropped += raise.downRun;
                raise.downRun = 0;
            }
        }

        if (raise === null) {
            raise = this.startRaise(t);
        }

        raise.lastUpMs = t;
        raise.upFrames += 1;
        raise.scoreSum += score;
        raise.minScore = Math.min(raise.minScore, score);
        raise.peakScore = Math.max(raise.peakScore, score);

        if (raise.cooldownUntilMs !== null && raise.firstUpAfterCooldownMs === null && t >= raise.cooldownUntilMs) {
            raise.firstUpAfterCooldownMs = t;
        }

        if (raise.candidate === null && !raise.blocked) {
            this.tryCandidate(events, raise, t);
        }
    }

    down(events, t) {
        const raise = this.raise;

        if (raise === null) {
            return;
        }

        raise.downRun += 1;

        if (raise.downRun > this.rules.maxDropFrames || t - raise.lastUpMs > this.rules.maxDropMs) {
            this.endRaise(events, 'lowered');
        }
    }

    startRaise(t) {
        const inCooldown = this.cooldownUntilMs !== null && t < this.cooldownUntilMs;

        this.raise = {
            fromMs: t,
            lastUpMs: t,
            upFrames: 0,
            downRun: 0,
            dropped: 0,
            scoreSum: 0,
            minScore: Infinity,
            peakScore: 0,
            // The cooldown this raise began in (null: none)
            cooldownUntilMs: inCooldown ? this.cooldownUntilMs : null,
            firstUpAfterCooldownMs: null,
            candidate: null,
            suppressed: false,
            blocked: false,
        };

        return this.raise;
    }

    tryCandidate(events, raise, t) {
        const rules = this.rules;
        let atMs = raise.fromMs;

        if (raise.cooldownUntilMs !== null) {
            if (t < raise.cooldownUntilMs) {
                if (t - raise.fromMs >= rules.holdMs) {
                    this.suppress(events, raise);
                }

                return;
            }

            if (rules.straddle === STRADDLE_SUPPRESS) {
                this.suppress(events, raise);
                raise.blocked = true;

                return;
            }

            if (rules.straddle === STRADDLE_FROM_END) {
                atMs = raise.firstUpAfterCooldownMs;
            }
        }

        if (t - atMs < rules.holdMs) {
            return;
        }

        const candidate = {
            type: 'candidate',
            id: this.nextId++,
            atMs,
            detectedAtMs: t,
            heldMs: t - atMs,
            windowFromMs: atMs,
            windowToMs: t,
            upFrames: raise.upFrames,
            droppedFrames: raise.dropped,
            score: round3(raise.scoreSum / raise.upFrames),
            minScore: round3(raise.minScore),
            peakScore: round3(raise.peakScore),
            straddled: raise.cooldownUntilMs !== null,
            mode: rules.mode,
            shadow: rules.mode === MODE_SHADOW,
            version: FINISH_MACHINE_VERSION,
        };

        raise.candidate = candidate;
        this.lastCandidate = candidate;
        this.counts.candidates += 1;
        // From the candidate's moment; with `raise_end` the cooldown starts when this raise ends (one raise is one
        // candidate meanwhile anyway)
        this.cooldownUntilMs = rules.cooldownFrom === COOLDOWN_FROM_CANDIDATE ? atMs + rules.cooldownMs : null;
        events.push({ ...candidate });
    }

    suppress(events, raise) {
        if (raise.suppressed) {
            return;
        }

        raise.suppressed = true;
        events.push({ type: 'raise_suppressed', atMs: raise.fromMs, untilMs: raise.cooldownUntilMs, reason: 'cooldown' });
    }

    endRaise(events, reason) {
        const raise = this.raise;
        this.raise = null;

        if (raise === null) {
            return;
        }

        if (raise.candidate !== null) {
            events.push({
                type: 'raise_end',
                candidateId: raise.candidate.id,
                windowFromMs: raise.candidate.atMs,
                windowToMs: raise.lastUpMs,
                upFrames: raise.upFrames,
                droppedFrames: raise.dropped,
                endReason: reason,
            });

            if (this.rules.cooldownFrom === COOLDOWN_FROM_RAISE_END) {
                this.cooldownUntilMs = raise.lastUpMs + this.rules.cooldownMs;
            }

            return;
        }

        if (!raise.suppressed) {
            events.push({
                type: 'raise_short',
                atMs: raise.fromMs,
                toMs: raise.lastUpMs,
                heldMs: raise.lastUpMs - raise.fromMs,
                upFrames: raise.upFrames,
                endReason: reason,
            });
        }
    }
}

function captureOf(signal) {
    if (signal === null || typeof signal !== 'object') {
        return NaN;
    }

    return Number.isFinite(signal.captureMs) ? signal.captureMs : signal.captureTimeMs;
}

function scoreOf(signal) {
    return Number.isFinite(signal?.score) ? signal.score : 1;
}

function round3(value) {
    return Number.isFinite(value) ? Math.round(value * 1000) / 1000 : 0;
}
