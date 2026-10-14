// Runs the player card's trigger rules (assets/player_card_rules.js), the trigger listener (assets/player_card.js), the
// layer's pure helpers (assets/player_card_layer.js) and its measurement (assets/player_card_measure.js) through the
// scenarios tests/PlayerCardScriptTest.php asks for (docs/features/navigation CARD-01a, CARD-07) and prints one JSON
// object of results. Links, dialogs and the document are plain fakes: attributes in a map, closest() answering the
// selectors the rules ask for.

import { bypassOf, cardUrl, contextKindOf, contextOf, inputOf, isReverted, playerIdOf, PLAYER_ID_PLACEHOLDER, shouldOpen, TRIGGER_SELECTOR } from '../assets/player_card_rules.js';
import { installPlayerCard } from '../assets/player_card.js';
import { freshEntry, PREFETCH_TTL_MS, withReturnPage } from '../assets/player_card_layer.js';
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

process.stdout.write(JSON.stringify(results));
