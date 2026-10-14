// Runs assets/press_feedback.js (Android's ripple, docs/features/navigation README §4.13 G11, GEST-11) through the cases
// tests/PressFeedbackScriptTest.php asks for and prints one JSON object {case: answer}. One press's timeline runs on a
// fake clock: the injected setTimer callbacks run when the case advances it.

import {
    CONTENT_DELAY_MS,
    COVER_MS,
    delayFor,
    FADE_ANIMATION,
    FADE_MS,
    fadeStartAt,
    FADING_CLASS,
    HOLD_MIN_MS,
    HOST_CLASS,
    MOVE_TOLERANCE_PX,
    movedTooFar,
    PRESS_SELECTOR,
    RIPPLE_CLASS,
    rippleEnabled,
    rippleGeometry,
    RipplePress,
    START_FRACTION,
    SURFACE_ATTRIBUTE,
    SURFACE_SELECTOR,
} from '../assets/press_feedback.js';

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

/** A press on a fake clock; the log holds [event, time] */
function pressOnAClock(marker) {
    let now = 0;
    let nextId = 1;
    const timers = new Map();
    const log = [];
    const press = new RipplePress({
        delay: delayFor(marker),
        origin: { x: 50, y: 20 },
        now: () => now,
        setTimer: (fn, ms) => {
            const id = nextId++;
            timers.set(id, { at: now + ms, fn });

            return id;
        },
        clearTimer: (id) => timers.delete(id),
        onStart: () => log.push(['start', now]),
        onFade: () => log.push(['fade', now]),
        onCancel: () => log.push(['cancel', now]),
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

    return { press, advance, log, timersLeft: () => timers.size };
}

const results = Object.fromEntries([
    run('constants', () => ({
        COVER_MS,
        HOLD_MIN_MS,
        FADE_MS,
        CONTENT_DELAY_MS,
        MOVE_TOLERANCE_PX,
        START_FRACTION,
        PRESS_SELECTOR,
        HOST_CLASS,
        RIPPLE_CLASS,
        FADING_CLASS,
        SURFACE_SELECTOR,
        FADE_ANIMATION,
        SURFACE_ATTRIBUTE,
    })),

    // Who ripples: Android only (the app included - decision 62), never under reduced motion
    run('rippleEnabled', () => ({
        android: rippleEnabled({ os: 'android', reducedMotion: false }),
        androidReduced: rippleEnabled({ os: 'android', reducedMotion: true }),
        ios: rippleEnabled({ os: 'ios', reducedMotion: false }),
        desktop: rippleEnabled({ os: 'other', reducedMotion: false }),
        nothing: rippleEnabled(),
    })),

    // The delay by context: the bars at once, content after 150 ms, cards never
    run('delayFor', () => ({ fixed: delayFor('fixed'), content: delayFor(''), layer: delayFor('layer'), none: delayFor(null), junk: delayFor('sticky') })),

    // Cancel by distance
    run('movedTooFar', () => ({
        still: movedTooFar({ x: 0, y: 0 }, { x: 0, y: 0 }),
        tenExactly: movedTooFar({ x: 0, y: 0 }, { x: 6, y: 8 }),
        eleven: movedTooFar({ x: 0, y: 0 }, { x: 11, y: 0 }),
        twentyDown: movedTooFar({ x: 0, y: 0 }, { x: 0, y: 20 }),
    })),

    // The fade start: the release, never before 225 ms of ripple
    run('fadeStartAt', () => ({
        quickTap: fadeStartAt({ startedAt: 0, releasedAt: 40 }),
        held: fadeStartAt({ startedAt: 0, releasedAt: 600 }),
        releasedAtTheStart: fadeStartAt({ startedAt: 150, releasedAt: 150 }),
    })),

    // The circle covers the surface from the touch point; it starts at 20 % of the larger side
    run('rippleGeometry', () => ({
        tabPillCentre: rippleGeometry({ width: 56, height: 32, x: 28, y: 16 }),
        rowCorner: rippleGeometry({ width: 300, height: 48, x: 0, y: 0 }),
        rowMiddle: rippleGeometry({ width: 300, height: 48, x: 150, y: 24 }),
        empty: rippleGeometry({ width: 0, height: 0, x: 0, y: 0 }),
    })),

    // A fixed bar control: the ripple at once; released at 100 ms → fade at 225 ms (held long enough), nothing left
    run('fixed: quick tap', () => {
        const { press, advance, log, timersLeft } = pressOnAClock('fixed');
        press.begin();
        advance(100);
        press.release();
        advance(1000);

        return { log, timersLeft: timersLeft() };
    }),
    run('fixed: held 600 ms', () => {
        const { press, advance, log } = pressOnAClock('fixed');
        press.begin();
        advance(600);
        press.release();
        advance(1000);

        return log;
    }),
    // Content: only after 150 ms held
    run('content: held 400 ms', () => {
        const { press, advance, log } = pressOnAClock('');
        press.begin();
        advance(149);
        const before = log.length;
        advance(251);
        press.release();
        advance(1000);

        return { before, log };
    }),
    // Content: a quick tap still gets a short ripple, from the release
    run('content: tap of 60 ms', () => {
        const { press, advance, log } = pressOnAClock('');
        press.begin();
        advance(60);
        press.release();
        advance(1000);

        return log;
    }),
    // A move over 10 px before the delay: never a ripple (the touch became a scroll)
    run('content: moved 20 px at 50 ms', () => {
        const { press, advance, log, timersLeft } = pressOnAClock('');
        press.begin();
        advance(50);
        press.move({ x: 50, y: 40 });
        advance(1000);
        press.release();
        advance(1000);

        return { log, timersLeft: timersLeft() };
    }),
    // A move over 10 px after the start: the ripple goes at once
    run('fixed: moved 20 px at 100 ms', () => {
        const { press, advance, log } = pressOnAClock('fixed');
        press.begin();
        advance(100);
        press.move({ x: 70, y: 20 });
        press.release();
        advance(1000);

        return log;
    }),
    // A small move is still a press
    run('fixed: moved 8 px', () => {
        const { press, advance, log } = pressOnAClock('fixed');
        press.begin();
        press.move({ x: 58, y: 20 });
        advance(300);
        press.release();
        advance(1000);

        return log;
    }),
    // pointercancel: before the start nothing, after it the ripple goes
    run('pointercancel', () => {
        const early = pressOnAClock('');
        early.press.begin();
        early.advance(100);
        early.press.cancel();
        early.advance(1000);

        const late = pressOnAClock('fixed');
        late.press.begin();
        late.advance(100);
        late.press.cancel();
        late.press.release();
        late.advance(1000);

        return { early: early.log, late: late.log, earlyTimers: early.timersLeft() };
    }),
]);

process.stdout.write(JSON.stringify(results));
