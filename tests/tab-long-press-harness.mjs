// Runs assets/tab_long_press.js (long-press a cut tab, docs/features/navigation README §4.13 G16, GEST-18) through the
// cases tests/TabLongPressScriptTest.php asks for and prints one JSON object {case: answer}. Timers are driven by hand:
// a fake clock runs the injected setTimer callbacks when the test advances it.

import {
    BUBBLE_GAP_PX,
    BUBBLE_ID,
    bubblePosition,
    CLICK_WINDOW_MS,
    EDGE_PX,
    HIDE_AFTER_MS,
    HOLD_MS,
    isTruncated,
    LongPress,
    longPressEnabled,
    longPressEnabledFor,
    MOVE_TOLERANCE_PX,
    wantsTitle,
} from '../assets/tab_long_press.js';

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

/** A long press on a fake clock: returns the press, the clock and the log of shows / hides with their times */
function pressOnAClock() {
    let now = 0;
    let nextId = 1;
    const timers = new Map();
    const log = [];
    const press = new LongPress({
        setTimer: (fn, ms) => {
            const id = nextId++;
            timers.set(id, { at: now + ms, fn });

            return id;
        },
        clearTimer: (id) => timers.delete(id),
        onShow: () => log.push(['show', now]),
        onHide: () => log.push(['hide', now]),
    });
    const advance = (ms) => {
        const until = now + ms;

        for (;;) {
            const due = [...timers.entries()].filter(([, timer]) => timer.at <= until).sort((a, b) => a[1].at - b[1].at)[0];

            if (!due) {
                break;
            }

            timers.delete(due[0]);
            now = due[1].at;
            due[1].fn();
        }

        now = until;
    };

    return { press, advance, log, pending: () => timers.size };
}

/** A fake <html> with the platform flags (assets/platform.js reads getAttribute) */
function root(attributes) {
    return { getAttribute: (name) => (name in attributes ? attributes[name] : null), classList: { contains: () => false } };
}

const results = Object.fromEntries([
    run('constants', () => ({ HOLD_MS, MOVE_TOLERANCE_PX, HIDE_AFTER_MS, BUBBLE_GAP_PX, EDGE_PX, CLICK_WINDOW_MS, BUBBLE_ID })),

    // Held 500 ms: the bubble; the release answers "drop the click", the bubble goes
    run('held 550 ms, then released', () => {
        const { press, advance, log, pending } = pressOnAClock();
        press.down({ x: 100, y: 700 });
        advance(499);
        const before = log.length;
        advance(51);
        const drop = press.up();

        return { shownAt: log[0] ?? null, beforeHold: before, drop, log, timersLeft: pending(), state: press.state };
    }),
    // A shorter tap: no bubble, the click navigates
    run('a tap of 150 ms', () => {
        const { press, advance, log, pending } = pressOnAClock();
        press.down({ x: 100, y: 700 });
        advance(150);
        const drop = press.up();
        advance(2000);

        return { drop, log, timersLeft: pending() };
    }),
    // Moved over 10 px while holding: a drag, never a bubble
    run('moved 20 px, then held on', () => {
        const { press, advance, log } = pressOnAClock();
        press.down({ x: 100, y: 700 });
        advance(100);
        press.move({ x: 120, y: 700 });
        advance(1000);

        return { drop: press.up(), log };
    }),
    run('moved 10 px exactly (not more)', () => {
        const { press, advance, log } = pressOnAClock();
        press.down({ x: 100, y: 700 });
        press.move({ x: 106, y: 708 });
        advance(HOLD_MS);

        return { shown: log.length === 1, drop: press.up() };
    }),
    // Held on: the bubble goes after 1.5 s by itself; the release still does not navigate
    run('held 3 s', () => {
        const { press, advance, log } = pressOnAClock();
        press.down({ x: 100, y: 700 });
        advance(3000);

        return { log, drop: press.up(), logAfterRelease: log.length };
    }),
    // Moving after the bubble showed changes nothing
    run('the bubble shown, then moved 40 px', () => {
        const { press, advance, log } = pressOnAClock();
        press.down({ x: 100, y: 700 });
        advance(600);
        press.move({ x: 140, y: 700 });

        return { log, drop: press.up() };
    }),
    // pointercancel: the browser took the touch over
    run('cancelled while holding / after showing', () => {
        const holding = pressOnAClock();
        holding.press.down({ x: 0, y: 0 });
        holding.advance(200);
        holding.press.cancel();
        holding.advance(1000);

        const shown = pressOnAClock();
        shown.press.down({ x: 0, y: 0 });
        shown.advance(600);
        shown.press.cancel();

        return { holding: holding.log, shown: shown.log, shownState: shown.press.state, shownUp: shown.press.up() };
    }),
    // A second press restarts cleanly
    run('two presses in a row', () => {
        const { press, advance, log } = pressOnAClock();
        press.down({ x: 0, y: 0 });
        advance(300);
        press.down({ x: 0, y: 0 });
        advance(300);
        const early = log.length;
        advance(200);

        return { early, log };
    }),

    // Which tabs are cut
    run('isTruncated', () => ({
        step3NotCurrent: isTruncated({ step: 3, current: false, scrollWidth: 1, clientWidth: 1 }),
        step3Current: isTruncated({ step: 3, current: true, scrollWidth: 40, clientWidth: 40 }),
        step3AsString: isTruncated({ step: '3', current: false }),
        step1Fits: isTruncated({ step: 1, current: false, scrollWidth: 40, clientWidth: 40 }),
        step2Wider: isTruncated({ step: 2, current: false, scrollWidth: 52, clientWidth: 40 }),
        step1CurrentWider: isTruncated({ step: 1, current: true, scrollWidth: 52, clientWidth: 40 }),
        nothingKnown: isTruncated({}),
        noArgument: isTruncated(),
    })),

    // Where the bubble applies
    run('longPressEnabled', () => ({
        androidTouch: longPressEnabled({ os: 'android', nativeApp: false, pointerType: 'touch' }),
        iosTouch: longPressEnabled({ os: 'ios', nativeApp: false, pointerType: 'touch' }),
        iosPen: longPressEnabled({ os: 'ios', nativeApp: false, pointerType: 'pen' }),
        androidMouse: longPressEnabled({ os: 'android', nativeApp: false, pointerType: 'mouse' }),
        desktopTouch: longPressEnabled({ os: 'other', nativeApp: false, pointerType: 'touch' }),
        nativeApp: longPressEnabled({ os: 'android', nativeApp: true, pointerType: 'touch' }),
        nothing: longPressEnabled(),
        forAnAndroidBrowser: longPressEnabledFor(root({ 'data-os': 'android' }), 'touch'),
        forTheApp: longPressEnabledFor(root({ 'data-os': 'android', 'data-native-app': 'android' }), 'touch'),
    })),
    run('wantsTitle', () => ({
        desktopCut: wantsTitle({ os: 'other', nativeApp: false, truncated: true }),
        desktopFits: wantsTitle({ os: 'other', nativeApp: false, truncated: false }),
        androidCut: wantsTitle({ os: 'android', nativeApp: false, truncated: true }),
        iosCut: wantsTitle({ os: 'ios', nativeApp: false, truncated: true }),
        appCut: wantsTitle({ os: 'other', nativeApp: true, truncated: true }),
    })),

    // The bubble's place: centred, 12 px above, 8 px from the edges
    run('bubblePosition', () => ({
        centred: bubblePosition({ tab: { left: 144, top: 744, width: 72 }, bubble: { width: 100, height: 30 }, viewportWidth: 360 }),
        leftEdge: bubblePosition({ tab: { left: 0, top: 744, width: 72 }, bubble: { width: 120, height: 30 }, viewportWidth: 360 }),
        rightEdge: bubblePosition({ tab: { left: 288, top: 744, width: 72 }, bubble: { width: 120, height: 30 }, viewportWidth: 360 }),
        widerThanTheScreen: bubblePosition({ tab: { left: 144, top: 744, width: 72 }, bubble: { width: 400, height: 30 }, viewportWidth: 360 }),
    })),
]);

process.stdout.write(JSON.stringify(results));
