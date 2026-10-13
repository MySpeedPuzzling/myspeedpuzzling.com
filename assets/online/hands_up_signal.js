/**
 * Raised hands in one camera frame (docs/features/online-competitions/README.md §5.7 "What is detected: raised hands"):
 * the models' landmarks in, `{raised, score, reason}` out - what the finish machine (finish_machine.js) counts.
 *
 * - **Angled view** (the phone 1 m away, the puzzler's upper body in view): MediaPipe Pose Landmarker - both wrists
 *   above the shoulders. The rule never pairs a wrist with "its" shoulder: both wrists are compared with the HIGHER
 *   shoulder, so a mirrored front camera (left and right swapped, MediaPipe labels by what it sees) gives the very same
 *   answer. Heights are measured in shoulder widths (the person's own scale - near or far from the camera alike), the
 *   horizontal part of a distance corrected by the frame's aspect ratio (landmarks are normalised to the width and the
 *   height separately).
 * - **Top-down mount** (raised arms leave the frame): MediaPipe Gesture Recognizer - two `Open_Palm` hands shown to
 *   the camera. Handedness is ignored for the same reason (mirroring); the two palms must lie apart, so one hand seen
 *   twice never counts.
 * - frameSignal(): the signal of the view the set-up chose - `angled`, `topDown`, or `any` (the set-up's test raise
 *   and the bench: either model may see it).
 *
 * The score (0-1) says how clearly the hands are up: the lower wrist's height above the shoulders against FULL_LIFT,
 * times its visibility (pose); the weaker of the two palms' gesture scores (gestures). The machine's `minScore` trades
 * misses against false raises on top of `raised` - tuned on the bench (tools/online-bench/score.mjs), never guessed.
 *
 * Landmarks travel in a compact form (compactPose(), compactHands()) - the detector worker sends it, the bench stores
 * it, score.mjs recomputes the signal from it with other thresholds. Pure, no DOM - pinned by
 * tests/online-hands-up-harness.mjs.
 */

// The models these thresholds were made for, pinned - never `latest` (README §5.7): MediaPipe tasks-vision from
// jsDelivr (its ESM bundle + the WASM base the worker hands to FilesetResolver), the models at their numbered version
// path in Google's bucket. The prototype may load them from these CDNs; Phase 2 hosts them itself (WP 2.1).
export const TASKS_VISION_VERSION = '1.1.0';
export const DETECTOR_ASSETS = Object.freeze({
    tasksVisionUrl: `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${TASKS_VISION_VERSION}/vision_bundle.mjs`,
    wasmBaseUrl: `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${TASKS_VISION_VERSION}/wasm`,
    poseModelUrl: 'https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task',
    gestureModelUrl: 'https://storage.googleapis.com/mediapipe-models/gesture_recognizer/gesture_recognizer/float16/1/gesture_recognizer.task',
});

export const VIEW_ANGLED = 'angled';
export const VIEW_TOP_DOWN = 'topDown';
export const VIEW_ANY = 'any';
export const VIEWS = [VIEW_ANGLED, VIEW_TOP_DOWN, VIEW_ANY];

// MediaPipe Pose Landmarker's 33 landmarks - the ones the rule reads
export const POSE = Object.freeze({
    NOSE: 0,
    LEFT_SHOULDER: 11,
    RIGHT_SHOULDER: 12,
    LEFT_ELBOW: 13,
    RIGHT_ELBOW: 14,
    LEFT_WRIST: 15,
    RIGHT_WRIST: 16,
    LEFT_HIP: 23,
    RIGHT_HIP: 24,
});
export const POSE_LANDMARKS = 33;
export const HAND_LANDMARKS = 21;
// The hand's landmark 0 is its wrist
export const HAND_WRIST = 0;
export const OPEN_PALM = 'Open_Palm';

export const REASON_RAISED = 'raised';
export const REASON_NO_PERSON = 'no_person';
export const REASON_LOW_VISIBILITY = 'low_visibility';
export const REASON_TOO_SMALL = 'too_small';
export const REASON_ONE_HAND = 'one_hand';
export const REASON_HANDS_DOWN = 'hands_down';
export const REASON_NO_HANDS = 'no_hands';
export const REASON_NO_PALM = 'no_palm';
export const REASON_ONE_PALM = 'one_palm';
export const REASON_SAME_HAND = 'same_hand';

export const DEFAULT_POSE_OPTIONS = Object.freeze({
    // A wrist MediaPipe is less sure than this is in view is not believed (the pose model guesses hidden joints)
    minVisibility: 0.5,
    minShoulderVisibility: 0.5,
    // Both wrists at least this many shoulder widths above the higher shoulder - a quarter keeps a hand at the
    // collar (scratching the neck, adjusting glasses) out, an arm stretched up is 1.5-2.5
    minLift: 0.25,
    // The height (shoulder widths) that scores 1
    fullLift: 1,
    // A person narrower than this (shoulder width / frame height) is too far or side-on to judge
    minScale: 0.02,
    // Side-on, the shoulders overlap: half the torso (shoulders to hips) is the scale then, when the hips are seen
    torsoScale: 0.5,
});

export const DEFAULT_GESTURE_OPTIONS = Object.freeze({
    gesture: OPEN_PALM,
    // The recognizer's own threshold - its canned classifier scores a clear open palm 0.7-0.95
    minGestureScore: 0.6,
    // The two palms' wrists at least this far apart (frame heights) - one hand never counts twice
    minHandSeparation: 0.08,
});

const round = (value, decimals) => {
    const factor = 10 ** decimals;

    return Math.round(value * factor) / factor;
};

const clamp01 = (value) => Math.min(1, Math.max(0, value));

const finite = (value, fallback = 0) => (Number.isFinite(value) ? value : fallback);

/**
 * Width / height of a frame; 1 when it is not known (landmarks then compare as they are).
 */
export function aspectOf(width, height) {
    return width > 0 && height > 0 ? width / height : 1;
}

/**
 * One pose (MediaPipe NormalizedLandmark[] - {x, y, z, visibility}) as [[x, y, visibility], …] - null when there is
 * none. x and y are fractions of the frame's width and height (0.1 ‰ is ~0.2 px of a 1080p frame).
 *
 * @param {Array<{x: number, y: number, visibility?: number}>|null|undefined} landmarks
 * @returns {Array<[number, number, number]>|null}
 */
export function compactPose(landmarks) {
    if (!Array.isArray(landmarks) || landmarks.length < POSE_LANDMARKS) {
        return null;
    }

    return landmarks.slice(0, POSE_LANDMARKS).map((point) => [
        round(finite(point?.x), 4),
        round(finite(point?.y), 4),
        round(finite(point?.visibility), 3),
    ]);
}

/**
 * The hands of a MediaPipe GestureRecognizerResult as [{handedness, handednessScore, gesture, gestureScore,
 * landmarks: [[x, y], …21]}] - the top gesture of each hand.
 *
 * @param {{landmarks?: Array<Array<{x: number, y: number}>>, handedness?: Array<Array<{categoryName: string, score: number}>>, gestures?: Array<Array<{categoryName: string, score: number}>>}|null|undefined} result
 */
export function compactHands(result) {
    const landmarks = Array.isArray(result?.landmarks) ? result.landmarks : [];

    return landmarks.map((points, index) => {
        const handedness = result?.handedness?.[index]?.[0] ?? result?.handednesses?.[index]?.[0] ?? null;
        const gesture = result?.gestures?.[index]?.[0] ?? null;

        return {
            handedness: typeof handedness?.categoryName === 'string' ? handedness.categoryName : null,
            handednessScore: round(finite(handedness?.score), 3),
            gesture: typeof gesture?.categoryName === 'string' ? gesture.categoryName : null,
            gestureScore: round(finite(gesture?.score), 3),
            landmarks: (Array.isArray(points) ? points : []).slice(0, HAND_LANDMARKS).map((point) => [
                round(finite(point?.x), 4),
                round(finite(point?.y), 4),
            ]),
        };
    });
}

function poseOptions(options) {
    return { ...DEFAULT_POSE_OPTIONS, ...(options ?? {}) };
}

function gestureOptions(options) {
    return { ...DEFAULT_GESTURE_OPTIONS, ...(options ?? {}) };
}

function point(pose, index) {
    const value = pose[index];

    return Array.isArray(value) && value.length >= 2 && Number.isFinite(value[0]) && Number.isFinite(value[1])
        ? { x: value[0], y: value[1], visibility: finite(value[2]) }
        : null;
}

/**
 * Raised hands seen by the pose model: both wrists at least `minLift` shoulder widths above the higher shoulder.
 *
 * @param {Array<[number, number, number]>|null} pose  compactPose()
 * @param {object} [options]  DEFAULT_POSE_OPTIONS + `aspect` (frame width / height)
 * @returns {{raised: boolean, score: number, reason: string, lift: [number, number]|null}}  lift: the wrists' heights
 *     above the shoulders in shoulder widths, the higher one first
 */
export function poseSignal(pose, options = {}) {
    const { aspect = 1, ...rest } = options ?? {};
    const o = poseOptions(rest);

    if (!Array.isArray(pose) || pose.length < POSE_LANDMARKS) {
        return { raised: false, score: 0, reason: REASON_NO_PERSON, lift: null };
    }

    const shoulders = [point(pose, POSE.LEFT_SHOULDER), point(pose, POSE.RIGHT_SHOULDER)];
    const wrists = [point(pose, POSE.LEFT_WRIST), point(pose, POSE.RIGHT_WRIST)];

    if (shoulders.some((shoulder) => shoulder === null) || wrists.some((wrist) => wrist === null)) {
        return { raised: false, score: 0, reason: REASON_NO_PERSON, lift: null };
    }

    if (shoulders.some((shoulder) => shoulder.visibility < o.minShoulderVisibility)) {
        return { raised: false, score: 0, reason: REASON_LOW_VISIBILITY, lift: null };
    }

    const ratio = Number.isFinite(aspect) && aspect > 0 ? aspect : 1;
    const shoulderWidth = Math.hypot((shoulders[0].x - shoulders[1].x) * ratio, shoulders[0].y - shoulders[1].y);
    let scale = shoulderWidth;
    const hips = [point(pose, POSE.LEFT_HIP), point(pose, POSE.RIGHT_HIP)];

    if (hips.every((hip) => hip !== null && hip.visibility >= o.minVisibility)) {
        const torso = Math.hypot(
            ((shoulders[0].x + shoulders[1].x) / 2 - (hips[0].x + hips[1].x) / 2) * ratio,
            (shoulders[0].y + shoulders[1].y) / 2 - (hips[0].y + hips[1].y) / 2,
        );
        scale = Math.max(scale, torso * o.torsoScale);
    }

    if (!(scale >= o.minScale)) {
        return { raised: false, score: 0, reason: REASON_TOO_SMALL, lift: null };
    }

    // y grows downwards: the higher shoulder has the smaller y
    const shoulderLine = Math.min(shoulders[0].y, shoulders[1].y);
    // The higher wrist first - no left or right, so a mirrored camera reads the same
    const heights = wrists
        .map((wrist) => ({ lift: round((shoulderLine - wrist.y) / scale, 3), visibility: wrist.visibility }))
        .sort((a, b) => b.lift - a.lift);
    const lift = heights.map((height) => height.lift);

    if (heights.some((height) => height.visibility < o.minVisibility)) {
        return { raised: false, score: 0, reason: REASON_LOW_VISIBILITY, lift };
    }

    const up = lift.filter((value) => value >= o.minLift).length;

    if (up < 2) {
        return { raised: false, score: 0, reason: up === 1 ? REASON_ONE_HAND : REASON_HANDS_DOWN, lift };
    }

    const score = Math.min(...heights.map((height) => clamp01(height.lift / o.fullLift) * clamp01(height.visibility)));

    return { raised: true, score: round(score, 3), reason: REASON_RAISED, lift };
}

/**
 * Raised hands seen by the gesture model: two open palms, apart.
 *
 * @param {Array<{gesture: string|null, gestureScore: number, landmarks: Array<[number, number]>}>|null} hands  compactHands()
 * @param {object} [options]  DEFAULT_GESTURE_OPTIONS + `aspect`
 * @returns {{raised: boolean, score: number, reason: string, palms: number}}
 */
export function gestureSignal(hands, options = {}) {
    const { aspect = 1, ...rest } = options ?? {};
    const o = gestureOptions(rest);
    const list = Array.isArray(hands) ? hands.filter((hand) => hand !== null && typeof hand === 'object') : [];

    if (list.length === 0) {
        return { raised: false, score: 0, reason: REASON_NO_HANDS, palms: 0 };
    }

    const palms = list
        .filter((hand) => hand.gesture === o.gesture && finite(hand.gestureScore) >= o.minGestureScore)
        .sort((a, b) => b.gestureScore - a.gestureScore);

    if (palms.length === 0) {
        return { raised: false, score: 0, reason: REASON_NO_PALM, palms: 0 };
    }

    if (palms.length === 1) {
        return { raised: false, score: 0, reason: REASON_ONE_PALM, palms: 1 };
    }

    const ratio = Number.isFinite(aspect) && aspect > 0 ? aspect : 1;

    // The best two palms that lie apart
    for (let first = 0; first < palms.length; first++) {
        for (let second = first + 1; second < palms.length; second++) {
            const a = palms[first].landmarks?.[HAND_WRIST];
            const b = palms[second].landmarks?.[HAND_WRIST];
            const apart = Array.isArray(a) && Array.isArray(b)
                ? Math.hypot((a[0] - b[0]) * ratio, a[1] - b[1]) >= o.minHandSeparation
                : false;

            if (apart) {
                return {
                    raised: true,
                    score: round(Math.min(palms[first].gestureScore, palms[second].gestureScore), 3),
                    reason: REASON_RAISED,
                    palms: palms.length,
                };
            }
        }
    }

    return { raised: false, score: 0, reason: REASON_SAME_HAND, palms: palms.length };
}

/**
 * The frame's signal for the chosen view. `any`: whichever model sees raised hands (the higher score), else the
 * reason of the one that came closer.
 *
 * @param {{pose?: Array|null, hands?: Array|null}} landmarks
 * @param {{view?: string, aspect?: number, pose?: object, gesture?: object}} [options]
 * @returns {{raised: boolean, score: number, reason: string, view: string, angled?: object, topDown?: object}}
 */
export function frameSignal(landmarks, options = {}) {
    const view = VIEWS.includes(options?.view) ? options.view : VIEW_ANGLED;
    const aspect = options?.aspect ?? 1;
    const result = { view };

    if (view === VIEW_ANGLED || view === VIEW_ANY) {
        result.angled = poseSignal(landmarks?.pose ?? null, { ...(options?.pose ?? {}), aspect });
    }

    if (view === VIEW_TOP_DOWN || view === VIEW_ANY) {
        result.topDown = gestureSignal(landmarks?.hands ?? null, { ...(options?.gesture ?? {}), aspect });
    }

    const signals = [result.angled, result.topDown].filter((signal) => signal !== undefined);
    const best = signals.reduce((winner, signal) => {
        if (winner === null) {
            return signal;
        }

        if (signal.raised !== winner.raised) {
            return signal.raised ? signal : winner;
        }

        return signal.score > winner.score ? signal : winner;
    }, null);

    return { raised: best.raised, score: best.score, reason: best.reason, ...result };
}

/**
 * The landmarks of a pose mirrored left ↔ right (x → 1 − x, the left and right landmarks swapped) - what a mirrored
 * front camera shows. For tests and the bench's "mirror" check.
 */
export function mirrorPose(pose) {
    if (!Array.isArray(pose)) {
        return pose;
    }

    // MediaPipe's left/right pairs (eyes, ears, mouth, shoulders … feet)
    const pairs = [[1, 4], [2, 5], [3, 6], [7, 8], [9, 10], [11, 12], [13, 14], [15, 16], [17, 18], [19, 20], [21, 22], [23, 24], [25, 26], [27, 28], [29, 30], [31, 32]];
    const mirrored = pose.map((value) => (Array.isArray(value) ? [round(1 - value[0], 4), value[1], value[2]] : value));

    for (const [left, right] of pairs) {
        if (left < mirrored.length && right < mirrored.length) {
            [mirrored[left], mirrored[right]] = [mirrored[right], mirrored[left]];
        }
    }

    return mirrored;
}
