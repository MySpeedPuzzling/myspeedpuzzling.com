import { os } from './platform.js';

// Pressed feedback per platform (docs/features/navigation README §4.13 G11; requirement GEST-11; decisions 58 D-6, 62).
//
// On Android a ripple spreads from the touch point over the pressed target: it starts at 20 % of the target's larger
// side, covers it in 450 ms `cubic-bezier(.2,0,0,1)`, stays at least 225 ms and fades out in 150 ms after the release;
// no delay on the fixed bars (`data-press="fixed"`), 150 ms inside scrolling content (`data-press`: Menu rows, sheet
// rows, chips, link rows - a touch that becomes a scroll never ripples); cancelled on pointercancel or a move over
// 10 px; none under reduced motion. Cards (`data-press="layer"`) and Bootstrap buttons in content never ripple. iOS and
// desktop keep GEST-04's state layer only (assets/styles/_press-states.scss) - so does Android, under the ripple.
//
// The ripple lives in its own host span inside the target (`<span class="msp-ripple-host" aria-hidden="true">`,
// absolute, inset 0, overflow hidden, the target's corners): the target itself never gets overflow: hidden (its badge
// would be clipped) and gets position: relative (when static) and isolation only while a ripple is in it - the ripple
// lies under the target's content, as GEST-04's layer does. Where the
// visible shape is an inner pill - a tab's 56 × 32 indicator, the ⏱ pill's body - the ripple fills that pill, as
// GEST-04's layer does. It stays on inside the Hotwire Native app (a press answer, not a navigation behaviour -
// decision 62). Colour: --nav-pressed-pill. turbo:before-cache removes every host (a snapshot never keeps one).
//
// The pure part (timings, geometry, one press's timeline with injected timers) is node-tested in
// tests/press-feedback-harness.mjs; install() wires it to the document (app.js).

export const COVER_MS = 450;
export const HOLD_MIN_MS = 225;
export const FADE_MS = 150;
export const CONTENT_DELAY_MS = 150;
export const MOVE_TOLERANCE_PX = 10;
export const START_FRACTION = 0.2;

export const PRESS_SELECTOR = '[data-press]';
export const HOST_CLASS = 'msp-ripple-host';
export const RIPPLE_CLASS = 'msp-ripple';
export const FADING_CLASS = 'is-fading';
// The ripple's own surface inside a target whose visible shape is an inner pill (GEST-04's layer sits there too)
export const SURFACE_SELECTOR = '.nav-tab-icon, .stopwatch-pill-body';
export const FADE_ANIMATION = 'msp-ripple-fade';
// On a surface while a ripple is in it: what the script changed there to restore afterwards ("position isolation")
export const SURFACE_ATTRIBUTE = 'data-ripple-surface';

/** Ripples only on an Android phone (any browser, the app included) and never under reduced motion */
export function rippleEnabled({ os: device, reducedMotion } = {}) {
    return device === 'android' && !reducedMotion;
}

/**
 * The delay before a ripple shows, by marker: the fixed bars at once, content after 150 ms (a scroll starts in that
 * time), cards never (null)
 */
export function delayFor(marker) {
    if (marker === 'layer' || marker === null || marker === undefined) {
        return null;
    }

    return marker === 'fixed' ? 0 : CONTENT_DELAY_MS;
}

/** A move of more than 10 px from where the finger went down: a scroll or a drag, not a press */
export function movedTooFar(origin, point) {
    return Math.hypot(point.x - origin.x, point.y - origin.y) > MOVE_TOLERANCE_PX;
}

/** When the fade starts: at the release, but never before the ripple has shown for 225 ms */
export function fadeStartAt({ startedAt, releasedAt }) {
    return Math.max(releasedAt, startedAt + HOLD_MIN_MS);
}

/**
 * A ripple circle from the touch point (x, y inside the surface) that covers the whole surface when scaled to 1: its
 * radius reaches the farthest corner; it starts at 20 % of the surface's larger side.
 */
export function rippleGeometry({ width, height, x, y }) {
    const radius = Math.max(
        Math.hypot(x, y),
        Math.hypot(width - x, y),
        Math.hypot(x, height - y),
        Math.hypot(width - x, height - y),
        1,
    );
    const size = radius * 2;

    return {
        size: Math.round(size * 100) / 100,
        left: Math.round((x - radius) * 100) / 100,
        top: Math.round((y - radius) * 100) / 100,
        startScale: Math.min(1, Math.round((START_FRACTION * Math.max(width, height) / size) * 1000) / 1000),
    };
}

/**
 * One press's timeline: start (now or after the delay), cancel on a long move or pointercancel, release → fade from
 * fadeStartAt(). Times and timers are injected: the browser passes performance.now / setTimeout, node a fake clock.
 * A release before the delay still shows a short ripple (Android does): it starts at the release and fades 225 ms later.
 */
export class RipplePress {
    constructor({ delay, origin, now, setTimer = (fn, ms) => setTimeout(fn, ms), clearTimer = (id) => clearTimeout(id), onStart = () => {}, onFade = () => {}, onCancel = () => {} }) {
        this.delay = delay;
        this.origin = origin;
        this.now = now;
        this.setTimer = setTimer;
        this.clearTimer = clearTimer;
        this.onStart = onStart;
        this.onFade = onFade;
        this.onCancel = onCancel;
        this.startedAt = null;
        this.releasedAt = null;
        this.timer = null;
        this.done = false;
    }

    begin() {
        if (this.delay === 0) {
            this.start();
        } else {
            this.timer = this.setTimer(() => {
                this.timer = null;
                this.start();
            }, this.delay);
        }
    }

    move(point) {
        if (!this.done && this.releasedAt === null && movedTooFar(this.origin, point)) {
            this.cancel();
        }
    }

    release() {
        if (this.done || this.releasedAt !== null) {
            return;
        }

        this.releasedAt = this.now();

        if (this.startedAt === null) {
            this.clear();
            this.start();
        }

        const wait = Math.max(0, fadeStartAt({ startedAt: this.startedAt, releasedAt: this.releasedAt }) - this.now());
        this.timer = this.setTimer(() => {
            this.timer = null;
            this.done = true;
            this.onFade();
        }, wait);
    }

    cancel() {
        if (this.done) {
            return;
        }

        this.done = true;
        this.clear();

        if (this.startedAt !== null) {
            this.onCancel();
        }
    }

    start() {
        if (this.done || this.startedAt !== null) {
            return;
        }

        this.startedAt = this.now();
        this.onStart();
    }

    clear() {
        if (this.timer !== null) {
            this.clearTimer(this.timer);
            this.timer = null;
        }
    }
}

// ---- The DOM part (app.js) ------------------------------------------------------------------------------------------

/** The element the ripple fills: the target, or its inner pill */
function surfaceOf(target) {
    return target.querySelector(SURFACE_SELECTOR) || target;
}

/** Inserts the host + ripple into the surface; returns the host */
function addRipple(surface, clientX, clientY, win) {
    const box = surface.getBoundingClientRect();
    const geometry = rippleGeometry({ width: box.width, height: box.height, x: clientX - box.left, y: clientY - box.top });

    // The host sits under the surface's content (z-index -1 in the surface's own stacking context - like GEST-04's
    // layer, an icon or a label is never dimmed by it): the surface positioned and isolated while a ripple is in it
    if (!surface.hasAttribute(SURFACE_ATTRIBUTE)) {
        const style = win.getComputedStyle(surface);
        surface.setAttribute(SURFACE_ATTRIBUTE, [style.position === 'static' ? 'position' : '', style.isolation === 'isolate' ? '' : 'isolation'].filter(Boolean).join(' '));

        if (style.position === 'static') {
            surface.style.position = 'relative';
        }

        if (style.isolation !== 'isolate') {
            surface.style.isolation = 'isolate';
        }
    }

    const host = surface.ownerDocument.createElement('span');
    host.className = HOST_CLASS;
    host.setAttribute('aria-hidden', 'true');

    const ripple = surface.ownerDocument.createElement('span');
    ripple.className = RIPPLE_CLASS;
    ripple.style.width = `${geometry.size}px`;
    ripple.style.height = `${geometry.size}px`;
    ripple.style.left = `${geometry.left}px`;
    ripple.style.top = `${geometry.top}px`;
    ripple.style.setProperty('--msp-ripple-from', String(geometry.startScale));

    host.appendChild(ripple);
    surface.appendChild(host);

    return host;
}

/** Removes a host; the surface gets its own position and isolation back once no ripple is left in it */
function removeHost(host) {
    const surface = host.parentElement;
    host.remove();

    if (surface && surface.hasAttribute(SURFACE_ATTRIBUTE) && !surface.querySelector(`.${HOST_CLASS}`)) {
        const changed = surface.getAttribute(SURFACE_ATTRIBUTE).split(' ');

        if (changed.includes('position')) {
            surface.style.position = '';
        }

        if (changed.includes('isolation')) {
            surface.style.isolation = '';
        }

        surface.removeAttribute(SURFACE_ATTRIBUTE);
    }
}

/** Every ripple off the page at once (a snapshot for Turbo's cache, GEST-15's list) */
export function removeAllRipples(doc) {
    doc.querySelectorAll(`.${HOST_CLASS}`).forEach((host) => removeHost(host));
}

/**
 * One delegated pointerdown listener (capture, passive: nothing waits for it) on [data-press]; the press follows its
 * pointer through pointermove / pointerup / pointercancel on the document.
 */
export function install({ window: win = typeof window !== 'undefined' ? window : null, document: doc = typeof document !== 'undefined' ? document : null } = {}) {
    if (!win || !doc || doc.__mspPressFeedbackInstalled) {
        return;
    }

    doc.__mspPressFeedbackInstalled = true;

    const presses = new Map();
    // A pointer id comes back (a mouse is always 1): a press only ever forgets itself
    const forget = (pointerId, press) => {
        if (presses.get(pointerId) === press) {
            presses.delete(pointerId);
        }
    };
    const reducedMotion = typeof win.matchMedia === 'function' ? win.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const now = () => win.performance.now();

    const fadeOut = (host) => {
        if (!host.isConnected) {
            return;
        }

        let removed = false;
        const remove = () => {
            if (!removed) {
                removed = true;
                removeHost(host);
            }
        };

        host.addEventListener('animationend', (event) => {
            if (event.animationName === FADE_ANIMATION) {
                remove();
            }
        });
        host.classList.add(FADING_CLASS);
        // No animationend (the host hidden, the tab in the background): removed anyway
        win.setTimeout(remove, FADE_MS + 100);
    };

    doc.addEventListener('pointerdown', (event) => {
        if (!event.isPrimary || event.button !== 0) {
            return;
        }

        const target = event.target instanceof win.Element ? event.target.closest(PRESS_SELECTOR) : null;

        if (!target || !rippleEnabled({ os: os(doc.documentElement), reducedMotion: reducedMotion ? reducedMotion.matches : false })) {
            return;
        }

        const delay = delayFor(target.getAttribute('data-press'));

        if (delay === null) {
            return;
        }

        presses.get(event.pointerId)?.cancel();

        const surface = surfaceOf(target);
        const point = { x: event.clientX, y: event.clientY };
        let host = null;
        const press = new RipplePress({
            delay,
            origin: point,
            now,
            setTimer: (fn, ms) => win.setTimeout(fn, ms),
            clearTimer: (id) => win.clearTimeout(id),
            onStart: () => {
                if (surface.isConnected) {
                    host = addRipple(surface, point.x, point.y, win);
                }
            },
            onFade: () => {
                forget(event.pointerId, press);

                if (host) {
                    fadeOut(host);
                }
            },
            onCancel: () => {
                forget(event.pointerId, press);

                if (host) {
                    removeHost(host);
                }
            },
        });

        presses.set(event.pointerId, press);
        press.begin();
    }, { capture: true, passive: true });

    doc.addEventListener('pointermove', (event) => {
        presses.get(event.pointerId)?.move({ x: event.clientX, y: event.clientY });
    }, { capture: true, passive: true });

    // Released: the press runs out on its own timers (the fade), the pointer is free for the next one
    doc.addEventListener('pointerup', (event) => {
        const press = presses.get(event.pointerId);
        presses.delete(event.pointerId);
        press?.release();
    }, { capture: true, passive: true });

    doc.addEventListener('pointercancel', (event) => {
        const press = presses.get(event.pointerId);
        presses.delete(event.pointerId);
        press?.cancel();
    }, { capture: true, passive: true });

    doc.addEventListener('turbo:before-cache', () => {
        presses.forEach((press) => press.cancel());
        presses.clear();
        // Released presses still fading are not in the map: their hosts go here
        removeAllRipples(doc);
    });
}
