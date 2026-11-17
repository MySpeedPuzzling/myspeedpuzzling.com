/* stimulusFetch: 'lazy' */
// (The page loads this controller eagerly from its own Encore entry, assets/live_entry.js - competition toolkit C2. The
// comment above only keeps it out of the site's `app` bundle, which never needs it.)
import { Controller } from '@hotwired/stimulus';
import { formatResultTime, parseResultTime } from '../official_results_time.js';
import { isGone, newClientId, officialResultsRequest } from '../official_results_api.js';
import { createIndexedDbStorage, createMemoryStorage, createTabLock, Outbox, STUCK_AFTER_ATTEMPTS } from '../official_results_outbox.js';
import { OfficialResultsEvents } from '../official_results_events.js';
import {
    buildSearchIndex,
    describeResult,
    entryForEnter,
    entryOfParticipant,
    entryValue,
    keepWithheldPlayers,
    preferredRound,
    recentEntries,
    sameValue,
    searchEntries,
    shownValue,
} from '../official_results_live.js';
import { parseNameTagUrl, startQrScan } from '../official_results_scan.js';
import { fillLink, landingOf, linkNavigation, pageSettings, resolveLanding, roundOptions, stateUrl, UUID_PLACEHOLDER, urlWithoutLanding } from '../live_entry_page.js';
import { createServerClock, formatCountdown, startedAtMs, stopwatchStatusAt } from '../round_stopwatch_clock.js';
import { chooseTranslation } from '../translation_choice.js';
import { devicePlayerOf, forgetDraftsExcept, samePlayer } from '../live_entry_device.js';
import { createIndexedDbSnapshotStorage, createMemorySnapshotStorage, PREFETCH_GAP_MS, prefetchRounds, prefetchTargets, SnapshotStore } from '../live_entry_snapshot.js';
import { clearDraft, readDraft, readPrefs, writeDraft, writePrefs } from '../live_entry_draft.js';
import { parseLiveEntryRoute } from '../live_entry_route.js';
import { liveUrlOnceLive, loginUrlWithReturn, shellPlan } from '../live_entry_shell.js';

/**
 * Live result entry on a referee's phone (templates/live_results/live_results.html.twig,
 * docs/features/competitions-management/live-results.md): find → enter → confirm → save → back to find.
 *
 * Every save goes into the outbox (official_results_outbox.js) before the network sees it; the page shows the
 * device's own unsent values on top of the server's. Other devices' saves come in over a private Mercure topic,
 * the stopwatch over its public one - one stream with the page's own token (official_results_events.js). Inputs are
 * never re-rendered: lists and texts around them are.
 *
 * The page carries no data of the round or of its reader (competition toolkit C1): one state fetch with `?viewer=1`
 * fills it (live_entry_page.js decides the variants), the outbox starts with the viewer's player id from it. Until it
 * answers the page says "Loading the round…" - a failure is tried again by itself (and on Retry / back online).
 */

const REQUEST_TIMEOUT_MS = 15000;
const STATE_REFRESH_MS = 60000;
// The first state did not come: tried again after 2, 4 … 30 s (and at once when the device is back online)
const LOAD_RETRY_MAX_MS = 30000;
const FLUSH_TICK_MS = 5000;
const TOAST_MS = 8000;
// "Finished now" trusts the stopwatch it knows only while live updates flow, or this soon after a state fetch
const STOPWATCH_TRUSTED_MS = 10000;
// A page load this page started itself (a link it let go, a round switch) is not asked about again by `beforeunload`
// for this long - the browser starts unloading right after the click
const LEAVE_CONFIRMED_MS = 3000;
// The page runs on its offline copy: the state is asked for this often (and at once when the device is back online)
const OFFLINE_COPY_RETRY_MS = 5000;
const CHANNEL = 'msp-official-results';
const ROUND_KEY = 'msp.liveResults.round.';

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
}

/**
 * The server's time of a state answer in milliseconds - `serverNowMs`, or the whole seconds of `serverNow` (an answer
 * from before the milliseconds came).
 */
function serverTimeOf(state) {
    return typeof state?.serverNowMs === 'number' ? state.serverNowMs : Date.parse(state?.serverNow ?? '');
}

function storageGet(key) {
    try {
        return window.localStorage.getItem(key);
    } catch (e) {
        return null;
    }
}

// The tab's sessionStorage / the device's localStorage, null where the browser refuses them (a private window)
function webStorage(name) {
    try {
        const storage = window[name];

        return storage && typeof storage.length === 'number' ? storage : null;
    } catch (e) {
        return null;
    }
}

function storageSet(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch (e) {
        // A private window - the default next time
    }
}

export default class extends Controller {
    static targets = [
        'roundSelect', 'organiserLink', 'addTitle', 'addNameLabel', 'addMembersField', 'addTableField', 'addNote', 'addPeopleTitle', 'pill', 'pillText', 'stopwatch', 'tables', 'banner',
        'findView', 'findInput', 'keyboardToggle', 'findNotice', 'readiness', 'matches', 'addButton', 'recentBlock', 'recent',
        'entryView', 'entryTable', 'entryName', 'entryMembers', 'entryNow', 'entryProblems',
        'timeForm', 'timeInput', 'timeParsed', 'piecesForm', 'piecesInput', 'piecesParsed', 'finishedNow', 'finishedNowClock', 'clearButton',
        'confirmView', 'confirmTable', 'confirmName', 'confirmResult', 'confirmNote', 'saveButton',
        'addView', 'addForm', 'addName', 'addMembers', 'addTable', 'addError', 'addSuggestions', 'addSuggestionList', 'addPeople', 'addPeopleList',
        'toast', 'toastText', 'toastUndo', 'sheet', 'sheetBody', 'scanner', 'video', 'scanHint',
    ];

    static values = {
        roundId: String,
        locale: String,
        stateUrl: String,
        eventPeopleUrl: String,
        recordUrl: String,
        liveUrl: String,
        scanUrl: String,
        loginUrl: String,
        csrfToken: String,
        entrant: String,
        table: String,
        entry: String,
        autoPicked: Boolean,
        notice: String,
        messages: Object,
        plurals: Object,
        shell: Boolean,
    };

    connect() {
        // The server's clock: the state fetch with the shortest round trip (round_stopwatch_clock.js) - "Finished now"
        // and the countdown agree with the projector
        this.serverClock = createServerClock();
        this.stopwatchKnownAt = 0;
        // Nothing of the round until its state answers (the page renders none of it)
        this.ready = false;
        this.userId = '';
        this.competition = null;
        this.round = null;
        this.rounds = [];
        this.stopwatch = { status: null, startedAt: null, stoppedAt: null };
        this.viewer = null;
        this.settings = pageSettings(null);
        this.outbox = null;
        // The event's people who are no entry of this round - quick add offers them before typing a name in again;
        // asked for when quick add (or a landing) needs them
        this.eventPeople = null;
        this.eventPeopleLoading = null;
        this.landing = landingOf({ entrant: this.entrantValue, table: this.tableValue, entry: this.entryValue });
        this.loadAttempts = 0;
        // The round's private results topic + its public stopwatch topic, with the token the state carries - kept open,
        // renewed and caught up by OfficialResultsEvents (signed out / no rights: the banner's Retry takes it from there)
        this.events = new OfficialResultsEvents({
            subscription: null,
            onMessage: (data) => this.receiveUpdate(data),
            refresh: () => (this.isConnected() ? this.refreshState() : Promise.resolve('closed')),
        });
        this.serverEntries = new Map();
        this.localEntries = new Map();

        this.view = 'find';
        this.openRef = null;
        this.pendingValue = null;
        this.pendingFinishedNow = false;
        this.query = '';
        this.historyEntry = false;
        this.leavingHistoryEntry = false;
        this.toastItem = null;
        this.toastTimer = null;
        this.renderQueued = false;
        this.stateBlocked = null;
        this.loadFailed = false;
        this.storageMissing = false;
        this.foreign = 0;
        this.lastClockSecond = null;
        // The page runs on the round's last known state (a snapshot, live_entry_snapshot.js) since this moment - its
        // first state fetch failed; null once a state answered
        this.offlineCopyAt = null;
        this.prefetchStarted = false;
        this.pendingTappedAt = null;
        // The offline copy (`live_results_shell`, toolkit C5): what it shows instead of a round - null, 'signedOut',
        // 'noCopy' - and the device's other snapshots offered there
        this.shellScreen = null;
        this.shellOthers = [];
        this.shellUnsent = 0;
        this.setupSnapshots();

        this.setKeyboard('text');

        this.onOnline = () => {
            // The offline copy without a round to ask for (signed out, an event nobody opened here): the URL itself
            // again - the network answers it now (or the copy comes back)
            if (this.shellValue && !this.ready && this.roundIdValue === '') {
                this.confirmLeaving();
                window.location.reload();

                return;
            }

            if (!this.ready || this.offlineCopyAt !== null) {
                this.loadNow();
            }

            this.flushSoon();
        };
        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.refreshState();
                this.flushSoon();
            } else {
                this.snapshots?.flush();
            }
        };
        this.onPageHide = () => this.snapshots?.flush();
        this.onFocus = () => {
            // Back from the sign-in tab
            if (this.outbox?.blocked != null || (this.stateBlocked !== null && this.stateBlocked !== 'gone')) {
                this.retryAll();
            }
        };
        this.onBeforeUnload = (event) => {
            if (this.outbox?.hasUnsent() && !this.leaveConfirmed()) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        // No Turbo on this page (competition toolkit C2): every link is a full page load. Another live entry page sends
        // this device's changes too - only leaving the tool asks (in the page's own words), and what was answered here
        // is not asked again by `beforeunload` (the browser's own question stays for back, reload and closing the tab)
        this.onLinkClick = (event) => {
            const link = event.defaultPrevented ? null : event.target?.closest?.('a[href]');

            if (!link) {
                return;
            }

            const navigation = linkNavigation({
                href: link.href,
                target: link.target,
                download: link.hasAttribute('download'),
                button: event.button,
                modifier: event.metaKey || event.ctrlKey || event.shiftKey || event.altKey,
            }, window.location.href, this.liveUrlValue);

            if (navigation === 'stay') {
                return;
            }

            if (navigation === 'leave' && this.outbox?.hasUnsent() && !window.confirm(this.t('leaveUnsent'))) {
                event.preventDefault();

                return;
            }

            this.confirmLeaving();
        };
        this.onPopState = () => this.handlePopState();
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && (this.view !== 'find' || !this.sheetTarget.hidden || !this.scannerTarget.hidden)) {
                event.preventDefault();
                this.closeEverything();
            }
        };

        window.addEventListener('online', this.onOnline);
        window.addEventListener('focus', this.onFocus);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        window.addEventListener('popstate', this.onPopState);
        window.addEventListener('pagehide', this.onPageHide);
        document.addEventListener('visibilitychange', this.onVisibility);
        document.addEventListener('click', this.onLinkClick);
        document.addEventListener('keydown', this.onKeydown);

        this.clockTimer = setInterval(() => this.tickClock(), 250);
        this.flushTimer = setInterval(() => this.flushSoon(), FLUSH_TICK_MS);
        this.stateTimer = setInterval(() => {
            if (this.ready && document.visibilityState === 'visible') {
                this.refreshState();
            }
        }, STATE_REFRESH_MS);

        this.render();
        this.findInputTarget.focus({ preventScroll: true });

        if (this.shellValue) {
            this.startShell();

            return;
        }

        // One fetch fills the page: the round, its entries, the clock, the stream's token and who reads it
        this.loadNow();
    }

    /**
     * The offline copy (competition toolkit C5, C-judge-page.md §1.6): the service worker answered it in place of a
     * live entry URL whose request failed. The URL tells what was asked for, the device's snapshots of the player its
     * cookie names give the round - shown at once, then the state is asked for until it answers (the page goes live by
     * itself). Without a player or a snapshot: a neutral screen, never a blank page.
     */
    async startShell() {
        const route = parseLiveEntryRoute(window.location.href);
        const query = new URL(window.location.href).searchParams;
        const player = devicePlayerOf(document.cookie);

        this.landing = landingOf({ entrant: query.get('entrant') ?? '', table: query.get('table') ?? '', entry: query.get('entry') ?? '' });

        // Signing in comes back to the URL that was asked for (not to this copy's own address)
        if (route !== null) {
            this.loginUrlValue = loginUrlWithReturn(this.loginUrlValue, `${window.location.pathname}${window.location.search}`);
        }

        const snapshots = player === null || this.snapshots === null ? [] : await this.snapshots.list().catch(() => []);
        const plan = shellPlan({
            route,
            player,
            snapshots,
            nowMs: Date.now(),
            remembered: (competitionId) => this.readRememberedRound(competitionId),
        });

        if (!this.isConnected()) {
            return;
        }

        if (plan.screen === 'round') {
            this.roundIdValue = plan.snapshot.roundId;

            if (this.landing === null) {
                this.landing = plan.landing;
            }

            await this.startFromSnapshot(plan.snapshot);
            this.loadNow();

            return;
        }

        this.shellScreen = plan.screen;
        this.shellOthers = plan.screen === 'noCopy' ? plan.others : [];
        this.findViewTarget.hidden = true;

        if (plan.screen === 'signedOut') {
            this.shellUnsent = await this.unsentOnDevice();
        } else if (plan.roundId) {
            // A round nobody opened on this device: it opens as soon as its state answers
            this.roundIdValue = plan.roundId;
            this.loadNow();
        }

        this.scheduleRender();
    }

    /**
     * Results on this device waiting to be sent (any player's) - only counted where the outbox's database exists.
     */
    async unsentOnDevice() {
        try {
            if (typeof indexedDB?.databases === 'function') {
                const databases = await indexedDB.databases();

                if (!databases.some((database) => database.name === 'msp-official-results')) {
                    return 0;
                }
            }

            const storage = await createIndexedDbStorage();

            return storage === null ? 0 : (await storage.getAll()).length;
        } catch (e) {
            return 0;
        }
    }

    /**
     * Asks for the state until the page has it (its first answer starts the page - start()).
     */
    loadNow() {
        clearTimeout(this.loadTimer);
        this.loadTimer = null;

        // The offline copy with no round to ask for
        if (this.roundIdValue === '') {
            return Promise.resolve();
        }

        return this.refreshState().then(async (kind) => {
            if ((this.ready && this.offlineCopyAt === null) || !this.isConnected() || kind === 'auth' || kind === 'forbidden' || this.stateBlocked === 'gone') {
                // Signed out / no rights: the banner's Retry (and the window's focus) asks again
                return;
            }

            // No network (or a failing server) before the first state: the round as this device last knew it - asked
            // for again until a state answers (the page then goes live by itself)
            if (!this.ready && (kind === 'offline' || kind === 'server')) {
                await this.startFromSnapshot();
            }

            if (!this.isConnected() || (this.ready && this.offlineCopyAt === null)) {
                return;
            }

            this.loadAttempts += 1;
            this.loadFailed = true;
            this.scheduleRender();
            this.loadTimer = setTimeout(() => this.loadNow(), Math.min(this.offlineCopyAt !== null ? OFFLINE_COPY_RETRY_MS : LOAD_RETRY_MAX_MS, 1000 * (2 ** this.loadAttempts)));
        });
    }

    /**
     * The first state answered: everything that needs the round or its reader starts here.
     */
    start(state) {
        this.ready = true;
        this.loadFailed = false;

        if (this.shellScreen !== null) {
            // The offline copy's screen gives way to the round (its state answered)
            this.shellScreen = null;
            this.shellOthers = [];
            this.findViewTarget.hidden = false;
            this.goneLiveUrl();
        }

        clearTimeout(this.loadTimer);
        this.userId = this.settings.playerId ?? '';

        // (Not from the offline copy: another round's page cannot load without the network)
        if (this.autoPickedValue && this.offlineCopyAt === null) {
            const remembered = this.readRememberedRound();
            const target = preferredRound(this.roundIdValue, remembered, this.rounds, Date.now());

            if (target !== this.roundIdValue) {
                this.confirmLeaving();
                window.location.replace(this.liveUrl(target));

                return;
            }
        }

        this.cleanUrl();
        this.setupOutbox();

        if (typeof BroadcastChannel !== 'undefined') {
            this.channel = new BroadcastChannel(CHANNEL);
            this.channel.onmessage = (event) => {
                if (event.data?.type === 'outbox' && event.data.userId === this.userId) {
                    this.outbox.load();
                }
            };
        }

        this.setKeyboard(this.prefs().keyboard ?? (this.usesTables() && this.round?.entries?.withTableNumber > 0 ? 'numeric' : 'text'));
        this.events.update(state.mercure ?? null);
        this.events.start();
        this.render();
        this.tickClock(true);
        this.land();

        // A result being entered before a reload comes back - unless the page was opened for an entry (a landing)
        if (this.landing === null && this.view === 'find') {
            this.restoreDraft();
        }

        if (this.noticeValue !== '') {
            this.notice(this.noticeValue, 'warning');
        }

        this.rememberState();
        this.schedulePrefetch();
    }

    // ---- Snapshots + drafts (competition toolkit C4) ----

    /**
     * The device's snapshots of the player its cookie names (live_entry_snapshot.js): every other player's go, and
     * so do other players' drafts in this tab.
     */
    setupSnapshots() {
        const player = devicePlayerOf(document.cookie);
        const session = webStorage('sessionStorage');

        if (session !== null) {
            forgetDraftsExcept(session, player);
        }

        this.snapshots = null;

        if (player === null) {
            return;
        }

        const opened = createIndexedDbSnapshotStorage()
            .catch(() => null)
            .then((storage) => storage ?? createMemorySnapshotStorage());

        this.snapshots = new SnapshotStore({
            storage: {
                getAll: async () => (await opened).getAll(),
                get: async (key) => (await opened).get(key),
                put: async (record) => (await opened).put(record),
                delete: async (key) => (await opened).delete(key),
            },
            playerId: player,
            devicePlayer: () => devicePlayerOf(document.cookie),
            marker: webStorage('localStorage'),
        });
        this.snapshots.start();
    }

    /**
     * The first state did not come: the page starts from the round's snapshot, if this device has one.
     */
    async startFromSnapshot(given = null) {
        if (this.snapshots === null) {
            return false;
        }

        const snapshot = given ?? await this.snapshots.read(this.roundIdValue).catch(() => null);

        if (snapshot === null || this.ready || !this.isConnected() || !samePlayer(snapshot.state?.viewer?.playerId, snapshot.playerId)) {
            return false;
        }

        if (snapshot.clockOffsetMs !== null) {
            this.serverClock.hint(snapshot.clockOffsetMs);
        }

        this.offlineCopyAt = snapshot.savedAt;
        this.applyState(snapshot.state);

        if (Array.isArray(snapshot.eventPeople)) {
            this.eventPeople = snapshot.eventPeople;
            this.peopleIndex = null;
        }

        this.start(snapshot.state);

        return true;
    }

    /**
     * A state answered while the page ran on its snapshot: it is the live page again (the same player - another one
     * signed in meanwhile gets the page anew).
     */
    goLive(state) {
        if (!samePlayer(state.viewer?.playerId, this.userId)) {
            this.confirmLeaving();
            window.location.reload();

            return;
        }

        this.offlineCopyAt = null;
        this.loadFailed = false;
        this.loadAttempts = 0;
        clearTimeout(this.loadTimer);
        this.goneLiveUrl();
        this.viewer = state.viewer;
        // The event's people from the snapshot: asked for anew when needed
        this.eventPeople = null;
        this.peopleIndex = null;
        this.refreshSettings();
        this.scheduleRender();
        this.schedulePrefetch();
    }

    /**
     * The offline copy went live: its address becomes the round's live entry URL (an event's or a tag's URL would pick
     * again on a reload), and signing in comes back there.
     */
    goneLiveUrl() {
        if (!this.shellValue) {
            return;
        }

        const live = this.liveUrl(this.roundIdValue);
        const path = liveUrlOnceLive(window.location.href, live);

        if (path !== null) {
            window.history.replaceState(window.history.state, '', path);
        }

        this.loginUrlValue = loginUrlWithReturn(this.loginUrlValue.split('?')[0], live);
    }

    /**
     * The round as the page knows it now goes into its snapshot (after a short pause) - never while the page runs on
     * the snapshot itself (its time would claim knowledge the page does not have).
     */
    rememberState() {
        if (this.snapshots === null || !this.ready || this.offlineCopyAt !== null || !samePlayer(this.viewer?.playerId, this.snapshots.playerId)) {
            return;
        }

        this.snapshots.save({
            roundId: this.roundIdValue,
            state: {
                competition: this.competition,
                round: this.round ? { ...this.round, stopwatch: this.stopwatch } : null,
                rounds: this.rounds,
                entries: [...this.serverEntries.values()],
                viewer: this.viewer,
            },
            clockOffsetMs: this.serverClock.offsetMs(),
            clockMeasuredAt: Date.now(),
            eventPeople: this.eventPeople,
        });
    }

    /**
     * Once per page, a while after it went live: the event's other rounds of the day go into snapshots too (a round
     * switch or another group's name tag work offline). Not on a data-saving connection.
     */
    schedulePrefetch() {
        if (this.prefetchStarted || this.snapshots === null || this.offlineCopyAt !== null || navigator.connection?.saveData === true) {
            return;
        }

        this.prefetchStarted = true;
        this.prefetchTimer = setTimeout(() => {
            prefetchRounds({
                roundIds: prefetchTargets(this.rounds, this.roundIdValue, this.serverNow()),
                store: this.snapshots,
                wait: (ms) => new Promise((resolve) => {
                    this.prefetchTimer = setTimeout(resolve, ms);
                }),
                shouldStop: () => !this.isConnected() || navigator.onLine === false || this.offlineCopyAt !== null,
                extra: () => ({ clockOffsetMs: this.serverClock.offsetMs(), clockMeasuredAt: Date.now() }),
                fetchState: async (roundId) => {
                    const abort = new AbortController();
                    const timer = setTimeout(() => abort.abort(), REQUEST_TIMEOUT_MS);

                    try {
                        const answer = await officialResultsRequest(stateUrl(this.stateUrlValue, roundId), { signal: abort.signal });

                        return answer.kind === 'ok' && samePlayer(answer.data?.viewer?.playerId, this.snapshots.playerId) ? answer.data : null;
                    } finally {
                        clearTimeout(timer);
                    }
                },
            });
        }, PREFETCH_GAP_MS);
    }

    prefs() {
        return readPrefs(webStorage('localStorage') ?? { getItem: () => null, setItem: () => {}, removeItem: () => {} });
    }

    /**
     * The opened entry, what is typed in it and the value waiting on the confirm view - for a reload of this tab.
     */
    rememberDraft() {
        const storage = webStorage('sessionStorage');

        if (storage === null || this.userId === '' || this.openRef === null || (this.view !== 'entry' && this.view !== 'confirm')) {
            return;
        }

        const confirm = this.view === 'confirm';

        writeDraft(storage, this.userId, {
            roundId: this.roundIdValue,
            ref: this.openRef,
            view: this.view,
            mode: this.piecesFormTarget.classList.contains('lr-hidden') ? 'time' : 'pieces',
            typed: this.timeInputTarget.value,
            pieces: this.piecesInputTarget.value,
            pending: confirm ? { value: this.pendingValue } : null,
            finishedNow: confirm && this.pendingFinishedNow && this.pendingTappedAt !== null ? { seconds: this.pendingValue?.seconds, tappedAt: this.pendingTappedAt } : null,
        }, Date.now());
    }

    forgetDraft() {
        const storage = webStorage('sessionStorage');

        if (storage !== null && this.userId !== '') {
            clearDraft(storage, this.userId);
        }
    }

    /**
     * The tab's draft of this round (younger than 10 minutes) opens again, exactly as it was - a "Finished now" value
     * as it was frozen, never measured again.
     */
    restoreDraft() {
        const storage = webStorage('sessionStorage');
        const draft = storage === null ? null : readDraft(storage, this.userId, this.roundIdValue, Date.now());

        if (draft === null || this.entry(draft.ref) === null) {
            return false;
        }

        this.openEntry(draft.ref);

        if (draft.mode === 'pieces') {
            this.didNotFinish();
            this.piecesInputTarget.value = draft.pieces;
            this.piecesTyped();
        } else {
            this.timeInputTarget.value = draft.typed;
            this.timeTyped();
        }

        if (draft.view === 'confirm') {
            this.review(draft.pending.value, { finishedNow: draft.finishedNow !== null, tappedAt: draft.finishedNow?.tappedAt ?? null, restored: true });
        }

        return true;
    }

    /**
     * `?entrant=` / `?entry=` / `?table=`: the entry opens, or the find view says why not.
     */
    land(people = this.eventPeople) {
        const target = resolveLanding(this.landing, this.allEntries(), people, this.settings.roundName);

        if (target === null) {
            return;
        }

        if (target.needPeople) {
            // Who is it? The event's people tell the name - asked once, then the landing is decided without them
            this.loadEventPeople().then((loaded) => {
                if (this.isConnected() && this.landing !== null && this.view === 'find') {
                    this.land(loaded);
                }
            });

            return;
        }

        this.landing = null;

        if (target.open) {
            this.openEntry(target.open);
        } else if (target.notice) {
            this.notice(this.t(target.notice, target.parameters), 'warning');
        }
    }

    /**
     * The event's people who are no entry of this round (official_results_event_people) - once per page; a failed
     * request leaves the list empty and asks again next time.
     */
    loadEventPeople() {
        if (this.eventPeople !== null) {
            return Promise.resolve(this.eventPeople);
        }

        if (this.eventPeopleLoading) {
            return this.eventPeopleLoading;
        }

        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), REQUEST_TIMEOUT_MS);

        this.eventPeopleLoading = officialResultsRequest(this.eventPeopleUrlValue.replace(UUID_PLACEHOLDER, this.roundIdValue), { signal: abort.signal })
            .then((answer) => {
                if (answer.kind === 'ok' && Array.isArray(answer.data?.people)) {
                    this.eventPeople = answer.data.people;
                    this.peopleIndex = null;
                    this.rememberState();
                }

                return this.eventPeople ?? [];
            })
            .catch(() => [])
            .finally(() => {
                clearTimeout(timer);
                this.eventPeopleLoading = null;
            });

        return this.eventPeopleLoading;
    }

    disconnect() {
        clearTimeout(this.loadTimer);
        clearInterval(this.clockTimer);
        clearInterval(this.flushTimer);
        clearInterval(this.stateTimer);
        clearTimeout(this.toastTimer);
        clearTimeout(this.countsTimer);
        clearTimeout(this.prefetchTimer);
        this.snapshots?.flush();
        this.events?.close();
        this.channel?.close();
        this.stopScan?.();

        window.removeEventListener('online', this.onOnline);
        window.removeEventListener('focus', this.onFocus);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        window.removeEventListener('popstate', this.onPopState);
        window.removeEventListener('pagehide', this.onPageHide);
        document.removeEventListener('visibilitychange', this.onVisibility);
        document.removeEventListener('click', this.onLinkClick);
        document.removeEventListener('keydown', this.onKeydown);
        this.historyEntry = false;
    }

    // ---- Texts ----

    t(key, parameters = {}) {
        let text = this.messagesValue[key] ?? key;

        for (const [name, value] of Object.entries(parameters)) {
            text = text.split(name).join(String(value));
        }

        return text;
    }

    plural(key, count) {
        const message = this.pluralsValue[key];

        return message ? (chooseTranslation(message.message, count, message.locale) ?? String(count)) : String(count);
    }

    describe(result) {
        return describeResult(result, this.round?.piecesCount ?? null, {
            noResult: '–',
            piecesPlaced: this.t('piecesPlaced'),
            piecesPlacedOf: this.t('piecesPlacedOf'),
            didNotStart: this.t('didNotStart'),
        });
    }

    timeOfDay(iso) {
        const date = new Date(iso);

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        try {
            return date.toLocaleTimeString(this.localeValue || undefined, { hour: 'numeric', minute: '2-digit' });
        } catch (e) {
            return date.toISOString().slice(11, 16);
        }
    }

    whoName(enteredBy) {
        if (!enteredBy) {
            return '';
        }

        return enteredBy.playerId === this.userId ? this.t('you') : (enteredBy.name ?? '');
    }

    // ---- State ----

    applyState(state) {
        this.competition = state.competition;
        this.round = state.round;
        this.rounds = Array.isArray(state.rounds) ? state.rounds : [];
        this.stopwatch = state.round?.stopwatch ?? { status: null, startedAt: null, stoppedAt: null };

        // An answer without entries (`?entries=0`) keeps the ones the page has
        if (Array.isArray(state.entries)) {
            this.serverEntries = new Map(state.entries.map((entry) => [entry.ref, entry]));

            for (const ref of this.serverEntries.keys()) {
                this.localEntries.delete(ref);
            }
        }

        // The reader is decided by the first answer (the outbox is theirs); later answers keep it
        if (!this.ready) {
            this.viewer = state.viewer ?? null;
        }

        this.refreshSettings();
        this.indexDirty = true;
    }

    refreshSettings() {
        this.settings = pageSettings({ competition: this.competition, round: this.round, viewer: this.viewer ?? null });
        this.applySettings();
    }

    /**
     * The template's variants for this round and reader (live_entry_page.js pageSettings()): touches only what changed,
     * never an input's value.
     */
    applySettings() {
        const settings = this.settings;
        const key = JSON.stringify([settings, this.rounds.map((round) => [round.id, round.name, round.stopwatch?.status ?? null])]);

        if (this.settingsKey === key) {
            return;
        }

        this.settingsKey = key;
        const competitionId = settings.competitionId;

        for (const link of this.element.querySelectorAll('[data-href]')) {
            link.setAttribute('href', fillLink(link.dataset.href, this.roundIdValue, competitionId));
        }

        for (const element of this.organiserLinkTargets) {
            element.hidden = !settings.organiser;
        }

        // The round picker: every round of the event, this one selected
        const options = roundOptions(this.rounds, this.roundIdValue, this.t('stopwatchRunning'));

        if (options.length > 0) {
            const select = this.roundSelectTarget;
            select.replaceChildren(...options.map((option) => {
                const element = document.createElement('option');
                element.value = option.value;
                element.textContent = option.label;
                element.selected = option.selected;

                return element;
            }));
            select.disabled = false;
        }

        if (settings.roundName !== '') {
            document.title = this.t('pageTitle', { '%round%': settings.roundName });
        }

        const placeholder = this.t(settings.usesTables ? 'findPlaceholder' : 'findPlaceholderNames');
        this.findInputTarget.setAttribute('placeholder', placeholder);
        this.findInputTarget.setAttribute('aria-label', placeholder);
        this.keyboardToggleTarget.hidden = !settings.usesTables;
        this.entryTableTarget.hidden = !settings.usesTables;

        // Quick add: a person, or a pair/team with its members
        this.setText(this.addTitleTarget, this.t(settings.solo ? 'addTitlePerson' : 'addTitleTeam'));
        this.setText(this.addNameLabelTarget, this.t(settings.solo ? 'addNameLabel' : 'addTeamNameLabel'));
        this.setText(this.addPeopleTitleTarget, this.t(settings.solo ? 'addEventPeople' : 'addEventPeopleMembers'));
        this.setText(this.addNoteTarget, this.t(settings.canSetTables ? 'addNote' : 'addNoteResultOnly'));
        this.addMembersFieldTarget.hidden = settings.solo;
        this.addTableFieldTarget.hidden = !settings.canSetTables;

        while (!settings.solo && this.addMembersTarget.querySelectorAll('input[data-member]').length < settings.members) {
            this.addMemberInput(null, { focus: false });
        }
    }

    mergeEntries(entries, { fromOwnSave = false } = {}) {
        let countsChanged = false;

        for (const entry of entries) {
            if (entry.roundId !== this.roundIdValue) {
                continue;
            }

            const before = this.serverEntries.get(entry.ref);
            countsChanged = countsChanged || before === undefined || before.tableNumber !== entry.tableNumber;
            this.serverEntries.set(entry.ref, keepWithheldPlayers(before, entry));
            this.localEntries.delete(entry.ref);
        }

        this.indexDirty = true;
        this.scheduleRender();
        this.rememberState();

        // An entrant added or a table number given from this device: the round's counts ("Tables: x / y") come with
        // the state - Mercure brings them to the other devices
        if (fromOwnSave && countsChanged) {
            clearTimeout(this.countsTimer);
            this.countsTimer = setTimeout(() => this.refreshState(), 1500);
        }
    }

    usesTables() {
        return this.settings.usesTables;
    }

    /**
     * The server's entries, entrants added on this device and not on the server yet (from the outbox, or just typed
     * in), each with this device's unsent table number on top.
     */
    allEntries() {
        const entries = [...this.serverEntries.values()];
        const known = new Set(this.serverEntries.keys());
        const items = this.outbox ? this.outbox.all() : [];

        for (const item of items) {
            if (item.roundId !== this.roundIdValue || !item.newEntry || known.has(item.entryRef) || this.localEntries.has(item.entryRef)) {
                continue;
            }

            this.localEntries.set(item.entryRef, this.localEntry(item.newEntry));
        }

        for (const [ref, entry] of this.localEntries) {
            if (!known.has(ref)) {
                entries.push(entry);
            }
        }

        // A refused table number (taken meanwhile) is not shown as the entry's table - its problem is
        const waitingTables = items.filter((item) => item.field === 'table_number' && item.state === 'pending');

        return entries.map((entry) => {
            const table = shownValue(entry, 'table_number', waitingTables);

            return table.item !== null ? { ...entry, tableNumber: table.value } : entry;
        });
    }

    localEntry(newEntry) {
        const team = newEntry.kind === 'team';
        const members = team
            ? (newEntry.members ?? []).map((member) => (typeof member === 'string' ? { name: member, participantId: null } : { name: member.name ?? '', participantId: member.participantId ?? null }))
            : [];
        const name = newEntry.name ?? null;

        return {
            ref: `${team ? 'team' : 'participant_round'}:${newEntry.clientEntryId}`,
            kind: team ? 'team' : 'person',
            id: newEntry.clientEntryId,
            roundId: this.roundIdValue,
            name,
            displayName: name ?? members.map((member) => member.name).join(', '),
            participantId: newEntry.participantId ?? null,
            country: null,
            countries: [],
            members,
            playerId: null,
            playerCode: null,
            playerName: null,
            tableNumber: null,
            result: null,
            rank: null,
            qualified: false,
            enteredAt: null,
            enteredBy: null,
            local: true,
            newEntry,
        };
    }

    entry(ref) {
        return this.allEntries().find((entry) => entry.ref === ref) ?? null;
    }

    searchIndex() {
        if (this.indexDirty || !this.index) {
            this.index = buildSearchIndex(this.allEntries());
            this.indexDirty = false;
        }

        return this.index;
    }

    /**
     * Fetches the round's state; a call while one is out waits for that one. Resolves to the answer's kind.
     */
    refreshState() {
        if (this.refreshing) {
            return this.refreshing;
        }

        // The round is gone - nothing to ask for any more (another round is picked above, a page of its own)
        if (this.stateBlocked === 'gone') {
            return Promise.resolve('client');
        }

        this.refreshing = this.fetchState().finally(() => {
            this.refreshing = null;
        });

        return this.refreshing;
    }

    async fetchState() {
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), REQUEST_TIMEOUT_MS);
        const sentAt = Date.now();

        try {
            const answer = await officialResultsRequest(stateUrl(this.stateUrlValue, this.roundIdValue), { signal: abort.signal });
            const answeredAt = Date.now();

            if (answer.kind === 'ok') {
                this.stateBlocked = null;
                this.serverClock.observe({ sentAtMs: sentAt, receivedAtMs: answeredAt, serverNowMs: serverTimeOf(answer.data) });
                this.stopwatchKnownAt = answeredAt;
                this.applyState(answer.data);
                this.scheduleRender();

                if (!this.ready) {
                    // The page's first state: everything that needs the round or its reader starts now
                    this.start(answer.data);
                } else {
                    if (this.offlineCopyAt !== null) {
                        this.goLive(answer.data);
                    }

                    // A fresh token - the stream is renewed with it in its last minutes, or opened again if it is down
                    this.events.update(answer.data.mercure ?? null);
                    this.rememberState();
                }
            } else if (answer.kind === 'auth' || answer.kind === 'forbidden') {
                this.stateBlocked = answer.kind;
                this.scheduleRender();
                // A token is only for whoever may still enter results: no stream until a state answers again
                this.events.suspend();
            } else if (isGone(answer)) {
                // The round was deleted: the page says so and stops asking - its unsent changes are refused one by one
                this.stateBlocked = 'gone';
                this.events.close();
                this.scheduleRender();
            }

            return answer.kind;
        } finally {
            clearTimeout(timer);
        }
    }

    /**
     * The stopwatch this page knows is the round's: live updates flow, or the state was fetched just now.
     */
    stopwatchTrusted() {
        return this.events.isLive() || Date.now() - this.stopwatchKnownAt < STOPWATCH_TRUSTED_MS;
    }

    isConnected() {
        return this.element.isConnected;
    }

    receiveUpdate(data) {
        if (typeof data?.type === 'string') {
            if (data.roundId !== this.roundIdValue) {
                return;
            }

            if (data.type === 'official_results.entries') {
                if (data.round) {
                    this.round = data.round;
                    this.refreshSettings();
                }

                this.mergeEntries(Array.isArray(data.entries) ? data.entries : []);
            } else if (data.type === 'official_results.refresh') {
                this.refreshState();
            } else if (data.type === 'official_results.round' && data.round) {
                this.round = data.round;
                this.refreshSettings();
                this.scheduleRender();
                this.rememberState();
            }

            return;
        }

        // The round's stopwatch (public topic, RoundStopwatchState - RoundStopwatchStateController's shape)
        if (data && 'status' in data) {
            this.stopwatch = {
                status: data.status ?? null,
                startedAt: data.startedAt ?? null,
                stoppedAt: data.stoppedAt ?? null,
                startedAtMs: data.startedAtMs ?? null,
                stoppedAtMs: data.stoppedAtMs ?? null,
            };
            this.stopwatchKnownAt = Date.now();

            if (typeof data.minutesLimit === 'number' && this.round) {
                this.round.minutesLimit = data.minutesLimit;
            }

            this.tickClock(true);
            this.rememberState();
        }
    }

    // ---- Outbox ----

    setupOutbox() {
        const opened = createIndexedDbStorage().then((storage) => {
            if (storage !== null) {
                return storage;
            }

            this.storageMissing = true;
            this.scheduleRender();

            return createMemoryStorage();
        });
        const storage = {
            getAll: async () => (await opened).getAll(),
            get: async (id) => (await opened).get(id),
            put: async (item) => (await opened).put(item),
            delete: async (id) => (await opened).delete(id),
        };

        this.outbox = new Outbox({
            storage,
            userId: this.userId,
            newId: newClientId,
            send: (roundId, changes) => this.sendChanges(roundId, changes),
            lock: createTabLock(`msp-official-results-outbox:${this.userId}`),
            onEntries: (entries, roundId) => {
                if (roundId === this.roundIdValue) {
                    this.mergeEntries(entries, { fromOwnSave: true });
                }
            },
            onChange: () => {
                this.indexDirty = true;
                this.scheduleRender();
            },
        });

        this.outbox.load().then(async () => {
            this.foreign = await this.outbox.foreignCount();
            this.scheduleRender();
            this.flushSoon();
        });
    }

    async sendChanges(roundId, changes) {
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), REQUEST_TIMEOUT_MS);

        try {
            return await officialResultsRequest(this.recordUrlValue.replace(UUID_PLACEHOLDER, roundId), {
                method: 'POST',
                body: { changes },
                csrfToken: this.csrfTokenValue,
                signal: abort.signal,
            });
        } finally {
            clearTimeout(timer);
        }
    }

    flushSoon() {
        if (!this.outbox || !this.outbox.hasUnsent()) {
            return;
        }

        this.outbox.flush().then(() => this.broadcast());
    }

    broadcast() {
        this.channel?.postMessage({ type: 'outbox', userId: this.userId });
    }

    enqueue(entry, field, to) {
        const server = this.serverEntries.get(entry.ref);
        const { item, persisted } = this.outbox.enqueue({
            roundId: this.roundIdValue,
            entryRef: entry.ref,
            newEntry: server === undefined && entry.newEntry ? entry.newEntry : null,
            field,
            serverValue: server !== undefined ? entryValue(server, field) : (field === 'qualified' ? false : null),
            to,
            // The name stays readable on the "unsent" list even when the entry is gone or in another round
            label: entry.displayName ?? entry.name ?? '',
        });

        persisted
            .catch(() => {
                this.storageMissing = true;
                this.scheduleRender();
            })
            .finally(() => {
                this.broadcast();
                this.flushSoon();
            });

        return item;
    }

    async retryAll() {
        this.stateBlocked = null;

        if (!this.ready) {
            this.loadAttempts = 0;
            await this.loadNow();

            return;
        }

        await this.refreshState();
        await this.outbox.retryNow();
        this.broadcast();
    }

    // ---- Rendering (never touches an input) ----

    scheduleRender() {
        if (this.renderQueued) {
            return;
        }

        this.renderQueued = true;
        requestAnimationFrame(() => {
            this.renderQueued = false;

            if (this.isConnected()) {
                this.render();
            }
        });
    }

    render() {
        this.renderHeader();
        this.renderBanner();

        if (this.view === 'find') {
            this.renderFind();
        }

        if (this.view === 'entry' || this.view === 'confirm') {
            this.renderEntryInfo();
        }

        if (!this.sheetTarget.hidden) {
            this.renderSheet();
        }
    }

    renderHeader() {
        const status = this.outbox ? this.outbox.status() : { pending: 0, conflicts: 0, rejected: 0, forbidden: 0, blocked: null, offline: false, sending: false };
        const blocked = status.blocked ?? this.stateBlocked;
        let state = 'saved';
        let text = this.t('syncSaved');

        if (!this.ready && this.shellScreen !== null) {
            state = 'waiting';
            text = this.t('shellOffline');
        } else if (!this.ready && (blocked === null || blocked === undefined)) {
            // Nothing of the round yet - no claim that anything is saved
            state = 'waiting';
            text = this.t('loading');
        } else if (blocked === 'auth') {
            state = 'blocked';
            text = this.t('syncSignIn');
        } else if (blocked === 'forbidden') {
            state = 'blocked';
            text = this.t('syncForbidden');
        } else if (blocked === 'csrf') {
            state = 'blocked';
            text = this.t('syncReload');
        } else if (blocked === 'gone') {
            state = 'blocked';
            text = this.t('syncGone');
        } else if (status.conflicts + status.rejected + status.forbidden > 0) {
            state = 'problem';
            text = this.plural('attention', status.conflicts + status.rejected + status.forbidden);
        } else if (status.pending > 0) {
            state = 'waiting';
            text = status.offline ? this.plural('offline', status.pending) : (status.sending ? this.t('syncSending') : this.plural('waiting', status.pending));
        }

        this.pillTarget.dataset.state = state;
        this.pillTarget.dataset.sending = status.sending ? '1' : '0';

        if (this.pillTextTarget.textContent !== text) {
            this.pillTextTarget.textContent = text;
        }

        if (this.usesTables() && this.round?.entries) {
            const tables = this.t('tables', { '%assigned%': this.round.entries.withTableNumber, '%total%': this.round.entries.total });
            this.tablesTarget.hidden = false;
            this.tablesTarget.textContent = tables;

            this.readinessTarget.hidden = !this.settings.organiser || this.round.tablesReadiness !== true || this.round.entries.withTableNumber >= this.round.entries.total;
        } else {
            this.tablesTarget.hidden = true;
            this.readinessTarget.hidden = true;
        }
    }

    renderBanner() {
        const blocked = this.outbox?.blocked ?? null;
        const reason = blocked === 'auth' || this.stateBlocked === 'auth' ? 'auth' : (this.stateBlocked ?? blocked);
        const parts = [];

        if (!this.ready && this.shellScreen !== null) {
            parts.push(this.shellScreenHtml());
        } else if (reason === 'auth' || reason === 'forbidden') {
            // Signed out, or no rights for THIS round's event: nothing of it can be sent
            parts.push(`<div class="alert alert-danger mb-2" role="alert">
                <p class="mb-2">${escapeHtml(this.t(reason === 'auth' ? 'bannerAuth' : 'bannerForbidden'))}</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-danger" href="${escapeHtml(this.loginUrlValue)}" target="_blank" rel="noopener">${escapeHtml(this.t('signIn'))}</a>
                    <button type="button" class="btn btn-outline-danger" data-action="live-results#retry">${escapeHtml(this.t('retry'))}</button>
                </div>
            </div>`);
        } else if (reason === 'csrf') {
            parts.push(`<div class="alert alert-danger mb-2" role="alert">
                <p class="mb-2">${escapeHtml(this.t('bannerCsrf'))}</p>
                <button type="button" class="btn btn-danger" data-action="live-results#reload">${escapeHtml(this.t('reload'))}</button>
            </div>`);
        } else if (reason === 'gone') {
            parts.push(`<div class="alert alert-danger mb-2" role="alert" data-live-round-gone>${escapeHtml(this.t('bannerGone'))}</div>`);
        } else if (!this.ready && this.loadFailed) {
            parts.push(`<div class="alert alert-warning mb-2" role="alert" data-live-load-failed>
                <p class="mb-2">${escapeHtml(this.t('loadFailed'))}</p>
                <button type="button" class="btn btn-warning" data-action="live-results#retry">${escapeHtml(this.t('retry'))}</button>
            </div>`);
        }

        // No rights for another round's event (yesterday's event, a removed maintainer): only those changes wait -
        // everything of this round goes on
        const forbidden = this.outbox ? this.outbox.status().forbidden : 0;

        if (forbidden > 0 && reason !== 'forbidden') {
            parts.push(`<div class="alert alert-warning mb-2" role="alert">
                <p class="mb-2">${escapeHtml(this.plural('forbiddenRounds', forbidden))}</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-warning" data-action="live-results#openSheet">${escapeHtml(this.t('showUnsent'))}</button>
                    <a class="btn btn-outline-secondary" href="${escapeHtml(this.loginUrlValue)}" target="_blank" rel="noopener">${escapeHtml(this.t('signIn'))}</a>
                </div>
            </div>`);
        }

        if (this.offlineCopyAt !== null) {
            parts.push(`<div class="alert alert-secondary mb-2 small" role="status" data-live-offline-copy>${escapeHtml(this.t('offlineCopy', { '%time%': this.timeOfDay(new Date(this.offlineCopyAt).toISOString()) }))}</div>`);
        }

        if (this.storageMissing) {
            parts.push(`<div class="alert alert-warning mb-2 small" role="alert">${escapeHtml(this.t('bannerStorage'))}</div>`);
        }

        if (this.foreign > 0) {
            parts.push(`<div class="alert alert-secondary mb-2 small" role="status">${escapeHtml(this.plural('foreign', this.foreign))}</div>`);
        }

        const html = parts.join('');

        if (this.bannerHtml !== html) {
            this.bannerHtml = html;
            this.bannerTarget.innerHTML = html;
            this.bannerTarget.hidden = html === '';
        }
    }

    /**
     * The offline copy's screens - neutral words (a non-referee scanning a tag offline gets them too, R §1.3).
     */
    shellScreenHtml() {
        const retry = `<button type="button" class="btn btn-outline-secondary" data-action="live-results#reload">${escapeHtml(this.t('retry'))}</button>`;

        if (this.shellScreen === 'signedOut') {
            const unsent = this.shellUnsent > 0 ? `<p class="small mb-2">${escapeHtml(this.plural('foreign', this.shellUnsent))}</p>` : '';

            return `<div class="alert alert-secondary mb-2" role="status" data-live-shell-screen="signed-out">
                <p class="mb-2">${escapeHtml(this.t('shellSignedOut'))}</p>
                ${unsent}
                <div class="d-flex flex-wrap gap-2">
                    ${retry}
                    <a class="btn btn-outline-secondary" href="${escapeHtml(this.loginUrlValue)}">${escapeHtml(this.t('signIn'))}</a>
                </div>
            </div>`;
        }

        const others = this.shellOthers.slice(0, 10).map((snapshot) => {
            const round = snapshot.state?.round?.name ?? '';
            const event = snapshot.state?.competition?.name ?? '';

            return `<a class="list-group-item list-group-item-action" href="${escapeHtml(this.liveUrl(snapshot.roundId))}">${escapeHtml(round)}${event !== '' ? ` <span class="text-muted small">· ${escapeHtml(event)}</span>` : ''}</a>`;
        }).join('');

        return `<div class="alert alert-secondary mb-2" role="status" data-live-shell-screen="no-copy">
                <p class="mb-2">${escapeHtml(this.t('shellNoCopy'))}</p>
                <div class="d-flex flex-wrap gap-2">${retry}</div>
            </div>${others !== '' ? `<p class="small text-muted mb-1">${escapeHtml(this.t('shellOtherCopies'))}</p><div class="list-group mb-2">${others}</div>` : ''}`;
    }

    renderFind() {
        const index = this.searchIndex();
        const items = this.outbox ? this.outbox.all() : [];
        const typed = this.query.trim() !== '';
        const tableNumbersOff = !this.usesTables();

        this.recentBlockTarget.hidden = typed;

        if (typed) {
            const found = searchEntries(index, this.query, { tableNumbersOff });
            this.matchesTarget.innerHTML = found.matches.length > 0
                ? found.matches.map((entry) => this.rowHtml(entry, items, entry === found.exactTable)).join('')
                : `<p class="text-muted text-center my-3 mb-0">${escapeHtml(this.t('matchesNone'))}</p>`;
        } else {
            this.matchesTarget.innerHTML = '';
            const recent = recentEntries(this.allEntries(), items.filter((item) => item.roundId === this.roundIdValue));
            this.recentTarget.innerHTML = recent.length > 0
                ? recent.map(({ entry }) => this.rowHtml(entry, items, false)).join('')
                : `<p class="text-muted small mb-0">${escapeHtml(this.t('recentEmpty'))}</p>`;
        }
    }

    rowHtml(entry, items, exact) {
        const result = shownValue(entry, 'result', items);
        const own = items.filter((item) => item.entryRef === entry.ref);
        const sub = [];

        if (entry.kind === 'team' && entry.name !== null && Array.isArray(entry.members)) {
            sub.push(entry.members.map((member) => member.name).join(', '));
        }

        const code = entry.playerCode ?? null;

        if (code) {
            sub.push(`#${code.toUpperCase()}`);
        }

        if (entry.local) {
            sub.push(this.t('local'));
        }

        let sync = '';

        if (own.some((item) => item.state === 'conflict' || item.state === 'rejected')) {
            sync = `<i class="bi bi-exclamation-triangle-fill text-danger" title="${escapeHtml(this.t('rowProblem'))}"></i><span class="visually-hidden">${escapeHtml(this.t('rowProblem'))}</span>`;
        } else if (own.length > 0) {
            sync = `<i class="bi bi-clock-history text-warning" title="${escapeHtml(this.t('rowWaiting'))}"></i><span class="visually-hidden">${escapeHtml(this.t('rowWaiting'))}</span>`;
        } else if (entry.result !== null && entry.result !== undefined) {
            sync = `<i class="bi bi-check2 text-success" title="${escapeHtml(this.t('rowSaved'))}"></i><span class="visually-hidden">${escapeHtml(this.t('rowSaved'))}</span>`;
        }

        const flag = typeof entry.country === 'string' && /^[a-z]{2}(-[a-z]{2,4})?$/.test(entry.country)
            ? `<span class="fi fi-${entry.country} me-1" aria-hidden="true"></span>`
            : '';

        return `<button type="button" class="lr-row${exact ? ' lr-row-exact' : ''}" data-action="live-results#openRow" data-ref="${escapeHtml(entry.ref)}">
            ${this.tableHtml(entry.tableNumber)}
            <span class="lr-row-main">
                <span class="lr-row-name">${flag}${escapeHtml(entry.displayName ?? entry.name ?? '')}</span>
                ${sub.length > 0 ? `<span class="lr-row-sub">${escapeHtml(sub.join(' · '))}</span>` : ''}
            </span>
            <span class="lr-row-result">${escapeHtml(result.value === null || result.value === undefined ? '' : this.describe(result.value))}</span>
            <span class="lr-row-sync">${sync}</span>
        </button>`;
    }

    tableHtml(number) {
        if (!this.usesTables()) {
            return '';
        }

        return typeof number === 'number'
            ? `<span class="lr-table" title="${escapeHtml(this.t('table', { '%number%': number }))}">${number}</span>`
            : `<span class="lr-table lr-table-none" title="${escapeHtml(this.t('noTable'))}">–</span>`;
    }

    renderEntryInfo() {
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;

        if (entry === null) {
            return;
        }

        const items = this.outbox ? this.outbox.all() : [];
        const name = entry.displayName ?? entry.name ?? '';

        this.setText(this.entryNameTarget, name);
        this.setText(this.entryMembersTarget, entry.kind === 'team' && entry.name !== null ? (entry.members ?? []).map((member) => member.name).join(', ') : (entry.playerCode ? `#${entry.playerCode.toUpperCase()}` : ''));
        this.setText(this.entryTableTarget, typeof entry.tableNumber === 'number' ? String(entry.tableNumber) : '–');
        this.entryTableTarget.classList.toggle('lr-table-none', typeof entry.tableNumber !== 'number');

        const shown = shownValue(entry, 'result', items);
        let now;

        if (shown.item !== null && shown.item.state === 'pending') {
            now = `<i class="bi bi-clock-history text-warning me-1" aria-hidden="true"></i>${escapeHtml(this.t('waiting', { '%result%': this.describe(shown.value) }))}`;
        } else if (entry.result !== null && entry.result !== undefined) {
            now = entry.enteredBy
                ? escapeHtml(this.t('savedBy', { '%result%': this.describe(entry.result), '%name%': this.whoName(entry.enteredBy), '%time%': this.timeOfDay(entry.enteredAt) }))
                : escapeHtml(this.t('saved', { '%result%': this.describe(entry.result) }));
            now = `<i class="bi bi-check2-circle text-success me-1" aria-hidden="true"></i>${now}`;
        } else {
            now = `<span class="text-muted">${escapeHtml(entry.local ? this.t('local') : this.t('noResult'))}</span>`;
        }

        if (this.entryNowHtml !== now) {
            this.entryNowHtml = now;
            this.entryNowTarget.innerHTML = now;
        }

        const problems = items.filter((item) => item.entryRef === entry.ref && (item.state !== 'pending' || item.attempts >= STUCK_AFTER_ATTEMPTS));
        const problemsHtml = problems.map((item) => this.problemHtml(item, entry, false)).join('');

        if (this.entryProblemsHtml !== problemsHtml) {
            this.entryProblemsHtml = problemsHtml;
            this.entryProblemsTarget.innerHTML = problemsHtml;
        }

        this.clearButtonTarget.disabled = shown.value === null || shown.value === undefined;
    }

    describeField(field, value) {
        if (field === 'result') {
            return value === null ? this.t('noResult') : this.describe(value);
        }

        if (field === 'table_number') {
            return value === null ? this.t('noTable') : this.t('table', { '%number%': value });
        }

        return value === true ? this.t('qualified') : this.t('notQualified');
    }

    problemHtml(item, entry, withName) {
        const fieldLabel = { result: this.t('fieldResult'), table_number: this.t('fieldTable'), qualified: this.t('fieldQualified') }[item.field] ?? '';
        const title = withName ? `<strong>${escapeHtml(entry?.displayName ?? '')}</strong> · ${escapeHtml(fieldLabel)}` : `<strong>${escapeHtml(fieldLabel)}</strong>`;
        const id = escapeHtml(item.id);

        if (item.state === 'conflict') {
            const theirs = this.describeField(item.field, item.current ?? null);
            const text = item.enteredBy
                ? this.t('conflictBy', { '%result%': theirs, '%name%': this.whoName(item.enteredBy), '%time%': this.timeOfDay(item.enteredAt) })
                : this.t('conflict', { '%result%': theirs });

            return `<div class="alert alert-danger lr-problem" role="alert">
                <div>${title}</div>
                <p class="mb-1">${escapeHtml(text)}</p>
                <p class="mb-2">${escapeHtml(this.t('yours', { '%result%': this.describeField(item.field, item.to) }))}</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-danger" data-action="live-results#keepMine" data-id="${id}">${escapeHtml(this.t('keepMine'))}</button>
                    <button type="button" class="btn btn-outline-danger" data-action="live-results#takeTheirs" data-id="${id}">${escapeHtml(this.t('takeTheirs'))}</button>
                </div>
            </div>`;
        }

        if (item.state === 'rejected') {
            // The server's translated reason; a refusal the device made up itself gets the generic text - never a key
            const message = typeof item.message === 'string' && item.message !== '' ? item.message : this.t('invalidChange');
            const fixable = item.field === 'result' && item.reason !== 'round_not_found' && item.roundId === this.roundIdValue;

            return `<div class="alert alert-danger lr-problem" role="alert">
                <div>${title}: ${escapeHtml(this.describeField(item.field, item.to))}</div>
                <p class="mb-2">${escapeHtml(this.t('rejected', { '%message%': message }))}</p>
                <div class="d-flex flex-wrap gap-2">
                    ${fixable ? `<button type="button" class="btn btn-danger" data-action="live-results#fix" data-id="${id}" data-ref="${escapeHtml(item.entryRef)}">${escapeHtml(this.t('fix'))}</button>` : ''}
                    <button type="button" class="btn btn-outline-danger" data-action="live-results#discard" data-id="${id}">${escapeHtml(this.t('discard'))}</button>
                </div>
            </div>`;
        }

        if (item.state === 'forbidden') {
            return `<div class="alert alert-warning lr-problem" role="alert">
                <div>${title}: ${escapeHtml(this.describeField(item.field, item.to))}</div>
                <p class="mb-2">${escapeHtml(this.t('problemForbidden'))}</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-action="live-results#retryItem" data-id="${id}">${escapeHtml(this.t('retryNow'))}</button>
                    <button type="button" class="btn btn-outline-danger" data-action="live-results#discard" data-id="${id}" data-confirm="1">${escapeHtml(this.t('discard'))}</button>
                </div>
            </div>`;
        }

        const seconds = Math.max(0, Math.ceil((Math.max(item.nextAttemptAt, this.outbox.retryAt) - Date.now()) / 1000));
        let waiting = this.t('sheetWaiting');

        if (this.outbox.inFlight.has(item.id)) {
            waiting = this.t('sheetSending');
        } else if (this.outbox.offline) {
            waiting = this.t('sheetOffline');
        } else if (this.outbox.busy) {
            waiting = this.t('sheetBusy');
        } else if (item.attempts >= STUCK_AFTER_ATTEMPTS) {
            waiting = this.t('stuck');
        }

        // A change of an event this page is not about can be let go (the referee may have lost the rights to it)
        const elsewhere = !this.rounds.some((round) => round.id === item.roundId);

        return `<div class="alert ${item.attempts >= STUCK_AFTER_ATTEMPTS ? 'alert-warning' : 'alert-light border'} lr-problem py-2">
            <div>${title}: ${escapeHtml(this.describeField(item.field, item.to))}</div>
            <div class="small">${escapeHtml(waiting)}${seconds > 0 ? ` · ${escapeHtml(this.t('retryIn', { '%seconds%': seconds }))}` : ''}</div>
            <div class="d-flex flex-wrap gap-2">
                ${item.attempts > 0 || this.outbox.offline || this.outbox.busy ? `<button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-action="live-results#retryItem" data-id="${id}">${escapeHtml(this.t('retryNow'))}</button>` : ''}
                ${elsewhere ? `<button type="button" class="btn btn-sm btn-outline-danger mt-2" data-action="live-results#discard" data-id="${id}" data-confirm="1">${escapeHtml(this.t('discard'))}</button>` : ''}
            </div>
        </div>`;
    }

    renderSheet() {
        const items = this.outbox.all();

        if (items.length === 0) {
            this.sheetBodyTarget.innerHTML = `<p class="text-success fs-5 my-4 text-center"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>${escapeHtml(this.t('sheetEmpty'))}</p>`;

            return;
        }

        const entries = new Map(this.allEntries().map((entry) => [entry.ref, entry]));
        const roundNames = new Map(this.rounds.map((round) => [round.id, round.name]));
        const sorted = [...items].sort((a, b) => Number(a.state === 'pending') - Number(b.state === 'pending') || a.seq - b.seq);

        this.sheetBodyTarget.innerHTML = sorted.map((item) => {
            const entry = entries.get(item.entryRef) ?? (item.newEntry ? this.localEntry(item.newEntry) : { displayName: item.label ?? '' });
            const other = item.roundId !== this.roundIdValue
                ? `<div class="small text-muted mb-1">${escapeHtml(roundNames.has(item.roundId) ? this.t('sheetOtherRound', { '%round%': roundNames.get(item.roundId) }) : this.t('sheetOtherEvent'))}</div>`
                : '';

            return other + this.problemHtml(item, entry, true);
        }).join('');

        const forbidden = items.filter((item) => item.state === 'forbidden').length;

        if (forbidden > 1) {
            this.sheetBodyTarget.insertAdjacentHTML('beforeend', `<button type="button" class="btn btn-outline-danger w-100 mt-2 lr-big-btn fw-normal" data-action="live-results#discardForbidden">${escapeHtml(this.t('discardAll'))}</button>`);
        }

        if (items.some((item) => item.state === 'pending' || item.state === 'forbidden') || this.outbox.blocked !== null) {
            this.sheetBodyTarget.insertAdjacentHTML('beforeend', `<button type="button" class="btn btn-outline-primary w-100 mt-2 lr-big-btn fw-normal" data-action="live-results#retry">${escapeHtml(this.t('sheetRetryAll'))}</button>`);
        }
    }

    setText(element, text) {
        if (element.textContent !== text) {
            element.textContent = text;
        }
    }

    notice(text, kind = 'warning', extraHtml = '') {
        this.findNoticeTarget.innerHTML = `<div class="alert alert-${kind} py-2 mb-0" role="alert">${escapeHtml(text)}${extraHtml}</div>`;
        this.findNoticeTarget.hidden = false;
    }

    clearNotice() {
        this.findNoticeTarget.hidden = true;
        this.findNoticeTarget.innerHTML = '';
    }

    // ---- Stopwatch ----

    serverNow() {
        return this.serverClock.serverNow();
    }

    /**
     * Seconds since the start at `at` (server time) - null while the stopwatch is not running: before a scheduled start
     * there is nothing to finish yet, and a time is never negative (round_stopwatch_clock.js).
     */
    elapsedSeconds(at = this.serverNow()) {
        const start = startedAtMs(this.stopwatch);

        if (stopwatchStatusAt(this.stopwatch, at) !== 'running' || start === null) {
            return null;
        }

        return Math.max(0, Math.floor((at - start) / 1000));
    }

    tickClock(force = false) {
        const now = this.serverNow();
        // A scheduled start runs from its moment on - this page follows its own clock, nothing tells it then
        const status = stopwatchStatusAt(this.stopwatch, now);
        const elapsed = this.elapsedSeconds(now);
        const start = startedAtMs(this.stopwatch);
        const limit = (this.round?.minutesLimit ?? 0) * 60;
        let text;
        let state = status ?? 'none';

        if (status === 'scheduled' && start !== null) {
            text = this.t('stopwatchStartsIn', { '%time%': formatCountdown(start - now) });
        } else if (status === 'running' && elapsed !== null) {
            text = `${this.t('stopwatchRunning')} ${formatResultTime(elapsed)}`;

            if (limit > 0 && elapsed >= limit) {
                text = `${this.t('stopwatchTimesUp')} ${formatResultTime(elapsed)}`;
                state = 'times_up';
            }
        } else if (status === 'stopped' && this.stopwatch.startedAt && this.stopwatch.stoppedAt) {
            text = `${this.t('stopwatchStopped')} ${formatResultTime(Math.floor((Date.parse(this.stopwatch.stoppedAt) - Date.parse(this.stopwatch.startedAt)) / 1000))}`;
        } else {
            text = this.t('stopwatchNotStarted');
        }

        if (!this.ready) {
            // The stopwatch comes with the round's state
            text = '\u00a0';
            state = 'none';
        }

        if (!force && text === this.lastClockText) {
            return;
        }

        this.lastClockText = text;
        this.stopwatchTarget.textContent = text;
        this.stopwatchTarget.dataset.state = state;

        const running = status === 'running' && elapsed !== null;
        this.finishedNowTarget.hidden = !running;

        if (running) {
            this.finishedNowClockTarget.textContent = formatResultTime(elapsed);
            this.finishedNowTarget.classList.toggle('btn-success', !(limit > 0 && elapsed >= limit));
            this.finishedNowTarget.classList.toggle('btn-warning', limit > 0 && elapsed >= limit);
        }
    }

    // ---- Find ----

    search() {
        this.query = this.findInputTarget.value;
        this.clearNotice();
        this.renderFind();
    }

    find(event) {
        event.preventDefault();
        this.query = this.findInputTarget.value;
        const found = searchEntries(this.searchIndex(), this.query, { tableNumbersOff: !this.usesTables() });
        const entry = entryForEnter(found);

        if (entry !== null) {
            this.openEntry(entry.ref);
        } else {
            this.renderFind();
        }
    }

    openRow(event) {
        const ref = event.currentTarget.dataset.ref;

        if (ref) {
            this.openEntry(ref);
        }
    }

    toggleKeyboard() {
        const next = this.keyboard === 'numeric' ? 'text' : 'numeric';
        this.setKeyboard(next);
        writePrefs(webStorage('localStorage') ?? { getItem: () => null, setItem: () => {}, removeItem: () => {} }, { keyboard: next });
        // The new keyboard shows only on a fresh focus
        this.findInputTarget.blur();
        this.findInputTarget.focus();
    }

    setKeyboard(mode) {
        this.keyboard = mode === 'numeric' && this.usesTables() ? 'numeric' : 'text';
        const input = this.findInputTarget;

        if (this.keyboard === 'numeric') {
            input.setAttribute('inputmode', 'numeric');
            input.setAttribute('pattern', '[0-9]*');
        } else {
            input.setAttribute('inputmode', 'search');
            input.removeAttribute('pattern');
        }

        if (this.hasKeyboardToggleTarget) {
            this.keyboardToggleTarget.textContent = this.keyboard === 'numeric' ? this.t('keyboardLetters') : this.t('keyboardDigits');
        }
    }

    switchRound(event) {
        const roundId = event.target.value;

        if (!roundId || roundId === this.roundIdValue) {
            return;
        }

        storageSet(ROUND_KEY + (this.settings.competitionId ?? ''), JSON.stringify({ roundId, at: Date.now() }));
        // Another live entry page: it sends this device's unsent changes too
        this.confirmLeaving();
        window.location.assign(this.liveUrl(roundId));
    }

    confirmLeaving() {
        this.leaveConfirmedAt = Date.now();
    }

    leaveConfirmed() {
        return this.leaveConfirmedAt !== undefined && Date.now() - this.leaveConfirmedAt < LEAVE_CONFIRMED_MS;
    }

    readRememberedRound(competitionId = this.settings.competitionId) {
        try {
            return JSON.parse(storageGet(ROUND_KEY + (competitionId ?? '')) ?? 'null');
        } catch (e) {
            return null;
        }
    }

    liveUrl(roundId) {
        return this.liveUrlValue.replace(UUID_PLACEHOLDER, roundId);
    }

    cleanUrl() {
        // ?entrant= / ?entry= / ?table= / ?auto= / ?notice= do their job once - a reload must not land again
        const url = urlWithoutLanding(window.location.href);

        if (url !== null) {
            window.history.replaceState(window.history.state, '', url);
        }
    }

    // ---- Views ----

    showView(view) {
        this.view = view;
        this.findViewTarget.hidden = view !== 'find';
        this.entryViewTarget.hidden = view !== 'entry';
        this.confirmViewTarget.hidden = view !== 'confirm';
        this.addViewTarget.hidden = view !== 'add';

        if (view !== 'find') {
            this.claimHistory();
        }

        window.scrollTo(0, 0);
    }

    openEntry(ref) {
        const entry = this.entry(ref);

        if (entry === null) {
            return;
        }

        this.closeOverlays({ keepHistory: true });
        this.openRef = ref;
        this.pendingValue = null;
        this.entryNowHtml = null;
        this.entryProblemsHtml = null;
        this.showTimeForm();

        // A refused result opens with the refused value, ready to fix
        const rejected = (this.outbox?.all() ?? []).find((item) => item.entryRef === ref && item.field === 'result' && item.state === 'rejected');
        this.timeInputTarget.value = rejected && rejected.to && typeof rejected.to.seconds === 'number' ? formatResultTime(rejected.to.seconds) : '';
        this.timeTyped();
        this.showView('entry');

        if (rejected && rejected.to && typeof rejected.to.piecesPlaced === 'number') {
            this.didNotFinish();
            this.piecesInputTarget.value = String(rejected.to.piecesPlaced);
            this.piecesTyped();
        }
        this.renderEntryInfo();
        this.tickClock(true);

        if (!this.piecesFormTarget.classList.contains('lr-hidden')) {
            this.piecesInputTarget.focus();
        } else if (!this.finishedNowTarget.hidden && this.timeInputTarget.value === '') {
            // A running stopwatch: "finished now" is the likely next tap - no keyboard over it
            this.finishedNowTarget.focus();
        } else {
            this.timeInputTarget.focus();
        }

        this.rememberDraft();
    }

    showTimeForm() {
        this.timeFormTarget.classList.remove('lr-hidden');
        this.piecesFormTarget.classList.add('lr-hidden');

        if (this.view === 'entry') {
            this.timeInputTarget.focus();
            this.rememberDraft();
        }
    }

    backToFind() {
        this.showFind({ focus: true });
        this.releaseHistory();
    }

    showFind({ focus = true } = {}) {
        this.closeOverlays({ keepHistory: true });
        // Saved, undone or left: nothing to bring back after a reload
        this.forgetDraft();
        this.openRef = null;
        this.pendingValue = null;
        this.findInputTarget.value = '';
        this.query = '';
        this.showView('find');
        this.renderFind();

        if (focus) {
            this.findInputTarget.focus();
        }
    }

    closeEverything() {
        if (!this.scannerTarget.hidden || !this.sheetTarget.hidden) {
            this.closeOverlays();

            return;
        }

        if (this.view === 'confirm') {
            this.backToEntry();

            return;
        }

        this.backToFind();
    }

    // History: a view or overlay over the find view gets an entry of its own, so the phone's back button/gesture
    // returns to finding instead of leaving the page (no Turbo on this page since competition toolkit C2 - the
    // browser's own history)
    claimHistory() {
        if (this.historyEntry) {
            return;
        }

        window.history.pushState({ liveResults: true }, '', window.location.href);
        this.historyEntry = true;
    }

    releaseHistory() {
        if (this.historyEntry) {
            this.historyEntry = false;
            this.leavingHistoryEntry = true;
            window.history.back();
        }
    }

    handlePopState() {
        if (this.leavingHistoryEntry) {
            this.leavingHistoryEntry = false;

            return;
        }

        if (this.historyEntry) {
            this.historyEntry = false;
            this.showFind({ focus: false });
        }
    }

    // ---- Enter ----

    timeTyped() {
        const text = this.timeInputTarget.value;
        const parsed = parseResultTime(text);
        const limit = (this.round?.minutesLimit ?? 0) * 60;
        let html;

        if (parsed.empty) {
            html = `<span class="text-muted small">${escapeHtml(this.t('timeHint'))}</span>`;
        } else if (parsed.error === 'out_of_range') {
            html = `<span class="text-danger">${escapeHtml(this.t('timeOutOfRange'))}</span>`;
        } else if (parsed.error) {
            html = `<span class="text-danger">${escapeHtml(this.t('timeInvalid'))}</span>`;
        } else {
            html = `<strong>${escapeHtml(this.t('timeParsed', { '%time%': formatResultTime(parsed.seconds) }))}</strong>`;

            if (limit > 0 && parsed.seconds > limit) {
                html += ` <span class="text-warning small">${escapeHtml(this.t('overLimit', { '%minutes%': this.round.minutesLimit }))}</span>`;
            }
        }

        this.timeInputTarget.classList.toggle('is-invalid', !parsed.empty && parsed.error !== undefined);
        this.timeParsedTarget.innerHTML = html;
        this.rememberDraft();
    }

    reviewTime(event) {
        event.preventDefault();
        const parsed = parseResultTime(this.timeInputTarget.value);

        if (parsed.empty || parsed.error) {
            this.timeTyped();
            this.timeInputTarget.focus();

            return;
        }

        this.review({ seconds: parsed.seconds });
    }

    async finishedNow() {
        // Frozen the moment it is tapped - on the server's clock
        const tappedAt = this.serverNow();
        const ref = this.openRef;

        // Live updates down for a while: the stopwatch may have been paused or stopped meanwhile - ask the server
        // before trusting it (a failed fetch keeps what is known: better than nothing on venue Wi-Fi)
        if (!this.stopwatchTrusted()) {
            this.finishedNowTarget.disabled = true;

            try {
                await this.refreshState();
            } finally {
                this.finishedNowTarget.disabled = false;
            }

            if (this.openRef !== ref || this.view !== 'entry') {
                return;
            }
        }

        const elapsed = this.elapsedSeconds(tappedAt);

        if (elapsed === null || elapsed < 1) {
            this.tickClock(true);
            this.setText(this.timeParsedTarget, this.t('finishedNowStopped'));
            this.timeInputTarget.focus();

            return;
        }

        this.review({ seconds: Math.min(elapsed, 86399) }, { finishedNow: true, tappedAt });
    }

    didNotFinish() {
        this.timeFormTarget.classList.add('lr-hidden');
        this.piecesFormTarget.classList.remove('lr-hidden');
        this.piecesInputTarget.value = '';
        this.piecesTyped();
        this.piecesInputTarget.focus();
    }

    piecesLimit() {
        const pieces = this.round?.piecesCount ?? null;

        return typeof pieces === 'number' && pieces > 1 ? pieces - 1 : null;
    }

    piecesTyped() {
        const text = this.piecesInputTarget.value.trim();
        const pieces = this.round?.piecesCount ?? null;
        const value = /^\d+$/.test(text) ? parseInt(text, 10) : null;
        const max = this.piecesLimit();
        let html;

        if (text === '') {
            html = `<span class="text-muted small">${escapeHtml(this.t('piecesHint'))}${pieces ? ` · ${escapeHtml(this.t('piecesOf', { '%pieces%': pieces }))}` : ''}</span>`;
        } else if (value === null || value < 1 || (max !== null && value > max)) {
            html = `<span class="text-danger">${escapeHtml(this.t('piecesInvalid'))}</span>`;
        } else {
            html = `<strong>${escapeHtml(this.describe({ piecesPlaced: value }))}</strong>`;
        }

        this.piecesInputTarget.classList.toggle('is-invalid', text !== '' && html.includes('text-danger'));
        this.piecesParsedTarget.innerHTML = html;
        this.rememberDraft();
    }

    reviewPieces(event) {
        event.preventDefault();
        const text = this.piecesInputTarget.value.trim();
        const value = /^\d+$/.test(text) ? parseInt(text, 10) : null;
        const max = this.piecesLimit();

        if (value === null || value < 1 || (max !== null && value > max)) {
            this.piecesTyped();
            this.piecesInputTarget.focus();

            return;
        }

        this.review({ piecesPlaced: value });
    }

    didNotStart() {
        this.review({ didNotStart: true });
    }

    clearResult() {
        this.review(null);
    }

    review(value, { finishedNow = false, tappedAt = null, restored = false } = {}) {
        const entry = this.entry(this.openRef);

        if (entry === null) {
            return;
        }

        this.pendingValue = value;
        this.pendingFinishedNow = finishedNow;
        this.pendingTappedAt = finishedNow ? tappedAt : null;
        const shown = shownValue(entry, 'result', this.outbox.all());
        const table = this.usesTables() && typeof entry.tableNumber === 'number' ? this.t('table', { '%number%': entry.tableNumber }) : '';

        this.confirmTableTarget.textContent = table;
        this.confirmNameTarget.textContent = entry.displayName ?? entry.name ?? '';
        this.confirmResultTarget.textContent = value === null ? this.t('clearResult') : this.describe(value);
        this.confirmResultTarget.classList.toggle('text-danger', value === null);

        let note = finishedNow ? this.t('finishedNowNote') : '';

        // Back after a reload: when it was tapped tells the referee the value is the frozen one
        if (finishedNow && restored && tappedAt !== null) {
            note = `${note} · ${this.t('finishedNowTappedAt', { '%time%': this.clockTime(tappedAt) })}`;
        }

        if (sameValue(shown.value, value)) {
            note = this.t('same');
        } else if (shown.value !== null && shown.value !== undefined) {
            const replaces = shown.item === null && entry.enteredBy
                ? this.t('replacesBy', { '%result%': this.describe(shown.value), '%name%': this.whoName(entry.enteredBy), '%time%': this.timeOfDay(entry.enteredAt) })
                : this.t('replaces', { '%result%': this.describe(shown.value) });
            note = note !== '' ? `${note} · ${replaces}` : replaces;
        }

        this.confirmNoteTarget.textContent = note;
        this.confirmNoteTarget.className = `small mb-3 ${shown.value !== null && shown.value !== undefined && !sameValue(shown.value, value) ? 'text-warning-emphasis fw-medium' : 'text-muted'}`;
        this.showView('confirm');
        this.saveButtonTarget.focus();
        this.rememberDraft();
    }

    clockTime(ms) {
        const date = new Date(ms);

        try {
            return date.toLocaleTimeString(this.localeValue || undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        } catch (e) {
            return date.toISOString().slice(11, 19);
        }
    }

    backToEntry() {
        this.showView('entry');
        this.rememberDraft();

        if (!this.piecesFormTarget.classList.contains('lr-hidden')) {
            this.piecesInputTarget.focus();
        } else {
            this.timeInputTarget.focus();
        }
    }

    save() {
        const entry = this.entry(this.openRef);

        if (entry === null) {
            this.backToFind();

            return;
        }

        const value = this.pendingValue;
        const shown = shownValue(entry, 'result', this.outbox.all());

        if (sameValue(shown.value, value) && (shown.item === null || shown.item.state === 'pending')) {
            this.showToast(this.t('toastNothing'), null);
            this.backToFind();

            return;
        }

        const item = this.enqueue(entry, 'result', value);

        if (navigator.vibrate) {
            navigator.vibrate(30);
        }

        this.showToast(this.t('toastSaved', {
            '%name%': entry.displayName ?? entry.name ?? '',
            '%result%': value === null ? this.t('clearResult') : this.describe(value),
        }), item);
        this.backToFind();
    }

    // ---- Toast + undo ----

    showToast(text, item) {
        clearTimeout(this.toastTimer);
        this.toastItem = item;
        this.toastTextTarget.textContent = text;
        this.toastUndoTarget.hidden = item === null;
        this.toastTarget.hidden = false;
        this.toastTimer = setTimeout(() => this.hideToast(), TOAST_MS);
    }

    hideToast() {
        clearTimeout(this.toastTimer);
        this.toastTarget.hidden = true;
        this.toastItem = null;
    }

    async undo() {
        const item = this.toastItem;
        this.hideToast();

        if (item === null) {
            return;
        }

        await this.outbox.undo(item);
        this.broadcast();
        this.flushSoon();
        this.showToast(this.t('toastUndone'), null);
        this.openEntry(item.entryRef);
    }

    // ---- Problems ----

    keepMine(event) {
        this.outbox.keepMine(event.currentTarget.dataset.id).finally(() => {
            this.broadcast();
            this.flushSoon();
        });
    }

    takeTheirs(event) {
        this.outbox.takeTheirs(event.currentTarget.dataset.id).finally(() => {
            this.broadcast();
            this.refreshState();
        });
    }

    discard(event) {
        // Never sent anywhere: a change set apart for missing rights, or of another event, goes only when confirmed
        if (event.currentTarget.dataset.confirm === '1' && !window.confirm(this.t('discardConfirm'))) {
            return;
        }

        this.outbox.remove(event.currentTarget.dataset.id).finally(() => this.broadcast());
    }

    discardForbidden() {
        const count = this.outbox.status().forbidden;

        if (count === 0 || !window.confirm(this.plural('discardAllConfirm', count))) {
            return;
        }

        this.outbox.discardWhere((item) => item.state === 'forbidden').finally(() => this.broadcast());
    }

    reload() {
        window.location.reload();
    }

    fix(event) {
        const ref = event.currentTarget.dataset.ref;
        this.closeOverlays({ keepHistory: true });

        if (ref) {
            this.openEntry(ref);
        }
    }

    retry() {
        this.retryAll();
    }

    retryItem(event) {
        this.outbox.retryNow(event.currentTarget.dataset.id).then(() => this.broadcast());
    }

    // ---- Overlays ----

    openSheet() {
        if (!this.ready) {
            return;
        }

        this.renderSheet();
        this.sheetTarget.hidden = false;
        this.claimHistory();
        this.sheetTarget.querySelector('button')?.focus();
    }

    closeOverlays({ keepHistory = false } = {}) {
        const wasOpen = !this.sheetTarget.hidden || !this.scannerTarget.hidden;
        this.sheetTarget.hidden = true;

        if (this.stopScan) {
            this.stopScan();
            this.stopScan = null;
        }

        this.scannerTarget.hidden = true;

        if (wasOpen && !keepHistory && this.view === 'find') {
            this.releaseHistory();
            this.findInputTarget.focus();
        }
    }

    async openScanner() {
        if (!this.ready) {
            return;
        }

        this.clearNotice();
        this.scannerTarget.hidden = false;
        this.scanHintTarget.textContent = this.t('scanHint');
        this.claimHistory();

        const stop = await startQrScan({
            video: this.videoTarget,
            onResult: (text) => this.scanned(text),
            onError: (reason) => {
                this.scanHintTarget.textContent = this.t(reason === 'decoder' ? 'scanDecoder' : 'scanCamera');
            },
        });

        // Closed while the camera was starting
        if (this.scannerTarget.hidden) {
            stop();
        } else {
            this.stopScan = stop;
        }
    }

    scanned(text) {
        this.stopScan = null;
        this.scannerTarget.hidden = true;
        const tag = parseNameTagUrl(text);

        if (tag === null || tag.competitionId !== String(this.settings.competitionId ?? '').toLowerCase()) {
            this.showFind({ focus: false });
            this.releaseHistory();
            this.notice(this.t('scanForeign'), 'warning');

            return;
        }

        const entry = entryOfParticipant(this.allEntries(), tag.participantId);

        if (entry === null) {
            this.showFind({ focus: false });
            this.releaseHistory();
            const link = fillLink(this.scanUrlValue, tag.participantId, this.settings.competitionId);
            this.notice(this.t('scanNotInRound', { '%round%': this.settings.roundName }), 'warning', ` <a class="alert-link" href="${escapeHtml(link)}">${escapeHtml(this.t('openTheirRound'))}</a>`);

            return;
        }

        if (navigator.vibrate) {
            navigator.vibrate(40);
        }

        this.openEntry(entry.ref);
    }

    // ---- Quick add ----

    openAdd() {
        if (!this.ready) {
            return;
        }

        // The event's people (asked for once) - the suggestions follow when they come
        this.loadEventPeople().then(() => {
            if (this.isConnected() && this.view === 'add') {
                this.addTyped();
            }
        });

        const typed = this.query.trim();
        this.clearNotice();
        this.addFormTarget.reset();
        this.addErrorTarget.hidden = true;
        this.lastMemberInput = null;

        for (const input of this.addFormTarget.querySelectorAll('input[data-participant-id]')) {
            delete input.dataset.participantId;
            delete input.dataset.participantName;
        }

        if (typed !== '' && /^\d+$/.test(typed) && this.settings.canSetTables) {
            this.addTableTarget.value = typed;
        } else if (typed !== '' && !typed.startsWith('#')) {
            this.addNameTarget.value = typed;
        }

        this.showView('add');
        this.addTyped();
        this.addNameTarget.focus();
    }

    addMemberInput(event = null, { focus = true } = {}) {
        const inputs = this.addMembersTarget.querySelectorAll('input[data-member]');

        if (inputs.length >= 20) {
            return;
        }

        const label = this.t('addMember').replace('__N__', String(inputs.length + 1));
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-lg';
        input.dataset.member = '';
        input.setAttribute('aria-label', label);
        input.placeholder = label;
        input.autocomplete = 'off';
        input.setAttribute('autocapitalize', 'words');
        input.maxLength = 255;
        this.addMembersTarget.appendChild(input);

        if (focus) {
            input.focus();
        }
    }

    /**
     * The quick add form: the name, the members - a member picked from the event's people carries their participant
     * id (`{participantId, name}`), a typed one is a name - and the table.
     */
    addValues() {
        const name = this.addNameTarget.value.trim();
        const members = !this.settings.solo
            ? [...this.addMembersTarget.querySelectorAll('input[data-member]')]
                .filter((input) => input.value.trim() !== '')
                .map((input) => (input.dataset.participantId ? { participantId: input.dataset.participantId, name: input.value.trim() } : input.value.trim()))
            : [];
        const tableText = this.settings.canSetTables ? this.addTableTarget.value.trim() : '';

        return { name, members, tableText };
    }

    /**
     * Participant ids that are an entry of this round already, or in one added on this device - not offered again.
     */
    participantIdsInRound() {
        const ids = new Set();

        for (const entry of this.allEntries()) {
            if (entry.participantId) {
                ids.add(String(entry.participantId).toLowerCase());
            }

            for (const member of entry.members ?? []) {
                if (member.participantId) {
                    ids.add(String(member.participantId).toLowerCase());
                }
            }
        }

        return ids;
    }

    eventPeopleIndex() {
        if (!this.peopleIndex) {
            this.peopleIndex = buildSearchIndex((this.eventPeople ?? []).map((person) => ({
                ref: `participant:${person.participantId}`,
                kind: 'person',
                name: person.name,
                displayName: person.name,
                members: [],
                playerCode: person.playerCode,
                tableNumber: null,
                participantId: person.participantId,
                country: person.country,
                result: null,
            })));
        }

        return this.peopleIndex;
    }

    addTyped(event = null) {
        const input = event?.target;

        if (input && input.dataset && 'member' in input.dataset) {
            this.lastMemberInput = input;

            // A member picked from the event's people and then retyped is somebody typed in
            if (input.dataset.participantId && input.value.trim() !== input.dataset.participantName) {
                delete input.dataset.participantId;
                delete input.dataset.participantName;
            }
        }

        const { name, members } = this.addValues();
        const typed = [name, ...members.map((member) => (typeof member === 'string' ? member : ''))].filter((value) => value.length >= 2);
        const seen = new Set();
        const suggestions = [];
        const people = [];
        const inRound = this.participantIdsInRound();
        const picked = new Set(members.filter((member) => typeof member !== 'string').map((member) => member.participantId));

        for (const text of typed) {
            for (const entry of searchEntries(this.searchIndex(), text, { tableNumbersOff: true, limit: 3 }).matches) {
                if (!seen.has(entry.ref) && suggestions.length < 5) {
                    seen.add(entry.ref);
                    suggestions.push(entry);
                }
            }

            for (const person of searchEntries(this.eventPeopleIndex(), text, { tableNumbersOff: true, limit: 5 }).matches) {
                const id = String(person.participantId).toLowerCase();

                if (!seen.has(person.ref) && !inRound.has(id) && !picked.has(person.participantId) && people.length < 5) {
                    seen.add(person.ref);
                    people.push(person);
                }
            }
        }

        const items = this.outbox ? this.outbox.all() : [];
        this.addSuggestionListTarget.innerHTML = suggestions.map((entry) => this.rowHtml(entry, items, false)).join('');
        this.addSuggestionsTarget.hidden = suggestions.length === 0;

        this.addPeopleListTarget.innerHTML = people.map((person) => this.personHtml(person)).join('');
        this.addPeopleTarget.hidden = people.length === 0;
    }

    personHtml(person) {
        const sub = [this.t('eventPerson')];

        if (person.playerCode) {
            sub.push(`#${String(person.playerCode).toUpperCase()}`);
        }

        const flag = typeof person.country === 'string' && /^[a-z]{2}(-[a-z]{2,4})?$/.test(person.country)
            ? `<span class="fi fi-${person.country} me-1" aria-hidden="true"></span>`
            : '';

        return `<button type="button" class="lr-row" data-action="live-results#pickEventPerson" data-participant-id="${escapeHtml(person.participantId)}">
            ${this.tableHtml(null)}
            <span class="lr-row-main">
                <span class="lr-row-name">${flag}${escapeHtml(person.name)}</span>
                <span class="lr-row-sub">${escapeHtml(sub.join(' · '))}</span>
            </span>
            <span class="lr-row-result"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>
            <span class="lr-row-sync"></span>
        </button>`;
    }

    /**
     * A person of the event picked in quick add: a solo round gets them as its entry at once (by their id, nobody
     * typed in twice); a pair/team round gets them as a member of the pair/team being added.
     */
    pickEventPerson(event) {
        const participantId = event.currentTarget.dataset.participantId;
        const person = (this.eventPeople ?? []).find((candidate) => candidate.participantId === participantId);

        if (!person) {
            return;
        }

        if (!this.settings.solo) {
            // The member field the referee was typing this person's name into, else the first empty one, else a new one
            const inputs = [...this.addMembersTarget.querySelectorAll('input[data-member]')];
            let input = inputs.includes(this.lastMemberInput) && !this.lastMemberInput.dataset.participantId
                ? this.lastMemberInput
                : inputs.find((candidate) => candidate.value.trim() === '');

            if (!input) {
                this.addMemberInput(null);
                const inputs = this.addMembersTarget.querySelectorAll('input[data-member]');
                input = inputs[inputs.length - 1];
            }

            if (!input) {
                return;
            }

            input.value = person.name;
            input.dataset.participantId = person.participantId;
            input.dataset.participantName = person.name;
            this.addTyped();

            return;
        }

        const { tableText } = this.addValues();
        const table = this.addTable(tableText);

        if (table === false) {
            return;
        }

        const newEntry = { clientEntryId: newClientId(), kind: 'person', participantId: person.participantId, name: person.name };
        const entry = { ...this.localEntry(newEntry), country: person.country ?? null, playerCode: person.playerCode ?? null };
        this.localEntries.set(entry.ref, entry);
        this.indexDirty = true;

        if (table !== null) {
            this.enqueue(entry, 'table_number', table);
        }

        this.openEntry(entry.ref);
    }

    /**
     * The quick add's table number: null for none, false when refused (the error is shown).
     */
    addTable(tableText) {
        if (tableText === '') {
            return null;
        }

        const table = /^\d+$/.test(tableText) ? parseInt(tableText, 10) : null;
        let error = null;

        if (table === null || table < 1 || table > 9999) {
            error = this.t('addTableInvalid');
        } else {
            const holder = this.allEntries().find((entry) => entry.tableNumber === table);

            if (holder) {
                error = this.t('addTableTaken', { '%number%': table, '%name%': holder.displayName ?? holder.name ?? '' });
            }
        }

        if (error !== null) {
            this.addErrorTarget.textContent = error;
            this.addErrorTarget.hidden = false;

            return false;
        }

        return table;
    }

    addEntrant(event) {
        event.preventDefault();
        const solo = this.settings.solo;
        const { name, members, tableText } = this.addValues();
        let error = null;

        if (solo && name === '') {
            error = this.t('addNameMissing');
        } else if (!solo && name === '' && members.length === 0) {
            error = this.t('addTeamMissing');
        }

        if (error !== null) {
            this.addErrorTarget.textContent = error;
            this.addErrorTarget.hidden = false;

            return;
        }

        const table = this.addTable(tableText);

        if (table === false) {
            return;
        }

        const clientEntryId = newClientId();
        const newEntry = solo
            ? { clientEntryId, kind: 'person', name }
            : { clientEntryId, kind: 'team', name: name === '' ? null : name, members };
        const entry = this.localEntry(newEntry);
        this.localEntries.set(entry.ref, entry);
        this.indexDirty = true;

        if (table !== null) {
            this.enqueue(entry, 'table_number', table);
        }

        this.openEntry(entry.ref);
    }
}
