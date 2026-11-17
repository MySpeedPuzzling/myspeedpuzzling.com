// The display editor's frame maths (docs/features/competitions-management/toolkit/D-displays-overlays.md §8.2,
// competition toolkit D6) - pure, no DOM: moving, resizing from any of the 8 handles, keyboard nudges, clamping to the
// canvas, snapping to the 1 % grid and to the canvas centre/edges (R §3: no sibling snapping, no multi-select), the
// kept aspect ratio of pictures and QR codes, and the hit test of a click on the stage.
// Frames are in % of the 16:9 canvas with 2 decimals, as the design stores them (schema.js FRAME_LIMITS).
// Run under node by tests/display-editor-harness.mjs.

import { FRAME_LIMITS } from './schema.js';

export const GRID = 1;
export const NUDGE = 0.1;
export const NUDGE_BIG = 1;
export const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
export const MIN_SIZE = FRAME_LIMITS.w[0];

// Elements whose shape is their content: a resize keeps the width/height ratio (Shift frees it for pictures only)
export const RATIO_LOCKED_TYPES = ['image', 'qr'];

const DEFAULT_THRESHOLD = 0.5;
// Canvas lines an edge or a centre snaps to (no siblings in v1)
const CANVAS_LINES = [0, 50, 100];

export function round2(value) {
    const rounded = Math.round(value * 100) / 100;

    return rounded === 0 ? 0 : rounded;
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function finish(frame) {
    return {
        x: round2(frame.x),
        y: round2(frame.y),
        w: round2(frame.w),
        h: round2(frame.h),
        rotate: frame.rotate ?? 0,
    };
}

/**
 * The frame kept inside the canvas: size within [0.5, 100], position so that the whole frame is visible.
 */
export function clampFrame(frame) {
    const w = clamp(frame.w, MIN_SIZE, 100);
    const h = clamp(frame.h, MIN_SIZE, 100);

    return finish({ ...frame, w, h, x: clamp(frame.x, 0, 100 - w), y: clamp(frame.y, 0, 100 - h) });
}

/**
 * Snaps a position of a frame of `size` on one axis: its start edge, end edge or centre to a canvas line within the
 * threshold (a guide is drawn there), otherwise its start edge to the grid.
 */
function snapPosition(position, size, threshold) {
    let best = null;

    for (const line of CANVAS_LINES) {
        for (const offset of [0, size, size / 2]) {
            const distance = Math.abs(position + offset - line);

            // The centre may only meet the centre line, an edge only the canvas edges or the centre line
            if (offset === size / 2 && line !== 50 && size !== 0) {
                continue;
            }

            if (distance <= threshold && (best === null || distance < best.distance)) {
                best = { distance, position: line - offset, guide: line };
            }
        }
    }

    if (best !== null) {
        return { position: best.position, guide: best.guide };
    }

    return { position: Math.round(position / GRID) * GRID, guide: null };
}

/**
 * Snaps one moving edge: to a canvas line within the threshold (with its guide), else to the grid.
 */
function snapEdge(value, threshold) {
    for (const line of CANVAS_LINES) {
        if (Math.abs(value - line) <= threshold) {
            return { value: line, guide: line };
        }
    }

    return { value: Math.round(value / GRID) * GRID, guide: null };
}

function thresholds(options) {
    return {
        x: options.thresholdX ?? options.threshold ?? DEFAULT_THRESHOLD,
        y: options.thresholdY ?? options.threshold ?? DEFAULT_THRESHOLD,
    };
}

/**
 * A drag by (dx, dy) % from the frame at the start of the drag → {frame, guides}. Guides: [{axis: 'x'|'y', at}].
 */
export function moveFrame(start, dx, dy, options = {}) {
    let x = start.x + dx;
    let y = start.y + dy;
    const guides = [];

    if (options.snap) {
        const limit = thresholds(options);
        const snappedX = snapPosition(x, start.w, limit.x);
        const snappedY = snapPosition(y, start.h, limit.y);
        x = snappedX.position;
        y = snappedY.position;

        if (snappedX.guide !== null) {
            guides.push({ axis: 'x', at: snappedX.guide });
        }

        if (snappedY.guide !== null) {
            guides.push({ axis: 'y', at: snappedY.guide });
        }
    }

    return { frame: clampFrame({ ...start, x, y }), guides };
}

/**
 * A resize by (dx, dy) % from one of the 8 handles of the frame at the start of the drag → {frame, guides}.
 * Options: snap (moving edges to the grid / canvas lines), keepRatio (the start frame's ratio), fromCentre (the
 * opposite edges move the other way - Alt).
 */
export function resizeFrame(start, handle, dx, dy, options = {}) {
    const west = handle.includes('w');
    const east = handle.includes('e');
    const north = handle.includes('n');
    const south = handle.includes('s');
    const guides = [];
    const limit = thresholds(options);

    let left = start.x;
    let right = start.x + start.w;
    let top = start.y;
    let bottom = start.y + start.h;

    const moveEdge = (value, axis) => {
        if (!options.snap) {
            return value;
        }

        const snapped = snapEdge(value, axis === 'x' ? limit.x : limit.y);

        if (snapped.guide !== null) {
            guides.push({ axis, at: snapped.guide });
        }

        return snapped.value;
    };

    if (west) {
        left = moveEdge(left + dx, 'x');
    }

    if (east) {
        right = moveEdge(right + dx, 'x');
    }

    if (north) {
        top = moveEdge(top + dy, 'y');
    }

    if (south) {
        bottom = moveEdge(bottom + dy, 'y');
    }

    if (options.fromCentre) {
        const centreX = start.x + start.w / 2;
        const centreY = start.y + start.h / 2;

        if (west) {
            right = 2 * centreX - left;
        } else if (east) {
            left = 2 * centreX - right;
        }

        if (north) {
            bottom = 2 * centreY - top;
        } else if (south) {
            top = 2 * centreY - bottom;
        }
    }

    // Never past the canvas, never smaller than the minimum (the moving edge gives way)
    left = clamp(left, 0, 100);
    right = clamp(right, 0, 100);
    top = clamp(top, 0, 100);
    bottom = clamp(bottom, 0, 100);

    if (right - left < MIN_SIZE) {
        if (west && !options.fromCentre) {
            left = right - MIN_SIZE;
        } else {
            right = left + MIN_SIZE;
        }
    }

    if (bottom - top < MIN_SIZE) {
        if (north && !options.fromCentre) {
            top = bottom - MIN_SIZE;
        } else {
            bottom = top + MIN_SIZE;
        }
    }

    let frame = { ...start, x: left, y: top, w: right - left, h: bottom - top };

    if (options.keepRatio && start.w > 0 && start.h > 0) {
        frame = keepRatio(start, frame, { west, east, north, south, fromCentre: options.fromCentre === true });
        // A guide is only true when the snapped edge survived the ratio
        return { frame: clampFrame(frame), guides: guides.filter((guide) => onEdge(frame, guide)) };
    }

    return { frame: clampFrame(frame), guides };
}

function onEdge(frame, guide) {
    const edges = guide.axis === 'x' ? [frame.x, frame.x + frame.w] : [frame.y, frame.y + frame.h];

    return edges.some((edge) => Math.abs(edge - guide.at) < 0.01);
}

/**
 * The resized frame brought back to the start frame's ratio, anchored at the opposite corner/edge (or the centre),
 * and shrunk (ratio kept) when it would leave the canvas.
 */
function keepRatio(start, frame, sides) {
    const ratio = start.w / start.h;
    const corner = (sides.west || sides.east) && (sides.north || sides.south);
    let w = frame.w;
    let h = frame.h;

    if (corner) {
        // The axis the pointer moved more (relative to the start size) leads
        if (Math.abs(w / start.w - 1) >= Math.abs(h / start.h - 1)) {
            h = w / ratio;
        } else {
            w = h * ratio;
        }
    } else if (sides.west || sides.east) {
        h = w / ratio;
    } else {
        w = h * ratio;
    }

    // Where the frame grows from: the fixed corner/edge, or the centre
    const anchorX = sides.fromCentre || !(sides.west || sides.east)
        ? start.x + start.w / 2
        : sides.west ? start.x + start.w : start.x;
    const anchorY = sides.fromCentre || !(sides.north || sides.south)
        ? start.y + start.h / 2
        : sides.north ? start.y + start.h : start.y;
    const factorX = sides.fromCentre || !(sides.west || sides.east) ? 0.5 : sides.west ? 1 : 0;
    const factorY = sides.fromCentre || !(sides.north || sides.south) ? 0.5 : sides.north ? 1 : 0;

    // The largest size that fits the canvas from the anchor
    const room = (anchor, factor) => {
        if (factor === 0.5) {
            return 2 * Math.min(anchor, 100 - anchor);
        }

        return factor === 1 ? anchor : 100 - anchor;
    };
    const maxW = room(anchorX, factorX);
    const maxH = room(anchorY, factorY);
    const scale = Math.min(1, maxW / w, maxH / h);
    w *= scale;
    h *= scale;

    // ...and never below the minimum on either axis
    if (w < MIN_SIZE || h < MIN_SIZE) {
        const grow = Math.max(MIN_SIZE / w, MIN_SIZE / h);
        w *= grow;
        h *= grow;
    }

    return { ...frame, x: anchorX - w * factorX, y: anchorY - h * factorY, w, h };
}

/**
 * An arrow key: 0.1 % (Shift: 1 %), kept inside the canvas. Returns null for any other key.
 */
export function nudgeFrame(frame, key, big = false) {
    const step = big ? NUDGE_BIG : NUDGE;
    const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    const move = moves[key];

    if (!move) {
        return null;
    }

    return clampFrame({ ...frame, x: frame.x + move[0], y: frame.y + move[1] });
}

/**
 * Whether a resize keeps the ratio: always for a QR code, for a picture unless Shift frees it, for anything else
 * while Shift is held.
 */
export function keepsRatio(type, shiftKey) {
    if (type === 'qr') {
        return true;
    }

    return RATIO_LOCKED_TYPES.includes(type) ? !shiftKey : shiftKey;
}

/**
 * The elements top first, as the stage stacks them: higher z on top, equal z by array order (later on top).
 */
export function stackOrder(elements) {
    return elements
        .map((element, index) => ({ element, index }))
        .sort((a, b) => (b.element.z - a.element.z) || (b.index - a.index))
        .map((item) => item.element);
}

/**
 * The element under a point (% of the canvas): the topmost visible one whose frame holds it (rotation ignored), else
 * null. `isVisible(element)` decides what can be clicked (hidden elements are picked from the layers list).
 */
export function hitTest(elements, point, isVisible = (element) => !element.hidden) {
    for (const element of stackOrder(elements)) {
        const { x, y, w, h } = element.frame;

        if (isVisible(element) && point.x >= x && point.x <= x + w && point.y >= y && point.y <= y + h) {
            return element.id;
        }
    }

    return null;
}

/**
 * A pointer position or movement in screen pixels → % of the stage (rect = the stage's bounding box on screen).
 */
export function toPercent(px, py, rect) {
    return {
        x: rect.width > 0 ? (px / rect.width) * 100 : 0,
        y: rect.height > 0 ? (py / rect.height) * 100 : 0,
    };
}

const PLACE_STEP = 2.5;
const BACKDROP_AREA = 0.6;

function overlapArea(a, b) {
    const w = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
    const h = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);

    return w > 0 && h > 0 ? w * h : 0;
}

/**
 * Where a new element lands: its default frame when that overlaps nothing, else the spot of the same size (on a 2.5 %
 * grid) that overlaps the existing frames least, the nearest to the default among equals. Backdrops (frames over 60 %
 * of the canvas, e.g. a board panel) are meant to be drawn on, so they do not count.
 */
export function placeFrame(frame, others) {
    const obstacles = others.filter((other) => other.w * other.h < BACKDROP_AREA * 10000);
    const cost = (candidate) => obstacles.reduce((sum, other) => sum + overlapArea(candidate, other), 0);

    if (cost(frame) === 0) {
        return frame;
    }

    let best = frame;
    let bestCost = cost(frame);
    let bestDistance = 0;

    for (let y = 0; y + frame.h <= 100 + 1e-9; y += PLACE_STEP) {
        for (let x = 0; x + frame.w <= 100 + 1e-9; x += PLACE_STEP) {
            const candidate = { ...frame, x: round2(x), y: round2(y) };
            const candidateCost = cost(candidate);
            const distance = (candidate.x - frame.x) ** 2 + (candidate.y - frame.y) ** 2;

            if (candidateCost < bestCost - 1e-9 || (Math.abs(candidateCost - bestCost) <= 1e-9 && distance < bestDistance)) {
                best = candidate;
                bestCost = candidateCost;
                bestDistance = distance;
            }
        }
    }

    return best;
}
