/**
 * Navigation instrumentation (docs/features/navigation, P0-03).
 *
 * Every link and button of the site chrome carries data-nav="<surface>/<place>/<item>". One delegated click listener
 * turns a click on one of them into
 *  - a `msp:nav` DOM event, detail {surface, place, item}, bubbling from the clicked element;
 *  - a GA4 `nav_click` event - only once GA is loaded (analytics_controller.js loads it after the first interaction);
 *  - a beacon to POST /-/nav-click (NavClickController), one info line on the `navigation` log channel.
 * Only `a[data-nav]` and `button[data-nav]` count, and a value outside the grammar is dropped (the <html> element
 * carries data-nav-layout, never data-nav).
 *
 * Once per browser session it also sends the innerWidth bucket and the pointer type, and one line per Back/Forward
 * navigation: a Turbo restore (app.js calls turboRestore() before it reloads the page - that reload is a replace, so
 * it is not counted again), a page loaded by a traverse, or a page shown from the back/forward cache.
 *
 * Every beacon carries the page's path, never its query string: loggablePage() replaces a path segment that looks like a
 * secret (a password-reset or account-deletion link, a credential claim, a newsletter or e-mail preferences token...)
 * with {token} before anything is sent, so a secret never leaves the browser. NavClickController::loggablePage() is the
 * authoritative copy of the rule; a page outside the grammar is not sent at all (the server would drop it).
 *
 * A pure module: whatever it touches comes in through `env` ({window, document, navigator?, storage?}), so
 * tests/nav-instrumentation-harness.mjs runs it under node. Nothing here may throw into the page.
 */

// JS has no trailing-newline exception: without the m flag $ is the end of the string (\z in NavClickController)
export const NAV_PATTERN = /^[a-z_]+\/[a-z_]+\/[a-z0-9_]+$/;
// RFC 3986 path characters (a browser percent-encodes everything else) and whole {token} segments
export const PAGE_PATTERN = /^(?:\/(?:\{token\}|[A-Za-z0-9\-._~%!$&'()*+,;=:@]*))+$/;
export const MASKED_SEGMENT = '{token}';
export const BEACON_URL = '/-/nav-click';
export const SESSION_KEY = 'msp:nav-session';

// 20+ characters of the token alphabets that are neither a UUID nor a slug (lower-case words joined by dashes)
const TOKEN_SEGMENT = /^[A-Za-z0-9._-]{20,}$/;
const UUID_SEGMENT = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/;
const SLUG_SEGMENT = /^[a-z0-9]+(?:-[a-z0-9]+)+(?:\.[a-z0-9]+)?$/;

const MAX_NAV_LENGTH = 100;
const MAX_PAGE_LENGTH = 300;

// requirements, Conventions: "desktop" in Media Queries Level 3 syntax; everything else is a phone
export const DESKTOP_QUERY = '(min-width: 768px) and (min-height: 501px), (min-width: 768px) and (pointer: fine)';

/** {surface, place, item} of a well-formed data-nav value, null for anything else */
export function parseNav(value) {
    if (typeof value !== 'string' || value.length > MAX_NAV_LENGTH || !NAV_PATTERN.test(value)) {
        return null;
    }

    const [surface, place, item] = value.split('/');

    return { surface, place, item };
}

function maskedSegment(segment) {
    return TOKEN_SEGMENT.test(segment) && !UUID_SEGMENT.test(segment) && !SLUG_SEGMENT.test(segment) ? MASKED_SEGMENT : segment;
}

/** location.pathname as it is logged: every secret segment replaced by {token}; null = not a path we send */
export function loggablePage(pathname) {
    if (typeof pathname !== 'string' || !PAGE_PATTERN.test(pathname)) {
        return null;
    }

    const page = pathname.split('/').map(maskedSegment).join('/');

    return page.length <= MAX_PAGE_LENGTH ? page : null;
}

/** < 768 / 768–1023 / 1024–1279 / 1280–1439 / 1440–1919 / ≥ 1920 */
export function viewportBucket(width) {
    if (width < 768) return 'lt768';
    if (width < 1024) return '768_1023';
    if (width < 1280) return '1024_1279';
    if (width < 1440) return '1280_1439';
    if (width < 1920) return '1440_1919';

    return 'gte1920';
}

function matches(window, query) {
    try {
        return Boolean(window.matchMedia && window.matchMedia(query).matches);
    } catch (e) {
        return false;
    }
}

export function layoutTier(window) {
    return matches(window, DESKTOP_QUERY) ? 'desktop' : 'phone';
}

export function pointerType(window) {
    if (matches(window, '(pointer: coarse)')) return 'coarse';
    if (matches(window, '(pointer: fine)')) return 'fine';

    return 'none';
}

/** The history position: the Navigation API's, else the one Turbo keeps in history.state */
function historyIndex(window) {
    try {
        const entry = window.navigation && window.navigation.currentEntry;

        if (entry && typeof entry.index === 'number' && entry.index >= 0) {
            return entry.index;
        }

        const turbo = window.history && window.history.state && window.history.state.turbo;

        return turbo && typeof turbo.restorationIndex === 'number' ? turbo.restorationIndex : null;
    } catch (e) {
        return null;
    }
}

function direction(from, to) {
    if (typeof from !== 'number' || typeof to !== 'number' || from < 0 || to < 0 || from === to) {
        return 'unknown';
    }

    return to < from ? 'back' : 'forward';
}

/**
 * Back or Forward of a document's load or back/forward-cache restore: from the entry left to the entry shown. The
 * activation's `entry` reads index -1 once the page replaced it (Turbo's history.replaceState when it starts - measured
 * in Chromium): the current entry, passed as currentIndex, is that same place then.
 */
function activationDirection(activation, currentIndex = null) {
    const entry = activation.entry && typeof activation.entry.index === 'number' && activation.entry.index >= 0 ? activation.entry.index : currentIndex;

    return direction(activation.from ? activation.from.index : null, entry);
}

function currentEntryIndex(window) {
    try {
        const entry = window.navigation && window.navigation.currentEntry;

        return entry && typeof entry.index === 'number' ? entry.index : null;
    } catch (e) {
        return null;
    }
}

export function createNavInstrumentation(env) {
    const { window, document } = env;
    const navigator = env.navigator || window.navigator || {};

    // Where the last page was shown (each turbo:load), to tell Back from Forward on a restore
    let lastIndex = historyIndex(window);
    // hasUAVisualTransition of the traverse in progress (popstate / Navigation API navigate fire before Turbo's visit)
    let traverse = null;

    function storage() {
        try {
            return env.storage !== undefined ? env.storage : window.sessionStorage;
        } catch (e) {
            return null;
        }
    }

    function send(payload) {
        try {
            const page = loggablePage(window.location.pathname);

            if (typeof navigator.sendBeacon !== 'function' || page === null) {
                return;
            }

            navigator.sendBeacon(BEACON_URL, JSON.stringify({
                v: 1,
                ...payload,
                page,
                layout: layoutTier(window),
                // Counted, not dropped: Loki filters the bots
                wd: navigator.webdriver === true,
            }));
        } catch (e) {
            // A lost beacon is a lost count, never a broken click
        }
    }

    function onClick(event) {
        const target = event.target;

        if (!target || typeof target.closest !== 'function') {
            return;
        }

        const element = target.closest('a[data-nav], button[data-nav]');

        if (!element) {
            return;
        }

        const value = element.getAttribute('data-nav');
        const detail = parseNav(value);

        if (detail === null) {
            return;
        }

        try {
            element.dispatchEvent(new window.CustomEvent('msp:nav', { bubbles: true, detail }));
        } catch (e) {
            // a listener's failure is reported by the browser, the count still goes out
        }

        if (window.gaLoaded === true && typeof window.gtag === 'function') {
            try {
                window.gtag('event', 'nav_click', {
                    nav_id: value,
                    nav_surface: detail.surface,
                    nav_place: detail.place,
                    nav_item: detail.item,
                    nav_layout: layoutTier(window),
                });
            } catch (e) {
                // GA is optional
            }
        }

        send({ k: 'click', nav: value });
    }

    function sendViewportOncePerSession() {
        const store = storage();

        // No session storage, no way to keep it to one line per session: nothing is sent
        try {
            if (!store || store.getItem(SESSION_KEY) !== null) {
                return;
            }

            store.setItem(SESSION_KEY, '1');
        } catch (e) {
            return;
        }

        send({
            k: 'viewport',
            bucket: viewportBucket(window.innerWidth),
            pointer: pointerType(window),
            standalone: matches(window, '(display-mode: standalone)') || navigator.standalone === true,
        });
    }

    function sendLoadedByTraverse() {
        try {
            const activation = window.navigation && window.navigation.activation;

            if (activation && typeof activation.navigationType === 'string') {
                if (activation.navigationType === 'traverse') {
                    send({ k: 'history', via: 'load', dir: activationDirection(activation, currentEntryIndex(window)), uaTransition: null });
                }

                return;
            }

            // No Navigation API: the navigation timing entry knows a back/forward load, not its direction
            const entries = window.performance && window.performance.getEntriesByType
                ? window.performance.getEntriesByType('navigation')
                : [];

            if (entries[0] && entries[0].type === 'back_forward') {
                send({ k: 'history', via: 'load', dir: 'unknown', uaTransition: null });
            }
        } catch (e) {
            // no history line
        }
    }

    // The navigate event comes first; a popstate without the property must not erase what it knew
    function rememberTraverse(event) {
        const uaTransition = typeof event.hasUAVisualTransition === 'boolean' ? event.hasUAVisualTransition : null;

        if (uaTransition !== null || traverse === null) {
            traverse = { uaTransition };
        }
    }

    function onNavigate(event) {
        if (event.navigationType === 'traverse') {
            rememberTraverse(event);
        }
    }

    function onPageShow(event) {
        if (!event.persisted) {
            return;
        }

        let dir = 'unknown';

        try {
            const activation = window.navigation && window.navigation.activation;
            dir = activation ? activationDirection(activation, currentEntryIndex(window)) : 'unknown';
        } catch (e) {
            // unknown
        }

        send({ k: 'history', via: 'bfcache', dir, uaTransition: null });
        lastIndex = historyIndex(window);
    }

    function onTurboLoad() {
        lastIndex = historyIndex(window);
        traverse = null;
    }

    const api = {
        /** app.js: a Turbo restore visit, right before it turns it into a full page load */
        turboRestore() {
            const index = historyIndex(window);

            send({
                k: 'history',
                via: 'turbo_restore',
                dir: direction(lastIndex, index),
                uaTransition: traverse ? traverse.uaTransition : null,
            });

            lastIndex = index;
            traverse = null;
        },

        start() {
            document.addEventListener('click', onClick, { capture: true, passive: true });
            // Capture: at the window, capture listeners run before Turbo's popstate listener starts the restore
            window.addEventListener('popstate', rememberTraverse, { capture: true });
            window.addEventListener('pageshow', onPageShow);
            document.addEventListener('turbo:load', onTurboLoad);

            try {
                if (window.navigation && typeof window.navigation.addEventListener === 'function') {
                    window.navigation.addEventListener('navigate', onNavigate);
                }
            } catch (e) {
                // no Navigation API
            }

            sendViewportOncePerSession();
            sendLoadedByTraverse();

            return api;
        },
    };

    return api;
}

export function startNavInstrumentation(env) {
    return createNavInstrumentation(env).start();
}
