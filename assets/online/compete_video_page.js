/**
 * The participant page's live video as the page shows it (contract phase-1b.md stream VP) - the part of
 * assets/controllers/compete_controller.js that only a round with live video needs, so it lives in a chunk of its own
 * (with compete_video.js and compete_video_runtime.js; livekit-client is yet another chunk, loaded when a room is
 * joined). The controller hands it what the page knows on every frame it draws; this decides with compete_video.js,
 * drives the rooms through compete_video_runtime.js and paints the video parts (templates/compete/_video_*.html.twig,
 * _screen_second.html.twig) through the controller's own painters.
 *
 * - plan(input) before the screen is shown: the phone's place in the hall and whether video holds the camera
 *   (compete_video.js hallPlan(), videoHoldsCamera()) - the screen turns the camera on or off by it;
 * - tick() after the frame: the runtime brought to the plan, the video parts painted;
 * - heartbeat() / stateStudio() / the runtime's data message: the invitation to the studio, from any of the three;
 * - join(), decline(), leave(), mute(), toggleStrip(): the participant's taps.
 *
 * Nothing here may stop the page competing: the controller calls every method inside its own try.
 */

import {
    formatSecondsLeft,
    hallPlan,
    loadStripHidden,
    saveStripHidden,
    secondScreenOffered,
    stripPlan,
    studioHoldsPhone,
    studioReducer,
    studioView,
    studioWanted,
    videoHoldsCamera,
    videoOn,
    videoStatus,
    voiceVolume,
} from './compete_video.js';
import { ParticipantVideo } from './compete_video_runtime.js';
import { clockText, countdownText } from './compete_flow.js';

// Marks this chunk (the bundle check looks for it)
export const VIDEO_CHUNK_NAME = 'msp-compete-video-runtime';

export class CompeteVideoPage {
    /**
     * @param {object} options
     * @param {object} options.api                 compete_api.js: videoToken(), studioAnswer()
     * @param {string} options.deviceId
     * @param {Storage|null} options.storage
     * @param {Map<string, Element>} options.parts  the [data-compete-video] elements
     * @param {AudioContext|null} [options.audioContext]
     * @param {object} options.paint               the controller's {field, shown, data, t, tc, notify}
     * @param {function(): number} options.serverNow
     * @param {function(): void} options.requestRender
     * @param {function(object): object} [options.createVideo]   new ParticipantVideo(options) - replaced in tests
     */
    constructor({ api, deviceId, storage, parts, audioContext = null, paint, serverNow, requestRender, createVideo = (options) => new ParticipantVideo(options) }) {
        this.api = api;
        this.deviceId = deviceId;
        this.storage = storage;
        this.parts = parts ?? new Map();
        this.paint = paint;
        this.serverNow = serverNow;
        this.requestRender = requestRender;

        // The invitation to the studio and every one that came to an end here (never offered again)
        this.studio = null;
        this.handled = new Set();
        // The invitation the page has shown (its end is said only then - never in the middle of a solve)
        this.shownId = null;
        this.stripHidden = loadStripHidden(storage);
        this.input = null;
        this.hall = { room: null, publish: false, subscribers: 'moderators' };

        this.video = createVideo({
            requestToken: (room, role) => this.api.videoToken({ deviceId: this.deviceId, room, role }),
            audioContext,
            containers: {
                audio: this.parts.get('audio') ?? null,
                strip: this.parts.get('strip') ?? null,
                host: this.parts.get('host') ?? null,
                studio: this.parts.get('studio') ?? null,
            },
            onChange: () => this.requestRender(),
            onInvite: (inviteId) => this.dispatch({ type: 'learned', inviteId, source: 'data' }),
            onStudio: (event) => this.dispatch(event),
        });
    }

    /**
     * Before the screen is shown: the phone's place in the hall. Returns whether live video holds the camera.
     *
     * @param {object} input   {state, screen, view, serverNowMs, ready, claimPending, takenOver, preview, linkDead,
     *                         secondScreen, cameraStatus, cameraTrack, practice, otherDeviceActive}
     */
    plan(input) {
        this.input = input;
        this.dispatch({ type: 'tick' });

        // The phone can not be in the studio any more
        if (studioHoldsPhone(this.studio)) {
            if (!videoOn(input.state)) {
                this.dispatch({ type: 'stop', outcome: 'unavailable' });
            } else if (input.takenOver || input.linkDead || input.secondScreen || input.preview) {
                this.dispatch({ type: 'stop', outcome: 'left' });
            }
        }

        const inStudio = studioHoldsPhone(this.studio);
        this.hall = hallPlan({
            available: true,
            state: input.state,
            screen: input.screen,
            view: input.view,
            serverNowMs: input.serverNowMs,
            // A fresh "I'm ready" counts once the server stored it: the token is for the device holding the entry
            ready: input.ready === true && input.claimPending !== true,
            takenOver: input.takenOver,
            preview: input.preview,
            linkDead: input.linkDead,
            secondScreen: input.secondScreen,
            inStudio,
            // iOS mutes the camera for a moment on many occasions - still the same track
            cameraLive: ['live', 'muted'].includes(input.cameraStatus),
        });

        return videoHoldsCamera({ hall: this.hall, inStudio, screen: input.screen, view: input.view, serverNowMs: input.serverNowMs });
    }

    /**
     * After the frame: the runtime brought to the plan (cheap when nothing changed), the video parts painted.
     *
     * @param {{wakeTapShown?: boolean}} [page]   "Tap here once" is up - its tap lets the voice go too
     */
    tick({ wakeTapShown = false } = {}) {
        const input = this.input;

        if (input === null) {
            return;
        }

        const hall = this.hall;
        const inStudio = studioHoldsPhone(this.studio);
        const strip = stripPlan({ state: input.state, hall, screen: input.screen, view: input.view, practice: input.practice, inStudio, hidden: this.stripHidden });

        this.video.apply({
            hall: hall.room,
            publish: hall.publish,
            // The very track the frame grabber reads (camera.js)
            camera: input.cameraTrack ?? null,
            subscribers: hall.subscribers,
            strip: strip.tiles,
            hostVideo: hall.room === 'screen',
            studio: studioWanted(this.studio),
            voiceVolume: voiceVolume(input.view, input.serverNowMs),
        });

        this.render(input, hall, strip, wakeTapShown);
    }

    // ---- the invitation's sources and the participant's taps

    /**
     * A heartbeat's answer - its `studioInviteId` (absent without a media server); `sentAtMs` = the server's time when
     * it was sent.
     */
    heartbeat(data, sentAtMs) {
        if (data === null || typeof data !== 'object' || !Object.prototype.hasOwnProperty.call(data, 'studioInviteId')) {
            return;
        }

        if (typeof data.studioInviteId === 'string' && data.studioInviteId !== '') {
            this.dispatch({ type: 'learned', inviteId: data.studioInviteId, source: 'heartbeat' });
        } else {
            this.dispatch({ type: 'cleared', sentAtMs });
        }
    }

    /**
     * The state's `studio` ({inviteId, status} | null).
     */
    stateStudio(studio) {
        if (studio !== null && typeof studio === 'object' && typeof studio.inviteId === 'string') {
            this.dispatch({ type: 'learned', inviteId: studio.inviteId, status: studio.status, source: 'state' });
        }
    }

    async join() {
        await this.answer('join');
    }

    async decline() {
        await this.answer('decline');
    }

    async answer(answer) {
        const studio = this.studio;

        if (studio?.phase !== 'invited') {
            return;
        }

        this.dispatch({ type: answer });
        const reply = await this.api.studioAnswer({ inviteId: studio.inviteId, answer });

        if (reply.kind === 'ok') {
            this.dispatch({ type: 'answered', status: reply.data?.studio?.status ?? null });
        } else if (reply.kind === 'client' || reply.kind === 'forbidden') {
            this.dispatch({ type: 'refused', code: reply.data?.error ?? 'failed' });
        } else {
            this.dispatch({ type: 'refused', code: reply.kind === 'offline' ? 'offline' : 'failed' });
        }
    }

    leave() {
        this.dispatch({ type: 'leave' });
    }

    mute() {
        this.video.toggleMicrophone();
        this.requestRender();
    }

    toggleStrip() {
        this.stripHidden = !this.stripHidden;
        saveStripHidden(this.storage, this.stripHidden);
        this.requestRender();
    }

    unlockAudio() {
        this.video.unlockAudio();
    }

    audioBlocked() {
        return this.video.audioBlocked();
    }

    close() {
        return this.video.close();
    }

    /**
     * The invitation moves on (compete_video.js studioReducer()); an end is said once - when the page had shown it.
     */
    dispatch(event) {
        const before = this.studio;
        const after = studioReducer(before, { ...event, atMs: this.serverNow() }, this.handled);

        if (after === before) {
            return;
        }

        this.studio = after;

        if (after?.phase === 'over' && before?.phase !== 'over' && after.outcome && this.shownId === after.inviteId) {
            this.paint.notify(this.paint.t(`video_outcome_${after.outcome}`), ['thanks', 'left'].includes(after.outcome) ? 'success' : 'info');
        }

        this.requestRender();
    }

    // ---- painting (the controller's painters skip what did not change)

    render(input, hall, strip, wakeTapShown = false) {
        const { field, shown, data, t, tc } = this.paint;
        const snapshot = this.video.snapshot();
        const inStudio = studioHoldsPhone(this.studio);
        const invite = studioView(this.studio, { cameraDevice: hall.room === 'camera' || inStudio, screen: input.screen, serverNowMs: input.serverNowMs });

        if (invite.prompt || invite.panel !== null) {
            this.shownId = this.studio?.inviteId ?? null;
        }

        // The quiet line: the studio's connection while in it, the hall's otherwise - nothing loud, nothing blocking
        const link = inStudio ? snapshot.studio : (hall.room === null ? 'off' : snapshot.hall);
        const status = videoStatus({ link, quality: inStudio ? snapshot.studioQuality : snapshot.quality });
        field('videoStatus', status === '' ? '' : t(`video_status_${status}`));
        data('video', status);

        // The host's voice - "speaking" only while the hall is there (not while it reconnects); "tap to hear the host"
        // only when "Tap here once" is not up already
        const speaking = hall.room !== null && !inStudio && snapshot.hall === 'connected' ? snapshot.speaking : [];
        shown('videoSpeaking', speaking.length > 0);
        field('videoSpeaking', speaking.length > 0 && speaking[0] !== '' ? t('video_host_speaking_name', { name: speaking[0] }) : t('video_host_speaking'));
        shown('videoAudioTap', snapshot.audioBlocked === true && !wakeTapShown);

        // The others
        shown('videoStrip', strip.shown);
        shown('videoStripTiles', strip.tiles);
        shown('videoStripEmpty', strip.tiles && snapshot.cameras === 0);
        field('videoStripTitle', tc('video_strip_title', snapshot.cameras));
        field('videoStripToggle', t(this.stripHidden ? 'video_strip_show' : 'video_strip_hide'));

        // The invitation
        shown('videoInvite', invite.prompt);
        field('videoInviteLeft', invite.prompt ? t('video_invite_left', { time: formatSecondsLeft(invite.secondsLeft) }) : '');
        field('videoInviteError', invite.error ? t(`video_invite_error_${invite.error}`) : '');
        this.disabled('inviteJoin', invite.busy);
        this.disabled('inviteDecline', invite.busy);
        shown('videoInviteElsewhere', invite.elsewhere);

        // On air
        const names = snapshot.studioNames
            .map((person) => (person.host && person.name !== '' ? t('video_studio_host', { name: person.name }) : person.name))
            .filter((name) => name !== '');
        shown('videoStudio', invite.panel !== null);
        data('studio', invite.panel ?? '');
        field('videoStudioTitle', invite.panel === null ? '' : t(`video_studio_${invite.panel}`));
        field('videoStudioPeople', invite.panel !== 'on_air' ? '' : (names.length > 0 ? t('video_studio_people', { names: names.join(', ') }) : t('video_studio_alone')));
        shown('videoStudioMic', invite.panel === 'on_air' && snapshot.micError === null && (snapshot.micOn || snapshot.micMuted));
        field('videoStudioMic', t(snapshot.micMuted ? 'video_studio_unmute' : 'video_studio_mute'));
        field('videoStudioMicError', invite.panel === 'on_air' && snapshot.micError ? t('video_studio_mic_blocked') : '');

        // The second screen
        shown('videoSecondOffer', secondScreenOffered({
            available: true,
            state: input.state,
            screen: input.screen,
            otherDeviceActive: input.otherDeviceActive,
            preview: input.preview,
        }));

        if (input.screen === 'second_screen') {
            field('videoSecondLine', secondScreenLine(input.view, t));
            shown('videoSecondNoHost', snapshot.hostTiles === 0);
            shown('videoWatchLink', input.state?.round?.settings?.studio === true);
        }
    }

    disabled(part, value) {
        const element = this.parts.get(part);

        if (element && element.disabled !== value) {
            element.disabled = value;
        }
    }
}

/**
 * The second screen's line under the clock: waiting, the time to the start, the time left, the end.
 */
export function secondScreenLine(view, t) {
    switch (view?.screen) {
        case 'scheduled':
        case 'countdown':
            return t('starts_in', { time: countdownText(view.countdownMs) });
        case 'running':
            return view.remainingMs === null ? '' : t('left', { time: clockText(view.remainingMs, { limitMs: view.limitMs, ceil: true }) });
        case 'ended':
            return t('video_second_ended');
        default:
            return t('waiting');
    }
}
