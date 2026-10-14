/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { createClock, fetchServerTime } from '../online/clock_sync.js';
import { deriveView, nextStateCheckAt, newer } from '../online/round_state.js';
import { fill } from '../online/compete_flow.js';
import { joinRoom } from '../online/livekit_session.js';
import {
    attemptsAfterLoss,
    clockLine,
    CONNECTED,
    CONNECTING,
    curtainFor,
    gridColumns,
    gridHeight,
    isLive,
    RETRYING,
    retryDelayMs,
    secondsUntil,
    soundButtonWanted,
    studioTiles,
    tileWidth,
    tokenRefusal,
    watchingCount,
} from '../online/watch_view.js';
import { chooseTranslation } from '../translation_choice.js';

// The round's live updates are down (the public stopwatch topic): its state is asked for this often meanwhile
const STATE_POLL_MS = 15000;
const CLOCK_TICK_MS = 250;
// LiveKit refreshes the room's count every few seconds - read that often
const COUNT_EVERY_MS = 3000;
const AUDIO_PLAYBACK_EVENT = 'audioPlaybackChanged';
// The stage never shrinks below this on a small screen - the page scrolls instead
const MIN_STAGE_HEIGHT = 180;

/**
 * The public watch page of a round's studio (templates/online_rounds/watch.html.twig, docs/features/online-competitions/
 * contracts/phase-1b.md §5 `online_watch`) - a thin shell over assets/online/watch_view.js:
 *
 * - the studio: a spectator token from `online_watch_token`, then the room through livekit_session.js (watching only);
 *   a tile per video on air, a sound per microphone, the "Live" badge and the number watching. Refused or cut off, the
 *   curtain says why and the page tries again after a growing wait (watch_view.js) - "the studio is full" after about a
 *   minute, never a reload;
 * - the sound: browsers play nothing before a tap - LiveKit says when it was refused, a button asks for the tap;
 * - the round's clock, read-only: the public stopwatch topic (`/round-stopwatch/{roundId}`, polled while it is down)
 *   and the server's time (clock_sync.js), the newest state winning by asOfMs (round_state.js);
 * - `obs`: the video alone - no clock, no curtain, no button (OBS's browser source plays sound without a tap).
 */
export default class extends Controller {
    static targets = [
        'stage', 'tiles', 'sounds', 'curtain', 'curtainTitle', 'curtainText', 'retryButton', 'retryNote', 'youtube',
        'soundButton', 'muteButton', 'live', 'count', 'clockLabel', 'clockTime', 'clockLeft', 'planned',
    ];

    static values = {
        urls: Object,
        stopwatch: Object,
        mercureUrl: String,
        topic: String,
        texts: Object,
        obs: Boolean,
    };

    connect() {
        this.closed = false;
        this.texts = this.textsValue ?? {};
        this.state = CONNECTING;
        this.session = null;
        this.sessionOffs = [];
        this.joining = false;
        this.attempts = 0;
        this.retryAt = null;
        this.retryTimer = null;
        this.connectedAt = null;
        this.participants = [];
        this.studio = { tiles: [], sounds: [], seen: new Map() };
        this.tileViews = new Map();
        this.soundViews = new Map();
        this.canPlaybackAudio = true;
        this.reconnecting = false;
        this.userMuted = false;
        this.stopwatch = this.hasStopwatchValue ? this.stopwatchValue : null;
        this.eventSource = null;
        this.pollTimer = null;
        this.stateCheckTimer = null;

        this.clock = createClock({ fetchTime: () => fetchServerTime(this.urlsValue.time) });
        this.onAudioPlayback = () => this.audioPlaybackChanged();
        this.onVisibility = () => this.visibilityChanged();
        document.addEventListener('visibilitychange', this.onVisibility);

        this.onResize = () => this.layout();
        window.addEventListener('resize', this.onResize);

        if (!this.obsValue) {
            this.clock.sync({ samples: 3 });
            this.startRoundStream();
            // The page's state was read when it was rendered - asked again at once, which also samples the clock
            this.refreshStopwatch();
            this.scheduleStateCheck();
        }

        // The clock, the countdown of the next try, the number watching
        this.ticker = window.setInterval(() => this.tick(), CLOCK_TICK_MS);
        this.lastCountAt = 0;
        this.render();
        this.layout();
        this.join();
    }

    disconnect() {
        this.closed = true;
        window.clearInterval(this.ticker);
        window.clearTimeout(this.retryTimer);
        window.clearTimeout(this.stateCheckTimer);
        window.clearInterval(this.pollTimer);
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.removeEventListener('resize', this.onResize);
        this.eventSource?.close();
        this.eventSource = null;
        this.clock.close();
        this.dropSession();
    }

    // ---- the studio

    /**
     * A token, then the room - one try at a time. Whatever fails on the way in leads to the next try after a wait
     * (never a reload), with the cause in the console.
     */
    async join() {
        if (this.closed || this.joining) {
            return;
        }

        this.joining = true;
        this.clearRetry();
        this.setState(CONNECTING);

        try {
            await this.enterStudio();
        } catch (e) {
            console.warn('The studio could not be joined - trying again after a wait', e);
            this.dropSession();

            if (!this.closed) {
                this.refused(tokenRefusal({ status: 0 }, this.attempts));
            }
        } finally {
            this.joining = false;
        }
    }

    async enterStudio() {
        let answer;

        try {
            answer = await this.requestToken();
        } catch (e) {
            answer = { status: 0, data: null, retryAfter: null };
        }

        if (this.closed) {
            return;
        }

        const data = answer.data;

        if (answer.status !== 200 || typeof data?.token !== 'string' || typeof data?.url !== 'string') {
            this.refused(tokenRefusal({ status: answer.status, error: data?.error ?? null, retryAfter: answer.retryAfter }, this.attempts));

            return;
        }

        const session = await joinRoom({ url: data.url, token: data.token });

        if (this.closed) {
            session.leave().catch(() => {});

            return;
        }

        this.session = session;
        this.connectedAt = performance.now();
        this.reconnecting = false;
        this.sessionOffs = [
            session.onParticipants((list) => this.participantsChanged(list)),
            session.onConnectionState(({ state }) => this.connectionChanged(state)),
        ];

        const room = session.room;
        room?.on?.(AUDIO_PLAYBACK_EVENT, this.onAudioPlayback);
        this.sessionOffs.push(() => room?.off?.(AUDIO_PLAYBACK_EVENT, this.onAudioPlayback));
        this.canPlaybackAudio = room?.canPlaybackAudio !== false;
        this.setState(CONNECTED);
    }

    async requestToken() {
        const response = await fetch(this.urlsValue.token, {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        let data = null;

        try {
            data = await response.json();
        } catch (e) {
            data = null;
        }

        return { status: response.status, data, retryAfter: response.headers.get('Retry-After') };
    }

    refused(outcome) {
        if (outcome.state === RETRYING) {
            this.attempts += 1;
        }

        this.setState(outcome.state);

        if (outcome.retryInMs !== null) {
            this.retryAt = Date.now() + outcome.retryInMs;
            this.retryTimer = window.setTimeout(() => this.join(), outcome.retryInMs);
        }

        this.render();
    }

    connectionChanged(state) {
        if (this.closed || this.session === null) {
            return;
        }

        // LiveKit's own reconnection after a network blip: the picture freezes meanwhile - the page says so
        if (state === 'reconnecting' || state === 'signalReconnecting' || state === 'connected') {
            this.reconnecting = state !== 'connected';
            this.render();

            return;
        }

        if (state !== 'disconnected') {
            this.render();

            return;
        }

        // LiveKit gave up reconnecting by itself, the studio closed or removed us: a new token after a wait
        const heldMs = this.connectedAt === null ? 0 : performance.now() - this.connectedAt;
        this.attempts = attemptsAfterLoss(this.attempts, heldMs);
        this.dropSession();
        this.refused({ state: RETRYING, retryInMs: retryDelayMs(this.attempts) });
    }

    retryNow() {
        this.clearRetry();
        this.join();
    }

    clearRetry() {
        window.clearTimeout(this.retryTimer);
        this.retryTimer = null;
        this.retryAt = null;
    }

    dropSession() {
        const session = this.session;
        this.session = null;
        this.connectedAt = null;
        this.reconnecting = false;
        this.sessionOffs.splice(0).forEach((off) => off());

        for (const view of this.tileViews.values()) {
            view.detach?.();
            view.element.remove();
        }

        for (const view of this.soundViews.values()) {
            view.detach?.();
            view.element.remove();
        }

        this.tileViews.clear();
        this.soundViews.clear();
        this.participants = [];
        this.studio = { tiles: [], sounds: [], seen: this.studio.seen };
        session?.leave().catch(() => {});
    }

    participantsChanged(list) {
        this.participants = Array.isArray(list) ? list : [];
        this.studio = studioTiles(this.participants, this.studio.seen, { voiceTiles: !this.obsValue });
        this.syncTiles();
        this.syncSounds();
        this.layout();
        this.render();
    }

    syncTiles() {
        const wanted = new Map(this.studio.tiles.map((tile) => [tile.key, tile]));

        for (const [key, view] of this.tileViews) {
            if (!wanted.has(key)) {
                view.detach?.();
                view.element.remove();
                this.tileViews.delete(key);
            }
        }

        this.studio.tiles.forEach((tile, index) => {
            let view = this.tileViews.get(tile.key);

            if (view === undefined) {
                view = this.createTile(tile);
                this.tileViews.set(tile.key, view);
            }

            if (tile.publication !== null && view.publication !== tile.publication) {
                view.detach?.();
                view.detach = this.session?.attach(tile.publication, view.video) ?? null;
                view.publication = tile.publication;
            }

            view.element.classList.toggle('is-muted', tile.muted || tile.source === 'voice');
            view.element.dataset.source = tile.source;
            view.element.dataset.role = tile.role;
            this.setText(view.name, tile.name);
            view.name.hidden = this.obsValue || tile.name === '';

            // In the order of the studio - moved only when out of place, a playing video is left alone
            const atIndex = this.tilesTarget.children[index] ?? null;

            if (atIndex !== view.element) {
                this.tilesTarget.insertBefore(view.element, atIndex);
            }
        });

        this.tilesTarget.dataset.count = String(this.studio.tiles.length);
    }

    createTile(tile) {
        const element = document.createElement('div');
        element.className = 'online-watch-tile';
        element.dataset.key = tile.key;

        const video = document.createElement('video');
        video.className = 'online-watch-video';
        video.autoplay = true;
        video.muted = true;
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.disablePictureInPicture = true;

        const placeholder = document.createElement('div');
        placeholder.className = 'online-watch-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.innerHTML = '<i class="bi bi-mic-fill"></i>';

        const name = document.createElement('span');
        name.className = 'online-watch-name';

        element.append(video, placeholder, name);

        return { element, video, name, publication: null, detach: null };
    }

    syncSounds() {
        const wanted = new Map(this.studio.sounds.map((sound) => [sound.key, sound]));

        for (const [key, view] of this.soundViews) {
            if (!wanted.has(key)) {
                view.detach?.();
                view.element.remove();
                this.soundViews.delete(key);
            }
        }

        for (const sound of this.studio.sounds) {
            let view = this.soundViews.get(sound.key);

            if (view === undefined) {
                const element = document.createElement('audio');
                element.autoplay = true;
                view = { element, publication: null, detach: null };
                this.soundsTarget.append(element);
                this.soundViews.set(sound.key, view);
            }

            if (view.publication !== sound.publication) {
                view.detach?.();
                view.detach = this.session?.attach(sound.publication, view.element) ?? null;
                view.publication = sound.publication;
            }

            // The listener's mute stays on the track - LiveKit applies it to the element it attaches later too
            sound.publication?.track?.setVolume?.(this.userMuted ? 0 : 1);
        }
    }

    // ---- the sound

    audioPlaybackChanged() {
        this.canPlaybackAudio = this.session?.room?.canPlaybackAudio !== false;
        this.render();
    }

    /**
     * The tap browsers want before they play sound
     */
    startSound() {
        this.userMuted = false;
        this.syncSounds();
        const started = this.session?.room?.startAudio?.();

        Promise.resolve(started).catch(() => {}).finally(() => this.audioPlaybackChanged());
    }

    toggleMute() {
        if (this.userMuted === false && this.canPlaybackAudio === false) {
            this.startSound();

            return;
        }

        this.userMuted = !this.userMuted;
        this.syncSounds();
        this.render();
    }

    // ---- what the page shows

    setState(state) {
        this.state = state;
        this.element.dataset.watchState = state;
        this.render();
    }

    tick() {
        if (this.closed) {
            return;
        }

        if (!this.obsValue) {
            this.renderClock();
        }

        if (this.retryAt !== null) {
            this.renderRetryNote();
        }

        if (Date.now() - this.lastCountAt >= COUNT_EVERY_MS) {
            this.renderCount();
        }
    }

    render() {
        if (this.closed) {
            return;
        }

        const live = this.state === CONNECTED && !this.reconnecting && isLive(this.studio);
        this.element.dataset.live = live ? 'true' : 'false';

        if (this.hasLiveTarget) {
            this.liveTarget.hidden = !live;
        }

        this.renderCurtain(live);
        this.renderSoundButtons();
        this.renderCount();

        if (!this.obsValue) {
            this.renderClock();
        }
    }

    renderCurtain(live) {
        if (!this.hasCurtainTarget) {
            return;
        }

        const key = curtainFor({ state: this.state, live, screen: this.roundView().screen, reconnecting: this.reconnecting });
        this.curtainTarget.hidden = key === null;
        this.element.dataset.curtain = key ?? '';

        if (key === null) {
            return;
        }

        this.setText(this.curtainTitleTarget, this.texts[key] ?? '');
        this.setText(this.curtainTextTarget, this.texts[`${key}_text`] ?? '');

        if (this.hasRetryButtonTarget) {
            this.retryButtonTarget.hidden = !['lost', 'full', 'unavailable'].includes(key);
        }

        if (this.hasYoutubeTarget) {
            this.youtubeTarget.hidden = !['full', 'lost'].includes(key);
        }

        this.renderRetryNote();
    }

    renderRetryNote() {
        if (!this.hasRetryNoteTarget) {
            return;
        }

        const seconds = this.state === CONNECTED || this.state === CONNECTING ? null : secondsUntil(this.retryAt, Date.now());
        this.retryNoteTarget.hidden = seconds === null;

        if (seconds !== null) {
            this.setText(this.retryNoteTarget, fill(this.texts.retry_in ?? '', { seconds }));
        }
    }

    renderSoundButtons() {
        const sounds = this.state === CONNECTED ? this.studio.sounds.length : 0;
        const ask = soundButtonWanted({ canPlaybackAudio: this.canPlaybackAudio, sounds, obs: this.obsValue });

        if (this.hasSoundButtonTarget) {
            this.soundButtonTarget.hidden = !ask;
        }

        if (this.hasMuteButtonTarget) {
            this.muteButtonTarget.hidden = ask || sounds === 0;
            const muted = this.userMuted;
            this.muteButtonTarget.setAttribute('aria-pressed', muted ? 'true' : 'false');
            this.muteButtonTarget.setAttribute('aria-label', muted ? (this.texts.unmute ?? '') : (this.texts.mute ?? ''));
            this.muteButtonTarget.title = this.muteButtonTarget.getAttribute('aria-label');
            this.muteButtonTarget.classList.toggle('is-muted', muted);
        }
    }

    renderCount() {
        this.lastCountAt = Date.now();

        if (!this.hasCountTarget) {
            return;
        }

        const room = this.session?.room ?? null;
        const count = this.state === CONNECTED ? watchingCount(room?.numParticipants, this.participants.length) : null;
        this.countTarget.hidden = count === null;
        this.element.dataset.watching = count === null ? '' : String(count);

        if (count !== null) {
            const text = chooseTranslation(this.texts.watching?.message, count, this.texts.watching?.locale ?? 'en') ?? String(count);
            this.setText(this.countTarget, text);
        }
    }

    /**
     * The tiles as large as the screen allows: OBS - the whole browser source; the page - its own width and the
     * window's height less the rest of the page (the bar, the clock). On air the stage then hugs its tiles (the mute
     * button sits on the video's corner), its height the rows they need.
     */
    layout() {
        if (!this.hasStageTarget || !this.hasTilesTarget) {
            return;
        }

        const stage = this.stageTarget;
        const gap = Number.parseFloat(getComputedStyle(this.tilesTarget).columnGap) || 0;
        let width = window.innerWidth;
        let height = window.innerHeight;

        if (!this.obsValue) {
            const page = getComputedStyle(this.element);
            const rest = Math.max(0, this.element.offsetHeight - stage.offsetHeight);
            width = this.element.clientWidth - (Number.parseFloat(page.paddingLeft) || 0) - (Number.parseFloat(page.paddingRight) || 0);
            height = Math.max(MIN_STAGE_HEIGHT, window.innerHeight - rest);
            stage.style.setProperty('--watch-available', `${Math.round(height)}px`);
        }

        const count = this.studio.tiles.length;
        const columns = gridColumns(count, width, height, { gap });
        const tile = tileWidth(count, width, height, { gap });
        const tiles = this.tilesTarget.style;
        tiles.setProperty('--watch-columns', String(columns));
        stage.toggleAttribute('data-on-air', count > 0);

        if (count === 0 || tile === null) {
            tiles.removeProperty('--watch-tile-width');
            stage.style.removeProperty('--watch-stage-height');
            stage.style.removeProperty('--watch-stage-width');

            return;
        }

        tiles.setProperty('--watch-tile-width', `${tile}px`);

        if (!this.obsValue) {
            stage.style.setProperty('--watch-stage-width', `${Math.ceil(columns * tile + (columns - 1) * gap)}px`);
            stage.style.setProperty('--watch-stage-height', `${gridHeight(count, columns, tile, { gap })}px`);
        }
    }

    // ---- the round's clock

    roundView() {
        return deriveView(this.stopwatch, this.clock.serverNow());
    }

    renderClock() {
        if (!this.hasClockTimeTarget) {
            return;
        }

        const line = clockLine(this.roundView());
        this.element.dataset.clock = line.state;
        this.setText(this.clockLabelTarget, this.texts[`clock_${line.state}`] ?? '');
        this.setText(this.clockTimeTarget, line.time ?? '');
        this.clockTimeTarget.hidden = line.time === null;

        if (this.hasPlannedTarget) {
            this.plannedTarget.hidden = line.state !== 'waiting';
        }

        if (this.hasClockLeftTarget) {
            this.clockLeftTarget.hidden = line.left === null;
            this.setText(this.clockLeftTarget, line.left === null ? '' : fill(this.texts.clock_left ?? '', { time: line.left }));
        }
    }

    adoptStopwatch(data) {
        if (data === null || typeof data !== 'object' || !('status' in data)) {
            return;
        }

        const next = newer(this.stopwatch, data);

        if (next !== this.stopwatch) {
            this.stopwatch = next;
            this.scheduleStateCheck();
            this.render();
        }
    }

    /**
     * The round's public stopwatch topic - the same updates the projector page and the phones get
     */
    startRoundStream() {
        if (!this.mercureUrlValue || !this.topicValue || typeof EventSource !== 'function') {
            this.startPolling();

            return;
        }

        let url;

        try {
            url = new URL(this.mercureUrlValue, window.location.href);
        } catch (e) {
            this.startPolling();

            return;
        }

        url.searchParams.append('topic', this.topicValue);
        this.eventSource = new EventSource(url);
        this.eventSource.addEventListener('open', () => {
            this.stopPolling();
            this.refreshStopwatch();
        });
        this.eventSource.addEventListener('message', (event) => {
            try {
                this.adoptStopwatch(JSON.parse(event.data));
            } catch (e) {
                this.refreshStopwatch();
            }
        });
        // The browser opens it again by itself - polled meanwhile
        this.eventSource.addEventListener('error', () => this.startPolling());
    }

    startPolling() {
        if (this.pollTimer !== null || this.closed) {
            return;
        }

        this.pollTimer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.refreshStopwatch();
            }
        }, STATE_POLL_MS);
    }

    stopPolling() {
        window.clearInterval(this.pollTimer);
        this.pollTimer = null;
    }

    async refreshStopwatch() {
        if (this.closed || !this.urlsValue.state) {
            return;
        }

        try {
            const t0 = performance.now();
            const response = await fetch(this.urlsValue.state, { cache: 'no-store', headers: { Accept: 'application/json' } });
            const t1 = performance.now();

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            if (Number.isFinite(data?.serverNowMs)) {
                this.clock.observe({ t0, t1, serverNowMs: data.serverNowMs });
            }

            this.adoptStopwatch(data);
        } catch (e) {
            // The next push, poll or return to the page tries again
        }
    }

    /**
     * A fetch just before and after the start and after the time limit - a missed push is caught right there
     */
    scheduleStateCheck() {
        window.clearTimeout(this.stateCheckTimer);

        if (this.closed || this.obsValue) {
            return;
        }

        const now = this.clock.serverNow();
        const at = nextStateCheckAt(this.stopwatch, now);

        if (at === null) {
            return;
        }

        this.stateCheckTimer = window.setTimeout(() => {
            this.refreshStopwatch();
            this.scheduleStateCheck();
        }, Math.max(0, Math.min(at - now, 2 ** 31 - 1)));
    }

    visibilityChanged() {
        if (document.visibilityState !== 'visible' || this.closed) {
            return;
        }

        if (!this.obsValue) {
            this.refreshStopwatch();
        }

        this.render();
    }

    setText(element, text) {
        if (element && element.textContent !== text) {
            element.textContent = text;
        }
    }
}
