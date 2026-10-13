// Runs the player card's trigger rules (assets/player_card_rules.js), the trigger listener (assets/player_card.js) and
// the layer's pure helpers (assets/player_card_layer.js) through the scenarios tests/PlayerCardScriptTest.php asks for
// (docs/features/navigation CARD-01a) and prints one JSON object of results. Links, dialogs and the document are
// plain fakes: attributes in a map, closest() answering the selectors the rules ask for.

import { cardUrl, contextKindOf, contextOf, inputOf, isReverted, playerIdOf, PLAYER_ID_PLACEHOLDER, shouldOpen, TRIGGER_SELECTOR } from '../assets/player_card_rules.js';
import { installPlayerCard } from '../assets/player_card.js';
import { freshEntry, PREFETCH_TTL_MS, withReturnPage } from '../assets/player_card_layer.js';

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
function fakeDocument(nativeApp = false) {
    const listeners = {};

    return {
        listeners,
        documentElement: { getAttribute: (name) => (name === 'data-native-app' && nativeApp ? 'android' : null) },
        body: { getAttribute: (name) => (name === 'data-player-card-url' ? template : null) },
        addEventListener(type, listener) {
            (listeners[type] = listeners[type] || []).push(listener);
        },
    };
}

function fakeWindow() {
    return {
        assigned: null,
        location: { assign(url) { this.parent.assigned = url; } },
        setTimeout: () => 1,
        clearTimeout: () => {},
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
