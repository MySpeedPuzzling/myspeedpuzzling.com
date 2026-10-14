// Runs assets/view_transition_gate.js (docs/features/navigation README §4.13 G12, G15; GEST-12, GEST-17) through the
// cases tests/ViewTransitionGateScriptTest.php asks for and prints one JSON object {case: answer}: the pure decisions
// (os × native × reduced motion × restore × morph × place), and install() against a fake document - Turbo's events in
// the order Turbo 8.0.23 dispatches them, the head meta and <html data-vt> read back after each.

import {
    gateFor,
    HERO_NAME,
    HERO_SELECTOR,
    install,
    META_NAME,
    MORPH,
    MORPH_MAX_WAIT_MS,
    MORPH_SOURCE_ATTRIBUTE,
    MORPH_THUMBNAIL_SELECTOR,
    morphCandidate,
    morphInTime,
    NONE,
    pathOf,
    PLACE,
    PLACE_LINK_SELECTOR,
    SAME_ORIGIN,
    VT_ATTRIBUTE,
} from '../assets/view_transition_gate.js';

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

// ---- a fake DOM: just what the gate touches --------------------------------------------------------------------------

function style() {
    const properties = new Map();

    return {
        setProperty: (name, value) => properties.set(name, value),
        removeProperty: (name) => properties.delete(name),
        getPropertyValue: (name) => properties.get(name) ?? '',
        has: (name) => properties.has(name),
    };
}

function element({ attributes = {}, matches = [], closest = {}, children = {}, complete, naturalWidth } = {}) {
    const attrs = { ...attributes };
    const el = {
        style: style(),
        complete,
        naturalWidth,
        getAttribute: (name) => (name in attrs ? attrs[name] : null),
        setAttribute: (name, value) => {
            attrs[name] = String(value);
        },
        removeAttribute: (name) => {
            delete attrs[name];
        },
        hasAttribute: (name) => name in attrs,
        matches: (selector) => matches.includes(selector),
        closest: (selector) => closest[selector] ?? null,
        querySelector: (selector) => children[selector] ?? null,
    };

    return el;
}

/**
 * A page: <html> with the device's OS (and the app flag), the head meta as the server renders it, a place tab, a content
 * link, a catalogue card with its thumbnail (decoded or not) and its two links (the puzzle page, the brand)
 */
function page({ device = 'android', nativeApp = false, reducedMotion = false, decoded = true } = {}) {
    const html = element({ attributes: { 'data-os': device, ...(nativeApp ? { 'data-native-app': 'android' } : {}) } });
    html.classList = { contains: () => false };
    const meta = element({ attributes: { name: META_NAME, content: SAME_ORIGIN } });
    const thumbnail = element({ complete: decoded, naturalWidth: decoded ? 180 : 0 });
    const card = element({ attributes: { [MORPH_SOURCE_ATTRIBUTE]: '/en/puzzle/p1' }, children: { [MORPH_THUMBNAIL_SELECTOR]: thumbnail } });
    const cardLink = element({ closest: { [`[${MORPH_SOURCE_ATTRIBUTE}]`]: card } });
    const brandLink = element({ closest: { [`[${MORPH_SOURCE_ATTRIBUTE}]`]: card } });
    const tab = element({ matches: [PLACE_LINK_SELECTOR] });
    const link = element();
    const listeners = new Map();
    const windowListeners = new Map();
    let clock = 1000;

    const named = () => [thumbnail, hero].filter((el) => el.style.has('view-transition-name'));
    const hero = element();
    const newBody = element({ children: { [HERO_SELECTOR]: hero } });

    const doc = {
        documentElement: html,
        head: { querySelector: (selector) => (selector === `meta[name="${META_NAME}"]` ? meta : null) },
        querySelectorAll: (selector) => (selector === '[style*="view-transition-name"]' ? named() : []),
        addEventListener: (type, fn) => listeners.set(type, [...(listeners.get(type) ?? []), fn]),
    };
    const win = {
        location: { href: 'https://msp.test/en/puzzle' },
        matchMedia: (query) => ({ matches: query === '(prefers-reduced-motion: reduce)' ? reducedMotion : false }),
        addEventListener: (type, fn) => windowListeners.set(type, [...(windowListeners.get(type) ?? []), fn]),
        performance: { now: () => clock },
    };

    const fire = (type, { target = html, detail = {}, defaultPrevented = false, onWindow = false, ...rest } = {}) => {
        const event = { type, target, detail, defaultPrevented, ...rest };
        ((onWindow ? windowListeners : listeners).get(type) ?? []).forEach((fn) => fn(event));
    };

    install({ window: win, document: doc, now: () => clock });

    return {
        html, meta, tab, link, card, cardLink, brandLink, thumbnail, hero, newBody, fire,
        advance: (ms) => {
            clock += ms;
        },
        state: () => ({
            meta: meta.getAttribute('content'),
            vt: html.getAttribute(VT_ATTRIBUTE),
            thumbnail: thumbnail.style.getPropertyValue('view-transition-name') || null,
            hero: hero.style.getPropertyValue('view-transition-name') || null,
        }),
    };
}

// The visit Turbo starts after a tap: turbo:click (on the link) → turbo:visit → the fetch → its answer → render → load
function visit(p, { link, url, action = 'advance', answerAfter = 120, load = true }) {
    const states = {};
    p.fire('turbo:click', { target: link, detail: { url } });
    states.click = p.state();
    p.fire('turbo:visit', { detail: { url, action } });
    p.advance(answerAfter);
    p.fire('turbo:before-fetch-response', { target: p.html });
    states.response = p.state();
    p.fire('turbo:before-render', { detail: { newBody: p.newBody } });
    states.render = p.state();

    if (load) {
        p.fire('turbo:load');
        states.load = p.state();
    }

    return states;
}

const DEVICES = ['android', 'ios', 'other'];

const results = Object.fromEntries([
    run('constants', () => ({
        META_NAME,
        SAME_ORIGIN,
        NONE,
        VT_ATTRIBUTE,
        PLACE,
        MORPH,
        HERO_NAME,
        HERO_SELECTOR,
        MORPH_SOURCE_ATTRIBUTE,
        MORPH_MAX_WAIT_MS,
        PLACE_LINK_SELECTOR,
    })),

    // The matrix: device × native × reduced motion × restore, for a plain tap, a place tap and a morph tap
    run('gateFor', () => {
        const rows = {};

        for (const device of DEVICES) {
            for (const nativeApp of [false, true]) {
                for (const reducedMotion of [false, true]) {
                    for (const restore of [false, true]) {
                        const key = `${device}${nativeApp ? ' app' : ''}${reducedMotion ? ' reduced' : ''}${restore ? ' restore' : ''}`;
                        const facts = { os: device, nativeApp, reducedMotion, restore };
                        rows[key] = {
                            idle: gateFor(facts),
                            place: gateFor({ ...facts, place: true }),
                            morph: gateFor({ ...facts, morph: true }),
                            placeAndMorph: gateFor({ ...facts, place: true, morph: true }),
                        };
                    }
                }
            }
        }

        rows.empty = gateFor();
        rows.junk = gateFor({ os: 'windows', place: true, morph: true });

        return rows;
    }),

    run('morphCandidate', () => ({
        samePath: morphCandidate({ linkPath: '/en/puzzle/p1', sourcePath: '/en/puzzle/p1', imageReady: true }),
        notDecoded: morphCandidate({ linkPath: '/en/puzzle/p1', sourcePath: '/en/puzzle/p1', imageReady: false }),
        otherPath: morphCandidate({ linkPath: '/en/brand/ravensburger', sourcePath: '/en/puzzle/p1', imageReady: true }),
        noSource: morphCandidate({ linkPath: '/en/puzzle/p1', sourcePath: '', imageReady: true }),
        nothing: morphCandidate(),
    })),

    run('morphInTime', () => ({
        at0: morphInTime(1000, 1000),
        at400: morphInTime(1000, 1400),
        at401: morphInTime(1000, 1401),
        at600: morphInTime(1000, 1600),
        noTap: morphInTime(null, 1000),
    })),

    run('pathOf', () => ({
        absolute: pathOf('https://msp.test/en/puzzle/p1?x=1#top', 'https://msp.test/en/hub'),
        relative: pathOf('/de/puzzle/p2', 'https://msp.test/en/hub'),
        junk: pathOf('http://[', 'nope'),
    })),

    // ---- install() -------------------------------------------------------------------------------------------------

    // Start: the server's same-origin stays on Android, becomes none on iOS / desktop / in the app / reduced motion
    run('install: the meta at start', () => Object.fromEntries([
        ['android', page({ device: 'android' }).state().meta],
        ['ios', page({ device: 'ios' }).state().meta],
        ['other', page({ device: 'other' }).state().meta],
        ['android app', page({ device: 'android', nativeApp: true }).state().meta],
        ['android reduced', page({ device: 'android', reducedMotion: true }).state().meta],
    ])),

    // Android: a tab → place (the fade); a content link → a view transition without animation (no data-vt); after the
    // load everything is back to the default
    run('install: android place tap', () => {
        const p = page({ device: 'android' });

        return visit(p, { link: p.tab, url: 'https://msp.test/en/community' });
    }),
    run('install: android content link', () => {
        const p = page({ device: 'android' });

        return visit(p, { link: p.link, url: 'https://msp.test/en/faq' });
    }),

    // iOS and desktop: a tab switches instantly (none)
    run('install: ios place tap', () => {
        const p = page({ device: 'ios' });

        return visit(p, { link: p.tab, url: 'https://msp.test/en/community' });
    }),
    run('install: desktop place tap', () => {
        const p = page({ device: 'other' });

        return visit(p, { link: p.tab, url: 'https://msp.test/en/community' });
    }),

    // The morph on both phones: the thumbnail named at the tap, the new page's box image right before it renders,
    // names gone after the load
    run('install: android morph', () => {
        const p = page({ device: 'android' });

        return visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1' });
    }),
    run('install: ios morph', () => {
        const p = page({ device: 'ios' });

        return visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1' });
    }),

    // No morph: desktop, the thumbnail still loading, the card's brand link, reduced motion, the app
    run('install: desktop card', () => {
        const p = page({ device: 'other' });

        return visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1' });
    }),
    run('install: ios card, thumbnail not decoded', () => {
        const p = page({ device: 'ios', decoded: false });

        return visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1' });
    }),
    run('install: ios card, brand link', () => {
        const p = page({ device: 'ios' });

        return visit(p, { link: p.brandLink, url: 'https://msp.test/en/brand/ravensburger' });
    }),
    run('install: android card, reduced motion', () => {
        const p = page({ device: 'android', reducedMotion: true });

        return visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1' });
    }),
    run('install: android app card + tab', () => {
        const p = page({ device: 'android', nativeApp: true });

        return {
            card: visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1' }),
            tab: visit(p, { link: p.tab, url: 'https://msp.test/en/community' }),
        };
    }),

    // Decision 68: the page answers 600 ms after the tap - the morph is dropped at the answer (iOS: no transition at
    // all; Android: an instant one), the new page's image keeps no name; 400 ms is still in time
    run('install: ios morph, slow answer', () => {
        const p = page({ device: 'ios' });

        return visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1', answerAfter: 600 });
    }),
    run('install: android morph, slow answer', () => {
        const p = page({ device: 'android' });

        return visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1', answerAfter: 600 });
    }),
    run('install: ios morph, answer at 400 ms', () => {
        const p = page({ device: 'ios' });

        return visit(p, { link: p.cardLink, url: 'https://msp.test/en/puzzle/p1', answerAfter: 400 });
    }),
    // A frame's fetch answering late changes nothing of the visit's morph
    run('install: ios morph, a frame answers late', () => {
        const p = page({ device: 'ios' });
        p.fire('turbo:click', { target: p.cardLink, detail: { url: 'https://msp.test/en/puzzle/p1' } });
        p.advance(600);
        p.fire('turbo:before-fetch-response', { target: p.link });

        return p.state();
    }),

    // Back: a restore visit and a popstate the browser animated set none, data-vt and the names go; the next load
    // puts the default back
    run('install: restore after a tap', () => {
        const p = page({ device: 'android' });
        p.fire('turbo:click', { target: p.tab, detail: { url: 'https://msp.test/en/community' } });
        p.fire('turbo:visit', { detail: { url: 'https://msp.test/en/hub', action: 'restore' } });
        const restored = p.state();
        p.fire('turbo:load');

        return { restored, load: p.state() };
    }),
    run('install: popstate', () => {
        const p = page({ device: 'android' });
        p.fire('popstate', { onWindow: true, hasUAVisualTransition: false });
        const plain = p.state();
        p.fire('turbo:click', { target: p.cardLink, detail: { url: 'https://msp.test/en/puzzle/p1' } });
        p.fire('popstate', { onWindow: true, hasUAVisualTransition: true });

        return { plain, animatedByTheBrowser: p.state() };
    }),

    // A tap that never became its visit (a leave guard stopped it): the next visit elsewhere, a form submission, a
    // prevented click carry nothing of it
    run('install: a visit elsewhere after a tap', () => {
        const p = page({ device: 'ios' });
        p.fire('turbo:click', { target: p.cardLink, detail: { url: 'https://msp.test/en/puzzle/p1' } });
        p.fire('turbo:visit', { detail: { url: 'https://msp.test/en/hub', action: 'advance' } });

        return p.state();
    }),
    run('install: the same visit with a hash', () => {
        const p = page({ device: 'android' });
        p.fire('turbo:click', { target: p.tab, detail: { url: 'https://msp.test/en/community#top' } });
        p.fire('turbo:visit', { detail: { url: 'https://msp.test/en/community', action: 'advance' } });

        return p.state();
    }),
    run('install: a form after a tap', () => {
        const p = page({ device: 'android' });
        p.fire('turbo:click', { target: p.tab, detail: { url: 'https://msp.test/en/community' } });
        p.fire('turbo:submit-start');

        return p.state();
    }),
    run('install: a prevented click', () => {
        const p = page({ device: 'ios' });
        p.fire('turbo:click', { target: p.cardLink, detail: { url: 'https://msp.test/en/puzzle/p1' }, defaultPrevented: true });

        return p.state();
    }),
    // A second tap after a cancelled morph: one name only (two would abort the transition)
    run('install: a second tap after a cancelled morph', () => {
        const p = page({ device: 'ios' });
        p.fire('turbo:click', { target: p.cardLink, detail: { url: 'https://msp.test/en/puzzle/p1' } });
        p.fire('turbo:click', { target: p.tab, detail: { url: 'https://msp.test/en/community' } });

        return p.state();
    }),
]);

process.stdout.write(JSON.stringify(results));
