import { activationDirection, direction, historyIndex } from './nav_instrumentation.js';
import { BFCACHE_RELOAD_EVENT, BFCACHE_RESTORE_EVENT, RESTORE_DECIDED_EVENT } from './instant_back.js';

/**
 * Back measurement (docs/features/navigation README §4.13 G9, requirement GEST-08): one line per Back / Forward with how
 * the page came back and how long it took - P0-03 logged the Back itself (a baseline exists from before the launch),
 * this takes that logger over (nav_instrumentation.js runs with `history: false`) and adds:
 *
 *   restore = snapshot        Turbo rendered its snapshot (assets/instant_back.js) - ms from the popstate to turbo:load
 *           | reload          a full load: instant_back's `location.href` fallback, or a browser back/forward load
 *                             without us (from another site, a page out of both caches) - measured by the next document
 *           | bfcache         the browser's back/forward cache, kept (GEST-14) - ms from its restoration entry
 *           | bfcache-reload  a back/forward-cache copy reloaded (away > 10 minutes, [data-bfcache-reload], …)
 *
 * A full load writes `msp:back-pending` {kind, at (epoch ms), dir, uaTransition} to sessionStorage before it leaves;
 * the next document reads it once (ms = now - at at its turbo:load). A layer closed by Back is no Back of a page:
 * layer_history.js stops that popstate before this module sees it. Each line is also dispatched as `msp:back` on the
 * document (detail = the line) - what the browser check counts.
 *
 * Direction (`dir` back | forward): the restore is often decided before this module's popstate listener runs - Chromium
 * calls a window's popstate listeners in the order they were added, capture or not, and Turbo adds its own when it
 * starts, before the app's (only a layer's or page owner's pause re-adds it after them); layer_history.js starts the
 * restore itself after a pop it stops (a page owner's page left). So the traversal begins with whichever comes first -
 * our popstate listener or the restore decision (current()) - and takes its direction from the Navigation API's
 * `navigate` event (it fires before every popstate listener: the destination's index against the entry left), else
 * from the history index now against the one last shown (Turbo's restoration index without the Navigation API). A
 * document loaded or restored by a traverse reads it from navigation.activation; its `entry` reads index -1 once Turbo
 * replaced that entry at start, so the current entry stands in. Only without the Navigation API does a load or a
 * back/forward-cache restore stay `unknown` (Turbo's index starts again in every document).
 *
 * buildBackPayload() is pure; createBackMeasurement(env) takes {window, document, storage?, now? (epoch ms),
 * perfNow?, send(payload)} so tests/back-measurement-harness.mjs drives it under node.
 */

export const BACK_PENDING_KEY = 'msp:back-pending';
export const BACK_EVENT = 'msp:back';
export const KINDS = ['snapshot', 'reload', 'bfcache', 'bfcache-reload'];

/** The line: {restore, ms (whole ms or null), dir, uaTransition} - null for a kind it does not know */
export function buildBackPayload({ kind, msToLoad, uaTransition, dir } = {}) {
    if (!KINDS.includes(kind)) {
        return null;
    }

    const ms = typeof msToLoad === 'number' && Number.isFinite(msToLoad) && msToLoad >= 0 ? Math.round(msToLoad) : null;

    return {
        restore: kind,
        ms,
        dir: ['back', 'forward', 'unknown'].includes(dir) ? dir : 'unknown',
        uaTransition: typeof uaTransition === 'boolean' ? uaTransition : null,
    };
}

export function createBackMeasurement(env) {
    const window = env.window;
    const document = env.document;
    const now = env.now || (() => Date.now());
    const perfNow = env.perfNow || (() => (window.performance && typeof window.performance.now === 'function' ? window.performance.now() : 0));

    function storage() {
        try {
            return env.storage !== undefined ? env.storage : window.sessionStorage;
        } catch (e) {
            return null;
        }
    }

    // Where the page was last shown (turbo:load, or a popstate this module saw), to tell Back from Forward
    let lastIndex = historyIndex(window);
    // What the Navigation API's navigate event said about the traversal under way: {index (the destination's), dir,
    // uaTransition}
    let announced = null;
    // The traversal under way: {index (the history index it landed on), t0 (perf), at (epoch), uaTransition, dir,
    // pending (the kind of the mark written for the next document)}
    let traversal = null;
    // A snapshot restore waiting for its turbo:load
    let snapshot = null;
    // What this document came back as by a full load, measured at its first turbo:load
    let arrived = null;
    let installed = false;

    function emit(payload) {
        if (payload === null) {
            return;
        }

        try {
            env.send(payload);
        } catch (e) {
            // a lost line, never a broken Back
        }

        try {
            const view = document.defaultView || window;
            const Custom = view && typeof view.CustomEvent === 'function' ? view.CustomEvent : null;

            if (Custom) {
                document.dispatchEvent(new Custom(BACK_EVENT, { detail: payload }));
            }
        } catch (e) {
            // nothing
        }
    }

    function readPending() {
        try {
            const store = storage();
            const raw = store ? store.getItem(BACK_PENDING_KEY) : null;

            if (store) {
                store.removeItem(BACK_PENDING_KEY);
            }

            const pending = raw ? JSON.parse(raw) : null;

            return pending && KINDS.includes(pending.kind) && typeof pending.at === 'number' ? pending : null;
        } catch (e) {
            return null;
        }
    }

    function writePending(kind, going) {
        try {
            const store = storage();

            if (store) {
                store.setItem(BACK_PENDING_KEY, JSON.stringify({
                    kind,
                    at: typeof going.at === 'number' ? going.at : now(),
                    dir: going.dir || 'unknown',
                    uaTransition: typeof going.uaTransition === 'boolean' ? going.uaTransition : null,
                }));
            }
        } catch (e) {
            // the next document does not know it came back: no line
        }
    }

    function currentEntryIndex() {
        const entry = window.navigation && window.navigation.currentEntry;

        return entry && typeof entry.index === 'number' ? entry.index : null;
    }

    /** Back or Forward of this document's load or back/forward-cache restore - 'unknown' without the Navigation API */
    function activated() {
        try {
            const activation = window.navigation && window.navigation.activation;

            return activation ? activationDirection(activation, currentEntryIndex()) : 'unknown';
        } catch (e) {
            return 'unknown';
        }
    }

    function loadedByTraverse() {
        try {
            const activation = window.navigation && window.navigation.activation;

            if (activation && typeof activation.navigationType === 'string') {
                return activation.navigationType === 'traverse' ? { dir: activated() } : null;
            }

            const entries = window.performance && window.performance.getEntriesByType ? window.performance.getEntriesByType('navigation') : [];

            return entries[0] && entries[0].type === 'back_forward' ? { dir: 'unknown' } : null;
        } catch (e) {
            return null;
        }
    }

    function uaTransitionOf(event) {
        return event && typeof event.hasUAVisualTransition === 'boolean' ? event.hasUAVisualTransition : null;
    }

    /** The Navigation API announces every traversal before any popstate listener runs (and also one a layer stops) */
    function onNavigate(event) {
        if (!event || event.navigationType !== 'traverse') {
            return;
        }

        // A new traversal: whatever an earlier pop began is over (a page owner's own entry ends in no restore)
        traversal = null;

        try {
            const index = event.destination && typeof event.destination.index === 'number' ? event.destination.index : null;

            announced = { index, dir: direction(currentEntryIndex(), index), uaTransition: uaTransitionOf(event) };
        } catch (e) {
            announced = null;
        }
    }

    /**
     * The traversal that landed on the history entry shown now, begun by whichever sees it first: our popstate listener,
     * or the restore decision when Turbo's popstate listener (or layer_history.js) started the restore before it
     */
    function current(event) {
        const index = historyIndex(window);

        if (traversal !== null && traversal.index === index) {
            // The popstate after Turbo's decision still says whether the browser animated it
            if (traversal.uaTransition === null && uaTransitionOf(event) !== null) {
                traversal.uaTransition = uaTransitionOf(event);

                if (traversal.pending) {
                    writePending(traversal.pending, traversal);
                }
            }

            return traversal;
        }

        const word = announced !== null && announced.index === index ? announced : null;
        const uaTransition = uaTransitionOf(event);

        traversal = {
            index,
            t0: perfNow(),
            at: now(),
            uaTransition: uaTransition !== null ? uaTransition : (word ? word.uaTransition : null),
            dir: word && word.dir !== 'unknown' ? word.dir : direction(lastIndex, index),
        };

        return traversal;
    }

    function onPopState(event) {
        // A page owner's own entries (Results) change the index without a turbo:load: follow every pop seen
        lastIndex = current(event).index;
    }

    function onRestoreDecided(event) {
        const kind = event && event.detail ? event.detail.kind : null;

        if (kind === 'snapshot') {
            snapshot = current(null);
        } else if (kind === 'reload') {
            const going = current(null);
            going.pending = 'reload';
            writePending('reload', going);
        }
    }

    function onTurboLoad() {
        if (arrived !== null) {
            const came = arrived;
            arrived = null;
            emit(buildBackPayload({ kind: came.kind, msToLoad: came.ms(), uaTransition: came.uaTransition, dir: came.dir }));
        } else if (snapshot !== null) {
            const restored = snapshot;
            snapshot = null;
            emit(buildBackPayload({ kind: 'snapshot', msToLoad: perfNow() - restored.t0, uaTransition: restored.uaTransition, dir: restored.dir }));
        }

        traversal = null;
        announced = null;
        lastIndex = historyIndex(window);
    }

    function onBfcacheRestore() {
        let ms = 0;

        try {
            const entries = window.performance && window.performance.getEntriesByType ? window.performance.getEntriesByType('back-forward-cache-restoration') : [];
            const entry = entries && entries.length ? entries[entries.length - 1] : null;

            if (entry && typeof entry.pageshowEventStart === 'number' && typeof entry.startTime === 'number') {
                ms = Math.max(0, entry.pageshowEventStart - entry.startTime);
            }
        } catch (e) {
            // 0 ms
        }

        emit(buildBackPayload({ kind: 'bfcache', msToLoad: ms, uaTransition: null, dir: activated() }));
        traversal = null;
        announced = null;
        lastIndex = historyIndex(window);
    }

    function onBfcacheReload() {
        traversal = { index: historyIndex(window), t0: perfNow(), at: now(), uaTransition: null, dir: activated() };
        writePending('bfcache-reload', traversal);
    }

    const api = {
        install() {
            if (installed) {
                return api;
            }

            installed = true;

            // This document: the end of a full-load Back we started, or a back/forward load the browser made by itself
            const pending = readPending();

            if (pending !== null) {
                arrived = { kind: pending.kind, ms: () => now() - pending.at, dir: pending.dir, uaTransition: pending.uaTransition };
            } else {
                const traverse = loadedByTraverse();

                if (traverse !== null) {
                    arrived = { kind: 'reload', ms: () => perfNow(), dir: traverse.dir, uaTransition: null };
                }
            }

            // Capture at the window, yet not before Turbo's own listener in Chromium (see Direction above); layer_history
            // stops a layer's pop before this
            window.addEventListener('popstate', onPopState, { capture: true });

            try {
                if (window.navigation && typeof window.navigation.addEventListener === 'function') {
                    window.navigation.addEventListener('navigate', onNavigate);
                }
            } catch (e) {
                // no Navigation API: the history index alone
            }

            window.addEventListener(BFCACHE_RESTORE_EVENT, onBfcacheRestore);
            window.addEventListener(BFCACHE_RELOAD_EVENT, onBfcacheReload);
            document.addEventListener(RESTORE_DECIDED_EVENT, onRestoreDecided);
            document.addEventListener('turbo:load', onTurboLoad);

            return api;
        },
    };

    return api;
}

export function installBackMeasurement(env) {
    return createBackMeasurement(env).install();
}
