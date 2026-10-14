// Runs assets/online/watch_view.js - the plain part of the public watch page of a round's studio
// (assets/controllers/online_watch_controller.js) - without a browser: the waits before trying again, what a refused
// token request means, the studio's tiles and sounds and their order, the grid, the number watching, the curtain, the
// round's clock and the sound button. Prints {scenario: "ok" | error} as JSON; tests/OnlineWatchScriptTest.php asserts
// every one is "ok".

import assert from 'node:assert/strict';
import {
    attemptsAfterLoss,
    clockLine,
    CONNECTED,
    CONNECTING,
    curtainFor,
    FULL,
    FULL_RETRY_MS,
    GONE,
    gridColumns,
    gridHeight,
    isLive,
    jittered,
    JITTER,
    LIMITED_RETRY_MS,
    LIMITED_SPREAD_MS,
    RETRY_MS,
    retryDelayMs,
    RETRYING,
    secondsUntil,
    soundButtonWanted,
    STABLE_MS,
    studioTiles,
    tileWidth,
    tokenRefusal,
    UNAVAILABLE,
    UNAVAILABLE_RETRY_MS,
    watchingCount,
} from '../assets/online/watch_view.js';
import { deriveView } from '../assets/online/round_state.js';

const HOST = 'm:018d0000-0000-0000-0000-000000000003';
const CO_HOST = 'm:018d0000-0000-0000-0000-000000000004';
const GUEST = 'p:018d0050-0000-0000-0000-000000000201';
const OTHER_GUEST = 'p:018d0050-0000-0000-0000-000000000202';

const low = () => 0;
const high = () => 0.999999;
const middle = () => 0.5;

function publication(sid, { muted = false } = {}) {
    return { trackSid: sid, isMuted: muted };
}

function participant(identity, kind, { name = '', camera = null, microphone = null, screen = null } = {}) {
    return { identity, name, kind, id: identity.slice(2), camera, microphone, screen, participant: {} };
}

const scenarios = {
    'the waits after a failure grow to a minute and stay there'() {
        assert.deepEqual([0, 1, 2, 3, 4, 5, 6, 40].map((attempt) => retryDelayMs(attempt, middle)), [...RETRY_MS, 60000, 60000]);
        assert.equal(retryDelayMs(-3, middle), RETRY_MS[0]);
        assert.equal(retryDelayMs(null, middle), RETRY_MS[0]);
        assert.equal(RETRY_MS[RETRY_MS.length - 1], 60000);
    },

    'every wait is spread by the jitter, never beyond it'() {
        assert.equal(jittered(10000, low), Math.round(10000 * (1 - JITTER)));
        assert.equal(jittered(10000, high), Math.round(10000 * (1 + JITTER * 0.999998)));
        assert.equal(jittered(10000, middle), 10000);
        // A broken random source counts as the middle
        assert.equal(jittered(10000, () => Number.NaN), 10000);
        assert.equal(jittered(10000, () => 7), Math.round(10000 * (1 + JITTER)));

        for (let i = 0; i < 200; i++) {
            const wait = retryDelayMs(2);
            assert.ok(wait >= RETRY_MS[2] * (1 - JITTER) && wait <= RETRY_MS[2] * (1 + JITTER), String(wait));
        }
    },

    'a full studio is tried again in about a minute'() {
        const outcome = tokenRefusal({ status: 409, error: 'studio_full' }, 3, middle);
        assert.deepEqual(outcome, { state: FULL, retryInMs: FULL_RETRY_MS });
        assert.ok(tokenRefusal({ status: 409, error: 'studio_full' }, 0, low).retryInMs >= FULL_RETRY_MS * (1 - JITTER));
    },

    'live video switched off is asked about again rarely'() {
        assert.deepEqual(tokenRefusal({ status: 409, error: 'video_unavailable' }, 0, middle), { state: UNAVAILABLE, retryInMs: UNAVAILABLE_RETRY_MS });
    },

    'a watch page that is gone is never tried again'() {
        assert.deepEqual(tokenRefusal({ status: 404, error: 'not_found' }), { state: GONE, retryInMs: null });
        assert.deepEqual(tokenRefusal({ status: 404, error: 'round_not_online' }), { state: GONE, retryInMs: null });
    },

    'too many requests wait for the server\'s Retry-After'() {
        assert.deepEqual(tokenRefusal({ status: 429, error: 'rate_limited', retryAfter: '12' }, 0, low), { state: RETRYING, retryInMs: 12000 });
        assert.equal(tokenRefusal({ status: 429, retryAfter: '12' }, 0, high).retryInMs, 12000 + Math.round(LIMITED_SPREAD_MS * 0.999999));
        // Without one (or a nonsense one): a minute
        assert.equal(tokenRefusal({ status: 429 }, 0, low).retryInMs, LIMITED_RETRY_MS);
        assert.equal(tokenRefusal({ status: 429, retryAfter: 'soon' }, 0, low).retryInMs, LIMITED_RETRY_MS);
    },

    'anything else - offline, a server error, an odd answer - backs off'() {
        assert.deepEqual(tokenRefusal({ status: 0 }, 0, middle), { state: RETRYING, retryInMs: RETRY_MS[0] });
        assert.deepEqual(tokenRefusal({ status: 503 }, 2, middle), { state: RETRYING, retryInMs: RETRY_MS[2] });
        assert.deepEqual(tokenRefusal({ status: 409, error: 'something_new' }, 9, middle), { state: RETRYING, retryInMs: 60000 });
        assert.deepEqual(tokenRefusal({ status: 200 }, 1, middle), { state: RETRYING, retryInMs: RETRY_MS[1] });
    },

    'a connection that held a minute starts the waits over'() {
        assert.equal(attemptsAfterLoss(4, STABLE_MS), 0);
        assert.equal(attemptsAfterLoss(4, STABLE_MS - 1), 4);
        assert.equal(attemptsAfterLoss(undefined, 5), 0);
        assert.equal(attemptsAfterLoss(2, Number.NaN), 2);
    },

    'the studio: a shared screen first, then the hosts, then the guests'() {
        const studio = studioTiles([
            participant(GUEST, 'participant', { name: 'Olivia', camera: publication('g1'), microphone: publication('g2') }),
            participant(HOST, 'moderator', { name: 'Jan', camera: publication('h1'), microphone: publication('h2'), screen: publication('h3') }),
        ]);

        assert.deepEqual(studio.tiles.map((tile) => [tile.key, tile.role, tile.source, tile.name]), [
            [`${HOST}#screen`, 'host', 'screen', 'Jan'],
            [`${HOST}#camera`, 'host', 'camera', 'Jan'],
            [`${GUEST}#camera`, 'guest', 'camera', 'Olivia'],
        ]);
        assert.deepEqual(studio.tiles.map((tile) => tile.publication.trackSid), ['h3', 'h1', 'g1']);
        // Every microphone is heard - the guest came first
        assert.deepEqual(studio.sounds.map((sound) => [sound.key, sound.publication.trackSid]), [[`${GUEST}#microphone`, 'g2'], [`${HOST}#microphone`, 'h2']]);
        assert.equal(isLive(studio), true);
    },

    'a tile keeps its place when somebody else comes or goes'() {
        let studio = studioTiles([
            participant(OTHER_GUEST, 'participant', { camera: publication('b') }),
            participant(GUEST, 'participant', { camera: publication('a') }),
        ]);
        // participantsOf() sorts by identity - the order of coming on air is the caller's map
        assert.deepEqual(studio.tiles.map((tile) => tile.identity), [OTHER_GUEST, GUEST]);

        studio = studioTiles([
            participant(GUEST, 'participant', { camera: publication('a') }),
            participant(OTHER_GUEST, 'participant', { camera: publication('b') }),
            participant(CO_HOST, 'moderator', { camera: publication('c') }),
        ], studio.seen);
        assert.deepEqual(studio.tiles.map((tile) => tile.identity), [CO_HOST, OTHER_GUEST, GUEST]);

        // The first guest leaves and comes back - back to their place, not to the end
        studio = studioTiles([participant(GUEST, 'participant', { camera: publication('a') }), participant(CO_HOST, 'moderator', { camera: publication('c') })], studio.seen);
        studio = studioTiles([
            participant(GUEST, 'participant', { camera: publication('a') }),
            participant(OTHER_GUEST, 'participant', { camera: publication('b2') }),
            participant(CO_HOST, 'moderator', { camera: publication('c') }),
        ], studio.seen);
        assert.deepEqual(studio.tiles.map((tile) => tile.identity), [CO_HOST, OTHER_GUEST, GUEST]);
    },

    'a voice without a camera gets a tile of its own, a camera turned off says so'() {
        const studio = studioTiles([
            participant(HOST, 'moderator', { name: 'Jan', microphone: publication('h2') }),
            participant(GUEST, 'participant', { name: 'Olivia', camera: publication('g1', { muted: true }) }),
        ]);

        assert.deepEqual(studio.tiles.map((tile) => [tile.key, tile.source, tile.muted, tile.publication?.trackSid ?? null]), [
            [`${HOST}#voice`, 'voice', false, null],
            [`${GUEST}#camera`, 'camera', true, 'g1'],
        ]);
        assert.equal(studio.sounds.length, 1);

        // OBS: the video alone - the voice is heard, not drawn
        const obs = studioTiles([participant(HOST, 'moderator', { microphone: publication('h2') })], new Map(), { voiceTiles: false });
        assert.deepEqual(obs.tiles, []);
        assert.equal(obs.sounds.length, 1);
        assert.equal(isLive(obs), true);
    },

    'nobody else is ever on screen'() {
        const studio = studioTiles([
            participant('s:00000000000000a1', 'spectator', { camera: publication('x') }),
            participant(`${GUEST}:screen`, 'screen', { camera: publication('y') }),
            participant('egress-1', 'unknown', { camera: publication('z') }),
            // In the studio, publishing nothing (a guest whose time on air ended)
            participant(OTHER_GUEST, 'participant'),
            null,
            { kind: 'moderator' },
        ]);

        assert.deepEqual(studio.tiles, []);
        assert.deepEqual(studio.sounds, []);
        assert.equal(studio.seen.size, 0);
        assert.equal(isLive(studio), false);
        assert.equal(isLive(null), false);
        assert.deepEqual(studioTiles(undefined).tiles, []);
    },

    'the grid: stacked on a phone held upright, side by side on a wide screen'() {
        // A phone, 375 wide and ~550 left for the stage
        assert.equal(gridColumns(1, 375, 551), 1);
        assert.equal(gridColumns(2, 375, 551), 1);
        assert.equal(tileWidth(2, 375, 551), 375);
        assert.equal(gridHeight(2, 1, 375), Math.ceil(2 * 375 / (16 / 9)));
        // A laptop
        assert.equal(gridColumns(2, 1280, 600), 2);
        assert.equal(tileWidth(2, 1280, 600), 640);
        assert.equal(gridColumns(3, 1280, 600), 2);
        assert.equal(gridColumns(4, 1280, 600), 2);
        // Five or six on a 16:9 screen: the same size as 2 × 3, spread out as 3 × 2
        assert.equal(gridColumns(5, 1920, 1080), 3);
        assert.equal(gridColumns(6, 1920, 1080), 3);
        // ... and stacked in an upright box
        assert.equal(gridColumns(2, 400, 800), 1);
        // A single tile never needs more
        assert.equal(gridColumns(1, 3000, 300), 1);
        assert.equal(tileWidth(1, 1920, 1080), 1920);
        assert.equal(tileWidth(1, 1920, 540), 960);
        // The gaps are taken off first
        assert.equal(tileWidth(2, 1280, 600, { gap: 10 }), 635);
        assert.equal(gridHeight(2, 2, 635, { gap: 10 }), Math.ceil(635 / (16 / 9)));
        assert.equal(gridHeight(3, 2, 400, { gap: 10 }), Math.ceil(2 * 225 + 10));
        // A box of unknown size: no more than two columns, no width
        assert.equal(gridColumns(4, 0, 0), 2);
        assert.equal(tileWidth(2, 0, 500), null);
        assert.equal(tileWidth(2, 800, 0), 400);
        assert.equal(gridHeight(0, 1, 800), 0);
    },

    'the number watching: LiveKit\'s count less who is on screen, never below this page'() {
        assert.equal(watchingCount(undefined, 0), null);
        assert.equal(watchingCount(0, 0), null);
        // A host, a guest and 37 spectators (this page among them)
        assert.equal(watchingCount(39, 2), 37);
        assert.equal(watchingCount(1, 0), 1);
        // LiveKit's count lags a few seconds behind who arrived
        assert.equal(watchingCount(2, 3), 1);
        assert.equal(watchingCount(5, Number.NaN), 5);
    },

    'the curtain says why the studio is not on screen'() {
        assert.equal(curtainFor({ state: CONNECTING }), 'connecting');
        assert.equal(curtainFor({ state: CONNECTED, live: true, screen: 'running' }), null);
        assert.equal(curtainFor({ state: CONNECTED, live: false, screen: 'waiting' }), 'not_live');
        assert.equal(curtainFor({ state: CONNECTED, live: false, screen: 'scheduled' }), 'not_live');
        assert.equal(curtainFor({ state: CONNECTED, live: false, screen: 'countdown' }), 'not_live');
        assert.equal(curtainFor({ state: CONNECTED, live: false, screen: 'running' }), 'quiet');
        assert.equal(curtainFor({ state: CONNECTED, live: false, screen: 'ended' }), 'off_air');
        assert.equal(curtainFor({ state: FULL }), 'full');
        assert.equal(curtainFor({ state: UNAVAILABLE }), 'unavailable');
        assert.equal(curtainFor({ state: GONE }), 'gone');
        assert.equal(curtainFor({ state: RETRYING, live: true }), 'lost');
        assert.equal(curtainFor(), 'connecting');
        // LiveKit reconnecting by itself: a veil over the frozen picture, whatever was on air
        assert.equal(curtainFor({ state: CONNECTED, live: true, screen: 'running', reconnecting: true }), 'reconnecting');
        assert.equal(curtainFor({ state: CONNECTED, live: false, screen: 'waiting', reconnecting: true }), 'reconnecting');
        assert.equal(curtainFor({ state: FULL, reconnecting: true }), 'full');
    },

    'the round\'s clock as the phones show it'() {
        const start = Date.UTC(2026, 10, 10, 17, 0, 0);
        const round = (fields) => ({ roundId: 'r', status: 'running', minutesLimit: 60, asOfMs: start, ...fields });

        assert.deepEqual(clockLine(deriveView(round({ startedAtMs: null, status: null }), start)), { state: 'waiting', time: null, left: null });
        assert.deepEqual(clockLine(deriveView(round({ startedAtMs: start }), start - 3723000)), { state: 'starts_in', time: '01:02:03', left: null });
        assert.deepEqual(clockLine(deriveView(round({ startedAtMs: start }), start - 7500)), { state: 'starts_in', time: '00:08', left: null });
        // An hour or less: MM:SS, what is left rounded up
        assert.deepEqual(clockLine(deriveView(round({ startedAtMs: start }), start + 754300)), { state: 'running', time: '12:34', left: '47:26' });
        // Longer: HH:MM:SS
        assert.deepEqual(
            clockLine(deriveView(round({ startedAtMs: start, minutesLimit: 120 }), start + 754300)),
            { state: 'running', time: '00:12:34', left: '01:47:26' },
        );
        // Ended by the time limit, or by "End now"
        assert.deepEqual(clockLine(deriveView(round({ startedAtMs: start }), start + 3700000)), { state: 'ended', time: '60:00', left: null });
        assert.deepEqual(clockLine(deriveView(round({ startedAtMs: start, stoppedAtMs: start + 1800000 }), start + 3700000)), { state: 'ended', time: '30:00', left: null });
        // No limit: nothing left to show
        assert.deepEqual(clockLine(deriveView(round({ startedAtMs: start, minutesLimit: 0 }), start + 5000)), { state: 'running', time: '00:00:05', left: null });
        assert.deepEqual(clockLine(null), { state: 'waiting', time: null, left: null });
    },

    'the sound button asks for a tap only when the browser refused and there is sound'() {
        assert.equal(soundButtonWanted({ canPlaybackAudio: false, sounds: 1 }), true);
        assert.equal(soundButtonWanted({ canPlaybackAudio: true, sounds: 1 }), false);
        assert.equal(soundButtonWanted({ canPlaybackAudio: false, sounds: 0 }), false);
        // OBS plays without a tap - and nobody could tap it there
        assert.equal(soundButtonWanted({ canPlaybackAudio: false, sounds: 2, obs: true }), false);
        assert.equal(soundButtonWanted(), false);
    },

    'trying again in whole seconds'() {
        assert.equal(secondsUntil(10500, 0), 11);
        assert.equal(secondsUntil(10000, 0), 10);
        assert.equal(secondsUntil(0, 500), 0);
        assert.equal(secondsUntil(null, 0), null);
    },
};

const results = {};

for (const [name, run] of Object.entries(scenarios)) {
    try {
        await run();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
