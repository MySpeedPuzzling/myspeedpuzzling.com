// Runs the detection bench's pure core through its scenarios and prints {scenario: "ok" | error} as JSON -
// tests/OnlineBenchScriptTest.php asserts every one is "ok":
// - tools/online-bench/lib/labels.js: the S1 label file, its list form for several raises, times;
// - tools/online-bench/lib/score_core.js: signals → assets/online/finish_machine.js → detection error, misses, false
//   raises, the finish; thinning to the phone's frame rate; signals recomputed from landmarks; the sweep and its
//   ranking; CSV and the markdown summary.

import assert from 'node:assert/strict';
import { DEFAULT_RULES } from '../assets/online/finish_machine.js';
import { POSE, POSE_LANDMARKS } from '../assets/online/hands_up_signal.js';
import { emptyLabels, formatLabels, formatLabelTime, formatTime, parseComplete, parseLabels, parseRange, parseTime } from '../tools/online-bench/lib/labels.js';
import {
    changesFromDefaults,
    combinations,
    compareTotals,
    describeValue,
    distribution,
    gridWithSignal,
    isSignalsFile,
    matchRaises,
    runMachine,
    scoreVideo,
    setupOf,
    signalsOf,
    subsample,
    summaryMarkdown,
    sweep,
    SWEEP_COLUMNS,
    sweepRows,
    toCsv,
    totals,
    VIDEO_COLUMNS,
    videoRows,
} from '../tools/online-bench/lib/score_core.js';

// The S1 example, as spikes.md shows it
const S1_EXAMPLE = `video: 07.mp4
phone: iPhone 12, front camera
placement: angled
light: evening lamp
puzzle: 1000 pcs, low texture (sea)
empty_mat: 00:00:01-00:00:05
last_piece_placed: 00:12:41.5
hands_up: 00:12:42.2
complete: yes            # or: no - 1 piece missing
notes: someone walked behind at 00:08:10
`;

/**
 * Bench frames every `stepMs` up to `toMs`, raised inside the `up` intervals, with a pose that matches (so the signal
 * can be recomputed from the landmarks).
 */
function frames({ toMs, stepMs = 100, up = [], drop = [], score = 0.9 }) {
    const list = [];

    for (let t = 0; t <= toMs; t += stepMs) {
        const raised = up.some(([a, b]) => t >= a && t <= b) && !drop.includes(t);
        list.push({
            t,
            inferenceMs: 20,
            pose: pose(raised),
            hands: null,
            signal: { raised, score: raised ? score : 0, reason: raised ? 'raised' : 'hands_down', view: 'angled' },
        });
    }

    return list;
}

function pose(raised) {
    const points = Array.from({ length: POSE_LANDMARKS }, () => [0.5, 0.3, 0.95]);
    points[POSE.LEFT_SHOULDER] = [0.42, 0.40, 0.95];
    points[POSE.RIGHT_SHOULDER] = [0.58, 0.40, 0.95];
    points[POSE.LEFT_HIP] = [0.45, 0.70, 0.95];
    points[POSE.RIGHT_HIP] = [0.55, 0.70, 0.95];
    // Raised: 0.12 above the shoulder line = 0.42 shoulder widths in a 16:9 frame
    points[POSE.LEFT_WRIST] = raised ? [0.40, 0.28, 0.95] : [0.40, 0.66, 0.95];
    points[POSE.RIGHT_WRIST] = raised ? [0.60, 0.28, 0.95] : [0.60, 0.66, 0.95];

    return points;
}

function video(name, frameList, labelsText = null) {
    return { name, frames: frameList, labels: labelsText === null ? null : parseLabels(labelsText), aspect: 16 / 9 };
}

const scenarios = {
    'times: HH:MM:SS.s, MM:SS, seconds - written as HH:MM:SS.t'() {
        assert.equal(parseTime('00:12:42.2'), 762_200);
        assert.equal(parseTime('12:42.2'), 762_200);
        assert.equal(parseTime('1:00:00'), 3_600_000);
        assert.equal(parseTime('762.2'), 762_200);
        assert.equal(parseTime(' 00:00:05 '), 5000);
        assert.equal(parseTime('00:61:00'), null);
        assert.equal(parseTime('soon'), null);
        assert.equal(parseTime(''), null);

        assert.equal(formatTime(762_200), '00:12:42.2');
        assert.equal(formatTime(762_249), '00:12:42.2');
        assert.equal(formatTime(762_250), '00:12:42.3', 'tenths rounded to the nearest');
        assert.equal(formatTime(3_599_960), '01:00:00.0');
        assert.equal(formatTime(-1), '');
        assert.equal(formatTime(16_456, 3), '00:00:16.456');
        assert.equal(formatTime(16_456, 2), '00:00:16.46');
        assert.equal(formatLabelTime(16_456), '00:00:16.456', 'the label keeps the frame\'s millisecond');
        assert.equal(formatLabelTime(762_200), '00:12:42.2');
        assert.equal(formatLabelTime(5000), '00:00:05.0');
        assert.equal(parseTime(formatLabelTime(42_149)), 42_149);

        assert.deepEqual(parseRange('00:00:01-00:00:05'), { fromMs: 1000, toMs: 5000 });
        assert.deepEqual(parseRange('00:00:03'), { fromMs: 3000, toMs: null });
        assert.equal(parseRange('00:00:05-00:00:01'), null);
        assert.deepEqual(parseComplete('no - 1 piece missing'), { complete: false, missing: 1 });
        assert.deepEqual(parseComplete('no - 2 pieces missing'), { complete: false, missing: 2 });
        assert.deepEqual(parseComplete('yes'), { complete: true, missing: 0 });
        assert.deepEqual(parseComplete('maybe'), { complete: null, missing: null });
    },

    'the S1 example reads as one complete raise; comments are dropped'() {
        const labels = parseLabels(S1_EXAMPLE);

        assert.equal(labels.video, '07.mp4');
        assert.equal(labels.phone, 'iPhone 12, front camera');
        assert.equal(labels.placement, 'angled');
        assert.deepEqual(labels.emptyMat, { fromMs: 1000, toMs: 5000 });
        assert.equal(labels.lastPiecePlacedMs, 761_500);
        assert.deepEqual(labels.raises, [{ atMs: 762_200, toMs: null, complete: true }]);
        assert.equal(labels.complete, true);
        assert.equal(labels.notes, 'someone walked behind at 00:08:10');
        assert.deepEqual(labels.warnings, []);

        const missing = parseLabels(S1_EXAMPLE.replace('complete: yes            # or: no - 1 piece missing', 'complete: no - 1 piece missing'));
        assert.deepEqual(missing.raises, [{ atMs: 762_200, toMs: null, complete: false }]);
        assert.equal(missing.missing, 1);
    },

    'the list form: several raises, each complete or incomplete, ranges, any order - the last one takes "complete:"'() {
        const labels = parseLabels(`video: 12.mp4
hands_up: 00:12:42.2
hands_up: 00:10:05.0-00:10:06.1 incomplete   # a piece was still missing
hands_up: 00:11:00.0 no
complete: yes
`);

        assert.deepEqual(labels.raises, [
            { atMs: 605_000, toMs: 606_100, complete: false },
            { atMs: 660_000, toMs: null, complete: false },
            { atMs: 762_200, toMs: null, complete: true },
        ]);

        // Without words: the earlier ones are premature, the last one is the file's verdict
        const bare = parseLabels('hands_up: 00:01:00\nhands_up: 00:02:00\ncomplete: no - 1 piece missing\n');
        assert.deepEqual(bare.raises.map((raise) => raise.complete), [false, false]);
        assert.deepEqual(parseLabels('hands_up: 00:01:00\n').raises[0].complete, null, 'no verdict at all: unknown');
    },

    'labels: bad lines are warnings, unknown keys are kept'() {
        const labels = parseLabels('video: a.mp4\nhands_up: later\nhands_up: 00:00:01 maybe\nempty_mat: 9-1\nlast_piece_placed: x\nnonsense line\ncamera_height: 40 cm\n');

        assert.equal(labels.raises.length, 0);
        assert.equal(labels.warnings.length, 5);
        assert.deepEqual(labels.extra, { camera_height: '40 cm' });
        assert.match(formatLabels(labels), /camera_height: 40 cm/);
    },

    'formatLabels: one raise is written exactly in the S1 form, several in the list form - both read back the same'() {
        const single = formatLabels(parseLabels(S1_EXAMPLE));

        assert.match(single, /^hands_up: 00:12:42\.2$/m, 'no word - the S1 form');
        assert.match(single, /^empty_mat: 00:00:01\.0-00:00:05\.0$/m);
        assert.match(single, /^complete: yes$/m);
        assert.deepEqual(parseLabels(single).raises, parseLabels(S1_EXAMPLE).raises);

        const labels = emptyLabels('power-jump.webm');
        labels.raises = [
            { atMs: 43_100, toMs: null, complete: true },
            { atMs: 17_500, toMs: 25_900, complete: false },
        ];
        labels.complete = true;
        labels.lastPiecePlacedMs = 17_000;
        const text = formatLabels(labels);

        assert.equal(text, 'video: power-jump.webm\nlast_piece_placed: 00:00:17.0\nhands_up: 00:00:17.5-00:00:25.9\nhands_up: 00:00:43.1\ncomplete: yes\n');
        assert.deepEqual(parseLabels(text).raises, [
            { atMs: 17_500, toMs: 25_900, complete: false },
            { atMs: 43_100, toMs: null, complete: true },
        ]);

        // A complete raise that is not the last needs its word, and an incomplete last one
        labels.raises = [{ atMs: 1000, toMs: null, complete: true }, { atMs: 20_000, toMs: null, complete: false }];
        labels.complete = null;
        const words = formatLabels(labels);
        assert.match(words, /hands_up: 00:00:01\.0 complete/);
        assert.match(words, /hands_up: 00:00:20\.0 incomplete/);
        assert.deepEqual(parseLabels(words).raises.map((raise) => raise.complete), [true, false]);

        const missing = emptyLabels();
        missing.complete = false;
        missing.missing = 2;
        assert.match(formatLabels(missing), /complete: no - 2 pieces missing/);
    },

    'signals: stored ones, or recomputed from the landmarks with other thresholds; thinned to the phone\'s frame rate'() {
        const list = frames({ toMs: 2000, up: [[500, 1500]] });

        const stored = signalsOf(list);
        assert.equal(stored.length, 21);
        assert.deepEqual(stored[5], { captureMs: 500, raised: true, score: 0.9 });

        const recomputed = signalsOf(list, { signalOptions: { pose: { minLift: 0.25 } }, aspect: 16 / 9 });
        assert.equal(recomputed[5].raised, true);
        const strict = signalsOf(list, { signalOptions: { pose: { minLift: 0.6 } }, aspect: 16 / 9 });
        assert.equal(strict[5].raised, false, '0.42 shoulder widths is below 0.6');

        // 10 fps → 5 fps: every other frame; jittered frames still thin evenly
        assert.deepEqual(subsample(stored, 5).map((signal) => signal.captureMs).slice(0, 4), [0, 200, 400, 600]);
        assert.equal(subsample(stored, null), stored);
        const jittery = [0, 98, 205, 297, 402, 499].map((captureMs) => ({ captureMs, raised: false }));
        assert.deepEqual(subsample(jittery, 5).map((signal) => signal.captureMs), [0, 205, 402]);
        assert.equal(signalsOf(null).length, 0);
    },

    'matching: each labelled raise takes the nearest free candidate within ±5 s'() {
        const raises = [{ atMs: 10_000 }, { atMs: 30_000 }, { atMs: 50_000 }];
        const candidates = [{ atMs: 9_800 }, { atMs: 12_000 }, { atMs: 36_000 }, { atMs: 50_300 }];
        const { pairs, unmatched } = matchRaises(raises, candidates);

        assert.deepEqual(pairs.map((pair) => pair.errorMs), [-200, null, 300]);
        assert.deepEqual(unmatched.map((candidate) => candidate.atMs), [12_000, 36_000]);

        // A candidate is used once - by the raise it is closest to
        const shared = matchRaises([{ atMs: 1000 }, { atMs: 1500 }], [{ atMs: 1200 }]);
        assert.deepEqual(shared.pairs.map((pair) => pair.errorMs), [200, null]);
        const closer = matchRaises([{ atMs: 1000 }, { atMs: 1500 }], [{ atMs: 1400 }]);
        assert.deepEqual(closer.pairs.map((pair) => pair.errorMs), [null, -100]);

        // An earlier raise without a candidate of its own never takes a later raise's (found on real footage: a jump
        // raise too short at 5 fps, the next raise's candidate 3.3 s later)
        const stolen = matchRaises([{ atMs: 42_149 }, { atMs: 45_419 }], [{ atMs: 45_486 }]);
        assert.deepEqual(stolen.pairs.map((pair) => pair.errorMs), [null, 67]);
        assert.deepEqual(stolen.unmatched, []);
    },

    'a video scored: a premature raise, the finish, a false raise, a missed short raise'() {
        // Premature raise at 10 s (labelled incomplete), finish at 31 s, an unlabelled raise at 50 s (false), a 0.3 s
        // raise at 70 s that the labels call complete (missed)
        const list = frames({ toMs: 80_000, up: [[10_000, 11_000], [31_200, 33_000], [50_000, 51_000], [70_000, 70_300]] });
        const result = scoreVideo(video('07.mp4', list, 'video: 07.mp4\nhands_up: 00:00:10.0 incomplete\nhands_up: 00:00:31.0 complete\nhands_up: 00:01:10.0\ncomplete: yes\n'));

        assert.deepEqual([result.raises, result.completeRaises, result.candidates], [3, 2, 3]);
        assert.deepEqual(result.errors, [0, 200]);
        assert.deepEqual([result.detected, result.within1s, result.missed, result.falseRaises], [2, 2, 1, 1]);
        assert.equal(result.missedInCooldown, 0, 'the 70 s raise was simply too short');

        // A labelled raise 5 s after a candidate is held back by the cooldown - counted apart
        const cooled = scoreVideo(video('c.mp4', frames({ toMs: 20_000, up: [[2000, 3000], [7000, 8500]] }), 'hands_up: 00:00:02.0\nhands_up: 00:00:07.0\ncomplete: yes\n'));
        assert.deepEqual([cooled.detected, cooled.missed, cooled.missedInCooldown, cooled.finishDetected], [1, 1, 1, false]);
        assert.equal(cooled.pairs[1].inCooldown, true);
        assert.equal(result.finishLabelled, true);
        assert.equal(result.finishDetected, false, 'the last complete raise (70 s) was too short');
        assert.deepEqual(result.latencies, [500, 700], 'the finish was labelled 0.2 s before the hands showed up');
        assert.equal(result.labelled, true);

        // Thinned to 2 fps the hold needs a frame 0.5 s after the first
        const slow = scoreVideo(video('07.mp4', list, 'hands_up: 00:00:31.0\ncomplete: yes\n'), { fps: 2 });
        assert.equal(slow.frames, 161);
        assert.equal(slow.finishDetected, true);
        assert.equal(slow.finishErrorMs, 500, 'the first frame at 2 fps after 31.2 s is 31.5 s');

        // Unlabelled: candidates only
        const unlabelled = scoreVideo(video('x.mp4', list));
        assert.deepEqual([unlabelled.labelled, unlabelled.raises, unlabelled.candidates, unlabelled.falseRaises], [false, 0, 3, 3]);
    },

    'totals and distributions over videos'() {
        assert.deepEqual(distribution([]), { count: 0, median: null, p90: null, max: null, mean: null });
        const d = distribution([5, 1, 3, 2, 4, 10, 6, 7, 8, 9]);
        assert.deepEqual([d.count, d.median, d.p90, d.max, d.mean], [10, 5.5, 9, 10, 5.5]);

        const a = scoreVideo(video('a', frames({ toMs: 20_000, up: [[2000, 3000]] }), 'hands_up: 00:00:01.8\ncomplete: yes\n'));
        const b = scoreVideo(video('b', frames({ toMs: 20_000, up: [[5000, 5300]] }), 'hands_up: 00:00:05.0\ncomplete: yes\n'));
        const sum = totals([a, b]);

        assert.deepEqual([sum.videos, sum.raises, sum.detected, sum.missed, sum.finishes, sum.missedFinishes], [2, 2, 1, 1, 2, 1]);
        assert.equal(sum.medianAbsErrorMs, 200);
        assert.equal(sum.meanErrorMs, 200);
        assert.equal(sum.medianLatencyMs, 700);
    },

    'the sweep: every combination, ranked - missed finishes first, then misses, false raises, errors'() {
        assert.equal(combinations({ a: [1, 2], b: [3, 4, 5], c: [] }).length, 6);
        assert.deepEqual(combinations({}), [{}]);

        // A flickering finish: every third frame drops - tolerated drops find it, no tolerance misses it
        const list = frames({ toMs: 20_000, up: [[5000, 7000]], drop: [5200, 5500, 5800, 6100, 6400, 6700] });
        const videos = [video('flicker', list, 'hands_up: 00:00:05.0\ncomplete: yes\n')];
        const entries = sweep(videos, { holdMs: [500, 1000], maxDropFrames: [0, 1], fps: [null] });

        assert.equal(entries.length, 4);
        assert.deepEqual(entries.map((entry) => entry.rank), [1, 2, 3, 4]);
        assert.equal(entries[0].row.maxDropFrames, 1);
        assert.equal(entries[0].totals.missedFinishes, 0);
        assert.equal(entries[0].totals.medianAbsErrorMs, 0);
        assert.equal(entries[0].row.holdMs, 500, 'equal on misses: the smaller error');
        assert.ok(entries.at(-1).totals.missedFinishes === 1 || entries.at(-1).totals.medianAbsErrorMs > 0);

        // Equally good rows: the one closest to today's defaults first
        const ties = sweep([video('clean', frames({ toMs: 20_000, up: [[5000, 7000]] }), 'hands_up: 00:00:05.0\ncomplete: yes\n')], { holdMs: [300, 500], minScore: [0.2, 0] });
        assert.deepEqual(ties.map((entry) => [entry.row.holdMs, entry.row.minScore]), [[500, 0], [300, 0], [500, 0.2], [300, 0.2]]);
        assert.equal(changesFromDefaults({ holdMs: 500, minScore: 0, fps: null }), 0);
        assert.equal(changesFromDefaults({ holdMs: 300, fps: 5, minLift: 0.25 }), 2);

        assert.ok(compareTotals({ missedFinishes: 0, missed: 3 }, { missedFinishes: 1, missed: 0 }) < 0);
        assert.ok(compareTotals({ missedFinishes: 0, missed: 0, falseRaises: 0, p90AbsErrorMs: null }, { missedFinishes: 0, missed: 0, falseRaises: 0, p90AbsErrorMs: 900 }) > 0, 'no error measured is the worst');

        // The signal's thresholds are swept from the landmarks
        const tuned = sweep(videos, gridWithSignal({ holdMs: [500], maxDropFrames: [1], fps: [null] }, { minLift: [0.25, 0.6] }));
        assert.equal(tuned.length, 2);
        assert.equal(tuned[0].row.minLift, 0.25);
        assert.equal(tuned[1].totals.missedFinishes, 1);
    },

    'setupOf: a grid row becomes rules, frame rate, view and signal options'() {
        assert.deepEqual(setupOf({ holdMs: 700, minScore: 0.2, fps: 5, view: 'angled', minLift: 0.3, minGestureScore: 0.7 }), {
            rules: { holdMs: 700, minScore: 0.2 },
            fps: 5,
            view: 'angled',
            signalOptions: { pose: { minLift: 0.3 }, gesture: { minGestureScore: 0.7 } },
        });
        assert.deepEqual(setupOf({ holdMs: 500, fps: null, view: null }), { rules: { holdMs: 500 }, fps: null, view: null, signalOptions: null });
        assert.equal(describeValue('fps', null), 'all');
        assert.equal(describeValue('view', null), 'stored');
        assert.equal(describeValue('holdMs', null), 'default');
        assert.equal(describeValue('holdMs', 500), 500);
    },

    'outputs: CSV quoting, the per-video and sweep rows, the markdown summary'() {
        assert.equal(toCsv([{ a: 'x,y', b: 'say "hi"', c: [1, -2], d: null }], ['a', 'b', 'c', 'd']), 'a,b,c,d\n"x,y","say ""hi""",1;-2,\n');

        const result = scoreVideo(video('07.mp4', frames({ toMs: 20_000, up: [[2000, 3000]] }), 'hands_up: 00:00:02.0\ncomplete: yes\n'));
        const rows = videoRows([result]);
        assert.equal(rows[0].finishDetected, 'yes');
        assert.equal(toCsv(rows, VIDEO_COLUMNS).split('\n')[0], VIDEO_COLUMNS.join(','));

        const entries = sweep([video('07.mp4', frames({ toMs: 20_000, up: [[2000, 3000]] }), 'hands_up: 00:00:02.0\ncomplete: yes\n')], { holdMs: [500], fps: [null, 5] });
        const flat = sweepRows(entries);
        assert.equal(flat[0].fps, 'all');
        assert.ok(toCsv(flat, SWEEP_COLUMNS).startsWith('rank,view,fps,holdMs'));

        const markdown = summaryMarkdown({ results: [result], sweepEntries: entries, rules: DEFAULT_RULES, generatedAt: '2026-10-13T12:00:00Z' });
        assert.match(markdown, /^# Hands-up detection - bench score/);
        assert.match(markdown, /\| 07\.mp4 \| 1 \(1\) \| 1 \| 1 \| 1 \| 0 \(0\) \| 0 \| yes \| 0 ms \| 0 \|/);
        assert.match(markdown, /\*\*Totals\*\* \(1 labelled videos\): 1 of 1 raises detected, 1 within 1 s, 0 missed \(0 of them began inside a cooldown\), 0 false raises, 0 of 1 finishes missed/);
        assert.match(markdown, /## Sweep - best 2 of 2/);
    },

    'isSignalsFile + runMachine: the bench file and the machine the phones run'() {
        assert.equal(isSignalsFile({ format: 'msp-online-bench-signals', frames: [] }), true);
        assert.equal(isSignalsFile({ format: 'other', frames: [] }), false);
        assert.equal(isSignalsFile(null), false);

        const { candidates, events, state } = runMachine(signalsOf(frames({ toMs: 5000, up: [[1000, 2000]] })), { holdMs: 300 });
        assert.equal(candidates.length, 1);
        assert.equal(candidates[0].detectedAtMs, 1300);
        assert.equal(candidates[0].mode, 'on');
        assert.equal(events.at(-1).type, 'raise_end');
        assert.equal(state.candidates, 1);
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
