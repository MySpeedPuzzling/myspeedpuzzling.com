/**
 * Which documents a Back brought back by a full load (docs/features/navigation PROF-05c, implementation plan decision 91).
 *
 * Today every Turbo restore visit becomes a full load: app.js's turbo:visit handler sets `location.href` to the URL
 * (README §4.13 G9). Before it does, it marks the URL here (sessionStorage `msp:restore-reload`); the next document
 * reads the mark once and removes it. A page that keeps its own place (the Results page's scroll position, saved when
 * it was left) asks cameBackByFullLoad(url): true only on the first page of the document (no Turbo visit since it
 * loaded) and only when that load was this restore reload, or the browser's own back/forward load of the URL.
 *
 * GEST-15 (Instant Back) folds this into assets/instant_back.js: it renders Turbo's snapshot instead where it may and
 * keeps the full load (with this mark) everywhere else. Its two switch points live here until then:
 * - isSnapshotRestore(): true while a restore visit renders Turbo's snapshot - a page restored that way keeps its own
 *   scroll and rows and must never apply a saved position. Until GEST-15 no restore renders a snapshot.
 * - SNAPSHOT_RESTORES: whether Turbo keeps snapshots of pages to restore (the scroll Turbo saves for an entry matters
 *   then). False until GEST-15.
 *
 * Pure factory createRestoreReload({window, storage}) for tests/player-results-state-harness.mjs; the default export is
 * the browser instance, created when app.js imports this module.
 */

export const RESTORE_RELOAD_KEY = 'msp:restore-reload';

/** GEST-15 sets it: Turbo renders snapshots on Back (instant_back.js INSTANT_BACK) */
export const SNAPSHOT_RESTORES = false;

function withoutHash(url) {
    const text = String(url);
    const at = text.indexOf('#');

    return at === -1 ? text : text.slice(0, at);
}

function absolute(url, base) {
    try {
        return withoutHash(new URL(String(url), base).href);
    } catch (e) {
        return null;
    }
}

export function createRestoreReload({ window, storage } = {}) {
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
        /** app.js, right before the full load of a restore visit */
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

        /** A Turbo visit happened: the page shown is no longer the one the document loaded with */
        visited() {
            visited = true;
        },

        /** Was this document's load the way back to `url` (and is it still the page it loaded with)? */
        cameBackByFullLoad(url) {
            if (visited) {
                return false;
            }

            const target = absolute(url, base());

            if (target === null) {
                return false;
            }

            if (reloadedFor !== null && absolute(reloadedFor, base()) === target) {
                return true;
            }

            const entry = navigationEntry();

            return Boolean(entry) && entry.type === 'back_forward' && absolute(entry.name, base()) === target;
        },

        /** GEST-15: true while a restore visit renders Turbo's snapshot; never before it */
        isSnapshotRestore() {
            return false;
        },
    };
}

let browserInstance = null;

function instance() {
    if (browserInstance === null) {
        browserInstance = createRestoreReload({ window });

        if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
            document.addEventListener('turbo:visit', () => browserInstance.visited());
        }
    }

    return browserInstance;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    instance();
}

export function markRestoreReload(url) {
    instance().mark(url);
}

export function cameBackByFullLoad(url) {
    return instance().cameBackByFullLoad(url);
}

export function isSnapshotRestore() {
    return instance().isSnapshotRestore();
}
