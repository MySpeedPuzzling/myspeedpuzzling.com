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

    // Where the page was last shown (turbo:load), to tell Back from Forward
    let lastIndex = historyIndex(window);
    // The traversal under way: {t0 (perf), at (epoch), uaTransition, dir}
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

    function writePending(kind) {
        const current = traversal || {};

        try {
            const store = storage();

            if (store) {
                store.setItem(BACK_PENDING_KEY, JSON.stringify({
                    kind,
                    at: typeof current.at === 'number' ? current.at : now(),
                    dir: current.dir || 'unknown',
                    uaTransition: typeof current.uaTransition === 'boolean' ? current.uaTransition : null,
                }));
            }
        } catch (e) {
            // the next document does not know it came back: no line
        }
    }

    function loadedByTraverse() {
        try {
            const activation = window.navigation && window.navigation.activation;

            if (activation && typeof activation.navigationType === 'string') {
                return activation.navigationType === 'traverse' ? { dir: activationDirection(activation) } : null;
            }

            const entries = window.performance && window.performance.getEntriesByType ? window.performance.getEntriesByType('navigation') : [];

            return entries[0] && entries[0].type === 'back_forward' ? { dir: 'unknown' } : null;
        } catch (e) {
            return null;
        }
    }

    function onPopState(event) {
        const index = historyIndex(window);
        const uaTransition = event && typeof event.hasUAVisualTransition === 'boolean' ? event.hasUAVisualTransition : null;

        traversal = { t0: perfNow(), at: now(), uaTransition, dir: direction(lastIndex, index) };
    }

    function onRestoreDecided(event) {
        const kind = event && event.detail ? event.detail.kind : null;

        if (kind === 'snapshot') {
            snapshot = traversal ? { ...traversal } : { t0: perfNow(), at: now(), uaTransition: null, dir: 'unknown' };
        } else if (kind === 'reload') {
            writePending('reload');
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
        lastIndex = historyIndex(window);
    }

    function onBfcacheRestore() {
        let ms = 0;
        let dir = 'unknown';

        try {
            const entries = window.performance && window.performance.getEntriesByType ? window.performance.getEntriesByType('back-forward-cache-restoration') : [];
            const entry = entries && entries.length ? entries[entries.length - 1] : null;

            if (entry && typeof entry.pageshowEventStart === 'number' && typeof entry.startTime === 'number') {
                ms = Math.max(0, entry.pageshowEventStart - entry.startTime);
            }

            const activation = window.navigation && window.navigation.activation;
            dir = activation ? activationDirection(activation) : 'unknown';
        } catch (e) {
            // 0 ms, direction unknown
        }

        emit(buildBackPayload({ kind: 'bfcache', msToLoad: ms, uaTransition: null, dir }));
        lastIndex = historyIndex(window);
    }

    function onBfcacheReload() {
        let dir = 'unknown';

        try {
            const activation = window.navigation && window.navigation.activation;
            dir = activation ? activationDirection(activation) : 'unknown';
        } catch (e) {
            // unknown
        }

        traversal = { t0: perfNow(), at: now(), uaTransition: null, dir };
        writePending('bfcache-reload');
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

            // Capture at the window: before Turbo starts the restore (layer_history stops a layer's pop before this)
            window.addEventListener('popstate', onPopState, { capture: true });
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
