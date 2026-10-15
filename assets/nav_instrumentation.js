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
 * navigation: a Turbo restore (turboRestore() before a restore turns into a full load - that reload is a replace, so
 * it is not counted again), a page loaded by a traverse, or a page shown from the back/forward cache. Since GEST-08 the
 * app starts it with `history: false` and assets/back_measurement.js sends that one line per Back instead, with how the
 * page came back (back(): kind `back` - restore snapshot | reload | bfcache | bfcache-reload, ms_to_load). The GEST-13
 * watchdog (assets/confirm_dialog.js) reports a native dialog an iPhone refused through dialogSuppressed(). Each open of
 * the Menu (MENU-02b: the phone panel; NAV-13: the desktop overlay) is one `menu_open` line: the Menu's controller
 * dispatches `msp:menu-open` {surface, place} on the document (MENU_OPEN_EVENT), this module sends it. A page search
 * that found nothing at all (MENU-03: no page, no player, no puzzle) is one `search_zero` line: the search dispatches
 * `msp:search-zero` {surface, q} (SEARCH_ZERO_EVENT) with the folded query, cut to 40 characters. The desktop ☰
 * docking or folding the Menu column (NAV-13) is one `layout` line {state, bucket}: assets/navigation/nav_layout.js
 * dispatches `msp:nav-layout` {layout, width} (NAV_LAYOUT_EVENT); the viewport line carries the state the session
 * started in (`dock`: docked | rail | none, read from the column's width --nav-left - D16's fold share).
 * The player card (CARD-07) is measured per open: assets/player_card_layer.js dispatches `msp:card-open` {context,
 * input} (CARD_OPEN_EVENT) and exactly one `msp:card-outcome` {context, input, outcome, ms} (CARD_OUTCOME_EVENT,
 * assets/player_card_measure.js), assets/player_card.js `msp:card-bypass` {context, input} (CARD_BYPASS_EVENT) for a
 * trigger clicked with a modifier or the middle button; each is one line (card_open, card_outcome, card_bypass). The
 * members modal (TEASE-01, assets/controllers/members_modal_controller.js) dispatches `msp:members-modal` {placement}
 * (MEMBERS_MODAL_EVENT) when it opens - one `members_modal` line with the teaser's placement, 'none' without one.
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

/** The document event the Menu dispatches when it opens (menu_layer_controller.js), detail {surface, place} */
export const MENU_OPEN_EVENT = 'msp:menu-open';

/** Where the Menu was opened: the phone panel (MENU-02b) or the desktop overlay (NAV-13) */
export const MENU_SURFACES = ['panel', 'overlay'];

/**
 * The document event of a page search without any result (nav_search_controller.js; where_did_it_go_controller.js for
 * a filter of "Where did it go?" that leaves nothing), detail {surface, q}
 */
export const SEARCH_ZERO_EVENT = 'msp:search-zero';

/**
 * Which search found nothing: the Menu panel's field or the page's / top bar's (MENU-03, SRCH-01), the filter of
 * "Where did it go?" (LAUNCH review Q3)
 */
export const SEARCH_SURFACES = ['menu', 'topbar', 'wdig'];

/** The longest query a search_zero line carries (decision 39) - NavClickController::MAX_QUERY_LENGTH */
export const SEARCH_QUERY_MAX = 40;

/** The document event of the desktop ☰ docking / folding the Menu (assets/navigation/nav_layout.js), detail {layout, width} */
export const NAV_LAYOUT_EVENT = 'msp:nav-layout';

/** The desktop Menu's states (NAV-13) - NavClickController::NAV_LAYOUTS */
export const NAV_LAYOUTS = ['docked', 'rail'];

/** The player card (CARD-07): an open and its one outcome (assets/player_card_layer.js), detail {context, input[, outcome, ms]} */
export const CARD_OPEN_EVENT = 'msp:card-open';
export const CARD_OUTCOME_EVENT = 'msp:card-outcome';

/** A card trigger clicked with a modifier or the middle button - no card, a new tab (assets/player_card.js) */
export const CARD_BYPASS_EVENT = 'msp:card-bypass';

/** The card's context kinds (PlayerCardContextKind, 'none' without one) - NavClickController::CARD_CONTEXTS */
export const CARD_CONTEXTS = ['none', 'puzzle', 'feed', 'notification', 'round', 'leaderboard'];

/** How the card was asked for (player_card_rules.js inputOf()) - NavClickController::CARD_INPUTS */
export const CARD_INPUTS = ['mouse', 'touch', 'pen', 'keyboard'];

/** What an open of the card ended in (assets/player_card_measure.js) - NavClickController::CARD_OUTCOMES */
export const CARD_OUTCOMES = ['continue', 'deep', 'puzzle', 'action', 'bounce', 'close'];

/** The longest time to an outcome a card_outcome line carries (a day, ms) - NavClickController::CARD_MS_MAX */
export const CARD_MS_MAX = 86400000;

/** The members modal opened (TEASE-01, members_modal_controller.js), detail {placement} */
export const MEMBERS_MODAL_EVENT = 'msp:members-modal';

/** A teaser's placement id (data-members-placement - the server knows them, MembersPlacement); 'none' without one */
export const MEMBERS_PLACEMENT_PATTERN = /^[a-z]+\.[a-z_]+$/;

/** GEST-08: how a page came back by a Back / Forward (NavClickController::RESTORE_KINDS) */
export const BACK_RESTORE_KINDS = ['snapshot', 'reload', 'bfcache', 'bfcache-reload'];

/** The longest ms_to_load logged (10 minutes; NavClickController::BACK_MS_MAX) - anything else is null */
export const BACK_MS_MAX = 600000;

/** The page's place (NavPlace) - the same list as NavClickController::PLACES */
export const PLACES = ['home', 'puzzles', 'add', 'community', 'profile', 'none'];

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

/**
 * The desktop Menu on this screen (NAV-13): 'docked' (the 256 px column), 'rail' (88 px) or 'none' (phones, 768-1023 px,
 * pages without the bars) - read from the column's width --nav-left on <body>, which CSS alone sets
 */
export function dockState(window) {
    try {
        const body = window.document && window.document.body;
        const width = body && typeof window.getComputedStyle === 'function' ? parseFloat(window.getComputedStyle(body).getPropertyValue('--nav-left')) : NaN;

        if (!(width > 0)) {
            return 'none';
        }

        return width >= 200 ? 'docked' : 'rail';
    } catch (e) {
        return 'none';
    }
}

export function pointerType(window) {
    if (matches(window, '(pointer: coarse)')) return 'coarse';
    if (matches(window, '(pointer: fine)')) return 'fine';

    return 'none';
}

/** The history position: the Navigation API's, else the one Turbo keeps in history.state */
export function historyIndex(window) {
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

export function direction(from, to) {
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
export function activationDirection(activation, currentIndex = null) {
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
    // P0-03's own Back/Forward lines - off once GEST-08's back measurement sends them (app.js)
    const historyLines = env.history !== false;

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
            dock: dockState(window),
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
        if (!event.persisted || !historyLines) {
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

    function onMenuOpen(event) {
        api.menuOpen(event && event.detail ? event.detail : {});
    }

    function onSearchZero(event) {
        api.searchZero(event && event.detail ? event.detail : {});
    }

    function onNavLayout(event) {
        api.layoutChanged(event && event.detail ? event.detail : {});
    }

    function onCardOpen(event) {
        api.cardOpen(event && event.detail ? event.detail : {});
    }

    function onCardOutcome(event) {
        api.cardOutcome(event && event.detail ? event.detail : {});
    }

    function onCardBypass(event) {
        api.cardBypass(event && event.detail ? event.detail : {});
    }

    function onMembersModal(event) {
        api.membersModal(event && event.detail ? event.detail : {});
    }

    const api = {
        /** A Turbo restore visit, right before it turns into a full page load (P0-03; GEST-08 sends back() instead) */
        turboRestore() {
            if (!historyLines) {
                return;
            }

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

        /**
         * GEST-08 (assets/back_measurement.js): one line per Back / Forward with how the page came back - `restore`
         * snapshot | reload | bfcache | bfcache-reload, `ms` to load (whole ms, null when unknown), `dir` and whether the
         * browser animated it itself. Anything outside the lists sends nothing.
         */
        back(detail = {}) {
            if (!BACK_RESTORE_KINDS.includes(detail.restore)) {
                return;
            }

            const ms = Number.isInteger(detail.ms) && detail.ms >= 0 && detail.ms <= BACK_MS_MAX ? detail.ms : null;

            send({
                k: 'back',
                restore: detail.restore,
                ms,
                dir: ['back', 'forward', 'unknown'].includes(detail.dir) ? detail.dir : 'unknown',
                uaTransition: typeof detail.uaTransition === 'boolean' ? detail.uaTransition : null,
            });
        },

        /**
         * assets/confirm_dialog.js (GEST-13 watchdog): a native confirm dialog answered `false` at once on an iPhone - a
         * dialog the browser refused to show. Once per page view (the watchdog counts); whether a layer was closed by
         * Back before and whether the page was restored by a Back/Forward (true/false today, GEST-15's restore kind).
         */
        dialogSuppressed(detail = {}) {
            const afterRestore = detail.afterRestore;

            send({
                k: 'dialog_suppressed',
                afterLayerBack: detail.afterLayerBack === true,
                afterRestore: typeof afterRestore === 'boolean' || typeof afterRestore === 'string' ? afterRestore : null,
            });
        },

        /**
         * MENU-02b / NAV-13: the Menu was opened - where (panel | overlay) and on which place's page. Anything outside
         * those lists sends nothing.
         */
        menuOpen(detail = {}) {
            if (!MENU_SURFACES.includes(detail.surface)) {
                return;
            }

            send({ k: 'menu_open', surface: detail.surface, place: PLACES.includes(detail.place) ? detail.place : 'none' });
        },

        /**
         * MENU-03 / SRCH-01: a page search found nothing - which field, and the query (cut to 40 characters; the search
         * sends it folded). An unknown field or an empty query sends nothing.
         */
        searchZero(detail = {}) {
            if (!SEARCH_SURFACES.includes(detail.surface) || typeof detail.q !== 'string') {
                return;
            }

            const q = Array.from(detail.q.trim()).slice(0, SEARCH_QUERY_MAX).join('');

            if (q === '') {
                return;
            }

            send({ k: 'search_zero', surface: detail.surface, q });
        },

        /**
         * NAV-13: the desktop ☰ docked the Menu column or folded it to the rail - the new state and the width bucket.
         * Anything else sends nothing.
         */
        layoutChanged(detail = {}) {
            if (!NAV_LAYOUTS.includes(detail.layout)) {
                return;
            }

            const width = typeof detail.width === 'number' && Number.isFinite(detail.width) ? detail.width : window.innerWidth;

            send({ k: 'layout', state: detail.layout, bucket: viewportBucket(width) });
        },

        /**
         * CARD-07: a player card opened - its context kind and the input that asked for it (a prefetch is no open).
         * Anything outside the lists sends nothing.
         */
        cardOpen(detail = {}) {
            if (!CARD_CONTEXTS.includes(detail.context) || !CARD_INPUTS.includes(detail.input)) {
                return;
            }

            send({ k: 'card_open', context: detail.context, input: detail.input });
        },

        /**
         * CARD-07: what one open of the card ended in, and after how long (ms; null when it is not a sane number).
         * Anything outside the lists sends nothing.
         */
        cardOutcome(detail = {}) {
            if (!CARD_CONTEXTS.includes(detail.context) || !CARD_INPUTS.includes(detail.input) || !CARD_OUTCOMES.includes(detail.outcome)) {
                return;
            }

            const ms = Number.isInteger(detail.ms) && detail.ms >= 0 && detail.ms <= CARD_MS_MAX ? detail.ms : null;

            send({ k: 'card_outcome', context: detail.context, input: detail.input, outcome: detail.outcome, ms });
        },

        /** CARD-07: a card trigger opened in a new tab or window instead (a modifier or the middle button) */
        cardBypass(detail = {}) {
            if (!CARD_CONTEXTS.includes(detail.context) || !CARD_INPUTS.includes(detail.input)) {
                return;
            }

            send({ k: 'card_bypass', context: detail.context, input: detail.input });
        },

        /** TEASE-01: the members modal opened - from which teaser (its placement), 'none' from a lock without one */
        membersModal(detail = {}) {
            const placement = typeof detail.placement === 'string' && MEMBERS_PLACEMENT_PATTERN.test(detail.placement) ? detail.placement : 'none';

            send({ k: 'members_modal', placement });
        },

        start() {
            document.addEventListener('click', onClick, { capture: true, passive: true });
            document.addEventListener(MENU_OPEN_EVENT, onMenuOpen);
            document.addEventListener(SEARCH_ZERO_EVENT, onSearchZero);
            document.addEventListener(NAV_LAYOUT_EVENT, onNavLayout);
            document.addEventListener(CARD_OPEN_EVENT, onCardOpen);
            document.addEventListener(CARD_OUTCOME_EVENT, onCardOutcome);
            document.addEventListener(CARD_BYPASS_EVENT, onCardBypass);
            document.addEventListener(MEMBERS_MODAL_EVENT, onMembersModal);
            // Capture at the window - which in Chromium is not before Turbo's own listener (a window's listeners run in the
            // order they were added, and Turbo adds its own when it starts): the Navigation API's navigate event comes first
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

            if (historyLines) {
                sendLoadedByTraverse();
            }

            return api;
        },
    };

    return api;
}

export function startNavInstrumentation(env) {
    return createNavInstrumentation(env).start();
}
