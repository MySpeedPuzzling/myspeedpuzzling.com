/**
 * Swipe down closes a bottom sheet (docs/features/navigation README §4.13 G2, requirement GEST-02, implementation plan
 * GEST-02). The gesture rules only - no DOM writes, no timers - so tests/sheet-drag-harness.mjs runs them under node;
 * assets/controllers/bottom_sheet_controller.js feeds them pointer events (the handle) and touch events (the body)
 * and moves the sheet.
 *
 * - Where a drag may start (canStart): on the handle / header area, or on the content while it (and every scroll box
 *   the finger is in) is scrolled to its top. Never in a textarea that can scroll or holds a selection, never in a
 *   field that takes the finger itself (input, select, contenteditable). On the handle never on a control either
 *   (button, link, label): the handle captures the pointer, so a tap there must stay that control's tap. In the
 *   content a button or link does not stop a drag: the drag starts only after the slop, and once the browser's
 *   touchmove is taken a tap is no tap any more (the achievement medal and the tier rows are buttons; README G2
 *   "start on content scrolled to its top", as the achievement sheet did before).
 * - When it starts (SheetDrag#move): after DRAG_SLOP px of mostly vertical, downward movement (|dx| ≤ 0.5 dy); a first
 *   move sideways or upwards leaves the whole gesture to the browser. The sheet then follows the finger, DRAG_SLOP px
 *   behind it, never above its resting place. The pull is measured from where the finger went down: browsers hold back
 *   the touchmoves inside their own touch slop (Chrome's first one comes at ~15 px), so the first move seen may be
 *   past the slop - the sheet catches up with the finger instead of losing that distance.
 * - How it ends (SheetDrag#release / releaseDecision): 'close' when the finger travelled more than CLOSE_DISTANCE px,
 *   past half the sheet's height, or flicked faster than FLICK_VELOCITY px/ms (over the last VELOCITY_WINDOW_MS) after
 *   more than FLICK_MIN px; otherwise 'spring' (back in SPRING_MS). A gesture that never became a drag ends with 'none'.
 *   The thresholds are the finger's travel from where it went down (README G2: a 100 px pull closes), not the sheet's
 *   offset, which trails the finger by the slop (Layers review F8).
 */

/** The sheets are bottom sheets only on phones (the same breakpoint as Bootstrap's sm) */
export const SHEET_PHONE_MEDIA = '(max-width: 575.98px)';

/** px of movement before a drag starts */
export const DRAG_SLOP = 8;

/** Mostly vertical: |dx| ≤ VERTICAL_RATIO × dy */
export const VERTICAL_RATIO = 0.5;

/** A pull this far (px) closes the sheet */
export const CLOSE_DISTANCE = 96;

/** A flick this fast (px/ms) closes it ... */
export const FLICK_VELOCITY = 0.6;

/** ... after at least this pull (px) */
export const FLICK_MIN = 24;

/** The flick speed is measured over the last ~100 ms of the drag */
export const VELOCITY_WINDOW_MS = 100;

/** Spring back after a short pull (ms); none under reduced motion */
export const SPRING_MS = 200;

/** Closing slide after a swipe (ms), the sheets' "out" duration; none under reduced motion */
export const OUT_MS = 200;

/** The sheets' easing (in 300 ms, out 200 ms - assets/styles/_bottom-sheet.scss) */
export const SHEET_EASING = 'cubic-bezier(.2, .8, .2, 1)';

// Controls that keep their own tap on the handle (it captures the pointer)
const HANDLE_CONTROLS = new Set(['BUTTON', 'A', 'INPUT', 'SELECT', 'TEXTAREA', 'LABEL', 'SUMMARY']);

// Fields that take the finger themselves anywhere in the sheet (a range slider, a select, typing)
const FIELDS = new Set(['INPUT', 'SELECT']);

function tagOf(element) {
    return String(element.tagName || element.localName || '').toUpperCase();
}

function isEditable(element) {
    if (element.isContentEditable === true) {
        return true;
    }

    const value = typeof element.getAttribute === 'function' ? element.getAttribute('contenteditable') : null;

    return value !== null && value !== 'false';
}

/** A textarea the finger is meant for: it scrolls, or text in it is selected */
export function textareaTakesTheFinger(textarea) {
    if ((textarea.scrollHeight ?? 0) > (textarea.clientHeight ?? 0)) {
        return true;
    }

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;

    return typeof start === 'number' && typeof end === 'number' && start !== end;
}

/**
 * May a drag start where the finger went down?
 *
 * @param {Element} target the element under the finger
 * @param {{zone: 'handle'|'body', root: Element}} env zone = the handle / header area or the sheet's content;
 *        root = that area's element (the walk from the target up stops there, inclusive)
 */
export function canStart(target, env) {
    const zone = env?.zone === 'handle' ? 'handle' : 'body';
    const root = env?.root ?? null;
    let element = target ?? null;

    while (element) {
        const tag = tagOf(element);

        if (tag === 'TEXTAREA' && textareaTakesTheFinger(element)) {
            return false;
        }

        if (FIELDS.has(tag) || isEditable(element)) {
            return false;
        }

        if (zone === 'handle' && HANDLE_CONTROLS.has(tag)) {
            return false;
        }

        // Content scrolled away from its top (the sheet's body or a scroll box inside it): the finger scrolls it back
        if (zone === 'body' && (element.scrollTop ?? 0) > 0) {
            return false;
        }

        if (element === root) {
            return true;
        }

        element = element.parentElement ?? null;
    }

    // The walk left the area without passing its root: not a drag of this sheet
    return root === null;
}

/**
 * A mouse that moves with no button held: its pointerup happened outside the handle before the drag captured it (a
 * mouse has no implicit capture), so the gesture is over - never drag the sheet on a hover (Layers review F7).
 *
 * @param {{pointerType?: string, buttons?: number}} event a pointermove
 */
export function pointerReleased(event) {
    return event?.pointerType === 'mouse' && event.buttons === 0;
}

/**
 * The end of a drag: 'close' or 'spring'.
 *
 * @param {{travel?: number, offset?: number, velocity: number, sheetHeight?: number}} drag travel = how far the finger
 *        went down from where it started (px; the thresholds of README G2), offset = how far the sheet was pulled
 *        (it trails the finger by DRAG_SLOP; used only when no travel is given), velocity = px/ms downwards over the
 *        last VELOCITY_WINDOW_MS, sheetHeight = the sheet's height (px)
 */
export function releaseDecision({ travel, offset = 0, velocity, sheetHeight = 0 }) {
    const pulled = typeof travel === 'number' ? travel : offset + DRAG_SLOP;

    if (pulled > CLOSE_DISTANCE) {
        return 'close';
    }

    if (sheetHeight > 0 && pulled > sheetHeight / 2) {
        return 'close';
    }

    if (velocity > FLICK_VELOCITY && pulled > FLICK_MIN) {
        return 'close';
    }

    return 'spring';
}

/**
 * One gesture: start → move … → release (or cancel). States: 'idle', 'pending' (finger down, inside the slop),
 * 'dragging', 'ignored' (not a drag of the sheet: started where it may not, or moved sideways / upwards first).
 */
export class SheetDrag {
    constructor() {
        this.reset();
    }

    reset() {
        this.state = 'idle';
        this.startX = 0;
        this.startY = 0;
        this.offset = 0;
        this.travel = 0;
        this.samples = [];
    }

    /** Finger down at (x, y) at time t (ms); allowed = canStart(…) */
    start(x, y, t, allowed = true) {
        this.reset();
        this.state = allowed ? 'pending' : 'ignored';
        this.startX = x;
        this.startY = y;
    }

    get dragging() {
        return this.state === 'dragging';
    }

    /**
     * The finger moved: {dragging, offset, started, prevent} - prevent = the caller takes this move from the browser
     * (preventDefault on a cancelable touchmove), only once the drag has started.
     */
    move(x, y, t) {
        if (this.state === 'pending') {
            const dx = x - this.startX;
            const dy = y - this.startY;

            if (Math.abs(dx) < DRAG_SLOP && Math.abs(dy) < DRAG_SLOP) {
                return { dragging: false, started: false, offset: 0, prevent: false };
            }

            if (dy < DRAG_SLOP || Math.abs(dx) > VERTICAL_RATIO * dy) {
                this.state = 'ignored';

                return { dragging: false, started: false, offset: 0, prevent: false };
            }

            this.state = 'dragging';
            this.travel = Math.max(0, y - this.startY);
            this.offset = this.pull(y);
            this.samples = [{ y, t }];

            return { dragging: true, started: true, offset: this.offset, prevent: true };
        }

        if (this.state !== 'dragging') {
            return { dragging: false, started: false, offset: 0, prevent: false };
        }

        this.travel = Math.max(0, y - this.startY);
        this.offset = this.pull(y);
        this.samples.push({ y, t });
        this.prune(t);

        return { dragging: true, started: false, offset: this.offset, prevent: true };
    }

    /** How far the sheet is pulled with the finger at y: the finger's travel less the slop, never above 0 */
    pull(y) {
        return Math.max(0, y - this.startY - DRAG_SLOP);
    }

    prune(now) {
        while (this.samples.length > 2 && now - this.samples[1].t >= VELOCITY_WINDOW_MS) {
            this.samples.shift();
        }
    }

    /** Downward speed (px/ms) over the last VELOCITY_WINDOW_MS before `now` (the release time) */
    velocity(now) {
        const samples = this.samples.filter((sample) => now - sample.t <= VELOCITY_WINDOW_MS);

        if (samples.length < 2) {
            return 0;
        }

        const first = samples[0];
        const last = samples[samples.length - 1];
        const dt = last.t - first.t;

        return dt > 0 ? (last.y - first.y) / dt : 0;
    }

    /** Finger up at time t (ms; default: the last move): 'close' | 'spring' | 'none' (it never became a drag) */
    release(sheetHeight = 0, t = null) {
        if (this.state !== 'dragging') {
            this.reset();

            return 'none';
        }

        const now = t ?? (this.samples.length > 0 ? this.samples[this.samples.length - 1].t : 0);
        const decision = releaseDecision({ travel: this.travel, velocity: this.velocity(now), sheetHeight });
        this.reset();

        return decision;
    }

    /** The browser took the gesture (pointercancel / touchcancel): a drag springs back */
    cancel() {
        const wasDragging = this.state === 'dragging';
        this.reset();

        return wasDragging ? 'spring' : 'none';
    }
}
