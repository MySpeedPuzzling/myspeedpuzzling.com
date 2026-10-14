// Runs the player card's trigger rules (assets/player_card_rules.js), the trigger listener (assets/player_card.js), the
// layer's pure helpers (assets/player_card_layer.js) and its measurement (assets/player_card_measure.js) through the
// scenarios tests/PlayerCardScriptTest.php asks for (docs/features/navigation CARD-01a, CARD-07) and prints one JSON
// object of results. Links, dialogs and the document are plain fakes: attributes in a map, closest() answering the
// selectors the rules ask for. The layer itself (requests per open, the focus return, a failed card fetch, a modal over
// the card - review CARD+TEASE F1, F2, F4, F5) runs on jsdom with the real trigger listener, a fake layer history and a
// Turbo frame played by the harness (its src set = one request, answered or failed on demand).

import { createRequire } from 'node:module';
import { bypassOf, cardUrl, contextKindOf, contextOf, inputOf, isReverted, playerIdOf, PLAYER_ID_PLACEHOLDER, shouldOpen, TRIGGER_SELECTOR } from '../assets/player_card_rules.js';
import { installPlayerCard } from '../assets/player_card.js';
import { closedRecently, createPlayerCardLayer, freshEntry, PREFETCH_TTL_MS, withReturnPage } from '../assets/player_card_layer.js';
import { BOUNCE_MS, createCardMeasure, outcomeOf } from '../assets/player_card_measure.js';
import { CARD_BYPASS_EVENT, CARD_OPEN_EVENT, CARD_OUTCOME_EVENT } from '../assets/nav_instrumentation.js';

const ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
const OTHER = '0190ffff-0000-7000-8000-000000000001';

/** A fake element: attributes, a parent chain and the selectors the rules ask closest()/matches() about */
function element(attributes = {}, { parent = null, tag = 'a', classes = [] } = {}) {
    const el = {
        tagName: tag.toUpperCase(),
        // A real link's href property is the absolute URL - what the fallback navigates to
        href: attributes.href ? 'http://site' + attributes.href : undefined,
        attributes: { ...attributes },
        parent,
        classes,
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
        },
        matches(selector) {
            return selector.split(',').map((part) => part.trim()).some((part) => {
                switch (part) {
                    case '.modal':
                        return this.classes.includes('modal');
                    case 'dialog[open]':
                        return this.tagName === 'DIALOG' && this.getAttribute('open') !== null;
                    case '.player-card-layer':
                        return this.classes.includes('player-card-layer');
                    case '#menu-layer':
                        return this.getAttribute('id') === 'menu-layer';
                    case TRIGGER_SELECTOR:
                        return this.tagName === 'A' && this.getAttribute('data-player-card') !== null;
                    case '[data-player-card-context]':
                        return this.getAttribute('data-player-card-context') !== null;
                    default:
                        return false;
                }
            });
        },
        closest(selector) {
            for (let node = this; node; node = node.parent) {
                if (node.matches(selector)) {
                    return node;
                }
            }

            return null;
        },
    };

    return el;
}

function trigger(extra = {}, options = {}) {
    return element({ href: '/en/player-profile/' + ID, 'data-player-card': '', 'aria-haspopup': 'dialog', ...extra }, options);
}

const click = (overrides = {}) => ({ button: 0, detail: 1, pointerType: 'mouse', defaultPrevented: false, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, ...overrides });

const results = {};

// shouldOpen(): only a plain primary click nobody handled, outside modals and foreign dialogs, on the web
const modal = element({}, { tag: 'div', classes: ['modal'] });
const foreignDialog = element({ open: '' }, { tag: 'dialog' });
const menuLayer = element({ open: '', id: 'menu-layer' }, { tag: 'dialog' });
const cardLayer = element({ open: '' }, { tag: 'dialog', classes: ['player-card-layer'] });
const contextRow = element({ 'data-player-card-context': 'puzzle:' + OTHER }, { tag: 'tr' });

results.shouldOpen = {
    plain: shouldOpen(click(), trigger()),
    keyboard: shouldOpen(click({ detail: 0, pointerType: '' }), trigger()),
    ctrl: shouldOpen(click({ ctrlKey: true }), trigger()),
    meta: shouldOpen(click({ metaKey: true }), trigger()),
    shift: shouldOpen(click({ shiftKey: true }), trigger()),
    alt: shouldOpen(click({ altKey: true }), trigger()),
    middle: shouldOpen(click({ button: 1 }), trigger()),
    defaultPrevented: shouldOpen(click({ defaultPrevented: true }), trigger()),
    insideModal: shouldOpen(click(), trigger({}, { parent: modal })),
    insideForeignDialog: shouldOpen(click(), trigger({}, { parent: foreignDialog })),
    insideMenu: shouldOpen(click(), trigger({}, { parent: menuLayer })),
    insideCard: shouldOpen(click(), trigger({}, { parent: cardLayer })),
    native: shouldOpen(click(), trigger(), { nativeApp: true }),
    targetBlank: shouldOpen(click(), trigger({ target: '_blank' })),
    targetSelf: shouldOpen(click(), trigger({ target: '_self' })),
    download: shouldOpen(click(), trigger({ download: '' })),
    noPlayer: shouldOpen(click(), trigger({ href: '/en/faq' })),
    // The revert rule (CARD-07): a context and input sent straight to the profile
    reverted: shouldOpen(click({ pointerType: 'touch' }), trigger({}, { parent: contextRow }), { direct: 'feed:touch puzzle:touch' }),
    revertedOtherInput: shouldOpen(click({ pointerType: 'mouse' }), trigger({}, { parent: contextRow }), { direct: 'puzzle:touch' }),
    revertedNoContext: shouldOpen(click({ pointerType: 'touch' }), trigger(), { direct: new Set(['none:touch']) }),
};

// playerIdOf(): the data-player-card value when it is an id, else the last id of the href's path (never the query)
results.playerIdOf = {
    profile: playerIdOf(trigger()),
    ownValue: playerIdOf(trigger({ 'data-player-card': OTHER })),
    junkValue: playerIdOf(trigger({ 'data-player-card': 'yes' })),
    returnParam: playerIdOf(trigger({ href: '/en/player-profile/' + ID + '?return=/en/player-profile/' + OTHER })),
    teamParam: playerIdOf(trigger({ href: '/en/player-results/' + ID + '?team=' + OTHER + '&category=duo' })),
    absolute: playerIdOf(trigger({ href: 'https://myspeedpuzzling.com/ja/%E3%83%97%E3%83%AD%E3%83%95%E3%82%A3%E3%83%BC%E3%83%AB/' + ID.toUpperCase() + '#x' })),
    none: playerIdOf(trigger({ href: '/en/faq' })),
    noHref: playerIdOf(element({ 'data-player-card': '' })),
};

// inputOf(): a click with no pointer behind it is the keyboard; else the pointer type, else the last pointerdown's
results.inputOf = {
    keyboard: inputOf({ detail: 0, pointerType: '' }),
    mouse: inputOf({ detail: 1, pointerType: 'mouse' }),
    touch: inputOf({ detail: 1, pointerType: 'touch' }),
    pen: inputOf({ detail: 1, pointerType: 'pen' }),
    safariTouch: inputOf({ detail: 1 }, 'touch'),
    unknown: inputOf({ detail: 1, pointerType: '' }),
};

results.context = {
    row: contextOf(trigger({}, { parent: contextRow })),
    none: contextOf(trigger()),
    kindPuzzle: contextKindOf('puzzle:' + OTHER),
    kindBoard: contextKindOf('leaderboard:times-500:cz'),
    kindNone: contextKindOf(null),
    kindJunk: contextKindOf('<b>:x'),
};

const template = '/en/puzzler-card/' + PLAYER_ID_PLACEHOLDER;
results.cardUrl = {
    plain: cardUrl(template, ID),
    context: cardUrl(template, ID, 'leaderboard:times-500:cz'),
    noTemplate: cardUrl(null, ID),
    noPlayer: cardUrl(template, null),
};

results.reverted = {
    string: isReverted('puzzle:touch feed:mouse', 'feed', 'mouse'),
    set: isReverted(new Set(['round:keyboard']), 'round', 'keyboard'),
    missing: isReverted('puzzle:touch', 'puzzle', 'mouse'),
    nothing: isReverted(null, 'puzzle', 'touch'),
};

// The layer's pure helpers: the prefetch cache's freshness, a guest's sign-in link pointed at the opener page
const now = 1_000_000;
results.layer = {
    fresh: freshEntry({ html: '<p>', at: now - PREFETCH_TTL_MS + 1 }, now),
    stale: freshEntry({ html: '<p>', at: now - PREFETCH_TTL_MS }, now),
    future: freshEntry({ html: '<p>', at: now + 10 }, now),
    empty: freshEntry(null, now),
    returnLogin: withReturnPage('/login?return=%2Fen%2Fplayer-profile%2F' + ID, '/en/puzzle/' + OTHER + '?tab=pairs', 'http://site/en/puzzle/' + OTHER),
    returnFavorite: withReturnPage('/en/add-player-to-favorites/' + ID + '?return=%2Fen%2Fplayer-profile%2F' + ID, '/en/hub', 'http://site/en/hub'),
    notAPath: withReturnPage('/login?return=%2Fen', '//evil.example', 'http://site/en/hub'),
    // The card just closed is not prefetched again by the focus returning to its name - for the cache's 20 s
    closedNow: closedRecently({ url: '/a', at: now - 1 }, '/a', now),
    closedLong: closedRecently({ url: '/a', at: now - PREFETCH_TTL_MS }, '/a', now),
    closedOther: closedRecently({ url: '/a', at: now - 1 }, '/b', now),
    closedNever: closedRecently(null, '/a', now),
};

// installPlayerCard(): the delegated click on the document, with a fake layer module
function fakeDocument(nativeApp = false, direct = null) {
    const listeners = {};
    const dispatched = [];

    return {
        listeners,
        dispatched,
        documentElement: { getAttribute: (name) => (name === 'data-native-app' && nativeApp ? 'android' : null) },
        body: {
            getAttribute: (name) => {
                if (name === 'data-player-card-url') {
                    return template;
                }

                return name === 'data-player-card-direct' ? direct : null;
            },
        },
        addEventListener(type, listener) {
            (listeners[type] = listeners[type] || []).push(listener);
        },
        dispatchEvent(event) {
            dispatched.push({ type: event.type, detail: event.detail });

            return true;
        },
    };
}

class FakeCustomEvent {
    constructor(type, init = {}) {
        this.type = type;
        this.detail = init.detail;
    }
}

function fakeWindow() {
    return {
        assigned: null,
        location: { assign(url) { this.parent.assigned = url; } },
        setTimeout: () => 1,
        clearTimeout: () => {},
        CustomEvent: FakeCustomEvent,
    };
}

async function scenario({ nativeApp = false, event = {}, target = trigger(), fail = false } = {}) {
    const document = fakeDocument(nativeApp);
    const window = fakeWindow();
    window.location.parent = window;
    const opened = [];
    let loads = 0;
    const load = () => {
        loads += 1;

        return fail ? Promise.reject(new Error('offline')) : Promise.resolve({ openCard: (link, url, options) => opened.push({ url, ...options }), prefetchCard: () => {} });
    };

    installPlayerCard({ window, document, load });
    const clickEvent = { ...click(event), target: { closest: (selector) => target.closest(selector) }, prevented: false };
    clickEvent.preventDefault = () => {
        clickEvent.prevented = true;
    };
    document.listeners.click.forEach((listener) => listener(clickEvent));
    await new Promise((resolve) => setTimeout(resolve, 0));

    return { prevented: clickEvent.prevented, loads, opened, assigned: window.assigned };
}

// CARD-07: a trigger opened in a new tab or window instead of the card - one msp:card-bypass on the document
async function bypassScenario({ type = 'click', event = {}, target = trigger(), nativeApp = false, direct = null } = {}) {
    const document = fakeDocument(nativeApp, direct);
    const window = fakeWindow();
    window.location.parent = window;

    installPlayerCard({ window, document, load: () => Promise.resolve({ openCard: () => {}, prefetchCard: () => {} }) });
    const fired = { ...click(event), target: { closest: (selector) => target.closest(selector) } };
    fired.preventDefault = () => {
        fired.defaultPrevented = true;
    };
    (document.listeners[type] || []).forEach((listener) => listener(fired));
    await new Promise((resolve) => setTimeout(resolve, 0));

    return document.dispatched.filter((event) => event.type === CARD_BYPASS_EVENT).map((event) => event.detail);
}

results.bypass = {
    eventName: CARD_BYPASS_EVENT,
    ctrlInARow: await bypassScenario({ event: { ctrlKey: true }, target: trigger({}, { parent: contextRow }) }),
    meta: await bypassScenario({ event: { metaKey: true } }),
    shiftTouch: await bypassScenario({ event: { shiftKey: true, pointerType: 'touch' } }),
    altKeyboard: await bypassScenario({ event: { altKey: true, detail: 0, pointerType: '' } }),
    middle: await bypassScenario({ type: 'auxclick', event: { button: 1 } }),
    // The middle button counts through auxclick only - a browser firing click for it too is not counted twice
    middleAsClick: await bypassScenario({ event: { button: 1 } }),
    right: await bypassScenario({ type: 'auxclick', event: { button: 2 } }),
    plain: await bypassScenario(),
    handledElsewhere: await bypassScenario({ event: { ctrlKey: true, defaultPrevented: true } }),
    native: await bypassScenario({ event: { ctrlKey: true }, nativeApp: true }),
    notATrigger: await bypassScenario({ event: { ctrlKey: true }, target: element({ href: '/en/player-profile/' + ID }) }),
    insideModal: await bypassScenario({ event: { ctrlKey: true }, target: trigger({}, { parent: modal }) }),
    // A reverted cell goes to the profile anyway: no card to bypass
    reverted: await bypassScenario({ event: { ctrlKey: true }, target: trigger({}, { parent: contextRow }), direct: 'puzzle:mouse' }),
};

results.bypassOf = {
    ctrl: bypassOf(click({ ctrlKey: true }), trigger()),
    middle: bypassOf(click({ button: 1 }), trigger()),
    plain: bypassOf(click(), trigger()),
    rightWithCtrl: bypassOf(click({ button: 2, ctrlKey: true }), trigger()),
    noPlayer: bypassOf(click({ ctrlKey: true }), trigger({ href: '/en/faq' })),
};

// CARD-07: the outcome of an open - a fake clock, the events as emitted
function measured(steps) {
    let now = 0;
    const emitted = [];
    const measure = createCardMeasure({ now: () => now, emit: (name, detail) => emitted.push({ event: name, ...detail }) });

    for (const step of steps) {
        if (step.wait) {
            now += step.wait;
        }

        if (step.open) {
            measure.opened(step.open[0], step.open[1]);
        }

        if (step.click !== undefined) {
            measure.clicked(step.click);
        }

        if (step.close) {
            measure.closed();
        }
    }

    return emitted;
}

results.measure = {
    events: [CARD_OPEN_EVENT, CARD_OUTCOME_EVENT],
    bounceMs: BOUNCE_MS,
    bounce: measured([{ open: ['puzzle', 'touch'] }, { wait: 500, close: true }]),
    closeAfterLooking: measured([{ open: ['puzzle', 'touch'] }, { wait: BOUNCE_MS, close: true }]),
    quickX: measured([{ open: ['none', 'mouse'] }, { wait: 300, click: 'close' }, { close: true }]),
    touchedThenClosed: measured([{ open: ['feed', 'touch'] }, { wait: 400, click: '' }, { close: true }]),
    profile: measured([{ open: ['leaderboard', 'keyboard'] }, { wait: 1200, click: 'profile' }, { wait: 100, close: true }]),
    firstWins: measured([{ open: ['round', 'pen'] }, { wait: 900, click: 'favorite' }, { wait: 900, click: 'results' }, { close: true }]),
    swap: measured([{ open: ['feed', 'mouse'] }, { wait: 800, open: ['notification', 'mouse'] }, { wait: 3000, click: 'overview' }]),
    nothingOpen: measured([{ click: 'profile' }, { close: true }]),
    closedTwice: measured([{ open: ['puzzle', 'mouse'] }, { wait: 5000, close: true }, { close: true }]),
};

results.outcomeOf = Object.fromEntries(
    ['profile', 'overview', 'view_profile', 'results', 'statistics', 'puzzle', 'favorite', 'compare', 'compare_open', 'message', 'share', 'tier_locked', 'edit_result', 'own_results', 'edit_profile', 'close', '', null]
        .map((item) => [String(item), outcomeOf(item)]),
);

results.listener = {
    plain: await scenario(),
    touch: await scenario({ event: { pointerType: 'touch' } }),
    withContext: await scenario({ target: trigger({}, { parent: contextRow }) }),
    ctrl: await scenario({ event: { ctrlKey: true } }),
    native: await scenario({ nativeApp: true }),
    notATrigger: await scenario({ target: element({ href: '/en/player-profile/' + ID }) }),
    layerFailed: await scenario({ fail: true }),
};


// ---- The layer on jsdom (review CARD+TEASE F1, F2, F4, F5) ----------------------------------------------------------

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const CARD_URL = '/en/puzzler-card/' + ID;
const PROFILE = 'https://msp.test/en/player-profile/' + ID;
const CARD_HTML = '<html><body><turbo-frame id="player-card"><article class="players-card"><h2 id="players-card-name">Anna</h2>'
    + '<button type="button" class="players-card-lock" data-bs-toggle="modal" data-bs-target="#membersExclusiveModal">Tier</button>'
    + '<form class="players-card-favorite" action="/en/favorite"><button>Favorite</button></form></article></turbo-frame></body></html>';

const flush = async () => {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

/**
 * One page with one name, the real trigger listener and the real layer; returns the levers the scenarios pull and what
 * the page asked the network for: `fetches` (prefetches, window.fetch) and `frameRequests` (the frame's src).
 */
function layerPage({ online = true } = {}) {
    const dom = new JSDOM('<!doctype html><html><body data-player-card-url="/en/puzzler-card/' + PLAYER_ID_PLACEHOLDER + '">'
        + '<main><a id="name" href="/en/player-profile/' + ID + '" data-player-card>Anna</a><button id="page-lock">Lock</button></main>'
        + '<div data-dynamic-modal-target="loading"><span class="visually-hidden">Loading...</span></div>'
        + '<div class="modal" id="membersExclusiveModal"></div><div class="modal" id="otherModal"></div></body></html>',
    { url: 'https://msp.test/en/hub', pretendToBeVisual: true, virtualConsole: new VirtualConsole() });
    const { window } = dom;
    const document = window.document;
    const env = { fetches: [], frameRequests: [], visits: [], hidden: [], answer: online ? 'ok' : 'offline' };

    // A phone (the sheet) with reduced motion: a close is over at once, no slide-out to wait for
    window.matchMedia = (query) => ({ matches: query.includes('max-width') || query.includes('reduce'), addEventListener() {}, removeEventListener() {} });
    window.HTMLDialogElement.prototype.show = function show() {
        this.setAttribute('open', '');
    };
    window.HTMLDialogElement.prototype.close = function close() {
        this.removeAttribute('open');
    };
    Object.defineProperty(window.HTMLDialogElement.prototype, 'open', { configurable: true, get() { return this.hasAttribute('open'); } });
    // jsdom lays nothing out: the name is on screen
    const anchor = document.getElementById('name');
    anchor.getClientRects = () => [{ width: 40, height: 20 }];

    window.fetch = (url) => {
        env.fetches.push(String(url));

        return env.answer === 'offline'
            ? Promise.reject(new TypeError('Failed to fetch'))
            : Promise.resolve({ ok: true, text: () => Promise.resolve(CARD_HTML) });
    };

    // Bootstrap's Modal: hide() drops .show and fires hidden.bs.modal (its own focus return to the opener comes first)
    window.bootstrap = {
        Modal: {
            getInstance: (element) => ({
                hide() {
                    env.hidden.push(element.id);
                    element.classList.remove('show');
                    element.dispatchEvent(new window.Event('hidden.bs.modal', { bubbles: true }));
                },
            }),
        },
    };

    // The layer history: one top, Back closes it ({reason: 'back'}), a visit from the layer is recorded
    const history = {
        top: null,
        open(id, options) {
            this.top = { id, ...options };
        },
        isOpen(id) {
            return this.top !== null && this.top.id === id;
        },
        requestClose() {
            this.back();
        },
        back() {
            const top = this.top;
            this.top = null;

            if (top) {
                top.onClose({ reason: 'back', id: top.id });
            }
        },
        closeForCache() {
            this.top = null;
        },
        visitFromLayer(url) {
            env.visits.push(String(url));
        },
    };

    const layer = createPlayerCardLayer({ window, document, layerHistory: history });
    installPlayerCard({
        window,
        document,
        load: () => Promise.resolve({ openCard: (link, url, options) => layer.open(link, url, options.input), prefetchCard: (url) => layer.prefetch(url) }),
    });

    // Turbo's frame: a src set is one request; answered with the card (before-fetch-response, frame-load) or failed
    const frameEvent = (frame, type, detail) => frame.dispatchEvent(new window.CustomEvent(type, { bubbles: true, cancelable: true, detail }));
    new window.MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            const frame = mutation.target;

            if (mutation.attributeName !== 'src' || !frame.getAttribute('src')) {
                return;
            }

            env.frameRequests.push(frame.getAttribute('src'));
            frameEvent(frame, 'turbo:before-fetch-request', {});

            if (env.answer === 'offline') {
                frameEvent(frame, 'turbo:fetch-request-error', { error: new TypeError('Failed to fetch') });

                return;
            }

            const answer = () => {
                frameEvent(frame, 'turbo:before-fetch-response', { fetchResponse: { succeeded: true, redirected: false, responseHTML: Promise.resolve(CARD_HTML) } });
                frame.innerHTML = new window.DOMParser().parseFromString(CARD_HTML, 'text/html').querySelector('turbo-frame').innerHTML;
                frameEvent(frame, 'turbo:frame-load', {});
            };

            if (env.answer === 'hold') {
                env.release = answer;

                return;
            }

            answer();
        });
    }).observe(document.body, { attributes: true, attributeFilter: ['src'], subtree: true });

    const page = {
        window,
        document,
        anchor,
        env,
        layer,
        history,
        // A tap: Safari and iOS focus no link on it - no prefetch before the open
        async tap() {
            anchor.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
            await flush();
        },
        // Chrome: the pointer rests / the link gets the focus first - one prefetch
        async focus() {
            anchor.focus();
            await flush();
        },
        async back() {
            history.back();
            await flush();
        },
        open: () => document.querySelector('dialog.player-card-layer[open]') !== null,
        busy: () => document.querySelector('.player-card-box[aria-busy]') !== null,
        name: () => {
            const dialog = document.querySelector('dialog.player-card-layer');
            const named = dialog ? document.getElementById(dialog.getAttribute('aria-labelledby')) : null;

            return named ? named.textContent.trim() : null;
        },
        counts: () => ({ fetches: env.fetches.length, frameRequests: env.frameRequests.length }),
        focusOnName: () => document.activeElement === anchor,
    };

    return page;
}

const realNow = Date.now;
let clock = 5_000_000;
Date.now = () => clock;

async function layerScenarios() {
    const out = {};

    // Safari / iOS: no prefetch before the tap - the open is the frame's own request; closing (Back, ×) puts the focus
    // back on the name and fetches nothing; a reopen within 20 s shows the kept answer, a later one asks again
    {
        const page = layerPage();
        page.env.answer = 'hold';
        await page.tap();
        const loading = { busy: page.busy(), name: page.name(), open: page.open() };
        page.env.answer = 'ok';
        page.env.release();
        await flush();
        const opened = { ...page.counts(), open: page.open(), name: page.name() };
        clock += 5000;
        await page.back();
        const closed = { ...page.counts(), open: page.open(), focusOnName: page.focusOnName() };
        clock += 5000;
        await page.tap();
        const reopened = { ...page.counts(), open: page.open(), busy: page.busy() };
        await page.back();
        clock += PREFETCH_TTL_MS + 1000;
        await page.tap();
        const later = { ...page.counts(), open: page.open() };
        out.safari = { loading, opened, closed, reopened, later };
    }

    // Chrome: the focus prefetches once, the open shows it; a card left open for 25 s closes without a second request
    {
        const page = layerPage();
        await page.focus();
        const prefetched = page.counts();
        await page.tap();
        const opened = { ...page.counts(), open: page.open(), busy: page.busy() };
        clock += 25000;
        await page.back();
        const closed = { ...page.counts(), open: page.open(), focusOnName: page.focusOnName() };
        // The focus of a deliberate reopen later on prefetches as always
        clock += PREFETCH_TTL_MS + 1000;
        page.anchor.blur();
        await page.focus();
        out.chrome = { prefetched, opened, closed, laterFocus: page.counts() };
    }

    // The card fetch fails on the network (offline after the layer loaded): the profile, as a replace visit from the layer
    {
        const page = layerPage({ online: false });
        await page.tap();
        out.offline = { ...page.counts(), visits: page.env.visits, busy: page.busy() };
    }

    // A failure after the card arrived (Favorite inside it, offline) is no reason to leave
    {
        const page = layerPage();
        await page.tap();
        page.layer.frame.querySelector('form').dispatchEvent(new page.window.CustomEvent('turbo:fetch-request-error', { bubbles: true, detail: {} }));
        await flush();
        out.failureInsideTheCard = { visits: page.env.visits, open: page.open() };
    }

    // Back with the members modal opened from the card's lock: both close, the focus ends on the name; a modal the page
    // opened (its opener outside the card) is not the card's to close
    {
        const page = layerPage();
        await page.tap();
        const { document, window } = page;
        const modal = document.getElementById('membersExclusiveModal');
        const other = document.getElementById('otherModal');
        const lock = document.querySelector('dialog.player-card-layer .players-card-lock');
        const show = (element, opener) => {
            const event = new window.Event('show.bs.modal', { bubbles: true, cancelable: true });
            Object.defineProperty(event, 'relatedTarget', { value: opener });
            element.dispatchEvent(event);
            element.classList.add('show');
            // Bootstrap's data API: focus back to the opener once hidden
            element.addEventListener('hidden.bs.modal', () => opener.focus(), { once: true });
        };
        show(modal, lock);
        show(other, document.getElementById('page-lock'));
        await page.back();
        out.modalOverTheCard = {
            hidden: page.env.hidden,
            membersModalShown: modal.classList.contains('show'),
            otherShown: other.classList.contains('show'),
            open: page.open(),
            focusOnName: page.focusOnName(),
            requests: page.counts(),
        };
    }

    return out;
}

results.layerRun = await layerScenarios();
Date.now = realNow;

process.stdout.write(JSON.stringify(results));
