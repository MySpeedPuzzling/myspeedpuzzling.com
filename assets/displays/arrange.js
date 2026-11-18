// The display editor's group maths (competition toolkit v2 E1, docs/features/competitions-management/displays.md
// "Editor (D6)") - pure, no DOM: the bounds of a multi-selection, moving / resizing / nudging it as one group, align
// (6 edges) and distribute (equal gaps), and the area selection (marquee). Frames are in % of the canvas with 2
// decimals (geometry.js); rotation is ignored, as the hit test and the selection box do.
// Items are [{id, type, frame}]; results are {[id]: frame} maps, so EditorState commits them as one undo step.
// Run under node by tests/display-editor-harness.mjs.

import { clampFrame, moveFrame, nudgeFrame, resizeFrame, round2 } from './geometry.js';

/**
 * The box around frames: {x, y, w, h}, or null for none.
 */
export function boundsOf(frames) {
    if (frames.length === 0) {
        return null;
    }

    const left = Math.min(...frames.map((frame) => frame.x));
    const top = Math.min(...frames.map((frame) => frame.y));
    const right = Math.max(...frames.map((frame) => frame.x + frame.w));
    const bottom = Math.max(...frames.map((frame) => frame.y + frame.h));

    return { x: round2(left), y: round2(top), w: round2(right - left), h: round2(bottom - top) };
}

function shifted(items, dx, dy) {
    const frames = {};

    for (const item of items) {
        frames[item.id] = clampFrame({ ...item.frame, x: item.frame.x + dx, y: item.frame.y + dy });
    }

    return frames;
}

/**
 * A drag of the whole group by (dx, dy) % → {frames, guides}: the group's edges/centre snap like one frame (canvas +
 * `options.lines`), the group stays inside the canvas as a whole, and every member moves by the same delta - the
 * spacing never distorts at a canvas edge.
 */
export function moveGroup(items, dx, dy, options = {}) {
    const bounds = boundsOf(items.map((item) => item.frame));

    if (bounds === null) {
        return { frames: {}, guides: [] };
    }

    const { frame, guides } = moveFrame({ ...bounds, rotate: 0 }, dx, dy, options);

    return { frames: shifted(items, frame.x - bounds.x, frame.y - bounds.y), guides };
}

/**
 * A resize of the whole group from one of the 8 handles → {frames, guides}: the group's box resizes like one frame
 * (resizeFrame options: snap, lines, keepRatio, fromCentre) and every member is mapped proportionally into it. Font
 * sizes are not scaled (logical pixels; `fit: shrink` exists for that).
 */
export function resizeGroup(items, handle, dx, dy, options = {}) {
    const bounds = boundsOf(items.map((item) => item.frame));

    if (bounds === null || bounds.w <= 0 || bounds.h <= 0) {
        return { frames: {}, guides: [] };
    }

    const { frame: box, guides } = resizeFrame({ ...bounds, rotate: 0 }, handle, dx, dy, options);
    const sx = box.w / bounds.w;
    const sy = box.h / bounds.h;
    const frames = {};

    for (const item of items) {
        const { x, y, w, h } = item.frame;
        frames[item.id] = clampFrame({
            ...item.frame,
            x: box.x + (x - bounds.x) * sx,
            y: box.y + (y - bounds.y) * sy,
            w: w * sx,
            h: h * sy,
        });
    }

    return { frames, guides };
}

/**
 * Whether a group resize keeps the ratio - keepsRatio() lifted to a group: a QR code inside → always; else a picture
 * inside → unless Shift; else while Shift is held. A picture in the group is never squashed by default.
 */
export function groupKeepsRatio(types, shiftKey) {
    if (types.includes('qr')) {
        return true;
    }

    return types.includes('image') ? !shiftKey : shiftKey;
}

/**
 * An arrow key for the group: one step (Shift: bigger) for every member, kept inside the canvas as a group. Returns
 * null for any other key.
 */
export function nudgeGroup(items, key, big = false) {
    const bounds = boundsOf(items.map((item) => item.frame));

    if (bounds === null) {
        return null;
    }

    const moved = nudgeFrame({ ...bounds, rotate: 0 }, key, big);

    if (moved === null) {
        return null;
    }

    return shifted(items, moved.x - bounds.x, moved.y - bounds.y);
}

export const ALIGN_EDGES = ['left', 'center', 'right', 'top', 'middle', 'bottom'];

function changedOnly(items, frames) {
    const changed = {};

    for (const item of items) {
        const next = frames[item.id];

        if (next && (next.x !== item.frame.x || next.y !== item.frame.y || next.w !== item.frame.w || next.h !== item.frame.h)) {
            changed[item.id] = next;
        }
    }

    return changed;
}

/**
 * Align: 2 or more items to the edge/centre of their bounds; exactly 1 item to the canvas (0 / 50 / 100). Only the
 * frames that change are returned.
 */
export function alignFrames(items, edge) {
    if (items.length === 0 || !ALIGN_EDGES.includes(edge)) {
        return {};
    }

    const bounds = items.length === 1 ? { x: 0, y: 0, w: 100, h: 100 } : boundsOf(items.map((item) => item.frame));
    const frames = {};

    for (const item of items) {
        const frame = { ...item.frame };

        switch (edge) {
            case 'left':
                frame.x = bounds.x;
                break;
            case 'center':
                frame.x = bounds.x + bounds.w / 2 - frame.w / 2;
                break;
            case 'right':
                frame.x = bounds.x + bounds.w - frame.w;
                break;
            case 'top':
                frame.y = bounds.y;
                break;
            case 'middle':
                frame.y = bounds.y + bounds.h / 2 - frame.h / 2;
                break;
            default:
                frame.y = bounds.y + bounds.h - frame.h;
        }

        frames[item.id] = clampFrame(frame);
    }

    return changedOnly(items, frames);
}

/**
 * Distribute with equal gaps (3 or more items, else null): sorted by their start edge (ties: centre, then id), the
 * first and the item reaching furthest stay, the others are spaced so every gap is (span − Σ sizes) / (n − 1) - a
 * negative gap is an equal overlap. Positions are accumulated unrounded and rounded per item.
 */
export function distributeFrames(items, axis) {
    if (items.length < 3 || (axis !== 'horizontal' && axis !== 'vertical')) {
        return null;
    }

    const pos = axis === 'horizontal' ? 'x' : 'y';
    const size = axis === 'horizontal' ? 'w' : 'h';
    const sorted = [...items].sort((a, b) => (a.frame[pos] - b.frame[pos])
        || ((a.frame[pos] + a.frame[size] / 2) - (b.frame[pos] + b.frame[size] / 2))
        || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
    const first = sorted[0];
    const rest = sorted.slice(1);
    // The item reaching furthest ends the row (the first one among equals in the sorted order stays last)
    let lastIndex = 0;

    rest.forEach((item, index) => {
        const end = item.frame[pos] + item.frame[size];
        const best = rest[lastIndex].frame[pos] + rest[lastIndex].frame[size];

        if (end > best + 1e-9) {
            lastIndex = index;
        }
    });

    const last = rest.splice(lastIndex, 1)[0];
    const order = [first, ...rest, last];
    const start = first.frame[pos];
    const end = Math.max(...items.map((item) => item.frame[pos] + item.frame[size]));
    const total = items.reduce((sum, item) => sum + item.frame[size], 0);
    const gap = (end - start - total) / (items.length - 1);
    const frames = {};
    let cursor = start;

    for (const item of order) {
        frames[item.id] = clampFrame({ ...item.frame, [pos]: cursor });
        cursor += item.frame[size] + gap;
    }

    return changedOnly(items, frames);
}

/**
 * The area of a drag between two points (% of the canvas), from any direction, kept inside the canvas.
 */
export function marqueeRect(a, b) {
    const clamp = (value) => Math.max(0, Math.min(100, value));
    const left = clamp(Math.min(a.x, b.x));
    const top = clamp(Math.min(a.y, b.y));
    const right = clamp(Math.max(a.x, b.x));
    const bottom = clamp(Math.max(a.y, b.y));

    return { x: left, y: top, w: right - left, h: bottom - top };
}

/**
 * The elements an area selects: not hidden, not locked, their frame overlapping the area (touching is not enough),
 * in document order.
 */
export function marqueeHits(elements, rect) {
    return elements
        .filter((element) => {
            if (element.hidden || element.locked) {
                return false;
            }

            const { x, y, w, h } = element.frame;
            const overlapW = Math.min(x + w, rect.x + rect.w) - Math.max(x, rect.x);
            const overlapH = Math.min(y + h, rect.y + rect.h) - Math.max(y, rect.y);

            return overlapW > 0 && overlapH > 0;
        })
        .map((element) => element.id);
}
