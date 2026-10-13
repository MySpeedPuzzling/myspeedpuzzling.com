import { isNativeApp } from '../platform.js';
import { isDocked, WIDE_QUERY } from './nav_layout.js';

// Links to the Menu (docs/features/navigation README §4.12 "Fallback page", MENU-02b, NAV-13): the route `menu` is a
// real page - for no JS, a new tab, deep links and the native app - and with JS on the web a link to it opens the Menu
// instead: on phones the panel (MENU-02b); on desktop (NAV-13, README §4.3) the docked column gets the focus where the
// Menu is docked, the overlay opens everywhere else (the rail, below 1280 px). The ☰ of the top bar
// (data-bar-action="menu", NAV-03 / NAV-04) is such a link too - but from 1280 px the desktop ☰ switches the column
// between docked and rail instead.
//
// One delegated click listener on the document (bubble phase: it runs before Turbo's own listener on the window and
// takes the click with preventDefault(), so Turbo never visits the page). A click the browser should keep - another
// button, a modifier key, a new tab, a download - and every click inside the native app go to the page as they are.
//
// The paths of `menu` in every locale come from the shell's data-menu-paths (rendered by the server, percent-encoded
// as path() writes them); a link matches by its path, whatever its query string or fragment.

/** Phones in Media Queries Level 3 syntax (requirements, Conventions; $nav-phone-media in _nav-tokens.scss) */
export const PHONE_QUERY = '(max-width: 767.98px), (max-height: 500.98px) and (pointer: coarse)';

/**
 * Which Menu this screen has (NAV-13): 'phone' (the panel), 'overlay' (desktop below 1280 px: the ☰ opens it), 'rail'
 * and 'docked' (from 1280 px, the player's choice per device - the ☰ switches between them)
 */
export const SURFACES = ['phone', 'overlay', 'rail', 'docked'];

/** The surface from what the screen is (pure): phone, at least 1280 px wide (desktop), the column docked */
export function menuSurface({ phone = false, wide = false, docked = false } = {}) {
    if (phone) {
        return 'phone';
    }

    if (docked) {
        return 'docked';
    }

    return wide ? 'rail' : 'overlay';
}

const OPENER_SELECTOR = 'a[href], [data-bar-action="menu"]';

function decoded(path) {
    let value = String(path || '');

    try {
        value = decodeURIComponent(value);
    } catch (e) {
        // a broken escape stays as written
    }

    return value.length > 1 ? value.replace(/\/+$/, '') : value;
}

/** The decoded paths of `menu` from the shell's data-menu-paths (a JSON list); an empty list when it is broken */
export function menuPaths(json) {
    let list;

    try {
        list = JSON.parse(String(json || '[]'));
    } catch (e) {
        return [];
    }

    return Array.isArray(list) ? list.filter((path) => typeof path === 'string' && path.startsWith('/')).map(decoded) : [];
}

/** Is this href (relative or absolute, same origin) a link to the Menu page in any locale? */
export function isMenuHref(href, paths, base) {
    if (typeof href !== 'string' || href === '' || href.startsWith('#')) {
        return false;
    }

    let url;

    try {
        url = new URL(href, base);
    } catch (e) {
        return false;
    }

    if ((url.protocol !== 'http:' && url.protocol !== 'https:') || url.origin !== new URL(base).origin) {
        return false;
    }

    return paths.includes(decoded(url.pathname));
}

/**
 * The id a link to the Menu names with its fragment (`/en/menu#nav-language`: "nav-language"), or null - for a link to
 * any other page, or one without a fragment
 */
export function menuFragmentOf(href, paths, base) {
    if (!isMenuHref(href, paths, base)) {
        return null;
    }

    let id;

    try {
        id = decodeURIComponent(new URL(href, base).hash.slice(1));
    } catch (e) {
        return null;
    }

    return id !== '' ? id : null;
}

/**
 * Shows the place `id` names inside the Menu `nav` (review F11): opens the <details> it is or sits in (the language
 * switch), focuses its summary (or its link) and scrolls it into view. False - nothing done - when the id is not an
 * element of the Menu; `dryRun` only answers that.
 */
export function revealInMenu(nav, id, { dryRun = false } = {}) {
    if (!nav || !id || typeof nav.querySelector !== 'function') {
        return false;
    }

    const element = nav.ownerDocument ? nav.ownerDocument.getElementById(id) : null;

    if (!element || element === nav || !nav.contains(element)) {
        return false;
    }

    if (dryRun) {
        return true;
    }

    const details = element.tagName === 'DETAILS' ? element : element.closest('details');

    if (details && nav.contains(details)) {
        details.open = true;
    }

    const focusable = details && nav.contains(details) ? details.querySelector('summary') : element.querySelector('a[href], button, summary');

    if (focusable) {
        focusable.focus({ preventScroll: true });
    }

    (details && nav.contains(details) ? details : element).scrollIntoView({ block: 'center', behavior: 'instant' });

    return true;
}

/** The element a click opens the Menu from (a link to `menu` or the ☰), or null */
export function menuOpenerOf(target, paths, base) {
    const element = target && typeof target.closest === 'function' ? target.closest(OPENER_SELECTOR) : null;

    if (!element) {
        return null;
    }

    if (element.getAttribute('data-bar-action') === 'menu') {
        return element;
    }

    return isMenuHref(element.getAttribute('href'), paths, base) ? element : null;
}

/**
 * What a click on a link to the Menu does (native-aware decision, tests/native-step-aside-harness.mjs):
 * 'page' - the browser follows the link to the `menu` page (the native app always, a click it should keep);
 * 'panel' - the phone panel opens; 'overlay' - the desktop overlay opens (below 1280 px, and from a link in the rail
 * state); 'focus' - the docked column's first row gets the focus (a link where the Menu is docked); 'toggle' - the
 * desktop ☰ from 1280 px switches the column between docked and rail.
 * surface: menuSurface(); click: {button, modified (Ctrl/Cmd/Shift/Alt), defaultPrevented, newTab (a target other than
 * _self), download}; bar: the click is on the top bar's ☰ (data-bar-action="menu")
 */
export function menuLinkAction(nativeApp, surface, click = {}, bar = false) {
    if (nativeApp) {
        return 'page';
    }

    if (click.defaultPrevented || (click.button || 0) > 0 || click.modified || click.newTab || click.download) {
        return 'page';
    }

    switch (surface) {
        case 'phone':
            return 'panel';
        case 'docked':
            return bar ? 'toggle' : 'focus';
        case 'rail':
            return bar ? 'toggle' : 'overlay';
        case 'overlay':
            return 'overlay';
        default:
            return 'page';
    }
}

/** This screen's surface (menuSurface() from the media queries and whether the column is shown) */
export function currentMenuSurface(window, document) {
    const query = (media) => typeof window.matchMedia === 'function' && window.matchMedia(media).matches;

    return menuSurface({ phone: query(PHONE_QUERY), wide: query(WIDE_QUERY), docked: isDocked(document) });
}

function clickFacts(event, opener) {
    const target = opener.getAttribute('target');

    return {
        button: event.button,
        modified: Boolean(event.ctrlKey || event.metaKey || event.shiftKey || event.altKey),
        defaultPrevented: event.defaultPrevented,
        newTab: Boolean(target) && target !== '_self',
        download: opener.hasAttribute('download'),
    };
}

/**
 * Listens for clicks on links to the Menu. open(opener, action) does what menuLinkAction() decided ('panel', 'overlay',
 * 'focus', 'toggle') and returns true, or false when it cannot (the click then goes on to the page). surface() tells
 * this screen's Menu surface (default: currentMenuSurface()). Returns the function that stops listening.
 */
export function listenForMenuLinks({ document, window, paths, open, surface = null }) {
    const surfaceOf = typeof surface === 'function' ? surface : () => currentMenuSurface(window, document);

    const onClick = (event) => {
        const opener = menuOpenerOf(event.target, paths, window.location.href);

        if (!opener) {
            return;
        }

        const action = menuLinkAction(isNativeApp(document.documentElement), surfaceOf(), clickFacts(event, opener), opener.getAttribute('data-bar-action') === 'menu');

        if (action === 'page') {
            return;
        }

        if (open(opener, action) === true) {
            event.preventDefault();
        }
    };

    document.addEventListener('click', onClick);

    return () => document.removeEventListener('click', onClick);
}
