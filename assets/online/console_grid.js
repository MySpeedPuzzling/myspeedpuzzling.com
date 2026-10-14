/**
 * The moderators' console of an online round with live video (docs/features/online-competitions/README.md §5.12 "The
 * moderator console", contract phase-1b.md stream VM) - the grid's pure rules, no DOM, pinned by
 * tests/online-console-harness.mjs:
 *
 * - orderTiles(): what matters first - the finished, the newest Finished tap on top -, then everybody else in the order
 *   given (online_live's: by name);
 * - pageOf(): one page of PAGE_SIZE tiles, the page asked for kept within the pages there are;
 * - subscriptionPlan(): which puzzle cameras the console receives, and how large - the page's tiles at the smallest
 *   simulcast layer (180p), the spotlight at the top one, nothing else: a camera nobody watches is not sent at all
 *   (the console subscribes to the page only; dynacast pauses the layers nobody receives on the phone);
 * - tileVideoState(): what a tile shows in place of the video, and why;
 * - stepSpotlight(): the camera before / after the spotlit one, in the console's order.
 */

export const PAGE_SIZE = 25;

// A tile asks for the 180p layer (livekit_session.js DEFAULT_SIMULCAST_LAYERS[0]) - adaptive stream may ask for less
export const TILE_DIMENSIONS = Object.freeze({ width: 320, height: 180 });

// The spotlight asks for the top layer (the camera's own resolution, at least 720p)
export const SPOTLIGHT_DIMENSIONS = Object.freeze({ width: 1280, height: 720 });

export const LAYER_LOW = 'low';
export const LAYER_HIGH = 'high';

function finishMomentOf(item) {
    if (item?.view?.finished !== true) {
        return null;
    }

    const moment = item?.participant?.finish?.momentMs;

    // Finished without a known moment: after every known one
    return typeof moment === 'number' && Number.isFinite(moment) ? moment : -Infinity;
}

/**
 * The console's order of the grid's items ({participant, view}): the finished first, the newest tap on top (a tie keeps
 * the given order), then the rest in the given order.
 *
 * @param {Array<{participant: object, view: object}>} items
 * @returns {Array<{participant: object, view: object}>} a new array
 */
export function orderTiles(items) {
    const finished = [];
    const rest = [];

    (Array.isArray(items) ? items : []).forEach((item, index) => {
        const moment = finishMomentOf(item);

        if (moment === null) {
            rest.push(item);
        } else {
            finished.push({ item, index, moment });
        }
    });

    finished.sort((a, b) => (b.moment === a.moment ? a.index - b.index : (b.moment > a.moment ? 1 : -1)));

    return [...finished.map((entry) => entry.item), ...rest];
}

/**
 * One page of the ordered items. A page out of range is the nearest page there is (the grid shrank meanwhile).
 *
 * @returns {{items: Array, page: number, pages: number, from: number, to: number, total: number}} `from` / `to` count
 *          from 1 (`from` 0 on an empty grid)
 */
export function pageOf(items, page = 0, pageSize = PAGE_SIZE) {
    const list = Array.isArray(items) ? items : [];
    const size = Number.isInteger(pageSize) && pageSize > 0 ? pageSize : PAGE_SIZE;
    const pages = Math.max(1, Math.ceil(list.length / size));
    const current = Math.min(Math.max(0, Number.isInteger(page) ? page : 0), pages - 1);
    const start = current * size;
    const slice = list.slice(start, start + size);

    return {
        items: slice,
        page: current,
        pages,
        from: slice.length === 0 ? 0 : start + 1,
        to: start + slice.length,
        total: list.length,
    };
}

/**
 * The page a participant is on in the ordered ids - null when they are not there
 */
export function pageWith(orderedIds, participantId, pageSize = PAGE_SIZE) {
    const index = Array.isArray(orderedIds) ? orderedIds.indexOf(participantId) : -1;
    const size = Number.isInteger(pageSize) && pageSize > 0 ? pageSize : PAGE_SIZE;

    return index < 0 ? null : Math.floor(index / size);
}

/**
 * Which cameras the console receives: participant id → LAYER_LOW (a tile of the page) or LAYER_HIGH (the spotlight,
 * wherever its tile is). Everybody else: not received.
 *
 * @param {string[]} pageIds
 * @param {string|null} spotlightId
 * @returns {Map<string, string>}
 */
export function subscriptionPlan(pageIds = [], spotlightId = null) {
    const plan = new Map();

    for (const id of Array.isArray(pageIds) ? pageIds : []) {
        if (typeof id === 'string' && id !== '') {
            plan.set(id, LAYER_LOW);
        }
    }

    if (typeof spotlightId === 'string' && spotlightId !== '') {
        plan.set(spotlightId, LAYER_HIGH);
    }

    return plan;
}

/**
 * The video size a layer asks LiveKit for (RemoteTrackPublication.setVideoDimensions())
 */
export function dimensionsFor(layer) {
    return layer === LAYER_HIGH ? { ...SPOTLIGHT_DIMENSIONS } : { ...TILE_DIMENSIONS };
}

/**
 * What a tile shows about its video:
 * - `connecting`: the console is not in the hall (yet, or again);
 * - `in_studio`: the participant's camera device is not in the hall because it is in the studio (an invitation they
 *   joined - the phone moved there);
 * - `not_joined`: the participant's camera device is not in the hall;
 * - `no_camera`: it is, but publishes no camera (its camera could not be published, or it has none);
 * - `paused`: the camera is muted (a phone's page in the background, say);
 * - `loading`: received, the first frame not there yet;
 * - `playing`.
 *
 * @param {{connected?: boolean, entry?: object|null, playing?: boolean, inStudio?: boolean}} state `entry`: the
 *        participant's camera device in the room (livekit_session.js participantsOf()), its `camera` a publication or
 *        null; `inStudio`: their invitation to the studio is joined
 */
export function tileVideoState({ connected = false, entry = null, playing = false, inStudio = false } = {}) {
    if (connected !== true) {
        return 'connecting';
    }

    if (!entry) {
        return inStudio === true ? 'in_studio' : 'not_joined';
    }

    const camera = entry.camera ?? null;

    if (!camera) {
        return 'no_camera';
    }

    if (camera.isMuted === true) {
        return 'paused';
    }

    return playing === true ? 'playing' : 'loading';
}

/**
 * The camera `step` places after (1) / before (-1) the spotlit one in the console's order - the same one at either
 * end, null when the spotlit one is not in the order (any more)
 */
export function stepSpotlight(orderedIds, currentId, step) {
    const ids = Array.isArray(orderedIds) ? orderedIds : [];
    const index = ids.indexOf(currentId);

    if (index < 0) {
        return null;
    }

    const next = Math.min(ids.length - 1, Math.max(0, index + (step < 0 ? -1 : 1)));

    return ids[next];
}
