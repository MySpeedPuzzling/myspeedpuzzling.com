/**
 * The participant page's live video - the rules (docs/features/online-competitions/README.md §5.12 "On the phone",
 * contract phase-1b.md stream VP, implementation-plan.md 1B.3 + 1B.6): when the phone is in the hall and publishes its
 * puzzle camera, who may watch it, when the other participants' cameras are shown, the studio invitation from the first
 * hint to "Thank you", the second screen, the host's voice around the beeps and what the status line says.
 * assets/online/compete_video_runtime.js does what these decide, assets/online/compete_video_page.js asks them (for
 * assets/controllers/compete_controller.js).
 *
 * Video is a layer next to the timing, never in its way: no rule here touches the clock, the screens or what is
 * recorded - a phone that cannot reach the media server competes exactly as without video.
 *
 * Pure: the state, the round's view (round_state.js deriveView()) and what the page knows in, a decision out - pinned
 * by tests/online-compete-video-harness.mjs.
 */

import { videoOn } from './compete_video_basics.js';

export { loadSecondScreen, saveSecondScreen, STORAGE_SECOND_SCREEN, videoOn } from './compete_video_basics.js';

// After a failure (LiveKit not reachable, a dropped connection, a server error) the page tries again after these waits,
// each ± JITTER - a hundred phones cut off together come back spread out. Never a reload.
export const RETRY_MS = [2000, 5000, 10000, 20000, 30000];
export const JITTER = 0.2;
// A connection that held this long starts the waits of its next failure from the first again
export const STABLE_MS = 60000;

// An invitation to the studio waits this long for its answer (StudioInvite: expired after 2 minutes, checked on read)
export const INVITE_ANSWER_MS = 120000;
// A heartbeat saying "no open invitation" is believed only when it was sent this long after the page learned one -
// one sent before the invitation was made answers from before it
export const INVITE_CLEAR_GRACE_MS = 2000;

// After the round ended the phone keeps its camera in the hall this long - the moderators see the finished puzzles,
// the studio can still call - then the camera turns off as without video (the hall stays for the host's voice)
export const AFTER_ROUND_CAMERA_MS = 15 * 60 * 1000;

// The other participants' cameras: the 180p layer (livekit_session.js DEFAULT_SIMULCAST_LAYERS[0])
export const STRIP_LAYER = { width: 320, height: 180 };
// At most this many tiles in the strip (it scrolls; LiveKit's adaptive stream sends only the tiles on screen)
export const STRIP_MAX = 24;

// The host's voice gives way to the phone's own beeps: lowered from just before the first countdown beep to just
// after GO, and while the time's-up alarm plays (beeps.js COUNTDOWN_BEEPS_MS, ALARM_PATTERN_MS)
export const VOICE_DUCKED = 0.2;
export const DUCK_BEFORE_START_MS = 3500;
export const DUCK_AFTER_START_MS = 900;
export const DUCK_ALARM_MS = 2300;

// What the page remembers on the device: the strip hidden ("Hide") - the second screen: compete_video_basics.js
export const STORAGE_STRIP_HIDDEN = 'msp-compete-video-strip-hidden';

// LiveKit's DisconnectReason values that decide what comes next
export const CLIENT_INITIATED = 1;
export const DUPLICATE_IDENTITY = 2;
export const PARTICIPANT_REMOVED = 4;

// The screens a ready camera device publishes on (the camera runs there - compete_flow.js cameraWanted())
const PUBLISH_SCREENS = ['waiting', 'countdown', 'running', 'finished', 'timeup', 'after'];
// The screens the other participants' cameras are shown on - never during the participant's own solve
const STRIP_SCREENS = ['waiting', 'finished', 'after'];
// The screens on which an invitation to the studio is offered (never during the solve: it waits for the finish)
const INVITE_SCREENS = ['waiting', 'finished', 'timeup', 'after'];

/**
 * `ms` ± JITTER of it - random() in [0, 1)
 */
export function jittered(ms, random = Math.random) {
    const value = Number(random());
    const share = Number.isFinite(value) ? Math.min(1, Math.max(0, value)) : 0.5;

    return Math.round(ms * (1 - JITTER + 2 * JITTER * share));
}

/**
 * The wait before the next try after `failures` failures in a row (1 = the first)
 */
export function retryDelayMs(failures, random = Math.random) {
    const index = Number.isInteger(failures) && failures > 1 ? Math.min(failures - 1, RETRY_MS.length - 1) : 0;

    return jittered(RETRY_MS[index], random);
}


/**
 * Who may watch this phone's puzzle camera (livekit_session.js setSubscribers()): every participant when the round
 * lets them see each other, else the moderators only - LiveKit enforces it.
 */
export function subscribersFor(state) {
    return state?.round?.settings?.seeEachOther === true ? 'all' : 'moderators';
}

/**
 * The phone's place in the hall:
 * - `room`: 'camera' (the device holding the entry - "I'm ready" tapped here and not taken over: publishes its
 *   puzzle camera), 'screen' (a second screen: watching only), null (not in the hall);
 * - `publish`: the camera goes out now (it is live and the screen is one of the round's);
 * - `subscribers`: subscribersFor().
 *
 * Nobody joins without video available (the page's video parts, a media server) and on for the round, without being
 * entered, in an organiser's preview or with a dead link. The studio takes the phone out of the hall (one video
 * encoder per phone, README §5.12 "The studio flow"). After the round the camera goes out AFTER_ROUND_CAMERA_MS more.
 *
 * @param {object} input
 * @param {boolean} input.available         the page has its video parts (a media server is configured)
 * @param {object|null} input.state
 * @param {string} input.screen              compete_flow.js screenFor()
 * @param {object} input.view                round_state.js deriveView()
 * @param {number} input.serverNowMs
 * @param {boolean} input.ready              "I'm ready" was tapped on this device
 * @param {boolean} [input.takenOver]
 * @param {boolean} [input.preview]
 * @param {boolean} [input.linkDead]
 * @param {boolean} [input.secondScreen]
 * @param {boolean} [input.inStudio]         studioHoldsPhone()
 * @param {boolean} [input.cameraLive]       camera.js status 'live' (or 'muted' - iOS mutes it for a moment)
 * @returns {{room: 'camera'|'screen'|null, publish: boolean, subscribers: 'all'|'moderators'}}
 */
export function hallPlan({
    available,
    state,
    screen,
    view,
    serverNowMs,
    ready,
    takenOver = false,
    preview = false,
    linkDead = false,
    secondScreen = false,
    inStudio = false,
    cameraLive = false,
}) {
    const none = { room: null, publish: false, subscribers: subscribersFor(state) };

    if (available !== true || preview || linkDead || !videoOn(state) || !state?.entry) {
        return none;
    }

    if (secondScreen) {
        return { ...none, room: 'screen' };
    }

    if (ready !== true || takenOver || inStudio || !PUBLISH_SCREENS.includes(screen)) {
        return none;
    }

    const publishing = screen === 'after' ? afterRoundCamera(view, serverNowMs) : true;

    return { ...none, room: 'camera', publish: publishing && cameraLive === true };
}

/**
 * The after screen keeps the camera on for the hall AFTER_ROUND_CAMERA_MS past the round's end.
 */
export function afterRoundCamera(view, serverNowMs) {
    const endedAtMs = view?.endedAtMs;

    if (!Number.isFinite(endedAtMs) || !Number.isFinite(serverNowMs)) {
        return false;
    }

    return serverNowMs < endedAtMs + AFTER_ROUND_CAMERA_MS;
}

/**
 * Whether live video keeps the camera running on a screen that would turn it off (compete_flow.js cameraWanted()):
 * the studio (the camera goes on air), and the after screen while the hall still gets it (afterRoundCamera()).
 */
export function videoHoldsCamera({ hall, inStudio = false, screen, view, serverNowMs }) {
    if (inStudio) {
        return true;
    }

    return hall?.room === 'camera' && screen === 'after' && afterRoundCamera(view, serverNowMs);
}

/**
 * Whether the other participants' cameras are shown: the round lets them see each other, the phone is in the hall,
 * and the participant is not solving - the waiting screen and after the finish, never during the countdown, the solve,
 * time's up or a practice (no distraction, no battery or bandwidth spent while puzzling). A second screen follows the
 * round: not while the participant is still puzzling (no Finished tap yet). "Hide" hides them on this device.
 *
 * @param {object} input
 * @param {object|null} input.state
 * @param {{room: string|null}} input.hall      hallPlan()
 * @param {string} input.screen
 * @param {object} input.view
 * @param {boolean} [input.practice]
 * @param {boolean} [input.inStudio]
 * @returns {{shown: boolean, tiles: boolean}}  shown: the strip (its header with "Show"); tiles: the cameras
 */
export function stripPlan({ state, hall, screen, view, practice = false, inStudio = false, hidden = false }) {
    if (hall?.room === null || hall?.room === undefined || state?.round?.settings?.seeEachOther !== true || practice || inStudio) {
        return { shown: false, tiles: false };
    }

    let calm;

    if (hall.room === 'screen') {
        const solving = (view?.screen === 'countdown' || view?.screen === 'running') && Number(state?.entry?.taps ?? 0) === 0;
        calm = !solving;
    } else {
        calm = STRIP_SCREENS.includes(screen);
    }

    return { shown: calm, tiles: calm && !hidden };
}

/**
 * The host's voice: full, lowered to VOICE_DUCKED around the countdown's beeps and GO and while the time's-up alarm
 * plays - the phone's own sounds are the timing reference (README §5.12), the voice never drowns them.
 *
 * @param {object} view            deriveView()
 * @param {number} serverNowMs
 */
export function voiceVolume(view, serverNowMs) {
    if (!Number.isFinite(serverNowMs)) {
        return 1;
    }

    const startedAtMs = view?.startedAtMs;

    if (Number.isFinite(startedAtMs) && serverNowMs >= startedAtMs - DUCK_BEFORE_START_MS && serverNowMs < startedAtMs + DUCK_AFTER_START_MS) {
        return VOICE_DUCKED;
    }

    const endedAtMs = view?.endedAtMs;

    if (view?.screen === 'ended' && view?.endedBy === 'limit' && Number.isFinite(endedAtMs) && serverNowMs >= endedAtMs && serverNowMs < endedAtMs + DUCK_ALARM_MS) {
        return VOICE_DUCKED;
    }

    return 1;
}

/**
 * The quiet video line in the bar: '' while the phone is not in the hall or the studio; `connecting`, `live`, `weak`
 * (LiveKit rates the connection poor or lost), `retrying` (dropped or not reachable - the page tries again by itself),
 * `off` (refused: video switched off, another device took the link).
 *
 * @param {object} input
 * @param {string} input.link        the runtime's link: off | connecting | connected | reconnecting | retrying | refused | duplicate
 * @param {string} [input.quality]   LiveKit's ConnectionQuality: excellent | good | poor | lost | unknown
 */
export function videoStatus({ link, quality = 'unknown' }) {
    switch (link) {
        case 'connecting':
            return 'connecting';
        case 'connected':
            return quality === 'poor' || quality === 'lost' ? 'weak' : 'live';
        case 'reconnecting':
        case 'retrying':
            return 'retrying';
        case 'refused':
        case 'duplicate':
            return 'off';
        default:
            return '';
    }
}

// ---- the studio invitation (README §5.12 "The studio flow", contract §4, §8)

/**
 * @typedef {object} Studio   the page's invitation, null when there is none
 * @property {string} inviteId
 * @property {'invited'|'answering'|'declining'|'joining'|'on_air'|'leaving'|'over'} phase
 * @property {number} learnedAtMs      when the page first heard of it (server time)
 * @property {number} expiresAtMs      learnedAtMs + INVITE_ANSWER_MS - the server decides, this is what the page shows
 * @property {string|null} outcome     phase 'over': thanks | left | moved_on | expired | withdrawn | declined | unavailable
 * @property {string|null} error       the last answer that failed to go out (phase 'invited'): offline | failed
 * @property {number} [overAtMs]
 */

// An outcome stays on screen this long, then the invitation is forgotten
export const OUTCOME_SHOWN_MS = 8000;

const PHASES_IN_STUDIO = ['joining', 'on_air', 'leaving'];

/**
 * The phone is in the studio, or on its way in or out - not in the hall then (hallPlan()).
 */
export function studioHoldsPhone(studio) {
    return studio !== null && studio !== undefined && PHASES_IN_STUDIO.includes(studio.phase);
}

/**
 * The studio connection is wanted (the runtime joins it): joining or on air - not while leaving.
 */
export function studioWanted(studio) {
    return studio !== null && studio !== undefined && (studio.phase === 'joining' || studio.phase === 'on_air');
}

function over(studio, outcome, atMs) {
    return { ...studio, phase: 'over', outcome, error: null, overAtMs: atMs };
}

function invited(inviteId, atMs) {
    return { inviteId, phase: 'invited', learnedAtMs: atMs, expiresAtMs: atMs + INVITE_ANSWER_MS, outcome: null, error: null };
}

/**
 * The invitation's next state for an event - the same object when nothing changes. `handled` (a Set of invitation
 * ids, kept by the page) holds every invitation that came to an end here: never offered again, whatever a late hint
 * says. Events (`atMs` = the server's time now):
 * - learned {inviteId, status?, source: 'data'|'heartbeat'|'state'} - a `studio` data message from the server, the
 *   heartbeat's `studioInviteId`, the state's `studio` {inviteId, status}; status absent = invited;
 * - cleared {sentAtMs} - a heartbeat without an open invitation (sent at sentAtMs, server time);
 * - tick - the time passes (the answer window);
 * - join / decline - the participant's taps; answered {status} / refused {code} - the answer's outcome;
 * - connected - on air in the studio; refused_studio {code} - no studio token; left {reason} - the studio connection
 *   closed (LiveKit's DisconnectReason: PARTICIPANT_REMOVED = "Thank you", CLIENT_INITIATED = this page left);
 * - leave - "Leave the studio"; stop {outcome} - video switched off, another device took the link;
 * - dismiss - the outcome was shown long enough.
 *
 * @param {Studio|null} studio
 * @param {object} event
 * @param {Set<string>} handled
 * @returns {Studio|null}
 */
export function studioReducer(studio, event, handled = new Set()) {
    const atMs = Number.isFinite(event?.atMs) ? event.atMs : 0;
    const finish = (outcome) => {
        if (studio === null) {
            return null;
        }

        handled.add(studio.inviteId);

        return over(studio, outcome, atMs);
    };

    switch (event?.type) {
        case 'learned':
            return learned(studio, event, atMs, handled);
        case 'cleared':
            if (studio?.phase === 'invited' && Number.isFinite(event.sentAtMs) && event.sentAtMs >= studio.learnedAtMs + INVITE_CLEAR_GRACE_MS) {
                return finish('withdrawn');
            }

            return studio;
        case 'tick':
            if (studio?.phase === 'invited' && atMs >= studio.expiresAtMs) {
                return finish('expired');
            }

            if (studio?.phase === 'over' && atMs >= (studio.overAtMs ?? 0) + OUTCOME_SHOWN_MS) {
                return null;
            }

            return studio;
        case 'join':
            return studio?.phase === 'invited' ? { ...studio, phase: 'answering', error: null } : studio;
        case 'decline':
            return studio?.phase === 'invited' ? { ...studio, phase: 'declining', error: null } : studio;
        case 'answered':
            if (studio?.phase === 'declining') {
                handled.add(studio.inviteId);

                return null;
            }

            if (studio?.phase === 'answering') {
                return event.status === 'joined' ? { ...studio, phase: 'joining' } : finish(outcomeOf(event.status));
            }

            return studio;
        case 'refused':
            if (studio?.phase !== 'answering' && studio?.phase !== 'declining') {
                return studio;
            }

            if (studio.phase === 'declining') {
                // Gone anyway (expired, withdrawn) - nothing to show for a "Not now"
                handled.add(studio.inviteId);

                return null;
            }

            return refused(studio, event.code, finish);
        case 'connected':
            return studio?.phase === 'joining' ? { ...studio, phase: 'on_air' } : studio;
        case 'refused_studio':
            if (!studioHoldsPhone(studio)) {
                return studio;
            }

            // studio_not_joined: the host ended it, or LiveKit's webhook did when the phone dropped out for good
            return finish(event.code === 'video_unavailable' ? 'unavailable' : 'moved_on');
        case 'left':
            if (studio?.phase === 'leaving') {
                return finish('left');
            }

            if (studio?.phase === 'on_air' || studio?.phase === 'joining') {
                // "Thank you": the server removed the guest. Any other drop: the runtime tries again (the server says
                // "studio_not_joined" when the studio has moved on)
                return event.reason === PARTICIPANT_REMOVED ? finish('thanks') : studio;
            }

            return studio;
        case 'leave':
            return studio?.phase === 'on_air' || studio?.phase === 'joining' ? { ...studio, phase: 'leaving' } : studio;
        case 'stop':
            if (studio === null || studio.phase === 'over') {
                return studio;
            }

            return finish(event.outcome ?? 'unavailable');
        case 'dismiss':
            return studio?.phase === 'over' ? null : studio;
        default:
            return studio;
    }
}

function outcomeOf(status) {
    switch (status) {
        case 'expired':
            return 'expired';
        case 'declined':
            return 'declined';
        case 'ended':
            return 'moved_on';
        default:
            return 'withdrawn';
    }
}

function refused(studio, code, finish) {
    switch (code) {
        case 'invite_expired':
            return finish('expired');
        case 'invite_not_open':
        case 'invite_not_found':
            return finish('withdrawn');
        case 'video_unavailable':
            return finish('unavailable');
        case 'offline':
            return { ...studio, phase: 'invited', error: 'offline' };
        default:
            return { ...studio, phase: 'invited', error: 'failed' };
    }
}

function learned(studio, event, atMs, handled) {
    const inviteId = typeof event.inviteId === 'string' && event.inviteId !== '' ? event.inviteId.toLowerCase() : null;
    const status = typeof event.status === 'string' ? event.status : 'invited';

    if (inviteId === null || handled.has(inviteId)) {
        return studio;
    }

    if (studio !== null && studio.inviteId === inviteId) {
        if (studio.phase === 'invited' && ['ended', 'expired', 'declined'].includes(status)) {
            handled.add(inviteId);

            return over(studio, status === 'expired' ? 'expired' : 'withdrawn', atMs);
        }

        if ((studio.phase === 'on_air' || studio.phase === 'joining') && status === 'ended') {
            handled.add(inviteId);

            return over(studio, 'thanks', atMs);
        }

        return studio;
    }

    // Another invitation while one is being answered or on air: the server keeps one open per participant - the one
    // in hand goes on
    if (studio !== null && studio.phase !== 'invited' && studio.phase !== 'over') {
        return studio;
    }

    if (status === 'invited') {
        return invited(inviteId, atMs);
    }

    // Joined before this page loaded (a reload on air): back into the studio - the server says whether it still is
    if (status === 'joined' && event.source === 'state') {
        return { ...invited(inviteId, atMs), phase: 'joining' };
    }

    // Answered, ended or expired before this page heard of it - nothing to offer
    handled.add(inviteId);

    return studio;
}

/**
 * What the page shows of the invitation: the prompt ("You're invited to the studio" - Join / Not now, seconds left),
 * the studio panel (joining / on air / leaving) or an outcome line. A device that is not the camera device (a second
 * screen, a phone not ready or taken over) only says that the camera phone was invited.
 *
 * @param {Studio|null} studio
 * @param {object} input
 * @param {boolean} input.cameraDevice      hallPlan().room === 'camera', or the phone is in the studio
 * @param {string} input.screen
 * @param {number} input.serverNowMs
 * @returns {{prompt: boolean, secondsLeft: number, busy: boolean, panel: string|null, outcome: string|null, elsewhere: boolean, error: string|null}}
 */
export function studioView(studio, { cameraDevice, screen, serverNowMs }) {
    const view = { prompt: false, secondsLeft: 0, busy: false, panel: null, outcome: null, elsewhere: false, error: null };

    if (studio === null || studio === undefined) {
        return view;
    }

    if (studio.phase === 'over') {
        return { ...view, outcome: studio.outcome };
    }

    if (studioHoldsPhone(studio)) {
        return { ...view, panel: studio.phase };
    }

    // invited / answering / declining
    if (!cameraDevice) {
        return { ...view, elsewhere: true };
    }

    if (!INVITE_SCREENS.includes(screen)) {
        return view;
    }

    return {
        ...view,
        prompt: true,
        busy: studio.phase !== 'invited',
        secondsLeft: Math.max(0, Math.ceil((studio.expiresAtMs - serverNowMs) / 1000)),
        error: studio.error,
    };
}

/**
 * "1:45" - the answer window as the prompt shows it.
 */
export function formatSecondsLeft(seconds) {
    const whole = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0;

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

// ---- the second screen (implementation-plan.md 1B.6, view-only for now)

/**
 * "Use as a second screen" is offered on a device that is not the camera device - another device holds the link
 * (the checklist says so) or took it over - while the round's video is on. The camera device records every tap with
 * its frames, so a second screen never has Finished and never takes the link.
 *
 * @param {object} input
 * @param {boolean} input.available
 * @param {object|null} input.state
 * @param {string} input.screen
 * @param {boolean} input.otherDeviceActive   the heartbeat names another device
 * @param {boolean} [input.preview]
 */
export function secondScreenOffered({ available, state, screen, otherDeviceActive, preview = false }) {
    if (available !== true || preview || !videoOn(state) || !state?.entry) {
        return false;
    }

    return screen === 'taken_over' || (screen === 'checklist' && otherDeviceActive === true);
}

export function loadStripHidden(storage) {
    try {
        return storage?.getItem(STORAGE_STRIP_HIDDEN) === '1';
    } catch (e) {
        return false;
    }
}

export function saveStripHidden(storage, hidden) {
    try {
        if (hidden) {
            storage?.setItem(STORAGE_STRIP_HIDDEN, '1');
        } else {
            storage?.removeItem(STORAGE_STRIP_HIDDEN);
        }

        return true;
    } catch (e) {
        return false;
    }
}

// ---- the room as the page shows it (livekit_session.js participantsOf() entries)

/**
 * The hall's people as the page needs them: the moderators (their voice, their camera for a second screen) and the
 * other participants' camera devices (the strip) - a second screen (`p:…:screen`) is nobody's camera. `speaking`: a
 * moderator's microphone is open (push-to-talk publishes it muted and opens it while held).
 *
 * @param {Array<{identity: string, name?: string, kind: string, id: string|null, camera: object|null, microphone: object|null}>} list
 * @returns {{moderators: Array, cameras: Array, speaking: string[]}}
 */
export function hallPeople(list) {
    const moderators = [];
    const cameras = [];

    for (const entry of Array.isArray(list) ? list : []) {
        if (entry?.kind === 'moderator') {
            moderators.push(entry);
        } else if (entry?.kind === 'participant' && entry.camera) {
            cameras.push(entry);
        }
    }

    const byName = (a, b) => String(a.name ?? '').localeCompare(String(b.name ?? '')) || (a.identity < b.identity ? -1 : 1);
    const speaking = moderators
        .filter((entry) => entry.microphone && entry.microphone.isMuted !== true)
        .map((entry) => entry.name || '')
        .filter((name, index, all) => all.indexOf(name) === index);

    return { moderators: moderators.sort(byName), cameras: cameras.sort(byName).slice(0, STRIP_MAX), speaking };
}
