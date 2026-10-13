/**
 * The S1 label file (docs/features/online-competitions/spikes.md "S1 - footage protocol", "Labels"): one small text
 * file per video, `key: value` lines, `#` starts a comment. Read and written here - by the bench page (labelling mode)
 * and by score.mjs.
 *
 * ```
 * video: 07.mp4
 * placement: angled
 * empty_mat: 00:00:01-00:00:05
 * last_piece_placed: 00:12:41.5
 * hands_up: 00:12:42.2
 * complete: yes            # or: no - 1 piece missing
 * ```
 *
 * **The list form** (backward compatible): `hands_up` may appear several times, one line per raise, for videos with
 * premature raises. Each value is a time or a range `start-end` (when the hands went down - optional), optionally
 * followed by `complete` or `incomplete` (`yes` / `no` work too). A raise without that word is `incomplete` unless it is
 * the last one - the last one takes the file's `complete:`. So the one-raise form of S1 reads exactly as before.
 *
 * ```
 * hands_up: 00:10:05.0-00:10:06.1 incomplete   # a piece was still missing
 * hands_up: 00:12:42.2 complete
 * ```
 *
 * Times: `HH:MM:SS(.s…)`, `MM:SS(.s…)` or seconds (`762.2`) - written as `HH:MM:SS.s…` to the millisecond the bench
 * knows (the frame's own time), trailing zeros left out (`00:12:42.2`, `00:00:16.456`).
 * Pure - pinned by tests/online-bench-harness.mjs.
 */

export const KNOWN_KEYS = ['video', 'phone', 'placement', 'light', 'puzzle', 'empty_mat', 'last_piece_placed', 'hands_up', 'complete', 'notes'];

/**
 * A time as milliseconds - null when it is no time.
 */
export function parseTime(text) {
    const value = String(text ?? '').trim();

    if (/^\d+(\.\d+)?$/.test(value)) {
        return Math.round(parseFloat(value) * 1000);
    }

    const match = value.match(/^(?:(\d+):)?(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/);

    if (match === null) {
        return null;
    }

    const hours = match[1] === undefined ? 0 : parseInt(match[1], 10);
    const minutes = parseInt(match[2], 10);
    const seconds = parseFloat(match[3]);

    if (minutes >= 60 || seconds >= 60) {
        return null;
    }

    return Math.round(((hours * 60 + minutes) * 60 + seconds) * 1000);
}

/**
 * Milliseconds as HH:MM:SS.t - `decimals` digits of the second (1: tenths, the video player's), rounded to the nearest
 * (labels are about moments, not elapsed times).
 */
export function formatTime(ms, decimals = 1) {
    if (!Number.isFinite(ms) || ms < 0) {
        return '';
    }

    const digits = Math.min(3, Math.max(1, Math.trunc(decimals)));
    const unit = 10 ** (3 - digits);
    const steps = Math.round(ms / unit);
    const perSecond = 10 ** digits;
    const totalSeconds = Math.floor(steps / perSecond);
    const pad = (value, length = 2) => String(value).padStart(length, '0');

    return `${pad(Math.floor(totalSeconds / 3600))}:${pad(Math.floor(totalSeconds / 60) % 60)}:${pad(totalSeconds % 60)}.${pad(steps % perSecond, digits)}`;
}

/**
 * A moment as the label file stores it: to the millisecond the bench knows (the frame's own time), without trailing
 * zeros - `00:12:42.2`, `00:00:16.456`.
 */
export function formatLabelTime(ms) {
    return formatTime(ms, 3).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '.0');
}

/**
 * `a-b` or `a` → {fromMs, toMs} (toMs null without an end); null when it is no time.
 */
export function parseRange(text) {
    const value = String(text ?? '').trim();
    const parts = value.split(/\s*-\s*/);

    if (parts.length === 1) {
        const at = parseTime(parts[0]);

        return at === null ? null : { fromMs: at, toMs: null };
    }

    if (parts.length !== 2) {
        return null;
    }

    const fromMs = parseTime(parts[0]);
    const toMs = parseTime(parts[1]);

    return fromMs === null || toMs === null || toMs < fromMs ? null : { fromMs, toMs };
}

/**
 * `yes` / `complete` → true, `no …` / `incomplete` → false; anything else → null. "no - 1 piece missing" also tells
 * how many: parseComplete() returns that in `missing`.
 */
export function parseComplete(text) {
    const value = String(text ?? '').trim().toLowerCase();

    if (value === 'yes' || value === 'complete' || value === 'y' || value === 'true') {
        return { complete: true, missing: 0 };
    }

    if (value.startsWith('no') || value.startsWith('incomplete') || value === 'false') {
        const count = value.match(/(\d+)\s*pieces?\s*missing/);

        return { complete: false, missing: count === null ? null : parseInt(count[1], 10) };
    }

    return { complete: null, missing: null };
}

function stripComment(line) {
    const index = line.search(/(^|\s)#/);

    return index === -1 ? line : line.slice(0, index);
}

/**
 * A label file's text → {video, phone, placement, light, puzzle, emptyMat, lastPiecePlacedMs, raises, complete,
 * missing, notes, extra, warnings}. raises: [{atMs, toMs, complete}] in time order.
 *
 * @param {string} text
 */
export function parseLabels(text) {
    const labels = {
        video: null,
        phone: null,
        placement: null,
        light: null,
        puzzle: null,
        emptyMat: null,
        lastPiecePlacedMs: null,
        raises: [],
        complete: null,
        missing: null,
        notes: null,
        extra: {},
        warnings: [],
    };
    const raw = [];

    String(text ?? '').split(/\r?\n/).forEach((line, index) => {
        const content = stripComment(line).trim();

        if (content === '') {
            return;
        }

        const colon = content.indexOf(':');

        if (colon <= 0) {
            labels.warnings.push(`line ${index + 1}: no "key: value"`);

            return;
        }

        const key = content.slice(0, colon).trim().toLowerCase();
        const value = content.slice(colon + 1).trim();

        switch (key) {
            case 'video':
            case 'phone':
            case 'placement':
            case 'light':
            case 'puzzle':
            case 'notes':
                labels[key] = value;
                break;
            case 'empty_mat': {
                const range = parseRange(value);

                if (range === null) {
                    labels.warnings.push(`line ${index + 1}: empty_mat is no time range`);
                } else {
                    labels.emptyMat = range;
                }
                break;
            }
            case 'last_piece_placed': {
                const at = parseTime(value);

                if (at === null) {
                    labels.warnings.push(`line ${index + 1}: last_piece_placed is no time`);
                } else {
                    labels.lastPiecePlacedMs = at;
                }
                break;
            }
            case 'hands_up': {
                const [when, word = null, ...more] = value.split(/\s+/);
                const range = parseRange(when);
                const completeness = word === null ? { complete: null } : parseComplete(word);

                if (range === null || more.length > 0 || (word !== null && completeness.complete === null)) {
                    labels.warnings.push(`line ${index + 1}: hands_up is "time[-end] [complete|incomplete]"`);
                } else {
                    raw.push({ atMs: range.fromMs, toMs: range.toMs, complete: completeness.complete });
                }
                break;
            }
            case 'complete': {
                const completeness = parseComplete(value);
                labels.complete = completeness.complete;
                labels.missing = completeness.missing;
                break;
            }
            default:
                labels.extra[key] = value;
        }
    });

    raw.sort((a, b) => a.atMs - b.atMs);
    labels.raises = raw.map((raise, index) => ({
        ...raise,
        complete: raise.complete ?? (index === raw.length - 1 ? labels.complete : false),
    }));

    return labels;
}

/**
 * Labels → the file's text (the S1 order; several raises as the list form).
 */
export function formatLabels(labels) {
    const lines = [];
    const add = (key, value) => {
        if (value !== null && value !== undefined && value !== '') {
            lines.push(`${key}: ${value}`);
        }
    };

    add('video', labels.video);
    add('phone', labels.phone);
    add('placement', labels.placement);
    add('light', labels.light);
    add('puzzle', labels.puzzle);

    if (labels.emptyMat && Number.isFinite(labels.emptyMat.fromMs)) {
        add('empty_mat', Number.isFinite(labels.emptyMat.toMs)
            ? `${formatLabelTime(labels.emptyMat.fromMs)}-${formatLabelTime(labels.emptyMat.toMs)}`
            : formatLabelTime(labels.emptyMat.fromMs));
    }

    if (Number.isFinite(labels.lastPiecePlacedMs)) {
        add('last_piece_placed', formatLabelTime(labels.lastPiecePlacedMs));
    }

    const raises = [...(labels.raises ?? [])].sort((a, b) => a.atMs - b.atMs);

    raises.forEach((raise, index) => {
        const when = Number.isFinite(raise.toMs) ? `${formatLabelTime(raise.atMs)}-${formatLabelTime(raise.toMs)}` : formatLabelTime(raise.atMs);
        // The word only where the reader would not infer it - so one raise is written exactly in the S1 form
        const inferred = index === raises.length - 1 ? (labels.complete ?? null) : false;
        const word = raise.complete === inferred || raise.complete === null || raise.complete === undefined
            ? ''
            : (raise.complete ? ' complete' : ' incomplete');
        add('hands_up', `${when}${word}`);
    });

    if (labels.complete === true) {
        add('complete', 'yes');
    } else if (labels.complete === false) {
        add('complete', Number.isInteger(labels.missing) && labels.missing > 0 ? `no - ${labels.missing} piece${labels.missing === 1 ? '' : 's'} missing` : 'no');
    }

    for (const [key, value] of Object.entries(labels.extra ?? {})) {
        if (!KNOWN_KEYS.includes(key)) {
            add(key, value);
        }
    }

    add('notes', labels.notes);

    return `${lines.join('\n')}\n`;
}

/**
 * Empty labels for a video.
 */
export function emptyLabels(video = null) {
    return parseLabels(video === null ? '' : `video: ${video}`);
}
