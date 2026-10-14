/**
 * The control room's live video (docs/features/online-competitions/README.md §5.12 "The moderator console", contract
 * phase-1b.md stream VM) - loaded by assets/controllers/online_control_controller.js only when the page has live video
 * (a media server configured and the round's video on: `data-online-control-video-value`), its own chunk; livekit-client
 * is another one again, loaded when the hall is joined (livekit_session.js).
 *
 * - The moderator joins the hall (`online_video_token`, room `hall`) as `m:{playerId}`, subscribing to nothing by
 *   itself: the grid receives the puzzle cameras of its page only, at the 180p layer, and the spotlight's at the top
 *   layer (console_grid.js subscriptionPlan()) - a camera nobody watches is not sent.
 * - The grid is the control room's own (one tile per participant, its status chips): finished first, the newest tap on
 *   top, PAGE_SIZE tiles a page; each tile gets the camera, a badge saying why there is none, a spotlight button
 *   (click / Enter; ← → step through, Esc closes) and the studio's invitation.
 * - Push-to-talk into the hall: the microphone is turned on by a click and published muted, with every participant
 *   allowed to hear it (`allowSubscribers: 'all'`, contract §8); it is open only while the button or the Space key is
 *   held, or while "Keep talking" is on (console_talk.js), with an on-air indicator.
 * - "Invite to the studio" on every finisher (`online_studio_invite`), the invitation's state on the tile, "Withdraw" /
 *   "Thank you" (`online_studio_end`); the states come from those answers and from the studio's JSON (`online_studio`),
 *   read often while an invitation is open, rarely otherwise, and at once when a participant's camera leaves or comes
 *   back to the hall (their phone moves to the studio) - console_invites.js.
 *
 * Nothing here touches the control room's own work: a media server that fails leaves the clock, the polls and the grid
 * as they are.
 */

import { SOURCE_MICROPHONE } from './livekit_session.js';
import { dimensionsFor, orderTiles, PAGE_SIZE, pageOf, pageWith, stepSpotlight, subscriptionPlan, tileVideoState } from './console_grid.js';
import { formatOnAir, INITIAL_TALK, isOnAir, spaceTalks, talkReducer } from './console_talk.js';
import { formatRemaining, inviteView } from './console_invites.js';
import { endInvite, RoomLink, sendInvite, StudioInvites, studioRefusal } from './console_room.js';

// How long a refused invitation's message stays on its tile
const INVITE_ERROR_MS = 8000;

const STATE_CHIP = {
    invited: 'warn',
    joined: 'info',
    on_air: 'bad',
    declined: '',
    expired: '',
    ended: 'ok',
    withdrawn: '',
};

export class ConsoleVideo {
    /**
     * @param {object} options
     * @param {HTMLElement} options.element      the control room (the controller's element)
     * @param {HTMLElement} options.grid         the grid of tiles
     * @param {object} options.config            data-online-control-video-value: {urls: {token, invite, end, studio}, placeholder, csrfToken, pageSize}
     * @param {function(string, object=): string} options.t
     * @param {function(): number} options.serverNow
     * @param {function(): void} options.render  draws the grid again (the controller's renderGrid())
     */
    constructor({ element, grid, config, t, serverNow, render }) {
        this.element = element;
        this.grid = grid;
        this.config = config && typeof config === 'object' ? config : {};
        this.urls = this.config.urls ?? {};
        this.pageSize = Number.isInteger(this.config.pageSize) && this.config.pageSize > 0 ? this.config.pageSize : PAGE_SIZE;
        this.t = t;
        this.serverNow = serverNow;
        this.render = render;

        this.session = null;
        this.connection = 'connecting';
        this.closed = false;
        this.roomOffs = [];
        this.offs = [];
        this.timers = { onAir: null };

        // The hall: participant id → their camera device's entry (livekit_session.js participantsOf()); the moderators
        this.cameras = new Map();
        this.moderators = [];

        // The grid as last drawn: everybody in the console's order, the page's ids, the tiles by participant
        this.orderedIds = [];
        this.pageIds = [];
        this.pageInfo = pageOf([], 0, this.pageSize);
        this.page = 0;
        this.items = new Map();
        this.tiles = new Map();
        this.spotlightId = null;

        this.talk = INITIAL_TALK;
        this.mic = null;
        this.micApplying = false;
        this.onAirSince = null;

        this.invites = new Map();
        this.busyInvites = new Set();
        this.inviteErrors = new Map();
    }

    // ---------------------------------------------------------------- life

    start() {
        const part = (name) => this.element.querySelector(`[data-online-console="${name}"]`);

        this.panel = {
            connection: part('connection'),
            reconnect: part('reconnect'),
            micEnable: part('micEnable'),
            hold: part('hold'),
            latch: part('latch'),
            onAir: part('onAir'),
            onAirTime: part('onAirTime'),
            talkError: part('talkError'),
            othersTalking: part('othersTalking'),
        };

        this.buildSpotlight();
        this.buildPager();
        this.bindTalk();

        this.link = new RoomLink({
            tokenUrl: this.urls.token,
            room: 'hall',
            csrfToken: this.config.csrfToken,
            joinOptions: {
                // The host's voice reaches every participant (contract §8)
                allowSubscribers: 'all',
                // Only the cameras the grid shows are received (applySubscriptions())
                connectOptions: { autoSubscribe: false },
            },
            onSession: (session) => this.joined(session),
            onLost: () => this.lost(),
            onStatus: (status) => this.setConnection(status),
        });
        this.studio = new StudioInvites({
            url: this.urls.studio,
            serverNow: this.serverNow,
            onChange: (invites) => {
                this.invites = invites;
                this.render();
            },
        });

        this.listen(this.element, 'click', (event) => this.clicked(event));
        this.listen(this.panel.reconnect, 'click', () => this.link.reconnectNow());
        this.listen(document, 'keydown', (event) => this.keyDown(event));
        this.listen(document, 'keyup', (event) => this.keyUp(event));
        this.listen(document, 'visibilitychange', () => {
            if (document.visibilityState !== 'visible') {
                this.dispatchTalk({ type: 'release_all' });
            }
        });
        this.listen(window, 'blur', () => this.dispatchTalk({ type: 'release_all' }));

        this.renderConnection();
        this.renderTalk();
        this.link.connect();
        this.studio.start();
    }

    close() {
        this.closed = true;
        window.clearInterval(this.timers.onAir);
        this.offs.splice(0).forEach((off) => off());
        this.studio?.close();
        // Lets go of the tiles, the spotlight and the microphone (lost()), then leaves the hall
        this.link?.close();
    }

    listen(target, type, listener, options) {
        if (!target) {
            return;
        }

        target.addEventListener(type, listener, options);
        this.offs.push(() => target.removeEventListener(type, listener, options));
    }

    // ---------------------------------------------------------------- the hall

    joined(session) {
        this.session = session;
        this.roomOffs.push(session.onParticipants((list) => this.roomChanged(list)));
        this.render();
    }

    /**
     * The hall's session is gone (dropped, or the page leaves): its videos, the spotlight's and the microphone with it
     */
    lost() {
        this.roomOffs.splice(0).forEach((off) => off());

        for (const tile of this.tiles.values()) {
            this.detachTile(tile);
        }

        this.detachSpotlight();
        this.dropMicrophone();
        this.session = null;
        this.cameras = new Map();
        this.moderators = [];
        this.renderOthersTalking();

        if (!this.closed) {
            this.render();
        }
    }

    setConnection(connection) {
        this.connection = connection;
        this.renderConnection();
        this.renderTalk();

        for (const tile of this.tiles.values()) {
            this.syncTileVideo(tile);
        }
    }

    roomChanged(list) {
        if (this.closed) {
            return;
        }

        const cameras = new Map();
        const moderators = [];

        for (const entry of Array.isArray(list) ? list : []) {
            if (entry.kind === 'participant' && typeof entry.id === 'string') {
                cameras.set(entry.id, entry);
            } else if (entry.kind === 'moderator') {
                moderators.push(entry);
            }
        }

        // A camera device left the hall or came back: an invited participant's phone moving to the studio, or back
        const moved = [...cameras.keys()].filter((id) => !this.cameras.has(id))
            .concat([...this.cameras.keys()].filter((id) => !cameras.has(id)));

        this.cameras = cameras;
        this.moderators = moderators;

        if (moved.some((id) => this.invites.has(id))) {
            this.studio.hint();
        }

        this.renderOthersTalking();
        this.render();
    }

    /**
     * The cameras received: the page's small, the spotlight's large, every other one not at all
     */
    applySubscriptions() {
        if (this.session === null) {
            return;
        }

        const plan = subscriptionPlan(this.pageIds, this.spotlightId);

        for (const [participantId, entry] of this.cameras) {
            const publication = entry.camera;

            if (!publication) {
                continue;
            }

            const layer = plan.get(participantId);

            if (layer !== undefined) {
                if (publication.isDesired !== true) {
                    publication.setSubscribed(true);
                }

                publication.setVideoDimensions(dimensionsFor(layer));
            } else if (publication.isDesired === true) {
                publication.setSubscribed(false);
            }
        }
    }

    // ---------------------------------------------------------------- the grid (hooks of the controller)

    /**
     * The tiles to draw, in the console's order: one page of them
     *
     * @param {Array<{participant: object, view: object}>} shown the filtered tiles, in online_live's order
     */
    arrange(shown) {
        const ordered = orderTiles(shown);
        this.orderedIds = ordered.map((item) => item.participant.participantId);
        this.items = new Map(ordered.map((item) => [item.participant.participantId, item]));

        if (this.spotlightId !== null && !this.items.has(this.spotlightId)) {
            // Filtered out or gone
            this.closeSpotlight(false);
        }

        this.pageInfo = pageOf(ordered, this.page, this.pageSize);
        this.page = this.pageInfo.page;
        this.pageIds = this.pageInfo.items.map((item) => item.participant.participantId);

        return this.pageInfo.items;
    }

    updateTile(tile, participant, view) {
        if (!tile.console) {
            this.decorate(tile, participant);
        }

        tile.console.view = view;
        this.tiles.set(participant.participantId, tile);
        this.syncTileVideo(tile);
        this.renderStudio(tile.console.studio, participant.participantId, view, tile.console);
    }

    dropTile(tile) {
        this.detachTile(tile);

        if (tile.console && this.tiles.get(tile.console.participantId) === tile) {
            this.tiles.delete(tile.console.participantId);
        }
    }

    afterGrid() {
        this.applySubscriptions();

        for (const tile of this.tiles.values()) {
            this.syncTileVideo(tile);
        }

        this.renderPager();
        this.renderSpotlight();
    }

    decorate(tile, participant) {
        const photo = tile.element.querySelector('.online-tile-photo');
        const body = tile.element.querySelector('.online-tile-body');

        const video = document.createElement('video');
        video.className = 'online-tile-video';
        video.muted = true;
        video.playsInline = true;
        video.autoplay = true;
        video.setAttribute('aria-hidden', 'true');

        const badge = document.createElement('span');
        badge.className = 'online-tile-video-state';

        const spotlight = document.createElement('button');
        spotlight.type = 'button';
        spotlight.className = 'online-tile-spotlight';
        spotlight.dataset.consoleAction = 'spotlight';
        spotlight.dataset.participantId = participant.participantId;
        spotlight.setAttribute('aria-label', this.t('video_tile_spotlight', { name: participant.name }));
        const icon = document.createElement('i');
        icon.className = 'bi bi-arrows-fullscreen';
        icon.setAttribute('aria-hidden', 'true');
        spotlight.append(icon);

        photo?.append(video, badge, spotlight);

        const studio = document.createElement('div');
        studio.className = 'online-tile-studio';
        body?.append(studio);

        tile.element.dataset.video = 'connecting';
        tile.console = {
            participantId: participant.participantId,
            name: participant.name,
            video,
            badge,
            spotlight,
            studio,
            track: null,
            detach: null,
            playing: false,
            state: null,
            studioKey: null,
            view: null,
        };

        video.addEventListener('playing', () => {
            tile.console.playing = true;
            this.syncTileVideo(tile);
        });
        video.addEventListener('emptied', () => {
            tile.console.playing = false;
            this.syncTileVideo(tile);
        });
    }

    syncTileVideo(tile) {
        const c = tile.console;

        if (!c) {
            return;
        }

        const connected = this.session !== null;
        const entry = connected ? (this.cameras.get(c.participantId) ?? null) : null;
        const onPage = this.pageIds.includes(c.participantId);
        const track = onPage && entry?.camera?.track ? entry.camera.track : null;

        if (track !== c.track) {
            this.detachTile(tile);

            if (track !== null) {
                c.detach = this.session.attach(entry.camera, c.video);
                c.track = track;
            }
        }

        // No video here for good (another tab took over, video turned off, signed out): the panel says why, the tiles
        // say nothing
        const state = this.videoGone()
            ? 'off'
            : tileVideoState({ connected, entry, playing: c.playing && c.track !== null, inStudio: this.inStudio(c.participantId) });

        if (c.state !== state) {
            c.state = state;
            tile.element.dataset.video = state;
            c.badge.textContent = state === 'off' ? '' : this.t(`video_tile_${state}`);
            c.badge.className = `online-tile-video-state online-tile-video-state--${state}`;
            c.badge.hidden = state === 'off';
        }
    }

    /**
     * The hall will not come back by itself: another tab took over, the round's video was turned off, signed out
     */
    videoGone() {
        return ['duplicate', 'unavailable', 'sign_in', 'forbidden'].includes(this.connection);
    }

    /**
     * Their phone is in the studio: a joined invitation (console_invites.js)
     */
    inStudio(participantId) {
        return inviteView(this.invites.get(participantId) ?? null, this.serverNow(), false).status === 'joined';
    }

    detachTile(tile) {
        const c = tile?.console;

        if (!c) {
            return;
        }

        if (c.detach) {
            try {
                c.detach();
            } catch (e) {
                // Already detached with its track
            }
        }

        c.detach = null;
        c.track = null;
        c.playing = false;
    }

    // ---------------------------------------------------------------- the pager

    buildPager() {
        const nav = document.createElement('nav');
        nav.className = 'online-pager';
        nav.setAttribute('aria-label', this.t('video_pager_label'));
        nav.hidden = true;

        const previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'btn btn-sm btn-outline-secondary';
        previous.dataset.consoleAction = 'page-previous';
        previous.textContent = `‹ ${this.t('video_pager_previous')}`;

        const label = document.createElement('span');
        label.className = 'online-pager-label small';
        label.setAttribute('aria-live', 'polite');

        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'btn btn-sm btn-outline-secondary';
        next.dataset.consoleAction = 'page-next';
        next.textContent = `${this.t('video_pager_next')} ›`;

        nav.append(previous, label, next);
        this.grid.after(nav);
        this.pager = { nav, previous, label, next };
    }

    renderPager() {
        const info = this.pageInfo;
        const pager = this.pager;

        if (!pager) {
            return;
        }

        pager.nav.hidden = info.pages <= 1;
        pager.previous.disabled = info.page === 0;
        pager.next.disabled = info.page >= info.pages - 1;

        const label = `${this.t('video_pager_page', { page: info.page + 1, pages: info.pages })} · ${this.t('video_pager_range', { from: info.from, to: info.to, total: info.total })}`;

        if (pager.label.textContent !== label) {
            pager.label.textContent = label;
        }
    }

    goToPage(page) {
        this.page = Math.max(0, page);
        this.render();
        this.grid.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
    }

    // ---------------------------------------------------------------- the spotlight

    buildSpotlight() {
        const section = document.createElement('section');
        section.className = 'online-spotlight';
        section.hidden = true;
        section.tabIndex = -1;
        section.setAttribute('aria-label', this.t('video_spotlight_title'));

        const stage = document.createElement('div');
        stage.className = 'online-spotlight-stage';
        const video = document.createElement('video');
        video.className = 'online-spotlight-video';
        video.muted = true;
        video.playsInline = true;
        video.autoplay = true;
        video.setAttribute('aria-hidden', 'true');
        const badge = document.createElement('span');
        badge.className = 'online-tile-video-state';
        stage.append(video, badge);

        const side = document.createElement('div');
        side.className = 'online-spotlight-side';

        const head = document.createElement('div');
        head.className = 'online-spotlight-head';
        const name = document.createElement('h3');
        name.className = 'h5 mb-0 online-spotlight-name';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.dataset.consoleAction = 'spotlight-close';
        close.setAttribute('aria-label', this.t('video_spotlight_close'));
        head.append(name, close);

        const chips = document.createElement('ul');
        chips.className = 'online-tile-chips list-unstyled';
        const finish = document.createElement('p');
        finish.className = 'online-tile-finish';
        const studio = document.createElement('div');
        studio.className = 'online-tile-studio';

        const nav = document.createElement('div');
        nav.className = 'online-spotlight-nav';
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'btn btn-sm btn-outline-secondary';
        previous.dataset.consoleAction = 'spotlight-previous';
        previous.textContent = '‹';
        previous.setAttribute('aria-label', this.t('video_spotlight_previous'));
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'btn btn-sm btn-outline-secondary';
        next.dataset.consoleAction = 'spotlight-next';
        next.textContent = '›';
        next.setAttribute('aria-label', this.t('video_spotlight_next'));
        const keys = document.createElement('span');
        keys.className = 'small text-body-secondary';
        keys.textContent = this.t('video_spotlight_keys');
        nav.append(previous, next, keys);

        side.append(head, chips, finish, studio, nav);
        section.append(stage, side);
        this.grid.before(section);

        this.spot = { section, video, badge, name, chips, finish, studio, close, previous, next, track: null, detach: null, playing: false, studioKey: null, opener: null };

        video.addEventListener('playing', () => {
            this.spot.playing = true;
            this.renderSpotlight();
        });
        video.addEventListener('emptied', () => {
            this.spot.playing = false;
            this.renderSpotlight();
        });
    }

    openSpotlight(participantId, opener = null) {
        if (!this.items.has(participantId)) {
            return;
        }

        const wasClosed = this.spotlightId === null;
        this.spotlightId = participantId;
        this.spot.opener = opener ?? this.spot.opener;
        this.page = pageWith(this.orderedIds, participantId, this.pageSize) ?? this.page;
        this.render();

        if (wasClosed) {
            this.spot.section.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
            this.spot.close.focus({ preventScroll: true });
        }
    }

    closeSpotlight(render = true) {
        const id = this.spotlightId;

        if (id === null) {
            return;
        }

        const opener = this.spot.opener;
        this.spotlightId = null;
        this.spot.opener = null;
        this.detachSpotlight();
        this.spot.section.hidden = true;

        if (render) {
            this.render();
            // Back where the spotlight was opened from - or to the camera's own tile
            (opener?.isConnected ? opener : this.tiles.get(id)?.console?.spotlight)?.focus({ preventScroll: true });
        }
    }

    detachSpotlight() {
        if (!this.spot) {
            return;
        }

        if (this.spot.detach) {
            try {
                this.spot.detach();
            } catch (e) {
                // Already detached with its track
            }
        }

        this.spot.detach = null;
        this.spot.track = null;
        this.spot.playing = false;
    }

    renderSpotlight() {
        const spot = this.spot;
        const id = this.spotlightId;

        if (!spot) {
            return;
        }

        if (id === null) {
            spot.section.hidden = true;

            return;
        }

        const item = this.items.get(id);
        const tile = this.tiles.get(id);
        const connected = this.session !== null;
        const entry = connected ? (this.cameras.get(id) ?? null) : null;
        const track = entry?.camera?.track ?? null;

        spot.section.hidden = false;
        spot.section.dataset.participantId = id;

        if (track !== spot.track) {
            this.detachSpotlight();

            if (track !== null) {
                spot.detach = this.session.attach(entry.camera, spot.video);
                spot.track = track;
            }
        }

        const state = tileVideoState({ connected, entry, playing: spot.playing && spot.track !== null, inStudio: this.inStudio(id) });
        spot.section.dataset.video = state;
        spot.badge.textContent = this.t(`video_tile_${state}`);
        spot.badge.className = `online-tile-video-state online-tile-video-state--${state}`;

        const name = item?.participant?.name ?? '';

        if (spot.name.textContent !== name) {
            spot.name.textContent = name;
        }

        // The tile's own chips and finish line, as the control room draws them
        if (tile) {
            spot.chips.replaceChildren(...[...tile.chips.children].map((node) => node.cloneNode(true)));
            spot.finish.textContent = tile.finish.textContent;
            spot.finish.className = tile.finish.className;
            spot.finish.hidden = tile.finish.hidden;
        }

        spot.previous.disabled = this.orderedIds[0] === id;
        spot.next.disabled = this.orderedIds[this.orderedIds.length - 1] === id;
        this.renderStudio(spot.studio, id, item?.view ?? null, spot);
    }

    // ---------------------------------------------------------------- clicks and keys

    clicked(event) {
        const button = event.target.closest?.('[data-console-action]');

        if (!button || !this.element.contains(button)) {
            return;
        }

        const action = button.dataset.consoleAction;
        const participantId = button.dataset.participantId ?? this.spotlightId;

        switch (action) {
            case 'spotlight':
                this.openSpotlight(participantId, button);
                break;
            case 'spotlight-close':
                this.closeSpotlight();
                break;
            case 'spotlight-previous':
                this.stepSpotlight(-1);
                break;
            case 'spotlight-next':
                this.stepSpotlight(1);
                break;
            case 'page-previous':
                this.goToPage(this.page - 1);
                break;
            case 'page-next':
                this.goToPage(this.page + 1);
                break;
            case 'invite':
                this.invite(participantId);
                break;
            case 'end':
                this.end(participantId);
                break;
            default:
                break;
        }
    }

    stepSpotlight(step) {
        const next = stepSpotlight(this.orderedIds, this.spotlightId, step);

        if (next !== null && next !== this.spotlightId) {
            this.openSpotlight(next);
        }
    }

    keyDown(event) {
        if (spaceTalks(event) && this.talk.mic === 'ready') {
            // Never a page scroll, a click on the focused button or a radio's toggle - only talking
            event.preventDefault();

            if (!event.repeat) {
                this.dispatchTalk({ type: 'press', source: 'space' });
            }

            return;
        }

        if (this.spotlightId === null || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const tag = String(event.target?.tagName ?? '').toUpperCase();

        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || event.target?.isContentEditable) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            this.closeSpotlight();
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            this.stepSpotlight(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            this.stepSpotlight(1);
        }
    }

    keyUp(event) {
        if (this.talk.holders.includes('space') && (event.code === 'Space' || event.key === ' ')) {
            event.preventDefault();
            this.dispatchTalk({ type: 'release', source: 'space' });
        }
    }

    // ---------------------------------------------------------------- push-to-talk

    bindTalk() {
        const { micEnable, hold, latch } = this.panel;

        this.listen(micEnable, 'click', () => this.enableMicrophone());
        this.listen(latch, 'click', () => this.dispatchTalk({ type: 'latch' }));
        this.listen(hold, 'pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();
            hold.setPointerCapture?.(event.pointerId);
            this.dispatchTalk({ type: 'press', source: 'pointer' });
        });

        for (const type of ['pointerup', 'pointercancel', 'lostpointercapture']) {
            this.listen(hold, type, () => this.dispatchTalk({ type: 'release', source: 'pointer' }));
        }

        // A press is a hold, never a click; a long touch never opens a menu
        this.listen(hold, 'click', (event) => event.preventDefault());
        this.listen(hold, 'contextmenu', (event) => event.preventDefault());
    }

    async enableMicrophone() {
        if (this.session === null || this.connection !== 'connected') {
            this.dispatchTalk({ type: 'failed', reason: 'not_connected' });

            return;
        }

        this.dispatchTalk({ type: 'enable' });

        if (this.talk.mic !== 'starting') {
            return;
        }

        let track = null;

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                video: false,
            });
            track = stream.getAudioTracks()[0] ?? null;

            if (track === null) {
                throw Object.assign(new Error('No microphone track'), { name: 'NotFoundError' });
            }

            // Silent from the first moment - the publishing included
            track.enabled = false;

            if (this.closed || this.session === null) {
                track.stop();

                return;
            }

            const publication = await this.session.publish(track, { source: SOURCE_MICROPHONE });
            await publication.mute();
            this.mic = { track, publication };
            track.addEventListener('ended', () => {
                // Unplugged, or taken away by the browser
                this.dropMicrophone();
                this.dispatchTalk({ type: 'failed', reason: 'mic_failed' });
            });
            this.dispatchTalk({ type: 'enabled' });
        } catch (e) {
            track?.stop();

            let reason = 'mic_failed';

            if (e?.name === 'NotAllowedError' || e?.name === 'SecurityError') {
                reason = 'mic_denied';
            } else if (e?.name === 'NotFoundError' || e?.name === 'OverconstrainedError') {
                reason = 'mic_missing';
            }

            this.dispatchTalk({ type: 'failed', reason });
        }
    }

    dropMicrophone() {
        const mic = this.mic;
        this.mic = null;

        if (mic !== null) {
            if (this.session !== null) {
                this.session.unpublish(mic.track).catch(() => {});
            }

            mic.track.stop();
        }

        if (this.talk.mic !== 'error') {
            this.dispatchTalk({ type: 'off' });
        }
    }

    dispatchTalk(event) {
        const before = this.talk;
        this.talk = talkReducer(this.talk, event);

        if (this.talk === before) {
            return;
        }

        const wasOnAir = isOnAir(before);
        const onAir = isOnAir(this.talk);

        if (onAir && !wasOnAir) {
            this.onAirSince = Date.now();
            window.clearInterval(this.timers.onAir);
            this.timers.onAir = window.setInterval(() => this.renderOnAirTime(), 250);
        } else if (!onAir && wasOnAir) {
            this.onAirSince = null;
            window.clearInterval(this.timers.onAir);
        }

        this.applyMicrophone();
        this.renderTalk();
    }

    /**
     * The microphone follows the state - one mute / unmute at a time, until it is where the state wants it
     */
    async applyMicrophone() {
        if (this.micApplying) {
            return;
        }

        this.micApplying = true;

        try {
            for (let guard = 0; guard < 10; guard++) {
                const publication = this.mic?.publication;

                if (!publication) {
                    break;
                }

                const wanted = isOnAir(this.talk);

                if ((publication.isMuted === true) !== wanted) {
                    break;
                }

                await (wanted ? publication.unmute() : publication.mute());
            }
        } catch (e) {
            // The connection went - connectionChanged() drops the microphone
        } finally {
            this.micApplying = false;
        }

        // Never live by mistake: a track not on air is silent
        if (this.mic !== null && !isOnAir(this.talk)) {
            this.mic.track.enabled = false;
        }
    }

    renderTalk() {
        const panel = this.panel;

        if (!panel) {
            return;
        }

        const { mic, latched, error, holders } = this.talk;
        const onAir = isOnAir(this.talk);
        const usable = this.connection === 'connected';

        if (panel.micEnable) {
            panel.micEnable.hidden = mic === 'ready';
            panel.micEnable.disabled = mic === 'starting' || !usable;
            panel.micEnable.textContent = this.t(mic === 'starting' ? 'video_talk_starting' : 'video_talk_enable');
        }

        if (panel.hold) {
            panel.hold.hidden = mic !== 'ready';
            panel.hold.classList.toggle('is-held', holders.length > 0);
            panel.hold.setAttribute('aria-pressed', holders.length > 0 ? 'true' : 'false');
        }

        if (panel.latch) {
            panel.latch.hidden = mic !== 'ready';
            panel.latch.setAttribute('aria-pressed', latched ? 'true' : 'false');
            panel.latch.textContent = this.t(latched ? 'video_talk_latch_on' : 'video_talk_latch');
            panel.latch.classList.toggle('btn-danger', latched);
            panel.latch.classList.toggle('btn-outline-danger', !latched);
        }

        if (panel.onAir) {
            panel.onAir.hidden = !onAir;
        }

        this.element.querySelector('.online-console')?.classList.toggle('is-on-air', onAir);

        if (panel.talkError) {
            panel.talkError.hidden = error === null;
            panel.talkError.textContent = error === null ? '' : this.t(`video_talk_error_${error}`);
        }

        this.renderOnAirTime();
    }

    renderOnAirTime() {
        if (!this.panel?.onAirTime) {
            return;
        }

        const text = this.onAirSince === null ? '' : formatOnAir(Date.now() - this.onAirSince);

        if (this.panel.onAirTime.textContent !== text) {
            this.panel.onAirTime.textContent = text;
        }
    }

    renderOthersTalking() {
        const box = this.panel?.othersTalking;

        if (!box) {
            return;
        }

        const names = this.moderators
            .filter((entry) => entry.microphone && entry.microphone.isMuted !== true)
            .map((entry) => entry.name || entry.identity);
        const text = names.length === 0 ? '' : this.t('video_talk_others', { name: names.join(', ') });

        box.hidden = text === '';

        if (box.textContent !== text) {
            box.textContent = text;
        }
    }

    renderConnection() {
        const badge = this.panel?.connection;

        if (!badge) {
            return;
        }

        const kind = {
            connected: 'text-bg-success',
            connecting: 'text-bg-secondary',
            reconnecting: 'text-bg-warning',
            failed: 'text-bg-danger',
            duplicate: 'text-bg-danger',
            unavailable: 'text-bg-secondary',
            sign_in: 'text-bg-danger',
            forbidden: 'text-bg-danger',
        }[this.connection] ?? 'text-bg-secondary';

        badge.textContent = this.t(`video_connection_${this.connection}`);
        badge.className = `badge ${kind}`;

        if (this.panel.reconnect) {
            this.panel.reconnect.hidden = this.connection !== 'duplicate';
        }
    }

    // ---------------------------------------------------------------- the studio's invitations

    renderStudio(container, participantId, view, holder) {
        if (!container) {
            return;
        }

        const invite = this.invites.get(participantId) ?? null;
        const serverNow = this.serverNow();
        const state = inviteView(invite, serverNow, view?.finished === true);
        const busy = this.busyInvites.has(participantId);
        const error = this.inviteErrors.get(participantId) ?? null;
        const errorText = error !== null && error.until > Date.now() ? error.text : '';
        const status = state.status === 'joined' && state.onAir ? 'on_air' : state.status;
        const remaining = state.status === 'invited' ? formatRemaining(state.remainingMs) : '';
        const chipText = status === null ? '' : this.t(`video_studio_${status}`, { time: remaining });
        const key = JSON.stringify([status, state.canInvite, state.canEnd, busy, errorText]);

        if (holder.studioKey === key) {
            // Only the countdown moves: its text, never new buttons under a pointer about to click them
            if (holder.studioChip && holder.studioChip.textContent !== chipText) {
                holder.studioChip.textContent = chipText;
            }

            return;
        }

        holder.studioKey = key;
        holder.studioChip = null;
        const nodes = [];

        if (status !== null) {
            const chip = document.createElement('span');
            chip.className = `online-chip online-chip--${STATE_CHIP[status] || 'muted'} online-studio-chip`;
            chip.dataset.studio = status;
            chip.textContent = chipText;
            holder.studioChip = chip;
            nodes.push(chip);
        }

        if (state.canInvite) {
            nodes.push(this.studioButton('invite', participantId, this.t(status === null ? 'video_studio_invite' : 'video_studio_invite_again'), 'btn-outline-primary', busy, 'bi-camera-reels'));
        }

        if (state.canEnd) {
            const thanks = state.status === 'joined';
            nodes.push(this.studioButton('end', participantId, this.t(thanks ? 'video_studio_thank_you' : 'video_studio_withdraw'), thanks ? 'btn-success' : 'btn-outline-secondary', busy, thanks ? 'bi-hand-thumbs-up' : 'bi-x-lg'));
        }

        if (errorText !== '') {
            const message = document.createElement('span');
            message.className = 'online-studio-error small';
            message.setAttribute('role', 'status');
            message.textContent = errorText;
            nodes.push(message);
        }

        container.replaceChildren(...nodes);
        container.hidden = nodes.length === 0;
    }

    studioButton(action, participantId, label, style, busy, icon) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-sm ${style}`;
        button.dataset.consoleAction = action;
        button.dataset.participantId = participantId;
        button.disabled = busy;

        const symbol = document.createElement('i');
        symbol.className = `bi ${icon} me-1`;
        symbol.setAttribute('aria-hidden', 'true');
        button.append(symbol, document.createTextNode(busy ? this.t('video_studio_working') : label));

        return button;
    }

    async invite(participantId) {
        if (typeof participantId !== 'string' || this.busyInvites.has(participantId)) {
            return;
        }

        this.busyInvites.add(participantId);
        this.inviteErrors.delete(participantId);
        this.render();

        this.studioAnswered(participantId, await sendInvite({ url: this.urls.invite, csrfToken: this.config.csrfToken }, participantId));
    }

    async end(participantId) {
        const invite = typeof participantId === 'string' ? this.invites.get(participantId) : null;

        if (!invite || this.busyInvites.has(participantId)) {
            return;
        }

        this.busyInvites.add(participantId);
        this.inviteErrors.delete(participantId);
        this.render();

        this.studioAnswered(participantId, await endInvite({ url: this.urls.end, placeholder: this.config.placeholder, csrfToken: this.config.csrfToken }, invite));
    }

    studioAnswered(participantId, answer) {
        this.busyInvites.delete(participantId);

        if (this.closed) {
            return;
        }

        if (answer.kind !== 'ok') {
            this.inviteErrors.set(participantId, { text: this.t(`video_studio_error_${studioRefusal(answer)}`), until: Date.now() + INVITE_ERROR_MS });
            window.setTimeout(() => this.render(), INVITE_ERROR_MS + 50);
        }

        // The invitation as it is now - also with `already_invited`, which carries the open one
        if (answer.data?.invite) {
            this.studio.merge(answer.data.invite);
        } else {
            this.render();
        }

        this.studio.hint();
    }

}
