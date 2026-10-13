// Runs assets/online/livekit_session.js - the live video wrapper over livekit-client - against a fake livekit-client
// (no browser, no media server): the pure helpers (identities, subscriber permissions, the simulcast plan, data
// messages) and a session's life (who may watch before and after connecting, the page's camera published as it is and
// never stopped, data by topic, participants, attaching, leaving, the page's background and pagehide). Prints
// {scenario: "ok" | error} as JSON; tests/OnlineLiveKitScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    decodeData,
    DEFAULT_SIMULCAST_LAYERS,
    isModerator,
    joinRoom,
    keepTrackRunning,
    parseIdentity,
    participantsOf,
    samePermissions,
    simulcastPlan,
    SOURCE_CAMERA,
    SOURCE_MICROPHONE,
    TOP_LAYER_ENCODING,
    trackPermissions,
} from '../assets/online/livekit_session.js';

const PARTICIPANT = '018d0050-0000-0000-0000-000000000201';
const OTHER_PARTICIPANT = '018d0050-0000-0000-0000-000000000202';
const PLAYER = '018d0000-0000-0000-0000-000000000003';
const OTHER_PLAYER = '018d0000-0000-0000-0000-000000000004';

async function settle() {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

// ---- the page's devices

class FakeMediaStreamTrack {
    constructor(kind = 'video', settings = { width: 1920, height: 1080 }) {
        this.kind = kind;
        this.settings = settings;
        this.readyState = 'live';
        this.enabled = true;
        this.stops = 0;
    }

    // On the prototype, like a browser's - the wrapper may shadow it only for a moment
    stop() {
        this.stops += 1;
        this.readyState = 'ended';
    }

    getSettings() {
        return { ...this.settings };
    }
}

class FakeEventTarget {
    constructor(extra = {}) {
        this.listeners = {};
        Object.assign(this, extra);
    }

    addEventListener(type, listener) {
        (this.listeners[type] ??= new Set()).add(listener);
    }

    removeEventListener(type, listener) {
        this.listeners[type]?.delete(listener);
    }

    dispatch(type) {
        for (const listener of [...(this.listeners[type] ?? [])]) {
            listener({ type });
        }
    }

    count() {
        return Object.values(this.listeners).reduce((sum, set) => sum + set.size, 0);
    }
}

// ---- a fake livekit-client, with LiveKit's habits that matter here

function fakeClient({ failConnect = false } = {}) {
    const rooms = [];
    const RoomEvent = {
        ParticipantConnected: 'participantConnected',
        ParticipantDisconnected: 'participantDisconnected',
        TrackPublished: 'trackPublished',
        TrackUnpublished: 'trackUnpublished',
        TrackSubscribed: 'trackSubscribed',
        TrackUnsubscribed: 'trackUnsubscribed',
        TrackMuted: 'trackMuted',
        TrackUnmuted: 'trackUnmuted',
        DataReceived: 'dataReceived',
        ConnectionStateChanged: 'connectionStateChanged',
        Disconnected: 'disconnected',
    };
    const Track = { Source: { Camera: 'camera', Microphone: 'microphone', ScreenShare: 'screen_share' } };

    class VideoPreset {
        constructor(width, height, maxBitrate, maxFramerate) {
            this.width = width;
            this.height = height;
            this.encoding = { maxBitrate, maxFramerate };
        }
    }

    // LiveKit's LocalTrack: its stop() lets go of everything, then stops the device
    class LocalTrack {
        constructor(mediaStreamTrack, constraints, userProvidedTrack) {
            this._mediaStreamTrack = mediaStreamTrack;
            this.userProvidedTrack = userProvidedTrack;
            this.source = 'unknown';
            this.isMuted = false;
            this.releases = 0;
        }

        get mediaStreamTrack() {
            return this._mediaStreamTrack;
        }

        stop() {
            this.releases += 1;
            this._mediaStreamTrack.stop();
        }

        async replaceTrack(track, options) {
            this.replaced = { track, options };
            this._mediaStreamTrack = track;

            return this;
        }
    }

    class LocalVideoTrack extends LocalTrack {}
    class LocalAudioTrack extends LocalTrack {}

    class LocalParticipant {
        constructor(room) {
            this.room = room;
            this.identity = 'p:' + PARTICIPANT;
            this.permissionCalls = [];
            this.publications = [];
            this.unpublished = [];
        }

        setTrackSubscriptionPermissions(allParticipantsAllowed, participantTrackPermissions) {
            this.permissionCalls.push({ allParticipantsAllowed, participantTrackPermissions, state: this.room.state });
            this.room.log.push('permissions');
        }

        async publishTrack(track, options) {
            track.source = options.source;
            const publication = { trackSid: 'TR_' + (this.publications.length + 1), track, options, source: options.source };
            this.publications.push(publication);
            this.room.log.push('publish');

            return publication;
        }

        async unpublishTrack(track, stopOnUnpublish) {
            this.unpublished.push({ track, stopOnUnpublish });
            this.publications = this.publications.filter((publication) => publication.track !== track);

            // LiveKit's: the room option decides when nothing is said
            if (stopOnUnpublish ?? this.room.options.stopLocalTrackOnUnpublish ?? true) {
                track.stop();
            }
        }
    }

    class Room {
        constructor(options) {
            this.options = options;
            this.handlers = new Map();
            this.remoteParticipants = new Map();
            this.state = 'disconnected';
            this.log = [];
            this.disconnects = [];
            this.localParticipant = new LocalParticipant(this);
            rooms.push(this);
        }

        on(event, listener) {
            if (!this.handlers.has(event)) {
                this.handlers.set(event, new Set());
            }

            this.handlers.get(event).add(listener);

            return this;
        }

        off(event, listener) {
            this.handlers.get(event)?.delete(listener);

            return this;
        }

        emit(event, ...args) {
            for (const listener of [...(this.handlers.get(event) ?? [])]) {
                listener(...args);
            }
        }

        listenerCount() {
            return [...this.handlers.values()].reduce((sum, set) => sum + set.size, 0);
        }

        async connect(url, token, options) {
            this.log.push('connect');

            if (failConnect) {
                throw new Error('could not establish signal connection');
            }

            this.connected = { url, token, options };
            this.state = 'connected';
        }

        // LiveKit's handleDisconnect(): every local track unpublished, and stopped unless told not to
        async disconnect(stopTracks = true) {
            this.disconnects.push(stopTracks);
            this.log.push('disconnect');

            for (const publication of [...this.localParticipant.publications]) {
                await this.localParticipant.unpublishTrack(publication.track, stopTracks);

                if (stopTracks) {
                    publication.track.stop();
                }
            }

            this.state = 'disconnected';
        }

        // ---- the test's hands

        join(identity, sources = []) {
            const participant = {
                identity,
                name: identity.toUpperCase(),
                trackPublications: new Map(sources.map((source, i) => [`TR_${identity}_${i}`, { trackSid: `TR_${identity}_${i}`, source, track: null }])),
            };
            this.remoteParticipants.set(identity, participant);
            this.emit(RoomEvent.ParticipantConnected, participant);

            return participant;
        }

        leaveRoom(identity) {
            const participant = this.remoteParticipants.get(identity);
            this.remoteParticipants.delete(identity);
            this.emit(RoomEvent.ParticipantDisconnected, participant);
        }
    }

    return { Room, RoomEvent, Track, VideoPreset, LocalVideoTrack, LocalAudioTrack, rooms };
}

async function joined({ client = fakeClient(), camera = new FakeMediaStreamTrack(), present = [], ...options } = {}) {
    const document = new FakeEventTarget({ visibilityState: 'visible' });
    const window = new FakeEventTarget();
    // People already in the room when it is joined
    const originalConnect = client.Room.prototype.connect;
    client.Room.prototype.connect = async function connect(...args) {
        for (const identity of present) {
            this.remoteParticipants.set(identity, { identity, name: identity, trackPublications: new Map() });
        }

        return originalConnect.apply(this, args);
    };

    const session = await joinRoom({
        url: 'wss://livekit.test',
        token: 'token-1',
        publishTrack: camera,
        loadClient: async () => client,
        window,
        document,
        ...options,
    });

    return { client, room: client.rooms[0], session, camera, document, window };
}

const scenarios = {
    async 'identities: participant, second screen, moderator, spectator - anything else is unknown'() {
        assert.deepEqual(parseIdentity('p:' + PARTICIPANT), { kind: 'participant', id: PARTICIPANT });
        assert.deepEqual(parseIdentity('p:' + PARTICIPANT.toUpperCase() + ':screen'), { kind: 'screen', id: PARTICIPANT });
        assert.deepEqual(parseIdentity('m:' + PLAYER), { kind: 'moderator', id: PLAYER });
        assert.deepEqual(parseIdentity('s:9f3a77c0d1e2b4a6'), { kind: 'spectator', id: '9f3a77c0d1e2b4a6' });

        for (const identity of ['p:not-a-uuid', 'm:' + PLAYER + ':screen', 'x:' + PLAYER, '', null, undefined, 42, 'p:' + PARTICIPANT + ':camera']) {
            assert.deepEqual(parseIdentity(identity), { kind: 'unknown', id: null }, String(identity));
        }

        assert.equal(isModerator('m:' + PLAYER), true);
        assert.equal(isModerator('p:' + PARTICIPANT), false);
    },

    async 'subscriber permissions: everybody, the moderators in the room, a list, nobody - sorted and unique'() {
        const room = ['p:' + OTHER_PARTICIPANT, 'm:' + OTHER_PLAYER, 'm:' + PLAYER, 'p:' + PARTICIPANT + ':screen', 's:abc', 'm:' + PLAYER];

        assert.deepEqual(trackPermissions('all', room), { allParticipantsAllowed: true, participantTrackPermissions: [] });
        assert.deepEqual(trackPermissions('moderators', room), {
            allParticipantsAllowed: false,
            participantTrackPermissions: [
                { participantIdentity: 'm:' + PLAYER, allowAll: true },
                { participantIdentity: 'm:' + OTHER_PLAYER, allowAll: true },
            ],
        });
        assert.deepEqual(trackPermissions(['p:' + PARTICIPANT, 'p:' + PARTICIPANT, '', 7], room).participantTrackPermissions, [
            { participantIdentity: 'p:' + PARTICIPANT, allowAll: true },
        ]);
        assert.deepEqual(trackPermissions('moderators', []), { allParticipantsAllowed: false, participantTrackPermissions: [] });
        assert.deepEqual(trackPermissions('nobody', room), { allParticipantsAllowed: false, participantTrackPermissions: [] });
        assert.deepEqual(trackPermissions(undefined, room), { allParticipantsAllowed: false, participantTrackPermissions: [] });

        assert.equal(samePermissions(trackPermissions('moderators', room), trackPermissions('moderators', [...room].reverse())), true);
        assert.equal(samePermissions(trackPermissions('moderators', room), null), false);
    },

    async 'simulcast: 180p and 360p below the camera, the top layer at the 720p bitrate'() {
        const fullHd = simulcastPlan({ width: 1920, height: 1080 });
        assert.equal(fullHd.simulcast, true);
        assert.deepEqual(fullHd.videoSimulcastLayers, DEFAULT_SIMULCAST_LAYERS);
        assert.deepEqual(fullHd.videoEncoding, TOP_LAYER_ENCODING);
        assert.deepEqual(TOP_LAYER_ENCODING, { maxBitrate: 1_700_000, maxFramerate: 30 });
        assert.deepEqual(DEFAULT_SIMULCAST_LAYERS.map((layer) => layer.height), [180, 360]);

        // A phone held upright, a 720p camera: the same
        assert.deepEqual(simulcastPlan({ width: 1080, height: 1920 }), fullHd);
        assert.deepEqual(simulcastPlan({ width: 1280, height: 720 }), fullHd);

        // Below 720p LiveKit picks the top bitrate itself; only the layers smaller than the camera
        const vga = simulcastPlan({ width: 640, height: 480 });
        assert.deepEqual(vga.videoSimulcastLayers.map((layer) => layer.height), [180, 360]);
        assert.equal(vga.videoEncoding, undefined);
        assert.deepEqual(simulcastPlan({ width: 640, height: 360 }).videoSimulcastLayers.map((layer) => layer.height), [180]);
        assert.deepEqual(simulcastPlan({ width: 320, height: 180 }), { simulcast: false, videoSimulcastLayers: [], videoEncoding: undefined });

        // Settings not known yet: a large camera; no layers asked: no simulcast
        assert.deepEqual(simulcastPlan({}), fullHd);
        assert.deepEqual(simulcastPlan(undefined), fullHd);
        assert.equal(simulcastPlan({ width: 1920, height: 1080 }, false).simulcast, false);
        assert.equal(simulcastPlan({ width: 1920, height: 1080 }, []).simulcast, false);
    },

    async 'data messages: JSON, text, bytes'() {
        const bytes = new TextEncoder().encode('{"inviteId":"018d0060-0000-7000-8000-000000000001"}');
        assert.deepEqual(decodeData(bytes), { inviteId: '018d0060-0000-7000-8000-000000000001' });
        assert.equal(decodeData(new TextEncoder().encode('10 minutes left')), '10 minutes left');
        assert.equal(decodeData('"text"'), 'text');
        assert.equal(decodeData({ not: 'bytes' }), null);
    },

    async 'LiveKit letting go of a track never stops the camera - the camera itself still can'() {
        const { LocalVideoTrack } = fakeClient();
        const camera = new FakeMediaStreamTrack();
        const localTrack = keepTrackRunning(new LocalVideoTrack(camera, undefined, true));

        localTrack.stop();
        localTrack.stop();

        assert.equal(localTrack.releases, 2, 'the rest of LiveKit\'s stop runs');
        assert.equal(camera.stops, 0);
        assert.equal(camera.readyState, 'live');
        assert.equal(Object.prototype.hasOwnProperty.call(camera, 'stop'), false, 'the camera\'s own stop is back');

        camera.stop();
        assert.equal(camera.stops, 1);
    },

    async 'joining: nobody may watch before the room is known, the moderators present before the camera is published'() {
        const camera = new FakeMediaStreamTrack();
        const { room, session } = await joined({ camera, present: ['m:' + PLAYER, 'p:' + OTHER_PARTICIPANT] });

        assert.equal(room.options.adaptiveStream, true);
        assert.equal(room.options.dynacast, true);
        assert.equal(room.options.stopLocalTrackOnUnpublish, false);
        assert.equal(room.options.disconnectOnPageLeave, false);
        assert.deepEqual(room.connected, { url: 'wss://livekit.test', token: 'token-1', options: {} });

        assert.deepEqual(room.log, ['permissions', 'connect', 'permissions', 'publish']);
        const [before, after] = room.localParticipant.permissionCalls;
        assert.deepEqual([before.allParticipantsAllowed, before.participantTrackPermissions, before.state], [false, [], 'disconnected']);
        assert.deepEqual(after.participantTrackPermissions, [{ participantIdentity: 'm:' + PLAYER, allowAll: true }]);

        // The very track of the page, as provided by the user, with the simulcast plan
        const [publication] = room.localParticipant.publications;
        assert.equal(publication.track.mediaStreamTrack, camera);
        assert.equal(publication.track.userProvidedTrack, true);
        assert.equal(publication.options.source, 'camera');
        assert.equal(publication.options.simulcast, true);
        assert.deepEqual(publication.options.videoSimulcastLayers.map((preset) => [preset.width, preset.height, preset.encoding.maxBitrate]), [[320, 180, 160_000], [640, 360, 450_000]]);
        assert.deepEqual(publication.options.videoEncoding, TOP_LAYER_ENCODING);
        assert.equal(session.identity, 'p:' + PARTICIPANT);
        assert.equal(camera.stops, 0);
    },

    async 'a moderator coming later may watch, a participant coming changes nothing, everybody when asked'() {
        const { room, session } = await joined();
        const calls = () => room.localParticipant.permissionCalls.length;
        const sentBefore = calls();

        room.join('p:' + OTHER_PARTICIPANT);
        assert.equal(calls(), sentBefore, 'nothing new to send');

        room.join('m:' + OTHER_PLAYER);
        assert.equal(calls(), sentBefore + 1);
        assert.deepEqual(room.localParticipant.permissionCalls.at(-1).participantTrackPermissions, [{ participantIdentity: 'm:' + OTHER_PLAYER, allowAll: true }]);

        room.leaveRoom('m:' + OTHER_PLAYER);
        assert.deepEqual(room.localParticipant.permissionCalls.at(-1).participantTrackPermissions, []);

        session.setSubscribers('all');
        assert.equal(room.localParticipant.permissionCalls.at(-1).allParticipantsAllowed, true);
        room.join('m:' + PLAYER);
        assert.equal(room.localParticipant.permissionCalls.at(-1).allParticipantsAllowed, true, 'everybody stays everybody');

        session.setSubscribers(['p:' + OTHER_PARTICIPANT]);
        assert.deepEqual(room.localParticipant.permissionCalls.at(-1), {
            allParticipantsAllowed: false,
            participantTrackPermissions: [{ participantIdentity: 'p:' + OTHER_PARTICIPANT, allowAll: true }],
            state: 'connected',
        });
    },

    async 'everybody from the start (participants see each other, the host\'s voice)'() {
        const { room } = await joined({ allowSubscribers: 'all' });

        assert.deepEqual(room.localParticipant.permissionCalls.map((call) => call.allParticipantsAllowed), [true]);
        assert.deepEqual(room.log, ['permissions', 'connect', 'publish']);
    },

    async 'data of one topic, decoded, with its sender - off stops it'() {
        const { room, session } = await joined();
        const received = [];
        const off = session.onData('studio', (data, meta) => received.push([data, meta]));
        const payload = new TextEncoder().encode('{"inviteId":"018d0060-0000-7000-8000-000000000001"}');

        room.emit('dataReceived', payload, undefined, 0, 'studio');
        room.emit('dataReceived', payload, { identity: 'm:' + PLAYER }, 0, 'studio');
        room.emit('dataReceived', payload, undefined, 0, 'chat');
        off();
        room.emit('dataReceived', payload, undefined, 0, 'studio');

        assert.deepEqual(received, [
            [{ inviteId: '018d0060-0000-7000-8000-000000000001' }, { from: null, topic: 'studio' }],
            [{ inviteId: '018d0060-0000-7000-8000-000000000001' }, { from: 'm:' + PLAYER, topic: 'studio' }],
        ]);
    },

    async 'participants: a list on every change, by identity, with their publications by source'() {
        const { room, session } = await joined();
        const reports = [];
        const off = session.onParticipants((list) => reports.push(list));

        assert.deepEqual(reports, [[]], 'reported at once');

        room.join('p:' + OTHER_PARTICIPANT, ['camera']);
        room.join('m:' + PLAYER, ['microphone', 'camera', 'screen_share']);
        const [moderator, participant] = reports.at(-1);

        assert.deepEqual([moderator.identity, moderator.kind, moderator.id], ['m:' + PLAYER, 'moderator', PLAYER]);
        assert.equal(moderator.microphone.source, 'microphone');
        assert.equal(moderator.screen.source, 'screen_share');
        assert.deepEqual([participant.kind, participant.id, participant.camera.source, participant.microphone], ['participant', OTHER_PARTICIPANT, 'camera', null]);

        room.emit('trackSubscribed', {}, participant.camera, participant.participant);
        assert.equal(reports.length, 4);

        off();
        room.leaveRoom('m:' + PLAYER);
        assert.equal(reports.length, 4);
        assert.deepEqual(participantsOf(room).map((entry) => entry.identity), ['p:' + OTHER_PARTICIPANT]);
    },

    async 'attach: a subscribed track at once, a coming one once it is subscribed; detach'() {
        const { room, session } = await joined();
        const element = { id: 'tile' };
        const calls = [];
        const track = { attach: (el) => calls.push(['attach', el]), detach: (el) => calls.push(['detach', el]) };

        const detachNow = session.attach({ trackSid: 'TR_1', track }, element);
        assert.deepEqual(calls, [['attach', element]]);
        detachNow();
        assert.deepEqual(calls.at(-1), ['detach', element]);

        const coming = { trackSid: 'TR_2', track: null };
        const detachLater = session.attach(coming, element);
        room.emit('trackSubscribed', { attach: () => calls.push(['wrong']) }, { trackSid: 'TR_3' }, {});
        coming.track = track;
        room.emit('trackSubscribed', track, { trackSid: 'TR_2' }, {});
        room.emit('trackSubscribed', track, { trackSid: 'TR_2' }, {});
        assert.deepEqual(calls.slice(2), [['attach', element]], 'attached once, to the right track');
        detachLater();
        assert.deepEqual(calls.at(-1), ['detach', element]);
    },

    async 'leaving unpublishes and disconnects - the camera stays on, even through LiveKit\'s own stop'() {
        const camera = new FakeMediaStreamTrack();
        const { room, session, document, window } = await joined({ camera });
        const [publication] = room.localParticipant.publications;

        // LiveKit stopping it by itself (a lost connection, the page's freeze, a publication timing out)
        publication.track.stop();
        assert.equal(camera.stops, 0);
        assert.equal(publication.track.releases, 1);

        await session.leave();
        await session.leave();

        assert.deepEqual(room.localParticipant.unpublished.map((entry) => entry.stopOnUnpublish), [false]);
        assert.deepEqual(room.disconnects, [false]);
        assert.equal(camera.stops, 0);
        assert.equal(camera.readyState, 'live');
        assert.equal(room.listenerCount(), 0);
        assert.equal(document.count() + window.count(), 0);
    },

    async 'the camera LiveKit disables in the background is enabled again on the return - unless muted'() {
        const camera = new FakeMediaStreamTrack();
        const { room, document } = await joined({ camera });
        const [publication] = room.localParticipant.publications;

        // LiveKit 5 s into the background on a phone
        document.visibilityState = 'hidden';
        camera.enabled = false;
        document.dispatch('visibilitychange');
        assert.equal(camera.enabled, false, 'not while hidden');

        document.visibilityState = 'visible';
        document.dispatch('visibilitychange');
        assert.equal(camera.enabled, true);

        publication.track.isMuted = true;
        camera.enabled = false;
        document.dispatch('visibilitychange');
        assert.equal(camera.enabled, false, 'muted on purpose');
    },

    async 'pagehide disconnects without stopping the camera; beforeunload is left to the page'() {
        const camera = new FakeMediaStreamTrack();
        const { room, window } = await joined({ camera });

        assert.equal(window.listeners.beforeunload, undefined);
        window.dispatch('pagehide');
        await settle();

        assert.deepEqual(room.disconnects, [false]);
        assert.equal(camera.stops, 0);
    },

    async 'a connection that fails cleans up and rejects - the camera untouched'() {
        const client = fakeClient({ failConnect: true });
        const camera = new FakeMediaStreamTrack();
        const document = new FakeEventTarget({ visibilityState: 'visible' });
        const window = new FakeEventTarget();

        await assert.rejects(joinRoom({ url: 'wss://livekit.test', token: 't', publishTrack: camera, loadClient: async () => client, window, document }), /signal connection/);

        const [room] = client.rooms;
        assert.equal(room.listenerCount(), 0);
        assert.equal(document.count() + window.count(), 0);
        assert.deepEqual(room.disconnects, [false]);
        assert.deepEqual(room.localParticipant.publications, []);
        assert.equal(camera.stops, 0);
    },

    async 'the camera opened again goes on in the same publication, nothing stopped'() {
        const camera = new FakeMediaStreamTrack();
        const { room, session } = await joined({ camera });
        const [publication] = room.localParticipant.publications;
        const reopened = new FakeMediaStreamTrack();

        assert.equal(await session.replaceTrack(camera, reopened), publication);
        assert.deepEqual(publication.track.replaced, { track: reopened, options: { userProvidedTrack: true } });

        // LiveKit letting go of the new one does not stop it either
        publication.track.stop();
        assert.equal(reopened.stops + camera.stops, 0);

        await session.leave();
        assert.equal(reopened.stops + camera.stops, 0);
    },

    async 'more tracks: the microphone published once, unpublished without stopping'() {
        const { room, session } = await joined({ publishTrack: null });
        const microphone = new FakeMediaStreamTrack('audio', {});

        const publication = await session.publish(microphone);
        assert.equal(await session.publish(microphone), publication, 'published once');
        assert.equal(publication.options.source, SOURCE_MICROPHONE);
        assert.equal(publication.options.simulcast, undefined, 'no video options for a microphone');

        const screen = new FakeMediaStreamTrack('video', { width: 1920, height: 1080 });
        assert.equal((await session.publish(screen, { source: 'screen_share', simulcastLayers: false })).options.simulcast, false);
        assert.equal((await session.publish(new FakeMediaStreamTrack(), { source: SOURCE_CAMERA })).options.source, 'camera');

        await session.unpublish(microphone);
        await session.unpublish(microphone);
        assert.deepEqual(room.localParticipant.unpublished.map((entry) => [entry.track.mediaStreamTrack, entry.stopOnUnpublish]), [[microphone, false]]);
        assert.equal(microphone.stops, 0);
    },

    async 'connection states and the reason of a disconnect'() {
        const { room, session } = await joined();
        const states = [];
        const off = session.onConnectionState((state) => states.push(state));

        room.emit('connectionStateChanged', 'reconnecting');
        room.emit('disconnected', 'DUPLICATE_IDENTITY');
        off();
        room.emit('connectionStateChanged', 'connected');

        assert.deepEqual(states, [{ state: 'reconnecting', reason: null }, { state: 'disconnected', reason: 'DUPLICATE_IDENTITY' }]);
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
