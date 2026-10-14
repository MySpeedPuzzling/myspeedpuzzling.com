// Runs the participant page's live video (contract phase-1b.md stream VP) without a browser or a media server:
// assets/online/compete_video.js - when the phone joins the hall and publishes, who may watch it, the strip of the
// others, the host's voice around the beeps, the studio invitation from the first hint to "Thank you", the second
// screen - assets/online/compete_video_runtime.js against a fake livekit_session.js joinRoom(): the page's very
// camera track published and never stopped, the hall left on a takeover, retries, the voice and the strip received,
// the studio round trip - and assets/online/compete_video_page.js between the controller and both. Prints {scenario: "ok" | error} as JSON; tests/OnlineCompeteVideoScriptTest.php asserts every
// one is "ok".

import assert from 'node:assert/strict';
import {
    AFTER_ROUND_CAMERA_MS,
    afterRoundCamera,
    CLIENT_INITIATED,
    DUPLICATE_IDENTITY,
    formatSecondsLeft,
    hallPeople,
    hallPlan,
    INVITE_ANSWER_MS,
    INVITE_CLEAR_GRACE_MS,
    jittered,
    loadSecondScreen,
    loadStripHidden,
    OUTCOME_SHOWN_MS,
    PARTICIPANT_REMOVED,
    RETRY_MS,
    retryDelayMs,
    saveSecondScreen,
    saveStripHidden,
    secondScreenOffered,
    STRIP_LAYER,
    stripPlan,
    studioHoldsPhone,
    studioReducer,
    studioView,
    studioWanted,
    subscribersFor,
    videoHoldsCamera,
    videoOn,
    videoStatus,
    VOICE_DUCKED,
    voiceVolume,
} from '../assets/online/compete_video.js';
import { LEAVE_WAIT_MS, ParticipantVideo, RoomLink } from '../assets/online/compete_video_runtime.js';
import { CompeteVideoPage } from '../assets/online/compete_video_page.js';
import { memoryStorage, SCREENS, screenFor } from '../assets/online/compete_flow.js';
import { createCompeteApi, STUDIO_INVITE_PLACEHOLDER } from '../assets/online/compete_api.js';
import { deriveView } from '../assets/online/round_state.js';

const ROUND = '018d0050-0000-0000-0000-000000000101';
const PARTICIPANT = '018d0050-0000-0000-0000-000000000202';
const OTHER = '018d0050-0000-0000-0000-000000000203';
const HOST = '018d0000-0000-0000-0000-000000000003';
const INVITE = '018d0060-0000-0000-0000-000000000001';
const OTHER_INVITE = '018d0060-0000-0000-0000-000000000002';
const START = 1_792_000_000_000;
const MINUTE = 60_000;

function state({ video = true, seeEachOther = false, studio = true, entry = {}, startedAtMs = null, stoppedAtMs = null } = {}) {
    return {
        asOfMs: START - 100_000,
        round: {
            id: ROUND,
            minutesLimit: 30,
            startedAtMs,
            stoppedAtMs,
            settings: { detection: 'off', video, seeEachOther, studio, unfinishedMeasure: 'left' },
        },
        entry: entry === null ? null : { taps: 0, candidates: 0, ...entry },
    };
}

function plan(overrides = {}) {
    const s = overrides.state ?? state();
    const serverNowMs = overrides.serverNowMs ?? START - MINUTE;

    return hallPlan({
        available: true,
        state: s,
        screen: 'waiting',
        view: deriveView(s, serverNowMs),
        serverNowMs,
        ready: true,
        cameraLive: true,
        ...overrides,
    });
}

async function settle() {
    for (let i = 0; i < 20; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

// ---- fakes: the page's tracks, elements, timers and LiveKit sessions

class FakeTrack {
    constructor(kind = 'video') {
        this.kind = kind;
        this.readyState = 'live';
        this.stops = 0;
    }

    stop() {
        this.stops += 1;
        this.readyState = 'ended';
    }
}

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.parent = null;
        this.className = '';
        this.textContent = '';
        this.attributes = {};
    }

    append(...elements) {
        for (const element of elements) {
            element.parent = this;
            this.children.push(element);
        }
    }

    remove() {
        if (this.parent) {
            this.parent.children = this.parent.children.filter((child) => child !== this);
            this.parent = null;
        }
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }
}

function fakeTimers() {
    let now = 0;
    let next = 1;
    const tasks = new Map();

    return {
        now: () => now,
        set: (task, ms) => {
            const id = next++;
            tasks.set(id, { at: now + ms, task });

            return id;
        },
        clear: (id) => tasks.delete(id),
        pending: () => [...tasks.values()].map((entry) => entry.at - now).sort((a, b) => a - b),
        async advance(ms) {
            const until = now + ms;

            for (;;) {
                const due = [...tasks.entries()].filter(([, entry]) => entry.at <= until).sort((a, b) => a[1].at - b[1].at)[0];

                if (!due) {
                    break;
                }

                tasks.delete(due[0]);
                now = due[1].at;
                due[1].task();
                await settle();
            }

            now = until;
        },
    };
}

function remotePublication(sid, { muted = false } = {}) {
    const publication = {
        trackSid: sid,
        isDesired: false,
        isMuted: muted,
        dimensions: null,
        track: { volume: null, setVolume(volume) { this.volume = volume; } },
        setSubscribed(value) {
            this.isDesired = value;
        },
        setVideoDimensions(dimensions) {
            this.dimensions = dimensions;
        },
    };

    return publication;
}

function moderator({ microphone = remotePublication('mic-host', { muted: true }), camera = null } = {}) {
    return { identity: `m:${HOST}`, name: 'Hannah Host', kind: 'moderator', id: HOST, camera, microphone, screen: null };
}

function participantCamera(id, name) {
    return { identity: `p:${id}`, name, kind: 'participant', id, camera: remotePublication(`cam-${id}`), microphone: null, screen: null };
}

/**
 * A fake livekit_session.js joinRoom(): every call records its options and returns a session that records what the
 * runtime asks of it; `sessions` lists them in order.
 */
function fakeLiveKit({ failFirst = 0 } = {}) {
    const sessions = [];
    let failures = failFirst;

    const join = async (options) => {
        if (failures > 0) {
            failures -= 1;

            throw new Error('could not establish signal connection');
        }

        const listeners = { participants: [], data: [], state: [] };
        const roomListeners = new Map();
        const room = {
            canPlaybackAudio: true,
            startAudioCalls: 0,
            localParticipant: { identity: `p:${PARTICIPANT}`, connectionQuality: 'excellent' },
            on(event, fn) {
                roomListeners.set(event, [...(roomListeners.get(event) ?? []), fn]);
            },
            off(event, fn) {
                roomListeners.set(event, (roomListeners.get(event) ?? []).filter((listener) => listener !== fn));
            },
            emit(event, ...args) {
                (roomListeners.get(event) ?? []).forEach((fn) => fn(...args));
            },
            startAudio() {
                this.startAudioCalls += 1;
                this.canPlaybackAudio = true;

                return Promise.resolve();
            },
        };
        const session = {
            options,
            room,
            calls: [],
            left: false,
            list: [],
            setSubscribers(allow) {
                this.calls.push(['setSubscribers', allow]);
            },
            onParticipants(fn) {
                listeners.participants.push(fn);
                fn(this.list);

                return () => {
                    listeners.participants = listeners.participants.filter((listener) => listener !== fn);
                };
            },
            onData(topic, fn) {
                const entry = { topic, fn };
                listeners.data.push(entry);

                return () => {
                    listeners.data = listeners.data.filter((listener) => listener !== entry);
                };
            },
            onConnectionState(fn) {
                listeners.state.push(fn);

                return () => {
                    listeners.state = listeners.state.filter((listener) => listener !== fn);
                };
            },
            attach(publication, element) {
                this.calls.push(['attach', publication.trackSid, element.tagName]);

                return () => this.calls.push(['detach', publication.trackSid]);
            },
            async publish(track, { source }) {
                this.calls.push(['publish', track, source]);

                return {
                    track,
                    source,
                    muted: false,
                    async mute() {
                        this.muted = true;
                    },
                    async unmute() {
                        this.muted = false;
                    },
                };
            },
            async unpublish(track) {
                this.calls.push(['unpublish', track]);
            },
            async replaceTrack(oldTrack, newTrack) {
                this.calls.push(['replaceTrack', oldTrack, newTrack]);
            },
            async leave() {
                this.left = true;
                this.calls.push(['leave']);
            },
            // the room changes
            setList(list) {
                this.list = list;
                listeners.participants.forEach((fn) => fn(list));
            },
            data(topic, payload, from) {
                listeners.data.filter((entry) => entry.topic === topic).forEach((entry) => entry.fn(payload, { from, topic }));
            },
            disconnect(reason) {
                // LiveKit: the state first, the reason right after
                listeners.state.forEach((fn) => fn({ state: 'disconnected', reason: null }));
                listeners.state.forEach((fn) => fn({ state: 'disconnected', reason }));
            },
            published() {
                return this.calls.filter(([name]) => name === 'publish').map(([, track, source]) => ({ track, source }));
            },
        };
        sessions.push(session);

        return session;
    };

    return { join, sessions };
}

function tokenServer(answers = {}) {
    const requests = [];
    const requestToken = async (room, role) => {
        requests.push({ room, role });
        const answer = answers[room];

        if (typeof answer === 'function') {
            return answer({ room, role }, requests);
        }

        return answer ?? { kind: 'ok', data: { url: 'wss://livekit.test', token: `${room}-${role}-${requests.length}`, room } };
    };

    return { requestToken, requests };
}

function runtime({ answers = {}, failFirst = 0, microphone = null, audioContext = null } = {}) {
    const livekit = fakeLiveKit({ failFirst });
    const server = tokenServer(answers);
    const timers = fakeTimers();
    const events = { invites: [], studio: [], changes: 0 };
    const containers = { audio: new FakeElement('div'), strip: new FakeElement('div'), host: new FakeElement('div'), studio: new FakeElement('div') };
    const microphones = [];
    const video = new ParticipantVideo({
        requestToken: server.requestToken,
        join: livekit.join,
        audioContext,
        getMicrophone: async () => {
            if (microphone instanceof Error) {
                throw microphone;
            }

            const track = new FakeTrack('audio');
            microphones.push(track);

            return { getAudioTracks: () => [track] };
        },
        containers,
        createElement: (tag) => new FakeElement(tag),
        timers,
        now: timers.now,
        random: () => 0.5,
        onChange: () => {
            events.changes += 1;
        },
        onInvite: (inviteId) => events.invites.push(inviteId),
        onStudio: (event) => events.studio.push(event),
    });

    return { video, livekit, server, timers, events, containers, microphones };
}

const HALL_CAMERA = { hall: 'camera', publish: true, subscribers: 'moderators', strip: false, hostVideo: false, studio: false, voiceVolume: 1 };

const scenarios = {
    'retries wait longer each time, spread, up to half a minute'() {
        assert.equal(retryDelayMs(1, () => 0.5), RETRY_MS[0]);
        assert.equal(retryDelayMs(2, () => 0.5), RETRY_MS[1]);
        assert.equal(retryDelayMs(99, () => 0.5), RETRY_MS[RETRY_MS.length - 1]);
        assert.equal(retryDelayMs(0, () => 0.5), RETRY_MS[0]);
        assert.equal(jittered(1000, () => 0), 800);
        assert.equal(jittered(1000, () => 0.999999), 1200);
        assert.equal(jittered(1000, () => Number.NaN), 1000);
    },

    'video follows the round switch and who may watch follows "see each other"'() {
        assert.equal(videoOn(state()), true);
        assert.equal(videoOn(state({ video: false })), false);
        assert.equal(videoOn(null), false);
        assert.equal(subscribersFor(state()), 'moderators');
        assert.equal(subscribersFor(state({ seeEachOther: true })), 'all');
        assert.equal(subscribersFor(null), 'moderators');
    },

    'nobody joins without video parts, the round video, an entry, in a preview or with a dead link'() {
        assert.deepEqual(plan(), { room: 'camera', publish: true, subscribers: 'moderators' });
        assert.equal(plan({ available: false }).room, null);
        assert.equal(plan({ state: state({ video: false }) }).room, null);
        assert.equal(plan({ state: state({ entry: null }) }).room, null);
        assert.equal(plan({ preview: true }).room, null);
        assert.equal(plan({ linkDead: true }).room, null);
        assert.equal(plan({ state: state({ seeEachOther: true }) }).subscribers, 'all');
    },

    'the camera device publishes once ready, on the round screens - a takeover or the studio leaves the hall'() {
        assert.equal(plan({ ready: false }).room, null, 'not before "I\'m ready"');
        assert.equal(plan({ screen: 'checklist' }).room, null);
        assert.equal(plan({ screen: 'welcome' }).room, null);
        assert.equal(plan({ takenOver: true }).room, null, 'a takeover leaves the hall');
        assert.equal(plan({ screen: 'taken_over' }).room, null);
        assert.equal(plan({ inStudio: true }).room, null, 'one encoder: the studio takes the phone');

        for (const screen of ['waiting', 'countdown', 'running', 'finished', 'timeup']) {
            assert.deepEqual(plan({ screen }), { room: 'camera', publish: true, subscribers: 'moderators' }, screen);
        }

        assert.deepEqual(plan({ cameraLive: false }), { room: 'camera', publish: false, subscribers: 'moderators' }, 'in the hall, nothing to publish');
    },

    'after the round the camera stays in the hall for a while, then only the voice'() {
        const ended = state({ startedAtMs: START, stoppedAtMs: START + 10 * MINUTE });
        const endAt = START + 10 * MINUTE;

        assert.equal(plan({ state: ended, screen: 'after', serverNowMs: endAt + MINUTE }).publish, true);
        assert.equal(plan({ state: ended, screen: 'after', serverNowMs: endAt + AFTER_ROUND_CAMERA_MS - 1 }).publish, true);
        assert.deepEqual(plan({ state: ended, screen: 'after', serverNowMs: endAt + AFTER_ROUND_CAMERA_MS }), { room: 'camera', publish: false, subscribers: 'moderators' });
        assert.equal(afterRoundCamera(deriveView(state(), START), START), false, 'no end, no window');
    },

    'live video holds the camera on air and on the after screen while the hall still gets it'() {
        const ended = state({ startedAtMs: START, stoppedAtMs: START + 10 * MINUTE });
        const view = deriveView(ended, START + 11 * MINUTE);
        const hall = { room: 'camera' };

        assert.equal(videoHoldsCamera({ hall, screen: 'after', view, serverNowMs: START + 11 * MINUTE }), true);
        assert.equal(videoHoldsCamera({ hall, screen: 'after', view, serverNowMs: START + 10 * MINUTE + AFTER_ROUND_CAMERA_MS }), false);
        assert.equal(videoHoldsCamera({ hall: { room: null }, screen: 'after', view, serverNowMs: START + 11 * MINUTE }), false);
        assert.equal(videoHoldsCamera({ hall: { room: null }, inStudio: true, screen: 'after', view, serverNowMs: START + 99 * MINUTE }), true, 'on air');
        assert.equal(videoHoldsCamera({ hall, screen: 'running', view: deriveView(state({ startedAtMs: START }), START + MINUTE), serverNowMs: START + MINUTE }), false, 'the round screens hold it anyway');
    },

    'a second screen joins as a screen and never publishes'() {
        assert.deepEqual(plan({ secondScreen: true, ready: false, screen: 'second_screen' }), { room: 'screen', publish: false, subscribers: 'moderators' });
        assert.equal(plan({ secondScreen: true, state: state({ video: false }) }).room, null);
        assert.equal(plan({ secondScreen: true, preview: true }).room, null);
    },

    'the others are shown while waiting and after the finish - never during the solve'() {
        const see = state({ seeEachOther: true });
        const hall = { room: 'camera' };
        const view = deriveView(see, START - MINUTE);
        const shown = (screen, extra = {}) => stripPlan({ state: see, hall, screen, view, ...extra });

        assert.deepEqual(shown('waiting'), { shown: true, tiles: true });
        assert.deepEqual(shown('finished'), { shown: true, tiles: true });
        assert.deepEqual(shown('after'), { shown: true, tiles: true });

        for (const screen of ['countdown', 'running', 'timeup', 'checklist']) {
            assert.deepEqual(shown(screen), { shown: false, tiles: false }, screen);
        }

        assert.deepEqual(shown('waiting', { practice: true }), { shown: false, tiles: false }, 'a practice is a solve');
        assert.deepEqual(shown('waiting', { inStudio: true }), { shown: false, tiles: false });
        assert.deepEqual(shown('waiting', { hidden: true }), { shown: true, tiles: false }, '"Hide": the header stays to show them again');
        assert.deepEqual(stripPlan({ state: state(), hall, screen: 'waiting', view }), { shown: false, tiles: false }, 'not allowed by the round');
        assert.deepEqual(stripPlan({ state: see, hall: { room: null }, screen: 'waiting', view }), { shown: false, tiles: false });

        // A second screen: by the round's clock and the finish taps
        const running = state({ seeEachOther: true, startedAtMs: START });
        const runningView = deriveView(running, START + MINUTE);
        assert.equal(stripPlan({ state: running, hall: { room: 'screen' }, screen: 'second_screen', view: runningView }).shown, false, 'still puzzling');
        const finished = state({ seeEachOther: true, startedAtMs: START, entry: { taps: 1 } });
        assert.equal(stripPlan({ state: finished, hall: { room: 'screen' }, screen: 'second_screen', view: runningView }).shown, true, 'finished');
        assert.equal(stripPlan({ state: see, hall: { room: 'screen' }, screen: 'second_screen', view }).shown, true, 'waiting');
    },

    'the host\'s voice gives way to the countdown beeps and the alarm'() {
        const running = deriveView(state({ startedAtMs: START }), START - 20_000);
        assert.equal(voiceVolume(running, START - 20_000), 1);
        assert.equal(voiceVolume(running, START - 3600), 1);
        assert.equal(voiceVolume(running, START - 3500), VOICE_DUCKED, 'before the first beep');
        assert.equal(voiceVolume(running, START), VOICE_DUCKED, 'GO');
        assert.equal(voiceVolume(running, START + 899), VOICE_DUCKED);
        assert.equal(voiceVolume(running, START + 900), 1);

        const limit = state({ startedAtMs: START });
        const endsAt = START + 30 * MINUTE;
        assert.equal(voiceVolume(deriveView(limit, endsAt + 100), endsAt + 100), VOICE_DUCKED, 'the alarm');
        assert.equal(voiceVolume(deriveView(limit, endsAt + 2500), endsAt + 2500), 1);
        const stopped = state({ startedAtMs: START, stoppedAtMs: START + MINUTE });
        assert.equal(voiceVolume(deriveView(stopped, START + MINUTE + 100), START + MINUTE + 100), 1, 'a stop has no alarm');
        assert.equal(voiceVolume(null, Number.NaN), 1);
    },

    'the quiet status line'() {
        assert.equal(videoStatus({ link: 'off' }), '');
        assert.equal(videoStatus({ link: 'connecting' }), 'connecting');
        assert.equal(videoStatus({ link: 'connected', quality: 'excellent' }), 'live');
        assert.equal(videoStatus({ link: 'connected', quality: 'poor' }), 'weak');
        assert.equal(videoStatus({ link: 'connected', quality: 'lost' }), 'weak');
        assert.equal(videoStatus({ link: 'reconnecting' }), 'retrying');
        assert.equal(videoStatus({ link: 'retrying' }), 'retrying');
        assert.equal(videoStatus({ link: 'refused' }), 'off');
        assert.equal(videoStatus({ link: 'duplicate' }), 'off');
    },

    'an invitation learned from the data message, the heartbeat or the state is one invitation'() {
        const handled = new Set();
        let studio = studioReducer(null, { type: 'learned', inviteId: INVITE.toUpperCase(), source: 'data', atMs: 1000 }, handled);
        assert.deepEqual(studio, { inviteId: INVITE, phase: 'invited', learnedAtMs: 1000, expiresAtMs: 1000 + INVITE_ANSWER_MS, outcome: null, error: null });

        // The same invitation again from the heartbeat and the state: nothing changes (the window keeps its start)
        assert.equal(studioReducer(studio, { type: 'learned', inviteId: INVITE, source: 'heartbeat', atMs: 5000 }, handled), studio);
        assert.equal(studioReducer(studio, { type: 'learned', inviteId: INVITE, status: 'invited', source: 'state', atMs: 6000 }, handled), studio);

        // Only from the heartbeat (the data message was missed): the same invitation
        const fromBeat = studioReducer(null, { type: 'learned', inviteId: INVITE, source: 'heartbeat', atMs: 2000 }, new Set());
        assert.equal(fromBeat.phase, 'invited');

        // The window: 2 minutes from when the page heard of it
        assert.equal(studioReducer(studio, { type: 'tick', atMs: 1000 + INVITE_ANSWER_MS - 1 }, handled), studio);
        studio = studioReducer(studio, { type: 'tick', atMs: 1000 + INVITE_ANSWER_MS }, handled);
        assert.equal(studio.phase, 'over');
        assert.equal(studio.outcome, 'expired');
        assert.ok(handled.has(INVITE));
        assert.equal(studioReducer(studio, { type: 'tick', atMs: studio.overAtMs + OUTCOME_SHOWN_MS }, handled), null, 'forgotten after a while');

        // Never offered again, whatever a late hint says
        assert.equal(studioReducer(null, { type: 'learned', inviteId: INVITE, source: 'heartbeat', atMs: 999_999 }, handled), null);
        // A newer invitation is offered
        assert.equal(studioReducer(null, { type: 'learned', inviteId: OTHER_INVITE, source: 'data', atMs: 999_999 }, handled).inviteId, OTHER_INVITE);
        // Garbage
        assert.equal(studioReducer(null, { type: 'learned', inviteId: '', atMs: 1 }, new Set()), null);
        assert.equal(studioReducer(null, { type: 'nonsense', atMs: 1 }, new Set()), null);
    },

    'Join → answered → joining → on air → "Thank you" → back, never offered again'() {
        const handled = new Set();
        let studio = studioReducer(null, { type: 'learned', inviteId: INVITE, source: 'data', atMs: 0 }, handled);
        studio = studioReducer(studio, { type: 'join', atMs: 100 }, handled);
        assert.equal(studio.phase, 'answering');
        assert.equal(studioHoldsPhone(studio), false, 'the hall is left only once the answer is in');
        studio = studioReducer(studio, { type: 'answered', status: 'joined', atMs: 200 }, handled);
        assert.equal(studio.phase, 'joining');
        assert.equal(studioHoldsPhone(studio), true);
        assert.equal(studioWanted(studio), true);
        studio = studioReducer(studio, { type: 'connected', atMs: 400 }, handled);
        assert.equal(studio.phase, 'on_air');

        // A dropped connection is no end: the runtime tries again
        assert.equal(studioReducer(studio, { type: 'left', reason: 0, atMs: 500 }, handled), studio);
        assert.equal(studioReducer(studio, { type: 'left', reason: CLIENT_INITIATED, atMs: 500 }, handled), studio);

        // "Thank you": removed by the server
        studio = studioReducer(studio, { type: 'left', reason: PARTICIPANT_REMOVED, atMs: 60_000 }, handled);
        assert.equal(studio.phase, 'over');
        assert.equal(studio.outcome, 'thanks');
        assert.equal(studioHoldsPhone(studio), false, 'back to the hall');
        assert.ok(handled.has(INVITE));

        // The state saying ended while on air ends it too
        let viaState = studioReducer(null, { type: 'learned', inviteId: OTHER_INVITE, source: 'data', atMs: 0 }, new Set());
        viaState = { ...viaState, phase: 'on_air' };
        assert.equal(studioReducer(viaState, { type: 'learned', inviteId: OTHER_INVITE, status: 'ended', source: 'state', atMs: 1 }, new Set()).outcome, 'thanks');
    },

    '"Not now", and answers the server refuses'() {
        const handled = new Set();
        const fresh = () => studioReducer(null, { type: 'learned', inviteId: INVITE, source: 'data', atMs: 0 }, handled);

        let studio = studioReducer(fresh(), { type: 'decline', atMs: 1 }, handled);
        assert.equal(studio.phase, 'declining');
        assert.equal(studioReducer(studio, { type: 'answered', status: 'declined', atMs: 2 }, handled), null);
        assert.ok(handled.has(INVITE));

        const outcome = (code) => {
            handled.clear();
            const answering = studioReducer(fresh(), { type: 'join', atMs: 1 }, handled);

            return studioReducer(answering, { type: 'refused', code, atMs: 2 }, handled);
        };

        assert.equal(outcome('invite_expired').outcome, 'expired');
        assert.equal(outcome('invite_not_open').outcome, 'withdrawn');
        assert.equal(outcome('invite_not_found').outcome, 'withdrawn');
        assert.equal(outcome('video_unavailable').outcome, 'unavailable');
        assert.deepEqual([outcome('offline').phase, outcome('offline').error], ['invited', 'offline'], 'no connection: Join again');
        assert.deepEqual([outcome('failed').phase, outcome('failed').error], ['invited', 'failed']);

        // "Not now" refused (it had expired meanwhile): simply gone
        handled.clear();
        const declining = studioReducer(fresh(), { type: 'decline', atMs: 1 }, handled);
        assert.equal(studioReducer(declining, { type: 'refused', code: 'invite_expired', atMs: 2 }, handled), null);

        // The answer said something else than joined
        handled.clear();
        const answering = studioReducer(fresh(), { type: 'join', atMs: 1 }, handled);
        assert.equal(studioReducer(answering, { type: 'answered', status: 'expired', atMs: 2 }, handled).outcome, 'expired');
    },

    'a heartbeat without an open invitation withdraws it - unless it was sent before the page heard of it'() {
        const handled = new Set();
        const studio = studioReducer(null, { type: 'learned', inviteId: INVITE, source: 'data', atMs: 10_000 }, handled);

        assert.equal(studioReducer(studio, { type: 'cleared', sentAtMs: 9_000, atMs: 10_500 }, handled), studio, 'sent before the invitation');
        assert.equal(studioReducer(studio, { type: 'cleared', sentAtMs: 10_000 + INVITE_CLEAR_GRACE_MS - 1, atMs: 12_500 }, handled), studio);
        assert.equal(studioReducer(studio, { type: 'cleared', sentAtMs: 10_000 + INVITE_CLEAR_GRACE_MS, atMs: 12_500 }, handled).outcome, 'withdrawn');

        // Answered: a heartbeat without an open invitation is normal then
        const joining = { ...studio, phase: 'joining' };
        assert.equal(studioReducer(joining, { type: 'cleared', sentAtMs: 99_999, atMs: 99_999 }, handled), joining);

        // The state saying it ended before the answer
        assert.equal(studioReducer(studio, { type: 'learned', inviteId: INVITE, status: 'ended', source: 'state', atMs: 11_000 }, new Set()).outcome, 'withdrawn');
        assert.equal(studioReducer(studio, { type: 'learned', inviteId: INVITE, status: 'expired', source: 'state', atMs: 11_000 }, new Set()).outcome, 'expired');
    },

    'a reload on air goes back into the studio - or hears that the studio moved on'() {
        const handled = new Set();
        let studio = studioReducer(null, { type: 'learned', inviteId: INVITE, status: 'joined', source: 'state', atMs: 0 }, handled);
        assert.equal(studio.phase, 'joining');

        // The webhook ended it when the old page left
        studio = studioReducer(studio, { type: 'refused_studio', code: 'studio_not_joined', atMs: 1 }, handled);
        assert.equal(studio.outcome, 'moved_on');

        // A joined invitation the heartbeat mentions is no reason to join (only the state's word is)
        assert.equal(studioReducer(null, { type: 'learned', inviteId: OTHER_INVITE, status: 'joined', source: 'heartbeat', atMs: 0 }, new Set()), null);
        // Ended / expired / declined before the page heard of it: nothing to offer
        const seen = new Set();
        assert.equal(studioReducer(null, { type: 'learned', inviteId: OTHER_INVITE, status: 'ended', source: 'state', atMs: 0 }, seen), null);
        assert.ok(seen.has(OTHER_INVITE));
        // Video switched off on air
        assert.equal(studioReducer({ ...studio, phase: 'on_air', outcome: null }, { type: 'refused_studio', code: 'video_unavailable', atMs: 2 }, new Set()).outcome, 'unavailable');
    },

    '"Leave the studio", and the end when the phone can not be in it any more'() {
        const handled = new Set();
        let studio = { ...studioReducer(null, { type: 'learned', inviteId: INVITE, source: 'data', atMs: 0 }, handled), phase: 'on_air' };
        studio = studioReducer(studio, { type: 'leave', atMs: 1 }, handled);
        assert.equal(studio.phase, 'leaving');
        assert.equal(studioHoldsPhone(studio), true, 'not in the hall before the studio is left');
        assert.equal(studioWanted(studio), false);
        studio = studioReducer(studio, { type: 'left', reason: CLIENT_INITIATED, atMs: 2 }, handled);
        assert.equal(studio.outcome, 'left');

        const onAir = { ...studioReducer(null, { type: 'learned', inviteId: OTHER_INVITE, source: 'data', atMs: 0 }, new Set()), phase: 'on_air' };
        assert.equal(studioReducer(onAir, { type: 'stop', outcome: 'unavailable', atMs: 5 }, new Set()).outcome, 'unavailable');
        assert.equal(studioReducer(null, { type: 'stop', atMs: 5 }, new Set()), null);

        // Another invitation while on air: the one in hand goes on
        assert.equal(studioReducer(onAir, { type: 'learned', inviteId: INVITE.replace('1', '9'), source: 'data', atMs: 6 }, new Set()), onAir);
    },

    'what the page shows of the invitation'() {
        const studio = studioReducer(null, { type: 'learned', inviteId: INVITE, source: 'data', atMs: 0 }, new Set());
        const shown = (screen, cameraDevice = true, at = 15_000, s = studio) => studioView(s, { cameraDevice, screen, serverNowMs: at });

        assert.deepEqual(shown('finished'), { prompt: true, secondsLeft: 105, busy: false, panel: null, outcome: null, elsewhere: false, error: null });
        assert.equal(shown('after').prompt, true);
        assert.equal(shown('waiting').prompt, true);
        assert.equal(shown('timeup').prompt, true);
        assert.equal(shown('running').prompt, false, 'never during the solve - it waits for the finish');
        assert.equal(shown('countdown').prompt, false);
        assert.deepEqual(shown('second_screen', false), { prompt: false, secondsLeft: 0, busy: false, panel: null, outcome: null, elsewhere: true, error: null });
        assert.equal(shown('finished', true, 15_000, { ...studio, phase: 'answering' }).busy, true);
        assert.equal(shown('finished', true, 15_000, { ...studio, phase: 'joining' }).panel, 'joining');
        assert.equal(shown('finished', true, 15_000, { ...studio, phase: 'on_air' }).panel, 'on_air');
        assert.equal(shown('finished', true, 15_000, { ...studio, phase: 'over', outcome: 'thanks' }).outcome, 'thanks');
        assert.equal(shown('finished', true, INVITE_ANSWER_MS + 5000).secondsLeft, 0);
        assert.equal(studioView(null, { cameraDevice: true, screen: 'finished', serverNowMs: 0 }).prompt, false);
        assert.equal(formatSecondsLeft(105), '1:45');
        assert.equal(formatSecondsLeft(9), '0:09');
        assert.equal(formatSecondsLeft(-3), '0:00');
    },

    'a second screen is offered only to a device that is not the camera device, while the video is on'() {
        const offered = (overrides) => secondScreenOffered({ available: true, state: state(), screen: 'checklist', otherDeviceActive: true, ...overrides });

        assert.equal(offered({}), true);
        assert.equal(offered({ otherDeviceActive: false }), false, 'nobody else holds the link');
        assert.equal(offered({ screen: 'taken_over', otherDeviceActive: false }), true);
        assert.equal(offered({ screen: 'waiting' }), false, 'the camera device itself');
        assert.equal(offered({ state: state({ video: false }) }), false);
        assert.equal(offered({ available: false }), false);
        assert.equal(offered({ preview: true }), false);
        assert.equal(offered({ state: state({ entry: null }) }), false);

        // The screen: whatever the progress, the round's clock to its end - after the not-entered and dead-link checks
        assert.ok(SCREENS.includes('second_screen'));
        const s = state({ startedAtMs: START });
        const view = deriveView(s, START + MINUTE);
        const progress = { ready: true, finishes: [], pieces: null };
        assert.equal(screenFor({ state: s, view, consent: false, progress, secondScreen: true }), 'second_screen');
        assert.equal(screenFor({ state: s, view, consent: true, progress, takenOver: true, secondScreen: true }), 'second_screen');
        assert.equal(screenFor({ state: state({ entry: null }), view, consent: true, progress, secondScreen: true }), 'not_entered');
        assert.equal(screenFor({ state: s, view, consent: true, progress, linkDead: true, secondScreen: true }), 'dead_link');
        assert.equal(screenFor({ state: s, view, consent: true, progress }), 'running', 'unchanged without it');
    },

    'the device remembers the second screen and the hidden strip'() {
        const storage = memoryStorage();
        assert.equal(loadSecondScreen(storage, PARTICIPANT), false);
        saveSecondScreen(storage, PARTICIPANT, true);
        assert.equal(loadSecondScreen(storage, PARTICIPANT), true);
        assert.equal(loadSecondScreen(storage, OTHER), false, 'per participant');
        saveSecondScreen(storage, PARTICIPANT, false);
        assert.equal(loadSecondScreen(storage, PARTICIPANT), false);

        assert.equal(loadStripHidden(storage), false);
        saveStripHidden(storage, true);
        assert.equal(loadStripHidden(storage), true);
        saveStripHidden(storage, false);
        assert.equal(loadStripHidden(storage), false);

        const broken = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } };
        assert.equal(loadSecondScreen(broken, PARTICIPANT), false);
        assert.equal(saveSecondScreen(broken, PARTICIPANT, true), false);
        assert.equal(loadStripHidden(null), false);
    },

    'the hall\'s people: the moderators and their open microphones, the others\' cameras, never a second screen'() {
        const people = hallPeople([
            participantCamera(OTHER, 'Zoe'),
            { identity: `p:${PARTICIPANT}:screen`, name: 'Me', kind: 'screen', id: PARTICIPANT, camera: null, microphone: null },
            participantCamera('018d0050-0000-0000-0000-000000000204', 'Anna'),
            { identity: 'p:018d0050-0000-0000-0000-000000000205', name: 'No camera', kind: 'participant', camera: null, microphone: null },
            moderator({ microphone: remotePublication('mic', { muted: false }) }),
            { identity: 's:abc', kind: 'spectator', camera: null, microphone: null },
        ]);

        assert.deepEqual(people.cameras.map((entry) => entry.name), ['Anna', 'Zoe']);
        assert.equal(people.moderators.length, 1);
        assert.deepEqual(people.speaking, ['Hannah Host']);
        assert.deepEqual(hallPeople([moderator()]).speaking, [], 'push-to-talk published muted: silent');
        assert.deepEqual(hallPeople(null), { moderators: [], cameras: [], speaking: [] });
    },

    'the API asks for video tokens and answers invitations at their URLs'() {
        const requests = [];
        const api = createCompeteApi({
            urls: { state: '/s', videoToken: '/api/compete/T/video-token', studioAnswer: `/api/compete/T/studio-invites/${STUDIO_INVITE_PLACEHOLDER}` },
            fetch: async (url, init) => {
                requests.push({ url, init });

                return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ serverNowMs: 1, url: 'wss://x', token: 't' }) };
            },
        });

        return (async () => {
            await api.videoToken({ deviceId: 'D', room: 'hall', role: 'camera' });
            await api.studioAnswer({ inviteId: INVITE, answer: 'join' });
            assert.equal(requests[0].url, '/api/compete/T/video-token');
            assert.deepEqual(JSON.parse(requests[0].init.body), { deviceId: 'D', room: 'hall', role: 'camera' });
            assert.equal(requests[0].init.credentials, 'omit');
            assert.equal(requests[1].url, `/api/compete/T/studio-invites/${INVITE}`);
            assert.deepEqual(JSON.parse(requests[1].init.body), { answer: 'join' });

            // No video parts / a broken id: nothing is sent
            const without = createCompeteApi({ urls: { state: '/s' }, fetch: async () => { throw new Error('must not be called'); } });
            assert.deepEqual(await without.videoToken({ deviceId: 'D', room: 'hall', role: 'camera' }), { kind: 'offline' });
            assert.deepEqual(await api.studioAnswer({ inviteId: '../x', answer: 'join' }), { kind: 'offline' });
            assert.equal(requests.length, 2);
        })();
    },

    // ---- the runtime against a fake LiveKit

    async 'the camera device joins the hall and publishes the page\'s very camera track - never stopping it'() {
        const { video, livekit, server, events } = runtime();
        const camera = new FakeTrack('video');

        video.apply({ ...HALL_CAMERA, camera });
        await settle();

        assert.deepEqual(server.requests, [{ room: 'hall', role: 'camera' }]);
        const [session] = livekit.sessions;
        assert.equal(session.options.allowSubscribers, 'moderators');
        assert.deepEqual(session.options.connectOptions, { autoSubscribe: false }, 'nothing received by itself');
        assert.equal(session.options.url, 'wss://livekit.test');
        assert.deepEqual(session.published(), [{ track: camera, source: 'camera' }]);
        assert.equal(camera.stops, 0);
        assert.equal(video.snapshot().hall, 'connected');
        assert.equal(video.snapshot().publishing, true);
        assert.ok(events.changes > 0);

        // The same plan again: nothing happens
        const calls = session.calls.length;
        video.apply({ ...HALL_CAMERA, camera });
        await settle();
        assert.equal(session.calls.length, calls);
        assert.equal(livekit.sessions.length, 1);
    },

    async 'another lens is the same publication with the new track; a camera off is unpublished'() {
        const { video, livekit } = runtime();
        const front = new FakeTrack('video');
        const back = new FakeTrack('video');

        video.apply({ ...HALL_CAMERA, camera: front });
        await settle();
        video.apply({ ...HALL_CAMERA, camera: back });
        await settle();
        const [session] = livekit.sessions;
        assert.deepEqual(session.calls.find(([name]) => name === 'replaceTrack'), ['replaceTrack', front, back]);

        video.apply({ ...HALL_CAMERA, camera: back, publish: false });
        await settle();
        assert.deepEqual(session.calls.at(-1), ['unpublish', back]);
        assert.equal(session.left, false, 'still in the hall: the host\'s voice');
        assert.equal(front.stops + back.stops, 0);

        // An ended track is not published
        const ended = new FakeTrack('video');
        ended.readyState = 'ended';
        video.apply({ ...HALL_CAMERA, camera: ended });
        await settle();
        assert.equal(session.published().length, 1);
    },

    async 'who may watch follows "see each other" live'() {
        const { video, livekit } = runtime();
        const camera = new FakeTrack();
        video.apply({ ...HALL_CAMERA, camera });
        await settle();
        video.apply({ ...HALL_CAMERA, camera, subscribers: 'all' });
        await settle();
        const [session] = livekit.sessions;
        assert.deepEqual(session.calls.filter(([name]) => name === 'setSubscribers'), [['setSubscribers', 'all']]);
        video.apply({ ...HALL_CAMERA, camera, subscribers: 'moderators' });
        await settle();
        assert.deepEqual(session.calls.filter(([name]) => name === 'setSubscribers').at(-1), ['setSubscribers', 'moderators']);
    },

    async 'a takeover leaves the hall - the camera stays live'() {
        const { video, livekit } = runtime();
        const camera = new FakeTrack();
        video.apply({ ...HALL_CAMERA, camera });
        await settle();
        video.apply({ ...HALL_CAMERA, camera, hall: null, publish: false });
        await settle();
        const [session] = livekit.sessions;
        assert.equal(session.left, true);
        assert.equal(camera.stops, 0);
        assert.equal(camera.readyState, 'live');
        assert.equal(video.snapshot().hall, 'off');
    },

    async 'a refusal is not tried again - except a claim still on its way'() {
        const refusing = runtime({ answers: { hall: { kind: 'client', status: 409, data: { error: 'video_unavailable' } } } });
        refusing.video.apply({ ...HALL_CAMERA, camera: new FakeTrack() });
        await settle();
        await refusing.timers.advance(120_000);
        assert.equal(refusing.server.requests.length, 1);
        assert.equal(refusing.video.snapshot().hall, 'refused');
        assert.equal(refusing.livekit.sessions.length, 0);

        // "I'm ready" a moment ago: the server may not know this device yet
        let asked = 0;
        const claim = runtime({ answers: { hall: () => (++asked < 3 ? { kind: 'client', status: 409, data: { error: 'not_active_device' } } : { kind: 'ok', data: { url: 'wss://l', token: 't' } }) } });
        claim.video.apply({ ...HALL_CAMERA, camera: new FakeTrack() });
        await settle();
        assert.equal(claim.video.snapshot().hall, 'retrying');
        await claim.timers.advance(RETRY_MS[0] * 1.2);
        await claim.timers.advance(RETRY_MS[1] * 1.2);
        assert.equal(asked, 3);
        assert.equal(claim.video.snapshot().hall, 'connected');
    },

    async 'LiveKit not reachable or dropped: tried again with growing waits - another device of the link: not'() {
        const { video, livekit, timers } = runtime({ failFirst: 2 });
        const camera = new FakeTrack();
        video.apply({ ...HALL_CAMERA, camera });
        await settle();
        assert.equal(video.snapshot().hall, 'retrying');
        assert.deepEqual(timers.pending(), [RETRY_MS[0]]);
        await timers.advance(RETRY_MS[0]);
        assert.deepEqual(timers.pending(), [RETRY_MS[1]], 'the second wait is longer');
        await timers.advance(RETRY_MS[1]);
        assert.equal(video.snapshot().hall, 'connected');
        assert.equal(livekit.sessions.length, 1);

        // A drop right after two failures: the waits go on growing (a connection that held a minute starts them over)
        livekit.sessions[0].disconnect(0);
        await settle();
        assert.equal(video.snapshot().hall, 'retrying');
        assert.equal(video.snapshot().publishing, false);
        assert.deepEqual(timers.pending(), [RETRY_MS[2]]);
        await timers.advance(RETRY_MS[2]);
        assert.equal(livekit.sessions.length, 2);
        assert.deepEqual(livekit.sessions[1].published(), [{ track: camera, source: 'camera' }], 'the same track again');

        // The same identity elsewhere (the link taken on another device): not again by itself
        livekit.sessions[1].disconnect(DUPLICATE_IDENTITY);
        await settle();
        await timers.advance(120_000);
        assert.equal(livekit.sessions.length, 2);
        assert.equal(video.snapshot().hall, 'duplicate');
        assert.equal(camera.stops, 0);
    },

    async 'the host\'s voice is received and played, lowered around the beeps, "speaking" while open'() {
        const { video, livekit, containers } = runtime();
        video.apply({ ...HALL_CAMERA, camera: new FakeTrack() });
        await settle();
        const [session] = livekit.sessions;
        const microphone = remotePublication('mic-host', { muted: true });
        session.setList([moderator({ microphone })]);
        await settle();

        assert.equal(microphone.isDesired, true, 'subscribed');
        assert.deepEqual(session.calls.filter(([name]) => name === 'attach'), [['attach', 'mic-host', 'AUDIO']]);
        assert.equal(containers.audio.children.length, 1);
        assert.deepEqual(video.snapshot().speaking, [], 'push-to-talk not held');

        microphone.isMuted = false;
        session.setList([moderator({ microphone })]);
        assert.deepEqual(video.snapshot().speaking, ['Hannah Host']);
        assert.equal(session.calls.filter(([name]) => name === 'attach').length, 1, 'attached once');

        video.apply({ ...HALL_CAMERA, camera: video.plan.camera, voiceVolume: VOICE_DUCKED });
        await settle();
        assert.equal(microphone.track.volume, VOICE_DUCKED);
        video.apply({ ...HALL_CAMERA, camera: video.plan.camera, voiceVolume: 1 });
        await settle();
        assert.equal(microphone.track.volume, 1);

        // The host leaves: the sound goes
        session.setList([]);
        assert.equal(containers.audio.children.length, 0);
        assert.deepEqual(session.calls.at(-1), ['detach', 'mic-host']);
    },

    async 'the others\' cameras at the 180p layer while the strip shows them - none otherwise'() {
        const { video, livekit, containers } = runtime();
        const camera = new FakeTrack();
        video.apply({ ...HALL_CAMERA, camera, subscribers: 'all' });
        await settle();
        const [session] = livekit.sessions;
        const zoe = participantCamera(OTHER, 'Zoe');
        session.setList([zoe, moderator()]);
        assert.equal(zoe.camera.isDesired, false, 'not while the strip is hidden');

        video.apply({ ...HALL_CAMERA, camera, subscribers: 'all', strip: true });
        await settle();
        assert.equal(zoe.camera.isDesired, true);
        assert.deepEqual(zoe.camera.dimensions, STRIP_LAYER);
        assert.equal(containers.strip.children.length, 1);
        assert.equal(containers.strip.children[0].children[1].textContent, 'Zoe');
        assert.equal(video.snapshot().strip, 1);
        assert.equal(video.snapshot().cameras, 1);

        video.apply({ ...HALL_CAMERA, camera, subscribers: 'all', strip: false });
        await settle();
        assert.equal(zoe.camera.isDesired, false);
        assert.equal(containers.strip.children.length, 0);
        assert.equal(video.snapshot().cameras, 1, 'still counted for the header');
    },

    async 'a second screen joins as a screen, publishes nothing, gets the host\'s camera'() {
        const { video, livekit, server, containers } = runtime();
        video.apply({ hall: 'screen', publish: false, camera: null, subscribers: 'moderators', strip: false, hostVideo: true, studio: false, voiceVolume: 1 });
        await settle();
        assert.deepEqual(server.requests, [{ room: 'hall', role: 'screen' }]);
        const [session] = livekit.sessions;
        const hostCamera = remotePublication('cam-host');
        session.setList([moderator({ camera: hostCamera })]);
        assert.equal(hostCamera.isDesired, true);
        assert.equal(containers.host.children.length, 1);
        assert.equal(video.snapshot().hostTiles, 1);
        assert.deepEqual(session.published(), []);
    },

    async 'only the server\'s own data messages invite'() {
        const { video, livekit, events } = runtime();
        video.apply({ ...HALL_CAMERA, camera: new FakeTrack() });
        await settle();
        const [session] = livekit.sessions;
        session.data('studio', { inviteId: INVITE }, `m:${HOST}`);
        session.data('studio', { nope: 1 }, null);
        session.data('other', { inviteId: INVITE }, null);
        assert.deepEqual(events.invites, []);
        session.data('studio', { inviteId: INVITE }, null);
        assert.deepEqual(events.invites, [INVITE]);
    },

    async 'the studio: the hall left first, the token right before, the same camera and a microphone, "Thank you", back'() {
        const { video, livekit, server, events, microphones, timers } = runtime();
        const camera = new FakeTrack();
        video.apply({ ...HALL_CAMERA, camera });
        await settle();
        const hall = livekit.sessions[0];

        // Joined: the plan takes the phone out of the hall and into the studio
        video.apply({ ...HALL_CAMERA, camera, hall: null, publish: false, studio: true });
        await settle();
        assert.equal(hall.left, true, 'the hall first');
        assert.deepEqual(server.requests.at(-1), { room: 'studio', role: 'camera' });
        const studio = livekit.sessions[1];
        assert.equal(studio.options.allowSubscribers, 'all');
        assert.deepEqual(studio.published(), [{ track: camera, source: 'camera' }, { track: microphones[0], source: 'microphone' }]);
        assert.equal(microphones.length, 1, 'asked once, at that moment');
        assert.deepEqual(events.studio, [{ type: 'connected' }]);
        assert.equal(video.snapshot().studio, 'connected');
        assert.equal(video.snapshot().micOn, true);

        studio.setList([moderator({ camera: remotePublication('cam-host'), microphone: remotePublication('mic-host') }), participantCamera(OTHER, 'Zoe')]);
        assert.deepEqual(video.snapshot().studioNames, [{ name: 'Hannah Host', host: true }, { name: 'Zoe', host: false }], 'the host first');

        // Mute
        assert.equal(video.toggleMicrophone(), true);
        assert.equal(video.snapshot().micMuted, true);
        video.toggleMicrophone();

        // "Thank you": the server removes the guest
        studio.disconnect(PARTICIPANT_REMOVED);
        await settle();
        assert.deepEqual(events.studio.at(-1), { type: 'left', reason: PARTICIPANT_REMOVED });
        await timers.advance(60_000);
        assert.equal(livekit.sessions.length, 2, 'never back into the studio by itself');

        // The page goes back to the hall
        video.apply({ ...HALL_CAMERA, camera });
        await settle();
        assert.equal(microphones[0].readyState, 'ended', 'the microphone goes with the studio');
        assert.deepEqual(server.requests.at(-1), { room: 'hall', role: 'camera' });
        assert.deepEqual(livekit.sessions[2].published(), [{ track: camera, source: 'camera' }]);
        assert.equal(camera.stops, 0, 'the camera never stopped');
        assert.equal(camera.readyState, 'live');
    },

    async 'the studio moved on: no token - and the phone back in the hall after leaving'() {
        const { video, livekit, events } = runtime({ answers: { studio: { kind: 'client', status: 409, data: { error: 'studio_not_joined' } } } });
        const camera = new FakeTrack();
        video.apply({ ...HALL_CAMERA, camera, hall: null, publish: false, studio: true });
        await settle();
        assert.deepEqual(events.studio, [{ type: 'refused_studio', code: 'studio_not_joined' }]);
        assert.equal(livekit.sessions.length, 0);

        // "Leave the studio" from on air: the studio is left before the hall is joined
        const second = runtime();
        second.video.apply({ ...HALL_CAMERA, camera, hall: null, publish: false, studio: true });
        await settle();
        const studio = second.livekit.sessions[0];
        second.video.apply({ ...HALL_CAMERA, camera });
        await settle();
        assert.equal(studio.left, true);
        assert.deepEqual(second.events.studio.at(-1), { type: 'left', reason: CLIENT_INITIATED });
        assert.equal(second.livekit.sessions.length, 2);
        assert.equal(camera.stops, 0);
    },

    async 'a microphone refused: on air with the camera only'() {
        const denied = new Error('denied');
        denied.name = 'NotAllowedError';
        const { video, livekit } = runtime({ microphone: denied });
        const camera = new FakeTrack();
        video.apply({ ...HALL_CAMERA, camera, hall: null, publish: false, studio: true });
        await settle();
        assert.deepEqual(livekit.sessions[0].published(), [{ track: camera, source: 'camera' }]);
        assert.equal(video.snapshot().micError, 'NotAllowedError');
        assert.equal(video.snapshot().micOn, false);
    },

    async 'sound held back by the browser is let go by a tap'() {
        const context = { state: 'suspended', resumes: 0, resume() { this.resumes += 1; this.state = 'running'; return Promise.resolve(); } };
        const { video, livekit } = runtime({ audioContext: context });
        video.apply({ ...HALL_CAMERA, camera: new FakeTrack() });
        await settle();
        const [session] = livekit.sessions;
        assert.deepEqual(session.options.roomOptions, { webAudioMix: { audioContext: context } }, 'the voice through the page\'s own context');
        assert.equal(video.audioBlocked(), false, 'nothing to play yet');
        session.setList([moderator()]);
        assert.equal(video.audioBlocked(), true);
        session.room.canPlaybackAudio = false;
        video.unlockAudio();
        assert.equal(context.resumes, 1);
        assert.equal(session.room.startAudioCalls, 1);
        assert.equal(video.audioBlocked(), false);
    },

    async 'closing leaves every room; a link closed while joining leaves the late session'() {
        const { video, livekit } = runtime();
        const camera = new FakeTrack();
        video.apply({ ...HALL_CAMERA, camera });
        await settle();
        await video.close();
        assert.equal(livekit.sessions[0].left, true);
        assert.equal(camera.stops, 0);
        video.apply({ ...HALL_CAMERA, camera, subscribers: 'all' });
        await settle();
        assert.equal(livekit.sessions.length, 1, 'closed for good');

        // RoomLink: a session that arrives after close() is left at once
        let release;
        const pending = new Promise((resolve) => {
            release = resolve;
        });
        const late = { left: false, leave: async () => { late.left = true; }, onConnectionState: () => () => {} };
        const link = new RoomLink({
            requestToken: async () => ({ kind: 'ok', data: { url: 'u', token: 't' } }),
            join: () => pending.then(() => late),
            onSession: () => assert.fail('no session after close'),
            timers: fakeTimers(),
        }).start();
        await settle();
        const closing = link.close();
        release();
        await closing;
        await settle();
        assert.equal(late.left, true);
        assert.equal(link.status, 'closed');
        assert.ok(LEAVE_WAIT_MS > 0);
    },

    // ---- the page's part (compete_video_page.js): the controller's input in, the runtime's plan and the painting out

    async 'the page: an invitation from the heartbeat is offered after the finish, Join takes the phone into the studio'() {
        const page = videoPage();
        const finished = { ...pageInput(), screen: 'finished' };

        assert.equal(page.page.plan(finished), false, 'the round screens hold the camera themselves');
        page.page.tick();
        assert.deepEqual(page.applied.at(-1), {
            hall: 'camera', publish: true, camera: finished.cameraTrack, subscribers: 'moderators', strip: false, hostVideo: false, studio: false, voiceVolume: 1,
        });

        page.page.heartbeat({ studioInviteId: INVITE }, page.now());
        page.page.plan(finished);
        page.page.tick();
        assert.equal(page.shown.get('videoInvite'), true);
        assert.equal(page.fields.get('videoInviteLeft'), 'video_invite_left time=2:00');

        await page.page.join();
        assert.deepEqual(page.answers, [{ inviteId: INVITE, answer: 'join' }]);
        page.page.plan(finished);
        page.page.tick();
        assert.equal(page.applied.at(-1).studio, true, 'joining: the studio wanted');
        assert.equal(page.applied.at(-1).hall, null, 'out of the hall');
        assert.equal(page.shown.get('videoInvite'), false);
        assert.equal(page.shown.get('videoStudio'), true);
        assert.equal(page.data.get('studio'), 'joining');

        page.events.onStudio({ type: 'connected' });
        page.page.plan(finished);
        page.page.tick();
        assert.equal(page.data.get('studio'), 'on_air');
        assert.equal(page.fields.get('videoStudioTitle'), 'video_studio_on_air');

        // "Thank you": said once, back in the hall
        page.events.onStudio({ type: 'left', reason: PARTICIPANT_REMOVED });
        assert.deepEqual(page.notices, [['video_outcome_thanks', 'success']]);
        page.page.plan(finished);
        page.page.tick();
        assert.equal(page.applied.at(-1).hall, 'camera');
        assert.equal(page.applied.at(-1).studio, false);
        assert.equal(page.data.get('studio'), '');
        page.events.onStudio({ type: 'left', reason: CLIENT_INITIATED });
        assert.equal(page.notices.length, 1, 'never twice');
    },

    'the organiser\'s switches reach an open page with its next state: video and "see each other" before, during and after the round - the same camera (stream SP)'() {
        const page = videoPage();
        const camera = new FakeTrack();
        // The page's input on a screen, with the round's switches as the newest state says
        const on = (screen, { video = true, seeEachOther = false, startedAtMs = null, stoppedAtMs = null, now = START - MINUTE } = {}) => {
            const s = state({ video, seeEachOther, startedAtMs, stoppedAtMs });
            page.clock = now;

            return { ...pageInput(), state: s, screen, view: deriveView(s, now), serverNowMs: now, cameraTrack: camera };
        };
        const step = (input) => {
            const holds = page.page.plan(input);
            page.page.tick();

            return { holds, applied: page.applied.at(-1) };
        };

        // Before the round (waiting): in the hall; "see each other" on - everybody may watch, the strip shows; off again
        let now = step(on('waiting'));
        assert.equal(now.applied.hall, 'camera');
        assert.equal(now.applied.camera, camera);
        now = step(on('waiting', { seeEachOther: true }));
        assert.equal(now.applied.subscribers, 'all');
        assert.equal(now.applied.strip, true);
        assert.equal(page.shown.get('videoStrip'), true);
        now = step(on('waiting', { seeEachOther: false }));
        assert.equal(now.applied.subscribers, 'moderators');
        assert.equal(now.applied.strip, false);
        assert.equal(page.shown.get('videoStrip'), false);

        // Video off: out of the hall, the quiet line empty - on again: back with the very same track
        now = step(on('waiting', { video: false }));
        assert.equal(now.applied.hall, null);
        assert.equal(now.applied.publish, false);
        assert.equal(page.fields.get('videoStatus'), '');
        now = step(on('waiting'));
        assert.equal(now.applied.hall, 'camera');
        assert.equal(now.applied.camera, camera);

        // During the solve: off and on again - the strip never shows while puzzling, the screen holds the camera itself
        const running = { startedAtMs: START, now: START + MINUTE };
        now = step(on('running', { ...running, seeEachOther: true }));
        assert.equal(now.applied.hall, 'camera');
        assert.equal(now.applied.strip, false, 'never during the solve');
        assert.equal(now.holds, false);
        now = step(on('running', { ...running, video: false }));
        assert.equal(now.applied.hall, null);
        assert.equal(now.holds, false, 'the round screens keep the camera without video');
        now = step(on('running', running));
        assert.equal(now.applied.hall, 'camera');

        // After the round: video holds the camera for the hall - switched off, the camera is let go as without video
        const after = { startedAtMs: START, stoppedAtMs: START + 2 * MINUTE, now: START + 3 * MINUTE };
        now = step(on('after', after));
        assert.equal(now.holds, true);
        assert.equal(now.applied.publish, true);
        now = step(on('after', { ...after, video: false }));
        assert.equal(now.holds, false);
        assert.equal(now.applied.hall, null);

        assert.equal(camera.stops, 0, 'the page\'s camera track is never stopped by video');
        assert.deepEqual(page.notices, []);
    },

    async 'video switched off while on air in the studio: the studio ends - switched on again, the phone is back in the hall (stream SP)'() {
        const page = videoPage();
        const finished = { ...pageInput(), screen: 'finished' };
        page.page.plan(finished);
        page.page.heartbeat({ studioInviteId: INVITE }, page.now());
        page.page.plan(finished);
        page.page.tick();
        await page.page.join();
        page.events.onStudio({ type: 'connected' });
        page.page.plan(finished);
        page.page.tick();
        assert.equal(page.data.get('studio'), 'on_air');

        const off = state({ video: false, startedAtMs: START });
        page.page.plan({ ...finished, state: off });
        page.page.tick();
        assert.equal(page.applied.at(-1).studio, false, 'the studio is left');
        assert.equal(page.applied.at(-1).hall, null);
        assert.equal(page.page.studio.outcome, 'unavailable');

        page.page.plan(finished);
        page.page.tick();
        assert.equal(page.applied.at(-1).hall, 'camera');
        assert.equal(page.applied.at(-1).studio, false, 'never back on air by itself');
    },

    async 'the runtime: the hall left when video goes off and joined again when it comes back - the camera track the same, never stopped (stream SP)'() {
        const { video, livekit, server } = runtime();
        const camera = new FakeTrack();

        video.apply({ ...HALL_CAMERA, camera });
        await settle();
        video.apply({ ...HALL_CAMERA, camera, hall: null, publish: false });
        await settle();
        assert.equal(livekit.sessions[0].left, true);
        assert.equal(video.snapshot().hall, 'off');

        video.apply({ ...HALL_CAMERA, camera, subscribers: 'all' });
        await settle();
        assert.equal(livekit.sessions.length, 2, 'a new connection');
        assert.deepEqual(server.requests, [{ room: 'hall', role: 'camera' }, { room: 'hall', role: 'camera' }]);
        assert.equal(livekit.sessions[1].options.allowSubscribers, 'all', 'joins with the newest "see each other"');
        assert.deepEqual(livekit.sessions[1].published(), [{ track: camera, source: 'camera' }]);
        assert.equal(camera.stops, 0);
        assert.equal(camera.readyState, 'live');
    },

    'the page: an invitation never shown (the participant was solving) ends without a word'() {
        const page = videoPage();
        const running = { ...pageInput(), screen: 'running' };
        page.page.plan(running);
        page.page.heartbeat({ studioInviteId: INVITE }, page.now());
        page.page.tick();
        assert.equal(page.shown.get('videoInvite'), false, 'never during the solve');
        page.clock += INVITE_ANSWER_MS;
        page.page.plan(running);
        page.page.tick();
        assert.equal(page.page.studio.outcome, 'expired');
        assert.deepEqual(page.notices, []);
    },

    'the page: the after screen holds the camera for the hall; a second screen gets the line and the host'() {
        const page = videoPage();
        const ended = state({ startedAtMs: START, stoppedAtMs: START + MINUTE });
        const at = START + 2 * MINUTE;
        assert.equal(page.page.plan({ ...pageInput(), state: ended, screen: 'after', view: deriveView(ended, at), serverNowMs: at }), true);

        const second = videoPage();
        const running = state({ startedAtMs: START });
        const input = { ...pageInput(), state: running, screen: 'second_screen', view: deriveView(running, START + MINUTE), serverNowMs: START + MINUTE, secondScreen: true, ready: false };
        assert.equal(second.page.plan(input), false);
        second.page.tick();
        assert.equal(second.applied.at(-1).hall, 'screen');
        assert.equal(second.applied.at(-1).hostVideo, true);
        assert.equal(second.fields.get('videoSecondLine'), 'left time=29:00');
        assert.equal(second.shown.get('videoWatchLink'), true);

        // The camera phone was invited: the second screen only says so
        second.page.heartbeat({ studioInviteId: INVITE }, START + MINUTE);
        second.page.tick();
        assert.equal(second.shown.get('videoInviteElsewhere'), true);
        assert.equal(second.shown.get('videoInvite'), false);
    },

    'the page: "speaking" only while the hall is there, "tap to hear the host" never next to "Tap here once"'() {
        const page = videoPage();
        page.snapshot.speaking = ['Hannah Host'];
        page.page.plan(pageInput());
        page.page.tick();
        assert.equal(page.shown.get('videoSpeaking'), true);
        assert.equal(page.fields.get('videoSpeaking'), 'video_host_speaking_name name=Hannah Host');

        // LiveKit resuming a dropped connection: the voice is not there now
        page.snapshot.hall = 'reconnecting';
        page.page.tick();
        assert.equal(page.shown.get('videoSpeaking'), false);
        assert.equal(page.data.get('video'), 'retrying');

        page.snapshot.hall = 'connected';
        page.snapshot.audioBlocked = true;
        page.page.tick({ wakeTapShown: true });
        assert.equal(page.shown.get('videoAudioTap'), false, 'its tap lets the voice go too');
        page.page.tick({ wakeTapShown: false });
        assert.equal(page.shown.get('videoAudioTap'), true);
    },
};

/**
 * compete_video_page.js with a fake runtime and painters that remember the last value
 */
function videoPage() {
    const result = {
        applied: [],
        answers: [],
        notices: [],
        fields: new Map(),
        shown: new Map(),
        data: new Map(),
        events: null,
        // The server's time - pageInput()'s moment
        clock: START + MINUTE,
        now: () => result.clock,
    };
    const snapshot = {
        hall: 'connected', quality: 'good', speaking: [], cameras: 0, hostTiles: 0, studio: 'connected', studioQuality: 'good',
        studioNames: [], micOn: true, micMuted: false, micError: null, audioBlocked: false,
    };
    const params = (values) => Object.entries(values ?? {}).map(([name, value]) => ` ${name}=${value}`).join('');
    result.snapshot = snapshot;

    result.page = new CompeteVideoPage({
        api: {
            videoToken: async () => ({ kind: 'ok', data: {} }),
            studioAnswer: async (answer) => {
                result.answers.push(answer);

                return { kind: 'ok', data: { studio: { inviteId: answer.inviteId, status: answer.answer === 'join' ? 'joined' : 'declined' } } };
            },
        },
        deviceId: 'device',
        storage: memoryStorage(),
        parts: new Map(),
        paint: {
            field: (name, text) => result.fields.set(name, text),
            shown: (name, visible) => result.shown.set(name, visible),
            data: (name, value) => result.data.set(name, value),
            t: (key, values) => `${key}${params(values)}`,
            tc: (key, count) => `${key} ${count}`,
            notify: (text, level) => result.notices.push([text, level]),
        },
        serverNow: () => result.clock,
        requestRender: () => {},
        createVideo: (options) => {
            result.events = options;

            return {
                apply: (plan) => result.applied.push(plan),
                snapshot: () => snapshot,
                toggleMicrophone: () => {},
                unlockAudio: () => {},
                audioBlocked: () => false,
                close: async () => {},
            };
        },
    });

    return result;
}

function pageInput() {
    const s = state({ startedAtMs: START });
    const at = START + MINUTE;

    return {
        state: s,
        screen: 'running',
        view: deriveView(s, at),
        serverNowMs: at,
        ready: true,
        claimPending: false,
        takenOver: false,
        preview: false,
        linkDead: false,
        secondScreen: false,
        cameraStatus: 'live',
        cameraTrack: new FakeTrack(),
        practice: false,
        otherDeviceActive: false,
    };
}

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
