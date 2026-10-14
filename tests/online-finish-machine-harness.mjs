// Runs assets/online/finish_machine.js - the finish rule: hands-up signals in, finish candidates out - through
// synthetic signal sequences and prints {scenario: "ok" | error} as JSON. tests/OnlineFinishMachineScriptTest.php
// asserts every one is "ok".
//
// The decisions these scenarios pin (each a tunable - the reasons are in the module's header):
// - a frame gap > maxGapMs (1.5 s; the pacing never goes below 1 fps) restarts a hold after it (`gapPolicy: 'restart'`;
//   'bridge' keeps it) and ends a raise that already is a candidate;
// - up to maxDropFrames (1) frames down inside a raise are a flicker if the raised frames around them are at most
//   maxDropMs (0.7 s) apart - the hold counts on from the first raised frame;
// - a raise that began inside the cooldown becomes a candidate once held past its end, at its own first raised frame
//   (`straddle: 'whole'`; 'from_end' / 'suppress'); one that ends inside it is reported once (`raise_suppressed`).

import assert from 'node:assert/strict';
import {
    COOLDOWN_FROM_RAISE_END,
    createFinishMachine,
    DEFAULT_RULES,
    FINISH_MACHINE_VERSION,
    GAP_BRIDGE,
    MODE_OFF,
    MODE_ON,
    MODE_SHADOW,
    PHASE_HOLDING,
    PHASE_IDLE,
    PHASE_RAISED,
    resolveRules,
    STRADDLE_FROM_END,
    STRADDLE_SUPPRESS,
} from '../assets/online/finish_machine.js';

// A round's clock: frames carry synced server milliseconds
const T = 1_792_000_000_000;

/**
 * Frames every `stepMs` from `fromMs` to `toMs` (relative to T), raised inside any of the `up` intervals [a, b]
 * (inclusive), with an optional score and frames to leave out (`drop` - relative times that are not raised).
 */
function sequence({ fromMs = 0, toMs, stepMs = 200, up = [], drop = [], skip = [], score = 0.9 }) {
    const frames = [];

    for (let at = fromMs; at <= toMs; at += stepMs) {
        if (skip.some(([a, b]) => at >= a && at <= b)) {
            continue;
        }

        const raised = up.some(([a, b]) => at >= a && at <= b) && !drop.includes(at);
        frames.push({ captureMs: T + at, raised, score: raised ? score : 0 });
    }

    return frames;
}

/**
 * Every event of the frames, each with the frame (relative ms) that produced it; moments made relative.
 */
function run(machine, frames) {
    const events = [];

    for (const frame of frames) {
        for (const event of machine.push(frame)) {
            events.push(relative({ ...event, frameMs: (frame.captureMs ?? frame.captureTimeMs) - T }));
        }
    }

    return events;
}

function relative(event) {
    const copy = { ...event };

    for (const key of ['atMs', 'detectedAtMs', 'windowFromMs', 'windowToMs', 'toMs', 'fromMs', 'untilMs']) {
        if (Number.isFinite(copy[key])) {
            copy[key] -= T;
        }
    }

    return copy;
}

const of = (events, type) => events.filter((event) => event.type === type);

const scenarios = {
    'a clean raise: one candidate at the first raised frame, once held 0.5 s - the window closes when the hands go down'() {
        const machine = createFinishMachine();
        const events = run(machine, sequence({ toMs: 6000, up: [[2000, 3600]] }));
        const [candidate] = of(events, 'candidate');

        assert.equal(of(events, 'candidate').length, 1);
        assert.equal(candidate.atMs, 2000, 'the capture time of the first raised frame');
        assert.equal(candidate.detectedAtMs, 2600, 'the first frame at least 500 ms after it (2000, 2200, 2400 are not enough)');
        assert.equal(candidate.frameMs, 2600);
        assert.equal(candidate.heldMs, 600);
        assert.deepEqual([candidate.windowFromMs, candidate.windowToMs], [2000, 2600], 'the window so far');
        assert.equal(candidate.upFrames, 4);
        assert.equal(candidate.id, 1);
        assert.equal(candidate.mode, MODE_ON);
        assert.equal(candidate.shadow, false);
        assert.equal(candidate.version, FINISH_MACHINE_VERSION);
        assert.equal(candidate.score, 0.9);

        const [end] = of(events, 'raise_end');
        assert.equal(end.candidateId, 1);
        assert.deepEqual([end.windowFromMs, end.windowToMs], [2000, 3600], 'the whole raised window - where the sharpest photo is');
        assert.equal(end.endReason, 'lowered');
        assert.equal(end.frameMs, 4000, 'the raise ends at the second frame down (one is tolerated)');
        assert.equal(of(events, 'raise_short').length, 0);
        assert.equal(of(events, 'gap').length, 0);
    },

    'held exactly 0.5 s is enough; a 0.4 s raise is no candidate - it is reported as short'() {
        const exactly = run(createFinishMachine(), sequence({ toMs: 3000, stepMs: 100, up: [[1000, 1500]] }));
        assert.equal(of(exactly, 'candidate').length, 1);
        assert.equal(of(exactly, 'candidate')[0].detectedAtMs, 1500);

        const machine = createFinishMachine();
        const events = run(machine, sequence({ toMs: 3000, up: [[1000, 1400]] }));

        assert.equal(of(events, 'candidate').length, 0);
        const [short] = of(events, 'raise_short');
        assert.deepEqual([short.atMs, short.toMs, short.heldMs, short.upFrames], [1000, 1400, 400, 3]);
        assert.equal(short.endReason, 'lowered');
        assert.equal(machine.state().candidates, 0);
    },

    'a single raised frame (a false positive) never makes a candidate'() {
        const events = run(createFinishMachine(), sequence({ toMs: 5000, up: [[1000, 1000], [3000, 3000]] }));

        assert.equal(of(events, 'candidate').length, 0);
        assert.equal(of(events, 'raise_short').length, 2);
        assert.ok(of(events, 'raise_short').every((short) => short.heldMs === 0));
    },

    'a flickering raise: single dropped frames inside it are tolerated - the moment stays the first raised frame'() {
        const machine = createFinishMachine();
        const events = run(machine, sequence({ toMs: 6000, up: [[2000, 3800]], drop: [2200, 2600, 3000, 3400] }));
        const [candidate] = of(events, 'candidate');

        assert.equal(of(events, 'candidate').length, 1);
        assert.equal(candidate.atMs, 2000);
        assert.equal(candidate.detectedAtMs, 2800, 'held 0.5 s counts over the tolerated drops - detected on a raised frame');
        assert.equal(candidate.droppedFrames, 2);
        assert.equal(of(events, 'raise_end')[0].droppedFrames, 4);
        assert.equal(of(events, 'raise_end')[0].windowToMs, 3800);
        assert.equal(of(events, 'raise_short').length, 0);
    },

    'two frames down in a row end the raise - the next raised frame starts a new hold'() {
        const machine = createFinishMachine();
        // Raised 1000-1200, down 1400 + 1600, raised again 1800-2600
        const events = run(machine, sequence({ toMs: 4000, up: [[1000, 1200], [1800, 2600]] }));

        assert.equal(of(events, 'raise_short').length, 1);
        assert.deepEqual([of(events, 'raise_short')[0].atMs, of(events, 'raise_short')[0].toMs], [1000, 1200]);
        assert.equal(of(events, 'candidate')[0].atMs, 1800);
        assert.equal(of(events, 'candidate')[0].detectedAtMs, 2400);

        // maxDropFrames 2 bridges them
        const lenient = run(createFinishMachine({ maxDropFrames: 2 }), sequence({ toMs: 4000, up: [[1000, 1200], [1800, 2600]] }));
        assert.equal(of(lenient, 'candidate')[0].atMs, 1000);
        assert.equal(of(lenient, 'candidate')[0].detectedAtMs, 1800);

        // maxDropFrames 0: every flicker ends the raise
        const strict = run(createFinishMachine({ maxDropFrames: 0 }), sequence({ toMs: 4000, up: [[1000, 2400]], drop: [1400] }));
        assert.equal(of(strict, 'candidate')[0].atMs, 1600);
    },

    'at a low frame rate one dropped frame is not a flicker: the raised frames around it are more than maxDropMs apart'() {
        // 2 fps: raised 0 and 1000, down 500 - one second unseen
        const events = run(createFinishMachine(), sequence({ toMs: 4000, stepMs: 500, up: [[0, 2500]], drop: [500] }));

        assert.equal(of(events, 'raise_short').length, 1, 'the raise at 0 ended where it was last seen');
        assert.equal(of(events, 'candidate')[0].atMs, 1000);
        assert.equal(of(events, 'candidate')[0].detectedAtMs, 1500);

        // A drop longer than maxDropMs ends the raise at once (no need to wait for the next frame)
        const machine = createFinishMachine({ maxDropMs: 300 });
        machine.push({ captureMs: T, raised: true });
        machine.push({ captureMs: T + 200, raised: true });
        const ended = machine.push({ captureMs: T + 600, raised: false });
        assert.equal(ended[0].type, 'raise_short');
        assert.equal(machine.state().phase, PHASE_IDLE);
    },

    'at 1 fps two raised frames a second apart make a candidate; one raised frame does not'() {
        const slow = run(createFinishMachine(), sequence({ toMs: 6000, stepMs: 1000, up: [[2000, 3000]] }));
        assert.equal(of(slow, 'candidate').length, 1);
        assert.deepEqual([of(slow, 'candidate')[0].atMs, of(slow, 'candidate')[0].detectedAtMs], [2000, 3000]);

        const once = run(createFinishMachine(), sequence({ toMs: 6000, stepMs: 1000, up: [[2000, 2500]] }));
        assert.equal(of(once, 'candidate').length, 0);
    },

    'a premature raise, then the real one after the cooldown: two candidates, each at its own first raised frame'() {
        const machine = createFinishMachine();
        // Premature at 10 s (a piece missing), the real finish at 31 s
        const events = run(machine, sequence({ toMs: 40_000, up: [[10_000, 11_000], [31_000, 32_400]] }));
        const candidates = of(events, 'candidate');

        assert.equal(candidates.length, 2);
        assert.deepEqual(candidates.map((candidate) => candidate.atMs), [10_000, 31_000]);
        assert.deepEqual(candidates.map((candidate) => candidate.id), [1, 2]);
        assert.ok(candidates.every((candidate) => candidate.straddled === false));
        assert.equal(of(events, 'raise_end').length, 2);
        assert.equal(machine.state().candidates, 2);
        assert.equal(machine.state().lastCandidate.atMs, T + 31_000);
    },

    'a raise inside the cooldown that ends inside it is no candidate - reported once as suppressed'() {
        const machine = createFinishMachine();
        // Candidate at 10 s; a celebration's second wave at 13-15 s
        const events = run(machine, sequence({ toMs: 30_000, up: [[10_000, 11_000], [13_000, 15_000]] }));

        assert.equal(of(events, 'candidate').length, 1);
        const suppressed = of(events, 'raise_suppressed');
        assert.equal(suppressed.length, 1, 'once per raise, not per frame');
        assert.deepEqual([suppressed[0].atMs, suppressed[0].untilMs, suppressed[0].reason], [13_000, 20_000, 'cooldown']);
        assert.equal(suppressed[0].frameMs, 13_600, 'when it had been held 0.5 s');
        assert.equal(of(events, 'raise_short').length, 0, 'a suppressed raise ends quietly');
    },

    'a raise straddling the cooldown (default "whole"): a candidate once held past the end - at its own first raised frame'() {
        const machine = createFinishMachine();
        // Candidate at 10 s (cooldown until 20 s); the participant fixed the gap and raised at 18 s, hands up until 21 s
        const events = run(machine, sequence({ toMs: 25_000, up: [[10_000, 11_000], [18_000, 21_000]] }));
        const candidates = of(events, 'candidate');

        assert.equal(candidates.length, 2);
        assert.equal(candidates[1].atMs, 18_000, 'the moment is when the hands went up');
        assert.equal(candidates[1].detectedAtMs, 20_000, 'known once the cooldown is over');
        assert.equal(candidates[1].straddled, true);
        assert.equal(of(events, 'raise_suppressed').length, 1, 'the page could say "keep them up" meanwhile');
        assert.equal(machine.state().cooldownUntilMs, T + 28_000, 'the next cooldown counts from its moment');
    },

    'a straddling raise under "from_end" takes the first raised frame after the cooldown; under "suppress" none'() {
        const frames = sequence({ toMs: 30_000, up: [[10_000, 11_000], [18_000, 21_000]] });
        const fromEnd = run(createFinishMachine({ straddle: STRADDLE_FROM_END }), frames);

        assert.equal(of(fromEnd, 'candidate').length, 2);
        assert.equal(of(fromEnd, 'candidate')[1].atMs, 20_000);
        assert.equal(of(fromEnd, 'candidate')[1].detectedAtMs, 20_600);

        const suppress = run(createFinishMachine({ straddle: STRADDLE_SUPPRESS }), frames);
        assert.equal(of(suppress, 'candidate').length, 1);
        assert.equal(of(suppress, 'raise_suppressed').length, 1);

        // A raise that begins after the cooldown is a candidate under every policy
        for (const straddle of ['whole', STRADDLE_FROM_END, STRADDLE_SUPPRESS]) {
            const later = run(createFinishMachine({ straddle }), sequence({ toMs: 30_000, up: [[10_000, 11_000], [21_000, 22_000]] }));
            assert.equal(of(later, 'candidate').length, 2, straddle);
            assert.equal(of(later, 'candidate')[1].atMs, 21_000, straddle);
        }
    },

    'hands up for 15 s are one raise and one candidate - the cooldown passing does not cut it in two'() {
        const machine = createFinishMachine();
        const events = run(machine, sequence({ toMs: 20_000, up: [[1000, 16_000]] }));

        assert.equal(of(events, 'candidate').length, 1);
        assert.equal(of(events, 'raise_end')[0].windowToMs, 16_000);
        assert.equal(of(events, 'raise_suppressed').length, 0);
    },

    'cooldownFrom "raise_end": the 10 s count from when the hands went down'() {
        const machine = createFinishMachine({ cooldownFrom: COOLDOWN_FROM_RAISE_END });
        // Raised 0-5 s, again at 12 s (12 s after the candidate, 7 s after the hands went down) and at 16 s
        const events = run(machine, sequence({ toMs: 20_000, up: [[0, 5000], [12_000, 13_000], [16_000, 17_000]] }));

        assert.equal(of(events, 'candidate').length, 2);
        assert.deepEqual(of(events, 'candidate').map((candidate) => candidate.atMs), [0, 16_000]);
        assert.equal(of(events, 'raise_suppressed')[0].untilMs, 15_000);
    },

    'a gap while holding restarts the hold after it - the candidate rests on frames that were seen'() {
        const machine = createFinishMachine();
        // Raised from 1000; frames stop between 1400 and 3600 (tab hidden); still raised after it
        const events = run(machine, sequence({ toMs: 6000, up: [[1000, 5000]], skip: [[1500, 3500]] }));
        const [gap] = of(events, 'gap');

        assert.deepEqual([gap.fromMs, gap.toMs, gap.gapMs], [1400, 3600, 2200]);
        assert.equal(gap.frameMs, 3600);
        assert.equal(of(events, 'raise_short')[0].endReason, 'gap');
        assert.equal(of(events, 'candidate')[0].atMs, 3600, 'the first raised frame after the gap');
        assert.equal(of(events, 'candidate')[0].detectedAtMs, 4200);
        assert.equal(machine.state().gaps, 1);
    },

    'gapPolicy "bridge" keeps the hold across the gap; a gap after the candidate always ends its raise'() {
        const bridged = run(createFinishMachine({ gapPolicy: GAP_BRIDGE }), sequence({ toMs: 6000, up: [[1000, 5000]], skip: [[1500, 3500]] }));
        assert.equal(of(bridged, 'candidate')[0].atMs, 1000);
        assert.equal(of(bridged, 'candidate')[0].detectedAtMs, 3600, 'detected on the first frame after the gap');

        const machine = createFinishMachine({ gapPolicy: GAP_BRIDGE });
        const events = run(machine, sequence({ toMs: 9000, up: [[1000, 8000]], skip: [[2500, 5000]] }));
        assert.equal(of(events, 'raise_end')[0].endReason, 'gap');
        assert.equal(of(events, 'raise_end')[0].windowToMs, 2400);
        assert.equal(of(events, 'candidate').length, 1, 'the raise after the gap is inside the cooldown');
        assert.equal(of(events, 'raise_suppressed').length, 1);
    },

    'the inference backing off to 1 fps is no gap - maxGapMs is above the pacing floor'() {
        const events = run(createFinishMachine(), sequence({ toMs: 8000, stepMs: 1000, up: [[3000, 5000]] }));

        assert.equal(of(events, 'gap').length, 0);
        assert.equal(of(events, 'candidate')[0].atMs, 3000);

        const strict = run(createFinishMachine({ maxGapMs: 900 }), sequence({ toMs: 8000, stepMs: 1000, up: [[3000, 5000]] }));
        assert.equal(of(strict, 'gap').length, 8);
        assert.equal(of(strict, 'candidate').length, 0, 'every raised frame stood alone');
    },

    'mode "off" never says anything; "shadow" flags its candidates; an unknown mode is off'() {
        const frames = sequence({ toMs: 6000, up: [[2000, 3600]], skip: [[4500, 5500]] });

        const off = createFinishMachine({ mode: MODE_OFF });
        assert.deepEqual(run(off, frames), []);
        assert.equal(off.state().frames, 0, 'nothing kept');

        const shadow = run(createFinishMachine({ mode: MODE_SHADOW }), frames);
        assert.equal(of(shadow, 'candidate').length, 1);
        assert.equal(of(shadow, 'candidate')[0].shadow, true);
        assert.equal(of(shadow, 'candidate')[0].mode, MODE_SHADOW);
        assert.equal(of(shadow, 'candidate')[0].atMs, 2000, 'the same timing as on');

        const on = run(createFinishMachine({ mode: MODE_ON }), frames);
        assert.equal(of(on, 'candidate')[0].shadow, false);

        assert.equal(createFinishMachine({ mode: 'sometimes' }).rules.mode, MODE_OFF);
        assert.deepEqual(run(createFinishMachine({ mode: 'sometimes' }), frames), []);
        assert.equal(createFinishMachine().rules.mode, MODE_ON, 'no mode given: on');
    },

    'minScore: frames below it do not count as raised'() {
        const frames = [
            ...sequence({ toMs: 3000, up: [[1000, 2000]], score: 0.35 }),
            ...sequence({ fromMs: 3200, toMs: 6000, up: [[4000, 5000]], score: 0.8 }),
        ];

        assert.equal(of(run(createFinishMachine(), frames), 'candidate').length, 1, 'cooldown: the second raise is inside it');
        const picky = run(createFinishMachine({ minScore: 0.5 }), frames);
        assert.equal(of(picky, 'candidate').length, 1);
        assert.equal(of(picky, 'candidate')[0].atMs, 4000);

        // A signal without a score counts as 1
        const bare = run(createFinishMachine({ minScore: 0.9 }), sequence({ toMs: 3000, up: [[1000, 2000]] }).map(({ captureMs, raised }) => ({ captureMs, raised })));
        assert.equal(of(bare, 'candidate').length, 1);
    },

    'frames out of order, twice, or without a time are ignored - and counted'() {
        const machine = createFinishMachine();

        assert.deepEqual(machine.push({ captureMs: T + 1000, raised: true }), []);
        assert.deepEqual(machine.push({ captureMs: T + 1000, raised: true }), [], 'the same moment again');
        assert.deepEqual(machine.push({ captureMs: T + 900, raised: true }), [], 'older');
        assert.deepEqual(machine.push({ captureMs: Number.NaN, raised: true }), []);
        assert.deepEqual(machine.push(null), []);
        assert.deepEqual(machine.push({ raised: true }), []);
        assert.equal(machine.state().ignored, 5);
        assert.equal(machine.state().frames, 1);

        // captureTimeMs (the worker's name) is accepted too
        machine.push({ captureTimeMs: T + 1300, raised: true });
        const events = machine.push({ captureTimeMs: T + 1500, raised: true });
        assert.equal(events[0].type, 'candidate');
        assert.equal(events[0].atMs, T + 1000);
    },

    'state(): idle → holding → raised, the hold so far and the cooldown'() {
        const machine = createFinishMachine();

        assert.equal(machine.state().phase, PHASE_IDLE);
        assert.equal(machine.state().lastCandidate, null);

        machine.push({ captureMs: T, raised: true, score: 0.8 });
        machine.push({ captureMs: T + 200, raised: true, score: 0.8 });
        let state = machine.state();
        assert.equal(state.phase, PHASE_HOLDING);
        assert.equal(state.holdFromMs, T);
        assert.equal(state.heldMs, 200);
        assert.equal(state.cooldownUntilMs, null);

        machine.push({ captureMs: T + 600, raised: true, score: 0.8 });
        state = machine.state();
        assert.equal(state.phase, PHASE_RAISED);
        assert.equal(state.cooldownUntilMs, T + 10_000);
        assert.equal(state.lastCandidate.atMs, T);

        machine.push({ captureMs: T + 800, raised: false });
        machine.push({ captureMs: T + 1000, raised: false });
        state = machine.state();
        assert.equal(state.phase, PHASE_IDLE);
        assert.equal(state.cooldownUntilMs, T + 10_000);

        machine.push({ captureMs: T + 10_200, raised: false });
        assert.equal(machine.state().cooldownUntilMs, null, 'over');

        // The snapshot is a copy
        machine.state().lastCandidate.atMs = 0;
        assert.equal(machine.state().lastCandidate.atMs, T);
    },

    'reset(): a new round - no cooldown, ids from 1, time may start again'() {
        const machine = createFinishMachine();
        run(machine, sequence({ toMs: 2000, up: [[0, 1000]] }));
        assert.equal(machine.state().candidates, 1);

        machine.reset();
        assert.equal(machine.state().candidates, 0);
        assert.equal(machine.state().cooldownUntilMs, null);

        const events = run(machine, sequence({ toMs: 2000, up: [[0, 1000]] }));
        assert.equal(of(events, 'candidate')[0].id, 1);
        assert.equal(of(events, 'candidate')[0].atMs, 0);
    },

    'the rules: defaults as README §5.7 says, invalid values replaced'() {
        assert.equal(DEFAULT_RULES.holdMs, 500);
        assert.equal(DEFAULT_RULES.cooldownMs, 10_000);
        assert.equal(DEFAULT_RULES.maxGapMs, 1500);
        assert.equal(DEFAULT_RULES.maxDropFrames, 1);
        assert.equal(DEFAULT_RULES.maxDropMs, 700);
        assert.equal(DEFAULT_RULES.minScore, 0);
        assert.equal(DEFAULT_RULES.straddle, 'whole');
        assert.equal(DEFAULT_RULES.gapPolicy, 'restart');
        assert.equal(DEFAULT_RULES.cooldownFrom, 'candidate');

        const rules = resolveRules({ holdMs: -1, cooldownMs: 'x', maxGapMs: 0, maxDropFrames: 1.5, minScore: Number.NaN, straddle: 'maybe', gapPolicy: 'jump' });
        assert.deepEqual({ ...rules }, { ...DEFAULT_RULES });
        assert.ok(Object.isFrozen(createFinishMachine().rules));
        assert.equal(createFinishMachine({ holdMs: 300 }).rules.holdMs, 300);
    },
};

const results = {};

for (const [name, scenario] of Object.entries(scenarios)) {
    try {
        await scenario();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
