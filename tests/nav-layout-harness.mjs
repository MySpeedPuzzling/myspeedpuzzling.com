// The desktop Menu's client-side rules (docs/features/navigation NAV-13, README §4.3 / D16) for
// tests/NavLayoutScriptTest.php:
// - assets/head/nav_layout.js: html[data-nav-layout] and html[data-nav-collapsed] from localStorage before first paint -
//   bad values ignored, nothing inside the app, a blocked storage throws nothing;
// - assets/navigation/nav_layout.js: the stored values, the folded groups, the ☰'s state per tier, where the column
//   scrolls to, and in jsdom: folding a group, marking a new body's disclosure buttons, the ☰ docking/folding, a link to
//   `menu` focusing the docked column's first row;
// - assets/navigation/menu_links.js: what a click on a link to `menu` or the ☰ does on each surface.
// Prints {case: facts} as JSON.

import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import {
    COLLAPSED_ATTRIBUTE,
    COLLAPSED_KEY,
    COLUMN_SCROLL_KEY,
    collapsedIds,
    collapsedValue,
    columnScrollTop,
    focusDockedMenu,
    focusPageMenu,
    installNavLayout,
    isDocked,
    LAYOUT_ATTRIBUTE,
    LAYOUT_EVENT,
    LAYOUT_KEY,
    markDisclosures,
    menuButtonState,
    saveColumnScroll,
    setGroupFolded,
    storedLayout,
    syncMenuButtons,
    toggledLayout,
    toggleNavLayout,
    WIDE_QUERY,
} from '../assets/navigation/nav_layout.js';
import { menuLinkAction, menuSurface } from '../assets/navigation/menu_links.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

// ---- assets/head/nav_layout.js ------------------------------------------------------------------------------------

const HEAD = readFileSync(new URL('../assets/head/nav_layout.js', import.meta.url), 'utf8');
// What the page inlines: its minified build (tools/build-inline-scripts.mjs) - the same cases must give the same answers
const HEAD_BUILT = readFileSync(new URL('../templates/_inline_scripts/nav_layout.js', import.meta.url), 'utf8');

/** Runs the head script on a fake window; returns the attributes it set on <html> (null when it threw) */
function head({ layout = null, collapsed = null, nativeApp = false, storageThrows = false } = {}, code = HEAD) {
    const attributes = {};
    const window = {
        localStorage: {
            getItem: (key) => {
                if (storageThrows) {
                    throw new Error('blocked');
                }

                return { 'msp:nav-layout': layout, 'msp:nav-collapsed': collapsed }[key] ?? null;
            },
        },
        document: {
            documentElement: {
                hasAttribute: (name) => nativeApp && name === 'data-native-app',
                setAttribute: (name, value) => {
                    attributes[name] = value;
                },
            },
        },
    };

    try {
        vm.runInNewContext(code, { window });
    } catch (e) {
        return null;
    }

    return attributes;
}

const cases = [];

const headCases = (code) => ({
    nothing: head({}, code),
    docked: head({ layout: 'docked' }, code),
    rail: head({ layout: 'rail' }, code),
    junkLayout: head({ layout: 'floating' }, code),
    upperCase: head({ layout: 'Docked' }, code),
    folded: head({ collapsed: 'roles help' }, code),
    foldedAndRail: head({ layout: 'rail', collapsed: 'about' }, code),
    onlySpaces: head({ collapsed: '   ' }, code),
    empty: head({ collapsed: '' }, code),
    injected: head({ collapsed: 'roles"] *{display:none' }, code),
    tooLong: head({ collapsed: 'a'.repeat(81) }, code),
    longest: head({ collapsed: 'a'.repeat(80) }, code),
    insideTheApp: head({ layout: 'docked', collapsed: 'roles', nativeApp: true }, code),
    storageBlocked: head({ layout: 'docked', storageThrows: true }, code),
    text: { hasScriptEnd: /<\/script/i.test(code), hasCommentOpen: code.includes('<!--'), setsDataNav: /setAttribute\(\s*'data-nav'/.test(code) },
});

cases.push(run('head script', () => headCases(HEAD)));
cases.push(run('head script build', () => headCases(HEAD_BUILT)));

// ---- assets/navigation/nav_layout.js (pure) -------------------------------------------------------------------------

cases.push(run('pure', () => ({
    keys: { layout: LAYOUT_KEY, collapsed: COLLAPSED_KEY, scroll: COLUMN_SCROLL_KEY, layoutAttribute: LAYOUT_ATTRIBUTE, collapsedAttribute: COLLAPSED_ATTRIBUTE, event: LAYOUT_EVENT, wide: WIDE_QUERY },
    storedLayout: ['docked', 'rail', 'floating', null, ''].map(storedLayout),
    collapsedIds: {
        twice: collapsedIds('roles help roles'),
        spaces: collapsedIds('  roles   about '),
        junk: collapsedIds('roles;help'),
        tooLong: collapsedIds('a'.repeat(81)),
        notAString: collapsedIds(null),
    },
    collapsedValue: {
        fold: collapsedValue(['roles'], 'help', true),
        foldAgain: collapsedValue(['roles', 'help'], 'help', true),
        unfold: collapsedValue(['roles', 'help'], 'roles', false),
        unfoldLast: collapsedValue(['roles'], 'roles', false),
        notAnId: collapsedValue(['roles'], 'he lp', true),
        // The oldest fold gives way: never more than 80 characters
        overflow: collapsedValue(['aaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbb', 'cccccccccccccccccccc', 'ddddddddddddddddd'], 'eeee', true),
    },
    toggledLayout: { fromDocked: toggledLayout(true), fromRail: toggledLayout(false) },
    columnScrollTop: {
        // Nothing saved, the current row in sight: the top
        top: columnScrollTop({ saved: null, height: 600, max: 900, row: { top: 100, height: 32 } }),
        // Saved and the row still in sight: where it was
        kept: columnScrollTop({ saved: '300', height: 600, max: 900, row: { top: 400, height: 32 } }),
        // The row below the saved window: centred on it
        below: columnScrollTop({ saved: 0, height: 600, max: 900, row: { top: 1000, height: 40 } }),
        // The row above the saved window: centred, never below 0
        above: columnScrollTop({ saved: 800, height: 600, max: 900, row: { top: 100, height: 32 } }),
        // Never past the end
        clamped: columnScrollTop({ saved: 5000, height: 600, max: 900, row: null }),
        junk: columnScrollTop({ saved: 'NaN', height: 600, max: 900, row: null }),
    },
    menuButtonState: {
        wideDocked: menuButtonState({ wide: true, docked: true }),
        wideRail: menuButtonState({ wide: true, docked: false }),
        narrow: menuButtonState({ wide: false, docked: false }),
        insideTheApp: menuButtonState({ nativeApp: true, wide: true, docked: true }),
        // The `menu` page (NAV review Q5): the ☰ is that page - no popup, no expanded state, no tooltip, at any width
        menuPageWide: menuButtonState({ wide: true, docked: false, current: true }),
        menuPageNarrow: menuButtonState({ wide: false, docked: false, current: true }),
    },
})));

// ---- menu_links.js: the decisions -----------------------------------------------------------------------------------

cases.push(run('menuSurface', () => ({
    phone: menuSurface({ phone: true, wide: false, docked: false }),
    // A landscape phone is a phone, however wide
    landscapePhone: menuSurface({ phone: true, wide: true, docked: false }),
    compact: menuSurface({ phone: false, wide: false, docked: false }),
    railBelow1280: menuSurface({ phone: false, wide: false, docked: false }),
    rail: menuSurface({ phone: false, wide: true, docked: false }),
    docked: menuSurface({ phone: false, wide: true, docked: true }),
})));

cases.push(run('menuLinkAction', () => {
    const table = {};

    for (const surface of ['phone', 'overlay', 'rail', 'docked']) {
        table[surface] = { link: menuLinkAction(false, surface, {}, false), bar: menuLinkAction(false, surface, {}, true) };
    }

    return {
        ...table,
        insideTheApp: ['phone', 'overlay', 'rail', 'docked'].map((surface) => menuLinkAction(true, surface, {}, true)),
        ctrlClickDocked: menuLinkAction(false, 'docked', { modified: true }, false),
        newTabRail: menuLinkAction(false, 'rail', { newTab: true }, false),
        unknown: menuLinkAction(false, 'tv', {}, false),
    };
}));

// ---- nav_layout.js in a document ------------------------------------------------------------------------------------

const ORIGIN = 'https://msp.test';

function page({ docked = true, collapsed = null, wide = true, nativeApp = false } = {}) {
    const html = `<!doctype html><html lang="en"${nativeApp ? ' data-native-app="android"' : ''}${collapsed ? ` data-nav-collapsed="${collapsed}"` : ''}><body>
        <header><a class="site-header-menu site-header-menu--desk" href="/en/menu" data-bar-action="menu" aria-haspopup="dialog" aria-expanded="false" aria-controls="site-menu" data-nav-fold-label="Collapse the menu" data-nav-dock-label="Expand the menu">☰</a></header>
        <div class="menu-slot" id="menu-slot"><nav id="site-menu">
            <section class="menu-group" data-menu-group="home"><ul><li><a class="mr mr--place" id="first-row" href="/en/hub">Home</a></li></ul></section>
            <section class="menu-group" data-menu-group="roles"><p id="menu-g-roles">Roles</p><button class="menu-disclosure" aria-expanded="true" aria-controls="menu-l-roles"></button><ul id="menu-l-roles"><li><a class="mr" href="/admin">Admin</a></li></ul></section>
            <section class="menu-group" data-menu-group="help"><p>Help</p><button class="menu-disclosure" aria-expanded="true"></button><ul><li id="menu-row-language"><details id="nav-language"><summary class="mr">Language</summary><ul><li><a href="/de/faq">Deutsch</a></li></ul></details></li></ul></section>
        </nav></div></body></html>`;
    const window = new JSDOM(html, { url: ORIGIN + '/en/faq', virtualConsole: new VirtualConsole() }).window;
    const document = window.document;
    const shown = docked ? [{}] : [];

    // jsdom lays nothing out: the column is "shown" when the test says it is docked
    document.getElementById('menu-slot').getClientRects = () => shown;
    document.querySelectorAll('#site-menu a, #site-menu summary, #site-menu button').forEach((element) => {
        element.getClientRects = () => [{}];
    });
    window.matchMedia = (query) => ({ matches: query === WIDE_QUERY ? wide : false });

    const events = [];
    const refreshes = [];
    document.addEventListener(LAYOUT_EVENT, (event) => events.push(event.detail));
    document.getElementById('site-menu').addEventListener('menu-counts:refresh', (event) => refreshes.push(event.detail));

    return { window, document, events, refreshes };
}

const attr = (document, selector, name) => document.querySelector(selector).getAttribute(name);

cases.push(run('fold a group', () => {
    const { window, document } = page();
    const folded = setGroupFolded(window, document, 'roles', true);
    const after = {
        value: folded,
        stored: window.localStorage.getItem(COLLAPSED_KEY),
        attribute: document.documentElement.getAttribute(COLLAPSED_ATTRIBUTE),
        roles: attr(document, '[data-menu-group="roles"] .menu-disclosure', 'aria-expanded'),
        help: attr(document, '[data-menu-group="help"] .menu-disclosure', 'aria-expanded'),
    };
    const unfolded = setGroupFolded(window, document, 'roles', false);

    return {
        after,
        unfolded: { value: unfolded, stored: window.localStorage.getItem(COLLAPSED_KEY), attribute: document.documentElement.getAttribute(COLLAPSED_ATTRIBUTE), roles: attr(document, '[data-menu-group="roles"] .menu-disclosure', 'aria-expanded') },
    };
}));

cases.push(run('a new body follows the folded groups', () => {
    const { document } = page({ collapsed: 'help' });
    const body = document.createElement('body');
    body.innerHTML = document.body.innerHTML;
    markDisclosures(body, collapsedIds('help'));

    return {
        roles: body.querySelector('[data-menu-group="roles"] .menu-disclosure').getAttribute('aria-expanded'),
        help: body.querySelector('[data-menu-group="help"] .menu-disclosure').getAttribute('aria-expanded'),
    };
}));

cases.push(run('the ☰ folds and docks', () => {
    const { window, document, events, refreshes } = page({ docked: true });
    syncMenuButtons(window, document);
    const before = { expanded: attr(document, '.site-header-menu--desk', 'aria-expanded'), haspopup: attr(document, '.site-header-menu--desk', 'aria-haspopup'), title: attr(document, '.site-header-menu--desk', 'title') };
    const folded = toggleNavLayout(window, document);
    // CSS would hide the slot now
    document.getElementById('menu-slot').getClientRects = () => [];
    syncMenuButtons(window, document);
    const rail = { expanded: attr(document, '.site-header-menu--desk', 'aria-expanded'), title: attr(document, '.site-header-menu--desk', 'title') };
    const docked = toggleNavLayout(window, document);

    return {
        before,
        folded,
        rail,
        docked,
        stored: window.localStorage.getItem(LAYOUT_KEY),
        attribute: document.documentElement.getAttribute(LAYOUT_ATTRIBUTE),
        events: events.map((detail) => detail.layout),
        // Docking asks for the Menu's numbers with the 5-minute TTL (never fresh on every visit)
        refreshes,
    };
}));

cases.push(run('the ☰ below 1280 px', () => {
    const { window, document } = page({ docked: false, wide: false });
    syncMenuButtons(window, document);

    return {
        expanded: attr(document, '.site-header-menu--desk', 'aria-expanded'),
        haspopup: attr(document, '.site-header-menu--desk', 'aria-haspopup'),
        controls: attr(document, '.site-header-menu--desk', 'aria-controls'),
        title: attr(document, '.site-header-menu--desk', 'title'),
    };
}));

cases.push(run('a link to menu where the column is docked', () => {
    const { window, document } = page({ docked: true, collapsed: 'help' });
    const nav = document.getElementById('site-menu');
    nav.scrollTop = 120;
    const first = focusDockedMenu(window, document, null);
    const focused = document.activeElement ? document.activeElement.id : null;
    const language = focusDockedMenu(window, document, 'nav-language');

    return {
        first,
        focused,
        scrollTop: nav.scrollTop,
        language,
        languageOpen: document.getElementById('nav-language').open,
        languageFocused: document.activeElement ? document.activeElement.tagName : null,
        // The group it is in was folded: unfolded to show it
        collapsed: document.documentElement.getAttribute(COLLAPSED_ATTRIBUTE),
        notDocked: focusDockedMenu(page({ docked: false }).window, page({ docked: false }).document, null),
    };
}));

cases.push(run('the column scroll is saved', () => {
    const { window, document } = page({ docked: true });
    document.getElementById('site-menu').scrollTop = 0;
    Object.defineProperty(document.getElementById('site-menu'), 'scrollTop', { value: 240, configurable: true });
    saveColumnScroll(window, document);
    const saved = window.sessionStorage.getItem(COLUMN_SCROLL_KEY);
    const rail = page({ docked: false });
    saveColumnScroll(rail.window, rail.document);

    return { saved, railSaves: rail.window.sessionStorage.getItem(COLUMN_SCROLL_KEY) };
}));

// ---- the `menu` page: its ☰ focuses the Menu instead of loading the page again (NAV review Q5) ---------------------

function menuPage({ wide = true, current = true } = {}) {
    const marker = current ? ' aria-current="page"' : ' aria-haspopup="dialog" aria-expanded="false"';
    const html = `<!doctype html><html lang="en"><body>
        <header class="site-header"><a class="site-header-menu site-header-menu--desk" id="menu-desk" href="/en/menu" data-bar-action="menu"${marker} aria-controls="site-menu" data-nav-fold-label="Collapse the menu" data-nav-dock-label="Expand the menu">☰</a><a class="site-header-menu site-header-menu--phone" id="menu-phone" href="/en/menu" data-bar-action="menu"${marker}>☰</a></header>
        <main><h1>Menu</h1><nav id="site-menu"><section class="menu-group" data-menu-group="you"><ul><li><a class="mr" id="first-row" href="/en/player/1">You</a></li><li><a class="mr" href="/en/settings">Settings</a></li></ul></section></nav></main>
        </body></html>`;
    const window = new JSDOM(html, { url: ORIGIN + '/en/menu', virtualConsole: new VirtualConsole() }).window;
    const document = window.document;

    document.querySelectorAll('#site-menu a').forEach((element) => {
        element.getClientRects = () => [{}];
    });
    window.matchMedia = (query) => ({ matches: query === WIDE_QUERY ? wide : false, addEventListener: () => {}, removeEventListener: () => {} });
    window.requestAnimationFrame = (callback) => callback();

    return { window, document };
}

function click(window, element, init = {}) {
    const event = new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    element.dispatchEvent(event);

    return event.defaultPrevented;
}

cases.push(run('the ☰ on the menu page', () => {
    const wide = menuPage({ wide: true });
    installNavLayout(wide.window, wide.document);
    const desk = wide.document.getElementById('menu-desk');
    const attributes = {
        haspopup: desk.getAttribute('aria-haspopup'),
        expanded: desk.getAttribute('aria-expanded'),
        controls: desk.getAttribute('aria-controls'),
        title: desk.getAttribute('title'),
        current: desk.getAttribute('aria-current'),
    };
    const deskPrevented = click(wide.window, desk);
    const deskFocused = wide.document.activeElement ? wide.document.activeElement.id : null;
    const layoutStored = wide.window.localStorage.getItem(LAYOUT_KEY);

    const phone = menuPage({ wide: false });
    installNavLayout(phone.window, phone.document);
    const phonePrevented = click(phone.window, phone.document.getElementById('menu-phone'));
    const phoneFocused = phone.document.activeElement ? phone.document.activeElement.id : null;

    // A click the browser keeps (a new tab) goes to the page as it is
    const kept = menuPage();
    installNavLayout(kept.window, kept.document);
    const ctrlClick = click(kept.window, kept.document.getElementById('menu-phone'), { ctrlKey: true });

    // Elsewhere (no aria-current) this listener leaves the ☰ alone - the Menu shell's listener opens the Menu there
    const other = menuPage({ current: false });
    installNavLayout(other.window, other.document);
    const otherPrevented = click(other.window, other.document.getElementById('menu-phone'));

    return {
        attributes,
        deskPrevented,
        deskFocused,
        layoutStored,
        phonePrevented,
        phoneFocused,
        ctrlClick,
        otherPrevented,
        notTheMenuPage: focusPageMenu(page().window, page().document),
    };
}));

// GEST-15 (e): below 1280 px the column cannot be docked (critical CSS shows its slot only within WIDE_QUERY) - known
// without a layout, so a render (a Back restoring a long list) is not laid out in the middle by turbo:render's check
cases.push(run('docked without a layout below 1280 px', () => {
    const narrow = page({ docked: true, wide: false });
    let measured = 0;
    narrow.document.getElementById('menu-slot').getClientRects = () => {
        measured += 1;
        return [{}];
    };
    const narrowDocked = isDocked(narrow.document);
    const wide = page({ docked: true, wide: true });
    const hidden = page({ docked: false, wide: true });

    return { narrowDocked, measured, wideDocked: isDocked(wide.document), wideHidden: isDocked(hidden.document) };
}));

process.stdout.write(JSON.stringify(Object.fromEntries(cases)));
