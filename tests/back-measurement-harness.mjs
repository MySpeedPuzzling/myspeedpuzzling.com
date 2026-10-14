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

/** A document of the browser: {window, document, lines, events, clock} */
function page({ store = storage(), index = 3, activation = { navigationType: 'push', from: null, entry: { index } }, restorations = [], navigationApi = true, timingType = null } = {}) {
    const window = new Target();
    const document = new Target();
    const lines = [];
    const events = [];
    const clock = { epoch: 1_700_000_000_000, perf: 1000 };
    window.navigation = navigationApi ? { currentEntry: { index }, activation } : null;
    window.history = { state: { turbo: { restorationIndex: index } } };
    window.performance = {
        now: () => clock.perf,
        getEntriesByType: (type) => (type === 'back-forward-cache-restoration' ? restorations : (timingType ? [{ type: timingType }] : [])),
    };
    window.CustomEvent = FakeCustomEvent;
    document.defaultView = window;
    document.addEventListener(BACK_EVENT, (event) => events.push(event.detail));

    createBackMeasurement({ window, document, storage: store, now: () => clock.epoch, perfNow: () => clock.perf, send: (line) => lines.push(line) }).install();

    return {
        window, document, lines, events, clock, store,
        at: (newIndex) => { if (window.navigation) window.navigation.currentEntry = { index: newIndex }; window.history.state = { turbo: { restorationIndex: newIndex } }; },
        fire: (target, type, extra = {}) => target.dispatchEvent(Object.assign(new FakeCustomEvent(type, { detail: extra.detail }), extra)),
        tick: (ms) => { clock.epoch += ms; clock.perf += ms; },
    };
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

run('blocked storage', () => {
    const blocked = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } };
    const p = page({ store: blocked });
    p.fire(p.window, 'popstate', {});
    p.fire(p.document, RESTORE_DECIDED_EVENT, { detail: { kind: 'reload' } });

    return { lines: p.lines, threw: false };
});

process.stdout.write(JSON.stringify(results));
