/**
 * The participant page's sounds (docs/features/online-competitions/README.md §5.5): the last three seconds of the
 * countdown, GO, the time's-up alarm and the chime of a detected finish - WebAudio, unlocked by the "I'm ready" tap (browsers play nothing before a
 * tap), with vibration as a bonus where it exists (never on iOS) - through signal() of assets/haptics.js, the one place
 * that talks to the vibration API (navigation GEST-10): these alerts keep their own patterns and play wherever the
 * browser has the API, outside the haptic rules and the Vibration setting.
 *
 * The countdown is scheduled on the audio clock from the server-time distance to each beep (countdownPlan()), so a
 * beep lands on its second even while the page renders slowly; a new start (a cancel, a reschedule) cancels the plan.
 * The phones' own beeps are the timing reference - a spoken countdown over the call arrives late (README §5.12).
 *
 * No DOM at import: the AudioContext constructor and the vibration are injected - pinned by tests/online-devices-harness.mjs.
 */
import { signal } from '../haptics.js';

// The beeps before GO, in ms before the start
export const COUNTDOWN_BEEPS_MS = [3000, 2000, 1000];
// How far ahead beeps are put on the audio clock
export const LOOKAHEAD_MS = 4000;
// A beep this late is still played (a frame or two)
export const LATE_TOLERANCE_MS = 120;

export const TONES = {
    tick: { frequency: 880, durationMs: 150, gain: 0.35 },
    go: { frequency: 1320, durationMs: 600, gain: 0.45 },
    alarm: { frequency: 1046, durationMs: 220, gain: 0.45 },
    chime: { frequency: 988, durationMs: 180, gain: 0.35 },
};

// A finish candidate (Phase 2, `on` mode): two rising tones - B5, then E6 a fifth up
export const CHIME_PATTERN = [{ offsetMs: 0, frequency: 988 }, { offsetMs: 170, frequency: 1319 }];
export const CHIME_VIBRATION = [80, 60, 80];

// The alarm: three double tones
export const ALARM_PATTERN_MS = [0, 280, 700, 980, 1400, 1680];
export const GO_VIBRATION = [250];
export const ALARM_VIBRATION = [400, 150, 400, 150, 700];

/**
 * The countdown's beeps still ahead (and not more than LOOKAHEAD_MS away): `{atMs, kind}` on the server's clock.
 *
 * @param {number} startedAtMs
 * @param {number} serverNowMs
 * @returns {{atMs: number, kind: 'tick'|'go'}[]}
 */
export function countdownPlan(startedAtMs, serverNowMs, { lookaheadMs = LOOKAHEAD_MS, lateToleranceMs = LATE_TOLERANCE_MS } = {}) {
    if (!Number.isFinite(startedAtMs) || !Number.isFinite(serverNowMs)) {
        return [];
    }

    return [
        ...COUNTDOWN_BEEPS_MS.map((beforeMs) => ({ atMs: startedAtMs - beforeMs, kind: 'tick' })),
        { atMs: startedAtMs, kind: 'go' },
    ].filter((beep) => beep.atMs >= serverNowMs - lateToleranceMs && beep.atMs <= serverNowMs + lookaheadMs);
}

export class Beeps {
    /**
     * @param {object} [options]
     * @param {function(new: AudioContext)|null} [options.AudioContext]
     * @param {function(number[]): boolean|null} [options.vibration] plays a pattern (default: signal() of assets/haptics.js)
     */
    constructor({
        AudioContext = globalThis.AudioContext ?? globalThis.webkitAudioContext ?? null,
        vibration = (pattern) => signal(pattern),
    } = {}) {
        this.AudioContextClass = AudioContext;
        this.vibrationFunction = vibration;
        this.context = null;
        // The countdown on the audio clock: its start and the beeps put there (by atMs)
        this.plan = { startedAtMs: null, scheduled: new Map() };
    }

    /**
     * Inside a tap: creates / resumes the audio context and plays a silent buffer (iOS unlocks on a sound started by a
     * gesture). Harmless to call on every tap.
     */
    unlock() {
        if (this.AudioContextClass === null) {
            return false;
        }

        try {
            if (this.context === null) {
                this.context = new this.AudioContextClass();
            }

            if (this.context.state === 'suspended' && typeof this.context.resume === 'function') {
                this.context.resume().catch?.(() => {});
            }

            const buffer = this.context.createBuffer(1, 1, 22050);
            const source = this.context.createBufferSource();
            source.buffer = buffer;
            source.connect(this.context.destination);
            source.start(0);
        } catch (e) {
            return false;
        }

        return true;
    }

    isUnlocked() {
        return this.context !== null && this.context.state !== 'closed';
    }

    /**
     * Puts the countdown's coming beeps on the audio clock - called on every render of the countdown; a beep is
     * scheduled once, a new start cancels the old plan.
     */
    scheduleCountdown(startedAtMs, serverNowMs) {
        if (this.plan.startedAtMs !== startedAtMs) {
            this.cancelCountdown();
            this.plan.startedAtMs = startedAtMs;
        }

        if (!this.isUnlocked()) {
            return 0;
        }

        let added = 0;

        for (const beep of countdownPlan(startedAtMs, serverNowMs)) {
            if (this.plan.scheduled.has(beep.atMs)) {
                continue;
            }

            const nodes = this.tone(TONES[beep.kind], Math.max(0, beep.atMs - serverNowMs) / 1000);

            if (nodes !== null) {
                this.plan.scheduled.set(beep.atMs, nodes);
                added += 1;
            }
        }

        return added;
    }

    /**
     * The start moved or was cancelled: beeps not played yet are silenced.
     */
    cancelCountdown() {
        for (const nodes of this.plan.scheduled.values()) {
            try {
                nodes.oscillator.stop();
                nodes.oscillator.disconnect();
                nodes.gain.disconnect();
            } catch (e) {
                // Played already
            }
        }

        this.plan = { startedAtMs: null, scheduled: new Map() };
    }

    /**
     * GO's vibration (the sound is part of the countdown plan).
     */
    goVibration() {
        this.buzz(GO_VIBRATION);
    }

    /**
     * Time's up: the alarm and a long vibration.
     */
    alarm() {
        this.buzz(ALARM_VIBRATION);

        if (!this.isUnlocked()) {
            return false;
        }

        ALARM_PATTERN_MS.forEach((offsetMs, index) => {
            const tone = index % 2 === 0 ? TONES.alarm : { ...TONES.alarm, frequency: TONES.alarm.frequency * 0.75 };
            this.tone(tone, offsetMs / 1000);
        });

        return true;
    }

    /**
     * Raised hands were recorded as the finish (README §5.7, `on` mode): a two-tone chime and a short vibration.
     */
    chime() {
        this.buzz(CHIME_VIBRATION);

        if (!this.isUnlocked()) {
            return false;
        }

        for (const { offsetMs, frequency } of CHIME_PATTERN) {
            this.tone({ ...TONES.chime, frequency }, offsetMs / 1000);
        }

        return true;
    }

    /**
     * A short confirmation (the Finished tap).
     */
    tap() {
        this.buzz([60]);
    }

    close() {
        this.cancelCountdown();

        try {
            this.context?.close?.()?.catch?.(() => {});
        } catch (e) {
            // Closed already
        }

        this.context = null;
    }

    // ---- internals

    buzz(pattern) {
        try {
            this.vibrationFunction?.(pattern);
        } catch (e) {
            // No vibration (iOS) - the sound and the screen carry it
        }
    }

    /**
     * One sine tone `inSeconds` from now on the audio clock, with a short fade in and out (no clicks).
     */
    tone({ frequency, durationMs, gain: level }, inSeconds) {
        try {
            const context = this.context;
            const at = context.currentTime + inSeconds;
            const duration = durationMs / 1000;
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency, at);
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(level, at + 0.01);
            gain.gain.setValueAtTime(level, at + Math.max(0.01, duration - 0.04));
            gain.gain.exponentialRampToValueAtTime(0.0001, at + duration);
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start(at);
            oscillator.stop(at + duration + 0.02);

            return { oscillator, gain, at };
        } catch (e) {
            return null;
        }
    }
}
