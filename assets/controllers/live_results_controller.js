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
    confirmTapAllowed,
    describeResult,
    entriesWithWaitingTables,
    entryForEnter,
    entryValue,
    findRows,
    outboxByEntry,
    rowsOf,
    keepWithheldPlayers,
    preferredRound,
    sameValue,
    savedMeanwhile,
    searchEntries,
    shownFromOf,
    shownValue,
    uniqueTable,
    withShownFrom,
} from '../official_results_live.js';
import { nativeReadsQr, parseCompetitionTagUrl, startQrScan } from '../official_results_scan.js';
import { prepareOfflineCopy } from '../live_entry_offline_ready.js';
import { EntriesValidator, fillLink, landingOf, linkNavigation, pageSettings, resolveLanding, roundOptions, stateUrl, UUID_PLACEHOLDER, urlWithoutLanding } from '../live_entry_page.js';
import { createServerClock, formatCountdown, monotonicNow, startedAtMs, stopwatchStatusAt } from '../round_stopwatch_clock.js';
import { chooseTranslation } from '../translation_choice.js';
import { devicePlayerOf, forgetDraftsExcept, samePlayer } from '../live_entry_device.js';
import { createIndexedDbSnapshotStorage, createMemorySnapshotStorage, PREFETCH_GAP_MS, prefetchRounds, prefetchTargets, SnapshotStore } from '../live_entry_snapshot.js';
import { clearDraft, readDraft, readPrefs, writeDraft, writePrefs } from '../live_entry_draft.js';
import { parseLiveEntryRoute } from '../live_entry_route.js';
import { liveUrlOnceLive, loginUrlWithReturn, shellPlan, snapshotOfTag } from '../live_entry_shell.js';
import { entryOfTag, tagPath } from '../live_entry_tag.js';
import { choiceOf, describeChoice, missingChoice, pickFormat, piecesLimit, puzzleChips, saveFields, togglePick } from '../live_entry_puzzles.js';
import { finishesWholeRound, finishFields, finishGate, splitFormat, splitRows, splitStep } from '../live_entry_splits.js';
import { convertTyped, MISSING, openAfterPick, openFor, openOfRecord, opensUnfinished, pickUnfinished, piecesMode, timesUp, toggleOpen, typedPieces } from '../official_results_missing.js';
import { FIELD as MISSING_PIECES, missingOf, missingPiecesChange, parseMissingPieces, withMissing } from '../official_results_finished_missing.js';
import { myTablesOf, parseTables, recentMine, splitMine, todo, withMyTables } from '../live_entry_my_tables.js';
import { ageOf, applyOutcome, confirmFields, decisionOnDevice, mergeProposals, proposalAlternative, proposalNotes, proposalOfEntry, proposalPanel, proposalValue, proposerName, rejectionChange, waitingNote, waitingProposals } from '../live_entry_proposals.js';

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

// The native apps (Hotwire Native) run no service worker - the same test as assets/live_entry.js
function isNativeApp() {
    return /Turbo Native (iOS|Android)|MySpeedPuzzling (iOS|Android)/.test(navigator.userAgent || '');
}

export default class extends Controller {
    static targets = [
        'roundSelect', 'organiserLink', 'addTitle', 'addNameLabel', 'addMembersField', 'addTableField', 'addNote', 'addPeopleTitle', 'pill', 'pillText', 'stopwatch', 'tables', 'banner',
        'bannerBelow', 'findView', 'findInput', 'keyboardToggle', 'findNotice', 'readiness', 'matches', 'addButton', 'recentBlock', 'recent', 'recentTitle', 'missingButton', 'proposalsButton',
        'myTablesRow', 'myTablesChip', 'todoBlock', 'todoTitle', 'todoList', 'recentMore', 'myTablesInput', 'myTablesError',
        'entryView', 'entryTable', 'entryName', 'entryMembers', 'entryNow', 'entryProblems', 'puzzles', 'splits', 'proposal',
        'timeForm', 'timeInput', 'timeParsed', 'piecesForm', 'piecesInput', 'piecesParsed', 'piecesSwitch', 'piecesRows', 'piecesLabel', 'finishedNow', 'finishedNowClock', 'clearButton',
        'confirmView', 'confirmResult', 'confirmNote', 'saveButton', 'autoOpenToggle', 'missingToggle', 'missingField', 'missingInput', 'missingHint',
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
        // and the countdown agree with the projector. On the monotonic clock: a change of the phone's clock while the page
        // is open moves neither (D2); a sleep or a clock change seen asks for a fresh sample
        this.serverClock = createServerClock({
            onJump: () => {
                if (this.ready && this.isConnected()) {
                    this.refreshState();
                }
            },
        });
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
        // The hash of serverEntries while they are one full state's list (judge-speed J2) - the page's own polls send it
        this.entriesValidator = new EntriesValidator();
        this.localEntries = new Map();

        this.view = 'find';
        this.openRef = null;
        this.pendingValue = null;
        this.pendingFinishedNow = false;
        // What the open card showed of the server's values (shownFromOf()) - the `from` of its Save, never the value
        // merged in at Save (reliability F2, D7)
        this.shownFrom = null;
        // A pick round (toolkit C10): the open entry's puzzle choice (round puzzle ids, solving order); the find view's
        // "Puzzle missing" list on/off
        this.choice = [];
        this.missingOnly = false;
        // My tables on this phone (judge-speed J3): this event's {text, on, ranges} or null, read once per event; Recent
        // of every table after a tap on "+ N at other tables" (until a card opens)
        this.myTablesFor = undefined;
        this.myTablesPref = null;
        this.recentAll = false;
        // Didn't finish (reliability hardening T1): Missing | Placed, the pick round's puzzles not finished and their counts
        this.piecesPref = MISSING;
        this.openPuzzles = [];
        this.pieceTexts = {};
        // Time proposals (toolkit C9): the round's, by id - only while the competition has them on; the find view's
        // "Proposals waiting" list on/off; the proposal a Change edits (its Save confirms it with the judge's value);
        // the open card's "Not right" reasons shown; when the card's Confirm showed up (the 350 ms guard)
        this.proposals = new Map();
        this.proposalsOn = false;
        this.proposalsOnly = false;
        this.proposalEditing = null;
        this.rejecting = false;
        this.proposalShownAt = null;
        this.proposalPointerAt = null;
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
        // The state was asked for once after the server refused a set for another player's session (D1)
        this.accountAsked = false;
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
        // The page's offline copy on this device (toolkit C6): asked for once per page load, once the page is live -
        // null = not (yet) stored, else when
        this.offlinePrepared = false;
        this.offlineReadyAt = null;
        this.setupSnapshots();

        this.setKeyboard('text');

        this.onOnline = () => {
            // What waited only for the connection goes now, not after the rest of its offline pause
            this.connectionBack();

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
        };
        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                // A sleep in the background is counted before anything reads the clock (D2)
                this.serverClock.shown();
                this.refreshState();
                this.flushSoon();
            } else {
                this.serverClock.hidden();
                this.snapshots?.flush();
            }
        };
        // (Back from the back/forward cache, `visibilitychange` says visible again - the clock and the state with it)
        this.onPageHide = () => {
            this.serverClock.hidden();
            this.snapshots?.flush();
        };
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
            if ((this.ready && this.offlineCopyAt === null) || !this.isConnected() || kind === 'auth' || kind === 'forbidden' || kind === 'another_account' || this.stateBlocked === 'gone') {
                // Signed out / no rights / another player's session: the banner's Retry (and the window's focus) asks again
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
        this.prepareOffline();
    }

    /**
     * Once the page is live: the service worker stores the page's offline copy + its files (and the QR decoder where
     * the browser reads no QR codes itself), so a reload without a connection still opens it (toolkit C6). Not from an
     * offline copy (asked again when it goes live), not in the native apps (they run no worker).
     */
    prepareOffline() {
        if (this.offlinePrepared || this.offlineCopyAt !== null || isNativeApp() || !('serviceWorker' in navigator)) {
            return;
        }

        this.offlinePrepared = true;

        nativeReadsQr()
            .then((native) => prepareOfflineCopy({ serviceWorker: navigator.serviceWorker, locale: this.localeValue, scanner: !native }))
            .then((stored) => {
                if (stored !== null && this.isConnected()) {
                    this.offlineReadyAt = stored.storedAt;

                    if (!this.sheetTarget.hidden) {
                        this.renderSheet();
                    }
                }
            })
            .catch(() => {});
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
            // The page load's first state is stored at once - an offline reload a second later finds the round (D3)
            firstAtOnce: true,
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
        this.prepareOffline();
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
                ...(this.proposalsOn ? { proposals: [...this.proposals.values()] } : {}),
            },
            // Against the wall clock: the next page load's monotonic clock starts from it (D2)
            clockOffsetMs: this.wallClockOffsetMs(),
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
                extra: () => ({ clockOffsetMs: this.wallClockOffsetMs(), clockMeasuredAt: Date.now() }),
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
            piecesMode: this.piecesPref,
            open: this.openPuzzles,
            texts: this.pieceTexts,
            choice: pickFormat(this.round) ? this.choice : null,
            proposalId: this.proposalEditing,
            pending: confirm ? { value: this.pendingValue } : null,
            shownFrom: this.shownFrom,
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

        if (Array.isArray(draft.choice)) {
            this.choice = choiceOf(pickFormat(this.round), draft.choice.map((roundPuzzleId) => ({ roundPuzzleId })));
        }

        // Change on a proposal: its Save still confirms it (toolkit C9)
        if (draft.proposalId !== null && this.openProposal(draft.proposalId) !== null) {
            this.proposalEditing = draft.proposalId;
        }

        if (draft.mode === 'pieces') {
            this.didNotFinish();
            this.piecesPref = draft.piecesMode ?? this.piecesPref;
            this.openPuzzles = openFor(this.choice, draft.open ?? this.openPuzzles);
            this.pieceTexts = { ...draft.texts };
            this.piecesRowsTarget.dataset.shape = '';
            this.piecesInputTarget.value = draft.pieces;
            this.piecesTyped();
        } else {
            this.showTimeForm();
            this.timeInputTarget.value = draft.typed;
            this.timeTyped();
        }

        if (draft.view === 'confirm') {
            this.review(draft.pending.value, { finishedNow: draft.finishedNow !== null, tappedAt: draft.finishedNow?.tappedAt ?? null, restored: true, shownFrom: draft.shownFrom });
        } else if (draft.shownFrom !== null) {
            // The card's `from` is what it showed before the reload, not what the server holds now
            this.shownFrom = draft.shownFrom;
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

    describe(result, missing = null) {
        const text = describeResult(result, this.round?.piecesCount ?? null, {
            noResult: '–',
            piecesPlaced: this.t('piecesPlaced'),
            piecesPlacedOf: this.t('piecesPlacedOf'),
            didNotStart: this.t('didNotStart'),
        });

        // Finished with pieces missing (competition toolkit T6): "1:23:45 · 1 piece missing"
        return withMissing(text, result, missing, (count) => this.plural('missingPieces', count).split('%count%').join(String(count)));
    }

    /** The count of pieces missing from a finished puzzle the device shows for an entry (server or its own pending). */
    shownMissing(entry, items = this.outbox?.all() ?? []) {
        return missingOf(shownValue(entry, MISSING_PIECES, items).value);
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

    /**
     * @param {object} state a state answer, or a snapshot's state
     * @param {null|'full'|'unchanged'|'stale'|'none'} outcome EntriesValidator.answered()'s word on a state answer;
     *        null = not an answer to the page's own request (a snapshot) - its entries are no full answer
     */
    applyState(state, outcome = null) {
        this.competition = state.competition;
        this.round = state.round;
        this.rounds = Array.isArray(state.rounds) ? state.rounds : [];
        this.stopwatch = state.round?.stopwatch ?? { status: null, startedAt: null, stoppedAt: null };

        // An answer without entries (`?entries=0`) keeps the ones the page has
        if (Array.isArray(state.entries)) {
            if (outcome !== 'full') {
                this.entriesValidator.changed();
            }

            this.serverEntries = new Map(state.entries.map((entry) => [entry.ref, entry]));

            for (const ref of this.serverEntries.keys()) {
                this.localEntries.delete(ref);
            }
        }

        // Time proposals (toolkit C9): the state lists every pending one and those decided lately
        this.proposalsOn = Array.isArray(state.proposals);
        this.proposals = this.proposalsOn ? mergeProposals(this.proposals, state.proposals, { stateAtMs: serverTimeOf(state) }) : new Map();

        // The reader is decided by the first answer (the outbox is theirs); later answers keep it
        if (!this.ready) {
            this.viewer = state.viewer ?? null;
        }

        this.refreshSettings();

        // The server's entries are those the page holds (judge-speed J2): no index or view to build again
        if (outcome !== 'unchanged') {
            this.entriesChanged();
        }
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
            // No longer one full state's list - the next poll asks for every entry (judge-speed J2)
            this.entriesValidator.changed();
            countsChanged = countsChanged || before === undefined || before.tableNumber !== entry.tableNumber;
            this.serverEntries.set(entry.ref, keepWithheldPlayers(before, entry));
            this.localEntries.delete(entry.ref);
        }

        this.entriesChanged();
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
     * The entries or this device's changes changed: the search index and the entries view are built again when next
     * asked for.
     */
    entriesChanged() {
        this.indexDirty = true;
        this.entriesCache = null;
    }

    /**
     * allEntries() as a list and by ref - built once per change of the entries or the outbox (competition toolkit C7:
     * an entry's lookup is O(1), not a pass over every entry with every change).
     *
     * @returns {{list: object[], byRef: Map<string, object>}}
     */
    entriesView() {
        if (!this.entriesCache) {
            this.entriesCache = this.buildEntries();
        }

        return this.entriesCache;
    }

    /**
     * The server's entries, entrants added on this device and not on the server yet (from the outbox, or just typed
     * in), each with this device's unsent table number on top.
     */
    allEntries() {
        return this.entriesView().list;
    }

    buildEntries() {
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
        return entriesWithWaitingTables(entries, items);
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
        return this.entriesView().byRef.get(ref) ?? null;
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
        // The clock's samples are stamped on its own (monotonic) clock
        const sentAt = monotonicNow();
        // The entries this page holds, by hash - left out of the answer when they are still the server's (judge-speed J2)
        const sent = this.entriesValidator.request();

        try {
            const answer = await officialResultsRequest(stateUrl(this.stateUrlValue, this.roundIdValue, { known: sent.known }), { signal: abort.signal });
            const answeredAt = monotonicNow();
            const answeredAtWall = Date.now();

            if (answer.kind === 'ok') {
                const viewerId = answer.data.viewer?.playerId;

                // Another player signed in on this browser meanwhile (D1): the outbox stops (its changes stay on the
                // device for their player's next sign-in), nothing of the other player goes into this page - it loads
                // anew as theirs, as goLive() does. Not where the outbox is in memory only: the page waits for a sign-in.
                // A same-player state lets a retry send again (the outbox learns it either way).
                if (this.ready && typeof viewerId === 'string' && viewerId !== '' && this.outbox?.viewerIs(viewerId) === false) {
                    this.stateBlocked = 'another_account';
                    this.entriesValidator.changed();
                    this.events.suspend();
                    this.scheduleRender();

                    if (!this.storageMissing || !this.outbox.hasUnsent()) {
                        this.confirmLeaving();
                        window.location.reload();
                    }

                    return 'another_account';
                }

                this.stateBlocked = null;
                this.connectionBack();
                this.serverClock.observe({ sentAtMs: sentAt, receivedAtMs: answeredAt, serverNowMs: serverTimeOf(answer.data) });
                this.stopwatchKnownAt = answeredAtWall;
                const entries = this.entriesValidator.answered(sent, answer.data);
                this.applyState(answer.data, entries);
                this.scheduleRender();

                // No entries, and this page's may not be the server's any more (changed since the request): asked for
                // again at once - with no hash, so the answer is full
                if (entries === 'stale') {
                    setTimeout(() => this.isConnected() && this.refreshState(), 0);
                }

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
            } else if (data.type === 'official_results.proposals') {
                this.proposalsOn = true;
                this.proposals = mergeProposals(this.proposals, data.proposals);
                this.scheduleRender();
                this.rememberState();
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
            send: (roundId, changes, expectedPlayerId) => this.sendChanges(roundId, changes, expectedPlayerId),
            lock: createTabLock(`msp-official-results-outbox:${this.userId}`),
            onEntries: (entries, roundId) => {
                if (roundId === this.roundIdValue) {
                    this.mergeEntries(entries, { fromOwnSave: true });
                }
            },
            onChange: () => {
                this.entriesChanged();
                this.scheduleRender();

                // The server refused a set for another player's session (D1): the state says whose page this is now
                if (this.outbox?.blocked === 'another_account' && !this.accountAsked) {
                    this.accountAsked = true;
                    this.refreshState();
                }
            },
            // The server's word on the proposals the set decided (toolkit C9) - an old copy asks for the state
            onProposals: (annotations, roundId) => {
                if (roundId !== this.roundIdValue) {
                    return;
                }

                let stale = false;

                for (const annotation of annotations) {
                    const applied = applyOutcome(this.proposals, annotation);
                    this.proposals = applied.proposals;
                    stale = stale || applied.stale;
                }

                this.scheduleRender();

                if (stale) {
                    this.refreshState();
                }
            },
        });

        this.outbox.load().then(async () => {
            this.foreign = await this.outbox.foreignCount();
            this.scheduleRender();
            this.flushSoon();
        });
    }

    async sendChanges(roundId, changes, expectedPlayerId = '') {
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), REQUEST_TIMEOUT_MS);

        try {
            return await officialResultsRequest(this.recordUrlValue.replace(UUID_PLACEHOLDER, roundId), {
                method: 'POST',
                // Whose changes they are - another player's session refuses them all (409 another_account, D1)
                body: expectedPlayerId ? { changes, expectedPlayerId } : { changes },
                csrfToken: this.csrfTokenValue,
                signal: abort.signal,
            });
        } finally {
            clearTimeout(timer);
        }
    }

    /**
     * The network answered (the `online` event, a state fetched): the outbox's changes that failed only for being
     * offline go at once (competition toolkit C7) - those set apart for rights or refusals stay apart.
     */
    connectionBack() {
        if (!this.outbox || !this.outbox.hasUnsent()) {
            return;
        }

        this.outbox.connectionBack().then(() => this.broadcast());
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
        return this.enqueueGroup(entry, [{ field, to }])[0];
    }

    /**
     * Changes of one entry saved together (a pick round's `puzzles` + `result`, toolkit C10): one group of the outbox,
     * sent in one request, applied together or not at all.
     */
    enqueueGroup(entry, fields) {
        const server = this.serverEntries.get(entry.ref);
        const { items, persisted } = this.outbox.enqueueGroup(fields.map(({ field, to, from, proposalId = null }) => ({
            roundId: this.roundIdValue,
            entryRef: entry.ref,
            newEntry: server === undefined && entry.newEntry ? entry.newEntry : null,
            field,
            // A rejection names its proposal (toolkit C9)
            serverValue: from !== undefined ? from : (server !== undefined ? entryValue(server, field) : (field === 'qualified' ? false : null)),
            to,
            proposalId,
            // The name stays readable on the "unsent" list even when the entry is gone or in another round
            label: entry.displayName ?? entry.name ?? '',
        })));

        persisted
            .catch(() => {
                this.storageMissing = true;
                this.scheduleRender();
            })
            .finally(() => {
                this.broadcast();
                this.flushSoon();
            });

        return items;
    }

    async retryAll() {
        this.stateBlocked = null;
        this.accountAsked = false;

        if (!this.ready) {
            this.loadAttempts = 0;
            await this.loadNow();

            return;
        }

        // The outbox sends again only after this state named its player (D1 - the sign-in tab may have been another's)
        if (await this.refreshState() === 'another_account') {
            return;
        }

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
        const blocked = this.stateBlocked === 'another_account' ? 'another_account' : (status.blocked ?? this.stateBlocked);
        let state = 'saved';
        let text = this.t('syncSaved');

        if (!this.ready && this.shellScreen !== null) {
            state = 'waiting';
            text = this.t('shellOffline');
        } else if (!this.ready && (blocked === null || blocked === undefined)) {
            // Nothing of the round yet - no claim that anything is saved
            state = 'waiting';
            text = this.t('loading');
        } else if (blocked === 'auth' || blocked === 'another_account') {
            state = 'blocked';
            text = this.t(blocked === 'auth' ? 'syncSignIn' : 'syncAnotherAccount');
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
        const reason = blocked === 'another_account' || this.stateBlocked === 'another_account'
            ? 'another_account'
            : (blocked === 'auth' || this.stateBlocked === 'auth' ? 'auth' : (this.stateBlocked ?? blocked));
        const parts = [];

        if (!this.ready && this.shellScreen !== null) {
            parts.push(this.shellScreenHtml());
        } else if (reason === 'auth' || reason === 'forbidden' || reason === 'another_account') {
            // Signed out, no rights for THIS round's event, or another player signed in here (D1 - this page's results
            // wait for their own player): nothing of it can be sent
            parts.push(`<div class="alert alert-danger mb-2" role="alert">
                <p class="mb-2">${escapeHtml(reason === 'another_account' ? this.plural('foreign', Math.max(1, this.outbox?.status().total ?? 0)) : this.t(reason === 'auth' ? 'bannerAuth' : 'bannerForbidden'))}</p>
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
        // A card or the quick add open: the banner goes below it - one inserted above would move Save (or Add) under
        // the finger between its pointer events (D6). The find view keeps it on top.
        // (An offline copy stored before the slot existed has only the top one)
        const below = this.view !== 'find' && this.hasBannerBelowTarget;
        const target = below ? this.bannerBelowTarget : this.bannerTarget;

        if (this.bannerHtml !== html || this.bannerPlace !== target) {
            this.bannerHtml = html;
            this.bannerPlace = target;

            for (const slot of this.hasBannerBelowTarget ? [this.bannerTarget, this.bannerBelowTarget] : [target]) {
                slot.innerHTML = slot === target ? html : '';
                slot.hidden = slot !== target || html === '';
            }
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

        const byEntry = outboxByEntry(items);
        this.recentBlockTarget.hidden = typed;

        // My tables (judge-speed J3): only the empty state looks at them - typing, the unique-table open and scans never
        const mine = typed ? null : this.myTables();
        const ranges = mine !== null && mine.on ? mine.ranges : null;
        this.myTablesRowTarget.hidden = mine === null;

        if (mine !== null) {
            this.setText(this.myTablesChipTarget, this.t('myTablesChip', { '%tables%': mine.text }));
            this.myTablesChipTarget.setAttribute('aria-pressed', String(mine.on));
            this.myTablesChipTarget.classList.toggle('active', mine.on);
        }

        // A pick round: entries with a result but no puzzle chosen (toolkit C10) - a filter in place of the recent ones
        const missing = typed ? [] : missingChoice(this.allEntries(), pickFormat(this.round), byEntry);
        this.missingOnly = this.missingOnly && missing.length > 0;
        this.missingButtonTarget.hidden = missing.length === 0;
        this.setText(this.missingButtonTarget, this.t('puzzleMissing', { '%count%': missing.length }));
        this.missingButtonTarget.setAttribute('aria-pressed', String(this.missingOnly));
        this.missingButtonTarget.classList.toggle('active', this.missingOnly);

        // Time proposals waiting for a judge (toolkit C9): a list whose rows open the entry's card - never a confirm here
        const waiting = typed || !this.proposalsOn ? [] : waitingProposals(this.proposals, (ref) => this.entry(ref), items);
        this.proposalsOnly = this.proposalsOnly && waiting.length > 0;
        this.missingOnly = this.missingOnly && !this.proposalsOnly;
        this.proposalsButtonTarget.hidden = waiting.length === 0;
        this.setText(this.proposalsButtonTarget, this.t('proposalsWaiting', { '%count%': waiting.length }));
        this.proposalsButtonTarget.setAttribute('aria-pressed', String(this.proposalsOnly));
        this.proposalsButtonTarget.classList.toggle('active', this.proposalsOnly);
        this.missingButtonTarget.setAttribute('aria-pressed', String(this.missingOnly));
        this.missingButtonTarget.classList.toggle('active', this.missingOnly);
        this.recentTitleTarget.hidden = this.missingOnly || this.proposalsOnly;
        this.recentMoreTarget.hidden = true;

        // To do at my tables: no result shown yet, table order - the next table on top after every Save
        const todoOn = ranges !== null && !this.missingOnly && !this.proposalsOnly;
        this.todoBlockTarget.hidden = !todoOn;

        if (todoOn) {
            const open = todo(this.allEntries(), ranges, byEntry);
            this.setText(this.todoTitleTarget, this.t('myTablesTodo', { '%count%': open.count }));
            this.todoListTarget.innerHTML = open.count > 0
                ? rowsOf(open.entries.map((entry) => ({ entry })), byEntry).map((row) => this.rowHtml(row)).join('')
                : `<p class="text-muted small mb-0">${escapeHtml(this.t('myTablesTodoEmpty'))}</p>`;
        }

        if (this.proposalsOnly) {
            const now = this.serverNow();
            this.matchesTarget.innerHTML = '';
            // Never hidden by My tables (D-4): mine first, then every other table
            this.recentTarget.innerHTML = this.mineFirstHtml(waiting, ranges, ({ proposal, entry }) => this.rowHtml({
                entry,
                exact: false,
                result: { value: proposalValue(proposal) },
                sync: '',
                note: `${this.age(proposal.receivedAtMs, now)}${waitingNote(proposal) ? ` · ${this.t(waitingNote(proposal))}` : ''}`,
            }));

            return;
        }

        if (this.missingOnly) {
            this.matchesTarget.innerHTML = '';
            this.recentTarget.innerHTML = this.mineFirstHtml(rowsOf(missing.map((entry) => ({ entry })), byEntry), ranges, (row) => this.rowHtml(row));

            return;
        }

        if (!typed && ranges !== null && !this.recentAll) {
            // Recent of my tables, the other tables' ones counted - one tap shows them all
            const { mine: recent, others } = recentMine(this.allEntries(), items.filter((item) => item.roundId === this.roundIdValue), ranges);
            this.matchesTarget.innerHTML = '';
            this.recentTarget.innerHTML = recent.length > 0
                ? rowsOf(recent.map(({ entry }) => ({ entry })), byEntry).map((row) => this.rowHtml(row)).join('')
                : (others === 0 ? `<p class="text-muted small mb-0">${escapeHtml(this.t('recentEmpty'))}</p>` : '');
            this.recentMoreTarget.hidden = others === 0;
            this.setText(this.recentMoreTarget, this.plural('otherTables', others));

            return;
        }

        const { rows } = findRows(index, this.query, items, { tableNumbersOff, entries: typed ? [] : this.allEntries(), roundId: this.roundIdValue });

        if (typed) {
            this.matchesTarget.innerHTML = rows.length > 0
                ? rows.map((row) => this.rowHtml(row)).join('')
                : `<p class="text-muted text-center my-3 mb-0">${escapeHtml(this.t('matchesNone'))}</p>`;
        } else {
            this.matchesTarget.innerHTML = '';
            this.recentTarget.innerHTML = rows.length > 0
                ? rows.map((row) => this.rowHtml(row)).join('')
                : `<p class="text-muted small mb-0">${escapeHtml(this.t('recentEmpty'))}</p>`;
        }
    }

    // Rows of a list My tables never shortens (D-4): mine first, then "Other tables" with the rest; as given without them
    mineFirstHtml(rows, ranges, html) {
        if (ranges === null) {
            return rows.map(html).join('');
        }

        const { mine, others } = splitMine(rows, ranges);

        return mine.map(html).join('')
            + (others.length > 0 ? `<h3 class="lr-recent-title" data-my-tables-divider>${escapeHtml(this.t('myTablesOthers'))}</h3>${others.map(html).join('')}` : '');
    }

    rowHtml({ entry, exact, result, sync: mark, note = null }) {
        const sub = [];

        if (note !== null) {
            sub.push(note);
        }

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

        if (mark === 'problem') {
            sync = `<i class="bi bi-exclamation-triangle-fill text-danger" title="${escapeHtml(this.t('rowProblem'))}"></i><span class="visually-hidden">${escapeHtml(this.t('rowProblem'))}</span>`;
        } else if (mark === 'waiting') {
            sync = `<i class="bi bi-clock-history text-warning" title="${escapeHtml(this.t('rowWaiting'))}"></i><span class="visually-hidden">${escapeHtml(this.t('rowWaiting'))}</span>`;
        } else if (mark === 'saved') {
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
            <span class="lr-row-result">${escapeHtml(result.value === null || result.value === undefined ? '' : this.describe(result.value, this.shownMissing(entry)))}</span>
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
            now = `<i class="bi bi-clock-history text-warning me-1" aria-hidden="true"></i>${escapeHtml(this.t('waiting', { '%result%': this.describe(shown.value, this.shownMissing(entry, items)) }))}`;
        } else if (entry.result !== null && entry.result !== undefined) {
            now = entry.enteredBy
                ? escapeHtml(this.t('savedBy', { '%result%': this.describe(entry.result, this.shownMissing(entry, items)), '%name%': this.whoName(entry.enteredBy), '%time%': this.timeOfDay(entry.enteredAt) }))
                : escapeHtml(this.t('saved', { '%result%': this.describe(entry.result, this.shownMissing(entry, items)) }));
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
        this.renderPuzzles(entry, items);
        this.renderSplits(entry, items);
        this.renderProposal(entry, items);

        this.checkMeanwhile(entry, items);
    }

    /**
     * A live update of the open entry while its confirm step is open (D7): another judge saved meanwhile. The note says
     * so, Save becomes "Replace …" and ignores taps for 350 ms again - the next tap is a deliberate one over the value
     * the card now shows, which is its new `from`. Nothing is overwritten that the judge did not see.
     */
    checkMeanwhile(entry, items) {
        const server = this.serverEntries.get(entry.ref);

        if (!savedMeanwhile(this.shownFrom, server, items)) {
            return;
        }

        if (this.view !== 'confirm') {
            // The entry step: this judge's own earlier Save arrived (its outbox change is gone) - the card showed it as
            // theirs. Another judge's value stays news: a Save from the entry step meets it as a conflict
            if (server.enteredBy && samePlayer(server.enteredBy.playerId, this.userId)) {
                this.shownFrom = shownFromOf(server);
                this.rememberDraft();
            }

            return;
        }

        this.shownFrom = shownFromOf(server);
        const shown = shownValue(entry, 'result', items);
        const same = sameValue(shown.value, this.pendingValue) && this.missingChange(entry) === null;
        this.confirmNoteTarget.textContent = same ? this.t('same') : this.meanwhileNote(entry);
        this.confirmNoteTarget.className = `small mb-3 ${same ? 'text-muted' : 'text-warning-emphasis fw-medium'}`;
        this.setSaveLabel(same ? null : this.t('replaceSaved', { '%result%': this.describe(shown.value, this.shownMissing(entry, items)) }));
        this.renderConfirmResult();
        this.confirmShownAt = performance.now();
        this.savePointerAt = null;
        this.rememberDraft();
    }

    meanwhileNote(entry) {
        const result = this.describe(entry.result ?? null, this.shownMissing(entry));

        return entry.enteredBy
            ? this.t('meanwhileBy', { '%result%': result, '%name%': this.whoName(entry.enteredBy), '%time%': this.timeOfDay(entry.enteredAt) })
            : this.t('meanwhile', { '%result%': result });
    }

    /**
     * Save's text: "Save", or "Replace 1:00:47" after a value saved meanwhile (null = the template's own).
     */
    setSaveLabel(text) {
        const button = this.saveButtonTarget;
        button.dataset.saveHtml ??= button.innerHTML;
        button.innerHTML = text === null ? button.dataset.saveHtml : `<i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>${escapeHtml(text)}`;
        button.classList.toggle('btn-success', text === null);
        button.classList.toggle('btn-warning', text !== null);
    }

    /**
     * The entry's time proposal on its card (toolkit C9, C-judge-page.md §3.1): the proposed time large, from whom and
     * when, late with both times, a recent link; one big Confirm (the card is its confirm view - 350 ms guard), Change,
     * Not right. A proposal decided while the card is open (elsewhere, withdrawn, superseded) changes here in place.
     */
    renderProposal(entry, items) {
        const proposal = this.proposalsOn ? proposalOfEntry(this.proposals, entry.ref) : null;
        const panel = proposalPanel(proposal, entry, items);
        const state = panel?.state;
        let html = '';

        if (panel !== null) {
            const time = formatResultTime(panel.value.seconds);
            const name = proposerName(proposal, entry);
            const lines = [`${this.t('proposalFrom', { '%time%': this.clockTime(proposal.tappedAtMs) })}${name ? ` · ${this.t('proposalBy', { '%name%': name })}` : ''} · ${this.t('proposalTapped', { '%age%': this.age(proposal.tappedAtMs, this.serverNow()) })}`];

            // Timing (T2): check / arrived later / the phone's clock - the module says what, the page formats it
            const notes = proposalNotes(proposal);
            const check = notes.some((note) => note.warning) ? `<div class="small fw-semibold text-warning-emphasis" data-proposal-check>${escapeHtml(this.t('proposalCheck'))}</div>` : '';
            lines.push(...notes.filter((note) => !note.warning).map((note) => this.proposalNote(note)));

            if (proposal.clockChanged) {
                lines.push(this.t('proposalClockChanged'));
            }

            if (proposal.proposedBy?.linkedAt) {
                lines.push(this.t('proposalLinked', { '%time%': this.timeOfDay(proposal.proposedBy.linkedAt) }));
            }

            let status = '';
            let buttons = '';
            const button = (action, text, kind = 'secondary', data = '') => `<button type="button" class="btn btn-outline-${kind} flex-fill" data-action="live-results#${action}"${data}>${escapeHtml(text)}</button>`;

            if (state === 'decided') {
                const seconds = proposal.decided?.seconds;
                status = this.t(proposal.status === 'superseded' && proposal.decided?.reason === 'result_cleared' ? 'proposalStatus_undone' : `proposalStatus_${proposal.status}`, { '%time%': Number.isInteger(seconds) ? formatResultTime(seconds) : time, '%proposed%': formatResultTime(proposal.proposedSeconds) })
                    + (proposal.decided?.byName ? ` · ${proposal.decided.byName}` : '');
            } else if (state === 'sending') {
                status = this.t(panel.rejecting ? 'proposalRejectWaiting' : 'proposalConfirmWaiting');
            } else if (state === 'matches') {
                status = this.t('proposalMatches');
            } else if (this.rejecting) {
                status = this.t('proposalRejectWhy');
                buttons = `<div class="d-flex gap-2">${['not_finished', 'wrong_entry', 'other'].map((reason) => button('rejectWith', this.t(`proposalReason_${reason}`), 'danger', ` data-reason="${reason}"`)).join('')}</div>${button('cancelReject', this.t('proposalRejectCancel'), 'secondary w-100 mt-2')}`;
            } else {
                status = [state === 'differs' ? this.t('proposalDiffers', { '%saved%': this.describe(panel.saved) }) : '', this.proposalNeedsChoice && pickFormat(this.round) ? this.t('proposalPickPuzzle') : ''].filter(Boolean).join(' · ');
                const other = proposalAlternative(proposal);
                buttons = `<button type="button" class="btn btn-success w-100 lr-big-btn" data-proposal-confirm data-id="${escapeHtml(proposal.id)}" data-action="pointerdown->live-results#proposalPointerDown live-results#confirmProposal"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>${escapeHtml(this.t(state === 'differs' ? 'proposalReplace' : 'proposalConfirm', { '%time%': time }))}</button>${other ? `<button type="button" class="btn btn-outline-success w-100 mt-2" data-proposal-confirm data-id="${escapeHtml(proposal.id)}" data-use="${other.use}" data-action="pointerdown->live-results#proposalPointerDown live-results#confirmProposal">${escapeHtml(this.t('proposalUseReceived', { '%time%': formatResultTime(other.seconds) }))}</button>` : ''}<div class="d-flex gap-2 mt-2">${button('changeProposal', this.t('proposalChange'))}${button('rejectProposal', this.t('proposalReject'), 'danger')}</div>`;
            }

            html = `<div class="lr-proposal" data-state="${state}" role="group"><div class="lr-proposal-time">${escapeHtml(this.t('proposalTitle', { '%time%': time }))}</div>${check}<div class="small text-muted">${lines.map(escapeHtml).join('<br>')}</div>${status !== '' ? `<div class="small fw-medium mt-1">${escapeHtml(status)}</div>` : ''}${buttons !== '' ? `<div class="mt-2">${buttons}</div>` : ''}</div>`;
        }

        // A Confirm that shows up (or now confirms another value) ignores taps for 350 ms - the tap that opened the card
        const key = state === 'confirm' || state === 'differs' ? `${proposal.id}|${panel.value.seconds}` : null;

        if (key !== this.proposalKey) {
            this.proposalKey = key;
            this.proposalShownAt = key === null ? null : performance.now();
        }

        if (this.proposalHtml !== html) {
            this.proposalHtml = html;
            this.proposalTarget.innerHTML = html;
            this.proposalTarget.hidden = html === '';
        }
    }

    /**
     * One timing line of a proposal (T2, proposalNotes()): seconds as result times, clocks as times of day.
     */
    proposalNote(note) {
        const values = {};

        for (const [name, seconds] of Object.entries(note.seconds ?? {})) {
            values[`%${name}%`] = formatResultTime(seconds);
        }

        for (const [name, atMs] of Object.entries(note.clocks ?? {})) {
            values[`%${name}%`] = this.clockTime(atMs);
        }

        return this.t(note.key, values);
    }

    /**
     * "2 min" / "40 s" ago, on the server's clock.
     */
    age(atMs, nowMs) {
        const { unit, count } = ageOf(atMs, nowMs);

        return this.t(unit === 'seconds' ? 'proposalAgeSeconds' : 'proposalAgeMinutes', { '%count%': count });
    }

    /**
     * The entry's proposal the card can still decide - pending and not decided on this device.
     */
    openProposal(id = null) {
        const proposal = this.openRef !== null && this.proposalsOn ? proposalOfEntry(this.proposals, this.openRef) : null;

        if (proposal === null || proposal.status !== 'pending' || (id !== null && proposal.id !== String(id).toLowerCase())
            || decisionOnDevice(proposal.id, this.outbox?.all() ?? []) !== null) {
            return null;
        }

        return proposal;
    }

    proposalPointerDown(event) {
        this.proposalPointerAt = typeof event?.timeStamp === 'number' && event.timeStamp > 0 ? event.timeStamp : performance.now();
    }

    /**
     * Confirm: the proposed value (the receipt's when late) saved as the entry's result carrying the proposal - with a
     * pick round's puzzle record in one group, which needs the judge's choice first.
     */
    confirmProposal(event) {
        const tappedAt = event?.detail > 0 && this.proposalPointerAt !== null ? this.proposalPointerAt : performance.now();
        this.proposalPointerAt = null;
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;
        const proposal = this.openProposal(event?.currentTarget?.dataset?.id ?? null);

        if (this.view !== 'entry' || entry === null || proposal === null || !confirmTapAllowed(this.proposalShownAt, tappedAt)) {
            return;
        }

        const format = pickFormat(this.round);
        // The tap's time - or the judge's explicit "Use received" on a time to check (T2)
        const value = proposalValue(proposal, event?.currentTarget?.dataset?.use ?? null);
        const { fields, record, needsChoice } = confirmFields(format, this.choice, value, entry, this.outbox.all(), proposal.id);

        if (needsChoice) {
            this.proposalNeedsChoice = true;
            this.proposalHtml = null;
            this.renderEntryInfo();

            return;
        }

        const saved = this.enqueueGroup(entry, fields);
        const choice = format !== null && Array.isArray(record) && record.length > 0 ? ` · ${describeChoice(format, record)}` : '';

        if (navigator.vibrate) {
            navigator.vibrate(30);
        }

        this.showToast(this.t('toastSaved', { '%name%': entry.displayName ?? entry.name ?? '', '%result%': this.describe(value) + choice }), saved);
        this.backToFind();
    }

    /**
     * Change: the time field with the proposed value - its Save confirms the proposal with the judge's value.
     */
    changeProposal() {
        const proposal = this.openProposal();

        if (proposal === null) {
            return;
        }

        this.proposalEditing = proposal.id;
        this.rejecting = false;
        this.showTimeForm();
        this.timeInputTarget.value = formatResultTime(proposalValue(proposal).seconds);
        this.timeTyped();
        this.timeInputTarget.focus();
        this.timeInputTarget.select();
    }

    rejectProposal() {
        if (this.openProposal() !== null) {
            this.rejecting = true;
            this.renderEntryInfo();
        }
    }

    cancelReject() {
        this.rejecting = false;
        this.renderEntryInfo();
    }

    /**
     * Not right, with the reason: a `proposal` change through the outbox (offline-safe). Nothing to undo - a sent
     * rejection is final.
     */
    rejectWith(event) {
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;
        const proposal = this.openProposal();

        if (entry === null || proposal === null) {
            return;
        }

        const change = rejectionChange(proposal, event.currentTarget.dataset.reason);
        this.enqueueGroup(entry, [change]);
        this.showToast(this.t('proposalRejected', { '%name%': entry.displayName ?? entry.name ?? '' }), null);
        this.backToFind();
    }

    toggleProposals() {
        this.proposalsOnly = !this.proposalsOnly;
        this.missingOnly = false;
        this.renderFind();
    }

    /**
     * A pick round's chips on the entry card (toolkit C10): labels always, the name and picture once the puzzle is out;
     * the solving order on the chosen ones; on the confirm step a warning while the choice is missing; on the entry step
     * "Save puzzle choice" while the choice differs from what is known.
     */
    renderPuzzles(entry, items) {
        const format = pickFormat(this.round);
        let html = '';

        if (format !== null) {
            const confirming = this.view === 'confirm';
            const value = confirming ? this.pendingValue : shownValue(entry, 'result', items).value;
            const { fields, record } = saveFields(format, this.choice, value, entry, items, confirming ? this.notDone(value) : null);
            const missing = confirming && value && (Number.isInteger(value.seconds) || Number.isInteger(value.piecesPlaced)) && record === null;
            const chips = puzzleChips(format, this.choice).map((chip) => `<button type="button" class="lr-chip${chip.order > 0 ? ' lr-chip-on' : ''}" data-action="live-results#pickPuzzle" data-id="${escapeHtml(chip.id)}" aria-pressed="${chip.order > 0}">
                    ${chip.order > 0 ? `<span class="lr-chip-order">${format.toSolve > 1 ? chip.order : '<i class="bi bi-check-lg" aria-hidden="true"></i>'}</span>` : ''}
                    ${chip.imageUrl ? `<img src="${escapeHtml(chip.imageUrl)}" alt="" width="48" height="48" loading="lazy">` : ''}
                    <span class="lr-chip-text"><strong>${escapeHtml(chip.label)}</strong>${chip.name ? `<span class="lr-chip-name">${escapeHtml(chip.name)}</span>` : ''}</span>
                    ${chip.hidden ? `<i class="bi bi-lock" title="${escapeHtml(this.t('puzzleHidden'))}" aria-label="${escapeHtml(this.t('puzzleHidden'))}"></i>` : ''}
                </button>`).join('');
            const head = format.toSolve > 1 ? this.t('puzzlesPickK', { '%count%': format.toSolve }) : this.t('puzzlesPickOne');
            const canSaveChoice = !confirming && fields.some((field) => field.field === 'puzzles') && (record !== null || this.choice.length === 0);

            html = `<div class="small fw-medium mb-1${missing ? ' text-warning-emphasis' : ' text-muted'}">${escapeHtml(missing ? this.t('puzzlesMissingNote') : head)}</div>
                <div class="lr-chips">${chips}</div>
                ${canSaveChoice ? `<button type="button" class="btn btn-outline-primary w-100 mt-2" data-action="live-results#saveChoice">${escapeHtml(this.t('puzzlesSave'))}</button>` : ''}`;
        }

        if (this.puzzlesHtml !== html) {
            this.puzzlesHtml = html;
            this.puzzlesTarget.innerHTML = html;
            this.puzzlesTarget.hidden = html === '';
        }
    }

    describeField(field, value) {
        if (field === 'result') {
            return value === null ? this.t('noResult') : this.describe(value);
        }

        if (field === 'table_number') {
            return value === null ? this.t('noTable') : this.t('table', { '%number%': value });
        }

        if (field === 'puzzles') {
            return Array.isArray(value) && value.length > 0 ? describeChoice(pickFormat(this.round), value) : this.t('puzzlesNone');
        }

        if (field === 'proposal') {
            return this.t('proposalReject');
        }

        if (field === MISSING_PIECES) {
            return missingOf(value) === null ? this.t('missingNone') : this.plural('missingPieces', value).split('%count%').join(String(value));
        }

        return value === true ? this.t('qualified') : this.t('notQualified');
    }

    problemHtml(item, entry, withName) {
        const fieldLabel = { result: this.t('fieldResult'), table_number: this.t('fieldTable'), qualified: this.t('fieldQualified'), puzzles: this.t('fieldPuzzles'), proposal: this.t('fieldProposal'), [MISSING_PIECES]: this.t('fieldMissing') }[item.field] ?? '';
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
            const fixable = (item.field === 'result' || item.field === 'puzzles') && item.reason !== 'round_not_found' && item.roundId === this.roundIdValue;

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
            this.sheetBodyTarget.innerHTML = `<p class="text-success fs-5 my-4 text-center"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>${escapeHtml(this.t('sheetEmpty'))}</p>${this.offlineReadyHtml()}`;

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

        this.sheetBodyTarget.insertAdjacentHTML('beforeend', this.offlineReadyHtml());
    }

    /**
     * Whether this page opens on this device without a connection (toolkit C6) - the native apps cannot.
     */
    offlineReadyHtml() {
        if (isNativeApp()) {
            return `<p class="small text-muted mt-3 mb-0" data-live-offline-ready="none">${escapeHtml(this.t('offlineNoWorker'))}</p>`;
        }

        if (this.offlineReadyAt === null) {
            return '';
        }

        return `<p class="small text-muted mt-3 mb-0" data-live-offline-ready><i class="bi bi-cloud-check me-1" aria-hidden="true"></i>${escapeHtml(this.t('offlineReady', { '%time%': this.timeOfDay(new Date(this.offlineReadyAt).toISOString()) }))}</p>`;
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

    /**
     * The server's time minus this phone's wall clock now - what a snapshot keeps for the next page load's first guess.
     */
    wallClockOffsetMs() {
        return Math.round(this.serverClock.serverNow() - Date.now());
    }

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

        // Proposals' ages ("tapped 2 min ago") move on every 15 s
        const ageTick = this.proposals.size > 0 ? Math.floor(now / 15000) : null;

        if (ageTick !== this.proposalAgeTick) {
            this.proposalAgeTick = ageTick;
            this.scheduleRender();
        }

        if (!force && text === this.lastClockText) {
            return;
        }

        this.lastClockText = text;
        this.stopwatchTarget.textContent = text;
        this.stopwatchTarget.dataset.state = state;

        const running = status === 'running' && elapsed !== null;
        this.finishedNowTarget.hidden = !running;

        if (this.splitsRunning !== running && this.openRef !== null) {
            this.renderSplits();
        }

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

        // A table number nothing else starts with opens at once - no Enter (iOS's number pad has none), no row tap
        // (competition toolkit C7; a per-phone preference, on by default)
        if (this.prefs().autoOpenTable !== false) {
            const unique = uniqueTable(this.searchIndex(), this.query, { tableNumbersOff: !this.usesTables() });

            if (unique !== null) {
                this.openEntry(unique.ref);

                return;
            }
        }

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

    // ---- My tables (judge-speed J3) ----

    // This event's tables on this phone, null on a round without table numbers (or none set)
    myTables() {
        const competitionId = this.competition?.id ?? null;

        if (this.myTablesFor !== competitionId) {
            this.myTablesFor = competitionId;
            this.myTablesPref = myTablesOf(this.prefs(), competitionId);
        }

        return this.usesTables() ? this.myTablesPref : null;
    }

    saveMyTables(change) {
        const competitionId = this.competition?.id;

        if (typeof competitionId !== 'string') {
            return;
        }

        const storage = webStorage('localStorage') ?? { getItem: () => null, setItem: () => {}, removeItem: () => {} };
        const prefs = writePrefs(storage, { myTables: withMyTables(this.prefs().myTables, competitionId, change, Date.now()) });
        // Kept for this page even where the browser stores nothing (a private window)
        this.myTablesFor = competitionId;
        this.myTablesPref = myTablesOf(prefs, competitionId);
        this.recentAll = false;
        this.renderFind();
    }

    toggleMyTables() {
        const mine = this.myTables();

        if (mine !== null) {
            this.saveMyTables({ text: mine.text, on: !mine.on });
        }
    }

    editMyTables() {
        this.openSheet();

        if (this.hasMyTablesInputTarget && !this.sheetTarget.hidden) {
            this.myTablesInputTarget.scrollIntoView({ block: 'center' });
            this.myTablesInputTarget.focus();
        }
    }

    setMyTables(event) {
        event.preventDefault();
        const input = this.myTablesInputTarget;
        const parsed = parseTables(input.value);
        const invalid = parsed.error !== undefined;

        input.classList.toggle('is-invalid', invalid);
        input.setAttribute('aria-invalid', String(invalid));
        this.myTablesErrorTarget.hidden = !invalid;

        if (invalid) {
            this.setText(this.myTablesErrorTarget, this.t({ syntax: 'myTablesErrorSyntax', range: 'myTablesErrorRange', too_many: 'myTablesErrorTooMany' }[parsed.error]));

            return;
        }

        input.value = parsed.text;
        this.saveMyTables({ text: parsed.text, on: true });
    }

    showOtherTables() {
        this.recentAll = true;
        this.renderFind();
    }

    toggleAutoOpen(event) {
        writePrefs(webStorage('localStorage') ?? { getItem: () => null, setItem: () => {}, removeItem: () => {} }, { autoOpenTable: event.currentTarget.checked });
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
        const inPlace = (this.view === 'entry' && view === 'confirm') || (this.view === 'confirm' && view === 'entry');
        this.view = view;
        this.findViewTarget.hidden = view !== 'find';
        // The confirm step is on the entry's card (competition toolkit C7): Save shows up where "Finished now" was
        this.entryViewTarget.hidden = view !== 'entry' && view !== 'confirm';
        this.entryViewTarget.classList.toggle('lr-confirming', view === 'confirm');
        this.confirmViewTarget.hidden = view !== 'confirm';
        this.addViewTarget.hidden = view !== 'add';

        if (view !== 'find') {
            this.claimHistory();
        }

        // The banner follows the view: on top of the find view, below a card (D6)
        this.renderBanner();

        if (!inPlace) {
            window.scrollTo(0, 0);
        }
    }

    openEntry(ref) {
        const entry = this.entry(ref);

        if (entry === null) {
            return;
        }

        this.closeOverlays({ keepHistory: true });
        this.openRef = ref;
        this.recentAll = false;
        this.pendingValue = null;
        this.shownFrom = shownFromOf(this.serverEntries.get(ref));
        this.entryNowHtml = null;
        this.entryProblemsHtml = null;
        this.puzzlesHtml = null;
        this.splitEditing = null;
        this.splitNote = null;
        this.splitsTarget.dataset.shape = '';
        this.proposalHtml = null;
        this.proposalKey = null;
        this.proposalEditing = null;
        this.proposalNeedsChoice = false;
        this.rejecting = false;
        // A pick round: the choice known (the server's, this device's waiting or refused one) is preselected
        const format = pickFormat(this.round);
        const record = format === null ? null : shownValue(entry, 'puzzles', this.outbox?.all() ?? []).value;
        this.choice = choiceOf(format, record);
        this.showTimeForm();

        // A refused result opens with the refused value, ready to fix
        const rejected = (this.outbox?.all() ?? []).find((item) => item.entryRef === ref && item.field === 'result' && item.state === 'rejected');
        this.timeInputTarget.value = rejected && rejected.to && typeof rejected.to.seconds === 'number' ? formatResultTime(rejected.to.seconds) : '';
        this.timeTyped();
        this.showView('entry');

        if (rejected && rejected.to && typeof rejected.to.piecesPlaced === 'number') {
            // Back as the judge typed it: placed (a pick round: per puzzle not finished, from the refused record)
            this.didNotFinish();
            this.piecesPref = 'placed';
            const refused = (this.outbox?.all() ?? []).find((item) => item.entryRef === ref && item.field === 'puzzles' && item.state === 'rejected');
            const items = Array.isArray(refused?.to) ? refused.to : (Array.isArray(record) ? record : []);
            this.openPuzzles = openOfRecord(this.choice, items);
            this.pieceTexts = Object.fromEntries(items.filter((item) => item.done !== true).map((item) => [String(item.roundPuzzleId).toLowerCase(), String(item.piecesPlaced ?? 0)]));
            this.piecesRowsTarget.dataset.shape = '';
            this.piecesInputTarget.value = String(rejected.to.piecesPlaced);
            this.piecesTyped();
        }
        this.renderEntryInfo();
        this.tickClock(true);

        const proposalConfirm = this.proposalTarget.querySelector('[data-proposal-confirm]');
        const shownResult = shownValue(entry, 'result', this.outbox?.all() ?? []).value;

        // The round's time is up (reliability hardening T1): an entry without a result opens in "Didn't finish" - the
        // judge sweeps the tables counting missing pieces. A default, never a restriction: "Back to the time" is one tap
        // (a time written down during the round). Not with a time proposal waiting, never before the limit
        if (opensUnfinished({ timeIsUp: this.roundTimesUp(), hasResult: shownResult !== null && shownResult !== undefined, proposalWaiting: proposalConfirm !== null, refused: Boolean(rejected) })) {
            this.didNotFinish();
        }

        if (!this.piecesFormTarget.classList.contains('lr-hidden')) {
            this.focusPieces();
        } else if (proposalConfirm !== null && this.timeInputTarget.value === '') {
            // A pending proposal: its Confirm is the likely next tap
            proposalConfirm.focus();
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
        this.finishedNowTarget.classList.remove('lr-hidden');
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
        this.shownFrom = null;
        this.proposalEditing = null;
        this.rejecting = false;
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

        if (this.splitGateRefuses({ seconds: parsed.seconds })) {
            this.timeInputTarget.focus();

            return;
        }

        this.review({ seconds: parsed.seconds });
    }

    /**
     * Puzzles finished one by one (real events W5): a time for the whole round completes the solving order with the one
     * left, or says why it cannot be saved yet (which puzzle was last; a time before an earlier split) - true = refused.
     */
    splitGateRefuses(value) {
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;
        const gate = entry === null ? { choice: null, refusal: null } : finishGate(splitFormat(this.round), shownValue(entry, 'puzzles', this.outbox?.all() ?? []).value, this.choice, value);

        if (gate.refusal !== null) {
            this.setText(this.timeParsedTarget, this.t(gate.refusal === 'pick_remaining' ? 'splitPickRemaining' : 'splitOutOfOrder'));

            return true;
        }

        if (gate.choice !== null) {
            this.choice = gate.choice;
        }

        return false;
    }

    async finishedNow(event) {
        // Frozen the moment it is tapped - on the server's clock
        const tappedAt = this.serverNow();
        const ref = this.openRef;

        // The second tap of a double tap on a puzzle's own Finished now (real events W5) is not the round's finish
        if (!this.splitTapAllowed(event)) {
            return;
        }

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

        const value = { seconds: Math.min(elapsed, 86399) };

        // Puzzles finished one by one (real events W5): the one left finishes with the round's time as its split
        if (this.splitGateRefuses(value)) {
            return;
        }

        this.review(value, { finishedNow: true, tappedAt });
    }

    didNotFinish() {
        this.timeFormTarget.classList.add('lr-hidden');
        // "Finished now" goes with the time form - one tap ("Back to time") brings both back
        this.finishedNowTarget.classList.add('lr-hidden');
        this.piecesFormTarget.classList.remove('lr-hidden');
        this.piecesInputTarget.value = '';
        this.piecesPref = this.prefs().piecesMode ?? MISSING;
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;
        this.openPuzzles = openOfRecord(this.choice, entry === null ? null : shownValue(entry, 'puzzles', this.outbox?.all() ?? []).value);
        this.pieceTexts = {};
        this.piecesTyped();
        this.focusPieces();
    }

    /** Time's up: the round has a limit and the stopwatch ran past it (running or stopped after it). */
    roundTimesUp(now = this.serverNow()) {
        const status = stopwatchStatusAt(this.stopwatch, now);
        const stopped = status === 'stopped' && this.stopwatch?.startedAt && this.stopwatch?.stoppedAt
            ? Math.floor((Date.parse(this.stopwatch.stoppedAt) - Date.parse(this.stopwatch.startedAt)) / 1000)
            : null;

        return timesUp(status, status === 'running' ? this.elapsedSeconds(now) : stopped, this.round?.minutesLimit);
    }

    /** The field to type in: a pick round without a choice → its chips, else the first open puzzle still empty. */
    focusPieces() {
        const format = pickFormat(this.round);

        if (format !== null && this.choice.length === 0) {
            const chip = this.puzzlesTarget.querySelector('.lr-chip');

            if (chip !== null) {
                chip.focus();

                return;
            }
        }

        const inputs = [...this.piecesRowsTarget.querySelectorAll('input')];
        (inputs.find((input) => input.value.trim() === '') ?? inputs[0] ?? this.piecesInputTarget).focus();
    }

    setPiecesMode(event) {
        const mode = event.currentTarget.dataset.mode;
        const was = this.piecesPref;

        if (mode === was) {
            return;
        }

        // The count the judge meant stays: 37 missing of 1000 → 963 placed
        const format = pickFormat(this.round);
        this.piecesInputTarget.value = convertTyped(this.piecesInputTarget.value, piecesMode(was, this.round?.piecesCount), piecesMode(mode, this.round?.piecesCount), this.round?.piecesCount);

        if (format !== null) {
            for (const row of pickUnfinished(format, this.choice, this.openPuzzles, this.pieceTexts, was).rows.filter((one) => one.open)) {
                this.pieceTexts[row.id] = convertTyped(row.text, row.mode, piecesMode(mode, row.pieces), row.pieces);
            }
        }

        this.piecesPref = mode;
        writePrefs(webStorage('localStorage') ?? { getItem: () => null, setItem: () => {}, removeItem: () => {} }, { piecesMode: mode });
        this.piecesTyped();
        this.focusPieces();
    }

    toggleOpenPuzzle(event) {
        this.openPuzzles = toggleOpen(this.choice, this.openPuzzles, event.currentTarget.dataset.id);
        this.piecesTyped();
        this.focusPieces();
    }

    pieceRowTyped(event) {
        this.pieceTexts[event.currentTarget.dataset.id] = event.currentTarget.value;
        this.piecesTyped();
    }

    /** A pick round with a choice: the chosen puzzles' rows instead of the one field. */
    pickRows() {
        const format = pickFormat(this.round);

        return format !== null && this.choice.length > 0 ? pickUnfinished(format, this.choice, this.openPuzzles, this.pieceTexts, this.piecesPref) : null;
    }

    piecesLimit() {
        const format = pickFormat(this.round);

        if (format !== null) {
            return piecesLimit(format, this.choice);
        }

        const pieces = this.round?.piecesCount ?? null;

        return typeof pieces === 'number' && pieces > 1 ? pieces - 1 : null;
    }

    /** One count's line: "missing 37 → placed 963 of 1000" (or the pieces placed when the count is unknown). */
    countText(read, pieces, invalidKey, max) {
        if (read.error) {
            return `<span class="text-danger">${escapeHtml(this.t(invalidKey, { '%max%': max ?? '' }))}</span>`;
        }

        return read.missing !== null
            ? `<strong>${escapeHtml(this.t('missingPreview', { '%missing%': read.missing, '%placed%': read.placed, '%pieces%': pieces }))}</strong>`
            : `<strong>${escapeHtml(this.describe({ piecesPlaced: read.placed }))}</strong>`;
    }

    piecesTyped() {
        const format = pickFormat(this.round);
        const pick = this.pickRows();
        const pieces = format === null ? (this.round?.piecesCount ?? null) : null;
        const mode = pick !== null ? this.piecesPref : piecesMode(this.piecesPref, pieces);
        const switchable = pick !== null ? pick.rows.some((row) => row.open && row.pieces > 1) : pieces > 1;
        let html;

        this.piecesSwitchTarget.hidden = !switchable;
        this.piecesSwitchTarget.querySelectorAll('button').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.mode === mode)));
        this.piecesRowsTarget.hidden = pick === null;
        this.piecesLabelTarget.hidden = pick !== null;
        this.piecesInputTarget.hidden = pick !== null;

        if (pick !== null) {
            this.renderPickRows(format, pick);
            html = pick.result !== null
                ? `<strong>${escapeHtml(this.t('missingTotal', { '%placed%': pick.result.piecesPlaced }))}</strong>`
                : (pick.firstEmpty !== null ? `<span class="text-muted small">${escapeHtml(this.t('missingTypeAll'))}</span>` : `<span class="text-danger">${escapeHtml(this.t('piecesInvalid'))}</span>`);
        } else {
            const text = this.piecesInputTarget.value.trim();
            const max = this.piecesLimit();
            const read = typedPieces(text, mode, pieces, { max });
            this.setText(this.piecesLabelTarget, this.t(mode === MISSING ? 'piecesLabelMissing' : 'piecesLabelPlaced'));

            if (read.empty) {
                html = `<span class="text-muted small">${escapeHtml(this.t(mode === MISSING ? 'missingHint' : 'piecesHint'))}${pieces ? ` · ${escapeHtml(this.t('piecesOf', { '%pieces%': pieces }))}` : ''}</span>`;
            } else {
                html = read.error && mode !== MISSING
                    ? `<span class="text-danger">${escapeHtml(this.t('piecesInvalid'))}</span>`
                    : this.countText(read, pieces, 'missingInvalid', pieces - 1);
            }

            this.piecesInputTarget.classList.toggle('is-invalid', read.error);
        }

        this.piecesParsedTarget.innerHTML = html;
        this.rememberDraft();
    }

    /**
     * The chosen puzzles in solving order: a switch finished / not finished, a count per one not finished with its
     * "missing → placed" line. The rows are built again only when their shape changes - never under the judge's thumb.
     */
    renderPickRows(format, pick) {
        const labels = new Map(puzzleChips(format, this.choice).map((chip) => [chip.id, chip.label]));
        const shape = JSON.stringify(pick.rows.map((row) => [row.id, row.open, row.mode]));

        if (this.piecesRowsTarget.dataset.shape !== shape) {
            this.piecesRowsTarget.dataset.shape = shape;
            this.piecesRowsTarget.innerHTML = pick.rows.map((row, index) => {
                const label = escapeHtml(labels.get(row.id) ?? '?');
                const what = escapeHtml(this.t(row.mode === MISSING ? 'piecesLabelMissing' : 'piecesLabelPlaced'));
                const field = row.open
                    ? `<input class="form-control lr-time-input" data-id="${escapeHtml(row.id)}" value="${escapeHtml(row.text)}" inputmode="numeric" pattern="[0-9]*" enterkeyhint="done" autocomplete="off" placeholder="${what}" aria-label="${label} - ${what}" data-action="input->live-results#pieceRowTyped">`
                    : '<span></span>';

                return `<div class="lr-prow"><button type="button" class="lr-chip${row.open ? '' : ' lr-chip-on'}" data-id="${escapeHtml(row.id)}" data-action="live-results#toggleOpenPuzzle" aria-pressed="${!row.open}"><span class="lr-chip-order">${index + 1}</span><span class="lr-chip-text"><strong>${label}</strong><span class="lr-chip-name">${escapeHtml(this.t(row.open ? 'missingNotFinished' : 'missingFinished'))}</span></span></button>${field}<div class="lr-pparsed" data-parsed="${escapeHtml(row.id)}"></div></div>`;
            }).join('');
        }

        for (const row of pick.rows) {
            const parsed = this.piecesRowsTarget.querySelector(`[data-parsed="${CSS.escape(row.id)}"]`);
            const input = this.piecesRowsTarget.querySelector(`input[data-id="${CSS.escape(row.id)}"]`);
            let html = '';

            if (row.open && row.empty) {
                html = `<span class="text-muted">${escapeHtml(this.t(row.mode === MISSING ? 'missingRowHint' : 'missingRowHintPlaced', { '%pieces%': row.pieces ?? '?' }))}</span>`;
            } else if (row.open && row.placed === 0 && !row.error) {
                html = `<strong>${escapeHtml(this.t('missingRowPlaced', { '%placed%': 0, '%pieces%': row.pieces ?? '?' }))}</strong>`;
            } else if (row.open) {
                html = this.countText(row, row.pieces, 'missingRowInvalid', row.pieces ?? '');
            }

            parsed.innerHTML = html;
            input?.classList.toggle('is-invalid', row.error);

            // Missing ↔ Placed converted the count - never under the judge's thumb
            if (input && input !== document.activeElement && input.value !== row.text) {
                input.value = row.text;
            }
        }
    }

    reviewPieces(event) {
        event.preventDefault();
        const value = this.piecesValue();

        if (value === null) {
            this.piecesTyped();
            this.focusPieces();

            return;
        }

        this.review(value);
    }

    /**
     * The unfinished result typed - null while it cannot be read. A pick round with a choice: Σ the finished puzzles'
     * pieces + the open ones' placed (official_results_puzzles.js total()); without a choice the pieces typed.
     */
    piecesValue() {
        const pick = this.pickRows();

        if (pick !== null) {
            return pick.result;
        }

        const pieces = pickFormat(this.round) === null ? (this.round?.piecesCount ?? null) : null;
        const read = typedPieces(this.piecesInputTarget.value, piecesMode(this.piecesPref, pieces), pieces, { max: this.piecesLimit() });

        return read.placed !== null ? { piecesPlaced: read.placed } : null;
    }

    /** The puzzles the judge marked not finished, with their pieces - for the record that goes with an unfinished result. */
    notDone(value) {
        const pick = Number.isInteger(value?.piecesPlaced) && !this.piecesFormTarget.classList.contains('lr-hidden') ? this.pickRows() : null;

        return pick !== null && pick.result !== null ? pick.notDone : null;
    }

    pickPuzzle(event) {
        const format = pickFormat(this.round);
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;

        if (format === null || entry === null) {
            return;
        }

        const before = this.choice;
        this.choice = togglePick(this.choice, event.currentTarget.dataset.id, format.toSolve);
        this.openPuzzles = openAfterPick(before, this.choice, this.openPuzzles);
        this.proposalNeedsChoice = false;

        // Unfinished: the total depends on the puzzles chosen
        if (!this.piecesFormTarget.classList.contains('lr-hidden')) {
            this.piecesTyped();

            if (this.view === 'confirm' && this.pendingValue?.piecesPlaced !== undefined) {
                const value = this.piecesValue();

                if (value === null) {
                    this.backToEntry();
                } else {
                    this.review(value, { finishedNow: this.pendingFinishedNow, tappedAt: this.pendingTappedAt });
                }
            } else if (this.choice.length === format.toSolve) {
                // All chosen: on to the first puzzle not finished
                this.focusPieces();
            }
        }

        this.renderEntryInfo();
        this.rememberDraft();
    }

    /**
     * The choice alone (recorded at the table, or added later from "Puzzle missing"), fitted to the result shown.
     */
    saveChoice() {
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;
        const format = pickFormat(this.round);

        if (entry === null || format === null) {
            return;
        }

        const items = this.outbox.all();
        const { fields, record } = saveFields(format, this.choice, shownValue(entry, 'result', items).value, entry, items);

        if (!fields.some((field) => field.field === 'puzzles')) {
            return;
        }

        const saved = this.enqueueGroup(entry, withShownFrom(fields, this.shownFrom));
        this.showToast(this.t('toastSaved', { '%name%': entry.displayName ?? entry.name ?? '', '%result%': this.describeField('puzzles', record) }), saved);
        this.backToFind();
    }

    // ---- Finished now per puzzle (real events W5, live_entry_splits.js) ----

    /**
     * A pick round where an entry solves 2+ puzzles: the puzzles finished so far with their splits, and - while the
     * entry has no result - "Finished now" per puzzle (stopwatch running) and a typed time. Built again only when its
     * shape changes - never under the judge's thumb (the typed field stays).
     */
    renderSplits(entry = null, items = null) {
        const open = entry ?? (this.openRef !== null ? this.entry(this.openRef) : null);
        const format = splitFormat(this.round);
        const all = items ?? (this.outbox ? this.outbox.all() : []);
        const running = this.elapsedSeconds() !== null;
        this.splitsRunning = running;
        const view = open === null || format === null
            ? { show: false, rows: [] }
            : splitRows(format, shownValue(open, 'puzzles', all).value, this.choice, shownValue(open, 'result', all).value, running);
        const shape = JSON.stringify([view.rows, this.splitEditing, this.splitNote ?? null]);

        if (this.splitsTarget.dataset.shape === shape) {
            return;
        }

        this.splitsTarget.dataset.shape = shape;
        this.splitsTarget.hidden = !view.show;

        if (!view.show) {
            this.splitsTarget.innerHTML = '';

            return;
        }

        const rows = view.rows.map((row) => {
            const id = escapeHtml(row.id);
            const at = row.at !== null ? `✓ ${formatResultTime(row.at)}${row.own !== null && row.order > 1 ? ` ${this.t('splitOwn', { '%time%': formatResultTime(row.own) })}` : ''}` : (row.done ? '✓' : '');
            const form = this.splitEditing === row.id
                ? `<form class="lr-split-form" data-id="${id}" data-action="submit->live-results#typedSplit" novalidate><input class="form-control" value="${row.at !== null ? escapeHtml(formatResultTime(row.at)) : ''}" placeholder="${escapeHtml(this.t('splitPlaceholder'))}" aria-label="${escapeHtml(row.label)}" inputmode="numeric" enterkeyhint="done" autocomplete="off"><button type="submit" class="btn btn-primary">${escapeHtml(this.t('splitSave'))}</button></form>`
                : '';

            return `<div class="lr-srow"><span class="lr-chip-order${row.done ? '' : ' lr-srow-open'}">${row.done ? row.order : ''}</span><strong class="lr-srow-label">${escapeHtml(row.label)}</strong><span class="lr-srow-at">${escapeHtml(at)}</span>${row.tap ? `<button type="button" class="btn btn-success lr-split-now" data-id="${id}" data-action="live-results#finishPuzzle"><i class="bi bi-stopwatch me-1" aria-hidden="true"></i>${escapeHtml(this.t('splitFinishedNow'))}</button>` : ''}${row.edit ? `<button type="button" class="btn btn-link px-1 lr-split-edit" data-id="${id}" data-action="live-results#editSplit" title="${escapeHtml(this.t('splitType'))}" aria-label="${escapeHtml(this.t('splitType'))} - ${escapeHtml(row.label)}"><i class="bi bi-pencil" aria-hidden="true"></i></button>` : ''}${form}</div>`;
        }).join('');

        this.splitsTarget.innerHTML = `<div class="small fw-medium text-muted mb-1">${escapeHtml(this.t('splitTitle'))}</div>${rows}${this.splitNote ? `<div class="small text-danger mt-1" role="alert">${escapeHtml(this.splitNote)}</div>` : ''}`;
    }

    /** A second tap of a double tap right after a split was saved hits the row below - ignored for 350 ms. */
    splitTapAllowed(event) {
        return confirmTapAllowed(this.splitSavedAt ?? null, typeof event?.timeStamp === 'number' && event.timeStamp > 0 ? event.timeStamp : performance.now());
    }

    async finishPuzzle(event) {
        // Frozen the moment it is tapped - on the server's clock, exactly like the round's Finished now
        const tappedAt = this.serverNow();
        const id = event.currentTarget.dataset.id;
        const ref = this.openRef;

        if (!this.splitTapAllowed(event) || this.view !== 'entry') {
            return;
        }

        if (!this.stopwatchTrusted()) {
            await this.refreshState();

            if (this.openRef !== ref || this.view !== 'entry') {
                return;
            }
        }

        const elapsed = this.elapsedSeconds(tappedAt);

        if (elapsed === null || elapsed < 1) {
            this.splitNote = this.t('finishedNowStopped');
            this.renderSplits();

            return;
        }

        this.recordSplit(id, Math.min(elapsed, 86399), tappedAt);
    }

    editSplit(event) {
        if (!this.splitTapAllowed(event)) {
            return;
        }

        const id = event.currentTarget.dataset.id;
        this.splitEditing = this.splitEditing === id ? null : id;
        this.splitNote = null;
        this.renderSplits();
        this.splitsTarget.querySelector('.lr-split-form input')?.focus();
    }

    typedSplit(event) {
        event.preventDefault();
        const parsed = parseResultTime(event.currentTarget.querySelector('input')?.value ?? '');

        if (parsed.empty || parsed.error) {
            this.splitNote = this.t('timeInvalid');
            this.renderSplits();
            this.splitsTarget.querySelector('.lr-split-form input')?.focus();

            return;
        }

        this.recordSplit(event.currentTarget.dataset.id, parsed.seconds, null);
    }

    /**
     * Puzzle `id` finished at `seconds`: the K-th goes to the confirm step with the round's result (Save = `puzzles` +
     * `result`, one group); any other is saved at once - the record alone, Undo in the toast, the card stays open.
     */
    recordSplit(id, seconds, tappedAt) {
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;
        const format = splitFormat(this.round);

        if (entry === null || format === null) {
            return;
        }

        const items = this.outbox.all();
        const known = shownValue(entry, 'puzzles', items).value;
        const step = splitStep(format, known, this.choice, id, seconds, shownValue(entry, 'result', items).value);

        if (step.refusal !== null) {
            this.splitNote = step.refusal === 'splits_out_of_order' ? this.t('splitOutOfOrder') : this.t('invalidChange');
            this.renderSplits();

            return;
        }

        this.splitNote = null;
        this.splitEditing = null;
        this.choice = step.choice;

        if (step.finishes) {
            // The card's `from` fresh from the server now (the snapshot of the entry's opening would read as "Saved meanwhile")
            this.review({ seconds }, { finishedNow: tappedAt !== null, tappedAt });

            return;
        }

        const saved = this.enqueueGroup(entry, withShownFrom([{ field: 'puzzles', to: step.record }], this.shownFrom));

        // The card shows this record now - it is the next change's `from`
        if (this.shownFrom) {
            this.shownFrom = { ...this.shownFrom, puzzles: step.record };
        }

        this.splitSavedAt = performance.now();

        if (navigator.vibrate) {
            navigator.vibrate(30);
        }

        const label = puzzleChips(format, []).find((chip) => chip.id === String(id).toLowerCase())?.label ?? '';
        this.showToast(this.t('toastSaved', { '%name%': entry.displayName ?? entry.name ?? '', '%result%': this.t('splitToast', { '%label%': label, '%time%': formatResultTime(seconds) }) }), saved);
        this.renderEntryInfo();
        this.rememberDraft();
    }

    toggleMissing() {
        this.missingOnly = !this.missingOnly;
        this.proposalsOnly = false;
        this.renderFind();
    }

    didNotStart() {
        this.review({ didNotStart: true });
    }

    clearResult() {
        this.review(null);
    }

    review(value, { finishedNow = false, tappedAt = null, restored = false, shownFrom = null } = {}) {
        const entry = this.entry(this.openRef);

        if (entry === null) {
            return;
        }

        // The confirm step's note names what Save replaces - that is its `from`; a restored step keeps the one it was
        // opened with, so a value saved meanwhile elsewhere comes back as "Saved meanwhile" or a conflict
        this.shownFrom = shownFrom ?? shownFromOf(this.serverEntries.get(entry.ref));
        this.setSaveLabel(null);
        this.pendingValue = value;
        this.prepareMissing(entry, value);
        this.pendingFinishedNow = finishedNow;
        this.pendingTappedAt = finishedNow ? tappedAt : null;
        const shown = shownValue(entry, 'result', this.outbox.all());

        this.renderConfirmResult();

        let note = finishedNow ? this.t('finishedNowNote') : '';

        // A split round with puzzles still to finish (real events W5): this is the whole round, not one puzzle
        if (finishedNow && !restored && finishesWholeRound(splitFormat(this.round), shownValue(entry, 'puzzles', this.outbox.all()).value)) {
            note = `${note} · ${this.t('splitWholeRound')}`;
        }

        // Back after a reload: when it was tapped tells the referee the value is the frozen one
        if (finishedNow && restored && tappedAt !== null) {
            note = `${note} · ${this.t('finishedNowTappedAt', { '%time%': this.clockTime(tappedAt) })}`;
        }

        if (sameValue(shown.value, value) && this.missingChange(entry) === null) {
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
        // A pick round's chips say on the confirm step whether the choice goes along (toolkit C10)
        this.renderEntryInfo();
        // A tap that started before this moment + 350 ms is not a Save (confirmTapAllowed())
        this.confirmShownAt = performance.now();
        this.savePointerAt = null;
        this.saveButtonTarget.focus({ preventScroll: true });
        this.rememberDraft();
    }

    // ---- Finished with pieces missing (competition toolkit T6) ----

    /**
     * The confirm step's "+ pieces missing": offered only with a finished time; open (prefilled) when the entry has a
     * count already, else closed - `pendingMissing` undefined = untouched (the count stays), null = complete, a number,
     * or false while the typed text does not read.
     */
    prepareMissing(entry, value) {
        const finished = value !== null && value !== undefined && Number.isInteger(value.seconds);
        const shown = this.shownMissing(entry);
        this.pendingMissing = undefined;
        this.missingInputTarget.value = shown !== null ? String(shown) : '';
        this.missingInputTarget.classList.remove('is-invalid');
        this.setText(this.missingHintTarget, this.t('missingHint'));
        this.missingFieldTarget.hidden = !finished || shown === null;
        this.missingToggleTarget.hidden = !finished || shown !== null;
    }

    openMissing() {
        this.missingToggleTarget.hidden = true;
        this.missingFieldTarget.hidden = false;
        this.missingInputTarget.focus();
    }

    missingTyped() {
        const parsed = parseMissingPieces(this.missingInputTarget.value, this.round?.piecesCount ?? null);
        this.pendingMissing = parsed.error ? false : (parsed.empty ? null : parsed.value);
        this.missingInputTarget.classList.toggle('is-invalid', parsed.error === true);
        this.setText(this.missingHintTarget, parsed.error ? this.t('missingInvalid') : this.t('missingHint'));
        this.renderConfirmResult();
    }

    missingEnter(event) {
        event.preventDefault();
        this.save(event);
    }

    missingChange(entry) {
        return this.pendingMissing === false ? null : missingPiecesChange(this.pendingValue, this.pendingMissing, this.shownMissing(entry));
    }

    renderConfirmResult() {
        const value = this.pendingValue;
        const entry = this.openRef !== null ? this.entry(this.openRef) : null;
        const missing = this.pendingMissing === undefined || this.pendingMissing === false ? (entry !== null ? this.shownMissing(entry) : null) : this.pendingMissing;

        this.confirmResultTarget.textContent = value === null ? this.t('clearResult') : this.describe(value, missing);
        this.confirmResultTarget.classList.toggle('text-danger', value === null);
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
        this.renderEntryInfo();
        this.rememberDraft();

        if (!this.piecesFormTarget.classList.contains('lr-hidden')) {
            this.focusPieces();
        } else {
            this.timeInputTarget.focus();
        }
    }

    savePointerDown(event) {
        this.savePointerAt = typeof event?.timeStamp === 'number' && event.timeStamp > 0 ? event.timeStamp : performance.now();
    }

    save(event) {
        // The second tap of a double tap on "Finished now" lands here - it saves nothing (the value was not seen yet).
        // A tap counts from its pointerdown; Enter or a click without one from now
        const tappedAt = event?.detail > 0 && this.savePointerAt !== null ? this.savePointerAt : performance.now();
        this.savePointerAt = null;

        if (this.view !== 'confirm' || !confirmTapAllowed(this.confirmShownAt, tappedAt)) {
            return;
        }

        const entry = this.entry(this.openRef);

        if (entry === null) {
            this.backToFind();

            return;
        }

        const value = this.pendingValue;
        // The result, and on a pick round the puzzle record that fits it - one group (toolkit C10, A rule 7)
        const format = pickFormat(this.round);
        let { fields, record } = saveFields(format, this.choice, value, entry, this.outbox.all(), this.notDone(value));
        // Puzzles finished one by one (real events W5): the last split is the time
        ({ fields, record } = finishFields(splitFormat(this.round), fields, record, value, shownValue(entry, 'puzzles', this.outbox.all()).value));
        // Change on a proposal (toolkit C9): the judge's value confirms it - the result carries the proposal
        const editing = this.proposalEditing !== null ? this.openProposal(this.proposalEditing) : null;

        if (editing !== null) {
            fields = [...fields.filter((field) => field.field !== 'result'), { field: 'result', to: value, proposalId: editing.id }];
        }

        // Finished with pieces missing (toolkit T6): a count typed that does not read stops the Save
        if (this.pendingMissing === false) {
            this.missingInputTarget.focus();

            return;
        }

        const missing = this.missingChange(entry);

        if (missing !== null) {
            // After the result - the server checks the entry is finished at that point of the group
            fields = [...fields, missing];
        }

        if (fields.length === 0) {
            this.showToast(this.t('toastNothing'), null);
            this.backToFind();

            return;
        }

        // Every change carries what the card showed as its `from` (D7) - the server's three-way check answers a conflict
        // for a value saved elsewhere that this card never showed
        fields = withShownFrom(fields, this.shownFrom);

        // Every change of the Save goes to Undo - a group already sent is no longer on the device to be found by its id
        const item = this.enqueueGroup(entry, fields);
        const choice = format !== null && Array.isArray(record) && record.length > 0 ? ` · ${describeChoice(format, record)}` : '';

        if (navigator.vibrate) {
            navigator.vibrate(30);
        }

        this.showToast(this.t('toastSaved', {
            '%name%': entry.displayName ?? entry.name ?? '',
            '%result%': (value === null ? this.t('clearResult') : this.describe(value, missingOf(this.pendingMissing ?? this.shownMissing(entry)))) + choice,
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
        this.openEntry((Array.isArray(item) ? item[0] : item).entryRef);
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
        const id = event.currentTarget.dataset.id;

        // After a sign-in the state first says whose session it is (D1)
        (this.outbox.viewerCheck ? this.refreshState() : Promise.resolve()).then((kind) => kind !== 'another_account' && this.outbox.retryNow(id).then(() => this.broadcast()));
    }

    // ---- Overlays ----

    openSheet() {
        if (!this.ready) {
            return;
        }

        if (this.hasAutoOpenToggleTarget) {
            this.autoOpenToggleTarget.checked = this.prefs().autoOpenTable !== false;
            this.autoOpenToggleTarget.closest('[data-live-results-prefs]').hidden = !this.usesTables();
        }

        if (this.hasMyTablesInputTarget) {
            this.myTablesInputTarget.value = this.myTables()?.text ?? '';
            this.myTablesInputTarget.classList.remove('is-invalid');
            this.myTablesInputTarget.removeAttribute('aria-invalid');
            this.myTablesErrorTarget.hidden = true;
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

    /**
     * A QR code read (also a decoded text handed in by hand): the name tag as always, the line-up, table and entry tags
     * (toolkit C8) resolved on this phone from the round it has - an entry or line-up of another round links to that
     * round (from this phone's copies of the event's rounds, else the tag's own address picks it).
     */
    async scanned(text) {
        this.stopScan = null;
        this.scannerTarget.hidden = true;
        const tag = parseCompetitionTagUrl(text);
        const competitionId = String(this.settings.competitionId ?? '').toLowerCase();

        if (tag === null || tag.competitionId !== competitionId) {
            this.showFind({ focus: false });
            this.releaseHistory();
            this.notice(this.t(tag === null || tag.kind === 'participant' ? 'scanForeign' : 'tagForeign'), 'warning');

            return;
        }

        const entry = entryOfTag(this.allEntries(), tag);

        if (entry === null && tag.kind === 'participant') {
            this.showFind({ focus: false });
            this.releaseHistory();
            const link = fillLink(this.scanUrlValue, tag.id, this.settings.competitionId);
            this.notice(this.t('scanNotInRound', { '%round%': this.settings.roundName }), 'warning', ` <a class="alert-link" href="${escapeHtml(link)}">${escapeHtml(this.t('openTheirRound'))}</a>`);

            return;
        }

        if (entry === null) {
            this.showFind({ focus: false });
            this.releaseHistory();
            const { notice, parameters } = resolveLanding({ kind: 'tag', tag }, [], null, this.settings.roundName);
            let link = '';

            if (tag.kind !== 'table') {
                const others = this.snapshots === null ? [] : (await this.snapshots.list().catch(() => []))
                    .filter((snapshot) => String(snapshot.competitionId ?? '').toLowerCase() === competitionId && snapshot.roundId !== this.roundIdValue);
                const theirs = snapshotOfTag(others, tag, Date.now());
                const href = theirs === null
                    ? tagPath(tag)
                    : `${this.liveUrl(theirs.roundId)}?entry=${encodeURIComponent(entryOfTag(theirs.state.entries, tag).ref)}`;
                link = ` <a class="alert-link" href="${escapeHtml(href)}">${escapeHtml(this.t('openTheirRound'))}</a>`;
            }

            if (this.isConnected()) {
                this.notice(this.t(notice, parameters), 'warning', link);
            }

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
        this.addSuggestionListTarget.innerHTML = rowsOf(suggestions.map((entry) => ({ entry })), outboxByEntry(items)).map((row) => this.rowHtml(row)).join('');
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
        this.entriesChanged();

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
        this.entriesChanged();

        if (table !== null) {
            this.enqueue(entry, 'table_number', table);
        }

        this.openEntry(entry.ref);
    }
}
