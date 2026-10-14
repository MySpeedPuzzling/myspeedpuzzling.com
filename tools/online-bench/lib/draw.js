/**
 * The bench page's drawings (tools/online-bench/index.html): the landmarks over the video and the signal timeline.
 * Plain 2D canvas, no state - everything comes in as arguments.
 */

import { HAND_WRIST, POSE } from '../../../assets/online/hands_up_signal.js';

const COLORS = {
    raised: '#1f9d55',
    down: '#9aa5b1',
    none: '#d64545',
    pose: '#ffd166',
    wrist: '#ef476f',
    hand: '#06d6a0',
    shoulderLine: '#118ab2',
    candidate: '#1f9d55',
    short: '#f3a712',
    suppressed: '#9b5de5',
    gap: '#d64545',
    label: '#3a86ff',
    labelIncomplete: '#f3722c',
    lastPiece: '#9b5de5',
    emptyMat: 'rgba(58, 134, 255, 0.18)',
    playhead: '#d64545',
    text: '#1d2733',
    grid: '#e4e8ee',
};

// The pose's bones worth drawing (arms, shoulders, torso)
const BONES = [
    [POSE.LEFT_SHOULDER, POSE.RIGHT_SHOULDER],
    [POSE.LEFT_SHOULDER, POSE.LEFT_ELBOW],
    [POSE.LEFT_ELBOW, POSE.LEFT_WRIST],
    [POSE.RIGHT_SHOULDER, POSE.RIGHT_ELBOW],
    [POSE.RIGHT_ELBOW, POSE.RIGHT_WRIST],
    [POSE.LEFT_SHOULDER, POSE.LEFT_HIP],
    [POSE.RIGHT_SHOULDER, POSE.RIGHT_HIP],
    [POSE.LEFT_HIP, POSE.RIGHT_HIP],
];

/**
 * Where the video's picture lies inside its element (object-fit: contain).
 */
export function contentBox(elementWidth, elementHeight, videoWidth, videoHeight) {
    if (!(videoWidth > 0) || !(videoHeight > 0)) {
        return { x: 0, y: 0, width: elementWidth, height: elementHeight };
    }

    const scale = Math.min(elementWidth / videoWidth, elementHeight / videoHeight);
    const width = videoWidth * scale;
    const height = videoHeight * scale;

    return { x: (elementWidth - width) / 2, y: (elementHeight - height) / 2, width, height };
}

/**
 * The frame's landmarks and signal over the video.
 *
 * @param {CanvasRenderingContext2D} context
 * @param {{x: number, y: number, width: number, height: number}} box   the picture inside the canvas
 * @param {object|null} frame   a bench frame: {pose, hands, signal, angled, topDown}
 * @param {{mirrored?: boolean, minLift?: number}} [options]
 */
export function drawOverlay(context, box, frame, { mirrored = false } = {}) {
    const { canvas } = context;
    context.clearRect(0, 0, canvas.width, canvas.height);

    if (frame === null || frame === undefined) {
        return;
    }

    const at = ([x, y]) => [box.x + (mirrored ? 1 - x : x) * box.width, box.y + y * box.height];
    const pose = frame.pose;

    if (Array.isArray(pose)) {
        context.lineWidth = 3;

        for (const [a, b] of BONES) {
            const visibility = Math.min(pose[a]?.[2] ?? 0, pose[b]?.[2] ?? 0);
            context.strokeStyle = COLORS.pose;
            context.globalAlpha = 0.25 + 0.75 * Math.max(0, Math.min(1, visibility));
            context.beginPath();
            context.moveTo(...at(pose[a]));
            context.lineTo(...at(pose[b]));
            context.stroke();
        }

        context.globalAlpha = 1;

        for (const index of [POSE.LEFT_WRIST, POSE.RIGHT_WRIST]) {
            const [x, y] = at(pose[index]);
            context.fillStyle = COLORS.wrist;
            context.beginPath();
            context.arc(x, y, 7, 0, Math.PI * 2);
            context.fill();
        }

        // The line both wrists must be above: the higher shoulder
        const shoulderY = Math.min(pose[POSE.LEFT_SHOULDER][1], pose[POSE.RIGHT_SHOULDER][1]);
        context.strokeStyle = COLORS.shoulderLine;
        context.setLineDash([8, 6]);
        context.lineWidth = 2;
        context.beginPath();
        context.moveTo(box.x, box.y + shoulderY * box.height);
        context.lineTo(box.x + box.width, box.y + shoulderY * box.height);
        context.stroke();
        context.setLineDash([]);
    }

    for (const hand of Array.isArray(frame.hands) ? frame.hands : []) {
        context.fillStyle = COLORS.hand;

        for (const point of hand.landmarks ?? []) {
            const [x, y] = at(point);
            context.beginPath();
            context.arc(x, y, 3, 0, Math.PI * 2);
            context.fill();
        }

        const wrist = hand.landmarks?.[HAND_WRIST];

        if (wrist) {
            const [x, y] = at(wrist);
            context.font = '600 14px system-ui, sans-serif';
            context.fillStyle = '#fff';
            context.strokeStyle = '#000';
            context.lineWidth = 3;
            const text = `${hand.gesture ?? '?'} ${(hand.gestureScore ?? 0).toFixed(2)}`;
            context.strokeText(text, x + 8, y + 18);
            context.fillText(text, x + 8, y + 18);
        }
    }

    const signal = frame.signal;

    if (signal) {
        const text = `${signal.raised ? 'RAISED' : 'down'} ${(signal.score ?? 0).toFixed(2)} - ${signal.reason}`;
        context.font = '700 18px system-ui, sans-serif';
        const width = context.measureText(text).width + 20;
        context.fillStyle = signal.raised ? COLORS.raised : 'rgba(29, 39, 51, 0.75)';
        context.fillRect(box.x + 10, box.y + 10, width, 32);
        context.fillStyle = '#fff';
        context.fillText(text, box.x + 20, box.y + 32);
    }
}

/**
 * The timeline: one track per signal, the machine's events, the labels, the playhead.
 *
 * @param {CanvasRenderingContext2D} context
 * @param {{durationMs: number, frames: object[], events: object[], labels: object|null, currentMs: number, view: string}} data
 */
export function drawTimeline(context, { durationMs, frames, events, labels, currentMs }) {
    const { canvas } = context;
    const width = canvas.width;
    const height = canvas.height;
    const left = 70;
    const plot = width - left - 10;
    const x = (ms) => left + (durationMs > 0 ? (ms / durationMs) * plot : 0);
    const tracks = [
        { name: 'signal', key: 'signal' },
        { name: 'angled', key: 'angled' },
        { name: 'topDown', key: 'topDown' },
    ];
    const trackHeight = 22;
    const top = 8;

    context.clearRect(0, 0, width, height);
    context.font = '12px system-ui, sans-serif';
    context.textBaseline = 'middle';

    // Seconds grid
    context.strokeStyle = COLORS.grid;
    context.lineWidth = 1;
    const stepSeconds = durationMs > 120_000 ? 30 : (durationMs > 30_000 ? 5 : 1);

    for (let second = 0; second * 1000 <= durationMs; second += stepSeconds) {
        context.beginPath();
        context.moveTo(x(second * 1000), top);
        context.lineTo(x(second * 1000), height - 18);
        context.stroke();
        context.fillStyle = COLORS.text;
        context.fillText(`${second}s`, x(second * 1000) + 2, height - 8);
    }

    // Labels: the empty mat (band), the last piece (line), the raises (dashed, complete green / incomplete orange)
    if (labels?.emptyMat && Number.isFinite(labels.emptyMat.fromMs)) {
        context.fillStyle = COLORS.emptyMat;
        const end = Number.isFinite(labels.emptyMat.toMs) ? labels.emptyMat.toMs : labels.emptyMat.fromMs + 200;
        context.fillRect(x(labels.emptyMat.fromMs), top, Math.max(2, x(end) - x(labels.emptyMat.fromMs)), height - 26);
    }

    tracks.forEach((track, index) => {
        const y = top + index * (trackHeight + 4);
        context.fillStyle = COLORS.text;
        context.fillText(track.name, 4, y + trackHeight / 2);

        for (let frameIndex = 0; frameIndex < frames.length; frameIndex++) {
            const frame = frames[frameIndex];
            const signal = frame[track.key];

            if (!signal) {
                continue;
            }

            const next = frames[frameIndex + 1];
            const from = x(frame.t);
            const to = next ? x(next.t) : from + 2;

            if (signal.raised) {
                context.fillStyle = COLORS.raised;
                context.globalAlpha = 0.35 + 0.65 * Math.max(0, Math.min(1, signal.score ?? 1));
            } else {
                context.fillStyle = signal.reason === 'no_person' || signal.reason === 'no_hands' ? COLORS.none : COLORS.down;
                context.globalAlpha = signal.reason === 'no_person' || signal.reason === 'no_hands' ? 0.35 : 0.5;
            }

            context.fillRect(from, y, Math.max(1, to - from), trackHeight);
        }

        context.globalAlpha = 1;
    });

    const eventsTop = top + tracks.length * (trackHeight + 4);
    context.fillStyle = COLORS.text;
    context.fillText('machine', 4, eventsTop + 10);

    for (const event of events ?? []) {
        if (event.type === 'candidate') {
            context.strokeStyle = COLORS.candidate;
            context.lineWidth = 3;
            context.beginPath();
            context.moveTo(x(event.atMs), top);
            context.lineTo(x(event.atMs), eventsTop + 20);
            context.stroke();
            context.fillStyle = COLORS.candidate;
            context.beginPath();
            context.moveTo(x(event.detectedAtMs), eventsTop + 20);
            context.lineTo(x(event.detectedAtMs) - 5, eventsTop + 28);
            context.lineTo(x(event.detectedAtMs) + 5, eventsTop + 28);
            context.fill();
        } else if (event.type === 'raise_short' || event.type === 'raise_suppressed') {
            context.fillStyle = event.type === 'raise_short' ? COLORS.short : COLORS.suppressed;
            context.fillRect(x(event.atMs) - 1, eventsTop + 4, 3, 12);
        } else if (event.type === 'gap') {
            context.fillStyle = COLORS.gap;
            context.fillRect(x(event.fromMs), eventsTop + 16, Math.max(2, x(event.toMs) - x(event.fromMs)), 3);
        }
    }

    for (const raise of labels?.raises ?? []) {
        context.strokeStyle = raise.complete === false ? COLORS.labelIncomplete : COLORS.label;
        context.lineWidth = 2;
        context.setLineDash([4, 3]);
        context.beginPath();
        context.moveTo(x(raise.atMs), top);
        context.lineTo(x(raise.atMs), height - 18);
        context.stroke();
        context.setLineDash([]);

        if (Number.isFinite(raise.toMs)) {
            context.fillStyle = raise.complete === false ? COLORS.labelIncomplete : COLORS.label;
            context.globalAlpha = 0.25;
            context.fillRect(x(raise.atMs), height - 24, Math.max(2, x(raise.toMs) - x(raise.atMs)), 5);
            context.globalAlpha = 1;
        }
    }

    if (Number.isFinite(labels?.lastPiecePlacedMs)) {
        context.strokeStyle = COLORS.lastPiece;
        context.lineWidth = 2;
        context.beginPath();
        context.moveTo(x(labels.lastPiecePlacedMs), top);
        context.lineTo(x(labels.lastPiecePlacedMs), height - 18);
        context.stroke();
    }

    if (Number.isFinite(currentMs)) {
        context.strokeStyle = COLORS.playhead;
        context.lineWidth = 2;
        context.beginPath();
        context.moveTo(x(currentMs), 0);
        context.lineTo(x(currentMs), height);
        context.stroke();
    }
}

/**
 * The moment under a click on the timeline.
 */
export function timeAtX(clickX, width, durationMs) {
    const left = 70;
    const plot = width - left - 10;

    return Math.max(0, Math.min(durationMs, ((clickX - left) / plot) * durationMs));
}
