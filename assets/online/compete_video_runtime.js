/**
 * The participant page's live video at work (docs/features/online-competitions/README.md §5.12, contract phase-1b.md
 * stream VP) - what assets/online/compete_video.js decides, done with LiveKit through livekit_session.js. Driven by
 * compete_video_page.js, in its chunk - loaded only when the round's video is on (livekit-client is another chunk
 * again, loaded when a room is joined).
 *
 * - The hall (`compete_api_video_token`, room `hall`): the camera device publishes its puzzle camera - the very
 *   MediaStreamTrack camera.js opened and the frame grabber reads; publishing never stops, reopens or reconfigures it
 *   (another lens or a reopened camera is the same publication with the new track) - and lets the moderators watch it,
 *   or everybody when the round lets participants see each other. A second screen joins as `p:…:screen`, watching only.
 *   Nothing is received by itself (`autoSubscribe: false`): the moderators' microphones always (the host's voice), the
 *   other participants' cameras at the 180p layer while the strip shows them, the moderators' cameras on a second
 *   screen.
 * - The studio (room `studio`, a joined invitation - its token lives two minutes, so it is asked for right before the
 *   join): the hall is left first, then the studio is joined with the same camera and a microphone asked for at that
 *   moment; everything there is received. "Thank you" closes the connection (PARTICIPANT_REMOVED) - the page goes back
 *   to the hall.
 * - A connection that fails or drops is tried again with a growing wait (compete_video.js retryDelayMs()), never by a
 *   reload; a refusal of the server (video off, another device holds the link, the studio moved on) is not tried again
 *   until the page asks for something else. Nothing here can stop the page competing: every failure ends here.
 * - The host's voice plays through the page's own AudioContext (LiveKit's `webAudioMix`): unlocked by the "I'm ready"
 *   tap and the "Tap here once" banner like the beeps, and lowered around the beeps (compete_video.js voiceVolume()).
 *
 * No DOM at import: livekit_session.js joinRoom(), the token requests, the microphone, the elements, the timers and the
 * clock are injected - pinned by tests/online-compete-video-harness.mjs.
 */

import { joinRoom, SOURCE_CAMERA, SOURCE_MICROPHONE } from './livekit_session.js';
import {
    CLIENT_INITIATED,
    DUPLICATE_IDENTITY,
    hallPeople,
    PARTICIPANT_REMOVED,
    retryDelayMs,
    STABLE_MS,
    STRIP_LAYER,
} from './compete_video.js';

export const MICROPHONE_CONSTRAINTS = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };

// A camera publication that failed is tried again after this long (not on every frame)
export const PUBLISH_RETRY_MS = 5000;
// Leaving a room is waited for this long at most before the next one is joined
export const LEAVE_WAIT_MS = 3000;

// LiveKit's events this module listens to on the room itself (RoomEvent values)
const CONNECTION_QUALITY_CHANGED = 'connectionQualityChanged';
const AUDIO_PLAYBACK_CHANGED = 'audioPlaybackChanged';

/**
 * A device's connection to one room of the round: the token of `compete_api_video_token`, joinRoom(), tried again
 * with a growing wait when it fails or drops. Never again by itself after a refusal of the server (`client` /
 * `forbidden` answers: video off, not the active device, the studio moved on, a dead link), when the same identity
 * connected elsewhere (DUPLICATE_IDENTITY - another device of this participant took the link) or when the server
 * removed it (PARTICIPANT_REMOVED - "Thank you" in the studio).
 *
 * Statuses: connecting | connected | reconnecting (LiveKit resuming by itself) | retrying (waiting for the next try) |
 * refused | duplicate | ended | closed.
 */
export class RoomLink {
    /**
     * @param {object} options
     * @param {function(): Promise<object>} options.requestToken    compete_api.js videoToken() for this room and role
     * @param {function(object): Promise<object>} options.join      livekit_session.js joinRoom
     * @param {function(): object} options.joinOptions               joinRoom()'s options besides url and token
     * @param {function(object): void} options.onSession
     * @param {function(object): void} [options.onLost]              the session is gone (before anything else)
     * @param {function(string): void} [options.onStatus]
     * @param {function(string): void} [options.onRefused]           the server's refusal code
     * @param {function(number): void} [options.onEnded]             removed by the server (the DisconnectReason)
     * @param {string[]} [options.retryOn]                           refusal codes tried again anyway (a claim on its way)
     * @param {object} [options.timers]                              {set, clear}
     * @param {function(): number} [options.now]
     * @param {function(): number} [options.random]
     */
    constructor({
        requestToken,
        join,
        joinOptions = () => ({}),
        onSession,
        onLost = () => {},
        onStatus = () => {},
        onRefused = () => {},
        onEnded = () => {},
        retryOn = [],
        timers = { set: (task, ms) => setTimeout(task, ms), clear: (timer) => clearTimeout(timer) },
        now = () => Date.now(),
        random = Math.random,
    }) {
        this.requestToken = requestToken;
        this.join = join;
        this.joinOptions = joinOptions;
        this.onSession = onSession;
        this.onLost = onLost;
        this.onStatus = onStatus;
        this.onRefused = onRefused;
        this.onEnded = onEnded;
        this.retryOn = retryOn;
        this.timers = timers;
        this.now = now;
        this.random = random;

        this.status = 'connecting';
        this.session = null;
        this.connecting = false;
        this.closed = false;
        this.failures = 0;
        this.timer = null;
        this.connectedAt = null;
        this.offs = [];
        this.deciding = false;
        this.disconnectReason = null;
    }

    setStatus(status) {
        if (this.status === status) {
            return;
        }

        this.status = status;

        try {
            this.onStatus(status);
        } catch (e) {
            // The page's listener must never break the link
        }
    }

    start() {
        this.connect();

        return this;
    }

    async connect() {
        if (this.closed || this.connecting || this.session !== null) {
            return;
        }

        this.connecting = true;
        this.timers.clear(this.timer);
        this.timer = null;
        this.setStatus(this.failures === 0 ? 'connecting' : 'retrying');

        let answer;

        try {
            answer = await this.requestToken();
        } catch (e) {
            answer = { kind: 'offline' };
        }

        if (this.closed) {
            this.connecting = false;

            return;
        }

        if (answer?.kind !== 'ok' || typeof answer.data?.token !== 'string' || typeof answer.data?.url !== 'string') {
            this.connecting = false;

            const code = typeof answer?.data?.error === 'string' ? answer.data.error : answer?.kind;

            if ((answer?.kind === 'client' || answer?.kind === 'forbidden') && !this.retryOn.includes(code)) {
                this.setStatus('refused');
                this.onRefused(code);
            } else {
                this.retry();
            }

            return;
        }

        let session;

        try {
            session = await this.join({ ...this.joinOptions(), url: answer.data.url, token: answer.data.token });
        } catch (e) {
            this.connecting = false;

            if (!this.closed) {
                this.retry();
            }

            return;
        }

        this.connecting = false;

        if (this.closed) {
            session.leave().catch(() => {});

            return;
        }

        this.session = session;
        this.connectedAt = this.now();
        this.offs.push(session.onConnectionState(({ state, reason }) => this.connectionChanged(state, reason)));
        this.setStatus('connected');

        try {
            this.onSession(session);
        } catch (e) {
            // The page's own part failed - the connection stays
        }
    }

    retry() {
        this.failures += 1;
        this.setStatus('retrying');
        this.timers.clear(this.timer);
        this.timer = this.timers.set(() => {
            this.timer = null;
            this.connect();
        }, retryDelayMs(this.failures, this.random));
    }

    connectionChanged(state, reason) {
        if (this.closed || this.session === null) {
            return;
        }

        if (state === 'reconnecting' || state === 'signalReconnecting') {
            this.setStatus('reconnecting');

            return;
        }

        if (state === 'connected') {
            this.setStatus('connected');

            return;
        }

        if (state !== 'disconnected') {
            return;
        }

        if (reason !== null && reason !== undefined) {
            this.disconnectReason = reason;
        }

        if (this.deciding) {
            return;
        }

        // LiveKit reports the state and the reason one right after the other - decided once both are in
        this.deciding = true;
        Promise.resolve().then(() => {
            const why = this.disconnectReason;
            this.deciding = false;
            this.disconnectReason = null;

            if (this.closed || this.session === null) {
                return;
            }

            const heldMs = this.connectedAt === null ? 0 : this.now() - this.connectedAt;
            this.drop();

            if (why === DUPLICATE_IDENTITY) {
                this.setStatus('duplicate');
            } else if (why === PARTICIPANT_REMOVED) {
                this.setStatus('ended');
                this.onEnded(why);
            } else if (why !== CLIENT_INITIATED) {
                if (heldMs >= STABLE_MS) {
                    this.failures = 0;
                }

                this.retry();
            }
        });
    }

    drop() {
        const session = this.session;

        if (session === null) {
            return;
        }

        this.offs.splice(0).forEach((off) => off());
        this.session = null;

        try {
            this.onLost(session);
        } catch (e) {
            // Let go anyway
        }

        session.leave().catch(() => {});
    }

    /**
     * Leaves the room for good - resolves once LiveKit has disconnected.
     */
    async close() {
        if (this.closed) {
            return;
        }

        this.closed = true;
        this.timers.clear(this.timer);
        this.timer = null;
        const session = this.session;
        this.offs.splice(0).forEach((off) => off());
        this.session = null;

        if (session !== null) {
            try {
                this.onLost(session);
            } catch (e) {
                // Let go anyway
            }

            // A dead network can hold LiveKit's goodbye - the next room does not wait for it longer than this
            await Promise.race([
                session.leave().catch(() => {}),
                new Promise((resolve) => {
                    this.timers.set(resolve, LEAVE_WAIT_MS);
                }),
            ]);
        }

        this.setStatus('closed');
    }
}

function isLive(track) {
    return track !== null && track !== undefined && track.readyState !== 'ended';
}

/**
 * The page's video: one call of apply() with the plan of the moment (compete_controller.js asks compete_video.js),
 * and whatever it takes to get there happens here, in order, at most one LiveKit change at a time per room.
 */
export class ParticipantVideo {
    /**
     * @param {object} options
     * @param {function(string, string): Promise<object>} options.requestToken  (room, role) → compete_api.js answer
     * @param {function(object): Promise<object>} [options.join]                 livekit_session.js joinRoom
     * @param {AudioContext|null} [options.audioContext]   the page's voice context (unlocked by its taps)
     * @param {function(): Promise<MediaStream>} [options.getMicrophone]
     * @param {{audio?: Element, strip?: Element, host?: Element, studio?: Element}} [options.containers]
     * @param {function(string): Element} [options.createElement]
     * @param {object} [options.timers]
     * @param {function(): number} [options.now]
     * @param {function(): number} [options.random]
     * @param {function(): void} [options.onChange]         something the page shows changed
     * @param {function(string): void} [options.onInvite]   a studio invitation from the server (topic `studio`)
     * @param {function(object): void} [options.onStudio]   {type: connected | refused_studio {code} | left {reason}}
     */
    constructor({
        requestToken,
        join = joinRoom,
        audioContext = null,
        getMicrophone = () => globalThis.navigator.mediaDevices.getUserMedia({ audio: MICROPHONE_CONSTRAINTS, video: false }),
        containers = {},
        createElement = (tag) => globalThis.document.createElement(tag),
        timers = { set: (task, ms) => setTimeout(task, ms), clear: (timer) => clearTimeout(timer) },
        now = () => Date.now(),
        random = Math.random,
        onChange = () => {},
        onInvite = () => {},
        onStudio = () => {},
    }) {
        this.requestToken = requestToken;
        this.join = join;
        this.audioContext = audioContext;
        this.getMicrophone = getMicrophone;
        this.containers = containers;
        this.createElement = createElement;
        this.timers = timers;
        this.now = now;
        this.random = random;
        this.onChange = onChange;
        this.onInvite = onInvite;
        this.onStudio = onStudio;

        this.closed = false;
        this.plan = {
            hall: null,
            publish: false,
            camera: null,
            subscribers: 'moderators',
            strip: false,
            hostVideo: false,
            studio: false,
            voiceVolume: 1,
        };
        this.hall = this.emptyRoom(null);
        // The last room being left: the next one is joined after it (one video encoder per phone)
        this.leaving = Promise.resolve();
        this.studio = null;
    }

    emptyRoom(role) {
        return {
            role,
            link: null,
            session: null,
            status: 'off',
            quality: 'unknown',
            published: null,
            busy: false,
            dirty: false,
            publishFailedAt: null,
            subscribers: null,
            volume: null,
            people: { moderators: [], cameras: [], speaking: [] },
            tiles: new Map(),
            audio: new Map(),
            offs: [],
        };
    }

    changed() {
        if (this.closed) {
            return;
        }

        try {
            this.onChange();
        } catch (e) {
            // The page's listener must never break the video
        }
    }

    roomOptions() {
        return this.audioContext ? { webAudioMix: { audioContext: this.audioContext } } : {};
    }

    /**
     * The plan of the moment:
     * {hall: 'camera'|'screen'|null, publish, camera: MediaStreamTrack|null, subscribers, strip, hostVideo, studio,
     * voiceVolume} - cheap when nothing changed (the page calls it on every frame it draws).
     */
    apply(plan) {
        if (this.closed) {
            return;
        }

        const previous = this.plan;
        this.plan = { ...previous, ...plan };
        const next = this.plan;

        if (
            previous.hall === next.hall
            && previous.publish === next.publish
            && previous.camera === next.camera
            && previous.subscribers === next.subscribers
            && previous.strip === next.strip
            && previous.hostVideo === next.hostVideo
            && previous.studio === next.studio
            && previous.voiceVolume === next.voiceVolume
            && !this.publishRetryDue()
        ) {
            return;
        }

        if (next.studio && this.studio === null) {
            this.startStudio();
        } else if (!next.studio && this.studio !== null) {
            this.stopStudio();
        }

        const role = next.studio ? null : next.hall;

        if (role !== this.hall.role) {
            this.closeHall();

            if (role !== null) {
                this.openHall(role);
            }
        }

        this.syncHall();
        this.syncStudio();
    }

    publishRetryDue() {
        const failedAt = this.hall.publishFailedAt ?? this.studio?.room.publishFailedAt ?? null;

        return failedAt !== null && this.now() - failedAt >= PUBLISH_RETRY_MS;
    }

    // ---- the hall

    openHall(role) {
        const hall = this.emptyRoom(role);
        this.hall = hall;
        hall.status = 'connecting';
        hall.link = new RoomLink({
            requestToken: () => this.requestToken('hall', role),
            // Right after "I'm ready" the server may not have stored the claim yet - asked again shortly
            retryOn: ['not_active_device'],
            join: this.join,
            joinOptions: () => ({
                allowSubscribers: this.plan.subscribers,
                connectOptions: { autoSubscribe: false },
                roomOptions: this.roomOptions(),
            }),
            onSession: (session) => this.hallJoined(hall, session),
            onLost: () => this.hallLost(hall),
            onStatus: (status) => {
                hall.status = status;
                this.changed();
            },
            timers: this.timers,
            now: this.now,
            random: this.random,
        });
        this.leaving.then(() => {
            if (this.hall === hall && !this.closed) {
                hall.link.start();
            }
        });
    }

    closeHall() {
        const hall = this.hall;

        if (hall.role === null) {
            return;
        }

        this.hall = this.emptyRoom(null);
        this.leaveRoom(hall.link);
        this.changed();
    }

    leaveRoom(link) {
        if (link === null) {
            return this.leaving;
        }

        const closing = link.close();
        this.leaving = Promise.all([this.leaving, closing]).then(() => undefined);

        return closing;
    }

    hallJoined(hall, session) {
        if (this.hall !== hall) {
            return;
        }

        hall.session = session;
        hall.subscribers = this.plan.subscribers;
        hall.offs.push(session.onParticipants((list) => {
            hall.people = hallPeople(list);
            this.syncHallSubscriptions(hall);
            this.changed();
        }));
        hall.offs.push(session.onData('studio', (data, { from }) => {
            // Only the server's own messages (LiveKitApi::sendData()) - nobody in the room may invite
            if (from === null && typeof data?.inviteId === 'string') {
                this.onInvite(data.inviteId);
            }
        }));
        this.watchRoom(hall, session);
        this.syncHall();
        this.changed();
    }

    hallLost(hall) {
        this.letGo(hall);
        this.changed();
    }

    /**
     * Connection quality and the audio's playback - read off the room itself
     */
    watchRoom(room, session) {
        const lkRoom = session.room;

        if (!lkRoom || typeof lkRoom.on !== 'function') {
            return;
        }

        room.quality = lkRoom.localParticipant?.connectionQuality ?? 'unknown';
        const onQuality = (quality, participant) => {
            if (participant === lkRoom.localParticipant || participant?.identity === lkRoom.localParticipant?.identity) {
                room.quality = quality;
                this.changed();
            }
        };
        const onPlayback = () => this.changed();
        lkRoom.on(CONNECTION_QUALITY_CHANGED, onQuality);
        lkRoom.on(AUDIO_PLAYBACK_CHANGED, onPlayback);
        room.offs.push(() => {
            lkRoom.off?.(CONNECTION_QUALITY_CHANGED, onQuality);
            lkRoom.off?.(AUDIO_PLAYBACK_CHANGED, onPlayback);
        });
    }

    /**
     * The session went: its tiles, sounds and publications with it (the tracks stay the page's)
     */
    letGo(room) {
        room.offs.splice(0).forEach((off) => off());

        for (const tile of room.tiles.values()) {
            this.removeTile(tile);
        }

        for (const tile of room.audio.values()) {
            this.removeTile(tile);
        }

        room.tiles.clear();
        room.audio.clear();
        room.session = null;
        room.published = null;
        room.busy = false;
        room.dirty = false;
        room.subscribers = null;
        room.volume = null;
        room.people = { moderators: [], cameras: [], speaking: [] };
    }

    syncHall() {
        const hall = this.hall;
        const session = hall.session;

        if (session === null) {
            return;
        }

        if (hall.subscribers !== this.plan.subscribers) {
            hall.subscribers = this.plan.subscribers;
            session.setSubscribers(this.plan.subscribers);
        }

        const camera = hall.role === 'camera' && this.plan.publish && isLive(this.plan.camera) ? this.plan.camera : null;
        this.syncPublication(hall, camera);
        this.syncHallSubscriptions(hall);
        this.syncVolume(hall);
    }

    /**
     * Brings the room's camera publication to `camera` (null: none): publish, the same publication with another
     * track, or unpublish - one change at a time, never stopping a track.
     */
    async syncPublication(room, camera) {
        const session = room.session;

        if (session === null) {
            return;
        }

        if (room.busy) {
            room.dirty = true;

            return;
        }

        if (camera === room.published) {
            room.publishFailedAt = null;

            return;
        }

        if (room.publishFailedAt !== null && this.now() - room.publishFailedAt < PUBLISH_RETRY_MS) {
            return;
        }

        room.busy = true;
        const before = room.published;

        try {
            if (camera === null) {
                await session.unpublish(before);
            } else if (before === null) {
                await session.publish(camera, { source: SOURCE_CAMERA });
            } else {
                await session.replaceTrack(before, camera);
            }

            if (room.session === session) {
                room.published = camera;
                room.publishFailedAt = null;
            }
        } catch (e) {
            // LiveKit refused or the connection went meanwhile: again in a while (or with the next session)
            if (room.session === session) {
                room.publishFailedAt = this.now();
            }
        } finally {
            room.busy = false;
        }

        this.changed();

        // The plan may have moved on meanwhile (another lens, the end of the after window): brought there now
        if (room.dirty && room.session === session) {
            room.dirty = false;

            if (room === this.hall) {
                this.syncHall();
            } else if (room === this.studio?.room) {
                this.syncStudio();
            }
        }
    }

    /**
     * What the hall receives: every moderator's microphone (played), their cameras on a second screen, the other
     * participants' cameras at the 180p layer while the strip shows them - nothing else.
     */
    syncHallSubscriptions(hall) {
        const session = hall.session;

        if (session === null) {
            return;
        }

        const wanted = new Set();

        for (const moderator of hall.people.moderators) {
            if (moderator.microphone) {
                subscribe(moderator.microphone);
                const key = `${moderator.identity}:microphone`;
                wanted.add(key);
                this.ensureAudio(hall, key, moderator.microphone, session);
            }

            if (moderator.camera) {
                if (this.plan.hostVideo) {
                    subscribe(moderator.camera);
                    const key = `${moderator.identity}:camera`;
                    wanted.add(key);
                    this.ensureTile(hall, key, moderator, moderator.camera, session, this.containers.host);
                } else {
                    unsubscribe(moderator.camera);
                }
            }
        }

        for (const person of hall.people.cameras) {
            if (this.plan.strip) {
                subscribe(person.camera);

                try {
                    person.camera.setVideoDimensions?.(STRIP_LAYER);
                } catch (e) {
                    // Not subscribed yet - adaptive stream sizes it by its tile
                }

                const key = `${person.identity}:camera`;
                wanted.add(key);
                this.ensureTile(hall, key, person, person.camera, session, this.containers.strip);
            } else {
                unsubscribe(person.camera);
            }
        }

        this.dropUnwanted(hall, wanted);
        this.syncVolume(hall, true);
    }

    syncVolume(hall, force = false) {
        const volume = this.plan.voiceVolume;

        if (!force && hall.volume === volume) {
            return;
        }

        hall.volume = volume;

        for (const moderator of hall.people.moderators) {
            try {
                moderator.microphone?.track?.setVolume?.(volume);
            } catch (e) {
                // Not playing yet - set again when it does
            }
        }
    }

    // ---- the studio

    startStudio() {
        const studio = {
            room: this.emptyRoom('studio'),
            mic: null,
            micPublished: null,
            micMuted: false,
            micError: null,
            asking: false,
            stopped: false,
        };
        this.studio = studio;
        studio.room.status = 'connecting';
        this.changed();

        // One video encoder per phone: the hall is left before the studio is joined
        this.leaving.then(() => {
            if (this.studio !== studio || studio.stopped || this.closed) {
                return;
            }

            studio.room.link = new RoomLink({
                // Its token lives two minutes: asked for right before each try, never ahead
                requestToken: () => this.requestToken('studio', 'camera'),
                join: this.join,
                joinOptions: () => ({ allowSubscribers: 'all', roomOptions: this.roomOptions() }),
                onSession: (session) => this.studioJoined(studio, session),
                onLost: () => this.studioLost(studio),
                onStatus: (status) => {
                    studio.room.status = status;
                    this.changed();
                },
                onRefused: (code) => this.studioEvent({ type: 'refused_studio', code }),
                onEnded: (reason) => this.studioEvent({ type: 'left', reason }),
                timers: this.timers,
                now: this.now,
                random: this.random,
            }).start();
        });
    }

    studioEvent(event) {
        try {
            this.onStudio(event);
        } catch (e) {
            // The page's listener must never break the video
        }
    }

    async studioJoined(studio, session) {
        if (this.studio !== studio) {
            return;
        }

        studio.room.session = session;
        studio.room.offs.push(session.onParticipants((list) => {
            studio.room.people = { ...hallPeople(list), everybody: Array.isArray(list) ? list : [] };
            this.syncStudioTiles(studio);
            this.changed();
        }));
        this.watchRoom(studio.room, session);
        this.studioEvent({ type: 'connected' });
        this.syncStudio();

        // The microphone - asked for now, once; the camera is the one already open, never asked for again
        if (studio.mic === null && !studio.asking && studio.micError === null) {
            studio.asking = true;

            try {
                const stream = await this.getMicrophone();
                const track = stream?.getAudioTracks?.()[0] ?? null;

                if (this.studio !== studio || studio.stopped) {
                    track?.stop?.();
                } else {
                    studio.mic = track;
                }
            } catch (e) {
                studio.micError = typeof e?.name === 'string' ? e.name : 'Error';
            }

            studio.asking = false;
        }

        this.syncStudio();
        this.changed();
    }

    studioLost(studio) {
        studio.micPublished = null;
        this.letGo(studio.room);
        this.changed();
    }

    syncStudio() {
        const studio = this.studio;
        const session = studio?.room.session ?? null;

        if (session === null) {
            return;
        }

        this.syncPublication(studio.room, isLive(this.plan.camera) ? this.plan.camera : null);

        if (studio.mic !== null && studio.micPublished === null && !studio.micPublishing) {
            studio.micPublishing = true;
            session.publish(studio.mic, { source: SOURCE_MICROPHONE }).then((publication) => {
                if (studio.room.session === session) {
                    studio.micPublished = publication;

                    if (studio.micMuted) {
                        publication?.mute?.();
                    }
                }
            }).catch(() => {
                studio.micError = studio.micError ?? 'PublishError';
            }).finally(() => {
                studio.micPublishing = false;
                this.changed();
            });
        }
    }

    /**
     * Everybody else in the studio is received (autoSubscribe): their cameras on the stage, their voices played
     */
    syncStudioTiles(studio) {
        const session = studio.room.session;

        if (session === null) {
            return;
        }

        const wanted = new Set();

        for (const entry of studio.room.people.everybody ?? []) {
            if (entry.camera) {
                const key = `${entry.identity}:camera`;
                wanted.add(key);
                this.ensureTile(studio.room, key, entry, entry.camera, session, this.containers.studio);
            }

            if (entry.microphone) {
                const key = `${entry.identity}:microphone`;
                wanted.add(key);
                this.ensureAudio(studio.room, key, entry.microphone, session);
            }
        }

        this.dropUnwanted(studio.room, wanted);
    }

    stopStudio() {
        const studio = this.studio;

        if (studio === null) {
            return;
        }

        studio.stopped = true;
        this.studio = null;
        studio.mic?.stop?.();
        studio.mic = null;
        // The hall waits for the studio to be left (one encoder)
        const closing = this.leaveRoom(studio.room.link);
        this.letGo(studio.room);
        closing.then(() => this.studioEvent({ type: 'left', reason: CLIENT_INITIATED }));
        this.changed();
    }

    toggleMicrophone() {
        const studio = this.studio;

        if (studio === null) {
            return false;
        }

        studio.micMuted = !studio.micMuted;
        const publication = studio.micPublished;

        if (publication !== null) {
            (studio.micMuted ? publication.mute?.() : publication.unmute?.())?.catch?.(() => {});
        }

        this.changed();

        return studio.micMuted;
    }

    // ---- tiles and sounds

    ensureTile(room, key, entry, publication, session, container) {
        const existing = room.tiles.get(key);

        if (existing !== undefined && existing.publication === publication) {
            if (existing.name.textContent !== (entry.name ?? '')) {
                existing.name.textContent = entry.name ?? '';
            }

            return;
        }

        if (existing !== undefined) {
            this.removeTile(existing);
            room.tiles.delete(key);
        }

        if (!container) {
            return;
        }

        const element = this.createElement('div');
        element.className = 'compete-video-tile';
        const video = this.createElement('video');
        video.autoplay = true;
        video.muted = true;
        video.playsInline = true;
        video.setAttribute?.('playsinline', '');
        video.setAttribute?.('disablepictureinpicture', '');
        const name = this.createElement('span');
        name.className = 'compete-video-tile-name';
        name.textContent = entry.name ?? '';
        element.append(video, name);
        container.append(element);

        let detach = () => {};

        try {
            detach = session.attach(publication, video) ?? detach;
        } catch (e) {
            // Shown empty - the next change attaches it again
        }

        room.tiles.set(key, { element, name, publication, detach });
    }

    ensureAudio(room, key, publication, session) {
        const existing = room.audio.get(key);

        if (existing !== undefined && existing.publication === publication) {
            return;
        }

        if (existing !== undefined) {
            this.removeTile(existing);
            room.audio.delete(key);
        }

        const element = this.createElement('audio');
        element.autoplay = true;
        this.containers.audio?.append(element);

        let detach = () => {};

        try {
            detach = session.attach(publication, element) ?? detach;
        } catch (e) {
            // Attached again with the next change
        }

        room.audio.set(key, { element, publication, detach });
    }

    dropUnwanted(room, wanted) {
        for (const map of [room.tiles, room.audio]) {
            for (const [key, tile] of map) {
                if (!wanted.has(key)) {
                    this.removeTile(tile);
                    map.delete(key);
                }
            }
        }
    }

    removeTile(tile) {
        try {
            tile.detach?.();
        } catch (e) {
            // Gone already
        }

        tile.element?.remove?.();
    }

    // ---- the page's taps and what it shows

    /**
     * In a tap: the voice's AudioContext resumes and LiveKit plays what it held back (browsers play sound only after
     * a tap). Cheap when nothing is held back - the page calls it on every tap only when audioBlocked().
     */
    unlockAudio() {
        try {
            if (this.audioContext && this.audioContext.state !== 'running') {
                this.audioContext.resume?.()?.catch?.(() => {});
            }
        } catch (e) {
            // Tried again on the next tap
        }

        for (const room of [this.hall, this.studio?.room]) {
            const lkRoom = room?.session?.room;

            if (lkRoom && lkRoom.canPlaybackAudio === false) {
                lkRoom.startAudio?.()?.catch?.(() => {});
            }
        }
    }

    /**
     * Sound to play that the browser holds back (no tap since the page loaded, iOS interrupted the audio)
     */
    audioBlocked() {
        const sounds = this.hall.audio.size + (this.studio?.room.audio.size ?? 0);

        if (sounds === 0) {
            return false;
        }

        const contextStopped = this.audioContext !== null && this.audioContext.state !== 'running';
        const roomBlocked = [this.hall, this.studio?.room].some((room) => room?.session?.room?.canPlaybackAudio === false);

        return contextStopped || roomBlocked;
    }

    snapshot() {
        const studio = this.studio;

        return {
            hall: this.hall.role === null ? 'off' : this.hall.status,
            hallRole: this.hall.role,
            quality: this.hall.quality,
            publishing: this.hall.published !== null,
            speaking: this.hall.people.speaking,
            moderators: this.hall.people.moderators.length,
            cameras: this.hall.people.cameras.length,
            strip: [...this.hall.tiles.keys()].filter((key) => !this.hall.people.moderators.some((m) => key.startsWith(`${m.identity}:`))).length,
            hostTiles: [...this.hall.tiles.keys()].filter((key) => this.hall.people.moderators.some((m) => key.startsWith(`${m.identity}:`))).length,
            studio: studio === null ? 'off' : studio.room.status,
            studioQuality: studio?.room.quality ?? 'unknown',
            studioPublishing: studio !== null && studio.room.published !== null,
            // The hosts first, then the other guests
            studioNames: studio === null ? [] : (studio.room.people.everybody ?? [])
                .filter((entry) => entry.kind === 'moderator' || entry.kind === 'participant')
                .map((entry) => ({ name: entry.name ?? '', host: entry.kind === 'moderator' }))
                .sort((a, b) => Number(b.host) - Number(a.host)),
            micOn: studio !== null && studio.micPublished !== null && !studio.micMuted,
            micMuted: studio?.micMuted ?? false,
            micError: studio?.micError ?? null,
            audioBlocked: this.audioBlocked(),
        };
    }

    async close() {
        if (this.closed) {
            return;
        }

        this.stopStudio();
        const hall = this.hall;
        this.closed = true;
        this.hall = this.emptyRoom(null);
        this.letGo(hall);
        await hall.link?.close();
    }
}

function subscribe(publication) {
    try {
        if (publication && publication.isDesired !== true) {
            publication.setSubscribed?.(true);
        }
    } catch (e) {
        // Asked again with the next change
    }
}

function unsubscribe(publication) {
    try {
        if (publication && publication.isDesired === true) {
            publication.setSubscribed?.(false);
        }
    } catch (e) {
        // Asked again with the next change
    }
}
