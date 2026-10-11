/*
 * The sounds of the last-piece reveal, synthesised with Web Audio (noise + oscillators, no audio files): a cardboard
 * "tk" per frame piece (one semitone higher each time), a deeper "clack" when the last piece clicks in, a soft bell
 * chime (more notes per tier) and a glitter tail for the shimmer tiers. Every sound of a reveal is scheduled at once
 * on the audio clock, so a busy main thread cannot make it late.
 *
 * On by default - the visitor can turn it off (the choice is kept in localStorage by the controller). The AudioContext
 * is created on the first tap with the sound on (browsers allow audio only after a user gesture) and closed on
 * disconnect. It plays as "ambient" audio where the browser supports the Audio Session API (Safari): the silent switch
 * mutes it and it mixes with whatever the visitor is listening to instead of stopping it.
 */

function mulberry32(seed) {
    let a = seed;

    return () => {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const noiseCache = new WeakMap();

function noiseBuffer(ctx) {
    let buffer = noiseCache.get(ctx);

    if (!buffer) {
        buffer = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
        const data = buffer.getChannelData(0);
        const random = mulberry32(42);

        for (let i = 0; i < data.length; i++) {
            data[i] = random() * 2 - 1;
        }

        noiseCache.set(ctx, buffer);
    }

    return buffer;
}

function envelope(ctx, t, peak, attack, decay) {
    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(peak, t + attack);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + attack + decay);

    return gain;
}

function noiseHit(ctx, out, t, type, frequency, q, peak, decay, offset) {
    const source = ctx.createBufferSource();
    source.buffer = noiseBuffer(ctx);
    const filter = ctx.createBiquadFilter();
    filter.type = type;
    filter.frequency.value = frequency;
    filter.Q.value = q;
    const gain = envelope(ctx, t, peak, 0.0015, decay);
    source.connect(filter);
    filter.connect(gain);
    gain.connect(out);
    source.start(t, offset % 0.9, decay + 0.03);
}

function tone(ctx, out, t, type, f0, f1, peak, attack, decay) {
    const oscillator = ctx.createOscillator();
    oscillator.type = type;
    oscillator.frequency.setValueAtTime(f0, t);

    if (f1 && f1 !== f0) {
        oscillator.frequency.exponentialRampToValueAtTime(f1, t + Math.min(decay, 0.12));
    }

    const gain = envelope(ctx, t, peak, attack, decay);
    oscillator.connect(gain);
    gain.connect(out);
    oscillator.start(t);
    oscillator.stop(t + attack + decay + 0.02);
}

const SYNTH = {
    // a piece pressed into place: a short cardboard "tk", one semitone higher per piece (builds anticipation)
    tick(ctx, out, t, i) {
        noiseHit(ctx, out, t, 'bandpass', 2300 + i * 120, 1.5, 0.34, 0.032, i * 0.071);
        const frequency = 640 * Math.pow(2, i / 12);
        tone(ctx, out, t, 'triangle', frequency, frequency * 0.8, 0.09, 0.002, 0.06);
    },
    // the last piece: a deeper, fuller "clack" (thump + knock + snap); the mid knock carries on phone speakers
    clunk(ctx, out, t) {
        tone(ctx, out, t, 'sine', 210, 70, 0.5, 0.003, 0.2);
        noiseHit(ctx, out, t, 'bandpass', 1350, 2.2, 0.62, 0.075, 0.31);
        noiseHit(ctx, out, t, 'highpass', 4200, 0.7, 0.34, 0.018, 0.57);
    },
    // a soft bell arpeggio, more notes per tier
    chime(ctx, out, t, notes) {
        notes.forEach((frequency, j) => {
            const tj = t + j * 0.075;
            tone(ctx, out, tj, 'sine', frequency, frequency, 0.13, 0.005, 0.95);
            tone(ctx, out, tj, 'sine', frequency * 2, frequency * 2, 0.04, 0.004, 0.4);
            tone(ctx, out, tj, 'sine', frequency * 3.01, frequency * 3.01, 0.018, 0.003, 0.2);
        });
    },
    // the shimmer tiers: a falling glitter tail
    glitter(ctx, out, t) {
        const random = mulberry32(5);

        for (let k = 0; k < 14; k++) {
            const tk = t + k * 0.05 + k * k * 0.0035;
            const frequency = 2500 + random() * 3200;
            tone(ctx, out, tk, 'sine', frequency, frequency, 0.05 * (1 - k / 17), 0.002, 0.11);
        }
    },
};

export class PieceSfx {
    constructor() {
        this.on = false;
        this.ctx = null;
        this.out = null;
    }

    /** Call from a user gesture (autoplay rules); creates the context only when the sound is on. */
    unlock() {
        if (!this.on) {
            return;
        }

        if (!this.ctx) {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;

            if (!AudioContextClass) {
                return;
            }

            if (navigator.audioSession) {
                try {
                    navigator.audioSession.type = 'ambient';
                } catch (e) {
                    // an older implementation: the default session
                }
            }

            try {
                this.ctx = new AudioContextClass();
            } catch (e) {
                this.ctx = null;
                return;
            }

            const compressor = this.ctx.createDynamicsCompressor();
            compressor.threshold.value = -16;
            compressor.ratio.value = 3;
            compressor.attack.value = 0.003;
            compressor.release.value = 0.15;
            this.out = this.ctx.createGain();
            this.out.gain.value = 0.85;
            this.out.connect(compressor);
            compressor.connect(this.ctx.destination);
        }

        if (this.ctx.state === 'suspended') {
            this.ctx.resume().catch(() => {});
        }
    }

    /**
     * Schedules events ([{t: ms from now, type, arg}]) on the audio clock; returns a bus to stop() them early.
     */
    play(events) {
        if (!this.on || !this.ctx || this.ctx.state === 'closed' || events.length === 0) {
            return null;
        }

        const bus = this.ctx.createGain();
        bus.connect(this.out);
        const base = this.ctx.currentTime + 0.012;

        for (const event of events) {
            SYNTH[event.type](this.ctx, bus, base + event.t / 1000, event.arg);
        }

        return bus;
    }

    stop(bus) {
        if (!bus) {
            return;
        }

        try {
            bus.gain.value = 0;
            bus.disconnect();
        } catch (e) {
            // already gone
        }
    }

    close() {
        if (this.ctx && this.ctx.state !== 'closed') {
            this.ctx.close().catch(() => {});
        }

        this.ctx = null;
        this.out = null;
    }
}
