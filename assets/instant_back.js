import { isNativeApp, os } from './platform.js';

/**
 * Instant Back (docs/features/navigation README §4.13 G9; requirements GEST-14, GEST-15; decisions 64, 65, 66, 91, 99).
 * A Back or Forward comes back at once, at the same place - where that is safe - and is a full load everywhere else.
 *
 * GEST-14 - the browser's back/forward cache (pwa_lifecycle_controller.js asks bfcacheVerdict() on `pageshow`):
 *   a page the browser kept frozen is shown as it was, unless it was away longer than 10 minutes, carries
 *   [data-bfcache-reload] (stopwatch, live results entry, results desk, participants sheet, round stopwatch,
 *   membership/checkout, conversation detail), the viewer changed meanwhile (<meta name="msp-viewer"> vs localStorage
 *   `msp:viewer`), a POST was sent from the document, it is an iPhone while INSTANT_BACK_IOS is false, or it is the
 *   Hotwire Native app (today's unconditional reload there). `pagehide` stamps the time per document in sessionStorage
 *   (`msp:pagehide:<document id>`, with an in-memory fallback).
 *
 * GEST-15 - Turbo's own snapshot cache, for the browse pages that opt in (<meta name="turbo-cache-control"
 *   content="no-preview">: Turbo caches them and uses the cache for restores only): a restore visit renders the snapshot
 *   only when INSTANT_BACK is on, a snapshot of that URL was cached less than 10 minutes ago for the same viewer
 *   (the ledger below, filled at turbo:before-cache) and it is not an iPhone with INSTANT_BACK_IOS off - otherwise
 *   `location.href = url`, today's full load (marked for PROF-05c's scroll restore: msp:restore-reload). Inside the app
 *   the restore is left to the native adapter. `scroll-behavior: auto` holds on <html> from that restore's turbo:visit to
 *   one frame after turbo:load; the view-transition gate never animates it (GEST-12).
 *
 * What Turbo caches is a clean copy of the page: no open layer (Menu panel, sheets, card, search view, dropdowns,
 * modals, popovers, menus), no announcement modal (LAUNCH review Q2: it shows once, never again from a restored page),
 * no widget that builds its own DOM (TomSelect, flatpickr, charts - their owners tear them down), no ripple or
 * celebration left. **The live page is never changed before a view transition captured it** (GEST-12's Android fade,
 * GEST-17's thumbnail morph - the search view must still be on screen when the old page is captured): Turbo 8.0.23
 * dispatches turbo:before-cache inside the frame that starts the view transition and clones the page one task later
 * (nextEventLoopTick). So the cache work - layer_history.closeForCache(), `msp:before-cache` for the layers and widget
 * owners, sanitizeSnapshot() - runs at once when no view transition will capture the page, and in the task right after
 * turbo:before-cache when one will (cacheWorkTiming()): after the capture of that frame, before Turbo's clone, which
 * was queued after it. A restore re-checks the copy before showing it (sanitizeSnapshot() on turbo:before-render's
 * new body) - the net for an order no browser should produce.
 *
 * Staleness (decision 65): a successful non-GET form submission (turbo:submit-end), any other successful same-origin
 * write made with fetch() (a Live component action, a dismissed hint, a post_action - isWriteRequest()) and a click on a
 * [data-clears-snapshots] link (GET mutations: the Favorite toggles, a delete link, sign-out) clear Turbo's cache and the
 * ledger, and tell every tab (localStorage `msp:last-write`; other tabs drop their copies on its `storage` event).
 * A page left *through* a write is never cached (Instant Back review F1): a visit started by a write - a
 * [data-clears-snapshots] link, a click that wrote on its way (a beacon), or a visit no user click started while a write
 * is still unrendered (a Live action's redirect, a modal form answered with a page, a post_action) - switches the page's
 * own `turbo-cache-control` to `no-cache` before Turbo would copy it, and the write is noted again once that visit's
 * page rendered: no copy taken before or around the write survives. A restore also reloads when the viewer stored by
 * the last page this browser loaded (any tab: a sign-out elsewhere) is not the copy's (F3).
 * A page too large to copy within a frame (CLONE_BUDGET_UNITS, F11) leaves through the full-load path the same way.
 * Ages use Date.now() only (the restore check can be tested by shifting Date.now).
 *
 * Pure decisions (bfcacheVerdict, restoreDecision, cacheWorkTiming, viewTransitionExpected, createSnapshotLedger,
 * createRestoreReload, sanitizeSnapshot) are node-tested in tests/instant-back-harness.mjs; createInstantBack(env) wires
 * them to the browser (assets/app.js installs the browser instance).
 */

// ---- The switches (docs/features/feature_flags.md) -----------------------------------------------------------------

/** GEST-15 revert: false = every Back after a Turbo visit is a full page load again (today's rule) */
export const INSTANT_BACK = true;

/**
 * Instant Back on iPhones (decision 66): true only when GEST-13 (the in-page confirmations) is verified - it is (state.md,
 * 2f8235c0), so iPhones get it too. The owner may set it false after the device checks (DC1, DC7).
 */
export const INSTANT_BACK_IOS = true;

/** GEST-14 revert: false = every back/forward-cache restore reloads the page (the old unconditional reload) */
export const KEEP_BFCACHE = true;

/** A page away longer than this comes back by a full load, from either cache */
export const MAX_AGE_MS = 10 * 60 * 1000;

/** Turbo keeps snapshots of pages to restore (PROF-05c: the scroll Turbo records for an entry matters then) */
export const SNAPSHOT_RESTORES = INSTANT_BACK;

/** Turbo 8.0.23's SnapshotCache size: the ledger forgets what Turbo forgets */
export const SNAPSHOT_LIMIT = 10;

export const PAGEHIDE_KEY_PREFIX = 'msp:pagehide:';
export const VIEWER_KEY = 'msp:viewer';
export const VIEWER_META = 'msp-viewer';
/** A guest's page has no msp-viewer meta: its body says it is a guest's (MENU-01, "everyone guest") */
export const VIEWER_AUDIENCE_ATTRIBUTE = 'data-viewer-audience';
const GUEST_AUDIENCE = /(^|\s)guest(\s|$)/;
export const RESTORE_RELOAD_KEY = 'msp:restore-reload';
export const BFCACHE_RELOAD_ATTRIBUTE = 'data-bfcache-reload';
export const CLEARS_SNAPSHOTS_ATTRIBUTE = 'data-clears-snapshots';
/** When this browser last wrote (localStorage, every tab and document): a copy taken before it never comes back */
export const LAST_WRITE_KEY = 'msp:last-write';
/** A page whose GET wrote (notifications marked read, a stopwatch started, a conversation read): <meta name=…> */
export const CLEARS_SNAPSHOTS_META = 'msp-clears-snapshots';
/** Marks window.fetch once it is watched for writes (watchFetchWrites) */
const FETCH_WATCHED = '__mspInstantBackWrites';

/** Dispatched on window when a fresh back/forward-cache restore is kept (GEST-14) */
export const BFCACHE_RESTORE_EVENT = 'msp:bfcache-restore';
/** Dispatched on window right before a back/forward-cache restore reloads the page (GEST-08 measures it) */
export const BFCACHE_RELOAD_EVENT = 'msp:bfcache-reload';
/** Dispatched on document when a restore visit was decided: {kind: 'snapshot' | 'reload', url} (GEST-08) */
export const RESTORE_DECIDED_EVENT = 'msp:restore-decided';
/**
 * Dispatched on document when Turbo is about to clone the page for its cache - after a view transition captured it
 * (see above): layers close silently, widget owners tear down. Never listen to turbo:before-cache for that.
 */
export const BEFORE_CACHE_EVENT = 'msp:before-cache';

/** Turbo's cache-control values (<meta name="turbo-cache-control">) */
export const CACHE_CONTROL_META = 'turbo-cache-control';
export const NO_CACHE = 'no-cache';
export const NO_PREVIEW = 'no-preview';

/**
 * A page too large to copy within a frame is never copied for a restore (Instant Back review F11): Turbo's clone of it
 * would be one long task right when the next page starts. Measured at 4x CPU throttling (Pixel 360, every opted-in page
 * in its largest realistic state - docs/features/navigation README §12 "Instant Back as built"): body.cloneNode(true)
 * costs ~2.7 µs per element and ~125 µs per picture (a cloned <img> looks its image up again), so a page weighs its
 * elements plus PICTURE_UNITS per picture, and over CLONE_BUDGET_UNITS (≈ 16 ms, one frame) it leaves through the
 * full-load path: its live meta says no-cache at turbo:visit (PROF-05c brings the Results rows and scroll back).
 */
export const CLONE_BUDGET_UNITS = 6000;
export const PICTURE_UNITS = 48;

/** Opened <details> menus closed for the cache (the language menus, the card's "More", the in-place confirmations) */
export const MENU_DETAILS_SELECTOR = 'details.nav-account-language[open], details.menu-language[open], details.site-header-language[open], details.players-card-more[open], details.ev-confirm[open]';

/** Classes the layers put on <body> while open */
export const BODY_LAYER_CLASSES = ['modal-open', 'global-search-shown'];

/** Layers and notes script builds into the page that a restored copy's controllers would not know (F2, F7) */
export const SCRIPT_BUILT_LAYERS = '.ev-menu-layer, .ev-follow-note';

/**
 * Built on <body> again by their controllers when a copy is shown (or never needed by it): removed from the copy, never
 * from the live page - the pull-to-refresh indicator (pwa_lifecycle_controller.js), the photo editor's modal (made by
 * image_editor_controller.js in an edit form of the modal frame, which a shown copy holds empty)
 */
export const REBUILT_ON_CONNECT = '.pwa-ptr-indicator, #imageEditorModal';

export const MODAL_FRAME_ID = 'modal-frame';

/** What celebrations leave on the page */
const CELEBRATION_CLASSES = ['is-celebrating', 'is-leveled'];
const CELEBRATION_HOSTS = '.puzzle-picker-confetti-host';

// ---- GEST-14: the back/forward cache ---------------------------------------------------------------------------------

/**
 * What a `pageshow` does: 'none' (a normal load), 'keep' (show the frozen page as it is) or 'reload'.
 *
 * facts: {persisted, awayMs (null = unknown), reloadMarker, os, native, viewerChanged, postedFrom, wroteSince (this
 *         browser wrote after the page was hidden - in another document or tab), keepBfcache?, instantBackIos?, maxAgeMs?}
 */
export function bfcacheVerdict(facts = {}) {
    const {
        persisted,
        awayMs,
        reloadMarker,
        os: device,
        native,
        viewerChanged,
        postedFrom,
        wroteSince,
        keepBfcache = KEEP_BFCACHE,
        instantBackIos = INSTANT_BACK_IOS,
        maxAgeMs = MAX_AGE_MS,
    } = facts || {};

    if (persisted !== true) {
        return 'none';
    }

    // Inside the app: today's unconditional reload (the app's own navigation decides what it shows)
    if (native || !keepBfcache || reloadMarker || viewerChanged || postedFrom || wroteSince) {
        return 'reload';
    }

    if (device === 'ios' && !instantBackIos) {
        return 'reload';
    }

    if (typeof awayMs !== 'number' || !Number.isFinite(awayMs) || awayMs < 0 || awayMs > maxAgeMs) {
        return 'reload';
    }

    return 'keep';
}

export function pagehideKey(documentId) {
    return PAGEHIDE_KEY_PREFIX + documentId;
}

// ---- GEST-15: Turbo's snapshots ---------------------------------------------------------------------------------------

/**
 * How a restore visit comes back: 'leave' (inside the app - the native adapter's), 'snapshot' (Turbo renders its cached
 * copy) or 'reload' (today's full load).
 *
 * facts: {native, instantBack?, instantBackIos?, os, entry ({cachedAt, viewer} | null), now, viewer, turboHasSnapshot?,
 *         lastWriteAt (when this browser last wrote, any tab - null: never), storedViewer (the viewer of the last page
 *         this browser loaded, any tab - null: unknown), maxAgeMs?}
 */
export function restoreDecision(facts = {}) {
    const {
        native,
        instantBack = INSTANT_BACK,
        instantBackIos = INSTANT_BACK_IOS,
        os: device,
        entry,
        now,
        viewer,
        turboHasSnapshot = true,
        lastWriteAt = null,
        storedViewer = null,
        maxAgeMs = MAX_AGE_MS,
    } = facts || {};

    if (native) {
        return 'leave';
    }

    if (!instantBack || (device === 'ios' && !instantBackIos)) {
        return 'reload';
    }

    if (!entry || typeof entry.cachedAt !== 'number' || turboHasSnapshot === false) {
        return 'reload';
    }

    const age = now - entry.cachedAt;

    if (typeof now !== 'number' || !Number.isFinite(age) || age < 0 || age > maxAgeMs) {
        return 'reload';
    }

    // Written since the copy was taken (in another tab too): the copy may show what is no more
    if (typeof lastWriteAt === 'number' && Number.isFinite(lastWriteAt) && lastWriteAt >= entry.cachedAt) {
        return 'reload';
    }

    // Signed out, in or as somebody else since - in another tab too (the page shown here still says the old viewer)
    if (typeof storedViewer === 'string' && storedViewer !== (entry.viewer || '')) {
        return 'reload';
    }

    return (entry.viewer || '') === (viewer || '') ? 'snapshot' : 'reload';
}

/** Will Turbo run this visit as a view transition (and so capture the page as it is in this frame)? */
export function viewTransitionExpected({ meta, available, reducedMotion, native } = {}) {
    return meta === 'same-origin' && available === true && reducedMotion !== true && native !== true;
}

/** When the cache work runs: 'now' (nothing will capture the page) or 'after-capture' (the task after turbo:before-cache) */
export function cacheWorkTiming({ viewTransition } = {}) {
    return viewTransition === true ? 'after-capture' : 'now';
}

/** What copying the page costs, in elements (CLONE_BUDGET_UNITS): its elements, a picture PICTURE_UNITS of them */
export function cloneUnits({ elements = 0, pictures = 0 } = {}) {
    const count = (value) => (typeof value === 'number' && Number.isFinite(value) && value > 0 ? value : 0);

    return count(elements) + PICTURE_UNITS * count(pictures);
}

/**
 * Is the page left kept out of Turbo's cache (its live meta switched to no-cache before Turbo would copy it)? When the
 * visit leaves it through a write (F1: a copy from before or around the write must never come back) or it is too large
 * to copy within a frame (F11). A visit that renders nothing of the page (a frame visit) never changes it.
 *
 * facts: {willRender, byWrite, elements, pictures (the page's element and picture counts), limit?}
 */
export function keepsNoCopy(facts = {}) {
    const { willRender = true, byWrite = false, elements = 0, pictures = 0, limit = CLONE_BUDGET_UNITS } = facts || {};

    if (willRender === false) {
        return false;
    }

    return byWrite === true || cloneUnits({ elements, pictures }) > limit;
}

/**
 * Is a fetch() a write the snapshots must not outlive (decision 65)? Any same-origin request that is not GET / HEAD /
 * OPTIONS - a Live component action (`/_components/<Name>/<action>`), a dismissed hint, a post_action - except the
 * telemetry beacons and health checks under `/-/` and a Live component's plain re-render (`/_components/<Name>`, which
 * Live sends as a POST when its props are long): those change nothing. Turbo's own form submissions are seen by
 * turbo:submit-end.
 */
export function isWriteRequest(method, url, base) {
    const verb = String(method || 'GET').toUpperCase();

    if (verb === 'GET' || verb === 'HEAD' || verb === 'OPTIONS') {
        return false;
    }

    let target;
    let here;

    try {
        target = new URL(String(url), base);
        here = new URL(String(base));
    } catch (e) {
        return false;
    }

    if (target.origin !== here.origin || target.pathname.startsWith('/-/')) {
        return false;
    }

    const live = target.pathname.match(/\/_components\/[^/]+(\/.*)?$/);

    return !(live && (live[1] === undefined || live[1] === '' || live[1] === '/'));
}

/** A URL without its #fragment, absolute against `base` (null for anything that is no URL) */
export function snapshotKey(url, base) {
    try {
        const parsed = new URL(String(url), base);
        parsed.hash = '';

        return parsed.href;
    } catch (e) {
        return null;
    }
}

/**
 * Which pages Turbo holds a snapshot of, since when and for whom: url => {cachedAt, viewer}. Least recently cached first
 * out once more than `limit` (Turbo's own cache keeps 10).
 */
export function createSnapshotLedger({ limit = SNAPSHOT_LIMIT } = {}) {
    const entries = new Map();

    return {
        record(key, entry) {
            if (key === null || key === undefined) {
                return;
            }

            entries.delete(key);
            entries.set(key, { cachedAt: entry.cachedAt, viewer: entry.viewer || '' });

            while (entries.size > limit) {
                entries.delete(entries.keys().next().value);
            }
        },
        entry(key) {
            return entries.has(key) ? entries.get(key) : null;
        },
        forget(key) {
            entries.delete(key);
        },
        clear() {
            entries.clear();
        },
        size() {
            return entries.size;
        },
    };
}

/**
 * Which documents a Back brought back by a full load (PROF-05c, decision 91; was assets/restore_reload.js). Before a
 * restore turns into a full load the URL is marked (sessionStorage `msp:restore-reload`); the next document reads the
 * mark once and removes it. cameBackByFullLoad(url): true only on the first page of the document (no Turbo visit since
 * it loaded) and only when that load was this restore reload, or the browser's own back/forward load of the URL.
 * isSnapshotRestore() comes from the env (the instant-back instance): a page restored from a snapshot keeps its own
 * scroll and rows and must never apply a saved position.
 */
export function createRestoreReload({ window, storage, isSnapshotRestore } = {}) {
    const store = () => {
        try {
            return storage !== undefined ? storage : window.sessionStorage;
        } catch (e) {
            return null;
        }
    };
    const base = () => (window && window.location ? String(window.location.href) : 'http://localhost/');

    // The URL this document was loaded for by a restore reload - read once, the mark never outlives one document
    let reloadedFor = null;

    try {
        const storageArea = store();

        if (storageArea) {
            reloadedFor = storageArea.getItem(RESTORE_RELOAD_KEY);
            storageArea.removeItem(RESTORE_RELOAD_KEY);
        }
    } catch (e) {
        reloadedFor = null;
    }

    let visited = false;

    function navigationEntry() {
        try {
            const entries = window.performance.getEntriesByType('navigation');

            return entries && entries.length ? entries[0] : null;
        } catch (e) {
            return null;
        }
    }

    return {
        mark(url) {
            try {
                const storageArea = store();

                if (storageArea) {
                    storageArea.setItem(RESTORE_RELOAD_KEY, String(url));
                }
            } catch (e) {
                // blocked storage: the page comes back at its top, as before
            }
        },

        visited() {
            visited = true;
        },

        cameBackByFullLoad(url) {
            if (visited) {
                return false;
            }

            const target = snapshotKey(url, base());

            if (target === null) {
                return false;
            }

            if (reloadedFor !== null && snapshotKey(reloadedFor, base()) === target) {
                return true;
            }

            const entry = navigationEntry();

            return Boolean(entry) && entry.type === 'back_forward' && snapshotKey(entry.name, base()) === target;
        },

        isSnapshotRestore() {
            return typeof isSnapshotRestore === 'function' ? isSnapshotRestore() === true : false;
        },

        /** This document was loaded by a Back (a restore reload or a back/forward load) and is still its first page */
        loadedByRestore() {
            if (visited) {
                return false;
            }

            if (reloadedFor !== null) {
                return true;
            }

            const entry = navigationEntry();

            return Boolean(entry) && entry.type === 'back_forward';
        },
    };
}

// ---- The clean copy ---------------------------------------------------------------------------------------------------

function removeAll(list) {
    list.forEach((element) => {
        try {
            element.remove();
        } catch (e) {
            // detached
        }
    });
}

/** Bootstrap's scroll-bar helper: the inline value it kept in data-bs-<property> goes back (or the property goes) */
function restoreBootstrapStyle(element, property) {
    const attribute = 'data-bs-' + property;

    if (!element.hasAttribute(attribute)) {
        return;
    }

    const value = element.getAttribute(attribute);
    element.removeAttribute(attribute);

    if (value === null || value === '') {
        element.style.removeProperty(property);
    } else {
        element.style.setProperty(property, value);
    }
}

/**
 * The page as Turbo may cache (and restore) it: nothing open, nothing built for one view. Works on the live <body> (the
 * cache work) and on a detached copy (a restore's new body). `menuOrder(nav)` puts the Menu's rows back in their docked
 * order (navigation/menu_order.js; app.js passes it). Never throws.
 */
export function sanitizeSnapshot(body, { menuOrder = null } = {}) {
    if (!body || typeof body.querySelectorAll !== 'function') {
        return;
    }

    const all = (selector) => {
        try {
            return Array.from(body.querySelectorAll(selector));
        } catch (e) {
            return [];
        }
    };

    try {
        // LAUNCH review Q2: an announcement modal shows once - a restored page never shows it again
        removeAll(all('[data-controller~="autoshow-modal"]'));

        // The player card's dialog is built by script per open (player_card_layer.js); the events page's ⋯ menu layer
        // (event_manage_menu_controller.js, on <body> - Instant Back review F2) and a guest's "Sign in to follow" note
        // (event_follow_controller.js, F7): the controllers of a restored copy never knew them
        removeAll(all('dialog.player-card-layer, ' + SCRIPT_BUILT_LAYERS));

        if (!body.isConnected) {
            // A copy about to be shown (a restore's new body): what its controllers build again when they connect goes
            // (REBUILT_ON_CONNECT), and the modal frame is as the server renders it, empty (a closed modal empties it;
            // its content was the page left's)
            removeAll(all(REBUILT_ON_CONNECT));
            all('turbo-frame#' + MODAL_FRAME_ID).forEach((frame) => {
                frame.replaceChildren();
                ['src', 'complete', 'busy', 'aria-busy'].forEach((name) => frame.removeAttribute(name));
            });
        }

        // Open dialogs: the Menu shell, the desktop overlay, the in-page confirmation
        all('dialog[open]').forEach((dialog) => {
            try {
                if (dialog.isConnected && typeof dialog.close === 'function') {
                    dialog.close();
                }
            } catch (e) {
                // not closable here
            }

            dialog.removeAttribute('open');
        });
        all('.menu-layer').forEach((shell) => shell.classList.remove('is-closing', 'is-searching', 'menu-layer--overlay'));

        // The Menu back in its slot, in dock order (MENU-02b moves #site-menu into the phone panel / the overlay)
        const nav = body.querySelector('#site-menu');
        const slot = body.querySelector('#menu-slot');

        if (nav && slot && nav.parentElement !== slot) {
            if (typeof menuOrder === 'function') {
                menuOrder(nav);
            }

            slot.appendChild(nav);
        }

        all('[aria-haspopup="dialog"][aria-expanded="true"]').forEach((opener) => opener.setAttribute('aria-expanded', 'false'));

        // Bootstrap modals (the dynamic modal, announcement and members modals) and their backdrops
        all('.modal.show, .modal[aria-modal="true"]').forEach((modal) => {
            modal.classList.remove('show');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            modal.removeAttribute('aria-modal');
        });
        removeAll(all('.modal-backdrop, .offcanvas-backdrop'));

        // Bootstrap dropdowns (Popper's inline placement goes too)
        all('.dropdown-menu.show').forEach((menu) => {
            menu.classList.remove('show');
            menu.removeAttribute('data-bs-popper');
            ['position', 'inset', 'margin', 'transform'].forEach((property) => menu.style.removeProperty(property));
        });
        all('[data-bs-toggle="dropdown"].show, [data-bs-toggle="dropdown"][aria-expanded="true"]').forEach((toggle) => {
            toggle.classList.remove('show');
            toggle.setAttribute('aria-expanded', 'false');
        });

        // Popovers (an open one is no state a copy keeps, but the live page may hold one)
        all('[popover]').forEach((popover) => {
            try {
                if (typeof popover.matches === 'function' && popover.matches(':popover-open')) {
                    popover.hidePopover();
                }
            } catch (e) {
                // no popover API
            }
        });

        all(MENU_DETAILS_SELECTOR).forEach((details) => details.removeAttribute('open'));

        // Toasts shown on this page (the flash messages are data-turbo-temporary - Turbo removes them itself)
        removeAll(all('#toast-container > *'));

        // GEST-11's ripples, GEST-17's inline view-transition names, celebrations
        removeAll(all('.msp-ripple-host'));
        all('[style*="view-transition-name"]').forEach((element) => element.style.removeProperty('view-transition-name'));
        all('.' + CELEBRATION_CLASSES.join(', .')).forEach((element) => element.classList.remove(...CELEBRATION_CLASSES));
        removeAll(all(CELEBRATION_HOSTS));

        // The body: no scroll lock, no layer class; nothing the layers made inert stays inert
        BODY_LAYER_CLASSES.forEach((name) => body.classList.remove(name));
        ['overflow', 'padding-right'].forEach((property) => restoreBootstrapStyle(body, property));
        body.style.removeProperty('overflow');
        all('[data-bs-padding-right], [data-bs-margin-right]').forEach((element) => {
            restoreBootstrapStyle(element, 'padding-right');
            restoreBootstrapStyle(element, 'margin-right');
        });
        Array.from(body.children || []).forEach((child) => {
            if (child.hasAttribute && child.hasAttribute('inert')) {
                child.removeAttribute('inert');
            }
        });
    } catch (e) {
        // a broken copy is still better than a thrown restore
    }
}

/** Widgets that build their own DOM, torn down (generic: TomSelect and flatpickr keep their instance on the element) */
export function teardownWidgets(root, charts = null) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return 0;
    }

    let count = 0;

    root.querySelectorAll('.tomselected').forEach((element) => {
        if (element.tomselect && typeof element.tomselect.destroy === 'function') {
            try {
                element.tomselect.destroy();
                count++;
            } catch (e) {
                // already gone
            }
        }
    });

    root.querySelectorAll('.flatpickr-input').forEach((element) => {
        if (element._flatpickr && typeof element._flatpickr.destroy === 'function') {
            try {
                element._flatpickr.destroy();
                count++;
            } catch (e) {
                // already gone
            }
        }
    });

    if (charts) {
        charts.forEach((chart) => {
            try {
                if (chart && chart.canvas && (!root.contains || root.contains(chart.canvas))) {
                    chart.destroy();
                    count++;
                }
            } catch (e) {
                // already gone
            }
        });
    }

    return count;
}

// ---- The browser instance -------------------------------------------------------------------------------------------

function randomId(window) {
    try {
        if (window && window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
    } catch (e) {
        // fall through
    }

    return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

/**
 * env: {window, document, turbo?() (Turbo's module or window.Turbo), layers? (layer_history), menuOrder?, now?,
 *       session? (sessionStorage), local? (localStorage), setTimeout?, raf?}
 */
export function createInstantBack(env) {
    const window = env.window;
    const document = env.document;
    const now = env.now || (() => Date.now());
    const later = env.setTimeout || ((fn, ms) => window.setTimeout(fn, ms));
    const nextFrame = env.raf || ((fn) => (typeof window.requestAnimationFrame === 'function' ? window.requestAnimationFrame(fn) : later(fn, 16)));
    const documentId = env.documentId || randomId(window);

    const ledger = createSnapshotLedger();
    const restoreReload = createRestoreReload({ window, storage: env.session, isSnapshotRestore: () => api.isSnapshotRestore() });
    const charts = new Set();

    // GEST-14: when this document was hidden (memory survives the back/forward cache), whether it sent a POST
    let hiddenAt = null;
    let posted = false;
    // GEST-15: the restore that brought the page shown: {kind, url, cachedAt, at}; reset by the next visit
    let shown = null;
    // A restore turned into a full load: nothing may render or be cached any more
    let reloading = false;
    // A page visit in progress (turbo:visit .. turbo:load): only then is the page left (before-cache is also fired for
    // a history entry without Turbo state, and for frame visits that do not render)
    let visiting = false;
    let holdingScroll = false;
    // The snapshot restore's own render (turbo:visit .. turbo:render): its new body is the cached copy
    let restoreRendering = false;
    /** The scroll a snapshot restore comes back to, taken at its turbo:visit: {history, id, position} (heldRestorePosition) */
    let heldRestore = null;
    let installed = false;
    // F1 - writes and the visits they start. writeCount: writes noted in this document; pendingWrite: one was noted
    // since the page shown rendered (turbo:load); clickWrites: writeCount when the click being dispatched began;
    // visitIntent: what the user's click or GET form starting the next visit was ({byWrite}); stampAfterLoad: the visit
    // in progress left its page through a write - note it again once the next page rendered
    let writeCount = 0;
    let pendingWrite = false;
    let clickWrites = null;
    let visitIntent = null;
    let stampAfterLoad = false;

    function turbo() {
        try {
            if (typeof env.turbo === 'function') {
                return env.turbo() || null;
            }

            return window.Turbo || null;
        } catch (e) {
            return null;
        }
    }

    function session() {
        const t = turbo();

        return t && t.session ? t.session : null;
    }

    function root() {
        return document ? document.documentElement : null;
    }

    function sessionStore() {
        try {
            return env.session !== undefined ? env.session : window.sessionStorage;
        } catch (e) {
            return null;
        }
    }

    function localStore() {
        try {
            return env.local !== undefined ? env.local : window.localStorage;
        } catch (e) {
            return null;
        }
    }

    function href() {
        return String(window.location.href);
    }

    function dispatch(target, type, detail) {
        try {
            const view = document.defaultView || window;
            const Custom = view && typeof view.CustomEvent === 'function' ? view.CustomEvent : null;

            if (Custom && target && typeof target.dispatchEvent === 'function') {
                target.dispatchEvent(new Custom(type, { detail }));
            }
        } catch (e) {
            // a listener failing never stops a restore
        }
    }

    /**
     * The viewer the page was rendered for: <meta name="msp-viewer"> of a signed-in viewer; empty for a guest's page,
     * which carries no meta (guest HTML stays as it was) and is known by <body data-viewer-audience> ("everyone guest");
     * null: a page that tells neither (a layout without base.html.twig)
     */
    function pageViewer() {
        try {
            const meta = document.head ? document.head.querySelector(`meta[name="${VIEWER_META}"]`) : null;

            if (meta) {
                return meta.getAttribute('content') || '';
            }

            const audience = document.body ? document.body.getAttribute(VIEWER_AUDIENCE_ATTRIBUTE) : null;

            return audience !== null && GUEST_AUDIENCE.test(audience) ? '' : null;
        } catch (e) {
            return null;
        }
    }

    function rememberViewer() {
        const viewer = pageViewer();

        if (viewer === null) {
            return;
        }

        try {
            const store = localStore();

            if (store) {
                store.setItem(VIEWER_KEY, viewer);
            }
        } catch (e) {
            // blocked storage: a restore is not checked against it
        }
    }

    function storedViewer() {
        try {
            const store = localStore();

            return store ? store.getItem(VIEWER_KEY) : null;
        } catch (e) {
            return null;
        }
    }

    function metaContent(name) {
        try {
            const meta = document.head ? document.head.querySelector(`meta[name="${name}"]`) : null;

            return meta ? meta.getAttribute('content') : null;
        } catch (e) {
            return null;
        }
    }

    function reducedMotion() {
        try {
            return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        } catch (e) {
            return false;
        }
    }

    /** Where Turbo caches the page shown: its last rendered location (layer_history keeps it current for page owners) */
    function renderedKey() {
        const s = session();
        const location = s && s.view ? s.view.lastRenderedLocation : null;

        return snapshotKey(location ? String(location.href || location) : href(), href());
    }

    function turboHasSnapshot(key) {
        const s = session();
        const cache = s && s.view && s.view.snapshotCache;

        if (!cache || typeof cache.has !== 'function') {
            return true;
        }

        try {
            return cache.has(new URL(key));
        } catch (e) {
            return true;
        }
    }

    /**
     * A write: the copies go, the document counts as having posted, and every tab learns when (LAST_WRITE_KEY). Until a
     * page renders after it, a visit no user click started is taken for one the write started (a Live action's redirect,
     * a modal form answered with a page) - the page it leaves keeps no copy (F1)
     */
    function noteWrite() {
        writeCount++;
        pendingWrite = true;

        // A click that only steered a frame (no visit came of it) no longer speaks for the next visit
        if (visitIntent !== null && visitIntent.byWrite !== true) {
            visitIntent = null;
        }

        stampWrite();
    }

    /** The write noted again once the page it led to rendered (F1): nothing cached around it survives */
    function stampWrite() {
        posted = true;
        clearSnapshots();
        rememberWriteTime();
    }

    /** The page's own turbo-cache-control: no-cache - Turbo neither copies nor keeps it (F1, F11) */
    function keepNoCopy() {
        try {
            const meta = document.head ? document.head.querySelector(`meta[name="${CACHE_CONTROL_META}"]`) : null;

            if (meta && meta.getAttribute('content') !== NO_CACHE) {
                meta.setAttribute('content', NO_CACHE);
            }
        } catch (e) {
            // Turbo copies the page: a write's visit notes the write again after it (stampAfterLoad), a large page costs
            // its clone once
        }
    }

    /** What the page left weighs (cloneUnits) - counted only for a page Turbo would copy */
    function pageSize() {
        try {
            const meta = document.head ? document.head.querySelector(`meta[name="${CACHE_CONTROL_META}"]`) : null;

            if (!document.body || (meta && meta.getAttribute('content') === NO_CACHE)) {
                return { elements: 0, pictures: 0 };
            }

            return { elements: document.body.getElementsByTagName('*').length, pictures: document.body.getElementsByTagName('img').length };
        } catch (e) {
            return { elements: 0, pictures: 0 };
        }
    }

    function rememberWriteTime() {
        try {
            const store = localStore();

            if (store) {
                store.setItem(LAST_WRITE_KEY, String(now()));
            }
        } catch (e) {
            // this document's copies are gone all the same
        }
    }

    function lastWriteAt() {
        try {
            const store = localStore();
            const stored = store ? store.getItem(LAST_WRITE_KEY) : null;

            return stored !== null && stored !== '' && Number.isFinite(Number(stored)) ? Number(stored) : null;
        } catch (e) {
            return null;
        }
    }

    /** The page's GET wrote (CLEARS_SNAPSHOTS_META, rendered by the server): what was cached before it is stale */
    function notePageWrites() {
        try {
            if (document.head && document.head.querySelector(`meta[name="${CLEARS_SNAPSHOTS_META}"]`)) {
                noteWrite();
            }
        } catch (e) {
            // nothing to note
        }
    }

    function clearSnapshots() {
        ledger.clear();

        try {
            const t = turbo();

            if (t && t.cache && typeof t.cache.clear === 'function') {
                t.cache.clear();
            }
        } catch (e) {
            // nothing cached then
        }
    }

    function holdScroll() {
        const html = root();

        if (html && html.style) {
            holdingScroll = true;
            html.style.setProperty('scroll-behavior', 'auto');
        }
    }

    function releaseScroll() {
        const html = root();

        if (holdingScroll && html && html.style) {
            holdingScroll = false;
            html.style.removeProperty('scroll-behavior');
        }
    }

    // ---- GEST-14 --------------------------------------------------------------------------------------------------

    function stampPageHide() {
        hiddenAt = now();

        try {
            const store = sessionStore();

            if (store) {
                store.setItem(pagehideKey(documentId), String(hiddenAt));
            }
        } catch (e) {
            // the in-memory stamp is enough: the frozen page keeps it
        }
    }

    function awayMs() {
        const stamp = hiddenStamp();

        return typeof stamp === 'number' ? now() - stamp : null;
    }

    function hiddenStamp() {
        let stamp = hiddenAt;

        try {
            const store = sessionStore();
            const stored = store ? store.getItem(pagehideKey(documentId)) : null;

            if (stored !== null && stored !== '' && Number.isFinite(Number(stored))) {
                stamp = Number(stored);
            }
        } catch (e) {
            // the in-memory stamp
        }

        return typeof stamp === 'number' ? stamp : null;
    }

    // ---- GEST-15 --------------------------------------------------------------------------------------------------

    /**
     * A snapshot restore comes back to the scroll its entry was left at - Turbo's restoration data of the visit's
     * identifier, as it stands at turbo:visit. Turbo records window scroll events into the *current* identifier when the
     * event is dispatched (ScrollObserver → history.updateRestorationData), and a scroll event is dispatched a frame after
     * the scroll: a Back taken right after the next page rendered (its scroll to the top still to be reported) makes the
     * popstate switch the identifier first, and that page's late "0" lands on the entry being restored - Turbo then
     * scrolls it to the top. Held here and put back at turbo:render, which Turbo dispatches before it scrolls
     * (Visit.renderPageSnapshot: renderPage → turbo:render → performScroll).
     */
    function holdRestorePosition() {
        heldRestore = null;

        try {
            const s = session();
            const history = s ? s.history : null;
            const visit = s && s.navigator ? s.navigator.currentVisit : null;
            const id = visit && visit.restorationIdentifier ? visit.restorationIdentifier : null;
            const data = history && history.restorationData && id ? history.restorationData[id] : null;
            const position = data && data.scrollPosition;

            if (id && position && Number.isFinite(position.x) && Number.isFinite(position.y)) {
                heldRestore = { history, id, position: { x: position.x, y: position.y } };
            }
        } catch (e) {
            heldRestore = null;
        }
    }

    function putBackRestorePosition() {
        const held = heldRestore;
        heldRestore = null;

        if (!held) {
            return;
        }

        try {
            const data = held.history.restorationData[held.id] || {};
            held.history.restorationData[held.id] = { ...data, scrollPosition: { ...held.position } };
        } catch (e) {
            // Turbo scrolls from what it has
        }
    }

    function cancelCurrentVisit() {
        const s = session();
        const visit = s && s.navigator ? s.navigator.currentVisit : null;

        try {
            if (visit && typeof visit.cancel === 'function') {
                visit.cancel();
            }
        } catch (e) {
            // turbo:before-render stops the render instead
        }
    }

    /**
     * The page this visit leaves keeps no copy when the visit leaves it through a write or it is too large to copy
     * (keepsNoCopy(), F1 / F11): its live meta says no-cache before Turbo would copy it (Turbo copies after the answer
     * or, on a restore, a frame after this event). A visit a write started notes it again once its page rendered (a
     * restore is no write's visit: it only keeps no copy of a page written on in place a moment ago).
     */
    function leaveWithoutCopyIf(byWrite, restore) {
        const s = session();
        const visit = s && s.navigator ? s.navigator.currentVisit : null;
        const willRender = !(visit && visit.willRender === false);

        if (willRender && byWrite && !restore) {
            stampAfterLoad = true;
        }

        if (keepsNoCopy({ willRender, byWrite, ...(willRender && !byWrite ? pageSize() : {}) })) {
            keepNoCopy();
        }
    }

    function onTurboVisit(event) {
        const detail = (event && event.detail) || {};
        visiting = true;
        heldRestore = null;
        restoreReload.visited();

        // Started by the user's own click or GET form (its intent says whether that wrote), else by script: by a write
        // when one is still unrendered (a Live action's redirect, a modal form answered with a page, a post_action)
        const intent = visitIntent;
        visitIntent = null;
        const byWrite = intent !== null && detail.action !== 'restore' ? intent.byWrite === true : pendingWrite;
        // A write from now on happens during this visit (onBeforeCache)
        pendingWrite = false;
        leaveWithoutCopyIf(byWrite, detail.action === 'restore');

        if (detail.action !== 'restore') {
            shown = null;
            releaseScroll();

            return;
        }

        const native = isNativeApp(root());
        const key = snapshotKey(detail.url, href());
        const entry = key === null ? null : ledger.entry(key);
        const kind = restoreDecision({
            native,
            os: os(root()),
            entry,
            now: now(),
            viewer: pageViewer() || '',
            turboHasSnapshot: entry ? turboHasSnapshot(key) : true,
            lastWriteAt: lastWriteAt(),
            storedViewer: storedViewer(),
        });

        if (kind === 'leave') {
            shown = null;

            return;
        }

        shown = { kind, url: key, cachedAt: entry ? entry.cachedAt : null, at: now() };
        dispatch(document, RESTORE_DECIDED_EVENT, { kind, url: detail.url });

        if (kind === 'snapshot') {
            restoreRendering = true;
            holdScroll();
            holdRestorePosition();

            return;
        }

        // Today's full load of the entry (a same-URL navigation replaces it): Turbo's own visit goes no further
        reloading = true;
        cancelCurrentVisit();
        restoreReload.mark(detail.url);
        window.location.href = detail.url;
    }

    function onBeforeRender(event) {
        if (reloading) {
            // A full load is on its way: nothing renders meanwhile (the visit's render was cancelled; this is the net)
            event.preventDefault();

            return;
        }

        if (restoreRendering && event.detail && event.detail.newBody) {
            sanitizeSnapshot(event.detail.newBody, { menuOrder: env.menuOrder });
        }
    }

    function onTurboRender() {
        restoreRendering = false;
        // Before Turbo scrolls the restored copy (holdRestorePosition)
        putBackRestorePosition();
        // The page just shown wrote on its GET: the copies cached before it (the page left among them) are stale
        notePageWrites();
    }

    function onTurboLoad() {
        visiting = false;
        restoreRendering = false;
        heldRestore = null;
        visitIntent = null;

        // The page of a visit that left its page through a write rendered: the write is noted again, after anything
        // Turbo may have copied around it (F1)
        if (stampAfterLoad) {
            stampAfterLoad = false;
            stampWrite();
        }

        // The page shown was rendered after every write noted so far
        pendingWrite = false;
        rememberViewer();

        charts.forEach((chart) => {
            if (!chart || !chart.canvas || !chart.canvas.isConnected) {
                charts.delete(chart);
            }
        });

        if (holdingScroll) {
            // Turbo scrolled before turbo:load; the next frame lets the page's own smooth scrolling back
            holdScroll();
            nextFrame(() => releaseScroll());
        }
    }

    function leavingVisit() {
        if (!visiting || reloading) {
            return false;
        }

        const s = session();
        const visit = s && s.navigator ? s.navigator.currentVisit : null;

        // A frame visit promoted to the address bar renders nothing of the page: the page stays
        return !(visit && visit.willRender === false);
    }

    function cacheWork(body) {
        if (body && body.isConnected) {
            if (env.layers && typeof env.layers.closeForCache === 'function') {
                try {
                    env.layers.closeForCache();
                } catch (e) {
                    // the copy is cleaned below anyway
                }
            }

            dispatch(document, BEFORE_CACHE_EVENT, { body });
        }

        teardownWidgets(body, charts);
        charts.clear();
        sanitizeSnapshot(body, { menuOrder: env.menuOrder });
    }

    /**
     * A page that rewrote its own address since Turbo rendered it (a Live component's URL-bound props replace the query
     * string): Turbo would cache it under the address it rendered, and a Back to the address shown would find nothing.
     * Only while another page is visited (the address is still this page's), never on a restore (already the target's).
     */
    function followOwnAddress() {
        const s = session();
        const view = s && s.view;
        const visit = s && s.navigator ? s.navigator.currentVisit : null;
        const rendered = view ? view.lastRenderedLocation : null;

        if (!rendered || !visit || visit.action === 'restore') {
            return;
        }

        try {
            const shown = new URL(href());

            if (shown.pathname === rendered.pathname && shown.href !== rendered.href) {
                view.lastRenderedLocation = shown;
            }
        } catch (e) {
            // cached under the address Turbo rendered
        }
    }

    function onBeforeCache() {
        if (!leavingVisit()) {
            return;
        }

        followOwnAddress();

        if (pendingWrite) {
            // A write landed while this visit was on its way: the page left may show the state before it - its copy is
            // never restored (not in the ledger), and the write is noted again once the next page rendered (F1)
            stampAfterLoad = true;
        } else {
            ledger.record(renderedKey(), { cachedAt: now(), viewer: pageViewer() || '' });
        }

        const body = document.body;
        const timing = cacheWorkTiming({
            viewTransition: viewTransitionExpected({
                meta: metaContent('view-transition'),
                available: typeof document.startViewTransition === 'function',
                reducedMotion: reducedMotion(),
                native: isNativeApp(root()),
            }),
        });

        if (timing === 'now') {
            cacheWork(body);
        } else {
            later(() => cacheWork(body), 0);
        }
    }

    function onSubmitEnd(event) {
        const detail = (event && event.detail) || {};
        const submission = detail.formSubmission;
        const method = submission && submission.method ? String(submission.method).toLowerCase() : 'get';

        if (detail.success && method !== 'get') {
            noteWrite();
        }
    }

    /**
     * Every write the page makes with fetch() - a Live component action, a dismissed hint - clears the snapshots and
     * marks the document as having posted, like a Turbo form submission (isWriteRequest()): Back never shows the state
     * before it. Wraps window.fetch once; the request and its answer are untouched.
     */
    function watchFetchWrites() {
        const w = env.window;

        if (!w || typeof w.fetch !== 'function' || w.fetch[FETCH_WATCHED]) {
            return;
        }

        const original = w.fetch;
        const watched = function (input, init) {
            const answer = original.apply(w, arguments);
            let write = false;

            try {
                const method = (init && init.method) || (input && typeof input === 'object' && typeof input.method === 'string' ? input.method : 'GET');
                const url = input && typeof input === 'object' && typeof input.url === 'string' ? input.url : String(input);
                write = isWriteRequest(method, url, href());
            } catch (e) {
                write = false;
            }

            if (write && answer && typeof answer.then === 'function') {
                answer.then((response) => {
                    if (response && response.ok) {
                        noteWrite();
                    }
                }, () => {});
            }

            return answer;
        };

        watched[FETCH_WATCHED] = true;
        w.fetch = watched;
    }

    function onSubmitCapture(event) {
        const form = event && event.target;
        const submitter = event && event.submitter;

        if (!form || String(form.tagName || '').toUpperCase() !== 'FORM') {
            return;
        }

        const method = (submitter && submitter.getAttribute && submitter.getAttribute('formmethod')) || form.getAttribute('method') || 'get';

        if (String(method).toLowerCase() !== 'get') {
            posted = true;
            // A form Turbo does not handle (data-turbo="false") leaves the document: every tab and the back/forward cache
            // learn of the write now - a refused one only costs a full load on Back
            rememberWriteTime();
        }
    }

    function clearsSnapshots(target) {
        return Boolean(target && typeof target.closest === 'function' && target.closest(`[${CLEARS_SNAPSHOTS_ATTRIBUTE}]`));
    }

    /**
     * Every click, before anything handles it (capture): a [data-clears-snapshots] element is a write - a Turbo link, a
     * link Turbo leaves alone (sign-out: data-turbo="false") or a modified click opening a new tab alike (F1, F3)
     */
    function onClickCapture(event) {
        clickWrites = writeCount;

        if (clearsSnapshots(event && event.target)) {
            noteWrite();
        }
    }

    /** Turbo follows a link: the visit it starts is the user's - by a write when the link is one or its click wrote */
    function onTurboClick(event) {
        const byWrite = clearsSnapshots(event && event.target) || (clickWrites !== null && writeCount > clickWrites);
        clickWrites = null;
        visitIntent = { byWrite };
    }

    /** A form Turbo submits: a GET form's visit is the user's (a POST's own visit never caches the page it leaves) */
    function onSubmitStart(event) {
        const submission = event && event.detail ? event.detail.formSubmission : null;
        const method = submission && submission.method ? String(submission.method).toLowerCase() : 'get';

        if (method === 'get') {
            visitIntent = { byWrite: false };
        }
    }

    /** Another tab wrote, signed out or in (localStorage): the copies of this one go at once */
    function onStorage(event) {
        const key = event ? event.key : undefined;

        if (key === null || key === LAST_WRITE_KEY || key === VIEWER_KEY) {
            clearSnapshots();
        }
    }

    function onChartConnect(event) {
        const chart = event && event.detail ? event.detail.chart : null;

        if (chart) {
            charts.add(chart);
        }
    }

    const api = {
        documentId() {
            return documentId;
        },

        // GEST-14 (pwa_lifecycle_controller.js)
        stampPageHide,

        /** The verdict for a `pageshow` event (and its side effects: the viewer remembered on a keep) */
        bfcacheVerdictFor(event) {
            const persisted = Boolean(event && event.persisted === true);

            if (!persisted) {
                return 'none';
            }

            const stored = storedViewer();
            const viewer = pageViewer();
            const verdict = bfcacheVerdict({
                persisted,
                awayMs: awayMs(),
                reloadMarker: Boolean(document.querySelector(`[${BFCACHE_RELOAD_ATTRIBUTE}]`)),
                os: os(root()),
                native: isNativeApp(root()),
                viewerChanged: stored !== null && viewer !== null && stored !== viewer,
                postedFrom: posted,
                wroteSince: (() => {
                    const written = lastWriteAt();
                    const hidden = hiddenStamp();

                    return written !== null && hidden !== null && written > hidden;
                })(),
            });

            if (verdict === 'keep') {
                shown = { kind: 'bfcache', url: snapshotKey(href(), href()), cachedAt: null, at: now() };
            }

            return verdict;
        },

        /** A POST left from this document (post_action_controller.js: its fetch is one) */
        notePost() {
            posted = true;
        },

        // GEST-15: a write made elsewhere (post_action_controller.js, event_follow_controller.js)
        clearSnapshots: noteWrite,
        isSnapshotRestore() {
            return Boolean(shown && shown.kind === 'snapshot');
        },
        /** How old the snapshot shown was when it was restored, ms (null when the page did not come from one) */
        restoredSnapshotAge() {
            return shown && shown.kind === 'snapshot' && typeof shown.cachedAt === 'number' ? Math.max(0, shown.at - shown.cachedAt) : null;
        },
        /**
         * How the page shown came back: 'snapshot' | 'bfcache' | 'reload' (this document was loaded by a Back - a restore
         * reload or the browser's own back/forward load) | null (GEST-13 watchdog's afterRestore)
         */
        lastRestoreKind() {
            if (shown) {
                return shown.kind;
            }

            return restoreReload.loadedByRestore() ? 'reload' : null;
        },

        // PROF-05c
        markRestoreReload(url) {
            restoreReload.mark(url);
        },
        cameBackByFullLoad(url) {
            return restoreReload.cameBackByFullLoad(url);
        },

        /** The ledger, for tests and diagnostics */
        ledger,

        install() {
            if (installed) {
                return api;
            }

            installed = true;
            rememberViewer();
            notePageWrites();
            // The page shown wrote on its GET and shows the state after it
            pendingWrite = false;

            document.addEventListener('turbo:visit', onTurboVisit);
            document.addEventListener('turbo:before-render', onBeforeRender);
            document.addEventListener('turbo:render', onTurboRender);
            document.addEventListener('turbo:load', onTurboLoad);
            document.addEventListener('turbo:before-cache', onBeforeCache);
            document.addEventListener('turbo:submit-start', onSubmitStart);
            document.addEventListener('turbo:submit-end', onSubmitEnd);
            document.addEventListener('turbo:click', onTurboClick);
            document.addEventListener('click', onClickCapture, true);
            document.addEventListener('submit', onSubmitCapture, true);
            document.addEventListener('chartjs:connect', onChartConnect);

            if (window && typeof window.addEventListener === 'function') {
                window.addEventListener('storage', onStorage);
            }

            watchFetchWrites();

            return api;
        },
    };

    return api;
}

// ---- The browser instance (assets/app.js installs it) ---------------------------------------------------------------

let browserInstance = null;
let browserEnv = null;

function instance() {
    if (browserInstance === null && typeof window !== 'undefined' && typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
        browserEnv = { window, document };
        browserInstance = createInstantBack(browserEnv);
    }

    return browserInstance;
}

// Created when the module is first imported (the restore mark of the document is read at once); app.js installs it
instance();

/**
 * app.js: the restore handler, the cache work and the staleness rules on every page (the web only - inside the app the
 * restore decision is 'leave'). options: {turbo, layers, menuOrder}
 */
export function installInstantBack(options = {}) {
    const created = instance();

    if (created === null) {
        return null;
    }

    Object.assign(browserEnv, options);

    return created.install();
}

export function stampPageHide() {
    instance()?.stampPageHide();
}

export function bfcacheVerdictFor(event) {
    return instance() ? instance().bfcacheVerdictFor(event) : 'reload';
}

export function notePost() {
    instance()?.notePost();
}

export function clearSnapshots() {
    instance()?.clearSnapshots();
}

export function isSnapshotRestore() {
    return instance() ? instance().isSnapshotRestore() : false;
}

export function restoredSnapshotAge() {
    return instance() ? instance().restoredSnapshotAge() : null;
}

export function lastRestoreKind() {
    return instance() ? instance().lastRestoreKind() : null;
}

export function markRestoreReload(url) {
    instance()?.markRestoreReload(url);
}

export function cameBackByFullLoad(url) {
    return instance() ? instance().cameBackByFullLoad(url) : false;
}
