// Runs assets/back_measurement.js (docs/features/navigation GEST-08: one line per Back / Forward with how the page came
// back) through the cases tests/BackMeasurementScriptTest.php asks for and prints one JSON object {case: answer}.
// The browser is a fake with just what the module touches; a "next document" is a new instance on a fresh window that
// keeps the session storage.

import { BACK_EVENT, BACK_PENDING_KEY, KINDS, buildBackPayload, createBackMeasurement } from '../assets/back_measurement.js';
import { BFCACHE_RELOAD_EVENT, BFCACHE_RESTORE_EVENT, RESTORE_DECIDED_EVENT } from '../assets/instant_back.js';

const results = {};

function run(name, fn) {
    try {
        results[name] = fn();
    } catch (error) {
        results[name] = { threw: String(error && error.stack ? error.stack : error) };
    }
}

class Target {
    constructor() {
        this.listeners = {};
    }

    addEventListener(type, listener) {
        (this.listeners[type] = this.listeners[type] || []).push(listener);
    }

    dispatchEvent(event) {
        (this.listeners[event.type] || []).forEach((listener) => listener(event));

        return true;
    }
}

class FakeCustomEvent {
    constructor(type, init = {}) {
        this.type = type;
        this.detail = init.detail;
    }
}

function storage(values = {}) {
    return {
        values,
        getItem: (key) => (key in values ? values[key] : null),
        setItem: (key, value) => { values[key] = String(value); },
        removeItem: (key) => { delete values[key]; },
    };
}

/**
 * A document of the browser: {window, document, lines, events, clock}. `before(window, document)` adds listeners before
 * the module installs - a window's listeners run in the order they were added (Chromium, capture or not), as Turbo's
 * popstate listener does. `turboIndex: false` = entries without Turbo's restoration index.
 */
function page({ store = storage(), index = 3, activation = { navigationType: 'push', from: null, entry: { index } }, restorations = [], navigationApi = true, timingType = null, turboIndex = true, before = null } = {}) {
    const window = new Target();
    const document = new Target();
    const lines = [];
    const events = [];
    const clock = { epoch: 1_700_000_000_000, perf: 1000 };
    window.navigation = navigationApi ? Object.assign(new Target(), { currentEntry: { index }, activation }) : null;
    window.history = { state: turboIndex ? { turbo: { restorationIndex: index } } : null };
    window.performance = {
        now: () => clock.perf,
        getEntriesByType: (type) => (type === 'back-forward-cache-restoration' ? restorations : (timingType ? [{ type: timingType }] : [])),
    };
    window.CustomEvent = FakeCustomEvent;
    document.defaultView = window;
    document.addEventListener(BACK_EVENT, (event) => events.push(event.detail));

    if (before) {
        before(window, document);
    }

    createBackMeasurement({ window, document, storage: store, now: () => clock.epoch, perfNow: () => clock.perf, send: (line) => lines.push(line) }).install();

    const fire = (target, type, extra = {}) => target.dispatchEvent(Object.assign(new FakeCustomEvent(type, { detail: extra.detail }), extra));
    const at = (newIndex) => { if (window.navigation) window.navigation.currentEntry = { index: newIndex }; window.history.state = turboIndex ? { turbo: { restorationIndex: newIndex } } : null; };

    return {
        window, document, lines, events, clock, store, at, fire,
        tick: (ms) => { clock.epoch += ms; clock.perf += ms; },
        /**
         * A traversal the way the browser runs it: the Navigation API's navigate event (from the entry shown), the
         * history moves, then popstate - unless layer_history stops it (`popstate: false`)
         */
        traverse: (toIndex, { ua = null, popstate = true } = {}) => {
            if (window.navigation) {
                fire(window.navigation, 'navigate', { navigationType: 'traverse', destination: { index: toIndex }, hasUAVisualTransition: ua });
            }

            at(toIndex);

            if (popstate) {
                fire(window, 'popstate', ua === null ? {} : { hasUAVisualTransition: ua });
            }
        },
    };
}

/** Turbo's popstate listener, added when Turbo starts (before the app's): the restore is decided inside it */
function turboFirst(options = {}) {
    const decide = { kind: null };
    const p = page({
        ...options,
        before: (window, document) => window.addEventListener('popstate', () => {
            if (decide.kind !== null) {
                document.dispatchEvent(new FakeCustomEvent(RESTORE_DECIDED_EVENT, { detail: { kind: decide.kind, url: '/en/hub' } }));
            }
        }),
    });

    return { p, decide };
}

/** A Back / Forward Turbo restores from its snapshot - the line, or a full load - the mark the next document reads */
function restoreBy(kind, { navigationApi = true, from = 3, to = 2, ua = null } = {}) {
    const store = storage();
    const { p, decide } = turboFirst({ store, index: from, navigationApi });
    decide.kind = kind;
    p.traverse(to, { ua });
    p.tick(40);
    p.fire(p.document, 'turbo:load');

    return kind === 'snapshot' ? p.lines : (({ kind: k, dir, uaTransition }) => ({ kind: k, dir, uaTransition }))(JSON.parse(store.values[BACK_PENDING_KEY]));
}

run('contract', () => ({ BACK_PENDING_KEY, BACK_EVENT, KINDS }));

run('payloads', () => ({
    snapshot: buildBackPayload({ kind: 'snapshot', msToLoad: 41.6, uaTransition: false, dir: 'back' }),
    reload: buildBackPayload({ kind: 'reload', msToLoad: 1200, dir: 'forward' }),
    bfcache: buildBackPayload({ kind: 'bfcache', msToLoad: 0, uaTransition: null }),
    bfcacheReload: buildBackPayload({ kind: 'bfcache-reload', msToLoad: -3, dir: 'sideways' }),
    unknown: buildBackPayload({ kind: 'prerender', msToLoad: 5 }),
    nothing: buildBackPayload(),
}));

run('snapshot restore', () => {
    const p = page({ index: 3 });
    p.at(2);
    p.fire(p.window, 'popstate', { hasUAVisualTransition: false });
    p.fire(p.document, RESTORE_DECIDED_EVENT, { detail: { kind: 'snapshot', url: '/en/hub' } });
    p.tick(38);
    p.fire(p.document, 'turbo:load');
    // A visit afterwards is no Back
    p.fire(p.document, 'turbo:load');

    return { lines: p.lines, events: p.events };
});

run('a layer closed by Back is no line', () => {
    // layer_history stops that popstate before this module: no popstate here, no decision, a turbo:load later
    const p = page();
    p.fire(p.document, 'turbo:load');

    return { lines: p.lines };
});

run('full-load restore', () => {
    const store = storage();
    const leaving = page({ store, index: 5 });
    leaving.at(4);
    leaving.fire(leaving.window, 'popstate', { hasUAVisualTransition: true });
    leaving.fire(leaving.document, RESTORE_DECIDED_EVENT, { detail: { kind: 'reload', url: '/en/stopwatch' } });
    const pending = JSON.parse(store.values[BACK_PENDING_KEY]);

    // The next document: measured at its first turbo:load, once
    const next = page({ store, index: 4, activation: { navigationType: 'replace', from: null, entry: { index: 4 } } });
    next.clock.epoch = pending.at + 850;
    next.fire(next.document, 'turbo:load');
    next.fire(next.document, 'turbo:load');

    return { pending: { kind: pending.kind, dir: pending.dir, uaTransition: pending.uaTransition }, lines: next.lines, pendingLeft: store.values[BACK_PENDING_KEY] ?? null };
});

run('back/forward cache', () => {
    const p = page({ index: 6, restorations: [{ startTime: 5000, pageshowEventStart: 5004.4 }] });
    p.window.navigation.activation = { navigationType: 'traverse', from: { index: 7 }, entry: { index: 6 } };
    p.fire(p.window, BFCACHE_RESTORE_EVENT);

    // A copy that reloads: the next document says so
    const store = storage();
    const old = page({ store, index: 2 });
    old.window.navigation.activation = { navigationType: 'traverse', from: { index: 1 }, entry: { index: 2 } };
    old.fire(old.window, BFCACHE_RELOAD_EVENT);
    const next = page({ store, index: 2, timingType: 'reload', activation: { navigationType: 'reload', from: null, entry: { index: 2 } } });
    next.clock.epoch += 640;
    next.fire(next.document, 'turbo:load');

    return { kept: p.lines, reloaded: next.lines };
});

run('a back/forward load without us', () => {
    // Back from another site to a page out of both caches: the browser loads it, no pending mark
    const p = page({ index: 1, activation: { navigationType: 'traverse', from: { index: 2 }, entry: { index: 1 } } });
    p.clock.perf = 730;
    p.fire(p.document, 'turbo:load');

    // Without the Navigation API: the timing entry knows it, not the direction
    const old = page({ index: 1, navigationApi: false, timingType: 'back_forward' });
    old.clock.perf = 500;
    old.fire(old.document, 'turbo:load');

    // A normal load: no line
    const normal = page({ index: 1 });
    normal.fire(normal.document, 'turbo:load');

    return { traverse: p.lines, timingOnly: old.lines, normal: normal.lines };
});

run('Turbo decides the restore before our popstate listener', () => ({
    // Chromium: Turbo's popstate listener (added first) decides the restore before this module's capture listener runs
    snapshotBack: restoreBy('snapshot', { from: 3, to: 2, ua: true }),
    snapshotForward: restoreBy('snapshot', { from: 2, to: 3, ua: false }),
    reloadBack: restoreBy('reload', { from: 5, to: 4, ua: true }),
    reloadForward: restoreBy('reload', { from: 4, to: 5 }),
}));

run('without the Navigation API: Turbo\'s restoration index', () => {
    // A Back and a Forward after a snapshot restore in the same document: the index shown follows each one
    const { p, decide } = turboFirst({ index: 3, navigationApi: false });
    decide.kind = 'snapshot';
    p.traverse(2, { ua: false });
    p.tick(30);
    p.fire(p.document, 'turbo:load');
    p.traverse(3);
    p.tick(20);
    p.fire(p.document, 'turbo:load');

    return {
        snapshots: p.lines,
        reloadBack: restoreBy('reload', { navigationApi: false, from: 6, to: 5, ua: true }),
        reloadForward: restoreBy('reload', { navigationApi: false, from: 5, to: 6 }),
    };
});

run('a pop layer_history stops and restores itself', () => {
    // The Results page (a page owner) at 3 pushed its own entries 4 and 5; a Back to 4 is its own (no restore), the Back
    // from 4 to 2 leaves the page: layer_history stops that popstate and starts the restore - no popstate reaches us
    const run = (navigationApi) => {
        const p = page({ index: 3, navigationApi });
        p.at(5);
        p.traverse(4, { ua: false });
        p.tick(5000);
        p.traverse(2, { ua: true, popstate: false });
        p.fire(p.document, RESTORE_DECIDED_EVENT, { detail: { kind: 'snapshot', url: '/en/hub' } });
        p.tick(45);
        p.fire(p.document, 'turbo:load');

        return p.lines;
    };

    return { navigationApi: run(true), turboIndex: run(false) };
});

run('a traversal whose activation entry Turbo replaced', () => {
    // Turbo's history.replaceState at start replaces the entry navigation.activation.entry points at: index -1
    const replaced = (from) => ({ navigationType: 'traverse', from: { index: from }, entry: { index: -1 } });
    const load = page({ index: 1, activation: replaced(2) });
    load.clock.perf = 610;
    load.fire(load.document, 'turbo:load');

    const forward = page({ index: 4, activation: replaced(3) });
    forward.clock.perf = 420;
    forward.fire(forward.document, 'turbo:load');

    const kept = page({ index: 6, restorations: [{ startTime: 100, pageshowEventStart: 102 }] });
    kept.window.navigation.activation = replaced(7);
    kept.fire(kept.window, BFCACHE_RESTORE_EVENT);

    const store = storage();
    const old = page({ store, index: 2 });
    old.window.navigation.activation = replaced(1);
    old.fire(old.window, BFCACHE_RELOAD_EVENT);
    const pending = JSON.parse(store.values[BACK_PENDING_KEY]);

    return { load: load.lines, forward: forward.lines, bfcache: kept.lines, bfcacheReload: pending.dir };
});

run('a direction nobody can know', () => {
    // No Navigation API and no Turbo restoration index on the entries: unknown, never a guess
    const { p, decide } = turboFirst({ index: 3, navigationApi: false, turboIndex: false });
    decide.kind = 'snapshot';
    p.traverse(2);
    p.tick(10);
    p.fire(p.document, 'turbo:load');

    // A back/forward-cache restore without the Navigation API
    const kept = page({ index: 1, navigationApi: false });
    kept.fire(kept.window, BFCACHE_RESTORE_EVENT);

    return { snapshot: p.lines, bfcache: kept.lines };
});

run('blocked storage', () => {
    const blocked = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } };
    const p = page({ store: blocked });
    p.fire(p.window, 'popstate', {});
    p.fire(p.document, RESTORE_DECIDED_EVENT, { detail: { kind: 'reload' } });

    return { lines: p.lines, threw: false };
});

process.stdout.write(JSON.stringify(results));
