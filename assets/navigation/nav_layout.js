import { isNativeApp } from '../platform.js';
import { TTL_MS } from './menu_counts.js';
import { NAV_LAYOUT_EVENT } from '../nav_instrumentation.js';

// The desktop Menu column, rail and their state (docs/features/navigation README §4.3 / D16, NAV-13).
//
// What shows is decided by CSS before first paint (templates/navigation/_critical_css.html.twig): from 1024 px the rail
// (#bottom-nav restyled); the Menu docked as the 256 px column (#site-menu in its slot #menu-slot) at 1280-1439 px when
// the player docked it, from 1440 px unless they folded it. JavaScript never writes a length (--nav-left is CSS only):
// it only stores the player's choices and sets the attributes CSS reads.
//
// - The layout (docked | rail, per device): localStorage `msp:nav-layout`, applied before paint by the head script
//   assets/head/nav_layout.js as html[data-nav-layout]. From 1280 px the desktop ☰ switches it (menu_links.js decides,
//   menu_layer_controller.js calls toggleNavLayout()); below 1280 px the ☰ opens the overlay instead.
// - Folded groups of the column and the overlay: localStorage `msp:nav-collapsed` (group ids, space separated, at most 80
//   characters) → html[data-nav-collapsed] (the head script on full loads; CSS hides the rows); a click on a group's
//   disclosure button folds or unfolds it here. Turbo keeps <html>'s attributes across visits - the server renders
//   every button expanded, so turbo:before-render marks the new body's buttons (the script after #site-menu does it on
//   full loads, _menu_slot.html.twig).
// - The column's own scroll position: sessionStorage `msp:nav-col-scroll`, saved when a visit leaves (and on pagehide),
//   restored after each render - moved so the current row is in sight when it is not. Always the column's own
//   scrollTop, never an element scrolled into view (that would scroll the page too).
// - The desktop ☰'s attributes: from 1280 px it switches the column - aria-controls="site-menu", aria-expanded = docked,
//   no aria-haspopup, a tooltip saying what a click does (data-nav-fold-label / data-nav-dock-label: nav.layout.fold /
//   nav.layout.dock); below 1280 px it opens the overlay dialog (aria-haspopup="dialog", aria-controls="menu-layer").
// - Links to `menu` where the column is docked focus its first row (focusDockedMenu()).
// - On the `menu` page itself the ☰ is that page (the server renders aria-current="page" on it, no popup): a click
//   focuses the Menu's first row - phones and desktop - instead of reloading the page (focusPageMenu(); NAV review Q5).
//
// Inside the native app nothing here runs (no column, no rail - the app's own navigation). Pure functions are exported for
// tests/nav-layout-harness.mjs; the browser listeners are installed once when app.js imports this module.

export const LAYOUT_KEY = 'msp:nav-layout';
export const COLLAPSED_KEY = 'msp:nav-collapsed';
export const COLUMN_SCROLL_KEY = 'msp:nav-col-scroll';

export const LAYOUT_ATTRIBUTE = 'data-nav-layout';
export const COLLAPSED_ATTRIBUTE = 'data-nav-collapsed';

export const DOCKED = 'docked';
export const RAIL = 'rail';

/** What the head script accepts as folded groups (the same pattern) */
export const COLLAPSED_PATTERN = /^[a-z_ ]{0,80}$/;
export const COLLAPSED_MAX_LENGTH = 80;

/** From 1280 px the desktop ☰ docks and folds the Menu (README §4.3); Media Queries Level 3 like every desktop query */
export const WIDE_QUERY = '(min-width: 1280px) and (min-height: 501px), (min-width: 1280px) and (pointer: fine)';

/** Dispatched on the document when the ☰ switched the layout, detail {layout, width} - nav_instrumentation.js sends it */
export const LAYOUT_EVENT = NAV_LAYOUT_EVENT;

const SLOT_ID = 'menu-slot';
const MENU_ID = 'site-menu';
const DESKTOP_MENU_BUTTON = '.site-header-menu--desk[data-bar-action="menu"]';
// Either ☰ on the `menu` page (the server marks it the current page)
const CURRENT_MENU_BUTTON = '[data-bar-action="menu"][aria-current="page"]';
const FOCUSABLE_ROW = 'a[href], summary, button:not([disabled])';
const GROUP_ID = /^[a-z_]+$/;

// ---- Pure -------------------------------------------------------------------------------------------------------------

/** A stored layout, or null for anything else */
export function storedLayout(value) {
    return value === DOCKED || value === RAIL ? value : null;
}

/** The folded group ids of a stored value: each once, in order; [] for anything the head script would refuse */
export function collapsedIds(value) {
    if (typeof value !== 'string' || !COLLAPSED_PATTERN.test(value)) {
        return [];
    }

    return value.split(' ').filter((id, index, all) => GROUP_ID.test(id) && all.indexOf(id) === index);
}

/**
 * The stored value after folding (folded = true) or unfolding a group: space-separated ids, at most 80 characters - the
 * oldest fold gives way when the list would grow longer (the head script refuses a longer value whole)
 */
export function collapsedValue(ids, id, folded) {
    const list = collapsedIds(Array.isArray(ids) ? ids.join(' ') : '').filter((other) => other !== id);

    if (folded && GROUP_ID.test(String(id))) {
        list.push(id);
    }

    while (list.join(' ').length > COLLAPSED_MAX_LENGTH) {
        list.shift();
    }

    return list.join(' ');
}

/** What a click on the desktop ☰ makes of the layout from 1280 px */
export function toggledLayout(docked) {
    return docked ? RAIL : DOCKED;
}

/**
 * Where the column scrolls to (its own scrollTop): the saved position, moved so the current row is in sight - centred -
 * when it is not, within [0, max].
 * saved: the stored position (anything not a number counts as 0); height: the column's clientHeight; max: scrollHeight
 * minus clientHeight; row: the current row's {top, height} in the column's content (null: no current row shown).
 */
export function columnScrollTop({ saved = null, height = 0, max = 0, row = null } = {}) {
    const number = typeof saved === 'string' ? parseFloat(saved) : saved;
    let top = typeof number === 'number' && Number.isFinite(number) && number > 0 ? number : 0;

    if (row && Number.isFinite(row.top) && Number.isFinite(row.height) && height > 0 && (row.top < top || row.top + row.height > top + height)) {
        top = row.top - (height - row.height) / 2;
    }

    const limit = Number.isFinite(max) && max > 0 ? max : 0;

    return Math.round(Math.max(0, Math.min(limit, top)));
}

/**
 * The desktop ☰'s state (native-aware decision, tests/native-step-aside-harness.mjs): from 1280 px it switches the
 * docked column - aria-expanded = docked, aria-controls the Menu, no popup, the tooltip of what a click does (fold |
 * dock); below 1280 px (and inside the app, where it is never shown) it opens the overlay dialog - its aria-expanded is
 * the overlay's, kept by menu_layer_controller.js (null = leave it). On the `menu` page (current: the ☰ carries
 * aria-current="page") it only points at the Menu, the page's content: no popup, no expanded state, no tooltip.
 */
export function menuButtonState({ nativeApp = false, wide = false, docked = false, current = false } = {}) {
    if (current) {
        return { haspopup: null, expanded: null, controls: MENU_ID, tooltip: null };
    }

    if (nativeApp || !wide) {
        return { haspopup: 'dialog', expanded: null, controls: 'menu-layer', tooltip: null };
    }

    return { haspopup: null, expanded: docked ? 'true' : 'false', controls: MENU_ID, tooltip: docked ? 'fold' : 'dock' };
}

// ---- Browser ----------------------------------------------------------------------------------------------------------

function matches(window, query) {
    try {
        return Boolean(window.matchMedia && window.matchMedia(query).matches);
    } catch (e) {
        return false;
    }
}

function storage(window, kind) {
    try {
        return window[kind] || null;
    } catch (e) {
        return null;
    }
}

function read(window, kind, key) {
    const store = storage(window, kind);

    try {
        return store ? store.getItem(key) : null;
    } catch (e) {
        return null;
    }
}

function write(window, kind, key, value) {
    const store = storage(window, kind);

    try {
        if (store) {
            store.setItem(key, value);
        }
    } catch (e) {
        // private mode, a full storage: the choice holds for this page only
    }
}

/** The page's Menu in its slot (null on the `menu` page, bar-free pages, and while a shell holds it) */
export function slotMenu(document) {
    const slot = document.getElementById(SLOT_ID);
    const nav = document.getElementById(MENU_ID);

    return slot && nav && nav.parentNode === slot ? nav : null;
}

/**
 * Is the Menu docked as the column right now - its slot shown (critical CSS decides). Critical CSS shows the slot only
 * within WIDE_QUERY, so anywhere narrower the answer needs no layout: asking getClientRects() at turbo:render laid the
 * whole new page out in the middle of the render on every phone visit and Back (GEST-15 (e)).
 */
export function isDocked(document) {
    const slot = document.getElementById(SLOT_ID);

    if (!slot) {
        return false;
    }

    const view = document.defaultView;

    if (view && typeof view.matchMedia === 'function') {
        try {
            if (!view.matchMedia(WIDE_QUERY).matches) {
                return false;
            }
        } catch (e) {
            // the layout below decides
        }
    }

    return slot.getClientRects().length > 0;
}

function shown(element) {
    return Boolean(element) && element.getClientRects().length > 0;
}

/** The current row's box inside the column's content, or null */
function currentRow(nav) {
    const row = nav.querySelector('[aria-current="page"]');

    if (!shown(row)) {
        return null;
    }

    const box = row.getBoundingClientRect();

    return { top: box.top - nav.getBoundingClientRect().top + nav.scrollTop, height: box.height };
}

/** Puts the docked column where it was (or onto the current row) - after a render, after docking */
export function placeColumn(window, document) {
    const nav = slotMenu(document);

    if (!nav || !isDocked(document)) {
        return;
    }

    nav.scrollTop = columnScrollTop({
        saved: read(window, 'sessionStorage', COLUMN_SCROLL_KEY),
        height: nav.clientHeight,
        max: nav.scrollHeight - nav.clientHeight,
        row: currentRow(nav),
    });
}

export function saveColumnScroll(window, document) {
    const nav = slotMenu(document);

    if (nav && isDocked(document)) {
        write(window, 'sessionStorage', COLUMN_SCROLL_KEY, String(Math.round(nav.scrollTop)));
    }
}

/** The disclosure buttons in `root` (a body, the document) follow the folded groups */
export function markDisclosures(root, ids) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return;
    }

    root.querySelectorAll('#site-menu .menu-disclosure').forEach((button) => {
        const group = button.closest('[data-menu-group]');
        const folded = Boolean(group) && ids.includes(group.getAttribute('data-menu-group'));
        button.setAttribute('aria-expanded', folded ? 'false' : 'true');
    });
}

function foldedIds(document) {
    return collapsedIds(document.documentElement.getAttribute(COLLAPSED_ATTRIBUTE) || '');
}

/** Folds or unfolds one group of the column / overlay - stored per device, shown at once */
export function setGroupFolded(window, document, id, folded) {
    const value = collapsedValue(foldedIds(document), id, folded);

    write(window, 'localStorage', COLLAPSED_KEY, value);

    if (value === '') {
        document.documentElement.removeAttribute(COLLAPSED_ATTRIBUTE);
    } else {
        document.documentElement.setAttribute(COLLAPSED_ATTRIBUTE, value);
    }

    markDisclosures(document, collapsedIds(value));

    return value;
}

/** The desktop ☰'s attributes for this screen (menuButtonState) */
export function syncMenuButtons(window, document) {
    const screen = {
        nativeApp: isNativeApp(document.documentElement),
        wide: matches(window, WIDE_QUERY),
        docked: isDocked(document),
    };

    document.querySelectorAll(DESKTOP_MENU_BUTTON).forEach((button) => {
        const state = menuButtonState({ ...screen, current: button.getAttribute('aria-current') === 'page' });

        if (state.haspopup === null) {
            button.removeAttribute('aria-haspopup');
        } else {
            button.setAttribute('aria-haspopup', state.haspopup);
        }

        if (state.expanded !== null) {
            button.setAttribute('aria-expanded', state.expanded);
        }

        button.setAttribute('aria-controls', state.controls);
        const tooltip = state.tooltip ? button.getAttribute('data-nav-' + state.tooltip + '-label') : null;

        if (tooltip) {
            button.setAttribute('title', tooltip);
        } else {
            button.removeAttribute('title');
        }
    });
}

/**
 * The desktop ☰ from 1280 px: docked ↔ rail - stored per device, applied at once (CSS moves everything, including
 * every fixed bar through --nav-left); docking loads the Menu's numbers (at most once per 5 minutes) and puts the
 * column onto the current row. Returns the new layout.
 */
export function toggleNavLayout(window, document) {
    const next = toggledLayout(isDocked(document));

    write(window, 'localStorage', LAYOUT_KEY, next);
    document.documentElement.setAttribute(LAYOUT_ATTRIBUTE, next);
    syncMenuButtons(window, document);

    const nav = slotMenu(document);

    if (next === DOCKED && nav) {
        markDisclosures(document, foldedIds(document));
        placeColumn(window, document);
        nav.dispatchEvent(new window.CustomEvent('menu-counts:refresh', { detail: { maxAge: TTL_MS } }));
    }

    document.dispatchEvent(new window.CustomEvent(LAYOUT_EVENT, { detail: { layout: next, width: window.innerWidth } }));

    return next;
}

/**
 * A link to `menu` where the column is docked: its first row gets the focus (the page does not move) and the column
 * scrolls to its top; with a fragment (`menu#nav-language`) the place it names is shown instead - a <details> opened, a
 * folded group unfolded. False when there is no docked column.
 */
export function focusDockedMenu(window, document, fragment = null) {
    const nav = slotMenu(document);

    if (!nav || !isDocked(document)) {
        return false;
    }

    const target = fragment ? document.getElementById(fragment) : null;

    if (target && target !== nav && nav.contains(target)) {
        const group = target.closest('[data-menu-group]');

        if (group && foldedIds(document).includes(group.getAttribute('data-menu-group'))) {
            setGroupFolded(window, document, group.getAttribute('data-menu-group'), false);
        }

        const details = target.tagName === 'DETAILS' ? target : target.closest('details');

        if (details && nav.contains(details)) {
            details.open = true;
        }

        const focusable = details && nav.contains(details) ? details.querySelector('summary') : (target.matches(FOCUSABLE_ROW) ? target : target.querySelector(FOCUSABLE_ROW));

        if (focusable) {
            focusable.focus({ preventScroll: true });
        }

        const box = (details && nav.contains(details) ? details : target).getBoundingClientRect();
        nav.scrollTop = columnScrollTop({
            saved: nav.scrollTop,
            height: nav.clientHeight,
            max: nav.scrollHeight - nav.clientHeight,
            row: { top: box.top - nav.getBoundingClientRect().top + nav.scrollTop, height: box.height },
        });

        return true;
    }

    nav.scrollTop = 0;
    const first = Array.from(nav.querySelectorAll(FOCUSABLE_ROW)).find(shown);

    if (first) {
        first.focus({ preventScroll: true });
    }

    return true;
}

/**
 * The `menu` page (NAV review Q5): its Menu - the page's content, #site-menu inside <main> - gets the focus on its first
 * row; the page scrolls (at once) only when that row is not in sight under the top bar. False when this page's Menu is
 * not its content.
 */
export function focusPageMenu(window, document) {
    const nav = document.getElementById(MENU_ID);

    if (!nav || !nav.closest('main')) {
        return false;
    }

    const first = Array.from(nav.querySelectorAll(FOCUSABLE_ROW)).find(shown);

    if (!first) {
        return true;
    }

    first.focus({ preventScroll: true });

    const header = document.querySelector('body > .site-header');
    const under = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
    const row = first.getBoundingClientRect();

    if (row.top < under || row.bottom > window.innerHeight) {
        window.scrollTo({ top: Math.max(0, window.scrollY + row.top - under - 16), behavior: 'instant' });
    }

    return true;
}

/** A plain click (no modifier, the main button, not taken yet) on the ☰ of the `menu` page itself, or null */
export function pageMenuButtonOf(event) {
    const button = event.target && typeof event.target.closest === 'function' ? event.target.closest(CURRENT_MENU_BUTTON) : null;

    if (!button || event.defaultPrevented || (event.button || 0) > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return null;
    }

    return button;
}

/** Installs the listeners once (app.js imports this module); returns false inside the app */
export function installNavLayout(window, document) {
    if (window.__mspNavLayout) {
        return true;
    }

    window.__mspNavLayout = true;
    const web = () => !isNativeApp(document.documentElement);

    // The ☰ on the `menu` page: the page is the Menu - its first row takes the focus, the page is not loaded again
    // (bubble phase on the document: before Turbo's own listener on the window)
    document.addEventListener('click', (event) => {
        if (web() && pageMenuButtonOf(event) && focusPageMenu(window, document)) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', (event) => {
        const button = event.target && typeof event.target.closest === 'function' ? event.target.closest('.menu-disclosure') : null;

        if (!button || !web() || !button.closest('#site-menu')) {
            return;
        }

        const group = button.closest('[data-menu-group]');

        if (group) {
            setGroupFolded(window, document, group.getAttribute('data-menu-group'), button.getAttribute('aria-expanded') !== 'false');
        }
    });

    // The server renders every disclosure expanded; the folded ones are marked before the new body is shown
    document.addEventListener('turbo:before-render', (event) => {
        if (web() && event.detail && event.detail.newBody) {
            markDisclosures(event.detail.newBody, foldedIds(document));
        }
    });

    document.addEventListener('turbo:before-visit', () => saveColumnScroll(window, document));
    window.addEventListener('pagehide', () => saveColumnScroll(window, document));

    document.addEventListener('turbo:render', () => {
        if (web()) {
            placeColumn(window, document);
            syncMenuButtons(window, document);
        }
    });

    document.addEventListener('turbo:load', () => {
        if (web()) {
            syncMenuButtons(window, document);
        }
    });

    // The tier changes with the window: 1280 px (the ☰ docks / opens the overlay) and 1440 px (the docked default)
    let pending = false;
    window.addEventListener('resize', () => {
        if (pending || !web()) {
            return;
        }

        pending = true;
        window.requestAnimationFrame(() => {
            pending = false;
            syncMenuButtons(window, document);
        });
    });

    if (web()) {
        syncMenuButtons(window, document);
    }

    return true;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
    installNavLayout(window, document);
}
