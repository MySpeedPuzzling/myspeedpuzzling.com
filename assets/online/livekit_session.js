/**
 * Live video of the online competitions (docs/features/online-competitions/README.md §5.12, contract phase-1b.md §6):
 * a thin wrapper over livekit-client (Apache-2.0) that the participant page, the moderators' console, the studio and
 * the watch page share. livekit-client is loaded only when a room is joined - `import('livekit-client')` is a chunk of
 * its own, no entry point carries it.
 *
 * joinRoom({url, token, publishTrack?, simulcastLayers?, allowSubscribers?}) connects to the room of a token minted by
 * the server (`compete_api_video_token`, `online_video_token`, `online_watch_token`) and resolves to a session:
 * - setSubscribers('all' | 'moderators' | [identities]): who may watch this device's tracks - LiveKit enforces it
 *   (setTrackSubscriptionPermissions). 'moderators' (the default) follows the room: every `m:…` there, recomputed as
 *   they come and go. A participant passes 'all' only while the round lets participants see each other; the host
 *   passes 'all' so the hall hears them.
 * - onData(topic, fn) → off: data messages of one topic, decoded (decodeData()); fn(data, {from, topic}) - `from` is
 *   null for the server's own messages (the studio invitation: topic `studio`, `{inviteId}` - trust only those).
 * - onParticipants(fn) → off: fn(list) whenever somebody comes, goes or (un)publishes - participantsOf().
 * - onConnectionState(fn) → off: fn({state, reason}) - LiveKit's ConnectionState, the reason of a disconnect.
 * - attach(publication, element) → detach: a track into a <video> / <audio> - also one not subscribed yet (it is
 *   attached once it is). With adaptiveStream the element's size picks the simulcast layer it gets.
 * - publish(track, {source, simulcastLayers}) / unpublish(track) / replaceTrack(old, new): more of the page's own
 *   tracks (the studio's microphone, the host's voice), the same rules as publishTrack.
 * - leave(): unpublishes and disconnects.
 *
 * The camera is the page's, never LiveKit's: publishTrack is the very MediaStreamTrack camera.js opened and the frame
 * grabber reads. It is published as provided by the user and never stopped, disabled for good or re-opened here:
 * - every stop() LiveKit runs on it (leave, a lost connection, the page's `freeze`, a publication timing out) lets go
 *   of the track without stopping the camera (keepTrackRunning());
 * - LiveKit disables a mobile camera after 5 s in the background - this wrapper enables it again on the page's return
 *   (unless it is muted on purpose);
 * - no disconnect on `beforeunload` (the page's own unload guard may keep the page) - only on `pagehide`.
 *
 * Simulcast of the puzzle camera (simulcastPlan()): 180p and 360p below the camera's own resolution, which is sent at
 * the 720p bitrate - the moderators' grid takes the small layer, the spotlight the top one; dynacast pauses the layers
 * nobody watches.
 *
 * Plain module, no DOM at import: livekit-client, the window and the document are injected - the pure helpers and the
 * session against a fake client are pinned by tests/online-livekit-harness.mjs.
 */

export const SOURCE_CAMERA = 'camera';
export const SOURCE_MICROPHONE = 'microphone';
export const SOURCE_SCREEN_SHARE = 'screen_share';

// LiveKit's own 180p and 360p presets (16:9) - the layers below the camera's resolution
export const DEFAULT_SIMULCAST_LAYERS = [
    { width: 320, height: 180, maxBitrate: 160_000, maxFramerate: 20 },
    { width: 640, height: 360, maxBitrate: 450_000, maxFramerate: 20 },
];

// The top layer's encoding: LiveKit's 720p bitrate, whatever the camera's resolution above it
export const TOP_LAYER_ENCODING = { maxBitrate: 1_700_000, maxFramerate: 30 };
export const TOP_LAYER_MIN_SIDE = 720;

const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const PARTICIPANT_IDENTITY = new RegExp(`^p:(${UUID})(:screen)?$`, 'i');
const MODERATOR_IDENTITY = new RegExp(`^m:(${UUID})$`, 'i');
const SPECTATOR_IDENTITY = /^s:([0-9a-z]+)$/i;

/**
 * Who an identity is (src/Value/LiveKitIdentity.php): {kind: 'participant' | 'screen' | 'moderator' | 'spectator' |
 * 'unknown', id} - the participant's, the player's or the spectator's id, null for an unknown identity.
 */
export function parseIdentity(identity) {
    if (typeof identity !== 'string') {
        return { kind: 'unknown', id: null };
    }

    let match = PARTICIPANT_IDENTITY.exec(identity);

    if (match !== null) {
        return { kind: match[2] ? 'screen' : 'participant', id: match[1].toLowerCase() };
    }

    match = MODERATOR_IDENTITY.exec(identity);

    if (match !== null) {
        return { kind: 'moderator', id: match[1].toLowerCase() };
    }

    match = SPECTATOR_IDENTITY.exec(identity);

    if (match !== null) {
        return { kind: 'spectator', id: match[1] };
    }

    return { kind: 'unknown', id: null };
}

export function isModerator(identity) {
    return parseIdentity(identity).kind === 'moderator';
}

/**
 * The arguments of LiveKit's setTrackSubscriptionPermissions() for a subscriber setting:
 * - 'all' → everybody in the room;
 * - 'moderators' → the moderators among `identities` (the room's participants now);
 * - a list of identities → exactly those; anything else → nobody.
 * Sorted and unique, so two settings compare as equal (samePermissions()).
 *
 * @returns {{allParticipantsAllowed: boolean, participantTrackPermissions: Array<{participantIdentity: string, allowAll: boolean}>}}
 */
export function trackPermissions(allow, identities = []) {
    if (allow === 'all') {
        return { allParticipantsAllowed: true, participantTrackPermissions: [] };
    }

    let allowed = [];

    if (allow === 'moderators') {
        allowed = identities.filter(isModerator);
    } else if (Array.isArray(allow)) {
        allowed = allow.filter((identity) => typeof identity === 'string' && identity !== '');
    }

    return {
        allParticipantsAllowed: false,
        participantTrackPermissions: [...new Set(allowed)].sort().map((identity) => ({ participantIdentity: identity, allowAll: true })),
    };
}

export function samePermissions(a, b) {
    return a !== null && b !== null && JSON.stringify(a) === JSON.stringify(b);
}

/**
 * How the puzzle camera is published for the camera's resolution ({width, height} of its settings; unknown = large):
 * the given layers smaller than the camera (LiveKit sends the camera's own resolution on top), the top layer at the
 * 720p bitrate from 720p up (below it LiveKit picks the bitrate itself). `layers` false / [] = no simulcast.
 *
 * @returns {{simulcast: boolean, videoSimulcastLayers: Array<{width: number, height: number, maxBitrate: number, maxFramerate: number}>, videoEncoding: ({maxBitrate: number, maxFramerate: number}|undefined)}}
 */
export function simulcastPlan(settings = {}, layers = DEFAULT_SIMULCAST_LAYERS) {
    const width = Number(settings?.width) > 0 ? Number(settings.width) : null;
    const height = Number(settings?.height) > 0 ? Number(settings.height) : null;
    const shorterSide = width === null || height === null ? Infinity : Math.min(width, height);
    const lower = Array.isArray(layers)
        ? layers.filter((layer) => Math.min(layer.width, layer.height) < shorterSide).slice(0, 2)
        : [];

    return {
        simulcast: lower.length > 0,
        videoSimulcastLayers: lower,
        videoEncoding: shorterSide >= TOP_LAYER_MIN_SIDE ? { ...TOP_LAYER_ENCODING } : undefined,
    };
}

/**
 * A data message's payload: JSON when it is JSON, else the text; null for anything unreadable
 */
export function decodeData(payload, decoder = new TextDecoder()) {
    let text;

    try {
        text = typeof payload === 'string' ? payload : decoder.decode(payload);
    } catch (e) {
        return null;
    }

    try {
        return JSON.parse(text);
    } catch (e) {
        return text;
    }
}

/**
 * Everybody else in the room with what they publish: [{identity, name, kind, id, camera, microphone, screen,
 * participant}] - each a publication (attach() it) or null - sorted by identity.
 */
export function participantsOf(room, sources = { camera: SOURCE_CAMERA, microphone: SOURCE_MICROPHONE, screen: SOURCE_SCREEN_SHARE }) {
    const list = [];

    for (const participant of room?.remoteParticipants?.values?.() ?? []) {
        const publications = [...(participant.trackPublications?.values?.() ?? [])];
        const bySource = (source) => publications.find((publication) => publication.source === source) ?? null;

        list.push({
            identity: participant.identity,
            name: participant.name ?? '',
            ...parseIdentity(participant.identity),
            camera: bySource(sources.camera),
            microphone: bySource(sources.microphone),
            screen: bySource(sources.screen),
            participant,
        });
    }

    return list.sort((a, b) => (a.identity < b.identity ? -1 : a.identity > b.identity ? 1 : 0));
}

/**
 * LiveKit lets go of a published track it stops (leaving, a lost connection, the page's `freeze`), but the device
 * behind it stays on: the page's camera is never LiveKit's to stop. Every other part of LiveKit's stop() runs.
 */
export function keepTrackRunning(localTrack) {
    const release = localTrack.stop;

    localTrack.stop = function stopWithoutTheDevice(...args) {
        const track = this.mediaStreamTrack;

        if (!track || typeof track !== 'object') {
            return release.apply(this, args);
        }

        const hadOwnStop = Object.prototype.hasOwnProperty.call(track, 'stop');
        const ownStop = track.stop;
        track.stop = () => {};

        try {
            return release.apply(this, args);
        } finally {
            if (hadOwnStop) {
                track.stop = ownStop;
            } else {
                delete track.stop;
            }
        }
    };

    return localTrack;
}

/**
 * @param {object} options
 * @param {string} options.url                      the token's `url` (LIVEKIT_PUBLIC_URL)
 * @param {string} options.token                    the token's `token`
 * @param {MediaStreamTrack|null} [options.publishTrack]  the page's camera, published at once
 * @param {Array|false} [options.simulcastLayers]  the camera's lower layers (DEFAULT_SIMULCAST_LAYERS), false = none
 * @param {'all'|'moderators'|string[]} [options.allowSubscribers]  who may watch this device's tracks
 * @param {function(): Promise<object>} [options.loadClient]   livekit-client - its own chunk
 * @param {object} [options.roomOptions]           more of LiveKit's room options
 * @param {object} [options.connectOptions]        LiveKit's connect options
 * @param {Window|null} [options.window]
 * @param {Document|null} [options.document]
 */
export async function joinRoom({
    url,
    token,
    publishTrack = null,
    simulcastLayers = DEFAULT_SIMULCAST_LAYERS,
    allowSubscribers = 'moderators',
    loadClient = () => import(/* webpackChunkName: "livekit-client" */ 'livekit-client'),
    roomOptions = {},
    connectOptions = {},
    window: win = globalThis.window ?? null,
    document: doc = globalThis.document ?? null,
}) {
    const client = await loadClient();
    const { Room, RoomEvent } = client;

    const room = new Room({
        adaptiveStream: true,
        dynacast: true,
        // The page's tracks are the page's: unpublishing never stops them
        stopLocalTrackOnUnpublish: false,
        // beforeunload may not leave the page (its unload guard) - pagehide disconnects below
        disconnectOnPageLeave: false,
        ...roomOptions,
    });

    const listeners = [];
    const published = new Map();
    let subscribers = allowSubscribers;
    let sentPermissions = null;
    let left = false;

    const on = (event, listener) => {
        room.on(event, listener);
        listeners.push([event, listener]);

        return () => {
            room.off(event, listener);
            const index = listeners.findIndex(([e, l]) => e === event && l === listener);

            if (index >= 0) {
                listeners.splice(index, 1);
            }
        };
    };

    const remoteIdentities = () => [...(room.remoteParticipants?.values?.() ?? [])].map((participant) => participant.identity);

    const applySubscribers = () => {
        const permissions = trackPermissions(subscribers, remoteIdentities());

        if (samePermissions(permissions, sentPermissions)) {
            return;
        }

        sentPermissions = permissions;
        room.localParticipant.setTrackSubscriptionPermissions(permissions.allParticipantsAllowed, permissions.participantTrackPermissions);
    };

    // A moderator who comes later may watch at once - 'moderators' follows the room
    on(RoomEvent.ParticipantConnected, applySubscribers);
    on(RoomEvent.ParticipantDisconnected, applySubscribers);

    // LiveKit disables a camera on a phone 5 s into the background and never enables a provided one again
    const onVisibility = () => {
        if (doc?.visibilityState !== 'visible') {
            return;
        }

        for (const { localTrack } of published.values()) {
            const track = localTrack.mediaStreamTrack;

            if (localTrack.source === client.Track?.Source?.Camera && track?.readyState === 'live' && track.enabled === false && localTrack.isMuted !== true) {
                track.enabled = true;
            }
        }
    };

    const onPageHide = () => {
        room.disconnect(false).catch(() => {});
    };

    doc?.addEventListener?.('visibilitychange', onVisibility);
    win?.addEventListener?.('pagehide', onPageHide);

    const detachPage = () => {
        doc?.removeEventListener?.('visibilitychange', onVisibility);
        win?.removeEventListener?.('pagehide', onPageHide);
    };

    const sourceOf = (source) => {
        const sources = client.Track?.Source ?? {};

        return {
            [SOURCE_CAMERA]: sources.Camera,
            [SOURCE_MICROPHONE]: sources.Microphone,
            [SOURCE_SCREEN_SHARE]: sources.ScreenShare,
        }[source] ?? source;
    };

    const publish = async (mediaStreamTrack, { source = mediaStreamTrack.kind === 'audio' ? SOURCE_MICROPHONE : SOURCE_CAMERA, simulcastLayers: layers = simulcastLayers } = {}) => {
        if (published.has(mediaStreamTrack)) {
            return published.get(mediaStreamTrack).publication;
        }

        const video = mediaStreamTrack.kind === 'video';
        const localTrack = keepTrackRunning(video
            ? new client.LocalVideoTrack(mediaStreamTrack, undefined, true)
            : new client.LocalAudioTrack(mediaStreamTrack, undefined, true));
        const options = { source: sourceOf(source) };

        if (video) {
            const plan = simulcastPlan(mediaStreamTrack.getSettings?.() ?? {}, layers === false ? [] : layers);
            options.simulcast = plan.simulcast;
            options.videoSimulcastLayers = plan.videoSimulcastLayers.map((layer) => new client.VideoPreset(layer.width, layer.height, layer.maxBitrate, layer.maxFramerate));

            if (plan.videoEncoding !== undefined) {
                options.videoEncoding = plan.videoEncoding;
            }
        }

        const entry = { localTrack, publication: null };
        published.set(mediaStreamTrack, entry);

        try {
            entry.publication = await room.localParticipant.publishTrack(localTrack, options);
        } catch (e) {
            published.delete(mediaStreamTrack);

            throw e;
        }

        return entry.publication;
    };

    const unpublish = async (mediaStreamTrack) => {
        const entry = published.get(mediaStreamTrack);

        if (entry === undefined) {
            return;
        }

        published.delete(mediaStreamTrack);
        await room.localParticipant.unpublishTrack(entry.localTrack, false);
    };

    try {
        // Nobody may watch before the room is known: the setting is sent on connect, then for the moderators present
        applySubscribers();
        await room.connect(url, token, connectOptions);
        applySubscribers();

        if (publishTrack !== null) {
            await publish(publishTrack, { source: SOURCE_CAMERA, simulcastLayers });
        }
    } catch (e) {
        listeners.splice(0).forEach(([event, listener]) => room.off(event, listener));
        detachPage();
        await room.disconnect(false).catch(() => {});

        throw e;
    }

    return {
        room,
        get identity() {
            return room.localParticipant?.identity ?? null;
        },

        setSubscribers(allow) {
            subscribers = allow;
            applySubscribers();
        },

        onData(topic, fn) {
            return on(RoomEvent.DataReceived, (payload, participant, kind, dataTopic) => {
                if (dataTopic === topic) {
                    fn(decodeData(payload), { from: participant?.identity ?? null, topic: dataTopic });
                }
            });
        },

        onParticipants(fn) {
            const report = () => fn(participantsOf(room, {
                camera: sourceOf(SOURCE_CAMERA),
                microphone: sourceOf(SOURCE_MICROPHONE),
                screen: sourceOf(SOURCE_SCREEN_SHARE),
            }));
            const offs = [
                RoomEvent.ParticipantConnected,
                RoomEvent.ParticipantDisconnected,
                RoomEvent.TrackPublished,
                RoomEvent.TrackUnpublished,
                RoomEvent.TrackSubscribed,
                RoomEvent.TrackUnsubscribed,
                RoomEvent.TrackMuted,
                RoomEvent.TrackUnmuted,
            ].filter((event) => event !== undefined).map((event) => on(event, report));

            report();

            return () => offs.forEach((off) => off());
        },

        onConnectionState(fn) {
            const offState = on(RoomEvent.ConnectionStateChanged, (state) => fn({ state, reason: null }));
            const offDisconnected = on(RoomEvent.Disconnected, (reason) => fn({ state: 'disconnected', reason: reason ?? null }));

            return () => {
                offState();
                offDisconnected();
            };
        },

        attach(publication, element) {
            if (publication?.track) {
                publication.track.attach(element);

                return () => publication.track?.detach(element);
            }

            const off = on(RoomEvent.TrackSubscribed, (track, subscribed) => {
                if (subscribed === publication || (subscribed?.trackSid !== undefined && subscribed.trackSid === publication?.trackSid)) {
                    track.attach(element);
                    off();
                }
            });

            return () => {
                off();
                publication?.track?.detach(element);
            };
        },

        publish,
        unpublish,

        /**
         * The page opened its camera again (camera.js after an `ended`, another lens): the same publication goes on
         * with the new track - neither of them is stopped here
         */
        async replaceTrack(oldTrack, newTrack) {
            const entry = published.get(oldTrack);

            if (entry === undefined) {
                return publish(newTrack);
            }

            await entry.localTrack.replaceTrack(newTrack, { userProvidedTrack: true });
            published.delete(oldTrack);
            published.set(newTrack, entry);

            return entry.publication;
        },

        async leave() {
            if (left) {
                return;
            }

            left = true;
            listeners.splice(0).forEach(([event, listener]) => room.off(event, listener));
            detachPage();

            for (const { localTrack } of published.values()) {
                try {
                    await room.localParticipant.unpublishTrack(localTrack, false);
                } catch (e) {
                    // Disconnected anyway below
                }
            }

            published.clear();
            await room.disconnect(false);
        },
    };
}
