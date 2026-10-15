// Runs assets/instant_back.js (docs/features/navigation README §4.13 G9, requirements GEST-14 and GEST-15) through the
// cases tests/InstantBackScriptTest.php asks for and prints one JSON object {case: answer}. The DOM parts run in jsdom
// (a real <body> to clean, real events); Turbo is a fake session with the internals the module reads.

import { createRequire } from 'node:module';
import {
    BEFORE_CACHE_EVENT,
    BFCACHE_RELOAD_ATTRIBUTE,
    BODY_LAYER_CLASSES,
    CACHE_CONTROL_META,
    CLEARS_SNAPSHOTS_ATTRIBUTE,
    CLONE_BUDGET_UNITS,
    CLEARS_SNAPSHOTS_META,
    LAST_WRITE_KEY,
    INSTANT_BACK,
    INSTANT_BACK_IOS,
    KEEP_BFCACHE,
    MAX_AGE_MS,
    MODAL_FRAME_ID,
    NO_CACHE,
    NO_PREVIEW,
    PAGEHIDE_KEY_PREFIX,
    PICTURE_UNITS,
    REBUILT_ON_CONNECT,
    RESTORE_DECIDED_EVENT,
    RESTORE_RELOAD_KEY,
    SCRIPT_BUILT_LAYERS,
    SNAPSHOT_LIMIT,
    SNAPSHOT_RESTORES,
    VIEWER_KEY,
    VIEWER_META,
    bfcacheVerdict,
    cacheWorkTiming,
    cloneUnits,
    createInstantBack,
    createSnapshotLedger,
    isWriteRequest,
    keepsNoCopy,
    restoreDecision,
    sanitizeSnapshot,
    snapshotKey,
    teardownWidgets,
    viewTransitionExpected,
} from '../assets/instant_back.js';
import { forgetFontsSettled, whenFontsReady } from '../assets/fonts_settled.js';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const ORIGIN = 'https://msp.test';
const results = {};

async function run(name, fn) {
    try {
        results[name] = await fn();
    } catch (error) {
        results[name] = { threw: String(error && error.stack ? error.stack : error) };
    }
}

function storage(values = {}, { throws = false } = {}) {
    return {
        values,
        getItem: (key) => {
            if (throws) {
                throw new Error('SecurityError');
            }

            return key in values ? values[key] : null;
        },
        setItem: (key, value) => {
            if (throws) {
                throw new Error('QuotaExceededError');
            }

            values[key] = String(value);
        },
        removeItem: (key) => {
            if (throws) {
                throw new Error('SecurityError');
            }

            delete values[key];
        },
    };
}

// ---- Constants --------------------------------------------------------------------------------------------------------

await run('constants', () => ({
    INSTANT_BACK,
    INSTANT_BACK_IOS,
    KEEP_BFCACHE,
    MAX_AGE_MS,
    SNAPSHOT_RESTORES,
    SNAPSHOT_LIMIT,
    PAGEHIDE_KEY_PREFIX,
    VIEWER_KEY,
    VIEWER_META,
    RESTORE_RELOAD_KEY,
    BFCACHE_RELOAD_ATTRIBUTE,
    CLEARS_SNAPSHOTS_ATTRIBUTE,
    BEFORE_CACHE_EVENT,
    RESTORE_DECIDED_EVENT,
    NO_CACHE,
    NO_PREVIEW,
    BODY_LAYER_CLASSES,
    CACHE_CONTROL_META,
    CLONE_BUDGET_UNITS,
    PICTURE_UNITS,
    SCRIPT_BUILT_LAYERS,
    REBUILT_ON_CONNECT,
    MODAL_FRAME_ID,
}));

// ---- GEST-14: the back/forward cache verdict ------------------------------------------------------------------------

await run('bfcache verdict', () => {
    const fresh = { persisted: true, awayMs: 60_000, reloadMarker: false, os: 'android', native: false, viewerChanged: false, postedFrom: false };

    return {
        notPersisted: bfcacheVerdict({ ...fresh, persisted: false }),
        fresh: bfcacheVerdict(fresh),
        desktop: bfcacheVerdict({ ...fresh, os: 'other' }),
        tenMinutes: bfcacheVerdict({ ...fresh, awayMs: MAX_AGE_MS }),
        elevenMinutes: bfcacheVerdict({ ...fresh, awayMs: 11 * 60_000 }),
        unknownAway: bfcacheVerdict({ ...fresh, awayMs: null }),
        negativeAway: bfcacheVerdict({ ...fresh, awayMs: -5 }),
        reloadMarker: bfcacheVerdict({ ...fresh, reloadMarker: true }),
        viewerChanged: bfcacheVerdict({ ...fresh, viewerChanged: true }),
        postedFrom: bfcacheVerdict({ ...fresh, postedFrom: true }),
        wroteSince: bfcacheVerdict({ ...fresh, wroteSince: true }),
        iosWithoutTheSwitch: bfcacheVerdict({ ...fresh, os: 'ios', instantBackIos: false }),
        iosWithTheSwitch: bfcacheVerdict({ ...fresh, os: 'ios', instantBackIos: true }),
        nativeApp: bfcacheVerdict({ ...fresh, native: true }),
        reverted: bfcacheVerdict({ ...fresh, keepBfcache: false }),
    };
});

// ---- GEST-15: the restore decision ----------------------------------------------------------------------------------

await run('restore decision', () => {
    const now = 1_000_000_000;
    const entry = { cachedAt: now - 60_000, viewer: 'v1' };
    const base = { native: false, os: 'android', entry, now, viewer: 'v1' };

    return {
        fresh: restoreDecision(base),
        desktop: restoreDecision({ ...base, os: 'other' }),
        exactlyTenMinutes: restoreDecision({ ...base, entry: { cachedAt: now - MAX_AGE_MS, viewer: 'v1' } }),
        elevenMinutes: restoreDecision({ ...base, entry: { cachedAt: now - 11 * 60_000, viewer: 'v1' } }),
        cachedInTheFuture: restoreDecision({ ...base, entry: { cachedAt: now + 1, viewer: 'v1' } }),
        noSnapshot: restoreDecision({ ...base, entry: null }),
        turboForgotIt: restoreDecision({ ...base, turboHasSnapshot: false }),
        otherViewer: restoreDecision({ ...base, viewer: 'v2' }),
        writtenAfterTheCopy: restoreDecision({ ...base, lastWriteAt: now - 30_000 }),
        writtenBeforeTheCopy: restoreDecision({ ...base, lastWriteAt: now - 90_000 }),
        guestBoth: restoreDecision({ ...base, entry: { cachedAt: now - 1, viewer: '' }, viewer: '' }),
        iosWithoutTheSwitch: restoreDecision({ ...base, os: 'ios', instantBackIos: false }),
        iosWithTheSwitch: restoreDecision({ ...base, os: 'ios', instantBackIos: true }),
        reverted: restoreDecision({ ...base, instantBack: false }),
        nativeApp: restoreDecision({ ...base, native: true }),
        nativeAppReverted: restoreDecision({ ...base, native: true, instantBack: false }),
        // F3: the viewer this browser's last page was rendered for (any tab) - a sign-out elsewhere
        signedOutElsewhere: restoreDecision({ ...base, storedViewer: '' }),
        otherAccountElsewhere: restoreDecision({ ...base, storedViewer: 'v2' }),
        sameViewerStored: restoreDecision({ ...base, storedViewer: 'v1' }),
        storedViewerUnknown: restoreDecision({ ...base, storedViewer: null }),
        guestCopySignedInElsewhere: restoreDecision({ ...base, entry: { cachedAt: now - 1, viewer: '' }, viewer: '', storedViewer: 'v2' }),
    };
});

// F1 / F11: which page left keeps no copy - a visit by a write, a page too large to copy within a frame; never a visit
// that renders nothing of the page
await run('pages kept out of the cache', () => ({
    plainVisit: keepsNoCopy({ willRender: true, byWrite: false, elements: 2400, pictures: 23 }),
    byWrite: keepsNoCopy({ willRender: true, byWrite: true, elements: 10, pictures: 0 }),
    frameVisitByWrite: keepsNoCopy({ willRender: false, byWrite: true }),
    largeByElements: keepsNoCopy({ elements: CLONE_BUDGET_UNITS + 1, pictures: 0 }),
    atTheBudget: keepsNoCopy({ elements: CLONE_BUDGET_UNITS, pictures: 0 }),
    largeByPictures: keepsNoCopy({ elements: 1000, pictures: 110 }),
    largeFrameVisit: keepsNoCopy({ willRender: false, elements: 100000, pictures: 1000 }),
    units: [cloneUnits({ elements: 2439, pictures: 23 }), cloneUnits({ elements: 3781, pictures: 105 }), cloneUnits({}), cloneUnits({ elements: -5, pictures: NaN })],
}));

await run('cache work timing', () => ({
    androidPlaceSwitch: cacheWorkTiming({ viewTransition: viewTransitionExpected({ meta: 'same-origin', available: true, reducedMotion: false, native: false }) }),
    iosOrDesktop: cacheWorkTiming({ viewTransition: viewTransitionExpected({ meta: 'none', available: true, reducedMotion: false, native: false }) }),
    noApi: cacheWorkTiming({ viewTransition: viewTransitionExpected({ meta: 'same-origin', available: false }) }),
    reducedMotion: cacheWorkTiming({ viewTransition: viewTransitionExpected({ meta: 'same-origin', available: true, reducedMotion: true }) }),
    nativeApp: cacheWorkTiming({ viewTransition: viewTransitionExpected({ meta: 'same-origin', available: true, native: true }) }),
}));

await run('ledger', () => {
    const ledger = createSnapshotLedger();

    for (let i = 1; i <= 12; i++) {
        ledger.record(`${ORIGIN}/en/page-${i}`, { cachedAt: i, viewer: '' });
    }

    const afterTwelve = { size: ledger.size(), first: ledger.entry(`${ORIGIN}/en/page-1`), third: ledger.entry(`${ORIGIN}/en/page-3`) };
    // Cached again: the most recent one, the least recent goes next
    ledger.record(`${ORIGIN}/en/page-3`, { cachedAt: 30, viewer: 'v' });
    ledger.record(`${ORIGIN}/en/page-13`, { cachedAt: 13, viewer: '' });
    const reCached = { third: ledger.entry(`${ORIGIN}/en/page-3`), fourth: ledger.entry(`${ORIGIN}/en/page-4`) };
    ledger.forget(`${ORIGIN}/en/page-3`);
    const forgotten = ledger.entry(`${ORIGIN}/en/page-3`);
    ledger.clear();

    return {
        afterTwelve,
        reCached,
        forgotten,
        cleared: ledger.size(),
        keyWithoutHash: snapshotKey('/en/hub#feed', `${ORIGIN}/en/faq`),
        keyJunk: snapshotKey('http://[', `${ORIGIN}/`),
    };
});

// ---- The clean copy --------------------------------------------------------------------------------------------------

const DIRTY = `<!doctype html><html><head><meta name="${VIEWER_META}" content="v1"></head>
<body class="modal-open global-search-shown keep-me" style="overflow: hidden; padding-right: 15px;" data-bs-overflow="" data-bs-padding-right="">
  <header id="site-header" inert><button id="burger" aria-haspopup="dialog" aria-expanded="true">☰</button></header>
  <main id="main" inert>
    <div id="menu-slot"></div>
    <div class="dropdown"><button id="toggle" data-bs-toggle="dropdown" class="show" aria-expanded="true">⋯</button>
      <ul id="dropmenu" class="dropdown-menu show" data-bs-popper="static" style="position: absolute; inset: 0px auto auto 0px; transform: translate(0px, 40px); color: red;"></ul></div>
    <details id="results-filters" open><summary>Filters</summary></details>
    <details id="lang" class="menu-language" open><summary>Language</summary></details>
    <span class="msp-ripple-host"><span class="msp-ripple"></span></span>
    <img id="thumb" style="view-transition-name: puzzle-hero; width: 10px;">
    <div id="recap" class="is-celebrating is-leveled" data-controller="recap-celebration"></div>
    <span class="puzzle-picker-confetti-host"></span>
    <div class="fixed-top" data-bs-padding-right="4px" style="padding-right: 19px;"></div>
    <div class="ev-row"><p class="ev-signin-note ev-follow-note" role="alert">Sign in to follow</p></div>
  </main>
  <div class="ev-menu-layer is-sheet"><div class="ev-menu-scrim"></div><div class="ev-menu-dialog" role="dialog"><turbo-frame id="event-manage-menu" src="/en/events/x/menu" complete><form method="post"></form></turbo-frame></div></div>
  <div class="pwa-ptr-indicator"></div>
  <div class="modal fade" id="imageEditorModal" tabindex="-1" aria-hidden="true"></div>
  <div id="toast-container"><div class="toast show">Saved</div></div>
  <div class="modal fade show" id="announcement" style="display: block;" aria-modal="true" role="dialog" data-controller="autoshow-modal"></div>
  <div class="modal fade show" id="dynamic-modal-container" style="display: block;" aria-modal="true" role="dialog"><turbo-frame id="modal-frame" data-dynamic-modal-target="frame" src="/en/edit-time/1" complete><form method="post" action="/en/x"></form></turbo-frame></div>
  <div class="modal-backdrop fade show"></div>
  <dialog id="menu-layer" class="menu-layer is-closing menu-layer--overlay is-searching" open><div class="menu-layer-body"><nav id="site-menu"><a>Row</a></nav></div></dialog>
  <dialog class="player-card-layer" open></dialog>
  <dialog id="confirm" open></dialog>
</body></html>`;

function cleaned(dom) {
    const doc = dom.window.document;
    const body = doc.body;
    const attr = (id, name) => (doc.getElementById(id) ? doc.getElementById(id).getAttribute(name) : 'gone');

    return {
        announcementGone: doc.getElementById('announcement') === null,
        dynamicModal: { cls: doc.getElementById('dynamic-modal-container').className, display: doc.getElementById('dynamic-modal-container').style.display, ariaHidden: attr('dynamic-modal-container', 'aria-hidden'), ariaModal: attr('dynamic-modal-container', 'aria-modal') },
        backdrops: doc.querySelectorAll('.modal-backdrop').length,
        bodyClass: body.className,
        bodyStyle: body.getAttribute('style') || '',
        bodyBsAttributes: ['data-bs-overflow', 'data-bs-padding-right'].filter((name) => body.hasAttribute(name)),
        fixedTop: doc.querySelector('.fixed-top').getAttribute('style'),
        inert: ['site-header', 'main'].filter((id) => doc.getElementById(id).hasAttribute('inert')),
        burgerExpanded: attr('burger', 'aria-expanded'),
        dropdown: { menu: doc.getElementById('dropmenu').className, popper: attr('dropmenu', 'data-bs-popper'), style: doc.getElementById('dropmenu').getAttribute('style'), toggle: doc.getElementById('toggle').className, toggleExpanded: attr('toggle', 'aria-expanded') },
        filtersOpen: doc.getElementById('results-filters').open,
        languageOpen: doc.getElementById('lang').open,
        ripples: doc.querySelectorAll('.msp-ripple-host').length,
        thumbStyle: doc.getElementById('thumb').getAttribute('style'),
        recap: doc.getElementById('recap').className,
        confettiHosts: doc.querySelectorAll('.puzzle-picker-confetti-host').length,
        toasts: doc.getElementById('toast-container').children.length,
        openDialogs: doc.querySelectorAll('dialog[open]').length,
        cardDialogs: doc.querySelectorAll('dialog.player-card-layer').length,
        menuShell: doc.getElementById('menu-layer').className,
        siteMenuInSlot: doc.getElementById('site-menu').parentElement.id,
        // F2 / F7: the ⋯ menu layer and the guest's note never stay
        scriptLayers: doc.querySelectorAll(SCRIPT_BUILT_LAYERS).length,
        // Built again on connect: gone from a copy about to be shown only (the live page keeps its own)
        rebuiltOnConnect: doc.querySelectorAll(REBUILT_ON_CONNECT).length,
        modalFrame: (() => { const f = doc.getElementById(MODAL_FRAME_ID); return { children: f.children.length, src: f.getAttribute('src'), complete: f.hasAttribute('complete'), dataTarget: f.getAttribute('data-dynamic-modal-target') }; })(),
    };
}

await run('sanitize live body', () => {
    const dom = new JSDOM(DIRTY, { url: `${ORIGIN}/en/hub` });
    const ordered = [];
    sanitizeSnapshot(dom.window.document.body, { menuOrder: (nav) => ordered.push(nav.id) });

    return { ...cleaned(dom), menuOrdered: ordered };
});

await run('sanitize detached copy', () => {
    const dom = new JSDOM(DIRTY, { url: `${ORIGIN}/en/hub` });
    const copy = dom.window.document.body.cloneNode(true);
    sanitizeSnapshot(copy);
    // The copy in its own document, to read it the same way
    const other = new JSDOM('<!doctype html><html><head></head><body></body></html>', { url: `${ORIGIN}/en/hub` });
    other.window.document.body.replaceWith(other.window.document.importNode(copy, true));

    return { ...cleaned(other), liveUntouched: dom.window.document.querySelectorAll('.modal-backdrop').length };
});

await run('sanitize never throws', () => {
    sanitizeSnapshot(null);
    sanitizeSnapshot({});
    sanitizeSnapshot({ querySelectorAll() { throw new Error('boom'); } });

    return 'ok';
});

await run('widgets', () => {
    const dom = new JSDOM('<!doctype html><html><body><select id="s" class="tomselected"></select><select id="plain"></select><input id="d" class="flatpickr-input"><canvas id="c"></canvas><canvas id="away"></canvas></body></html>', { url: `${ORIGIN}/` });
    const doc = dom.window.document;
    const destroyed = [];
    doc.getElementById('s').tomselect = { destroy: () => destroyed.push('tomselect') };
    doc.getElementById('d')._flatpickr = { destroy: () => destroyed.push('flatpickr') };
    const charts = new Set([
        { canvas: doc.getElementById('c'), destroy: () => destroyed.push('chart') },
        { canvas: { isConnected: false }, destroy: () => destroyed.push('chart elsewhere') },
        { canvas: doc.getElementById('away'), destroy: () => { throw new Error('gone'); } },
    ]);
    const count = teardownWidgets(doc.body, charts);

    return { count, destroyed, nothingToTearDown: teardownWidgets(null) };
});

// ---- The browser instance (jsdom + a fake Turbo session) ---------------------------------------------------------------

function browser({ os = 'android', native = false, viewTransitions = false, meta = 'same-origin', cacheHas = true, sessionStore = storage(), localStore = storage(), viewer = 'v1', audience = 'everyone signed_in', fetchImpl = null } = {}) {
    // A guest's page carries no msp-viewer meta (viewer null here) - its body's audience says it is a guest's
    const viewerMeta = viewer === null ? '' : `<meta name="${VIEWER_META}" content="${viewer}">`;
    const dom = new JSDOM(`<!doctype html><html data-os="${os}"${native ? ' data-native-app="android"' : ''}><head>
        <meta name="turbo-cache-control" content="no-preview">${viewerMeta}<meta name="view-transition" content="${meta}">
        </head><body data-viewer-audience="${audience}"><main><a id="fav" href="/en/fav" ${CLEARS_SNAPSHOTS_ATTRIBUTE}>Favorite</a><a id="plain" href="/en/ladder">Ladder</a><a id="logout" href="/logout" data-turbo="false" ${CLEARS_SNAPSHOTS_ATTRIBUTE}>Sign out</a><form id="post-form" method="post" action="/en/x"><button>Go</button></form></main></body></html>`,
    { url: `${ORIGIN}/en/hub`, pretendToBeVisual: true });
    const { window } = dom;
    const document = window.document;
    let clock = 1_700_000_000_000;
    const timers = [];
    const frames = [];
    const assigned = [];
    const cancels = [];
    const cacheClears = [];
    const closeForCache = [];
    const events = [];

    if (viewTransitions) {
        document.startViewTransition = () => ({ finished: Promise.resolve() });
    }

    // location.href = url: recorded, never navigated (jsdom cannot)
    const location = { get href() { return window.location.href; }, set href(value) { assigned.push(String(value)); } };
    const fakeWindow = new Proxy(window, {
        get(target, key) {
            if (key === 'location') {
                return location;
            }

            const value = Reflect.get(target, key, target);

            return typeof value === 'function' ? value.bind(target) : value;
        },
    });

    const currentVisit = { action: 'advance', willRender: true, cancel: () => cancels.push('cancel') };
    const turbo = {
        session: {
            view: {
                lastRenderedLocation: new URL(`${ORIGIN}/en/hub`),
                snapshotCache: { has: () => cacheHas },
            },
            navigator: { currentVisit },
            history: { restorationIdentifier: 'r-hub', restorationData: {} },
        },
        cache: { clear: () => cacheClears.push('clear') },
    };

    [BEFORE_CACHE_EVENT, RESTORE_DECIDED_EVENT].forEach((type) => document.addEventListener(type, (event) => events.push({ type, detail: event.detail && event.detail.kind ? { kind: event.detail.kind } : null })));

    if (fetchImpl) {
        window.fetch = fetchImpl;
    }

    const instance = createInstantBack({
        window: fakeWindow,
        document,
        turbo: () => turbo,
        layers: { closeForCache: () => closeForCache.push('close') },
        now: () => clock,
        setTimeout: (fn) => timers.push(fn),
        raf: (fn) => frames.push(fn),
        session: sessionStore,
        local: localStore,
        documentId: 'doc-1',
    }).install();

    const fire = (type, detail = {}, cancelable = false) => {
        const event = new window.CustomEvent(type, { detail, cancelable, bubbles: true });
        document.dispatchEvent(event);

        return event;
    };

    return {
        dom, window, document, instance, turbo, currentVisit, fire, timers, frames, assigned, cancels, cacheClears, closeForCache, events, sessionStore, localStore,
        tick: (ms) => { clock += ms; },
        now: () => clock,
        flushTimers: () => { timers.splice(0).forEach((fn) => fn()); },
        flushFrames: () => { frames.splice(0).forEach((fn) => fn()); },
        scrollBehavior: () => document.documentElement.style.getPropertyValue('scroll-behavior'),
    };
}

/** Leaves the page with a Turbo visit: turbo:visit, then the cache (as Turbo 8.0.23 orders them) */
function leave(b, url = `${ORIGIN}/en/puzzle/1`) {
    b.fire('turbo:visit', { url, action: 'advance' });
    b.fire('turbo:before-cache');
}

await run('snapshot restore', () => {
    const b = browser({ os: 'other' });
    leave(b);
    const recorded = b.instance.ledger.entry(`${ORIGIN}/en/hub`);
    const cacheWork = { closeForCache: b.closeForCache.length, beforeCacheEvents: b.events.filter((e) => e.type === BEFORE_CACHE_EVENT).length };
    b.fire('turbo:load');
    b.tick(4 * 60_000);
    // Back: Turbo's restore visit of the Hub
    b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
    const atVisit = { isSnapshotRestore: b.instance.isSnapshotRestore(), kind: b.instance.lastRestoreKind(), age: b.instance.restoredSnapshotAge(), scrollBehavior: b.scrollBehavior(), assigned: b.assigned.length, cancels: b.cancels.length };
    // The cached copy is cleaned before it is shown
    const copy = b.document.createElement('body');
    copy.innerHTML = '<div class="modal-backdrop"></div><div id="m" class="modal show" style="display:block"></div>';
    const render = b.fire('turbo:before-render', { newBody: copy }, true);
    const copyClean = { backdrops: copy.querySelectorAll('.modal-backdrop').length, modal: copy.querySelector('#m').className, prevented: render.defaultPrevented };
    b.fire('turbo:render');
    b.fire('turbo:load');
    const atLoad = b.scrollBehavior();
    b.flushFrames();

    return {
        recorded,
        cacheWork,
        atVisit,
        copyClean,
        scrollHeldThroughLoad: atLoad,
        scrollAfterFrame: b.scrollBehavior(),
        decided: b.events.filter((e) => e.type === RESTORE_DECIDED_EVENT).map((e) => e.detail.kind),
        // The next page shown by a visit: no restore any more
        afterVisit: (() => { b.fire('turbo:visit', { url: `${ORIGIN}/en/faq`, action: 'advance' }); return { isSnapshotRestore: b.instance.isSnapshotRestore(), kind: b.instance.lastRestoreKind(), age: b.instance.restoredSnapshotAge() }; })(),
    };
});

await run('reload restores', () => {
    const answers = {};

    // Older than 10 minutes (Date.now shifted, as the acceptance script does)
    {
        const b = browser({ os: 'other' });
        leave(b);
        b.fire('turbo:load');
        b.tick(11 * 60_000);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        const render = b.fire('turbo:before-render', { newBody: b.document.createElement('body') }, true);
        answers.elevenMinutes = { assigned: b.assigned, cancels: b.cancels.length, mark: b.sessionStore.values[RESTORE_RELOAD_KEY] || null, renderPrevented: render.defaultPrevented, kind: b.instance.lastRestoreKind() };
        // A cache after the reload decision: nothing recorded, nothing cleaned
        const before = b.closeForCache.length;
        b.fire('turbo:before-cache');
        answers.elevenMinutes.cacheWorkAfter = b.closeForCache.length - before;
    }

    // A page never cached (not opted in)
    {
        const b = browser({ os: 'other' });
        b.fire('turbo:visit', { url: `${ORIGIN}/en/stopwatch`, action: 'restore' });
        answers.notCached = { assigned: b.assigned };
    }

    // Turbo no longer holds it
    {
        const b = browser({ os: 'other', cacheHas: false });
        leave(b);
        b.fire('turbo:load');
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.evicted = { assigned: b.assigned };
    }

    // The app: the native adapter's
    {
        const b = browser({ native: true });
        leave(b);
        b.fire('turbo:load');
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.nativeApp = { assigned: b.assigned, kind: b.instance.lastRestoreKind(), decided: b.events.filter((e) => e.type === RESTORE_DECIDED_EVENT).length };
    }

    return answers;
});

await run('cache work timing in the page', () => {
    // Android: a view transition captures the page in the frame of turbo:before-cache - the cache work waits a task
    const android = browser({ os: 'android', viewTransitions: true, meta: 'same-origin' });
    leave(android);
    const beforeTask = { closeForCache: android.closeForCache.length, events: android.events.length, timers: android.timers.length };
    android.flushTimers();
    const afterTask = { closeForCache: android.closeForCache.length, events: android.events.filter((e) => e.type === BEFORE_CACHE_EVENT).length };

    // iOS / desktop: nothing captures the page - at once
    const ios = browser({ os: 'ios', viewTransitions: true, meta: 'none' });
    leave(ios);

    return { beforeTask, afterTask, iosAtOnce: { closeForCache: ios.closeForCache.length, timers: ios.timers.length } };
});

await run('caches that leave the page as it is', () => {
    // turbo:before-cache without a visit (Turbo caches the page for a history entry without state): the page stays
    const pop = browser({ os: 'other' });
    pop.fire('turbo:before-cache');

    // A frame visit promoted to the address bar does not render the page
    const frame = browser({ os: 'other' });
    frame.currentVisit.willRender = false;
    leave(frame);

    return {
        withoutVisit: { recorded: pop.instance.ledger.size(), work: pop.closeForCache.length },
        frameVisit: { recorded: frame.instance.ledger.size(), work: frame.closeForCache.length },
    };
});

await run('a page that rewrote its own address', () => {
    // A Live component's URL-bound props replaced the query string after Turbo rendered the page
    const b = browser({ os: 'other' });
    b.window.history.replaceState(null, '', '/en/hub?scope=cz');
    leave(b);
    const recorded = { shown: b.instance.ledger.entry(`${ORIGIN}/en/hub?scope=cz`) !== null, rendered: b.instance.ledger.entry(`${ORIGIN}/en/hub`) !== null, turboKey: b.turbo.session.view.lastRenderedLocation.href };
    b.fire('turbo:load');

    // On a restore the address is the target's already: never taken over
    const r = browser({ os: 'other' });
    r.currentVisit.action = 'restore';
    r.window.history.replaceState(null, '', '/en/puzzle/brands');
    r.fire('turbo:visit', { url: `${ORIGIN}/en/puzzle/brands`, action: 'restore' });
    r.fire('turbo:before-cache');

    return { recorded, restore: { turboKey: r.turbo.session.view.lastRenderedLocation.href } };
});

await run('staleness', () => {
    const b = browser({ os: 'other' });
    leave(b);
    b.fire('turbo:load');
    const recorded = b.instance.ledger.size();

    b.fire('turbo:submit-end', { success: true, formSubmission: { method: 'get' } });
    const afterGet = { recorded: b.instance.ledger.size(), clears: b.cacheClears.length };
    b.fire('turbo:submit-end', { success: false, formSubmission: { method: 'post' } });
    const afterRefusedPost = { recorded: b.instance.ledger.size(), clears: b.cacheClears.length };
    b.fire('turbo:submit-end', { success: true, formSubmission: { method: 'post' } });
    const afterPost = { recorded: b.instance.ledger.size(), clears: b.cacheClears.length };

    leave(b);
    b.fire('turbo:load');
    const link = b.document.getElementById('fav');
    // The click itself (capture phase - a link Turbo does not follow, a new tab, sign-out alike): not turbo:click
    link.addEventListener('click', (event) => event.preventDefault());
    link.dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    const afterFavorite = { recorded: b.instance.ledger.size(), clears: b.cacheClears.length };

    b.instance.clearSnapshots();

    return { recorded, afterGet, afterRefusedPost, afterPost, afterFavorite, cleared: b.instance.ledger.size() };
});

await run('bfcache in the page', () => {
    const answers = {};
    const persisted = { persisted: true };

    {
        const b = browser({ os: 'android' });
        b.instance.stampPageHide();
        const stamped = b.sessionStore.values[PAGEHIDE_KEY_PREFIX + 'doc-1'];
        b.tick(5 * 60_000);
        answers.fiveMinutes = b.instance.bfcacheVerdictFor(persisted);
        answers.kindAfterKeep = b.instance.lastRestoreKind();
        answers.stamped = stamped === String(b.now() - 5 * 60_000);
        answers.notPersisted = b.instance.bfcacheVerdictFor({ persisted: false });
        // The acceptance script ages the stamp in sessionStorage from the next page
        b.sessionStore.values[PAGEHIDE_KEY_PREFIX + 'doc-1'] = String(b.now() - 11 * 60_000);
        answers.agedStamp = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        const b = browser({ os: 'android', sessionStore: storage({}, { throws: true }) });
        b.instance.stampPageHide();
        b.tick(60_000);
        answers.blockedStorageInMemory = b.instance.bfcacheVerdictFor(persisted);
        b.tick(10 * 60_000);
        answers.blockedStorageOld = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        const b = browser({ os: 'android' });
        const marker = b.document.createElement('div');
        marker.setAttribute(BFCACHE_RELOAD_ATTRIBUTE, '');
        b.document.body.appendChild(marker);
        b.instance.stampPageHide();
        answers.reloadMarker = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        const b = browser({ os: 'android' });
        b.instance.stampPageHide();
        // Another tab signed in as somebody else meanwhile
        b.localStore.values[VIEWER_KEY] = 'someone-else';
        answers.viewerChanged = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        // A guest's page (no meta): a guest is '' - somebody who signed in meanwhile makes it reload; still a guest keeps it
        const b = browser({ os: 'android', viewer: null, audience: 'everyone guest' });
        answers.guestRemembered = b.localStore.values[VIEWER_KEY];
        b.instance.stampPageHide();
        answers.guestStillGuest = b.instance.bfcacheVerdictFor(persisted);
        b.localStore.values[VIEWER_KEY] = 'signed-in-since';
        answers.guestSignedInMeanwhile = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        // A page that tells neither (no meta, no audience): its viewer is unknown - never checked against the stored one
        const b = browser({ os: 'android', viewer: null, audience: '' });
        b.localStore.values[VIEWER_KEY] = 'someone';
        b.document.body.removeAttribute('data-viewer-audience');
        b.instance.stampPageHide();
        answers.unknownViewer = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        const b = browser({ os: 'android' });
        answers.viewerRemembered = b.localStore.values[VIEWER_KEY];
        const form = b.document.getElementById('post-form');
        form.addEventListener('submit', (event) => event.preventDefault());
        form.dispatchEvent(new b.window.Event('submit', { bubbles: true, cancelable: true }));
        b.instance.stampPageHide();
        answers.postedFrom = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        const b = browser({ os: 'android' });
        b.instance.stampPageHide();
        b.instance.notePost();
        answers.postActionPosted = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        const b = browser({ native: true });
        b.instance.stampPageHide();
        answers.nativeApp = b.instance.bfcacheVerdictFor(persisted);
    }

    return answers;
});

await run('a restored document', () => {
    // The next document after a restore reload: the mark is read once - the page came back by a full load
    const sessionStore = storage({ [RESTORE_RELOAD_KEY]: `${ORIGIN}/en/hub` });
    const b = browser({ os: 'other', sessionStore });
    const cameBack = b.instance.cameBackByFullLoad(`${ORIGIN}/en/hub`);
    const kind = b.instance.lastRestoreKind();
    b.fire('turbo:visit', { url: `${ORIGIN}/en/faq`, action: 'advance' });

    return { cameBack, kind, markLeft: sessionStore.values[RESTORE_RELOAD_KEY] || null, kindAfterVisit: b.instance.lastRestoreKind(), cameBackAfterVisit: b.instance.cameBackByFullLoad(`${ORIGIN}/en/hub`) };
});

await run('fetch writes', async () => {
    const base = `${ORIGIN}/en/hub`;
    const rule = {
        get: isWriteRequest('GET', '/en/remove-from-wish-list/1', base),
        head: isWriteRequest('HEAD', '/en/x', base),
        post: isWriteRequest('POST', '/en/remove-from-wish-list/1', base),
        put: isWriteRequest('put', `${ORIGIN}/api/v1/me/collections/1`, base),
        liveAction: isWriteRequest('POST', '/en/_components/PuzzleTimes/changeChartView', base),
        liveBatch: isWriteRequest('POST', '/en/_components/Comparison/_batch', base),
        liveRerender: isWriteRequest('POST', '/en/_components/PuzzleSearch', base),
        liveRerenderSlash: isWriteRequest('POST', '/en/_components/PuzzleSearch/', base),
        telemetry: isWriteRequest('POST', '/-/nav-click', base),
        otherOrigin: isWriteRequest('POST', 'https://mercure.example/.well-known/mercure', base),
        junk: isWriteRequest('POST', 'http://[', base),
    };

    const calls = [];
    const fake = (input, init) => {
        calls.push([String(input && input.url ? input.url : input), init && init.method ? init.method : (input && input.method) || 'GET']);

        return Promise.resolve({ ok: !String(input && input.url ? input.url : input).includes('refused') });
    };
    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
    const persisted = { persisted: true };
    const answers = {};

    {
        const b = browser({ os: 'android', fetchImpl: fake });
        await b.window.fetch('/en/puzzle?page=2');
        await b.window.fetch('/en/_components/PuzzleSearch', { method: 'POST' });
        await b.window.fetch('/-/nav-click', { method: 'POST' });
        await settle();
        b.instance.stampPageHide();
        answers.readsOnly = { clears: b.cacheClears.length, verdict: b.instance.bfcacheVerdictFor(persisted) };
    }

    {
        const b = browser({ os: 'android', fetchImpl: fake });
        b.instance.ledger.record(`${ORIGIN}/en/puzzle`, { cachedAt: b.now(), viewer: 'v1' });
        const answer = await b.window.fetch('/en/_components/PuzzleTimes/changeChartView', { method: 'POST' });
        await settle();
        b.instance.stampPageHide();
        answers.liveAction = { answerPassedOn: answer.ok, clears: b.cacheClears.length, ledgerEntry: b.instance.ledger.entry(`${ORIGIN}/en/puzzle`), verdict: b.instance.bfcacheVerdictFor(persisted) };
    }

    {
        const b = browser({ os: 'android', fetchImpl: fake });
        await b.window.fetch(new Request(`${ORIGIN}/en/dismiss-hint/refused`, { method: 'POST' }));
        await settle();
        answers.refusedWrite = { clears: b.cacheClears.length };
        await b.window.fetch(new Request(`${ORIGIN}/en/dismiss-hint/x`, { method: 'POST' }));
        await settle();
        answers.requestObject = { clears: b.cacheClears.length };
        // A second instance over the same window (a module loaded twice) does not wrap it again
        const first = b.window.fetch;
        createInstantBack({ window: b.window, document: b.document, turbo: () => b.turbo, now: () => b.now(), setTimeout: () => {}, raf: () => {}, session: storage(), local: storage(), documentId: 'doc-2' }).install();
        answers.wrappedOnce = b.window.fetch === first;
    }

    return { rule, answers, sent: calls.length };
});

await run('writes elsewhere', () => {
    const persisted = { persisted: true };
    const answers = {};

    {
        // Another tab (or the next document) wrote after this page was hidden: its back/forward-cache copy reloads
        const localStore = storage();
        const b = browser({ os: 'android', localStore });
        b.instance.stampPageHide();
        b.tick(1000);
        localStore.values[LAST_WRITE_KEY] = String(b.now());
        answers.bfcacheAfterAWriteElsewhere = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        // ... and before it was hidden: kept
        const localStore = storage();
        const b = browser({ os: 'android', localStore });
        localStore.values[LAST_WRITE_KEY] = String(b.now() - 1000);
        b.instance.stampPageHide();
        answers.bfcacheAfterAnOlderWrite = b.instance.bfcacheVerdictFor(persisted);
    }

    {
        // A snapshot cached, then another tab wrote: the Back is a full load
        const localStore = storage();
        const b = browser({ os: 'other', localStore });
        leave(b);
        b.fire('turbo:load');
        b.tick(30_000);
        localStore.values[LAST_WRITE_KEY] = String(b.now());
        b.tick(30_000);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.snapshotAfterAWriteElsewhere = { kind: b.instance.lastRestoreKind(), assigned: b.assigned.length };
    }

    {
        // A POST form leaving the document (data-turbo="false"): every tab learns of the write at the submit
        const localStore = storage();
        const b = browser({ os: 'android', localStore });
        const form = b.document.getElementById('post-form');
        form.addEventListener('submit', (event) => event.preventDefault());
        form.dispatchEvent(new b.window.Event('submit', { bubbles: true, cancelable: true }));
        answers.postFormSubmitted = localStore.values[LAST_WRITE_KEY] === String(b.now());
    }

    {
        // A page whose GET wrote (the notifications page): shown by a visit, the copies go and every tab learns it
        const localStore = storage();
        const b = browser({ os: 'other', localStore });
        leave(b);
        const meta = b.document.createElement('meta');
        meta.setAttribute('name', CLEARS_SNAPSHOTS_META);
        b.document.head.appendChild(meta);
        b.fire('turbo:render');
        answers.pageThatWrote = { clears: b.cacheClears.length, ledger: b.instance.ledger.entry(`${ORIGIN}/en/hub`), lastWrite: localStore.values[LAST_WRITE_KEY] === String(b.now()) };
        meta.remove();
        b.fire('turbo:render');
        answers.pageThatDidNot = { clears: b.cacheClears.length };
    }

    return answers;
});

// ---- F1: a page left through a write never comes back from a copy taken before or around it -------------------------

const cacheControl = (b) => b.document.head.querySelector(`meta[name="${CACHE_CONTROL_META}"]`).getAttribute('content');

/** The user's click on a link of the page: the click (capture phase first), then Turbo's turbo:click for it */
function tap(b, id, { turboFollows = true } = {}) {
    const element = b.document.getElementById(id);
    element.addEventListener('click', (event) => event.preventDefault(), { once: true });
    element.dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, cancelable: true }));

    if (turboFollows) {
        element.dispatchEvent(new b.window.CustomEvent('turbo:click', { bubbles: true, detail: { url: element.href } }));
    }
}

const writeFetch = (input) => Promise.resolve({ ok: !String(input && input.url ? input.url : input).includes('refused') });
const settled = () => new Promise((resolve) => setTimeout(resolve, 0));

await run('a page left through a write', async () => {
    const answers = {};

    {
        // Review S1: the profile (reached by a visit) → Favorite (a GET link that writes, redirected back to the same
        // address: Turbo renders it, then its replace visit that renders nothing) → Back to the entry below
        const b = browser({ os: 'other' });
        b.fire('turbo:load');
        b.tick(1000);
        tap(b, 'fav');
        b.fire('turbo:visit', { url: `${ORIGIN}/en/add-player-to-favorites/1`, action: 'advance' });
        const atVisit = cacheControl(b);
        // The worst case: Turbo copies the page anyway (a meta it never read) - the copy is not trusted
        b.fire('turbo:before-cache');
        const copiedAt = b.now();
        const recordedAroundTheWrite = b.instance.ledger.size();
        b.tick(400);
        b.fire('turbo:render');
        b.currentVisit.willRender = false;
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'replace' });
        b.currentVisit.willRender = true;
        b.fire('turbo:load');
        const stamped = Number(b.localStore.values[LAST_WRITE_KEY]);
        b.tick(2000);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.favoriteThenBack = {
            cacheControlAtVisit: atVisit,
            recordedAroundTheWrite,
            ledgerAfterTheVisit: b.instance.ledger.entry(`${ORIGIN}/en/hub`),
            writeStampedAfterTheCopy: stamped >= copiedAt,
            back: b.instance.lastRestoreKind(),
            assigned: b.assigned.length,
        };
    }

    {
        // A write by fetch (a Live action) whose answer starts a visit by script (Live's redirect): no click of the user
        const b = browser({ os: 'other', fetchImpl: writeFetch });
        b.fire('turbo:load');
        await b.window.fetch('/en/_components/PuzzleTimes/save', { method: 'POST' });
        await settled();
        b.fire('turbo:visit', { url: `${ORIGIN}/en/puzzle/1`, action: 'advance' });
        const atVisit = cacheControl(b);
        b.fire('turbo:render');
        b.fire('turbo:load');
        b.tick(1000);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.scriptVisitAfterAWrite = { cacheControlAtVisit: atVisit, back: b.instance.lastRestoreKind() };
    }

    {
        // A write in place (the page shows its result), then the user's own link: the copy is taken after it - kept
        const b = browser({ os: 'other', fetchImpl: writeFetch });
        b.fire('turbo:load');
        await b.window.fetch('/en/_components/MarketplaceListing/loadMore', { method: 'POST' });
        await settled();
        b.tick(1000);
        tap(b, 'plain');
        b.fire('turbo:visit', { url: `${ORIGIN}/en/ladder`, action: 'advance' });
        const atVisit = cacheControl(b);
        b.fire('turbo:before-cache');
        b.fire('turbo:render');
        b.fire('turbo:load');
        b.tick(60_000);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.userLinkAfterAWriteInPlace = { cacheControlAtVisit: atVisit, back: b.instance.lastRestoreKind() };
    }

    {
        // A write that lands while the user's visit is on its way (a Live action answered late): the copy taken then is
        // not trusted
        let resolveWrite;
        const late = () => new Promise((resolve) => { resolveWrite = () => resolve({ ok: true }); });
        const b = browser({ os: 'other', fetchImpl: late });
        b.fire('turbo:load');
        const pending = b.window.fetch('/en/_components/PuzzleTimes/save', { method: 'POST' });
        tap(b, 'plain');
        b.fire('turbo:visit', { url: `${ORIGIN}/en/ladder`, action: 'advance' });
        resolveWrite();
        await pending;
        await settled();
        b.fire('turbo:before-cache');
        const recorded = b.instance.ledger.size();
        b.fire('turbo:render');
        b.fire('turbo:load');
        b.tick(1000);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.writeDuringTheVisit = { recorded, back: b.instance.lastRestoreKind() };
    }

    {
        // A link whose click writes on its way (the guide's beacon) - no data-clears-snapshots on it
        const b = browser({ os: 'other' });
        b.fire('turbo:load');
        b.document.getElementById('plain').addEventListener('click', () => b.instance.clearSnapshots());
        tap(b, 'plain');
        b.fire('turbo:visit', { url: `${ORIGIN}/en/ladder`, action: 'advance' });
        answers.clickThatWrote = { cacheControlAtVisit: cacheControl(b) };
    }

    {
        // A click that only steered a frame (no visit), then a write, then a visit by script: the write's
        const b = browser({ os: 'other', fetchImpl: writeFetch });
        b.fire('turbo:load');
        tap(b, 'plain');
        await b.window.fetch('/en/_components/PuzzleTimes/save', { method: 'POST' });
        await settled();
        b.fire('turbo:visit', { url: `${ORIGIN}/en/puzzle/1`, action: 'advance' });
        answers.frameClickThenAWrite = { cacheControlAtVisit: cacheControl(b) };
    }

    {
        // The user's GET form (a search) after a write in place: the user's visit - the copy is kept
        const b = browser({ os: 'other', fetchImpl: writeFetch });
        b.fire('turbo:load');
        await b.window.fetch('/en/_components/PuzzleTimes/save', { method: 'POST' });
        await settled();
        b.fire('turbo:submit-start', { formSubmission: { method: 'get' } });
        b.fire('turbo:visit', { url: `${ORIGIN}/en/puzzle?search=sea`, action: 'advance' });
        answers.getFormAfterAWrite = { cacheControlAtVisit: cacheControl(b) };
    }

    {
        // Sign-out (data-turbo="false", data-clears-snapshots): a write every tab learns of at the click
        const b = browser({ os: 'android' });
        leave(b);
        b.fire('turbo:load');
        b.tick(500);
        tap(b, 'logout', { turboFollows: false });
        b.instance.stampPageHide();
        answers.signOutLink = {
            lastWrite: b.localStore.values[LAST_WRITE_KEY] === String(b.now()),
            ledger: b.instance.ledger.size(),
            bfcache: b.instance.bfcacheVerdictFor({ persisted: true }),
        };
    }

    return answers;
});

// ---- F11: a page too large to copy within a frame leaves through the full-load path ------------------------------------

await run('a page too large to copy', () => {
    const answers = {};
    const grow = (b, elements, pictures = 0) => {
        const main = b.document.querySelector('main');
        main.insertAdjacentHTML('beforeend', '<div></div>'.repeat(elements) + '<img alt="">'.repeat(pictures));
    };

    {
        const b = browser({ os: 'other' });
        grow(b, CLONE_BUDGET_UNITS);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/ladder`, action: 'advance' });
        answers.overByElements = cacheControl(b);
    }

    {
        const b = browser({ os: 'other' });
        grow(b, 1000, Math.ceil((CLONE_BUDGET_UNITS - 1000) / PICTURE_UNITS) + 1);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/ladder`, action: 'advance' });
        answers.overByPictures = cacheControl(b);
    }

    {
        const b = browser({ os: 'other' });
        grow(b, 1000, 20);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/ladder`, action: 'advance' });
        b.fire('turbo:before-cache');
        answers.withinTheBudget = { cacheControl: cacheControl(b), recorded: b.instance.ledger.size() };
    }

    {
        // Left by Back too (Turbo copies the page a restore leaves, for Forward)
        const b = browser({ os: 'other' });
        leave(b);
        b.fire('turbo:load');
        grow(b, CLONE_BUDGET_UNITS);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.leftByBack = cacheControl(b);
    }

    {
        // A frame visit promoted to the address bar renders nothing of the page: the page stays as it is
        const b = browser({ os: 'other' });
        grow(b, CLONE_BUDGET_UNITS * 2);
        b.currentVisit.willRender = false;
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub?page=2`, action: 'advance' });
        answers.frameVisit = cacheControl(b);
    }

    return answers;
});

// ---- F3: a sign-out (or in) elsewhere - Back never shows the other viewer's copy ----------------------------------------

await run('the viewer changed elsewhere', () => {
    const answers = {};

    {
        // Tab A cached its pages signed in; tab B signed out (its next page stored the guest); Back in tab A
        const b = browser({ os: 'other', viewer: 'v1' });
        leave(b);
        b.fire('turbo:load');
        b.tick(1000);
        b.localStore.values[VIEWER_KEY] = '';
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.signedOutInAnotherTab = { back: b.instance.lastRestoreKind(), assigned: b.assigned.length };
    }

    {
        // The same, but nobody changed: the copy
        const b = browser({ os: 'other', viewer: 'v1' });
        leave(b);
        b.fire('turbo:load');
        b.tick(1000);
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        answers.sameViewer = { back: b.instance.lastRestoreKind() };
    }

    {
        // Another tab wrote or changed the viewer: this tab drops its copies at once (the storage event); anything else
        // stored there changes nothing
        const b = browser({ os: 'other' });
        leave(b);
        b.fire('turbo:load');
        const storage = (key) => {
            const event = new b.window.Event('storage');
            Object.defineProperty(event, 'key', { value: key });
            b.window.dispatchEvent(event);
        };
        storage('something-else');
        const afterOtherKey = { ledger: b.instance.ledger.size(), clears: b.cacheClears.length };
        storage(LAST_WRITE_KEY);
        const afterWrite = { ledger: b.instance.ledger.size(), clears: b.cacheClears.length };
        leave(b);
        b.fire('turbo:load');
        storage(VIEWER_KEY);
        answers.storageEvents = { afterOtherKey, afterWrite, afterViewer: { ledger: b.instance.ledger.size(), clears: b.cacheClears.length } };
    }

    return answers;
});

await run('a late scroll event never moves a restore', () => {
    const answers = {};
    const y = (b, id) => (b.turbo.session.history.restorationData[id] || {}).scrollPosition;

    {
        // Left at 1200; Back right after the next page rendered: the popstate switched Turbo's identifier, then that
        // page's scroll to the top was reported - into the entry being restored. Turbo scrolls at turbo:render's end
        const b = browser({ os: 'other' });
        leave(b);
        b.fire('turbo:load');
        const history = b.turbo.session.history;
        history.restorationData['r-hub'] = { scrollPosition: { x: 0, y: 1200 } };
        b.currentVisit.action = 'restore';
        b.currentVisit.restorationIdentifier = 'r-hub';
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        history.restorationData['r-hub'] = { scrollPosition: { x: 0, y: 0 }, other: 'kept' };
        b.fire('turbo:before-render', { newBody: b.document.createElement('body') }, true);
        b.fire('turbo:render');
        answers.snapshot = { kind: b.instance.lastRestoreKind(), position: y(b, 'r-hub'), other: history.restorationData['r-hub'].other };
        // Only once: a later scroll of the page is the reader's
        history.restorationData['r-hub'] = { scrollPosition: { x: 0, y: 300 } };
        b.fire('turbo:render');
        answers.afterwards = y(b, 'r-hub');
    }

    {
        // An entry Turbo had no position for: nothing invented
        const b = browser({ os: 'other' });
        leave(b);
        b.fire('turbo:load');
        b.currentVisit.restorationIdentifier = 'r-none';
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        b.fire('turbo:render');
        answers.noPosition = b.turbo.session.history.restorationData['r-none'] || null;
    }

    {
        // A restore that reloads (too old) and a visit that is no restore: untouched
        const b = browser({ os: 'other' });
        leave(b);
        b.fire('turbo:load');
        b.tick(11 * 60_000);
        b.turbo.session.history.restorationData['r-hub'] = { scrollPosition: { x: 0, y: 1200 } };
        b.currentVisit.restorationIdentifier = 'r-hub';
        b.fire('turbo:visit', { url: `${ORIGIN}/en/hub`, action: 'restore' });
        b.turbo.session.history.restorationData['r-hub'] = { scrollPosition: { x: 0, y: 0 } };
        b.fire('turbo:render');
        answers.reload = { kind: b.instance.lastRestoreKind(), position: y(b, 'r-hub') };
        b.fire('turbo:visit', { url: `${ORIGIN}/en/faq`, action: 'advance' });
        b.turbo.session.history.restorationData['r-hub'] = { scrollPosition: { x: 0, y: 5 } };
        b.fire('turbo:render');
        answers.advance = y(b, 'r-hub');
    }

    return answers;
});

await run('fonts asked while they load only', async () => {
    // GEST-15 (e): reading document.fonts.ready after the fonts settled lays the page out on the spot (Blink) - the
    // controllers measuring in connect() ask for it until it resolved once in the document, never on later renders
    forgetFontsSettled();
    let reads = 0;
    const ready = Promise.resolve();
    const doc = { fonts: { get ready() { reads += 1; return ready; } } };
    const calls = [];
    const first = whenFontsReady(() => calls.push('first'), doc);
    const second = whenFontsReady(() => calls.push('second'), doc);
    await ready;
    await Promise.resolve();
    const readsBeforeSettled = reads;
    const later = whenFontsReady(() => calls.push('later'), doc);
    await Promise.resolve();
    const answer = { first, second, later, calls, readsBeforeSettled, readsAfterSettled: reads - readsBeforeSettled,
        noFonts: whenFontsReady(() => calls.push('none'), {}) };
    forgetFontsSettled();

    return answer;
});

process.stdout.write(JSON.stringify(results));
