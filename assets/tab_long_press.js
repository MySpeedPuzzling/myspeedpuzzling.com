import { isNativeApp, os } from './platform.js';

// Long-press a truncated tab shows its label (docs/features/navigation README §4.13 G16; requirement GEST-18) - the pure
// part, node-tested (tests/tab-long-press-harness.mjs); the DOM side is assets/controllers/tab_gestures_controller.js.
//
// Only while the tab's label is not fully shown - NAV-02's step 3 (only the current tab keeps its label on screen) for
// every other tab, or a label wider than its box (scrollWidth > clientWidth): a finger held 500 ms without moving more
// than 10 px shows a bubble with the full label 12 px above the tab (role="tooltip", the tab's aria-describedby); Android
// also feels `longPress` (assets/haptics.js); the release does not navigate (the click that may follow is dropped once);
// the bubble hides on release or after 1.5 s; a shorter tap navigates as always. iOS and Android alike (a touch or a
// pen); desktop and other devices get the native `title` instead. Never inside the Hotwire Native app (no web tab bar).
// A label, not a menu (README §4.13 "Don't": no custom long-press menus).

export const HOLD_MS = 500;
export const MOVE_TOLERANCE_PX = 10;
export const HIDE_AFTER_MS = 1500;
export const BUBBLE_GAP_PX = 12;
export const EDGE_PX = 8;
// A click that has not come this long after the release is not coming (Chromium sends none after a long press): the
// next tap is a tap again
export const CLICK_WINDOW_MS = 600;
export const BUBBLE_ID = 'nav-tab-bubble';

// NAV-02's "current label only" step (assets/bottom_nav_labels.js STEP_CURRENT_ONLY)
const STEP_CURRENT_ONLY = 3;

/**
 * Is the tab's label cut? In step 3 every tab but the current one shows its icon only; otherwise a label whose text is
 * wider than its box. Measured before the tap marks the tab pending (GEST-04 shows a pending tab's label).
 */
export function isTruncated({ step, current, scrollWidth, clientWidth } = {}) {
    if (Number(step) === STEP_CURRENT_ONLY && !current) {
        return true;
    }

    return typeof scrollWidth === 'number' && typeof clientWidth === 'number' && scrollWidth > clientWidth;
}

/** Where the bubble applies: a finger (or a pen) on an iPhone or an Android phone, outside the app */
export function longPressEnabled({ os: device, nativeApp, pointerType } = {}) {
    return !nativeApp && (device === 'ios' || device === 'android') && (pointerType === 'touch' || pointerType === 'pen');
}

/** The same, with the platform read from <html> (assets/platform.js) */
export function longPressEnabledFor(root, pointerType) {
    return longPressEnabled({ os: os(root), nativeApp: isNativeApp(root), pointerType });
}

/** Desktop and other devices: a cut label's native `title` (README §4.13: "Desktop / other: native title") */
export function wantsTitle({ os: device, nativeApp, truncated } = {}) {
    return Boolean(truncated) && !nativeApp && device !== 'ios' && device !== 'android';
}

/**
 * The bubble's place: centred over the tab, 12 px above it, at least 8 px from the viewport's sides.
 *
 * @param {{tab: {left: number, top: number, width: number}, bubble: {width: number, height: number}, viewportWidth: number}} geometry
 * @returns {{left: number, top: number}}
 */
export function bubblePosition({ tab, bubble, viewportWidth }) {
    const centred = tab.left + tab.width / 2 - bubble.width / 2;
    const max = Math.max(EDGE_PX, viewportWidth - EDGE_PX - bubble.width);

    return {
        left: Math.round(Math.min(Math.max(centred, EDGE_PX), max)),
        top: Math.round(tab.top - BUBBLE_GAP_PX - bubble.height),
    };
}

export const IDLE = 'idle';
export const HOLDING = 'holding';
export const SHOWN = 'shown';
export const RELEASED = 'released';

/**
 * One press at a time: down → (500 ms held, ≤ 10 px moved) → shown → hidden on release or after 1.5 s. Timers are
 * injected (node tests drive them by hand). up() answers whether the click that may follow must be dropped.
 */
export class LongPress {
    constructor({ setTimer = (fn, ms) => setTimeout(fn, ms), clearTimer = (id) => clearTimeout(id), onShow = () => {}, onHide = () => {} } = {}) {
        this.setTimer = setTimer;
        this.clearTimer = clearTimer;
        this.onShow = onShow;
        this.onHide = onHide;
        this.state = IDLE;
        this.origin = null;
        this.holdTimer = null;
        this.hideTimer = null;
        this.visible = false;
    }

    down({ x, y }) {
        this.reset();
        this.state = HOLDING;
        this.origin = { x, y };
        this.holdTimer = this.setTimer(() => this.show(), HOLD_MS);
    }

    /** A move of more than 10 px before the bubble is a drag: no bubble. After it, moving changes nothing */
    move({ x, y }) {
        if (this.state !== HOLDING || this.origin === null) {
            return;
        }

        if (Math.hypot(x - this.origin.x, y - this.origin.y) > MOVE_TOLERANCE_PX) {
            this.reset();
        }
    }

    /** The finger is lifted: true when the bubble was shown (the release must not navigate) */
    up() {
        const shown = this.state === SHOWN;
        this.reset();

        if (shown) {
            this.state = RELEASED;
        }

        return shown;
    }

    /** pointercancel: the browser took the touch over - nothing to drop, the bubble goes */
    cancel() {
        this.reset();
    }

    show() {
        this.holdTimer = null;

        if (this.state !== HOLDING) {
            return;
        }

        this.state = SHOWN;
        this.visible = true;
        this.onShow();
        this.hideTimer = this.setTimer(() => this.hide(), HIDE_AFTER_MS);
    }

    hide() {
        if (this.hideTimer !== null) {
            this.clearTimer(this.hideTimer);
            this.hideTimer = null;
        }

        if (this.visible) {
            this.visible = false;
            this.onHide();
        }
    }

    reset() {
        if (this.holdTimer !== null) {
            this.clearTimer(this.holdTimer);
            this.holdTimer = null;
        }

        this.hide();
        this.state = IDLE;
        this.origin = null;
    }
}
