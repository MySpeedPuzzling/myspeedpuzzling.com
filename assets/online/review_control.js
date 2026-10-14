/**
 * The control room's reading of an online round's participants (docs/features/online-competitions/
 * implementation-plan.md WP 1.7) - pure, no DOM, pinned by tests/online-review-harness.mjs. From one participant of
 * `online_live` (OnlineLiveParticipant) and the server's time:
 *
 * - readiness: `ready` (the phone said "I'm ready"), `waiting` (it made contact, not ready yet), `not_seen`;
 * - online: a heartbeat within ONLINE_FRESH_MS (three heartbeats of 15 s) - offline after that; unknown without one;
 * - camera: ok / off from the last heartbeat; the page hidden is its own warning;
 * - clock quality from the round trip of the phone's best clock sample (the heartbeat's, else the one it was ready
 *   with): its error is at most rtt / 2 - good up to CLOCK_GOOD_RTT_MS, poor from CLOCK_POOR_RTT_MS (the phone would
 *   not let the participant be ready - clock_sync.js READY_RTT_MS);
 * - finished: the last Finished tap not undone, as an elapsed time - marked when it lies after the round's end; with the
 *   round's detection `on` (Phase 2, README §5.7) the latest finish candidate of the run counts as a finish too - the
 *   moderator sees "Finished (detected)" at once; the shown finish is the later of the two (`finishSource` button /
 *   detection), the detected one stays readable (`detectedElapsedMs`) next to a later tap;
 * - the phone's finish detector (detection `shadow` / `on`): `ready`, `loading`, `button_only` (it can not run there,
 *   failed, or was switched off on the phone - the Finished button is all it has) or `unknown` (no heartbeat with it);
 * - another device open on the link (`otherDevice` - a second phone, a laptop: not the one holding the entry) while it
 *   beats, i.e. seen within ONLINE_FRESH_MS like the holder's own heartbeat;
 * - counts and the filters (not ready, offline, finished).
 */

export const ONLINE_FRESH_MS = 45000;
export const CLOCK_GOOD_RTT_MS = 250;
export const CLOCK_POOR_RTT_MS = 1000;
export const FILTERS = ['all', 'not_ready', 'offline', 'finished'];
export const DETECTION_MODES = ['off', 'shadow', 'on'];

function finite(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/**
 * The phone's finish detector as the tile shows it - null while the round's detection is off.
 *
 * @returns {'ready'|'loading'|'button_only'|'unknown'|null}
 */
export function detectorStatus(participant, detection) {
    if (detection !== 'shadow' && detection !== 'on') {
        return null;
    }

    const state = participant?.presence?.detector?.state;

    switch (state) {
        case 'ready':
            return 'ready';
        case 'loading':
            return 'loading';
        case 'unsupported':
        case 'error':
        case 'off':
            return 'button_only';
        default:
            return 'unknown';
    }
}

/**
 * @param {object} participant  one of online_live's participants
 * @param {{serverNowMs: number, startedAtMs?: number|null, endedAtMs?: number|null, detection?: string}} context
 */
export function participantView(participant, { serverNowMs, startedAtMs = null, endedAtMs = null, detection = 'off' } = {}) {
    const presence = participant?.presence ?? null;
    const lastSeenMs = finite(presence?.lastSeenMs);
    const lastSeenAgoMs = lastSeenMs !== null && Number.isFinite(serverNowMs) ? Math.max(0, serverNowMs - lastSeenMs) : null;
    const online = lastSeenAgoMs === null ? null : lastSeenAgoMs <= ONLINE_FRESH_MS;

    let readiness = 'not_seen';

    if (finite(participant?.readyAtMs) !== null) {
        readiness = 'ready';
    } else if (participant?.seen === true || presence !== null) {
        readiness = 'waiting';
    }

    let camera = 'unknown';

    if (online === true && presence?.camera?.ok === true) {
        camera = 'ok';
    } else if (online === true && presence?.camera?.ok === false) {
        camera = 'off';
    }

    const rttMs = finite(presence?.clock?.rttMs) ?? finite(participant?.readyClockRttMs);
    let clockQuality = 'unknown';

    if (rttMs !== null) {
        clockQuality = rttMs <= CLOCK_GOOD_RTT_MS ? 'good' : (rttMs < CLOCK_POOR_RTT_MS ? 'fair' : 'poor');
    }

    const otherSeenMs = finite(participant?.otherDevice?.lastSeenMs);
    const otherDevice = otherSeenMs !== null && Number.isFinite(serverNowMs) && serverNowMs - otherSeenMs <= ONLINE_FRESH_MS;

    const tapMs = finite(participant?.finish?.momentMs);
    // A candidate is a finish only while the round's detection is `on` - in `shadow` the participant never saw it
    const detectedMs = detection === 'on' ? finite(participant?.candidate?.momentMs) : null;
    const finishMs = tapMs === null ? detectedMs : (detectedMs === null ? tapMs : Math.max(tapMs, detectedMs));
    const finished = finishMs !== null;
    const finishSource = !finished ? null : (finishMs === tapMs ? 'button' : 'detection');
    const elapsed = (moment) => (moment !== null && Number.isFinite(startedAtMs) && moment >= startedAtMs ? moment - startedAtMs : null);
    const finishElapsedMs = elapsed(finishMs);

    return {
        readiness,
        online,
        lastSeenAgoMs,
        camera,
        hidden: online === true && presence?.visible === false,
        clockQuality,
        clockErrorMs: rttMs === null ? null : Math.round(rttMs / 2),
        otherDevice,
        finished,
        finishMomentMs: finishMs,
        finishSource,
        finishElapsedMs,
        detectedElapsedMs: elapsed(detectedMs),
        tapElapsedMs: elapsed(tapMs),
        detector: detectorStatus(participant, detection),
        finishAfterEnd: finished && Number.isFinite(endedAtMs) && finishMs > endedAtMs,
        reported: participant?.reported ?? null,
        confirmed: participant?.review === 'confirmed',
        pendingUploads: finite(presence?.outbox?.media) ?? 0,
    };
}

/**
 * The numbers over the grid.
 *
 * @param {Array<ReturnType<typeof participantView>>} views
 */
export function countsOf(views) {
    const counts = { entered: 0, seen: 0, ready: 0, online: 0, cameraOff: 0, finished: 0, reported: 0 };

    for (const view of Array.isArray(views) ? views : []) {
        counts.entered += 1;
        counts.seen += view.readiness !== 'not_seen' ? 1 : 0;
        counts.ready += view.readiness === 'ready' ? 1 : 0;
        counts.online += view.online === true ? 1 : 0;
        counts.cameraOff += view.camera === 'off' ? 1 : 0;
        counts.finished += view.finished ? 1 : 0;
        counts.reported += !view.finished && view.reported !== null ? 1 : 0;
    }

    return counts;
}

/**
 * The grid's filters: everybody, those not ready yet (and not finished), those offline (never seen counts too - their
 * phone is not there), those finished.
 */
export function matchesFilter(view, filter) {
    switch (filter) {
        case 'not_ready':
            return view.readiness !== 'ready' && !view.finished;
        case 'offline':
            return view.online !== true;
        case 'finished':
            return view.finished;
        default:
            return true;
    }
}

/**
 * "Last seen" in the largest whole unit: seconds below a minute, minutes below an hour, then hours.
 *
 * @returns {{value: number, unit: 's'|'min'|'h'}|null}
 */
export function agoParts(ms) {
    if (!Number.isFinite(ms) || ms < 0) {
        return null;
    }

    const seconds = Math.floor(ms / 1000);

    if (seconds < 60) {
        return { value: seconds, unit: 's' };
    }

    if (seconds < 3600) {
        return { value: Math.floor(seconds / 60), unit: 'min' };
    }

    return { value: Math.floor(seconds / 3600), unit: 'h' };
}

/**
 * Should the photo of a tile change? Only for another photo, or when the shown one failed to load - the URL itself
 * changes with every poll (it is signed again), the photo does not. An answer without a photo keeps the one shown (a
 * photo is never taken back - a URL that could not be signed this time is no reason to blank the tile).
 */
export function photoChanged(shown, incoming) {
    if (!incoming || typeof incoming.url !== 'string' || incoming.url === '') {
        return false;
    }

    return !shown || shown.mediaId !== incoming.mediaId || shown.failed === true;
}
