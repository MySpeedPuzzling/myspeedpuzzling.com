/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import GEOMETRY from '../last_piece_reveal/geometry.json';
import { lookFor } from '../last_piece_reveal/looks.js';
import { LastPieceReveal, Ticker } from '../last_piece_reveal/reveal.js';
import { PieceConfetti } from '../last_piece_reveal/confetti.js';
import { PieceSfx } from '../last_piece_reveal/sfx.js';

const SOUND_KEY = 'msp-reveal-sound';
const DECODE_WAIT_MS = 400;
const SEEDS = { single: 6, level: 7, 'level-max': 8 };

/**
 * The last-piece reveal of an earned badge (badge reveal page) or of the level medallion (XP launch reveal):
 * the picture arrives as a 3x3 jigsaw and its icon piece clicks in last (assets/last_piece_reveal/reveal.js).
 *
 * One controller per page area; every `stage` target is one reveal (a <button> with a `.lpr-tray` child - the
 * unrevealed look is CSS, nothing pops in when this lazy controller arrives). Per stage, as action params:
 *   look        1-5 (tier), `single`, `level` or `level-max`
 *   url         reveal endpoint, POSTed once on the first tap (fire-and-forget, as before)
 *   image       the badge art at 2x of the stage on a phone (WebP), image-large the bigger file for big stages
 *   done-label  the stage's accessible name once revealed
 * A stage holding an inline `<svg class="lpr-emblem">` reveals that picture instead of an image.
 *
 * - Several stages reveal one after another, never two at once: a tap on another stage finishes the running one
 *   first; when one ends, the next unrevealed one nudges once.
 * - `prefers-reduced-motion`: the badge appears at once, no confetti. A tap while it runs skips to the end.
 * - Sound is off until the visitor turns it on (sound toggle target, kept in localStorage); it starts only inside a
 *   tap (browser autoplay rules).
 * - `autoplay`: the first stage plays by itself once it is on screen (the level medallion of the XP launch reveal).
 * - Another controller can drive the stages through an outlet (the XP launch deck, xp_launch_reveal_controller.js):
 *   start(), stateOf(), preloadStage(), postReveal(), stopAll(), unlockSound(); `last-piece-reveal:revealed` tells
 *   it that a picture is complete (detail: stage, instant).
 * - Turbo-safe: disconnect cancels every animation, the confetti, the rAF loop and closes the audio context.
 */
export default class extends Controller {
    static targets = ['stage', 'soundToggle', 'count', 'after', 'done'];

    static values = {
        autoplay: { type: Boolean, default: false },
        skipLabel: String,
    };

    connect() {
        this.live = true;
        this.ticker = new Ticker();
        this.sfx = new PieceSfx();
        this.sfx.on = readSoundPreference();
        this.confetti = new PieceConfetti(GEOMETRY.confetti, this.ticker);
        this.reveals = new Map();
        this.pictures = new Map();
        this.images = new Map();
        this.masks = new Map();
        this.pending = new Set();
        this.countStarted = false;
        this.renderSoundToggle();

        // A stage revealed (or already sent to the server) before a reconnect stays revealed
        for (const stage of this.stageTargets) {
            if (stage.classList.contains('is-revealed') || stage.dataset.lprPosted === '1') {
                this.start(stage, { silent: true, instant: true });
            }
        }

        this.observeForPreload();

        if (this.autoplayValue) {
            this.scheduleAutoplay();
        }
    }

    disconnect() {
        this.live = false;
        window.clearTimeout(this.autoplayTimer);

        if (this.autoplayObserver) {
            this.autoplayObserver.disconnect();
            this.autoplayObserver = null;
        }

        if (this.preloadObserver) {
            this.preloadObserver.disconnect();
            this.preloadObserver = null;
        }

        for (const reveal of this.reveals.values()) {
            reveal.destroy();
        }

        this.reveals.clear();
        this.confetti.clear();
        this.ticker.stop();
        this.sfx.close();
        this.pictures.clear();
        this.images.clear();
        this.masks.clear();
        this.pending.clear();
    }

    /** action: a tap (or Enter / Space) on a stage */
    play(event) {
        event.preventDefault();
        this.sfx.unlock();                                 // inside the user gesture
        this.start(event.currentTarget);
    }

    /** action: the sound toggle */
    toggleSound() {
        this.sfx.on = !this.sfx.on;
        writeSoundPreference(this.sfx.on);
        this.renderSoundToggle();

        if (this.sfx.on) {
            this.sfx.unlock();
            this.sfx.play([{ t: 0, type: 'tick', arg: 4 }]);  // a small "tk": this is what it sounds like
        } else {
            for (const reveal of this.reveals.values()) {
                if (reveal.bus) {
                    this.sfx.stop(reveal.bus);
                    reveal.bus = null;
                }
            }
        }
    }

    async start(stage, { silent = false, instant = false } = {}) {
        if (this.pending.has(stage)) {
            return;
        }

        const reveal = this.revealFor(stage);

        if (reveal.state === 'running') {
            reveal.skip();
            return;
        }

        // never two at once: whatever still runs elsewhere jumps to its end
        for (const other of this.reveals.values()) {
            if (other !== reveal && other.state === 'running') {
                other.skip();
            }
        }

        const params = paramsOf(stage);

        if (!silent) {
            this.postReveal(stage);
        }

        for (const other of this.stageTargets) {
            other.classList.remove('is-next');
        }

        this.pending.add(stage);

        try {
            await this.prepare(stage, reveal, params);
        } finally {
            this.pending.delete(stage);
        }

        if (this.reveals.get(stage) !== reveal) {
            return;                                        // disconnected meanwhile (Turbo navigation)
        }

        reveal.play(lookFor(params.look), {
            reduced: instant || prefersReducedMotion(),
            seed: SEEDS[params.look] || Number(params.look) || 1,
            sound: !silent,
        });
    }

    revealFor(stage) {
        let reveal = this.reveals.get(stage);

        if (!reveal) {
            reveal = new LastPieceReveal(stage, {
                geometry: GEOMETRY,
                ticker: this.ticker,
                sfx: this.sfx,
                confetti: this.confetti,
                picture: () => {
                    const emblem = stage.querySelector('.lpr-emblem');

                    return emblem ? { kind: 'emblem', element: emblem } : { kind: 'image', url: this.pictures.get(stage) || '' };
                },
                clipRect: () => this.element.getBoundingClientRect(),
                onReveal: (look, { instant }) => this.revealed(stage, instant),
                onState: (state) => this.stateChanged(stage, state),
            });
            this.reveals.set(stage, reveal);
        }

        return reveal;
    }

    /** The reveal endpoint, once per badge - also across a reconnect (fire-and-forget, as the flip did). */
    postReveal(stage) {
        const url = paramsOf(stage).url;

        if (url && stage.dataset.lprPosted !== '1') {
            stage.dataset.lprPosted = '1';
            fetch(url, { method: 'POST', keepalive: true }).catch(() => {});
        }
    }

    /** idle, loading (the picture is being decoded), running, done */
    stateOf(stage) {
        if (this.pending.has(stage)) {
            return 'loading';
        }

        const reveal = this.reveals.get(stage);

        return reveal ? reveal.state : 'idle';
    }

    /** a stage about to be shown: its picture (and glint mask) on the way, so it starts at once */
    preloadStage(stage) {
        if (!stage || stage.querySelector('.lpr-emblem')) {
            return;
        }

        const params = paramsOf(stage);
        const url = imageUrlFor(stage, params);

        if (url) {
            this.preload(url);
            const look = lookFor(params.look);

            if (look.glint || look.shimmer) {
                whenIdle(() => {
                    if (this.live) {
                        this.shineMaskFor(url);
                    }
                });
            }
        }
    }

    /** everything quiet at once (a skip to the summary): running reveals jump to their end, sound and confetti stop */
    stopAll() {
        for (const reveal of this.reveals.values()) {
            if (reveal.state === 'running') {
                reveal.skip();
            }
        }

        this.confetti.clear();
    }

    /** call inside a user gesture: browsers allow audio only after one */
    unlockSound() {
        this.sfx.unlock();
    }

    /** The badge picture decoded before the pieces fly (waits at most DECODE_WAIT_MS), the glint mask on its way. */
    async prepare(stage, reveal, params) {
        reveal.build();

        if (stage.querySelector('.lpr-emblem')) {
            return;
        }

        const url = imageUrlFor(stage, params);
        this.pictures.set(stage, url);

        if (!url) {
            return;
        }

        const look = lookFor(params.look);

        if (look.glint || look.shimmer) {
            this.shineMaskFor(url).then((mask) => reveal.setShineMask(mask || url));
        }

        const image = this.preload(url);

        if (image.decode) {
            await Promise.race([image.decode().catch(() => {}), wait(DECODE_WAIT_MS)]);
        }
    }

    revealed(stage, instant) {
        const params = paramsOf(stage);
        stage.classList.add('is-revealed');

        if (params.doneLabel) {
            stage.setAttribute('aria-label', params.doneLabel);
        }

        const card = stage.closest('.lpr-card');
        const result = card ? card.querySelector('.lpr-result-text') : null;

        if (result && result.hidden) {
            result.hidden = false;                         // inside an aria-live region: read out once
            fadeIn(result, instant);
        }

        for (const after of this.afterTargets) {
            if (!after.classList.contains('is-shown')) {
                after.classList.add('is-shown');
                fadeIn(after, instant);
            }
        }

        if (this.hasCountTarget && !this.countStarted && !instant && !prefersReducedMotion()) {
            // the XP counter spins up from zero once the level is complete (xp_count_controller.js)
            this.countStarted = true;
            const count = this.countTarget;
            count.setAttribute('data-controller', `${count.getAttribute('data-controller') || ''} xp-count`.trim());
        }

        if (this.hasDoneTarget && this.stageTargets.every((other) => other.classList.contains('is-revealed'))) {
            this.doneTarget.hidden = false;
        }

        this.dispatch('revealed', { detail: { stage, instant } });
    }

    stateChanged(stage, state) {
        if (state === 'running' && this.skipLabelValue) {
            stage.setAttribute('aria-label', this.skipLabelValue);
        }

        if (state === 'done') {
            const params = paramsOf(stage);

            if (params.doneLabel) {
                stage.setAttribute('aria-label', params.doneLabel);
            }

            // guide the sequence: the next badge still waiting for its last piece nudges once
            const next = this.stageTargets.find((other) => !other.classList.contains('is-revealed') && !this.pending.has(other));

            if (next) {
                next.classList.add('is-next');
            }
        }
    }

    renderSoundToggle() {
        for (const toggle of this.soundToggleTargets) {
            toggle.setAttribute('aria-pressed', this.sfx.on ? 'true' : 'false');
            toggle.classList.toggle('is-on', this.sfx.on);
            const icon = toggle.querySelector('i');

            if (icon) {
                icon.className = this.sfx.on ? 'bi bi-volume-up' : 'bi bi-volume-mute';
            }
        }
    }

    scheduleAutoplay() {
        const stage = this.stageTargets[0];

        if (!stage || stage.classList.contains('is-revealed')) {
            return;
        }

        const go = () => {
            const reveal = this.reveals.get(stage);

            if (!reveal || reveal.state === 'idle') {
                this.start(stage);
            }
        };

        if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
            go();
            return;
        }

        // play once it is on screen, after a short look at the empty puzzle
        this.autoplayObserver = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                this.autoplayObserver.disconnect();
                this.autoplayObserver = null;
                this.autoplayTimer = window.setTimeout(go, 650);
            }
        }, { threshold: 0.6 });
        this.autoplayObserver.observe(stage);
    }

    /**
     * The badge pictures of the stages on (or near) the screen load in the background - and their glint masks are
     * made while the browser is idle - so a tap starts at once and nothing heavy runs during the animation.
     */
    observeForPreload() {
        const stages = this.stageTargets.filter((stage) => !stage.querySelector('.lpr-emblem'));

        if (stages.length === 0 || !('IntersectionObserver' in window)) {
            return;
        }

        this.preloadObserver = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    this.preloadObserver.unobserve(entry.target);
                    this.preloadStage(entry.target);
                }
            }
        }, { rootMargin: '200px 0px' });

        for (const stage of stages) {
            this.preloadObserver.observe(stage);
        }
    }

    preload(url) {
        let image = this.images.get(url);

        if (!image) {
            image = new Image();
            image.decoding = 'async';
            image.src = url;
            this.images.set(url, image);
        }

        return image;
    }

    /**
     * The glint/shimmer mask: the badge's alpha without its navy ink (a luminance key), so the outlines stay crisp
     * under the sheen. Same-origin picture, so the canvas stays readable; anything failing = the badge itself.
     */
    shineMaskFor(url) {
        if (!this.masks.has(url)) {
            this.masks.set(url, new Promise((resolve) => {
                const image = new Image();
                image.onload = () => {
                    try {
                        const size = 256;
                        const canvas = document.createElement('canvas');
                        canvas.width = size;
                        canvas.height = size;
                        const ctx = canvas.getContext('2d', { willReadFrequently: true });
                        ctx.drawImage(image, 0, 0, size, size);
                        const pixels = ctx.getImageData(0, 0, size, size);
                        const p = pixels.data;

                        for (let i = 0; i < p.length; i += 4) {
                            const luminance = (0.2126 * p[i] + 0.7152 * p[i + 1] + 0.0722 * p[i + 2]) / 255;
                            p[i + 3] = Math.round(p[i + 3] * Math.min(1, Math.max(0, (luminance - 0.42) / 0.22)));
                            p[i] = 255;
                            p[i + 1] = 255;
                            p[i + 2] = 255;
                        }

                        ctx.putImageData(pixels, 0, 0);
                        resolve(canvas.toDataURL('image/png'));
                    } catch (e) {
                        resolve(null);
                    }
                };
                image.onerror = () => resolve(null);
                image.src = url;
            }));
        }

        return this.masks.get(url);
    }
}

function paramsOf(stage) {
    const data = stage.dataset;

    return {
        look: data.lastPieceRevealLookParam || 'single',
        url: data.lastPieceRevealUrlParam || null,
        image: data.lastPieceRevealImageParam || null,
        imageLarge: data.lastPieceRevealImageLargeParam || null,
        doneLabel: data.lastPieceRevealDoneLabelParam || null,
    };
}

/** the WebP at 2x of the stage on a phone; the bigger file once the stage needs more pixels (big stage or 3x screen) */
function imageUrlFor(stage, params) {
    const needed = stage.clientWidth * Math.min(window.devicePixelRatio || 1, 3);

    if (params.imageLarge && (needed > 330 || !params.image)) {
        return params.imageLarge;
    }

    return params.image || params.imageLarge;
}

function fadeIn(element, instant) {
    if (instant || prefersReducedMotion() || !element.animate) {
        return;
    }

    element.animate(
        [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
        { duration: 260, easing: 'ease-out' },
    );
}

function prefersReducedMotion() {
    return window.matchMedia !== undefined && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function readSoundPreference() {
    try {
        return window.localStorage.getItem(SOUND_KEY) === '1';
    } catch (e) {
        return false;                                      // private mode: off, the default
    }
}

function writeSoundPreference(on) {
    try {
        window.localStorage.setItem(SOUND_KEY, on ? '1' : '0');
    } catch (e) {
        // private mode: kept for this page only
    }
}

function wait(ms) {
    return new Promise((resolve) => {
        window.setTimeout(resolve, ms);
    });
}

function whenIdle(fn) {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(fn, { timeout: 2000 });
    } else {
        window.setTimeout(fn, 300);                       // Safari
    }
}
