/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { createClock, fetchServerTime } from '../online/clock_sync.js';
import {
    adoptRoundClock,
    adoptState,
    countdownDigit,
    deriveView,
    formatClock,
    nextStateCheckAt,
    readClock,
    resultSeconds,
} from '../online/round_state.js';
import { createBeforeUnloadHandler, openEventOutbox, uuidv7 } from '../online/event_outbox.js';
import { openMediaOutbox } from '../online/media_outbox.js';
import { BURST_CONTENT_TYPE, createFrameBuffer } from '../online/frame_buffer.js';
import { Camera } from '../online/camera.js';
import { ScreenWakeLock } from '../online/wake_lock.js';
import { Beeps } from '../online/beeps.js';
import { FrameGrabber } from '../online/frame_grabber.js';
// The user-agent string for the review's diagnostics, read where every platform read is (docs/features/navigation)
import { browserDiagnostics } from '../platform.js';
import { createCompeteApi, isDeadLink } from '../online/compete_api.js';
import {
    answerReach,
    buildEvent,
    cameraWanted,
    checklistRows,
    CONNECTION_FAILURES_SHOWN,
    connectionStatus,
    clockText,
    countdownText,
    currentFinish,
    deviceIdFrom,
    fill,
    GO_SHOWN_MS,
    loadConsent,
    loadProgress,
    memoryStorage,
    nextDisplayChangeAt,
    nextPeriodicPhotoAt,
    PRACTICE_MINUTES,
    practiceAllowed,
    practiceStartAt,
    practiceState,
    readiness,
    readPieces,
    runKeyOf,
    saveConsent,
    saveProgress,
    screenFor,
    shortClock,
    TAKEOVER_GRACE_MS,
    takeoverVerdict,
    undoLeftMs,
    wakeTapNeeded,
} from '../online/compete_flow.js';
import { OfficialResultsEvents } from '../official_results_events.js';
import { chooseTranslation } from '../translation_choice.js';

/**
 * The participant's page of an online round (templates/compete/page.html.twig, docs/features/online-competitions/
 * README.md §5.2-§5.6, §5.10) - a thin shell over the plain modules in assets/online/:
 *
 * - clock: the server's time (clock_sync.js) from `/-/time` and every API answer; synced at load, on every return to
 *   the page, when a start is learned and right after it;
 * - state: the first one from the page, then the round's Mercure stream (official_results_events.js) and fetches - on
 *   (re)connect, on return, 2 s before / 1 s after the start, and whenever a heartbeat's hash or round differs; the
 *   newest wins by asOfMs (round_state.js adoptState / adoptRoundClock);
 * - heartbeat every 15 s: device, camera, clock quality, visibility, outbox counts; `takenOverBy` = another device -
 *   this one gives way once its own claim is older (compete_flow.js takeoverVerdict());
 * - events through the event outbox and photos through the media outbox (stored on the phone first, sent until
 *   acknowledged) - nothing is recorded before the participant agreed to the photos, nothing at all in an organiser's
 *   preview;
 * - the screens (compete_flow.js screenFor()), the stopwatch on requestAnimationFrame, the Finished button with its
 *   10-second undo, time's up with the pieces question, a 1-minute practice that sends nothing.
 *
 * One document for the whole session: no Turbo visit, no history change (iOS mutes the camera on them, README §5.5).
 */

const HEARTBEAT_MS = 15000;
const RENDER_EVERY_MS = 50;
// Not entered / no round yet: the state is looked at this often
const SLOW_POLL_MS = 60000;
// The longest single wait of the state-check timer (it re-plans itself)
const MAX_TIMER_MS = 10 * 60 * 1000;
// iOS mutes the camera for a moment on many occasions - logged as lost only when it lasts
const MUTE_GRACE_MS = 1000;
const REOPEN_DELAY_MS = 600;
// A return after this long in the background is said out loud
const HIDDEN_WARNING_MS = 3000;
// The start photo is taken only when the page was there at GO
const START_PHOTO_WITHIN_MS = 3000;
// A push without asOfMs (an older payload) - the state is fetched, spread over this much time
const REFRESH_JITTER_MS = 2000;
const NOTICE_MS = 7000;
// The API answers that are clock samples besides /-/time
const CLOCK_SAMPLE_REQUESTS = ['heartbeat', 'state'];
const CLOCK_SCREENS = ['waiting', 'countdown', 'running', 'finished', 'timeup'];
const COMPETING_SCREENS = ['countdown', 'running', 'finished'];

// A click this soon after a handled pointerdown is the same tap
const SAME_TAP_MS = 1500;
const STORAGE_PROBE = 'msp-online-probe';

/**
 * localStorage, or null where it throws (private windows, blocked site data) - the page then keeps nothing.
 */
function browserStorage() {
    try {
        const storage = window.localStorage;
        storage.getItem(STORAGE_PROBE);

        return storage;
    } catch (e) {
        return null;
    }
}

/**
 * A tap's moment on performance.now()'s clock: the event's own timestamp (taken when the finger touched, before any
 * handler ran) - unless an old engine gives an epoch value or a stale one.
 */
function tapMoment(event) {
    const now = performance.now();
    const stamp = event?.timeStamp;

    return Number.isFinite(stamp) && stamp > 0 && stamp <= now + 1 && now - stamp < 1000 ? stamp : now;
}

export default class extends Controller {
    static targets = ['initialState', 'video', 'cameraBox', 'notice', 'consentBox', 'consentButton', 'readyButton', 'piecesInput', 'piecesError'];

    static values = {
        participantId: String,
        urls: Object,
        connect: Object,
        texts: Object,
        inApp: Boolean,
    };

    connect() {
        this.disableTurboDrive();
        this.collectElements();

        this.preview = this.connectValue?.status === 'organiser_confirm_required';
        const deviceStorage = browserStorage();
        // An organiser's preview keeps nothing - it is not the participant
        this.storage = this.preview || deviceStorage === null ? memoryStorage() : deviceStorage;
        this.deviceId = deviceIdFrom(this.preview ? memoryStorage() : this.storage, () => uuidv7());
        this.participantId = this.participantIdValue;
        this.state = this.readInitialState();
        this.roundId = this.state?.round?.id ?? null;
        // The run = the start of the round clock: the Finished taps and the pieces are kept per run (compete_flow.js)
        this.runKey = runKeyOf(this.state);
        this.consent = loadConsent(this.storage, this.participantId);
        this.progress = loadProgress(this.storage, this.participantId, this.roundId, this.runKey);
        this.connectStatus = this.connectValue?.status ?? 'anonymous';
        this.connectFailed = false;

        this.linkDead = false;
        this.takenOver = false;
        this.otherDeviceActive = false;
        // This page's last claim of the link ("I'm ready", "Use this device instead") - takeoverVerdict()
        this.claim = null;
        this.recheckAfterClaim = false;
        this.recheckTimer = null;
        this.practice = null;
        this.lastOkAt = null;
        // Requests in a row without an answer / answered with an error (connectionStatus())
        this.noAnswers = 0;
        this.failedAnswers = 0;
        this.lastPeriodicMs = null;
        this.periodicBusy = false;
        // The start (startedAtMs) whose running clock this page showed - time's up is live only after it
        this.sawRunning = null;
        this.hiddenAt = null;
        this.screen = null;
        this.shownScreen = null;
        this.view = deriveView(this.state, Date.now());
        this.painted = new Map();
        this.renderWanted = true;
        this.lastRenderAt = 0;
        // When the clock on screen changes next (compete_flow.js nextDisplayChangeAt()) - rendered in that frame
        this.nextChangeAt = null;
        this.cameraLostRecorded = false;
        this.eventsOutbox = null;
        this.mediaOutbox = null;
        this.stateFetch = null;
        this.closed = false;
        // A tap on this page since it loaded - the sound and Safari's screen lock wait for one (wakeTapNeeded())
        this.touched = false;

        this.clock = createClock({
            fetchTime: () => fetchServerTime(this.urlsValue.time),
            onJump: () => this.afterClockJump(),
            onChange: () => this.requestRender(),
        });
        this.api = createCompeteApi({
            urls: { ...this.urlsValue, connect: this.connectValue?.url ?? null },
            // The light requests only (Redis, a few reads): events and photos write to the database and come in bursts -
            // their slow answers would push the precise samples out of the clock's window (the newest 16 count)
            onSample: (sample, name) => {
                if (CLOCK_SAMPLE_REQUESTS.includes(name)) {
                    this.clock.observe(sample);
                }
            },
            onAnswer: (name, answer) => this.noteAnswer(name, answer),
        });
        this.clock.sync();

        this.frameBuffer = createFrameBuffer({ now: () => this.clock.toServerMs(performance.now()) });
        this.camera = new Camera({
            storage: deviceStorage,
            onChange: () => this.cameraChanged(),
            onTrackEvent: (event) => this.cameraTrackEvent(event),
        });
        this.element.dataset.facing = this.camera.facing;
        this.grabber = new FrameGrabber({ video: this.videoTarget, toServerMs: (perf) => this.clock.toServerMs(perf) });
        this.wakeLock = new ScreenWakeLock({ onChange: () => this.requestRender() }).attach();
        this.beeps = new Beeps();

        // Loaded again after "I'm ready" (a reload, a discarded tab): the screen lock at once where a browser grants it
        // without a tap (Chrome); Safari waits for the first tap, the sound everywhere - "Tap here once" asks for it
        if (this.progress.ready && !this.preview) {
            this.wakeLock.request();
        }

        this.openOutboxes();
        this.startStream();
        this.listen();

        this.heartbeatTimer = window.setInterval(() => this.beat(), HEARTBEAT_MS);
        this.firstBeatTimer = window.setTimeout(() => this.beat(), 800 + Math.random() * 1200);
        this.scheduleStateCheck();
        this.ensureConsentRecorded();

        if (this.connectStatus === 'auto') {
            this.connectProfile(false);
        }

        this.boundLoop = () => this.loop();
        this.frame = window.requestAnimationFrame(this.boundLoop);
        this.render();
    }

    disconnect() {
        this.closed = true;
        window.cancelAnimationFrame(this.frame);
        window.clearInterval(this.heartbeatTimer);
        window.clearTimeout(this.firstBeatTimer);
        window.clearTimeout(this.stateCheckTimer);
        window.clearTimeout(this.noticeTimer);
        window.clearTimeout(this.muteTimer);
        window.clearTimeout(this.refreshTimer);
        window.clearTimeout(this.recheckTimer);
        this.unlisten();
        this.stream?.close();
        this.grabber.stop();
        this.camera.stop();
        this.frameBuffer.cancelAll();
        this.wakeLock.release();
        this.wakeLock.detach();
        this.beeps.close();
        this.clock.close();
        this.eventsOutbox?.close();
        this.mediaOutbox?.close();
    }

    // ---- actions

    consentChanged() {
        this.consentButtonTarget.disabled = !this.consentBoxTarget.checked;
    }

    giveConsent(event) {
        event?.preventDefault();

        if (!this.consentBoxTarget.checked) {
            return;
        }

        this.consent = true;

        if (!this.preview) {
            saveConsent(this.storage, this.participantId, Date.now());
        }

        this.ensureConsentRecorded();
        this.render();
    }

    ready() {
        // The tap unlocks the beeps and the wake lock (browsers grant both only from a tap)
        this.touched = true;
        this.beeps.unlock();
        this.wakeLock.request();

        if (!readiness(this.camera, this.clock.quality()).canBeReady) {
            return;
        }

        this.progress.ready = true;
        this.saveProgress();
        this.finishClaim(this.startClaim(), this.recordDeviceReady(false));
        this.beat();
        this.render();
    }

    async takeOver() {
        this.touched = true;
        this.beeps.unlock();
        this.wakeLock.request();
        this.otherDeviceActive = false;
        // Claimed from this tap on: no answer until the server stored it makes this device give way again
        const claim = this.startClaim();
        // The camera first (it reports with device.ready), then the screen
        const opening = this.ensureCamera({ force: true });
        this.render();
        await opening;
        this.finishClaim(claim, this.recordDeviceReady(true));
        this.beat();
    }

    restartCamera() {
        this.beeps.unlock();
        this.ensureCamera({ force: true });
    }

    /**
     * "Tap here once" (wakeTapNeeded()) - the tap itself did it (anyTap()); a keyboard's activation comes here only.
     */
    wakeUp() {
        this.touched = true;
        this.beeps.unlock();

        if (this.wakeLock.state !== 'active') {
            this.wakeLock.request();
        }

        this.render();
    }

    switchCamera() {
        this.frameBuffer.cancelAll();
        this.camera.switchFacing();
    }

    takeEmptyMat() {
        if (!this.mayRecord() || !this.grabber.hasFrames()) {
            return;
        }

        this.progress.emptyMat = true;
        this.saveProgress();
        this.takeStill('empty_mat');
        this.requestRender();
    }

    /**
     * The Finished button - on pointerdown (the earliest moment of a tap); a click counts only without one before it
     * (a keyboard, a screen reader's activation).
     */
    finish(event) {
        if (event.type === 'pointerdown') {
            if ((event.button ?? 0) !== 0) {
                return;
            }

            this.lastFinishPointerAt = performance.now();
        } else if (event.type === 'click' && this.lastFinishPointerAt !== undefined && performance.now() - this.lastFinishPointerAt < SAME_TAP_MS) {
            return;
        }

        const perf = tapMoment(event);

        if (this.practice !== null) {
            this.practiceFinish(perf);

            return;
        }

        if (this.screen !== 'running') {
            return;
        }

        const stamp = this.clock.stamp(perf);
        const eventId = uuidv7(stamp.clientMs);
        this.progress.finishes.push({ eventId, momentMs: stamp.clientMs, undone: false, continued: false });
        this.saveProgress();
        // The moment first - the evidence must never delay it
        this.record('finish.button', {}, { stamp, id: eventId });
        this.beeps.tap();
        this.captureFinishEvidence(eventId, stamp.clientMs);
        this.render();
    }

    undoFinish() {
        const finish = currentFinish(this.progress);

        if (finish === null || undoLeftMs(finish, this.clock.serverNow()) <= 0) {
            return;
        }

        finish.undone = true;
        this.saveProgress();
        this.record('finish.button.undone', { eventId: finish.eventId });
        this.render();
    }

    keepPuzzling() {
        const finish = currentFinish(this.progress);

        if (finish !== null) {
            finish.continued = true;
            this.saveProgress();
            this.render();
        }
    }

    sendPieces(event) {
        event.preventDefault();
        const total = this.state?.round?.piecesTotal ?? null;
        const result = readPieces(this.piecesInputTarget.value, total);

        if (!result.ok) {
            this.piecesErrorTarget.textContent = this.t(`pieces_invalid_${result.reason}`, { total });
            this.piecesErrorTarget.hidden = false;
            this.piecesInputTarget.focus();

            return;
        }

        this.piecesErrorTarget.hidden = true;
        const measure = this.measure();
        this.progress.pieces = { measure, pieces: result.pieces };
        this.saveProgress();
        this.record('timeup.pieces', { measure, pieces: result.pieces });
        this.render();
    }

    startPractice() {
        if (!practiceAllowed(this.view)) {
            return;
        }

        this.beeps.unlock();
        this.practice = { startedAtMs: practiceStartAt(this.clock.serverNow()), finishMs: null, alarmed: false };
        this.render();
    }

    endPractice() {
        this.practice = null;
        this.beeps.cancelCountdown();
        this.render();
    }

    confirmConnect() {
        this.connectProfile(true);
    }

    // ---- set-up

    disableTurboDrive() {
        // Nothing on this page may become a Turbo visit (the links carry data-turbo="false" as well)
        try {
            if (window.Turbo?.session) {
                window.Turbo.session.drive = false;
            }
        } catch (e) {
            // No Turbo - nothing to stop
        }
    }

    collectElements() {
        this.fields = new Map();
        this.shows = new Map();
        this.screens = new Map();
        this.checks = new Map();

        for (const element of this.element.querySelectorAll('[data-compete-field]')) {
            const name = element.dataset.competeField;
            this.fields.set(name, [...(this.fields.get(name) ?? []), element]);
        }

        for (const element of this.element.querySelectorAll('[data-compete-show]')) {
            const name = element.dataset.competeShow;
            this.shows.set(name, [...(this.shows.get(name) ?? []), element]);
        }

        for (const element of this.element.querySelectorAll('[data-compete-screen]')) {
            this.screens.set(element.dataset.competeScreen, element);
        }

        for (const element of this.element.querySelectorAll('[data-compete-check]')) {
            this.checks.set(element.dataset.competeCheck, {
                row: element,
                detail: element.querySelector('[data-compete-check-detail]'),
            });
        }
    }

    readInitialState() {
        try {
            const state = JSON.parse(this.initialStateTarget.textContent);

            return state !== null && typeof state === 'object' ? state : null;
        } catch (e) {
            return null;
        }
    }

    openOutboxes() {
        if (this.preview) {
            this.eventsReady = Promise.resolve(null);
            this.mediaReady = Promise.resolve(null);

            return;
        }

        this.eventsReady = openEventOutbox({
            scope: this.participantId,
            send: (events) => this.api.events({ deviceId: this.deviceId, events }),
            onChange: () => {
                this.noteClaim();
                this.requestRender();
            },
        }).then((outbox) => {
            this.eventsOutbox = outbox;

            return outbox;
        }).catch(() => null);

        this.mediaReady = openMediaOutbox({
            scope: this.participantId,
            presign: (request) => this.api.media({
                deviceId: this.deviceId,
                roundId: request.roundId,
                kind: request.kind,
                takenMs: request.takenMs,
                bytes: request.bytes,
                contentType: request.contentType,
                relatedEventId: request.eventId,
                previousFailure: request.previousFailure,
            }),
            reportUploaded: (report) => this.reportUploaded(report),
            onChange: () => this.requestRender(),
        }).then((outbox) => {
            this.mediaOutbox = outbox;

            return outbox;
        }).catch(() => null);
    }

    listen() {
        this.onVisibility = () => this.visibilityChanged();
        this.onOffline = () => this.wentOffline();
        this.onOnline = () => this.cameOnline();
        this.onFirstTap = () => this.anyTap();
        this.onBeforeUnload = createBeforeUnloadHandler(() => this.hasUnsent()
            || (this.progress.ready && !this.preview && COMPETING_SCREENS.includes(this.screen)));

        document.addEventListener('visibilitychange', this.onVisibility);
        window.addEventListener('offline', this.onOffline);
        window.addEventListener('online', this.onOnline);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        this.element.addEventListener('pointerdown', this.onFirstTap, { capture: true, passive: true });
    }

    unlisten() {
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.removeEventListener('offline', this.onOffline);
        window.removeEventListener('online', this.onOnline);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.element.removeEventListener('pointerdown', this.onFirstTap, { capture: true });
    }

    // ---- the state

    /**
     * The round's stopwatch topic, with the token the state carries - one stream per round.
     */
    startStream() {
        this.stream?.close();
        this.stream = null;
        const roundId = this.state?.round?.id ?? null;

        if (roundId === null || this.linkDead || !this.state?.mercure) {
            return;
        }

        this.stream = new OfficialResultsEvents({
            subscription: this.state.mercure,
            refresh: () => this.refreshState(),
            onMessage: (data) => this.received(roundId, data),
        });
        this.stream.start();
    }

    received(roundId, data) {
        if (this.closed || this.state?.round?.id !== roundId) {
            return;
        }

        const previous = this.state;
        const next = adoptRoundClock(previous, data);

        if (next !== previous) {
            this.state = next;
            this.afterStateChange(previous);

            return;
        }

        // An older payload without asOfMs can not be ordered - the state says what it meant
        if (!Number.isFinite(data?.asOfMs)) {
            this.refreshSoon();
        }
    }

    refreshSoon() {
        if (this.refreshTimer) {
            return;
        }

        this.refreshTimer = window.setTimeout(() => {
            this.refreshTimer = null;
            this.refreshState();
        }, Math.random() * REFRESH_JITTER_MS);
    }

    /**
     * The state again - one request at a time. Resolves to the answer's kind (OfficialResultsEvents' refresh contract).
     */
    refreshState() {
        if (this.linkDead) {
            return Promise.resolve('forbidden');
        }

        if (this.stateFetch !== null) {
            return this.stateFetch;
        }

        this.stateFetch = this.api.state().then((answer) => {
            if (answer.kind === 'ok' && answer.data !== null && typeof answer.data === 'object') {
                this.adopt(answer.data);

                return 'ok';
            }

            return isDeadLink(answer) ? 'forbidden' : answer.kind;
        }).finally(() => {
            this.stateFetch = null;
        });

        return this.stateFetch;
    }

    adopt(incoming) {
        const previous = this.state;
        const next = adoptState(previous, incoming);

        if (next === previous) {
            // Not newer - its subscription may still be the fresher token
            if (incoming?.mercure && incoming?.round?.id === previous?.round?.id) {
                this.stream?.update(incoming.mercure);
            }

            return;
        }

        this.state = next;
        this.afterStateChange(previous);
    }

    afterStateChange(previous) {
        const roundId = this.state?.round?.id ?? null;

        if (roundId !== (previous?.round?.id ?? null)) {
            this.roundChanged();
        } else {
            if (this.state?.mercure) {
                this.stream?.update(this.state.mercure);
            }

            if (runKeyOf(this.state) !== this.runKey) {
                this.runChanged();
            }
        }

        const before = readClock(previous);
        const after = readClock(this.state);

        // A start learned (or moved): the clock is checked now, not at the next heartbeat
        if (after?.startedAtMs !== null && after?.startedAtMs !== undefined && after.startedAtMs !== before?.startedAtMs) {
            this.clock.sync();
        }

        this.scheduleStateCheck();
        this.requestRender();
    }

    roundChanged() {
        this.roundId = this.state?.round?.id ?? null;
        this.runKey = runKeyOf(this.state);
        this.progress = loadProgress(this.storage, this.participantId, this.roundId, this.runKey);
        this.lastPeriodicMs = null;
        this.sawRunning = null;
        this.practice = null;
        this.takenOver = false;
        this.frameBuffer.cancelAll();
        this.beeps.cancelCountdown();
        this.startStream();
        this.ensureConsentRecorded();
    }

    /**
     * Another run of the same round (a Reset, a new start, a countdown called off or moved): its own Finished taps and
     * pieces - none of the run that was; the set-up (consent, ready, the empty mat) stays.
     */
    runChanged() {
        this.runKey = runKeyOf(this.state);
        const run = loadProgress(this.storage, this.participantId, this.roundId, this.runKey);
        this.progress.finishes = run.finishes;
        this.progress.pieces = run.pieces;
    }

    /**
     * The next planned state fetch: 2 s before the start, 1 s after it and after the time limit; every minute while the
     * page has no round or is not entered.
     */
    scheduleStateCheck() {
        window.clearTimeout(this.stateCheckTimer);

        if (this.closed || this.linkDead) {
            return;
        }

        const serverNow = this.clock.serverNow();
        const at = nextStateCheckAt(this.state, serverNow);
        let delay;

        if (at !== null) {
            delay = at - serverNow;
        } else if (!this.state?.round || !this.state?.entry) {
            delay = SLOW_POLL_MS;
        } else {
            return;
        }

        this.stateCheckTimer = window.setTimeout(() => {
            const now = this.clock.serverNow();

            if (at === null || at <= now + 100) {
                this.refreshState();

                if (at !== null) {
                    this.clock.sync({ samples: 4 });
                }
            }

            this.scheduleStateCheck();
        }, Math.max(0, Math.min(delay, MAX_TIMER_MS)));
    }

    afterClockJump() {
        // The phone slept (or its clock was changed): whatever happened meanwhile
        this.refreshState();
        this.beat();
        this.scheduleStateCheck();
        this.requestRender();
    }

    // ---- presence

    async beat() {
        if (this.closed || this.preview || this.linkDead || !this.state?.round || !this.state?.entry || this.beating) {
            return;
        }

        this.beating = true;

        try {
            const quality = this.clock.quality();
            const settings = this.camera.settings();
            const sentAt = Date.now();
            const answer = await this.api.heartbeat({
                deviceId: this.deviceId,
                roundId: this.state.round.id,
                visible: document.visibilityState !== 'hidden',
                camera: {
                    ok: this.camera.status === 'live',
                    w: settings.width ?? null,
                    h: settings.height ?? null,
                    fps: settings.frameRate ?? null,
                    facing: this.camera.facing,
                },
                detector: { ok: false, fps: null, mode: this.state.round.settings?.detection ?? 'off' },
                clock: { offsetMs: quality.offsetMs, rttMs: quality.rttMs },
                outbox: { events: this.eventsOutbox?.pendingCount() ?? 0, media: this.mediaOutbox?.pendingCount() ?? 0 },
                ready: this.progress.ready === true,
                screen: this.screen,
            });

            if (answer.kind !== 'ok' || answer.data === null || typeof answer.data !== 'object') {
                return;
            }

            const data = answer.data;
            const roundId = this.state?.round?.id ?? '';
            // The hash has no round in it - the round is compared on its own (two waiting rounds hash alike)
            const otherRound = typeof data.roundId === 'string' && data.roundId.toLowerCase() !== roundId.toLowerCase();
            const otherHash = typeof data.stateHash === 'string' && data.stateHash !== this.state?.round?.stateHash;

            if (otherRound || otherHash) {
                this.refreshState();
            }

            this.applyTakeover(data.takenOverBy, sentAt);
        } finally {
            this.beating = false;
        }
    }

    /**
     * The heartbeat's `takenOverBy` (sent at `beatSentAt`): another device holds the link - the checklist says so, a
     * ready device gives way once its own claim is older than the answer (takeoverVerdict()); never back by itself.
     */
    applyTakeover(activeDevice, beatSentAt) {
        this.noteClaim();
        const verdict = takeoverVerdict({
            activeDevice,
            deviceId: this.deviceId,
            ready: this.progress.ready === true,
            takenOver: this.takenOver,
            claim: this.claim,
            beatSentAt,
        });
        this.otherDeviceActive = verdict.otherDeviceActive;

        if (verdict.takenOver !== this.takenOver) {
            this.takenOver = verdict.takenOver;
            this.requestRender();
        }

        if (verdict.recheckAt !== null) {
            this.scheduleRecheck(verdict.recheckAt);
        } else if (verdict.otherDeviceActive && this.claim?.pending) {
            this.recheckAfterClaim = true;
        }
    }

    noteAnswer(name, answer) {
        if (name === 'connect') {
            return;
        }

        const reach = answerReach(answer);

        if (reach === 'ok') {
            this.lastOkAt = Date.now();
            this.noAnswers = 0;
            this.failedAnswers = 0;
        } else if (reach === 'none') {
            this.noAnswers += 1;
            this.failedAnswers = 0;
        } else {
            this.failedAnswers += 1;
            this.noAnswers = 0;
        }

        if (isDeadLink(answer)) {
            this.markLinkDead();
        } else if (answer.kind === 'client' && answer.data?.error === 'round_not_online') {
            this.refreshSoon();
        }

        this.requestRender();
    }

    markLinkDead() {
        if (this.linkDead) {
            return;
        }

        this.linkDead = true;
        this.stream?.close();
        this.stream = null;
        this.render();
    }

    // ---- recording

    /**
     * Events and photos are recorded only for a participant who agreed, is entered in the round and is not an
     * organiser's preview (`explicitRound`: for a round given by the item itself, e.g. a photo of an earlier round).
     */
    mayRecord(explicitRound = false) {
        if (this.preview || this.linkDead || !this.consent) {
            return false;
        }

        return explicitRound || (Boolean(this.state?.round) && Boolean(this.state?.entry));
    }

    /**
     * This device takes the link: until its device.ready is stored (and TAKEOVER_GRACE_MS after), no heartbeat answer
     * naming another device makes it give way (takeoverVerdict()).
     */
    startClaim() {
        const claim = { eventId: null, pending: true, ackedAt: null };
        this.claim = claim;
        this.takenOver = false;

        return claim;
    }

    finishClaim(claim, event) {
        if (event === null) {
            // Nothing recorded (an organiser's preview) - nothing to wait for
            claim.pending = false;
            claim.ackedAt = Date.now();

            return;
        }

        // After record() handed the event to the outbox (an earlier callback of the same promise)
        this.eventsReady.then(() => {
            claim.eventId = event.id;
            this.noteClaim();
        });
    }

    /**
     * The claim's device.ready left the outbox - the server stored it (or refused it): from TAKEOVER_GRACE_MS on, an
     * answer naming another device is believed. An answer set aside meanwhile is asked again then.
     */
    noteClaim() {
        const claim = this.claim;

        if (claim === null || !claim.pending || claim.eventId === null || this.eventsOutbox?.isPending(claim.eventId)) {
            return;
        }

        claim.pending = false;
        claim.ackedAt = Date.now();

        if (this.recheckAfterClaim) {
            this.recheckAfterClaim = false;
            this.scheduleRecheck(claim.ackedAt + TAKEOVER_GRACE_MS);
        }
    }

    scheduleRecheck(at) {
        window.clearTimeout(this.recheckTimer);
        this.recheckTimer = window.setTimeout(() => {
            this.recheckTimer = null;
            this.beat();
        }, Math.max(0, at - Date.now()) + 50);
    }

    record(type, data = {}, { stamp = null, id = null } = {}) {
        if (!this.mayRecord()) {
            return null;
        }

        const at = stamp ?? this.clock.stamp();
        const event = buildEvent(type, at, { id: id ?? uuidv7(at.clientMs), roundId: this.state.round.id, data });
        this.eventsReady.then((outbox) => outbox?.enqueue(event)).catch(() => {});

        return event;
    }

    ensureConsentRecorded() {
        if (this.consent && !this.progress.consentSent && this.mayRecord()) {
            this.record('consent.given', {});
            this.progress.consentSent = true;
            this.saveProgress();
        }
    }

    recordDeviceReady(takeover) {
        const quality = this.clock.quality();
        const settings = this.camera.settings();

        return this.record('device.ready', {
            takeover,
            userAgent: browserDiagnostics(),
            platform: navigator.userAgentData?.platform ?? navigator.platform ?? null,
            standalone: window.matchMedia?.('(display-mode: standalone)')?.matches === true || navigator.standalone === true,
            inApp: this.inAppValue,
            screen: { width: window.screen?.width ?? null, height: window.screen?.height ?? null, pixelRatio: window.devicePixelRatio ?? null },
            camera: {
                ok: this.camera.status === 'live',
                facing: this.camera.facing,
                pick: this.camera.pick,
                w: settings.width ?? null,
                h: settings.height ?? null,
                fps: settings.frameRate ?? null,
            },
            clock: { offsetMs: quality.offsetMs, rttMs: quality.rttMs, errorMs: quality.errorMs },
            wakeLock: this.wakeLock.state,
        });
    }

    /**
     * The media outbox's report of an upload - resolves once the `media.uploaded` event is stored on the phone.
     */
    async reportUploaded(report) {
        if (!this.mayRecord(true)) {
            return;
        }

        const at = this.clock.stamp();
        const data = { mediaId: report.mediaId, bytes: report.bytes, kind: report.kind };

        if (Array.isArray(report.manifest) && report.manifest.length > 0) {
            data.manifest = report.manifest;
        }

        const event = buildEvent('media.uploaded', at, {
            id: uuidv7(at.clientMs),
            roundId: report.roundId ?? this.state?.round?.id ?? null,
            data,
        });
        const outbox = await this.eventsReady;

        if (outbox !== null) {
            await outbox.enqueue(event);
        }
    }

    async enqueueMedia(item) {
        const outbox = await this.mediaReady;

        if (outbox === null) {
            return;
        }

        try {
            await outbox.enqueue(item);
        } catch (e) {
            // A blob the browser could not give (the camera went away meanwhile) - nothing to keep
        }
    }

    async takeStill(kind, { eventId = null } = {}) {
        if (!this.mayRecord() || !this.grabber.hasFrames()) {
            return;
        }

        const roundId = this.state.round.id;
        const shot = await this.grabber.still();

        if (shot !== null) {
            await this.enqueueMedia({ kind, blob: shot.blob, takenMs: shot.captureMs, eventId, roundId });
        }
    }

    async takePeriodic(serverNow) {
        this.periodicBusy = true;
        this.lastPeriodicMs = serverNow;
        const roundId = this.state.round.id;

        try {
            const shot = await this.grabber.small();

            if (shot !== null) {
                await this.enqueueMedia({ kind: 'periodic', blob: shot.blob, takenMs: shot.captureMs, roundId });
            }
        } finally {
            this.periodicBusy = false;
        }
    }

    /**
     * A Finished tap's evidence: a full-resolution photo at once, and the frames from 5 s before to 5 s after the tap.
     */
    captureFinishEvidence(eventId, momentMs) {
        if (!this.mayRecord()) {
            return;
        }

        const roundId = this.state.round.id;

        if (this.grabber.hasFrames()) {
            this.grabber.still().then((shot) => {
                if (shot !== null) {
                    this.enqueueMedia({ kind: 'button', blob: shot.blob, takenMs: shot.captureMs, eventId, roundId });
                }
            }).catch(() => {});
        }

        if (this.frameBuffer.size() > 0 || this.grabber.isRunning()) {
            this.frameBuffer.burst(momentMs).then((burst) => {
                if (burst.manifest.length > 0) {
                    this.enqueueMedia({
                        kind: 'burst',
                        blob: burst.blob,
                        takenMs: momentMs,
                        eventId,
                        manifest: burst.manifest,
                        contentType: BURST_CONTENT_TYPE,
                        roundId,
                    });
                }
            }).catch(() => {});
        }
    }

    hasUnsent() {
        return (this.eventsOutbox?.hasUnsent() ?? false) || (this.mediaOutbox?.hasUnsent() ?? false);
    }

    saveProgress() {
        saveProgress(this.storage, this.participantId, this.roundId, this.progress, this.runKey);
    }

    measure() {
        return this.state?.round?.settings?.unfinishedMeasure === 'placed' ? 'placed' : 'left';
    }

    // ---- the camera

    /**
     * Opens the camera where the screen needs it: by itself when it is off or ended; after a refusal only from a tap
     * (`force` - Try again), never in a loop of prompts.
     */
    async ensureCamera({ force = false } = {}) {
        if (this.closed || this.cameraOpening) {
            return;
        }

        if (!force && !cameraWanted(this.screen, this.practice !== null)) {
            return;
        }

        const status = this.camera.status;

        if (!force && !['idle', 'ended'].includes(status)) {
            return;
        }

        if (!force && document.visibilityState === 'hidden') {
            return;
        }

        this.cameraOpening = true;

        try {
            await this.camera.open();
        } finally {
            this.cameraOpening = false;
        }

        this.requestRender();
    }

    cameraChanged() {
        const stream = this.camera.stream;

        if (this.videoTarget.srcObject !== stream) {
            this.frameBuffer.cancelAll();
            this.videoTarget.srcObject = stream;

            if (stream) {
                this.videoTarget.play()?.catch?.(() => {});
            }
        }

        this.element.dataset.facing = this.camera.facing;

        if (this.camera.status === 'live' && this.cameraLostRecorded) {
            this.cameraLostRecorded = false;
            this.record('camera.back', {});
        }

        this.requestRender();
    }

    cameraTrackEvent({ type }) {
        if (type === 'ended') {
            this.cameraLost('ended', this.clock.stamp());
            window.setTimeout(() => this.ensureCamera(), REOPEN_DELAY_MS);
        } else if (type === 'mute') {
            const stamp = this.clock.stamp();
            window.clearTimeout(this.muteTimer);
            this.muteTimer = window.setTimeout(() => {
                if (this.camera.status === 'muted') {
                    this.cameraLost('muted', stamp);
                }
            }, MUTE_GRACE_MS);
        } else if (type === 'unmute') {
            window.clearTimeout(this.muteTimer);
        }
    }

    cameraLost(reason, stamp) {
        // Only while competing - the review flags gaps in the round, not in the set-up
        if (this.cameraLostRecorded || !this.progress.ready || this.view?.screen === 'ended') {
            return;
        }

        if (this.record('camera.lost', { reason }, { stamp }) !== null) {
            this.cameraLostRecorded = true;
        }
    }

    // ---- page and network

    anyTap() {
        this.touched = true;
        this.requestRender();

        // Every tap may unlock what needs one: the beeps, the wake lock (Safari), a camera that stopped
        if (this.progress.ready || this.practice !== null) {
            this.beeps.unlock();
        }

        if (this.progress.ready && this.wakeLock.state !== 'active') {
            this.wakeLock.request();
        }
    }

    visibilityChanged() {
        if (document.visibilityState === 'hidden') {
            this.hiddenAt = Date.now();

            if (this.progress.ready) {
                this.record('page.hidden', { screen: this.screen });
            }

            return;
        }

        const hiddenMs = this.hiddenAt === null ? 0 : Date.now() - this.hiddenAt;
        this.hiddenAt = null;
        this.clock.check();
        this.clock.sync();
        this.refreshState();
        this.beat();
        this.eventsOutbox?.flush();
        this.mediaOutbox?.flush();

        if (this.progress.ready) {
            this.record('page.visible', { hiddenMs });
        }

        if (hiddenMs > HIDDEN_WARNING_MS && this.progress.ready && CLOCK_SCREENS.includes(this.screen)) {
            this.notify(this.t('hidden_warning', { time: formatClock(hiddenMs) }), 'warning');
        }

        // iOS unmutes the camera by itself; an ended one is opened again
        window.setTimeout(() => this.ensureCamera(), REOPEN_DELAY_MS);
        this.requestRender();
    }

    wentOffline() {
        if (this.progress.ready) {
            this.record('network.offline', {});
        }

        this.requestRender();
    }

    cameOnline() {
        if (this.progress.ready) {
            this.record('network.online', {});
        }

        this.eventsOutbox?.online();
        this.mediaOutbox?.online();
        this.clock.sync();
        this.refreshState();
        this.beat();
        this.requestRender();
    }

    // ---- connect to the profile (README §5.4)

    async connectProfile(confirm) {
        if (!this.connectValue?.url) {
            return;
        }

        this.connectStatus = confirm ? this.connectStatus : 'connecting';
        this.connectFailed = false;
        this.paintConnect();
        const answer = await this.api.connect({ csrfToken: this.connectValue.csrfToken ?? '', confirm });
        const status = answer.kind === 'ok' ? answer.data?.status : null;

        if (status === 'connected' && confirm) {
            // The organiser's preview becomes the participant's own page - a fresh start, nothing recorded so far
            window.location.reload();

            return;
        }

        if (status === 'connected') {
            this.connectStatus = 'already_connected';
            this.notify(this.t('connect_connected'), 'success');
        } else if (['already_connected', 'belongs_to_other', 'is_other_participant', 'organiser_confirm_required'].includes(status)) {
            this.connectStatus = status;
        } else {
            this.connectStatus = confirm ? this.connectStatus : 'auto';
            this.connectFailed = true;
        }

        this.paintConnect();
    }

    // ---- practice

    practiceFinish(perf) {
        if (this.practice === null || this.practice.finishMs !== null) {
            return;
        }

        const view = deriveView(practiceState(this.practice), this.clock.serverNow());

        if (view.screen !== 'running') {
            return;
        }

        this.practice.finishMs = this.clock.toServerMs(perf);
        this.beeps.tap();
        this.render();
    }

    // ---- rendering

    requestRender() {
        this.renderWanted = true;
    }

    loop() {
        if (this.closed) {
            return;
        }

        this.frame = window.requestAnimationFrame(this.boundLoop);
        const now = performance.now();
        // The clock on screen changes now (GO, the next second) - drawn in this very frame, not up to 50 ms later
        const changeDue = this.nextChangeAt !== null && this.clock.serverNow() >= this.nextChangeAt;

        if (!this.renderWanted && !changeDue && now - this.lastRenderAt < RENDER_EVERY_MS) {
            return;
        }

        this.render();
    }

    render() {
        this.renderWanted = false;
        this.lastRenderAt = performance.now();

        try {
            const serverNow = this.clock.serverNow();
            const view = deriveView(this.state, serverNow);
            this.view = view;
            const screen = screenFor({
                state: this.state,
                view,
                consent: this.consent,
                progress: this.progress,
                takenOver: this.takenOver,
                linkDead: this.linkDead,
            });

            // The real round comes first: a countdown or a running round ends a practice
            if (this.practice !== null && !practiceAllowed(view) && view.screen !== 'ended') {
                this.practice = null;
                this.beeps.cancelCountdown();
            }

            this.screen = screen;
            this.nextChangeAt = nextDisplayChangeAt(this.practice !== null ? deriveView(practiceState(this.practice), serverNow) : view, serverNow);
            this.showScreen(this.practice !== null ? 'practice' : screen);
            this.effects(screen, view, serverNow);
            this.paint(screen, view, serverNow);
        } catch (e) {
            // The loop must go on - a broken frame is drawn again on the next one
            console.error(e);
        }
    }

    showScreen(shown) {
        if (shown === this.shownScreen) {
            return;
        }

        this.shownScreen = shown;
        this.element.dataset.screen = shown;

        for (const [name, section] of this.screens) {
            section.hidden = name !== shown;
        }

        const wanted = cameraWanted(this.screen, this.practice !== null);
        this.cameraBoxTarget.hidden = !wanted;

        if (wanted) {
            this.ensureCamera();
        } else {
            this.grabber.stop();
            this.camera.stop();
        }

        if (shown === 'timeup') {
            window.setTimeout(() => this.piecesInputTarget.focus({ preventScroll: true }), 300);
        }
    }

    /**
     * What happens once per start: the countdown seen, GO (photo, vibration), time's up (alarm, end photo); the
     * periodic photos; the ring buffer's frames while the round runs.
     */
    effects(screen, view, serverNow) {
        const startKey = view.startedAtMs;

        if (this.practice !== null) {
            this.practiceEffects(serverNow);
        } else if (view.screen === 'countdown' && screen === 'countdown') {
            this.beeps.scheduleCountdown(view.startedAtMs, serverNow);
            this.once(`countdown:${startKey}`, () => this.record('countdown.seen', {
                countdownMs: Math.round(view.countdownMs),
                startedAtMs: view.startedAtMs,
            }));
        } else if (view.screen === 'waiting' || view.screen === 'scheduled') {
            this.beeps.cancelCountdown();
        }

        if (view.screen === 'running' && COMPETING_SCREENS.includes(screen)) {
            this.sawRunning = startKey;
            this.once(`start:${startKey}`, () => {
                this.record('start.seen', { lateMs: Math.round(view.elapsedMs), startedAtMs: view.startedAtMs });

                // Only at GO itself: the unwrapped package, the pieces not touched yet
                if (view.elapsedMs < START_PHOTO_WITHIN_MS) {
                    this.beeps.goVibration();
                    this.takeStill('start');
                }
            });
        }

        if (view.screen === 'ended' && this.sawRunning !== null && this.sawRunning === startKey) {
            this.once(`end:${startKey}`, () => {
                if (screen === 'timeup') {
                    this.beeps.alarm();
                    this.takeStill('end');
                }
            });
        }

        // The ring buffer for the finish bursts: while a Finished tap is possible, and for the frames after one
        const grabbing = ['running', 'finished'].includes(screen) && this.camera.status === 'live' && this.practice === null;

        if (grabbing && !this.grabber.isRunning()) {
            this.grabber.start({
                wants: (captureMs) => this.frameBuffer.wants(captureMs),
                onFrame: (blob, captureMs) => this.frameBuffer.push(blob, captureMs),
            });
        } else if (!grabbing && this.grabber.isRunning()) {
            this.grabber.stop();
        }

        if (
            this.practice === null
            && !this.periodicBusy
            && ['waiting', 'countdown', 'running', 'finished'].includes(screen)
            && this.camera.status === 'live'
            && this.mayRecord()
        ) {
            const due = nextPeriodicPhotoAt(view, serverNow, this.lastPeriodicMs);

            if (due !== null && due <= serverNow && this.grabber.hasFrames()) {
                this.takePeriodic(serverNow);
            }
        }
    }

    /**
     * Once per mark per round on this device (kept with the progress, so a reload does not repeat it). What the action
     * records is up to record() / takeStill() - the sounds belong to the screen.
     */
    once(mark, action) {
        if (this.progress.seen[mark]) {
            return;
        }

        this.progress.seen[mark] = true;
        this.saveProgress();
        action();
    }

    practiceEffects(serverNow) {
        const practice = this.practice;
        const view = deriveView(practiceState(practice), serverNow);

        if (view.screen === 'countdown') {
            this.beeps.scheduleCountdown(practice.startedAtMs, serverNow);
        } else if (view.screen === 'ended' && practice.finishMs === null && !practice.alarmed) {
            practice.alarmed = true;
            this.beeps.alarm();
        }
    }

    paint(screen, view, serverNow) {
        const round = this.state?.round ?? null;
        const limitMs = view.limitMs;
        const digit = countdownDigit(view.countdownMs);

        this.setData('clock', shortClock(this.practice !== null ? PRACTICE_MINUTES * 60000 : limitMs) ? 'short' : 'long');
        this.setField('elapsed', clockText(view.elapsedMs, { limitMs }));
        this.setField('remaining', view.remainingMs === null ? '' : this.t('left', { time: clockText(view.remainingMs, { limitMs, ceil: true }) }));
        this.setField('waitingLine', Number.isFinite(view.countdownMs) && view.countdownMs > 0
            ? this.t('starts_in', { time: countdownText(view.countdownMs) })
            : this.t('waiting'));
        this.setField('countdown', digit === 0 ? this.t('go') : String(digit));
        this.setField('timeLimit', Number.isFinite(round?.minutesLimit) ? this.tc('time_limit', round.minutesLimit) : '');

        if (this.practice === null) {
            this.setData('go', view.startedAtMs !== null && digit === 0 ? '1' : '0');
        }

        this.paintFinish(screen, view, serverNow);
        this.paintBanners(screen, view);
        this.paintStatus();
        this.paintConnect();

        if (screen === 'checklist' && this.practice === null) {
            this.paintChecklist();
        }

        if (screen === 'timeup' || screen === 'after') {
            this.paintTimeUp(round);
        }

        if (this.practice !== null) {
            this.paintPractice(serverNow);
        }

        this.setShown('practiceButton', this.practice === null && practiceAllowed(view));
    }

    paintFinish(screen, view, serverNow) {
        const finish = currentFinish(this.progress);
        const startedAtMs = view.startedAtMs;
        const seconds = finish !== null ? resultSeconds(finish.momentMs, startedAtMs) : null;

        this.setField('finishTime', seconds === null ? '' : clockText(seconds * 1000, { limitMs: view.limitMs }));
        this.setShown('afterTime', screen === 'after' && seconds !== null);

        const undoLeft = finish === null ? 0 : undoLeftMs(finish, serverNow);
        this.setShown('undo', screen === 'finished' && undoLeft > 0);
        this.setShown('keepPuzzling', screen === 'finished' && undoLeft <= 0 && view.screen === 'running');
        this.setField('undoLabel', this.t('undo', { seconds: Math.ceil(undoLeft / 1000) }));
    }

    paintBanners(screen, view) {
        const ready = this.progress.ready === true;
        const competing = ready && CLOCK_SCREENS.includes(screen) && this.practice === null;
        const live = view.screen === 'running' || view.screen === 'countdown';

        this.setShown('preview', this.preview);
        this.setShown('offline', navigator.onLine === false);
        this.setShown('cameraAlert', competing && screen !== 'timeup' && !['live', 'opening'].includes(this.camera.status));
        this.setShown('liveBanner', screen === 'checklist' && view.screen === 'running');
        this.setShown('countdownBanner', screen === 'checklist' && view.screen === 'countdown');
        this.setShown('otherDevice', screen === 'checklist' && this.otherDeviceActive);
        this.setShown('keepOpen', competing && live);
        this.setShown('afterPending', screen === 'after' && this.hasUnsent());
        this.setShown('wakeTap', !this.preview && wakeTapNeeded({
            ready,
            screen,
            practice: this.practice !== null,
            touched: this.touched,
            soundLocked: !this.beeps.isUnlocked(),
            wakeLock: this.wakeLock.state,
        }));
    }

    paintStatus() {
        const pending = (this.eventsOutbox?.pendingCount() ?? 0) + (this.mediaOutbox?.pendingCount() ?? 0);
        // "Offline" only when the network is down - a server error or a refusal says the connection has a problem
        const connection = connectionStatus({ online: navigator.onLine !== false, noAnswers: this.noAnswers, failedAnswers: this.failedAnswers });

        this.setField('statusCamera', this.camera.status === 'live' ? this.t('status_camera_ok') : this.t('status_camera_off'));
        this.setField('statusConnection', this.t(`status_${connection}`));
        this.setField('statusPending', pending > 0 ? this.tc('status_pending', pending) : '');
        this.setData('camera', this.camera.status);
        this.setData('connection', connection);
    }

    paintChecklist() {
        const quality = this.clock.quality();
        const settings = this.camera.settings();
        const rows = checklistRows({
            camera: { status: this.camera.status },
            clock: quality,
            clockSyncing: this.clock.syncing !== null,
            wakeLock: this.wakeLock.state,
            connection: {
                online: navigator.onLine !== false && this.noAnswers < CONNECTION_FAILURES_SHOWN,
                lastOkAgoMs: this.lastOkAt === null ? null : Date.now() - this.lastOkAt,
                failing: this.failedAnswers >= CONNECTION_FAILURES_SHOWN,
            },
            uploads: this.mediaOutbox?.status() ?? null,
            emptyMat: this.progress.emptyMat,
        });
        const params = {
            width: settings.width ?? '?',
            height: settings.height ?? '?',
            ms: Number.isFinite(quality.errorMs) ? Math.max(1, Math.round(quality.errorMs)) : '?',
        };

        for (const [name, row] of Object.entries(rows)) {
            const check = this.checks.get(name);

            if (!check) {
                continue;
            }

            if (check.row.dataset.level !== row.level) {
                check.row.dataset.level = row.level;
            }

            const key = `check_${name}_${row.code}`;
            const entry = this.textsValue?.[key];
            // Plural texts come as {message, locale} (browser_translation())
            const text = entry !== null && typeof entry === 'object' ? this.tc(key, row.count ?? 0) : this.t(key, params);

            if (check.detail && check.detail.textContent !== text) {
                check.detail.textContent = text;
            }
        }

        const { canBeReady } = readiness(this.camera, quality);

        if (this.readyButtonTarget.disabled === canBeReady) {
            this.readyButtonTarget.disabled = !canBeReady;
        }

        this.setShown('readyHint', !canBeReady);
        this.setShown('cameraRetry', ['denied', 'unavailable', 'busy', 'error', 'ended', 'idle'].includes(this.camera.status));
        this.setShown('cameraSwitch', this.camera.status === 'live');
        this.setShown('emptyMatButton', !this.progress.emptyMat && this.camera.status === 'live' && this.mayRecord());
    }

    paintTimeUp(round) {
        const measure = this.measure();
        const total = round?.piecesTotal ?? null;
        const reported = this.progress.pieces;

        this.setField('piecesQuestion', this.t(measure === 'placed' ? 'pieces_question_placed' : 'pieces_question_left'));
        this.setField('piecesOf', Number.isFinite(total) ? this.t('pieces_of', { total }) : '');
        this.setField('piecesReported', reported === null
            ? ''
            : this.t(reported.measure === 'placed' ? 'pieces_reported_placed' : 'pieces_reported_left', { count: reported.pieces }));
        this.setShown('afterPieces', reported !== null);
    }

    paintPractice(serverNow) {
        const practice = this.practice;
        const view = deriveView(practiceState(practice), serverNow);
        const finished = practice.finishMs !== null;
        const counting = view.screen === 'countdown' || (view.screen === 'running' && view.elapsedMs < GO_SHOWN_MS && !finished);

        const digit = countdownDigit(view.countdownMs);
        const limitMs = view.limitMs;

        this.setShown('practiceCountdown', counting);
        this.setShown('practiceRunning', !counting && view.screen === 'running' && !finished);
        this.setShown('practiceResult', finished);
        this.setShown('practiceTimeUp', !finished && view.screen === 'ended');
        this.setData('go', counting && digit === 0 ? '1' : '0');
        this.setField('practiceCountdown', digit === 0 ? this.t('go') : String(digit));
        this.setField('practiceElapsed', clockText(view.elapsedMs, { limitMs }));
        this.setField('practiceRemaining', view.remainingMs === null ? '' : this.t('left', { time: clockText(view.remainingMs, { limitMs, ceil: true }) }));

        const seconds = finished ? resultSeconds(practice.finishMs, practice.startedAtMs) : null;
        this.setField('practiceResult', seconds === null ? '' : clockText(seconds * 1000, { limitMs }));
    }

    paintConnect() {
        const status = this.connectStatus;
        const name = this.connectValue?.playerName ?? null;
        let line = '';

        if (this.connectFailed) {
            line = this.t('connect_failed');
        } else if (status === 'connecting') {
            line = this.t('connect_connecting');
        } else if (status === 'already_connected') {
            line = name ? this.t('connect_connected_as', { name }) : this.t('connect_connected');
        } else if (status === 'belongs_to_other' || status === 'is_other_participant') {
            line = this.t(`connect_${status}`);
        }

        this.setField('connectLine', line);
        this.setShown('connectLine', line !== '');
        this.setShown('signIn', status === 'anonymous');
        this.setShown('thisIsMe', this.preview && Boolean(this.connectValue?.url));
    }

    setField(name, text) {
        if (this.painted.get(`f:${name}`) === text) {
            return;
        }

        this.painted.set(`f:${name}`, text);

        for (const element of this.fields.get(name) ?? []) {
            element.textContent = text;
        }
    }

    setData(name, value) {
        if (this.painted.get(`d:${name}`) === value) {
            return;
        }

        this.painted.set(`d:${name}`, value);
        this.element.dataset[name] = value;
    }

    setShown(name, visible) {
        if (this.painted.get(`s:${name}`) === visible) {
            return;
        }

        this.painted.set(`s:${name}`, visible);

        for (const element of this.shows.get(name) ?? []) {
            element.hidden = !visible;
        }
    }

    notify(text, level = 'info') {
        if (!this.hasNoticeTarget) {
            return;
        }

        this.noticeTarget.textContent = text;
        this.noticeTarget.dataset.level = level;
        this.noticeTarget.hidden = false;
        window.clearTimeout(this.noticeTimer);
        this.noticeTimer = window.setTimeout(() => {
            this.noticeTarget.hidden = true;
        }, NOTICE_MS);
    }

    // ---- texts (templates/compete/_texts.html.twig)

    t(key, params = {}) {
        const text = this.textsValue?.[key];

        return typeof text === 'string' ? fill(text, params) : key;
    }

    tc(key, count, params = {}) {
        const text = this.textsValue?.[key];
        const chosen = text !== null && typeof text === 'object' ? chooseTranslation(text.message, count, text.locale) : null;

        return fill(chosen ?? String(count), params);
    }
}
