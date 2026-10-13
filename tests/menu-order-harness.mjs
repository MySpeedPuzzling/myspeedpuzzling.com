// The Menu's client-side rules (docs/features/navigation MENU-02b, MENU-01) for tests/MenuOrderScriptTest.php:
// - assets/navigation/menu_order.js: the phone panel puts the groups into phone order by moving the nodes and back into
//   dock order when it closes (decision 29), on made-up groups and on the real slot HTML the test hands over on stdin
//   (`pages`: the slot's #site-menu of /en/faq, compared with the `menu` page's server-rendered phone order);
// - assets/navigation/menu_counts.js: the lazy counts - fresh on every open of the panel (maxAge 0), at most once per
//   5 minutes for the docked column (TTL_MS), never another player's;
// - assets/navigation/menu_links.js: which clicks on a link to `menu` open the panel (phones) - or, on desktop (NAV-13),
//   the overlay, focus the docked column, dock / fold it (the ☰ from 1280 px).
// Prints {case: facts} as JSON.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { applyMenuOrder, groupsOf, orderOf, sortedGroups } from '../assets/navigation/menu_order.js';
import { applyCounts, cachedCounts, countText, loadMenuCounts, STORAGE_KEY, TTL_MS } from '../assets/navigation/menu_counts.js';
import { isMenuHref, listenForMenuLinks, menuLinkAction, menuOpenerOf, menuPaths, PHONE_QUERY } from '../assets/navigation/menu_links.js';
import { WIDE_QUERY } from '../assets/navigation/nav_layout.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const input = JSON.parse(readFileSync(0, 'utf8') || '{}');
const ORIGIN = 'https://msp.test';

function dom(body, url = ORIGIN + '/en/faq') {
    return new JSDOM(`<!doctype html><html lang="en"><body>${body}</body></html>`, { url, virtualConsole: new VirtualConsole() });
}

/** <nav id="site-menu"> with one section per [id, phone, dock] (null = no attribute) */
function nav(groups, extra = '') {
    const sections = groups.map(([id, phone, dock]) => {
        const attributes = [phone === null ? '' : ` data-order-phone="${phone}"`, dock === null ? '' : ` data-order-dock="${dock}"`].join('');

        return `<section class="menu-group" data-menu-group="${id}"${attributes}><ul><li id="menu-row-${id}"><a href="/en/${id}">${id}</a></li></ul></section>`;
    }).join('');

    const window = dom(`<div id="menu-slot"><nav id="site-menu">${extra}${sections}</nav></div>`).window;

    return { window, nav: window.document.getElementById('site-menu') };
}

const ids = (element) => groupsOf(element).map((group) => group.dataset.menuGroup);

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

async function runAsync(name, fn) {
    try {
        return [name, await fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

// ---- menu_order.js ------------------------------------------------------------------------------------------------

// DOM = docked-column order (home, puzzles, community, you, inbox, add, help, about, logout), phone order differs
const MENU = [
    ['home', 900, 10], ['puzzles', 60, 20], ['community', 50, 30], ['you', 10, 40], ['inbox', 20, 50],
    ['add', 70, 60], ['help', 80, 70], ['about', 90, 80], ['logout', 100, 90],
];

const cases = [];

cases.push(run('phone order', () => {
    const { nav: menu } = nav(MENU);
    const dockBefore = ids(menu);
    const moved = applyMenuOrder(menu, 'phone');

    return { dockBefore, phone: ids(menu), moved, marked: menu.getAttribute('data-menu-order') };
}));

cases.push(run('back to dock order', () => {
    const { nav: menu } = nav(MENU);
    applyMenuOrder(menu, 'phone');
    const moved = applyMenuOrder(menu, 'dock');

    return { dock: ids(menu), moved, marked: menu.getAttribute('data-menu-order') };
}));

cases.push(run('already in order moves nothing', () => {
    const { nav: menu } = nav(MENU);
    const dock = applyMenuOrder(menu, 'dock');
    applyMenuOrder(menu, 'phone');
    const phoneAgain = applyMenuOrder(menu, 'phone');

    return { dock, phoneAgain };
}));

cases.push(run('the same nodes move, nothing is copied', () => {
    const { window, nav: menu } = nav(MENU);
    const before = groupsOf(menu);
    applyMenuOrder(menu, 'phone');
    const after = groupsOf(menu);

    return {
        sameNodes: before.length === after.length && before.every((group) => after.includes(group)),
        rows: window.document.querySelectorAll('[id^="menu-row-"]').length,
        menus: window.document.querySelectorAll('#site-menu').length,
    };
}));

cases.push(run('ties keep the DOM order, broken values go last', () => {
    const { nav: menu } = nav([['a', 20, 1], ['b', null, 2], ['c', 10, 3], ['d', 'x', 4], ['e', 20, 5], ['f', 10, 6]]);
    applyMenuOrder(menu, 'phone');

    return {
        phone: ids(menu),
        orders: { missing: orderOf(groupsOf(menu).find((g) => g.dataset.menuGroup === 'b'), 'phone'), unknownSurface: orderOf(groupsOf(menu)[0], 'rail') },
        sortedIsPure: sortedGroups([], 'phone').length,
    };
}));

cases.push(run('other children of the nav stay', () => {
    const { nav: menu } = nav(MENU, '<script type="application/json" id="nav-search-private">[]</script>');
    applyMenuOrder(menu, 'phone');

    return { first: menu.firstElementChild.id, phone: ids(menu) };
}));

cases.push(run('unknown surface or no nav does nothing', () => {
    const { nav: menu } = nav(MENU);

    return { unknown: applyMenuOrder(menu, 'rail'), none: applyMenuOrder(null, 'phone'), dock: ids(menu), marked: menu.getAttribute('data-menu-order') };
}));

// The real slot: phone order applied in the browser = the `menu` page's server-rendered phone order (rows without the
// dock-only parts the panel hides by CSS)
for (const page of input.pages || []) {
    cases.push(run('page ' + page.name, () => {
        const window = dom(page.slot).window;
        const menu = window.document.getElementById('site-menu');
        const visibleRows = () => Array.from(menu.querySelectorAll('section.menu-group'))
            .filter((group) => !group.classList.contains('menu-only-dock'))
            .flatMap((group) => Array.from(group.querySelectorAll('li[id^="menu-row-"]')))
            .filter((row) => !row.classList.contains('menu-only-dock'))
            .map((row) => row.id);
        const dockRows = Array.from(menu.querySelectorAll('li[id^="menu-row-"]')).map((row) => row.id);

        applyMenuOrder(menu, 'phone');
        const phone = visibleRows();
        applyMenuOrder(menu, 'dock');

        return { phone, dockRestored: JSON.stringify(Array.from(menu.querySelectorAll('li[id^="menu-row-"]')).map((row) => row.id)) === JSON.stringify(dockRows) };
    }));
}

// ---- menu_counts.js -----------------------------------------------------------------------------------------------

function memoryStorage(initial = {}, { throws = false } = {}) {
    const items = { ...initial };

    return {
        items,
        getItem(key) {
            if (throws) throw new Error('SecurityError');
            return Object.prototype.hasOwnProperty.call(items, key) ? items[key] : null;
        },
        setItem(key, value) {
            if (throws) throw new Error('QuotaExceededError');
            items[key] = String(value);
        },
    };
}

function countsRoot() {
    const window = dom('<nav id="site-menu"><span data-menu-count="organised"></span><span data-menu-count="review"></span></nav>').window;

    return window.document.getElementById('site-menu');
}

const slots = (root) => Array.from(root.querySelectorAll('[data-menu-count]')).map((slot) => slot.textContent);

function fakeFetch(answers) {
    const calls = [];
    const fetchImpl = async (url, options) => {
        calls.push({ url, accept: options && options.headers ? options.headers.Accept : null, credentials: options ? options.credentials : null });
        const answer = answers.length > 1 ? answers.shift() : answers[0];

        return { ok: answer.ok !== false, json: async () => answer.data };
    };

    return { calls, fetchImpl };
}

cases.push(run('cachedCounts', () => {
    const data = { organised: 2, review: 1 };
    const at = 1_000_000;
    const stored = { at, player: 'p1', data };

    return {
        withinTtl: cachedCounts(stored, 'p1', at + TTL_MS, TTL_MS),
        pastTtl: cachedCounts(stored, 'p1', at + TTL_MS + 1, TTL_MS),
        otherPlayer: cachedCounts(stored, 'p2', at + 1, TTL_MS),
        maxAgeZero: cachedCounts(stored, 'p1', at, 0),
        fromTheFuture: cachedCounts(stored, 'p1', at - 1, TTL_MS),
        broken: [cachedCounts(null, 'p1', at, TTL_MS), cachedCounts({ player: 'p1', data }, 'p1', at, TTL_MS), cachedCounts({ at, player: 'p1', data: 5 }, 'p1', at, TTL_MS)],
        ttl: TTL_MS,
        key: STORAGE_KEY,
    };
}));

cases.push(run('countText', () => [0, 1, 5, 99, 100, 250, -3, 2.7, NaN, Infinity, '3', null, undefined].map((value) => countText(value))));

cases.push(run('applyCounts', () => {
    const root = countsRoot();
    applyCounts(root, { organised: 3, review: 0 });
    const filled = slots(root);
    applyCounts(root, null);

    return { filled, cleared: slots(root) };
}));

cases.push(await runAsync('the docked column asks at most once per 5 minutes', async () => {
    const storage = memoryStorage();
    const { calls, fetchImpl } = fakeFetch([{ data: { organised: 2, review: 1 } }, { data: { organised: 3, review: 0 } }]);
    let clock = 5_000_000;
    const load = () => loadMenuCounts(countsRoot(), { url: '/en/menu-counts.json', player: 'p1', maxAge: TTL_MS, storage, fetchImpl, now: () => clock });

    const first = await load();
    clock += 60_000;
    await load();
    clock += TTL_MS - 60_000;
    const atTheEdge = await load();
    const fetchesWithinTtl = calls.length;
    clock += 1;
    const after = await load();

    return {
        first,
        atTheEdge,
        fetchesWithinTtl,
        fetchesAfterTtl: calls.length,
        after,
        stored: JSON.parse(storage.items[STORAGE_KEY]),
        request: calls[0],
    };
}));

cases.push(await runAsync('the panel asks on every open', async () => {
    const storage = memoryStorage({ [STORAGE_KEY]: JSON.stringify({ at: 10, player: 'p1', data: { organised: 9, review: 9 } }) });
    const { calls, fetchImpl } = fakeFetch([{ data: { organised: 1, review: 4 } }]);
    const root = countsRoot();
    await loadMenuCounts(root, { url: '/en/menu-counts.json', player: 'p1', maxAge: 0, storage, fetchImpl, now: () => 11 });
    await loadMenuCounts(root, { url: '/en/menu-counts.json', player: 'p1', maxAge: 0, storage, fetchImpl, now: () => 12 });

    return { fetches: calls.length, slots: slots(root) };
}));

cases.push(await runAsync('another player\'s answer is never used', async () => {
    const storage = memoryStorage({ [STORAGE_KEY]: JSON.stringify({ at: 100, player: 'p1', data: { organised: 9, review: 9 } }) });
    const { calls, fetchImpl } = fakeFetch([{ data: { organised: null, review: 2 } }]);
    const root = countsRoot();
    const data = await loadMenuCounts(root, { url: '/en/menu-counts.json', player: 'p2', maxAge: TTL_MS, storage, fetchImpl, now: () => 200 });

    return { fetches: calls.length, data, slots: slots(root), storedPlayer: JSON.parse(storage.items[STORAGE_KEY]).player };
}));

cases.push(await runAsync('a failed answer leaves the slots empty and stores nothing', async () => {
    const storage = memoryStorage();
    const { fetchImpl } = fakeFetch([{ ok: false, data: { review: 5 } }]);
    const root = countsRoot();
    const data = await loadMenuCounts(root, { url: '/en/menu-counts.json', player: 'p1', maxAge: TTL_MS, storage, fetchImpl, now: () => 1 });

    return { data, slots: slots(root), stored: storage.items[STORAGE_KEY] ?? null };
}));

cases.push(await runAsync('no storage: the numbers still show', async () => {
    const storage = memoryStorage({}, { throws: true });
    const { calls, fetchImpl } = fakeFetch([{ data: { organised: 1, review: 2 } }]);
    const root = countsRoot();
    await loadMenuCounts(root, { url: '/en/menu-counts.json', player: 'p1', maxAge: TTL_MS, storage, fetchImpl, now: () => 1 });
    await loadMenuCounts(root, { url: '/en/menu-counts.json', player: 'p1', maxAge: TTL_MS, storage, fetchImpl, now: () => 2 });

    return { fetches: calls.length, slots: slots(root) };
}));

// ---- menu_links.js ------------------------------------------------------------------------------------------------

const PATHS = ['/menu', '/en/menu', '/de/menue', '/es/menu', '/fr/menu', '/ja/%E3%83%A1%E3%83%8B%E3%83%A5%E3%83%BC'];

cases.push(run('menuPaths', () => ({
    paths: menuPaths(JSON.stringify(PATHS)),
    broken: [menuPaths('not json'), menuPaths('{"a": 1}'), menuPaths(null), menuPaths(JSON.stringify(['menu', 5, '/en/menu/']))],
})));

cases.push(run('isMenuHref', () => {
    const paths = menuPaths(JSON.stringify(PATHS));
    const base = ORIGIN + '/en/puzzle';
    const hrefs = {
        en: '/en/menu', cs: '/menu', de: '/de/menue', es: '/es/menu', fr: '/fr/menu', jaEncoded: '/ja/%E3%83%A1%E3%83%8B%E3%83%A5%E3%83%BC', jaRaw: '/ja/メニュー',
        absolute: ORIGIN + '/en/menu', withQuery: '/en/menu?return=/en/faq', withFragment: '/en/menu#nav-language', trailingSlash: '/en/menu/',
        relative: 'menu', otherOrigin: 'https://example.org/en/menu', javascript: 'javascript:void(0)', fragmentOnly: '#menu',
        otherPage: '/en/menu-counts.json', prefix: '/en/menus', empty: '',
    };

    return Object.fromEntries(Object.entries(hrefs).map(([name, href]) => [name, isMenuHref(href, paths, base)]));
}));

cases.push(run('menuLinkAction', () => ({
    phone: menuLinkAction(false, 'phone', {}),
    // NAV-13: below 1280 px the overlay, a link where the column is docked focuses it, the ☰ from 1280 px docks / folds
    desktop: menuLinkAction(false, 'overlay', {}),
    docked: menuLinkAction(false, 'docked', {}),
    dockedBurger: menuLinkAction(false, 'docked', {}, true),
    nativeApp: menuLinkAction(true, 'phone', {}),
    middleButton: menuLinkAction(false, 'phone', { button: 1 }),
    modified: menuLinkAction(false, 'phone', { modified: true }),
    alreadyTaken: menuLinkAction(false, 'phone', { defaultPrevented: true }),
    newTab: menuLinkAction(false, 'phone', { newTab: true }),
    download: menuLinkAction(false, 'phone', { download: true }),
})));

function clickScenario({ html, selector, phone = true, wide = false, docked = false, nativeApp = false, opens = true, init = {} }) {
    const window = dom(html, ORIGIN + '/en/faq').window;
    const document = window.document;

    if (nativeApp) {
        document.documentElement.setAttribute('data-native-app', 'android');
    }

    window.matchMedia = (query) => ({ matches: query === PHONE_QUERY ? phone : (query === WIDE_QUERY ? wide : false) });

    if (docked) {
        // jsdom lays nothing out: the docked column's slot is shown
        const slot = document.createElement('div');
        slot.id = 'menu-slot';
        slot.getClientRects = () => [{}];
        document.body.appendChild(slot);
    }

    const opened = [];
    const actions = [];
    const stop = listenForMenuLinks({ document, window, paths: menuPaths(JSON.stringify(PATHS)), open: (opener, action) => (opened.push(opener.id || opener.tagName), actions.push(action), opens) });
    // A navigation is never followed by jsdom; the default action is only read back
    const event = new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    document.querySelector(selector).dispatchEvent(event);
    stop();
    const second = new window.MouseEvent('click', { bubbles: true, cancelable: true });
    document.querySelector(selector).dispatchEvent(second);

    return { opened, actions, prevented: event.defaultPrevented, afterStop: second.defaultPrevented };
}

cases.push(run('listenForMenuLinks', () => {
    const link = '<main><a id="to-menu" href="/de/menue"><span id="inside">Menü</span></a><a id="other" href="/en/faq">FAQ</a></main>';

    return {
        phone: clickScenario({ html: link, selector: '#inside' }),
        burger: clickScenario({ html: '<header><a id="burger" href="/en/menu" data-bar-action="menu" aria-expanded="false">☰</a></header>', selector: '#burger' }),
        // NAV-13: desktop below 1280 px opens the overlay; from 1280 px a link opens it in the rail state, focuses the
        // docked column; the ☰ docks / folds
        desktop: clickScenario({ html: link, selector: '#to-menu', phone: false }),
        rail: clickScenario({ html: link, selector: '#to-menu', phone: false, wide: true }),
        docked: clickScenario({ html: link, selector: '#to-menu', phone: false, wide: true, docked: true }),
        wideBurger: clickScenario({ html: '<header><a id="burger" href="/en/menu" data-bar-action="menu" aria-expanded="false">☰</a></header>', selector: '#burger', phone: false, wide: true }),
        nativeApp: clickScenario({ html: link, selector: '#to-menu', nativeApp: true }),
        ctrlClick: clickScenario({ html: link, selector: '#to-menu', init: { ctrlKey: true } }),
        newTab: clickScenario({ html: '<a id="blank" href="/en/menu" target="_blank">x</a>', selector: '#blank' }),
        cannotOpen: clickScenario({ html: link, selector: '#to-menu', opens: false }),
        otherLink: clickScenario({ html: link, selector: '#other' }),
        opener: menuOpenerOf(dom(link).window.document.getElementById('inside'), menuPaths(JSON.stringify(PATHS)), ORIGIN + '/en/faq').id,
    };
}));

process.stdout.write(JSON.stringify(Object.fromEntries(cases)));
