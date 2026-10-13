/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { SOURCE_CAMERA, SOURCE_MICROPHONE, SOURCE_SCREEN_SHARE } from '../online/livekit_session.js';
import { endInvite, RoomLink, StudioInvites, studioRefusal } from '../online/console_room.js';
import { formatRemaining } from '../online/console_invites.js';
import { audienceOf, inviteOfGuest, stageOf, studioSections } from '../online/console_studio.js';
import { formatOnAir } from '../online/console_talk.js';
import { chooseTranslation } from '../translation_choice.js';

// The stage, the audience and the countdowns
const TICK_MS = 1000;

// How long a refused "Thank you" / "Withdraw" stays next to its guest
const ERROR_MS = 8000;

/**
 * The host's studio of an online round (templates/online_rounds/studio.html.twig, docs/features/online-competitions/
 * README.md §5.12 "The studio flow", contract phase-1b.md stream VM):
 *
 * - joins the studio (`online_video_token`, room `studio`, `m:{playerId}` - the studio's admin) at once, watching:
 *   the stage shows the guests, the co-hosts and every screen shared, with their sound; everybody may receive the
 *   host's tracks (`allowSubscribers: 'all'` - the spectators, the guests);
 * - "Start camera and microphone" - the host's own getUserMedia - puts the host on air (published with the wrapper,
 *   livekit_session.js); mute, camera off / on, share a screen, go off air. A connection that drops publishes them
 *   again once it is back;
 * - "Thank you" on a guest / "Withdraw" on a waiting invitation (`online_studio_end`): the server takes the guest off
 *   air and out of the studio (contract §8);
 * - the side lists and the guests' invitations come from the studio's JSON (console_room.js StudioInvites);
 * - "Live" and the number watching: the spectators join hidden, LiveKit's count of the room includes them
 *   (console_studio.js audienceOf()).
 */
export default class extends Controller {
    static targets = ['liveBadge', 'audience', 'connection', 'reconnect', 'stage', 'selfTile', 'selfVideo', 'selfState', 'stageEmpty', 'goLive', 'micToggle', 'cameraToggle', 'shareToggle', 'offAir', 'sound', 'error', 'onAirList', 'waitingList', 'earlierList'];

    static values = {
        urls: Object,
        placeholder: String,
        csrfToken: String,
        texts: Object,
    };

    connect() {
        this.closed = false;
        this.session = null;
        this.offs = [];
        this.list = [];
        this.tiles = new Map();
        this.invites = new Map();
        this.busy = new Set();
        this.errors = new Map();
        this.offset = 0;
        this.connection = 'connecting';
        // The host's own tracks: {track, publication} - the page's, never LiveKit's
        this.local = { camera: null, microphone: null, screen: null };
        this.onAir = false;
        this.starting = false;
        this.micMuted = false;

        this.link = new RoomLink({
            tokenUrl: this.urlsValue.token,
            room: 'studio',
            csrfToken: this.csrfTokenValue,
            joinOptions: { allowSubscribers: 'all' },
            onSession: (session) => this.joined(session),
            onLost: () => this.lost(),
            onStatus: (status) => {
                this.connection = status;
                this.renderConnection();
                this.renderControls();
            },
        });

        this.studio = new StudioInvites({
            url: this.urlsValue.studio,
            serverNow: () => this.serverNow(),
            onChange: (invites) => {
                this.invites = invites;
                this.render();
            },
            onServerTime: (serverNowMs, sentAt, receivedAt) => {
                this.offset = serverNowMs - (sentAt + receivedAt) / 2;
            },
        });

        this.onClick = (event) => this.clicked(event);
        this.element.addEventListener('click', this.onClick);

        this.renderConnection();
        this.renderControls();
        this.link.connect();
        this.studio.start();
        this.timer = window.setInterval(() => this.tick(), TICK_MS);
        this.render();
    }

    disconnect() {
        this.closed = true;
        window.clearInterval(this.timer);
        this.element.removeEventListener('click', this.onClick);
        this.studio.close();
        this.link.close();

        // The host's camera, microphone and screen are the page's - they go with it
        for (const kind of ['camera', 'microphone', 'screen']) {
            this.local[kind]?.track?.stop();
            this.local[kind] = null;
        }
    }

    // ---------------------------------------------------------------- texts

    t(key, params = {}) {
        let text = this.textsValue[key];

        if (typeof text !== 'string') {
            return key;
        }

        for (const [name, value] of Object.entries(params)) {
            text = text.replaceAll(`%${name}%`, String(value));
        }

        return text;
    }

    tc(key, count, params = {}) {
        const message = this.textsValue[key];
        let text = message && typeof message === 'object' ? chooseTranslation(message.message, count, message.locale) : null;

        if (text === null) {
            text = typeof message === 'string' ? message : String(count);
        }

        for (const [name, value] of Object.entries({ count, ...params })) {
            text = text.replaceAll(`%${name}%`, String(value));
        }

        return text;
    }

    serverNow() {
        return Date.now() + this.offset;
    }

    // ---------------------------------------------------------------- the room

    async joined(session) {
        this.session = session;
        this.offs.push(session.onParticipants((list) => this.roomChanged(list)));

        // Back after a drop: the host was on air - on air again with the same tracks
        if (this.onAir) {
            await this.publishLocal();
        }

        this.render();
    }

    lost() {
        this.offs.splice(0).forEach((off) => off());

        for (const tile of this.tiles.values()) {
            this.detachTile(tile);
        }

        // The publications went with the session; the tracks stay the page's
        for (const kind of ['camera', 'microphone', 'screen']) {
            if (this.local[kind]) {
                this.local[kind].publication = null;
            }
        }

        this.session = null;
        this.list = [];

        if (!this.closed) {
            this.render();
        }
    }

    reconnect() {
        this.link.reconnectNow();
    }

    roomChanged(list) {
        const guestsBefore = new Set(this.list.filter((entry) => entry.kind === 'participant').map((entry) => entry.id));
        const guestsNow = new Set(list.filter((entry) => entry.kind === 'participant').map((entry) => entry.id));
        this.list = list;

        // A guest came or went: their invitation changed too
        if (guestsBefore.size !== guestsNow.size || [...guestsNow].some((id) => !guestsBefore.has(id))) {
            this.studio.hint();
        }

        this.render();
    }

    // ---------------------------------------------------------------- on air

    async goOnAir() {
        if (this.starting || this.onAir) {
            return;
        }

        if (this.session === null) {
            this.showError('not_connected');

            return;
        }

        this.starting = true;
        this.showError(null);
        this.renderControls();

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30 } },
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
            });

            this.local.camera = { track: stream.getVideoTracks()[0] ?? null, publication: null };
            this.local.microphone = { track: stream.getAudioTracks()[0] ?? null, publication: null };
            this.onAir = true;
            this.micMuted = false;
            this.showSelf();
            await this.publishLocal();
            // A click: the guests' sound may play now
            this.session?.room?.startAudio?.()?.catch?.(() => {});
        } catch (e) {
            this.stopLocal();
            this.onAir = false;
            this.showError(e?.name === 'NotAllowedError' || e?.name === 'SecurityError'
                ? 'camera_denied'
                : (e?.name === 'NotFoundError' || e?.name === 'OverconstrainedError' ? 'camera_missing' : 'camera_failed'));
        }

        this.starting = false;
        this.render();
    }

    async publishLocal() {
        const sources = { camera: SOURCE_CAMERA, microphone: SOURCE_MICROPHONE, screen: SOURCE_SCREEN_SHARE };

        for (const [kind, source] of Object.entries(sources)) {
            const local = this.local[kind];

            if (!local?.track || local.track.readyState !== 'live' || local.publication || this.session === null) {
                continue;
            }

            try {
                local.publication = await this.session.publish(local.track, { source, simulcastLayers: kind === 'screen' ? false : undefined });

                if (kind === 'microphone' && this.micMuted) {
                    await local.publication.mute();
                }
            } catch (e) {
                this.showError(kind === 'screen' ? 'share_failed' : 'camera_failed');
            }
        }
    }

    async unpublishLocal(kind) {
        const local = this.local[kind];

        if (!local) {
            return;
        }

        this.local[kind] = null;

        if (local.publication && this.session !== null) {
            await this.session.unpublish(local.track).catch(() => {});
        }

        local.track?.stop();
    }

    stopLocal() {
        for (const kind of ['camera', 'microphone', 'screen']) {
            this.local[kind]?.track?.stop();
            this.local[kind] = null;
        }

        this.showSelf();
    }

    async goOffAir() {
        this.onAir = false;

        for (const kind of ['camera', 'microphone', 'screen']) {
            await this.unpublishLocal(kind);
        }

        this.showSelf();
        this.render();
    }

    async toggleMicrophone() {
        const publication = this.local.microphone?.publication;

        this.micMuted = !this.micMuted;

        if (publication) {
            await (this.micMuted ? publication.mute() : publication.unmute()).catch(() => {});
        } else if (this.local.microphone?.track) {
            this.local.microphone.track.enabled = !this.micMuted;
        }

        this.renderControls();
    }

    async toggleCamera() {
        if (this.local.camera) {
            await this.unpublishLocal('camera');
            this.showSelf();
            this.render();

            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30 } } });
            this.local.camera = { track: stream.getVideoTracks()[0] ?? null, publication: null };
            this.showSelf();
            await this.publishLocal();
        } catch (e) {
            this.showError(e?.name === 'NotAllowedError' ? 'camera_denied' : 'camera_failed');
        }

        this.render();
    }

    async toggleScreen() {
        if (this.local.screen) {
            await this.unpublishLocal('screen');
            this.render();

            return;
        }

        try {
            const stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
            const track = stream.getVideoTracks()[0] ?? null;

            if (track === null) {
                return;
            }

            this.local.screen = { track, publication: null };
            // The browser's own "Stop sharing"
            track.addEventListener('ended', () => {
                if (this.local.screen?.track === track) {
                    this.unpublishLocal('screen').then(() => this.render());
                }
            });
            await this.publishLocal();
        } catch (e) {
            // Cancelled in the browser's picker: nothing to say
            if (e?.name !== 'NotAllowedError' && e?.name !== 'AbortError') {
                this.showError('share_failed');
            }
        }

        this.render();
    }

    startSound() {
        const started = this.session?.room?.startAudio?.();

        started?.then?.(() => this.renderControls(), () => {});
    }

    showSelf() {
        const track = this.local.camera?.track ?? null;
        const video = this.selfVideoTarget;

        if (track === null) {
            video.srcObject = null;
            video.hidden = true;
        } else if (video.srcObject?.getVideoTracks?.()[0] !== track) {
            video.srcObject = new MediaStream([track]);
            video.hidden = false;
        }

        this.selfTileTarget.dataset.onAir = this.onAir ? 'true' : 'false';
        this.selfStateTarget.textContent = this.t(this.onAir ? (track === null ? 'stage_camera_off' : 'stage_on_air') : 'stage_off_air');
    }

    showError(key) {
        this.errorTarget.hidden = key === null;
        this.errorTarget.textContent = key === null ? '' : this.t(`error_${key}`);
    }

    // ---------------------------------------------------------------- the guests' invitations

    clicked(event) {
        const button = event.target.closest?.('[data-studio-action]');

        if (!button || !this.element.contains(button)) {
            return;
        }

        const participantId = button.dataset.participantId;

        if (button.dataset.studioAction === 'end' && typeof participantId === 'string') {
            this.end(participantId);
        }
    }

    async end(participantId) {
        const invite = this.invites.get(participantId) ?? null;

        if (invite === null || this.busy.has(participantId)) {
            return;
        }

        this.busy.add(participantId);
        this.errors.delete(participantId);
        this.render();

        const answer = await endInvite({ url: this.urlsValue.end, placeholder: this.placeholderValue, csrfToken: this.csrfTokenValue }, invite);
        this.busy.delete(participantId);

        if (this.closed) {
            return;
        }

        if (answer.kind !== 'ok') {
            this.errors.set(participantId, { key: studioRefusal(answer), until: Date.now() + ERROR_MS });
        }

        if (answer.data?.invite) {
            this.studio.merge(answer.data.invite);
        } else {
            this.render();
        }

        this.studio.hint();
    }

    // ---------------------------------------------------------------- drawing

    tick() {
        this.renderAudience();
        this.renderLists();
        this.renderControls();
    }

    render() {
        if (this.closed) {
            return;
        }

        this.showSelf();
        this.renderStage();
        this.renderLists();
        this.renderAudience();
        this.renderControls();
    }

    renderConnection() {
        const kind = {
            connected: 'text-bg-success',
            reconnecting: 'text-bg-warning',
            failed: 'text-bg-danger',
            duplicate: 'text-bg-danger',
            sign_in: 'text-bg-danger',
            forbidden: 'text-bg-danger',
        }[this.connection] ?? 'text-bg-secondary';

        this.connectionTarget.textContent = this.t(`connection_${this.connection}`);
        this.connectionTarget.className = `badge ${kind}`;
        this.reconnectTarget.hidden = this.connection !== 'duplicate';
    }

    renderControls() {
        const connected = this.connection === 'connected' && this.session !== null;
        const sharing = this.local.screen !== null;

        this.goLiveTarget.hidden = this.onAir;
        this.goLiveTarget.disabled = this.starting || !connected;
        this.setLabel(this.goLiveTarget, this.t(this.starting ? 'controls_starting' : 'controls_go_on_air'));

        this.micToggleTarget.hidden = !this.onAir;
        this.micToggleTarget.setAttribute('aria-pressed', this.micMuted ? 'true' : 'false');
        this.setLabel(this.micToggleTarget, this.t(this.micMuted ? 'controls_unmute' : 'controls_mute'));

        this.cameraToggleTarget.hidden = !this.onAir;
        this.setLabel(this.cameraToggleTarget, this.t(this.local.camera ? 'controls_camera_off' : 'controls_camera_on'));

        this.shareToggleTarget.hidden = !this.onAir || typeof navigator.mediaDevices?.getDisplayMedia !== 'function';
        this.setLabel(this.shareToggleTarget, this.t(sharing ? 'controls_stop_share' : 'controls_share'));

        this.offAirTarget.hidden = !this.onAir;

        // The guests' sound waits for a click in this page (the browser's autoplay rules)
        this.soundTarget.hidden = this.session === null || this.session.room?.canPlaybackAudio !== false;
    }

    setLabel(button, text) {
        const label = button.querySelector('span');

        if (label && label.textContent !== text) {
            label.textContent = text;
        }
    }

    renderAudience() {
        const room = this.session?.room ?? null;
        const audience = room === null ? 0 : audienceOf(room.numParticipants, this.list.length);
        const live = audience > 0;
        const text = room === null ? '' : this.tc('audience', audience);

        this.liveBadgeTarget.textContent = this.t(live ? 'live' : 'not_live');
        this.liveBadgeTarget.classList.toggle('is-live', live);
        this.element.dataset.audience = String(audience);

        if (this.audienceTarget.textContent !== text) {
            this.audienceTarget.textContent = text;
        }
    }

    renderStage() {
        const stage = this.session === null ? [] : stageOf(this.list);
        const seen = new Set();
        let previous = this.selfTileTarget;

        for (const spec of stage) {
            seen.add(spec.key);
            let tile = this.tiles.get(spec.key);

            if (!tile) {
                tile = this.createTile(spec);
                this.tiles.set(spec.key, tile);
            }

            this.updateTile(tile, spec);

            if (previous.nextElementSibling !== tile.element) {
                previous.after(tile.element);
            }

            previous = tile.element;
        }

        for (const [key, tile] of this.tiles) {
            if (!seen.has(key)) {
                this.detachTile(tile);
                tile.element.remove();
                this.tiles.delete(key);
            }
        }

        this.stageEmptyTarget.hidden = stage.some((spec) => spec.kind === 'guest');
    }

    createTile(spec) {
        const element = document.createElement('article');
        element.className = `online-studio-tile online-studio-tile--${spec.kind}`;
        element.dataset.key = spec.key;
        element.dataset.identity = spec.identity;

        const video = document.createElement('video');
        video.className = 'online-studio-video';
        video.muted = true;
        video.playsInline = true;
        video.autoplay = true;
        video.setAttribute('aria-hidden', 'true');

        const audio = document.createElement('audio');
        audio.autoplay = true;

        const name = document.createElement('span');
        name.className = 'online-studio-tile-name';

        const state = document.createElement('span');
        state.className = 'online-studio-tile-state';

        const actions = document.createElement('div');
        actions.className = 'online-studio-tile-actions';

        element.append(video, audio, name, state, actions);

        return { element, video, audio, name, state, actions, videoTrack: null, audioTrack: null, detachVideo: null, detachAudio: null, key: null };
    }

    updateTile(tile, spec) {
        const videoTrack = spec.video?.track ?? null;

        if (videoTrack !== tile.videoTrack) {
            tile.detachVideo?.();
            tile.detachVideo = null;
            tile.videoTrack = videoTrack;

            if (videoTrack !== null && this.session !== null) {
                tile.detachVideo = this.session.attach(spec.video, tile.video);
            }
        }

        const audioTrack = spec.audio?.track ?? null;

        if (audioTrack !== tile.audioTrack) {
            tile.detachAudio?.();
            tile.detachAudio = null;
            tile.audioTrack = audioTrack;

            if (audioTrack !== null && this.session !== null) {
                tile.detachAudio = this.session.attach(spec.audio, tile.audio);
            }
        }

        const serverNow = this.serverNow();
        const invite = spec.kind === 'guest' ? inviteOfGuest(this.invites, spec.id, serverNow) : null;
        const busy = spec.kind === 'guest' && this.busy.has(spec.id);
        const error = spec.kind === 'guest' ? this.errors.get(spec.id) : null;
        const errorKey = error && error.until > Date.now() ? error.key : null;
        const label = spec.kind === 'screen' ? this.t('stage_screen', { name: spec.name || spec.identity }) : (spec.name || spec.identity);
        const states = [];

        if (spec.kind === 'cohost') {
            states.push(this.t('stage_cohost'));
        }

        if (spec.kind !== 'screen' && (spec.audio === null || spec.audio.isMuted === true)) {
            states.push(this.t('stage_mic_muted'));
        }

        if (spec.kind !== 'screen' && (spec.video === null || spec.video.isMuted === true)) {
            states.push(this.t('stage_no_video'));
        }

        const key = JSON.stringify([label, states, invite?.id ?? null, busy, errorKey]);

        if (tile.key === key) {
            return;
        }

        tile.key = key;
        tile.name.textContent = label;
        tile.state.textContent = states.join(' · ');
        tile.state.hidden = states.length === 0;
        tile.element.dataset.video = videoTrack === null ? 'none' : 'live';

        const nodes = [];

        if (spec.kind === 'guest') {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-success';
            button.dataset.studioAction = 'end';
            button.dataset.participantId = spec.id;
            // Without the invitation known yet (the studio's JSON is a moment behind) - read again, then thank
            button.disabled = busy || invite === null;
            button.textContent = this.t(busy ? 'working' : 'thank_you');
            nodes.push(button);

            if (invite === null && tile.hinted !== true) {
                tile.hinted = true;
                this.studio.hint();
            }
        }

        if (errorKey !== null) {
            const message = document.createElement('span');
            message.className = 'online-studio-error small';
            message.setAttribute('role', 'status');
            message.textContent = this.t(`error_end_${errorKey}`);
            nodes.push(message);
        }

        tile.actions.replaceChildren(...nodes);
        tile.actions.hidden = nodes.length === 0;
    }

    detachTile(tile) {
        for (const detach of [tile.detachVideo, tile.detachAudio]) {
            try {
                detach?.();
            } catch (e) {
                // Gone with its track
            }
        }

        tile.detachVideo = null;
        tile.detachAudio = null;
        tile.videoTrack = null;
        tile.audioTrack = null;
        tile.key = null;
    }

    renderLists() {
        const serverNow = this.serverNow();
        const { waiting, onAir, earlier } = studioSections(this.invites, serverNow);
        const inRoom = new Set(this.list.filter((entry) => entry.kind === 'participant').map((entry) => entry.id));

        this.renderList(this.onAirListTarget, onAir, 'lists_on_air_none', (invite) => {
            let line = this.t('lists_joining');

            if (Number.isFinite(invite.onAirFromMs) && inRoom.has(invite.participantId)) {
                line = this.t('lists_on_air_for', { time: formatOnAir(serverNow - invite.onAirFromMs) });
            }

            return [line, this.listButton(invite, 'thank_you', 'btn-success')];
        });

        this.renderList(this.waitingListTarget, waiting, 'lists_waiting_none', (invite) => [
            this.t('lists_waiting_left', { time: formatRemaining(invite.expiresAtMs - serverNow) }),
            this.listButton(invite, 'withdraw', 'btn-outline-secondary'),
        ]);

        // studioSections() says `withdrawn` for one ended before anybody was on air
        this.renderList(this.earlierListTarget, earlier, 'lists_earlier_none', (invite) => [this.t(`status_${invite.status}`)]);
    }

    renderList(list, invites, emptyKey, describe) {
        const rows = invites.map((invite) => {
            const [line, button] = describe(invite);

            return { invite, line, button };
        });
        const key = JSON.stringify(rows.map(({ invite, line, button }) => [invite.id, invite.name, line, button?.disabled ?? null]));

        if (list.dataset.key === key) {
            return;
        }

        list.dataset.key = key;

        if (rows.length === 0) {
            const item = document.createElement('li');
            item.className = 'small text-body-secondary';
            item.textContent = this.t(emptyKey);
            list.replaceChildren(item);

            return;
        }

        list.replaceChildren(...rows.map(({ invite, line, button }) => {
            const item = document.createElement('li');
            item.className = 'online-studio-list-item';
            item.dataset.participantId = invite.participantId;

            const name = document.createElement('span');
            name.className = 'online-studio-list-name';
            name.textContent = invite.name ?? '';

            const detail = document.createElement('span');
            detail.className = 'small text-body-secondary';
            detail.textContent = line;

            item.append(name, detail);

            if (button) {
                item.append(button);
            }

            return item;
        }));
    }

    listButton(invite, key, style) {
        const busy = this.busy.has(invite.participantId);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-sm ${style}`;
        button.dataset.studioAction = 'end';
        button.dataset.participantId = invite.participantId;
        button.disabled = busy;
        button.textContent = this.t(busy ? 'working' : key);

        return button;
    }
}
