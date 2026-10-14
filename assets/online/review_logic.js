/**
 * The review of an online round, the parts that are arithmetic (docs/features/online-competitions/README.md §5.9) -
 * pure, no DOM, pinned by tests/online-review-harness.mjs:
 *
 * - a finish burst is ONE object, the frames one after another; its manifest `[{offset, length, captureMs}]` says
 *   where each JPEG lies and when it was captured on the synced clock (frame_buffer.js). frameSlices() turns it into
 *   the frames the scrubber shows - in capture order, only those that lie inside the object;
 * - the moment the reviewer settles on is a frame's capture time rounded to the millisecond (the server accepts only
 *   moments the phone recorded - GetOnlineEntryRecordedMoments); its source is `button` when it is a tap's own moment,
 *   `detection` when it is a finish candidate's (Phase 2), `reviewer` when the reviewer moved it; finishMoments() /
 *   nextMoment() = the taps and candidates in time, the `G` key's step;
 * - the official time of a moment: floor((moment − start) / 1000) s (round_state.js resultSeconds());
 * - summarize(): count, median, quartiles and IQR by linear interpolation between the closest ranks - the same numbers
 *   as the accuracy report's AccuracySummary (PHP), pinned by the same cases on both sides;
 * - reviewKey(): ↑/↓ participants, ←/→ frames, C confirm, G gap (next moment), U unfinished - never while the reviewer
 *   types.
 */

import { formatClock, resultSeconds } from './round_state.js';

export { resultSeconds };

/**
 * The frames of a burst object of `totalBytes` bytes (null: not known yet - every frame is taken), sorted by capture
 * time; a frame whose bytes lie outside the object, or without a usable capture time, is left out.
 *
 * @param {Array<{offset: number, length: number, captureMs: number}>} manifest
 * @param {number|null} totalBytes
 * @returns {Array<{index: number, offset: number, length: number, captureMs: number, momentMs: number}>}
 */
export function frameSlices(manifest, totalBytes = null) {
    if (!Array.isArray(manifest)) {
        return [];
    }

    const limit = Number.isFinite(totalBytes) && totalBytes >= 0 ? totalBytes : Infinity;
    const frames = manifest
        .filter((frame) => frame !== null && typeof frame === 'object'
            && Number.isInteger(frame.offset) && frame.offset >= 0
            && Number.isInteger(frame.length) && frame.length > 0
            && frame.offset + frame.length <= limit
            && Number.isFinite(frame.captureMs))
        .map((frame) => ({
            offset: frame.offset,
            length: frame.length,
            captureMs: frame.captureMs,
            momentMs: Math.round(frame.captureMs),
        }))
        .sort((a, b) => a.captureMs - b.captureMs || a.offset - b.offset);

    return frames.map((frame, index) => ({ index, ...frame }));
}

/**
 * The frame closest to a moment (the tap's moment when the burst opens) - the earlier one on a tie; -1 without frames.
 */
export function nearestFrame(frames, momentMs) {
    if (!Array.isArray(frames) || frames.length === 0 || !Number.isFinite(momentMs)) {
        return -1;
    }

    let best = 0;

    for (let index = 1; index < frames.length; index++) {
        if (Math.abs(frames[index].captureMs - momentMs) < Math.abs(frames[best].captureMs - momentMs)) {
            best = index;
        }
    }

    return best;
}

/**
 * One step through a list (frames, participants), clamped to it - never wrapping round.
 */
export function stepIndex(index, delta, count) {
    if (!Number.isInteger(count) || count <= 0) {
        return -1;
    }

    const from = Number.isInteger(index) && index >= 0 ? index : (delta >= 0 ? -1 : count);

    return Math.min(count - 1, Math.max(0, from + delta));
}

/**
 * What a confirmation sends for a moment: the moment in whole milliseconds and where it came from - `detection` when it
 * is the moment of one of the phone's finish candidates (raised hands it detected - Phase 2), `button` when it is the
 * moment of one of the participant's taps, else `reviewer` (a frame the reviewer picked). A moment that is both (a tap
 * and a candidate in the same millisecond) is the detection's: the review stood on the candidate.
 *
 * @param {number} momentMs
 * @param {Array<{momentMs: number}>} taps
 * @param {Array<{momentMs: number}>} [candidates]
 * @returns {{momentMs: number, source: 'button'|'detection'|'reviewer', pieces: null}|null}
 */
export function momentConfirmation(momentMs, taps = [], candidates = []) {
    if (!Number.isFinite(momentMs)) {
        return null;
    }

    const moment = Math.round(momentMs);
    const isAt = (list) => Array.isArray(list) && list.some((item) => item && item.momentMs === moment);
    let source = 'reviewer';

    if (isAt(candidates)) {
        source = 'detection';
    } else if (isAt(taps)) {
        source = 'button';
    }

    return { momentMs: moment, source, pieces: null };
}

/**
 * The finish moments of a participant the review steps through (README §5.9: the candidates with their photos, the
 * taps): every tap and every finish candidate, in time - `{kind: 'tap'|'candidate', id, momentMs, item}`, each kind
 * numbered from 1 in its own order (`number`); on the same millisecond the candidate first.
 *
 * @param {Array<{eventId: string, momentMs: number}>} taps
 * @param {Array<{eventId: string, momentMs: number}>} candidates
 */
export function finishMoments(taps = [], candidates = []) {
    const list = [];
    const add = (items, kind) => (Array.isArray(items) ? items : [])
        .filter((item) => item && typeof item.eventId === 'string' && Number.isFinite(item.momentMs))
        .forEach((item, index) => list.push({ kind, id: item.eventId, momentMs: item.momentMs, number: index + 1, item }));

    add(candidates, 'candidate');
    add(taps, 'tap');

    return list.sort((a, b) => a.momentMs - b.momentMs || (a.kind === b.kind ? 0 : (a.kind === 'candidate' ? -1 : 1)));
}

/**
 * The moment after `id` among finishMoments() - the reviewer's `G` (the photo shows a gap: on to the next raise or tap).
 * Null at the last one, or when `id` is not among them.
 */
export function nextMoment(moments, id) {
    const index = (Array.isArray(moments) ? moments : []).findIndex((moment) => moment.id === id);

    return index === -1 || index + 1 >= moments.length ? null : moments[index + 1];
}

/**
 * What a confirmation sends for an unfinished puzzle: the pieces placed - a whole number from 1, below the puzzle's
 * piece count when it is known. Null when the text is no such number.
 *
 * @returns {{momentMs: null, source: 'reviewer', pieces: number}|null}
 */
export function piecesConfirmation(text, piecesTotal = null) {
    const value = String(text ?? '').trim();

    if (!/^\d{1,6}$/.test(value)) {
        return null;
    }

    const pieces = parseInt(value, 10);

    if (pieces < 1 || (Number.isInteger(piecesTotal) && piecesTotal > 0 && pieces >= piecesTotal)) {
        return null;
    }

    return { momentMs: null, source: 'reviewer', pieces };
}

/**
 * Pieces placed from a time-up report: as reported in "placed", the puzzle's pieces minus the report in "left" - null
 * when the measure or the piece count is not known (OnlineReviewAssessment::piecesPlaced(), PHP).
 */
export function piecesPlaced(measure, pieces, piecesTotal = null) {
    if (!Number.isInteger(pieces)) {
        return null;
    }

    if (measure === 'placed') {
        return pieces;
    }

    if (measure === 'left') {
        return Number.isInteger(piecesTotal) ? Math.max(0, piecesTotal - pieces) : null;
    }

    return null;
}

/**
 * A moment on the round's clock: HH:MM:SS.t (tenths, rounded down - the official time never rounds up). Before the
 * start: -HH:MM:SS.t.
 */
export function formatElapsed(ms) {
    if (!Number.isFinite(ms)) {
        return '';
    }

    const negative = ms < 0;
    const absolute = Math.abs(ms);
    const tenths = Math.floor(absolute / 100) % 10;

    return `${negative ? '-' : ''}${formatClock(absolute)}.${tenths}`;
}

/**
 * The reviewer's adjustment (confirmed − tap) in seconds with a sign and a tenth: "+0.4 s", "−1.2 s", "0.0 s".
 */
export function formatAdjustment(ms) {
    if (!Number.isFinite(ms)) {
        return '';
    }

    const tenths = Math.round(Math.abs(ms) / 100);
    const sign = tenths === 0 ? '' : (ms < 0 ? '−' : '+');

    return `${sign}${(tenths / 10).toFixed(1)} s`;
}

/**
 * A distribution in a few numbers - linear interpolation between the closest ranks ("type 7"), like AccuracySummary.
 *
 * @param {number[]} values
 * @returns {{count: number, median: number|null, q1: number|null, q3: number|null, iqr: number|null}}
 */
export function summarize(values) {
    const sorted = (Array.isArray(values) ? values : []).filter(Number.isFinite).sort((a, b) => a - b);

    if (sorted.length === 0) {
        return { count: 0, median: null, q1: null, q3: null, iqr: null };
    }

    const quantile = (p) => {
        const position = (sorted.length - 1) * p;
        const lower = Math.floor(position);
        const upper = Math.ceil(position);

        return sorted[lower] + (position - lower) * (sorted[upper] - sorted[lower]);
    };
    const q1 = quantile(0.25);
    const q3 = quantile(0.75);

    return { count: sorted.length, median: quantile(0.5), q1, q3, iqr: q3 - q1 };
}

/**
 * The review's keys: ↑/↓ the participants, ←/→ the frames, C confirm, G gap (the photo shows a gap - on to the next
 * moment), U unfinished. Nothing while the reviewer types (an input - but the frame slider, which takes ←/→ itself -, a
 * select, a text area, an editable element) or holds a modifier - the browser's own shortcuts stay.
 *
 * @param {{key: string, target?: {tagName?: string, isContentEditable?: boolean, type?: string}, ctrlKey?: boolean, metaKey?: boolean, altKey?: boolean}} event
 * @returns {'up'|'down'|'left'|'right'|'confirm'|'gap'|'unfinished'|null}
 */
export function reviewKey(event) {
    if (!event || event.ctrlKey || event.metaKey || event.altKey) {
        return null;
    }

    const target = event.target ?? null;
    const tag = typeof target?.tagName === 'string' ? target.tagName.toUpperCase() : '';

    if (target?.isContentEditable === true || tag === 'TEXTAREA' || tag === 'SELECT') {
        return null;
    }

    if (tag === 'INPUT') {
        // The frame slider steps through the frames with ←/→ itself; ↑/↓ and the letters stay the review's
        if (String(target.type ?? '').toLowerCase() !== 'range' || event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            return null;
        }
    }

    switch (event.key) {
        case 'ArrowUp':
            return 'up';
        case 'ArrowDown':
            return 'down';
        case 'ArrowLeft':
            return 'left';
        case 'ArrowRight':
            return 'right';
        case 'c':
        case 'C':
            return 'confirm';
        case 'g':
        case 'G':
            return 'gap';
        case 'u':
        case 'U':
            return 'unfinished';
        default:
            return null;
    }
}

/**
 * The next row to look at after one is confirmed: the first one after `index` (wrapping round) that still needs a
 * look, else -1.
 *
 * @param {Array<{status: string}>} rows
 */
export function nextToReview(rows, index) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return -1;
    }

    for (let step = 1; step <= rows.length; step++) {
        const candidate = ((Number.isInteger(index) ? index : -1) + step + rows.length) % rows.length;

        if (rows[candidate] && rows[candidate].status !== 'confirmed' && rows[candidate].status !== 'no_finish') {
            return candidate;
        }
    }

    return -1;
}
