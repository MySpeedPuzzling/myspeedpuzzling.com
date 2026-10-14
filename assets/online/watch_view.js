/**
 * What the public watch page of a round's studio shows (docs/features/online-competitions/contracts/phase-1b.md §5
 * `online_watch`) - the plain part of assets/controllers/online_watch_controller.js: LiveKit's participants
 * (livekit_session.js participantsOf()), the token endpoint's answers and the round's clock in; the tiles, the sounds,
 * what the curtain over the stage says, the number watching and when to try again out. No DOM, no timers - pinned by
 * tests/online-watch-harness.mjs.
 */

import { clockText, countdownText } from './compete_flow.js';

// After a failure (a network error, a server error, a lost connection to the studio) the page tries again after these
// waits, growing to a minute, each ± JITTER - so a studio's spectators cut off together come back spread out. Never a
// reload: a page that cannot connect says why and keeps trying.
export const RETRY_MS = [2000, 5000, 10000, 20000, 30000, 60000];
export const JITTER = 0.2;
// A connection that held this long starts the waits of the next failure from the first again
export const STABLE_MS = 60000;
// "The studio is full - try again in a minute": the page tries again by itself after about that long
export const FULL_RETRY_MS = 60000;
// The round's live video switched off meanwhile: asked again this rarely
export const UNAVAILABLE_RETRY_MS = 120000;
// Too many requests from this address: the server's Retry-After (this when it gives none), plus up to this much more
export const LIMITED_RETRY_MS = 60000;
export const LIMITED_SPREAD_MS = 2000;
// The studio's tiles are video frames: 16:9
export const TILE_ASPECT = 16 / 9;

// Where the page stands with the studio
export const CONNECTING = 'connecting';
export const CONNECTED = 'connected';
export const FULL = 'full';
export const UNAVAILABLE = 'unavailable';
export const GONE = 'gone';
export const RETRYING = 'retrying';

/**
 * `ms` ± JITTER of it - random() in [0, 1)
 */
export function jittered(ms, random = Math.random) {
    const value = Number(random());
    const share = Number.isFinite(value) ? Math.min(1, Math.max(0, value)) : 0.5;

    return Math.round(ms * (1 - JITTER + 2 * JITTER * share));
}

/**
 * The wait before the next try after `attempt` failures in a row (0 = the first failure)
 */
export function retryDelayMs(attempt, random = Math.random) {
    const index = Number.isInteger(attempt) && attempt > 0 ? Math.min(attempt, RETRY_MS.length - 1) : 0;

    return jittered(RETRY_MS[index], random);
}

/**
 * What a refused token request means for the page (`online_watch_token`): {state, retryInMs} - retryInMs null = never
 * by itself.
 * - 409 `studio_full` → FULL, again in about a minute;
 * - 409 `video_unavailable` → UNAVAILABLE, again in a couple of minutes;
 * - 404 → GONE (the round's page is not there any more, or its event went back to a draft): no more tries;
 * - 429 → RETRYING after the server's Retry-After;
 * - anything else (offline, a server error, an answer that is no token) → RETRYING after retryDelayMs(attempt).
 *
 * @param {{status: number, error?: string|null, retryAfter?: string|number|null}} answer
 */
export function tokenRefusal({ status, error = null, retryAfter = null } = {}, attempt = 0, random = Math.random) {
    if (status === 409 && error === 'studio_full') {
        return { state: FULL, retryInMs: jittered(FULL_RETRY_MS, random) };
    }

    if (status === 409 && error === 'video_unavailable') {
        return { state: UNAVAILABLE, retryInMs: jittered(UNAVAILABLE_RETRY_MS, random) };
    }

    if (status === 404) {
        return { state: GONE, retryInMs: null };
    }

    if (status === 429) {
        const seconds = Number.parseInt(String(retryAfter ?? ''), 10);
        const waitMs = Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : LIMITED_RETRY_MS;
        const spread = Number(random());

        return { state: RETRYING, retryInMs: waitMs + Math.round(LIMITED_SPREAD_MS * (Number.isFinite(spread) ? Math.min(1, Math.max(0, spread)) : 0)) };
    }

    return { state: RETRYING, retryInMs: retryDelayMs(attempt, random) };
}

/**
 * Failures in a row before the next one, after a connection that held for `heldMs`: a connection that held STABLE_MS
 * starts over from the first wait
 */
export function attemptsAfterLoss(attempts, heldMs) {
    if (Number.isFinite(heldMs) && heldMs >= STABLE_MS) {
        return 0;
    }

    return Number.isInteger(attempts) && attempts > 0 ? attempts : 0;
}

function isMuted(publication) {
    return publication?.isMuted === true;
}

function displayName(entry) {
    return typeof entry?.name === 'string' ? entry.name.trim() : '';
}

const GROUP = { screen: 0, host: 1, guest: 2 };

function groupOf(tile) {
    return tile.source === 'screen' ? GROUP.screen : GROUP[tile.role];
}

/**
 * The studio as the page shows it, from livekit_session.js participantsOf() entries ({identity, name, kind, camera,
 * microphone, screen}):
 * - a tile per video on air - a shared screen, then the hosts (`m:*`), then the guests (`p:*`); somebody heard but not
 *   seen gets a tile of their own (`voice`) so the viewer sees who speaks;
 * - a sound per microphone;
 * - within a group in the order they first came on air (`seen`, kept by the caller - the returned map has the
 *   newcomers added), so a tile never jumps when somebody else comes or goes.
 * `voiceTiles: false` (OBS: the video alone) leaves the voice-only tiles out - their sound plays all the same.
 * Nobody else ever shows: spectators (hidden from the room anyway), a participant's second screen (it only watches),
 * unknown identities, and anybody in the studio who publishes nothing.
 *
 * @returns {{tiles: Array<{key: string, identity: string, name: string, role: 'host'|'guest', source: 'camera'|'screen'|'voice', publication: object|null, muted: boolean}>, sounds: Array<{key: string, identity: string, publication: object}>, seen: Map<string, number>}}
 */
export function studioTiles(participants = [], seen = new Map(), { voiceTiles = true } = {}) {
    const order = new Map(seen instanceof Map ? seen : []);
    const tiles = [];
    const sounds = [];

    for (const entry of Array.isArray(participants) ? participants : []) {
        const role = entry?.kind === 'moderator' ? 'host' : (entry?.kind === 'participant' ? 'guest' : null);

        if (role === null || typeof entry.identity !== 'string' || entry.identity === '') {
            continue;
        }

        const camera = entry.camera ?? null;
        const screen = entry.screen ?? null;
        const microphone = entry.microphone ?? null;

        if (camera === null && screen === null && microphone === null) {
            continue;
        }

        if (!order.has(entry.identity)) {
            order.set(entry.identity, order.size);
        }

        const base = { identity: entry.identity, name: displayName(entry), role };

        if (screen !== null) {
            tiles.push({ ...base, key: `${entry.identity}#screen`, source: 'screen', publication: screen, muted: isMuted(screen) });
        }

        if (camera !== null) {
            tiles.push({ ...base, key: `${entry.identity}#camera`, source: 'camera', publication: camera, muted: isMuted(camera) });
        }

        if (camera === null && screen === null && voiceTiles) {
            tiles.push({ ...base, key: `${entry.identity}#voice`, source: 'voice', publication: null, muted: isMuted(microphone) });
        }

        if (microphone !== null) {
            sounds.push({ key: `${entry.identity}#microphone`, identity: entry.identity, publication: microphone });
        }
    }

    tiles.sort((a, b) => groupOf(a) - groupOf(b) || order.get(a.identity) - order.get(b.identity));
    sounds.sort((a, b) => order.get(a.identity) - order.get(b.identity));

    return { tiles, sounds, seen: order };
}

/**
 * The studio is on air: somebody is seen or heard
 */
export function isLive(studio) {
    return (studio?.tiles?.length ?? 0) > 0 || (studio?.sounds?.length ?? 0) > 0;
}

/**
 * How many columns the stage's tiles take so each is as large as it can be in a `width` × `height` box with `gap`
 * between the tiles: a phone held upright stacks two guests, a wide screen puts them side by side. 1 for a single tile;
 * up to 2 for a box of unknown size.
 */
export function gridColumns(count, width, height, { aspect = TILE_ASPECT, gap = 0 } = {}) {
    if (!Number.isInteger(count) || count <= 1) {
        return 1;
    }

    if (!(width > 0) || !(height > 0)) {
        return Math.min(count, 2);
    }

    let best = 1;
    let bestWidth = -1;

    const landscape = width >= height;

    for (let columns = 1; columns <= count; columns++) {
        const size = fitted(count, columns, width, height, aspect, gap);
        // The same size either way: a wide box spreads the tiles out (3 × 2), an upright one stacks them
        const tie = Math.abs(size - bestWidth) <= 0.5;

        if (size > bestWidth + 0.5 || (tie && landscape)) {
            best = columns;
            bestWidth = Math.max(size, bestWidth);
        }
    }

    return best;
}

function fitted(count, columns, width, height, aspect, gap) {
    const rows = Math.ceil(count / columns);
    const byWidth = (width - (columns - 1) * gap) / columns;
    const byHeight = ((height - (rows - 1) * gap) / rows) * aspect;

    return Math.max(0, Math.min(byWidth, byHeight));
}

/**
 * The width of one tile in gridColumns()' layout of the box (whole pixels) - null for a box of unknown width
 */
export function tileWidth(count, width, height, { aspect = TILE_ASPECT, gap = 0 } = {}) {
    if (!(width > 0)) {
        return null;
    }

    const tiles = Math.max(1, Number.isInteger(count) ? count : 1);
    const columns = gridColumns(tiles, width, height, { aspect, gap });

    if (!(height > 0)) {
        return Math.floor((width - (columns - 1) * gap) / columns);
    }

    return Math.floor(fitted(tiles, columns, width, height, aspect, gap));
}

/**
 * The height the tiles of tileWidth() take, with the gaps between their rows
 */
export function gridHeight(count, columns, width, { aspect = TILE_ASPECT, gap = 0 } = {}) {
    if (!Number.isInteger(count) || count <= 0 || !(width > 0)) {
        return 0;
    }

    const rows = Math.ceil(count / Math.max(1, columns));

    return Math.ceil(rows * (width / aspect) + (rows - 1) * gap);
}

/**
 * The number watching: LiveKit's count of the room (`Room.numParticipants`, refreshed by the server every few seconds -
 * hidden spectators included) less everybody this page sees there (the hosts and the guests). This page's own
 * spectator is among them, so never below 1 - and null before LiveKit said anything.
 */
export function watchingCount(numParticipants, visibleCount) {
    if (!Number.isFinite(numParticipants) || numParticipants <= 0) {
        return null;
    }

    const visible = Number.isFinite(visibleCount) && visibleCount > 0 ? visibleCount : 0;

    return Math.max(1, numParticipants - visible);
}

/**
 * What the curtain over the stage says (a key of the page's texts) - null: no curtain, the studio is on screen.
 * Connected with nobody on air, the round's clock tells which: before the start "not live yet", while it runs "quiet",
 * after the end "off air". While LiveKit reconnects by itself the picture is frozen: "reconnecting" (a veil over it).
 *
 * @param {{state: string, live?: boolean, screen?: string, reconnecting?: boolean}} input  screen = round_state.js
 *        deriveView().screen
 */
export function curtainFor({ state, live = false, screen = 'waiting', reconnecting = false } = {}) {
    switch (state) {
        case CONNECTED:
            if (reconnecting) {
                return 'reconnecting';
            }

            if (live) {
                return null;
            }

            if (screen === 'ended') {
                return 'off_air';
            }

            return screen === 'running' ? 'quiet' : 'not_live';
        case FULL:
            return 'full';
        case UNAVAILABLE:
            return 'unavailable';
        case GONE:
            return 'gone';
        case RETRYING:
            return 'lost';
        default:
            return 'connecting';
    }
}

/**
 * The round's clock as the watch page writes it, from round_state.js deriveView(): {state, time, left}
 * - waiting (no start yet): no time - the page shows the planned start;
 * - scheduled / countdown: "Starts in" + the time to the start (rounded up);
 * - running: the time since the start + what is left of the time limit;
 * - ended: the time it ran.
 * MM:SS for a round of an hour or less, else HH:MM:SS - like the participants' phones (compete_flow.js clockText()).
 */
export function clockLine(view) {
    const limitMs = Number.isFinite(view?.limitMs) ? view.limitMs : null;

    switch (view?.screen) {
        case 'scheduled':
        case 'countdown':
            return { state: 'starts_in', time: countdownText(view.countdownMs), left: null };
        case 'running':
            return {
                state: 'running',
                time: clockText(view.elapsedMs, { limitMs }),
                left: Number.isFinite(view.remainingMs) ? clockText(view.remainingMs, { limitMs, ceil: true }) : null,
            };
        case 'ended':
            return { state: 'ended', time: clockText(view.elapsedMs, { limitMs }), left: null };
        default:
            return { state: 'waiting', time: null, left: null };
    }
}

/**
 * "Tap to turn the sound on": the browser refused to play the studio's sound without a tap (LiveKit's
 * canPlaybackAudio) and there is sound to play. Never in OBS, whose browser source plays without one.
 */
export function soundButtonWanted({ canPlaybackAudio = true, sounds = 0, obs = false } = {}) {
    return !obs && canPlaybackAudio === false && sounds > 0;
}

/**
 * Whole seconds until a moment - for "Trying again in 12 s"; null without one
 */
export function secondsUntil(atMs, nowMs) {
    if (!Number.isFinite(atMs) || !Number.isFinite(nowMs)) {
        return null;
    }

    return Math.max(0, Math.ceil((atMs - nowMs) / 1000));
}
