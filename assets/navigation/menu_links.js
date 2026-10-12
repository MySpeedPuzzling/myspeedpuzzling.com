import { isNativeApp } from '../platform.js';

// Links to the Menu (docs/features/navigation README §4.12 "Fallback page", MENU-02b): the route `menu` is a real page -
// for no JS, a new tab, deep links and the native app - and with JS on the web a link to it opens the Menu instead.
// On phones that is the panel (MENU-02b); the desktop column and overlay are NAV-13's. The ☰ of the top bar
// (data-bar-action="menu", NAV-03) is such a link too.
//
// One delegated click listener on the document (bubble phase: it runs before Turbo's own listener on the window and
// takes the click with preventDefault(), so Turbo never visits the page). A click the browser should keep - another
// button, a modifier key, a new tab, a download - and every click inside the native app go to the page as they are.
//
// The paths of `menu` in every locale come from the shell's data-menu-paths (rendered by the server, percent-encoded
// as path() writes them); a link matches by its path, whatever its query string or fragment.

/** Phones in Media Queries Level 3 syntax (requirements, Conventions; $nav-phone-media in _nav-tokens.scss) */
export const PHONE_QUERY = '(max-width: 767.98px), (max-height: 500.98px) and (pointer: coarse)';

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
 * 'page' - the browser follows the link to the `menu` page (the native app always, a click it should keep, desktop
 * until NAV-13); 'panel' - the phone panel opens.
 * click: {button, modified (Ctrl/Cmd/Shift/Alt), defaultPrevented, newTab (a target other than _self), download}
 */
export function menuLinkAction(nativeApp, phone, click = {}) {
    if (nativeApp) {
        return 'page';
    }

    if (click.defaultPrevented || (click.button || 0) > 0 || click.modified || click.newTab || click.download) {
        return 'page';
    }

    return phone ? 'panel' : 'page';
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
 * Listens for clicks on links to the Menu. open(opener) shows the surface and returns true, or false when it cannot
 * (the click then goes on to the page). Returns the function that stops listening.
 */
export function listenForMenuLinks({ document, window, paths, open }) {
    const onClick = (event) => {
        const opener = menuOpenerOf(event.target, paths, window.location.href);

        if (!opener) {
            return;
        }

        const phone = typeof window.matchMedia === 'function' && window.matchMedia(PHONE_QUERY).matches;

        if (menuLinkAction(isNativeApp(document.documentElement), phone, clickFacts(event, opener)) !== 'panel') {
            return;
        }

        if (open(opener) === true) {
            event.preventDefault();
        }
    };

    document.addEventListener('click', onClick);

    return () => document.removeEventListener('click', onClick);
}
