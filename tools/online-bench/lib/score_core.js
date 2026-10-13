/**
 * The bench's scoring (docs/features/online-competitions/spikes.md S2): the per-frame signals the bench page exported
 * + the S1 labels → the finish machine (assets/online/finish_machine.js, the very module the phones will run) → how
 * good the detection is:
 *
 * - **detection error** = candidate moment − labelled raise (signed, ms): late is positive. The review's number
 *   (README §5.9: confirmed − detected) with the label as the confirmed moment.
 * - **detected** - a labelled raise with a candidate within ±`matchMs` (5 s - the finish burst around a candidate
 *   covers it, so a reviewer can still pin the moment); **within 1 s** - README §1 P3's target; **missed** - no
 *   candidate; **false raises** - candidates that match no labelled raise; **the finish** - the last complete raise.
 * - **latency** = detectedAtMs − labelled raise: how long the participant waits for "Finish recorded".
 *
 * Matching: the closest (raise, candidate) pairs within ±matchMs first, each raise and candidate used once.
 *
 * The sweep runs the machine with every combination of a grid (hold, minScore, dropped frames, gaps, the frame rate
 * the phone would analyse at, the view - and optionally the signal's own thresholds, recomputed from the stored
 * landmarks) and ranks them: missed finishes, then missed raises, then false raises, then the 90th percentile of the
 * absolute error, then its median - fewer is better everywhere; among equals the row with fewer changes from today's
 * defaults first. A missed finish costs a person (the button); a false raise costs a look at a photo.
 *
 * Pure: score.mjs does the files. Pinned by tests/online-bench-harness.mjs.
 */

import { createFinishMachine, DEFAULT_RULES } from '../../../assets/online/finish_machine.js';
import { frameSignal, VIEW_ANGLED, VIEW_ANY, VIEW_TOP_DOWN } from '../../../assets/online/hands_up_signal.js';

export const SIGNALS_FORMAT = 'msp-online-bench-signals';
export const SIGNALS_FORMAT_VERSION = 1;
export const MATCH_MS = 5000;
export const WITHIN_MS = 1000;

export const DEFAULT_GRID = Object.freeze({
    holdMs: [300, 500, 700, 1000],
    minScore: [0, 0.2, 0.4, 0.6],
    maxDropFrames: [0, 1, 2],
    maxGapMs: [1000, 1500, 3000],
    // null = every analysed frame
    fps: [null, 5, 4, 2],
    // null = the signal the bench stored
    view: [null],
});

export const SIGNAL_GRID = Object.freeze({
    minVisibility: [0.3, 0.5, 0.7],
    minLift: [0.1, 0.25, 0.5],
    minGestureScore: [0.5, 0.6, 0.75],
});

/**
 * Is this the bench's signals file?
 */
export function isSignalsFile(json) {
    return json !== null && typeof json === 'object' && json.format === SIGNALS_FORMAT && Array.isArray(json.frames);
}

/**
 * The frames' signals, as the machine takes them ({captureMs, raised, score}). With `view` or `signalOptions` the
 * signal is computed again from the stored landmarks (hands_up_signal.js) - otherwise the stored one is used.
 */
export function signalsOf(frames, { view = null, signalOptions = null, aspect = null } = {}) {
    const recompute = view !== null || signalOptions !== null;

    return (Array.isArray(frames) ? frames : [])
        .filter((frame) => Number.isFinite(frame?.t))
        .map((frame) => {
            if (!recompute) {
                return { captureMs: frame.t, raised: frame.signal?.raised === true, score: frame.signal?.score ?? 0 };
            }

            const signal = frameSignal(
                { pose: frame.pose ?? null, hands: frame.hands ?? null },
                { ...(signalOptions ?? {}), view: view ?? frame.signal?.view ?? VIEW_ANGLED, aspect: aspect ?? frame.aspect ?? 1 },
            );

            return { captureMs: frame.t, raised: signal.raised, score: signal.score };
        });
}

/**
 * The frames a phone analysing at `fps` would have seen: the first one, then each at least 1000/fps (less 10 % for
 * the video's frame timing) after the last one kept. null: all of them.
 */
export function subsample(signals, fps) {
    if (!Number.isFinite(fps) || fps <= 0) {
        return signals;
    }

    const gap = (1000 / fps) * 0.9;
    const kept = [];

    for (const signal of signals) {
        if (kept.length === 0 || signal.captureMs - kept[kept.length - 1].captureMs >= gap) {
            kept.push(signal);
        }
    }

    return kept;
}

/**
 * The machine over the signals → its candidates (and every event, for the page's timeline).
 */
export function runMachine(signals, rules = {}) {
    const machine = createFinishMachine({ ...rules, mode: rules.mode ?? 'on' });
    const events = [];

    for (const signal of signals) {
        events.push(...machine.push(signal));
    }

    return { candidates: events.filter((event) => event.type === 'candidate'), events, state: machine.state() };
}

/**
 * Labelled raises ↔ candidates, closest pairs first: every (raise, candidate) within ±matchMs, the nearest assigned
 * first, each raise and each candidate at most once. (Raise by raise in time order would hand a later raise's own
 * candidate to an earlier raise that had none - a jump raise too short at 5 fps "found" 3 s late.)
 *
 * @returns {{pairs: Array<{raise: object, candidate: object|null, errorMs: number|null}>, unmatched: object[]}}
 */
export function matchRaises(raises, candidates, matchMs = MATCH_MS) {
    const ordered = [...raises].sort((a, b) => a.atMs - b.atMs);
    const options = [];

    ordered.forEach((raise, raiseIndex) => {
        candidates.forEach((candidate, candidateIndex) => {
            const distance = Math.abs(candidate.atMs - raise.atMs);

            if (distance <= matchMs) {
                options.push({ raiseIndex, candidateIndex, distance });
            }
        });
    });

    options.sort((a, b) => a.distance - b.distance || a.raiseIndex - b.raiseIndex || a.candidateIndex - b.candidateIndex);
    const raiseTaken = new Map();
    const candidateTaken = new Set();

    for (const option of options) {
        if (!raiseTaken.has(option.raiseIndex) && !candidateTaken.has(option.candidateIndex)) {
            raiseTaken.set(option.raiseIndex, option.candidateIndex);
            candidateTaken.add(option.candidateIndex);
        }
    }

    const pairs = ordered.map((raise, raiseIndex) => {
        if (!raiseTaken.has(raiseIndex)) {
            return { raise, candidate: null, errorMs: null };
        }

        const candidate = candidates[raiseTaken.get(raiseIndex)];

        return { raise, candidate, errorMs: candidate.atMs - raise.atMs };
    });

    return { pairs, unmatched: candidates.filter((candidate, index) => !candidateTaken.has(index)) };
}

/**
 * count, median, p90, max of a list - null when empty. p90 by the nearest rank (small samples).
 */
export function distribution(values) {
    const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);

    if (sorted.length === 0) {
        return { count: 0, median: null, p90: null, max: null, mean: null };
    }

    const middle = sorted.length >> 1;
    const median = sorted.length % 2 === 1 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
    const p90 = sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * 0.9) - 1)];

    return {
        count: sorted.length,
        median,
        p90,
        max: sorted[sorted.length - 1],
        mean: sorted.reduce((sum, value) => sum + value, 0) / sorted.length,
    };
}

/**
 * One video scored with one set of rules.
 *
 * @param {{name: string, frames: object[], labels: object|null, aspect?: number}} video
 * @param {{rules?: object, fps?: number|null, view?: string|null, signalOptions?: object|null, matchMs?: number}} [setup]
 */
export function scoreVideo(video, { rules = {}, fps = null, view = null, signalOptions = null, matchMs = MATCH_MS } = {}) {
    const signals = subsample(signalsOf(video.frames, { view, signalOptions, aspect: video.aspect ?? null }), fps);
    const { candidates, events } = runMachine(signals, rules);
    const raises = video.labels?.raises ?? [];
    const { pairs, unmatched } = matchRaises(raises, candidates, matchMs);
    const errors = pairs.filter((pair) => pair.errorMs !== null).map((pair) => pair.errorMs);
    // A missed raise the machine saw but held back: it began inside the cooldown of an earlier candidate
    const suppressed = events.filter((event) => event.type === 'raise_suppressed');

    for (const pair of pairs) {
        pair.inCooldown = pair.candidate === null && suppressed.some((event) => Math.abs(event.atMs - pair.raise.atMs) <= matchMs);
    }

    const finish = [...pairs].reverse().find((pair) => pair.raise.complete === true) ?? null;

    return {
        video: video.name,
        labelled: video.labels !== null && video.labels !== undefined,
        frames: signals.length,
        raises: raises.length,
        completeRaises: raises.filter((raise) => raise.complete === true).length,
        candidates: candidates.length,
        detected: errors.length,
        within1s: errors.filter((error) => Math.abs(error) <= WITHIN_MS).length,
        missed: pairs.length - errors.length,
        missedInCooldown: pairs.filter((pair) => pair.inCooldown).length,
        falseRaises: unmatched.length,
        finishLabelled: finish !== null,
        finishDetected: finish !== null && finish.candidate !== null,
        finishErrorMs: finish?.errorMs ?? null,
        errors,
        latencies: pairs.filter((pair) => pair.candidate !== null).map((pair) => pair.candidate.detectedAtMs - pair.raise.atMs),
        pairs,
        unmatched,
        events,
    };
}

/**
 * The totals of several videos' scores.
 */
export function totals(results) {
    const sum = (key) => results.reduce((total, result) => total + (Number.isFinite(result[key]) ? result[key] : 0), 0);
    const errors = results.flatMap((result) => result.errors);
    const absolute = distribution(errors.map((error) => Math.abs(error)));
    const signed = distribution(errors);
    const latency = distribution(results.flatMap((result) => result.latencies));

    return {
        videos: results.length,
        raises: sum('raises'),
        completeRaises: sum('completeRaises'),
        candidates: sum('candidates'),
        detected: sum('detected'),
        within1s: sum('within1s'),
        missed: sum('missed'),
        missedInCooldown: sum('missedInCooldown'),
        falseRaises: sum('falseRaises'),
        finishes: results.filter((result) => result.finishLabelled).length,
        missedFinishes: results.filter((result) => result.finishLabelled && !result.finishDetected).length,
        medianAbsErrorMs: absolute.median,
        p90AbsErrorMs: absolute.p90,
        maxAbsErrorMs: absolute.max,
        meanErrorMs: signed.mean,
        medianLatencyMs: latency.median,
    };
}

/**
 * Every combination of the grid's values (keys with a list).
 */
export function combinations(grid) {
    let rows = [{}];

    for (const [key, values] of Object.entries(grid)) {
        const list = Array.isArray(values) && values.length > 0 ? values : [null];
        rows = rows.flatMap((row) => list.map((value) => ({ ...row, [key]: value })));
    }

    return rows;
}

const RULE_KEYS = ['holdMs', 'minScore', 'maxDropFrames', 'maxDropMs', 'maxGapMs', 'cooldownMs', 'gapPolicy', 'straddle', 'cooldownFrom'];
const POSE_KEYS = ['minVisibility', 'minShoulderVisibility', 'minLift', 'fullLift'];
const GESTURE_KEYS = ['minGestureScore', 'minHandSeparation'];

/**
 * A grid row → what scoreVideo() takes.
 */
export function setupOf(row) {
    const rules = {};
    const pose = {};
    const gesture = {};

    for (const [key, value] of Object.entries(row)) {
        if (value === null || value === undefined) {
            continue;
        }

        if (RULE_KEYS.includes(key)) {
            rules[key] = value;
        } else if (POSE_KEYS.includes(key)) {
            pose[key] = value;
        } else if (GESTURE_KEYS.includes(key)) {
            gesture[key] = value;
        }
    }

    const tuned = Object.keys(pose).length > 0 || Object.keys(gesture).length > 0;

    return {
        rules,
        fps: Number.isFinite(row.fps) ? row.fps : null,
        view: typeof row.view === 'string' ? row.view : null,
        signalOptions: tuned ? { pose, gesture } : null,
    };
}

/**
 * Better first: missed finishes, missed raises, false raises, p90 |error|, median |error| (no error = worst).
 */
export function compareTotals(a, b) {
    const keys = ['missedFinishes', 'missed', 'falseRaises', 'p90AbsErrorMs', 'medianAbsErrorMs'];

    for (const key of keys) {
        const x = a[key] ?? Infinity;
        const y = b[key] ?? Infinity;

        if (x !== y) {
            return x - y;
        }
    }

    return 0;
}

// What the phones run unless the bench shows better: the machine's defaults, every analysed frame, the stored view,
// the signal's own thresholds
export const SWEEP_DEFAULTS = Object.freeze({
    holdMs: DEFAULT_RULES.holdMs,
    minScore: DEFAULT_RULES.minScore,
    maxDropFrames: DEFAULT_RULES.maxDropFrames,
    maxGapMs: DEFAULT_RULES.maxGapMs,
    fps: null,
    view: null,
    minVisibility: 0.5,
    minLift: 0.25,
    minGestureScore: 0.6,
});

/**
 * How many settings of a grid row differ from SWEEP_DEFAULTS - among equally good rows, the one closest to what runs
 * today ranks first (a change needs footage that asks for it).
 */
export function changesFromDefaults(row) {
    return Object.entries(row).filter(([key, value]) => key in SWEEP_DEFAULTS && value !== SWEEP_DEFAULTS[key]).length;
}

/**
 * The sweep: every grid row over every labelled video → rows ranked best first.
 *
 * @returns {Array<{rank: number, setup: object, totals: object}>}
 */
export function sweep(videos, grid = DEFAULT_GRID, { matchMs = MATCH_MS } = {}) {
    const labelled = videos.filter((video) => video.labels !== null && video.labels !== undefined);

    return combinations(grid)
        .map((row) => {
            const setup = setupOf(row);

            return { row, totals: totals(labelled.map((video) => scoreVideo(video, { ...setup, matchMs }))) };
        })
        .sort((a, b) => compareTotals(a.totals, b.totals) || changesFromDefaults(a.row) - changesFromDefaults(b.row))
        .map((entry, index) => ({ rank: index + 1, ...entry }));
}

/**
 * A grid that also tunes the signal (needs the landmarks in the signals file).
 */
export function gridWithSignal(grid = DEFAULT_GRID, signalGrid = SIGNAL_GRID) {
    return { ...grid, ...signalGrid };
}

/**
 * Rows → CSV (RFC 4180 quoting).
 */
export function toCsv(rows, columns) {
    const cell = (value) => {
        const text = value === null || value === undefined ? '' : (Array.isArray(value) ? value.join(';') : String(value));

        return /[",\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
    };

    return `${[columns.join(','), ...rows.map((row) => columns.map((column) => cell(row[column])).join(','))].join('\n')}\n`;
}

/**
 * The per-video rows of the CSV / summary.
 */
export function videoRows(results) {
    return results.map((result) => ({
        video: result.video,
        labelled: result.labelled ? 'yes' : 'no',
        frames: result.frames,
        raises: result.raises,
        completeRaises: result.completeRaises,
        candidates: result.candidates,
        detected: result.detected,
        within1s: result.within1s,
        missed: result.missed,
        missedInCooldown: result.missedInCooldown,
        falseRaises: result.falseRaises,
        finishDetected: result.finishLabelled ? (result.finishDetected ? 'yes' : 'no') : '',
        finishErrorMs: result.finishErrorMs,
        errorsMs: result.errors,
        latenciesMs: result.latencies,
    }));
}

export const VIDEO_COLUMNS = ['video', 'labelled', 'frames', 'raises', 'completeRaises', 'candidates', 'detected', 'within1s', 'missed', 'missedInCooldown', 'falseRaises', 'finishDetected', 'finishErrorMs', 'errorsMs', 'latenciesMs'];
export const SWEEP_COLUMNS = ['rank', 'view', 'fps', 'holdMs', 'minScore', 'maxDropFrames', 'maxGapMs', 'minVisibility', 'minLift', 'minGestureScore', 'missedFinishes', 'missed', 'missedInCooldown', 'falseRaises', 'detected', 'within1s', 'medianAbsErrorMs', 'p90AbsErrorMs', 'maxAbsErrorMs', 'meanErrorMs', 'medianLatencyMs'];

/**
 * A grid value as the tables show it: fps null = every analysed frame, view null = the stored signal, any other null
 * = the default.
 */
export function describeValue(key, value) {
    if (value !== null && value !== undefined) {
        return value;
    }

    if (key === 'fps') {
        return 'all';
    }

    return key === 'view' ? 'stored' : 'default';
}

/**
 * Sweep entries → flat rows for SWEEP_COLUMNS.
 */
export function sweepRows(entries) {
    return entries.map((entry) => ({
        rank: entry.rank,
        ...Object.fromEntries(Object.entries(entry.row).map(([key, value]) => [key, describeValue(key, value)])),
        ...entry.totals,
        meanErrorMs: entry.totals.meanErrorMs === null ? null : Math.round(entry.totals.meanErrorMs),
    }));
}

/**
 * The markdown summary: the default rules per video + totals, the best rows of the sweep.
 */
export function summaryMarkdown({ results, sweepEntries = [], rules = DEFAULT_RULES, top = 15, generatedAt = null }) {
    const lines = [];
    const all = totals(results.filter((result) => result.labelled));
    const ms = (value) => (value === null || value === undefined ? '-' : `${Math.round(value)} ms`);

    lines.push('# Hands-up detection - bench score', '');

    if (generatedAt) {
        lines.push(`Generated ${generatedAt}.`, '');
    }

    lines.push(
        `Rules: hold ${rules.holdMs} ms, cooldown ${rules.cooldownMs} ms, minScore ${rules.minScore}, `
            + `maxDropFrames ${rules.maxDropFrames}, maxDropMs ${rules.maxDropMs}, maxGapMs ${rules.maxGapMs}, gap ${rules.gapPolicy}, straddle ${rules.straddle}.`,
        '',
        '| Video | Raises (complete) | Candidates | Detected | Within 1 s | Missed (in a cooldown) | False raises | Finish | Finish error | Errors |',
        '|---|---|---|---|---|---|---|---|---|---|',
    );

    for (const result of results) {
        const finish = result.finishLabelled ? (result.finishDetected ? 'yes' : '**missed**') : '-';
        lines.push(`| ${result.video}${result.labelled ? '' : ' (no labels)'} | ${result.raises} (${result.completeRaises}) | ${result.candidates} | ${result.detected} | ${result.within1s} | ${result.missed} (${result.missedInCooldown}) | ${result.falseRaises} | ${finish} | ${ms(result.finishErrorMs)} | ${result.errors.map((error) => `${error > 0 ? '+' : ''}${error}`).join(', ') || '-'} |`);
    }

    lines.push(
        '',
        `**Totals** (${all.videos} labelled videos): ${all.detected} of ${all.raises} raises detected, ${all.within1s} within 1 s, `
            + `${all.missed} missed (${all.missedInCooldown} of them began inside a cooldown), ${all.falseRaises} false raises, ${all.missedFinishes} of ${all.finishes} finishes missed; `
            + `|error| median ${ms(all.medianAbsErrorMs)}, p90 ${ms(all.p90AbsErrorMs)}, max ${ms(all.maxAbsErrorMs)}; `
            + `mean signed error ${ms(all.meanErrorMs)} (positive = late); median wait for the candidate ${ms(all.medianLatencyMs)}.`,
        '',
        'Error = candidate moment − labelled raise. Detected = a candidate within ±5 s (the finish burst covers it). '
            + 'A false raise is a candidate that matches no labelled raise. A raise missed "in a cooldown" was seen but held '
            + 'back by the 10 s cooldown of an earlier candidate (finish_machine.js) - by design, the button covers it.',
    );

    if (sweepEntries.length > 0) {
        lines.push('', `## Sweep - best ${Math.min(top, sweepEntries.length)} of ${sweepEntries.length}`, '', 'Ranked by missed finishes, missed raises, false raises, p90 |error|, median |error|.', '');
        const keys = Object.keys(sweepEntries[0].row);
        lines.push(`| # | ${keys.join(' | ')} | Missed finishes | Missed | False | Within 1 s | Median \\|error\\| | p90 \\|error\\| |`);
        lines.push(`|${'---|'.repeat(keys.length + 7)}`);

        for (const entry of sweepEntries.slice(0, top)) {
            const values = keys.map((key) => describeValue(key, entry.row[key]));
            const t = entry.totals;
            lines.push(`| ${entry.rank} | ${values.join(' | ')} | ${t.missedFinishes} | ${t.missed} | ${t.falseRaises} | ${t.within1s} | ${ms(t.medianAbsErrorMs)} | ${ms(t.p90AbsErrorMs)} |`);
        }
    }

    return `${lines.join('\n')}\n`;
}

export { VIEW_ANGLED, VIEW_ANY, VIEW_TOP_DOWN };
