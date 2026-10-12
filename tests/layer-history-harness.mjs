// Runs assets/layer_history.js (Back closes the top layer, GEST-01) through every case of
// tests/LayerHistoryScriptTest.php and prints what each one did as JSON: {case: facts}.
//
// The browser is a fake built for the history: jsdom for the elements (links, forms, frames, closest()), a window whose
// popstate listeners run capture-first at the target and honour stopImmediatePropagation, a history stack whose back()
// pops asynchronously (a virtual clock), and a Turbo session written after Turbo 8.0.23's own History / Navigator /
// Visit: start() mints a new restoration identifier and listens to popstate again, a pop with Turbo state starts a
// restore visit, one without calls historyPoppedWithEmptyState, a visit writes history only at render (changeHistory),
// a link's action is read right after turbo:click, a form's only after turbo:submit-end (review F4).

import { createRequire } from 'node:module';
import { createLayerHistory, startsPageVisit, LAYER_STATE, PAGE_STATE } from '../assets/layer_history.js';
import { createNavInstrumentation } from '../assets/nav_instrumentation.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const ORIGIN = 'https://msp.test';

const clone = (value) => (value === undefined || value === null ? null : JSON.parse(JSON.stringify(value)));

class Clock {
    now = 0;
    timers = [];
    seq = 0;

    setTimeout(fn, ms) {
        const id = ++this.seq;
        this.timers.push({ id, at: this.now + (ms || 0), fn });

        return id;
    }

    clearTimeout(id) {
        this.timers = this.timers.filter((timer) => timer.id !== id);
    }

    advance(ms) {
        const end = this.now + ms;

        for (;;) {
            this.timers.sort((a, b) => a.at - b.at || a.id - b.id);
            const timer = this.timers[0];

            if (!timer || timer.at > end) {
                break;
            }

            this.timers.shift();
            this.now = timer.at;
            timer.fn();
        }

        this.now = end;
    }
}

/** The window as an event target: capture listeners first at the target, a snapshot per dispatch */
class FakeWindow {
    listeners = [];

    constructor(env) {
        this.env = env;
        this.performance = { now: () => env.clock.now };
        this.crypto = globalThis.crypto;
        this.navigator = { sendBeacon: (url, body) => (env.beacons.push({ url, ...JSON.parse(body) }), true) };
        this.innerWidth = 360;
        this.matchMedia = () => ({ matches: false });
        this.sessionStorage = { getItem: () => '1', setItem() {} };
        this.console = { warn: (message) => env.warnings.push(String(message)) };
    }

    get location() {
        return this.env.location;
    }

    get history() {
        return this.env.history;
    }

    addEventListener(type, fn, options) {
        this.listeners.push({ type, fn, capture: options === true || Boolean(options && options.capture) });
    }

    removeEventListener(type, fn, options) {
        const capture = options === true || Boolean(options && options.capture);
        this.listeners = this.listeners.filter((l) => !(l.type === type && l.fn === fn && l.capture === capture));
    }

    dispatch(type, props) {
        let stopped = false;
        const event = {
            type,
            ...props,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
            stopImmediatePropagation() {
                stopped = true;
            },
            stopPropagation() {},
        };
        const snapshot = [...this.listeners.filter((l) => l.type === type && l.capture), ...this.listeners.filter((l) => l.type === type && !l.capture)];

        for (const listener of snapshot) {
            if (stopped) {
                break;
            }

            listener.fn.call(this, event);
        }

        return event;
    }
}

class FakeHistory {
    calls = [];

    constructor(env, url, state) {
        this.env = env;
        this.entries = [{ url, state: clone(state) }];
        this.index = 0;
    }

    get state() {
        return clone(this.entries[this.index].state);
    }

    get length() {
        return this.entries.length;
    }

    get url() {
        return this.entries[this.index].url;
    }

    absolute(url) {
        return url === undefined || url === null ? this.url : new URL(String(url), this.url).href;
    }

    pushState(state, _title, url) {
        this.calls.push('push');
        this.entries.splice(this.index + 1);
        this.entries.push({ url: this.absolute(url), state: clone(state) });
        this.index++;
    }

    replaceState(state, _title, url) {
        this.calls.push('replace');
        this.entries[this.index] = { url: this.absolute(url), state: clone(state) };
    }

    back() {
        this.calls.push('back');
        this.go(-1);
    }

    forward() {
        this.go(1);
    }

    go(delta) {
        // The browser traverses asynchronously
        this.env.clock.setTimeout(() => {
            const to = this.index + delta;

            if (to < 0 || to >= this.entries.length) {
                return;
            }

            this.index = to;
            this.env.window.dispatch('popstate', { state: clone(this.entries[to].state) });
        }, 1);
    }

    /** A full page load of `url` at the current position (app.js's restore → location.href) */
    load(url) {
        this.entries[this.index] = { url: this.absolute(url), state: null };
    }
}

class FakeLocation {
    calls = [];

    constructor(env) {
        this.env = env;
    }

    get href() {
        return this.env.history.url;
    }

    get pathname() {
        return new URL(this.href).pathname;
    }

    get origin() {
        return new URL(this.href).origin;
    }

    replace(url) {
        this.calls.push(['replace', String(url)]);
        this.env.history.entries[this.env.history.index] = { url: new URL(String(url), this.href).href, state: null };
    }

    assign(url) {
        this.calls.push(['assign', String(url)]);
    }

    reload() {
        this.calls.push(['reload']);
    }
}

/** Turbo 8.0.23's History / Navigator / Visit, reduced to what decides the history */
function fakeTurbo(env, { noIdentity = false, noNavigator = false } = {}) {
    let ids = 0;
    const uuid = () => `turbo-${++ids}`;
    const session = { visits: [], emptyPops: 0, navigator: noNavigator ? {} : { currentVisit: null } };

    const history = {
        started: false,
        starts: 0,
        stops: 0,
        currentIndex: 0,
        restorationIdentifier: uuid(),
        location: new URL(env.history.url),
        start() {
            if (!this.started) {
                env.window.addEventListener('popstate', this.onPopState, false);
                this.currentIndex = (env.history.state && env.history.state.turbo && env.history.state.turbo.restorationIndex) || 0;
                this.started = true;
                this.starts++;
                this.update('replace', new URL(env.location.href));
            }
        },
        stop() {
            if (this.started) {
                env.window.removeEventListener('popstate', this.onPopState, false);
                this.started = false;
                this.stops++;
            }
        },
        push(location, id) {
            this.update('push', location, id);
        },
        replace(location, id) {
            this.update('replace', location, id);
        },
        update(method, location, id = uuid()) {
            if (method === 'push') {
                ++this.currentIndex;
            }

            const state = { turbo: { restorationIdentifier: id, restorationIndex: this.currentIndex } };
            env.history[method === 'push' ? 'pushState' : 'replaceState'](state, '', location.href);
            this.location = location;
            this.restorationIdentifier = id;
        },
        onPopState: (event) => {
            const turbo = (event.state || {}).turbo;
            history.location = new URL(env.location.href);

            if (turbo) {
                history.restorationIdentifier = turbo.restorationIdentifier;
                session.visit(env.location.href, { action: 'restore', historyChanged: true, restorationIdentifier: turbo.restorationIdentifier, fromTurboPop: true });
                history.currentIndex = turbo.restorationIndex;
            } else {
                history.currentIndex++;
                session.emptyPops++;
                history.update('replace', new URL(env.location.href));
            }
        },
    };

    if (noIdentity) {
        // A future Turbo with other internals: start/stop stay, the index and replace are gone
        const update = history.update.bind(history);
        history.update = (method, location, id) => update(method, location, id);
        delete history.currentIndex;
        delete history.replace;
        Object.defineProperty(history, 'currentIndex', { get: () => undefined, set() {}, configurable: true });
    }

    session.history = history;
    session.visit = (url, options = {}) => {
        const visit = { url: new URL(String(url), env.location.href).href, action: options.action || 'advance', options, referrer: env.location.href };
        session.visits.push(visit);

        if ('currentVisit' in session.navigator) {
            session.navigator.currentVisit = visit;
        }

        env.document.dispatchEvent(new env.dom.window.CustomEvent('turbo:visit', { bubbles: true, detail: { url: visit.url, action: visit.action } }));

        return visit;
    };
    /** The visit's response rendered: Visit#changeHistory, then turbo:load */
    session.render = (visit) => {
        if (!visit.options.historyChanged) {
            const method = visit.url === visit.referrer ? 'replace' : (visit.action === 'advance' ? 'push' : 'replace');
            // Turbo's update() writes even while its popstate listener is stopped
            const current = history.currentIndex;
            history.currentIndex = typeof current === 'number' ? current : 0;
            history.update(method, new URL(visit.url));
        }

        env.document.dispatchEvent(new env.dom.window.CustomEvent('turbo:load', { bubbles: true }));
    };

    return session;
}

const BODY = `
<main id="page">
  <a id="page-link" href="/en/puzzle">Puzzles</a>
  <a id="external" href="https://example.org/x">External</a>
  <a id="no-turbo" href="/logout" data-turbo="false">Log out</a>
  <a id="download" href="/export.csv" download>Export</a>
  <a id="opens-modal" href="/en/help" data-turbo-frame="modal-frame">Help</a>
  <turbo-frame id="results-frame"><a id="in-frame" href="/en/results?page=2">More</a><a id="top-in-frame" href="/en/puzzle/1" data-turbo-frame="_top">Puzzle</a></turbo-frame>
  <turbo-frame id="escape-frame" target="_top"><a id="in-top-frame" href="/en/faq">FAQ</a></turbo-frame>
</main>
<div id="dynamic-modal-container"><turbo-frame id="modal-frame">
  <a id="modal-frame-link" href="/en/help/next">Next</a>
  <a id="modal-top-link" href="/en/faq" data-turbo-frame="_top">FAQ</a>
  <form id="modal-frame-form" method="post" action="/en/feedback"></form>
  <form id="modal-top-form" method="post" action="/en/compare/add" data-turbo-frame="_top"></form>
</turbo-frame></div>
<dialog id="menu-layer"><a id="menu-row" href="/en/hub">Hub</a></dialog>
<nav id="bottom-bar"><a id="tab" href="/en/community">Community</a></nav>
<dialog id="confirm"></dialog>
`;

/**
 * A fresh page at `path` (entry index `index`, Turbo running) with the module installed on it.
 * options: native (data-native-app), turbo ({noIdentity, noNavigator}), noTurbo, entries (earlier pages), instrument.
 */
function page(path = '/en/faq', options = {}) {
    const env = { clock: new Clock(), beacons: [], warnings: [] };
    // jsdom cannot navigate: a click nobody prevented (download, ctrl click) would only log "not implemented"
    const virtualConsole = new VirtualConsole();
    env.dom = new JSDOM(`<!doctype html><html><body>${BODY}</body></html>`, { url: ORIGIN + path, virtualConsole });
    env.document = env.dom.window.document;

    if (options.native) {
        env.document.documentElement.setAttribute('data-native-app', 'android');
    }

    const urls = [...(options.entries || []), path];
    env.window = new FakeWindow(env);
    env.history = new FakeHistory(env, ORIGIN + urls[0], null);
    urls.slice(1).forEach((url) => env.history.entries.push({ url: ORIGIN + url, state: null }));
    env.history.index = env.history.entries.length - 1;

    // Every earlier entry carries the Turbo state Turbo gave it
    env.history.entries.forEach((entry, index) => {
        entry.state = { turbo: { restorationIdentifier: `page-${index}`, restorationIndex: index } };
    });
    env.location = new FakeLocation(env);
    env.session = options.noTurbo ? null : fakeTurbo(env, options.turbo || {});

    if (env.session) {
        // The page was loaded: Turbo started and kept the entry's identity (as its first load did)
        env.session.history.started = true;
        env.session.history.currentIndex = env.history.index;
        env.session.history.restorationIdentifier = `page-${env.history.index}`;
        env.window.addEventListener('popstate', env.session.history.onPopState, false);
    }

    env.layers = createLayerHistory({
        window: env.window,
        document: env.document,
        history: env.history,
        location: env.location,
        turbo: () => env.session,
        setTimeout: (fn, ms) => env.clock.setTimeout(fn, ms),
        clearTimeout: (id) => env.clock.clearTimeout(id),
        console: env.window.console,
    }).install();

    if (options.instrument) {
        // P0-03 started by app.js after the module's import, and app.js's restore handler
        env.instrumentation = createNavInstrumentation({ window: env.window, document: env.document, navigator: env.window.navigator, storage: env.window.sessionStorage }).start();
        env.document.addEventListener('turbo:visit', (event) => {
            if (event.detail.action === 'restore') {
                env.instrumentation.turboRestore();
            }
        });
    }

    env.$ = (id) => env.document.getElementById(id);
    env.closes = [];
    env.layer = (id, element = null) => env.layers.open(id, { element, onClose: ({ reason }) => env.closes.push(`${id}:${reason}`) });

    return env;
}

/** Turbo's link handling: turbo:click on the link, then the action read right after it (getActionForLink) */
function turboClick(env, link) {
    const event = new env.dom.window.CustomEvent('turbo:click', { bubbles: true, cancelable: true, detail: { url: link.href } });
    link.dispatchEvent(event);
    const action = link.getAttribute('data-turbo-action') || 'advance';

    return { action, prevented: event.defaultPrevented };
}

/** Turbo's form submission order: submit-start, the response, submit-end, and only then the action is read (F4) */
function turboSubmit(env, form, { frame = null, submitter = null } = {}) {
    const formSubmission = { formElement: form, submitter, fetchRequest: { headers: frame ? { 'Turbo-Frame': frame } : {} } };
    form.dispatchEvent(new env.dom.window.CustomEvent('turbo:submit-start', { bubbles: true, detail: { formSubmission } }));
    const atSubmitStart = form.getAttribute('data-turbo-action');
    form.dispatchEvent(new env.dom.window.CustomEvent('turbo:submit-end', { bubbles: true, detail: { formSubmission, success: true } }));
    const atSubmitEnd = form.getAttribute('data-turbo-action');
    // formSubmissionSucceededWithResponse awaited responseHTML: getVisitAction(submitter, form) now
    const action = (submitter && submitter.getAttribute('data-turbo-action')) || form.getAttribute('data-turbo-action') || 'advance';

    return { atSubmitStart, atSubmitEnd, action };
}

function click(env, element, init = {}) {
    const event = new env.dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    element.dispatchEvent(event);

    return event.defaultPrevented;
}

function key(env, keyName, { prevented = false } = {}) {
    const event = new env.dom.window.KeyboardEvent('keydown', { key: keyName, bubbles: true, cancelable: true });

    if (prevented) {
        event.preventDefault();
    }

    env.document.body.dispatchEvent(event);

    return event.defaultPrevented;
}

const stateOf = (env) => env.history.state;
const historyUrls = (env) => env.history.entries.map((entry) => new URL(entry.url).pathname);

const cases = {
    // (a) two owners: the first resume does not restart Turbo, the last does
    'two owners': () => {
        const env = page('/en/puzzlers/abc/results');
        env.layers.pauseTurboHistory('results');
        env.layers.replacePageState('results', '/en/puzzlers/abc/results');
        env.layer('menu');
        const stoppedOnce = env.session.history.stops;
        env.layers.requestClose('menu');
        env.clock.advance(5);
        const afterLayerClose = env.session.history.started;
        env.layers.resumeTurboHistory('results');

        return { stops: stoppedOnce, startedAfterTheLayerClosed: afterLayerClose, startedAfterResults: env.session.history.started, closes: env.closes };
    },

    // (b) a second layer takes the entry over: replaceState, no back()
    'swap': () => {
        const env = page();
        env.layer('menu');
        const length = env.history.length;
        env.layer('modal');

        return { lengthBefore: length, lengthAfter: env.history.length, calls: env.history.calls, state: stateOf(env), current: env.layers.current(), closes: env.closes };
    },

    // (c) a popstate closes the layer and resumes Turbo with the page's identity
    'popstate closes': () => {
        const env = page('/en/faq', { entries: ['/en/hub', '/en/puzzle'] });
        env.layer('menu');
        const opened = { length: env.history.length, index: env.history.index, state: stateOf(env), turboStarted: env.session.history.started };
        env.history.back();
        env.clock.advance(5);

        return {
            opened,
            closes: env.closes,
            isOpen: env.layers.isOpen(),
            index: env.history.index,
            url: new URL(env.location.href).pathname,
            turboStarted: env.session.history.started,
            restorationIdentifier: env.session.history.restorationIdentifier,
            currentIndex: env.session.history.currentIndex,
            entryState: stateOf(env),
            turboVisits: env.session.visits.length,
            lastLayerBackAt: env.layers.lastLayerBackAt(),
        };
    },

    // × twice in a row: exactly one back()
    'close is one back': () => {
        const env = page();
        env.layer('modal');
        env.layers.requestClose('modal');
        env.layers.requestClose('modal');
        env.layers.requestClose();
        const backs = env.history.calls.filter((call) => call === 'back').length;
        env.clock.advance(5);

        return { backs, closes: env.closes, index: env.history.index, length: env.history.length };
    },

    // the current entry is not the layer's any more: no back(), closed at once
    'state mismatch': () => {
        const env = page();
        env.layer('menu');
        env.history.replaceState({ somebodyElse: true }, '', env.location.href);
        env.layers.requestClose('menu');

        return { backs: env.history.calls.filter((call) => call === 'back').length, closes: env.closes, isOpen: env.layers.isOpen(), turboStarted: env.session.history.started };
    },

    // an open while a close waits for its popstate: no push before the pop, then one entry
    'open during pending close': () => {
        const env = page();
        env.layer('menu');
        env.layers.requestClose('menu');
        env.layer('modal');
        const pushesBeforePop = env.history.calls.filter((call) => call === 'push').length;
        env.clock.advance(5);

        return { pushesBeforePop, pushesAfter: env.history.calls.filter((call) => call === 'push').length, closes: env.closes, current: env.layers.current(), index: env.history.index, state: stateOf(env) };
    },

    // the open does not wait longer than 300 ms for a pop that never comes
    'open waits at most 300 ms': () => {
        const env = page();
        env.layer('menu');
        env.history.go = () => {};
        env.layers.requestClose('menu');
        env.layer('modal');
        env.clock.advance(299);
        const at299 = env.layers.current();
        env.clock.advance(2);

        return { at299, at301: env.layers.current(), closes: env.closes, warned: env.warnings.length };
    },

    // (d) replace marking
    'replace marking': () => {
        const marks = {};
        const scenarios = [
            ['page link', 'page-link'],
            ['_top link inside a frame', 'top-in-frame'],
            ['link in a frame with target _top', 'in-top-frame'],
            ['_top link inside the modal frame', 'modal-top-link'],
            ['tab under the Menu', 'tab'],
            ['Menu row', 'menu-row'],
            ['modal-frame link', 'opens-modal'],
            ['link inside the modal frame', 'modal-frame-link'],
            ['link inside a frame', 'in-frame'],
        ];

        // A page none of the links lead to (a link to the layer's own page goes back first: 'leave to the same page')
        for (const [name, id] of scenarios) {
            const env = page('/en/start');
            env.layer('menu', env.$('menu-layer'));
            const { action } = turboClick(env, env.$(id));
            marks[name] = action;
        }

        // without a layer nothing is marked
        const env = page('/en/start');
        marks['page link, no layer'] = turboClick(env, env.$('page-link')).action;

        return marks;
    },

    // the marker is put back at turbo:visit (the link keeps its own value)
    'marker restored': () => {
        const env = page();
        env.$('page-link').setAttribute('data-turbo-action', 'advance');
        env.layer('menu');
        const { action } = turboClick(env, env.$('page-link'));
        env.session.visit(env.$('page-link').href, { action });

        return { action, afterVisit: env.$('page-link').getAttribute('data-turbo-action'), closes: env.closes, turboStarted: env.session.history.started };
    },

    // forms: a page form inside the layer is marked and stays marked past turbo:submit-end; a frame form is not
    'form marking': () => {
        const page1 = page();
        page1.layer('modal');
        const pageForm = turboSubmit(page1, page1.$('modal-top-form'));
        page1.session.visit(ORIGIN + '/en/compare', { action: pageForm.action });
        const restoredAtVisit = page1.$('modal-top-form').getAttribute('data-turbo-action');

        const page2 = page();
        page2.layer('modal');
        const frameForm = turboSubmit(page2, page2.$('modal-frame-form'), { frame: 'modal-frame' });

        const page3 = page();
        const noLayer = turboSubmit(page3, page3.$('modal-top-form'));

        return { pageForm, restoredAtVisit, frameForm, noLayer };
    },

    // leaving from a layer through a Turbo visit: history reads [A, B], never [A, A', B]
    'leave by link': () => {
        const env = page('/en/puzzle', { entries: ['/en/hub'] });
        env.layer('modal');
        const { action } = turboClick(env, env.$('modal-top-link'));
        const visit = env.session.visit(env.$('modal-top-link').href, { action });
        env.session.render(visit);

        return { urls: historyUrls(env), index: env.history.index, closes: env.closes, turboStarted: env.session.history.started, state: stateOf(env).turbo.restorationIndex };
    },

    // a link to the very page the layer was opened on (the Menu row of the current page): back first, then the visit
    'leave to the same page': () => {
        const env = page('/en/faq', { entries: ['/en/hub'] });
        env.layer('modal');
        const { action, prevented } = turboClick(env, env.$('modal-top-link'));
        const visitsBeforePop = env.session.visits.length;
        env.clock.advance(5);
        const visit = env.session.visits[0];
        env.session.render(visit);

        return { prevented, markedAction: action, visitsBeforePop, visit: { url: new URL(visit.url).pathname, action: visit.action }, backStack: historyUrls(env).slice(0, env.history.index + 1), closes: env.closes };
    },

    // the same through a POST form with data-turbo-frame="_top" inside the layer (F4)
    'leave by form': () => {
        const env = page('/en/faq', { entries: ['/en/hub'] });
        env.layer('modal');
        const { action } = turboSubmit(env, env.$('modal-top-form'));
        const visit = env.session.visit(ORIGIN + '/en/compare', { action });
        env.session.render(visit);

        return { action, urls: historyUrls(env), index: env.history.index };
    },

    // data-turbo="false" and other-origin links: location.replace(); download / modified clicks untouched
    'non-Turbo links': () => {
        const result = {};

        for (const [name, id, init] of [['data-turbo=false', 'no-turbo', {}], ['external', 'external', {}], ['download', 'download', {}], ['ctrl click external', 'external', { ctrlKey: true }]]) {
            const env = page();
            env.layer('menu');
            const prevented = click(env, env.$(id), init);
            result[name] = { prevented, location: env.location.calls, closes: env.closes, isOpen: env.layers.isOpen() };
        }

        const env = page();
        result['no layer'] = { prevented: click(env, env.$('no-turbo')), location: env.location.calls };

        return result;
    },

    // resume keeps Turbo's restoration identifier and index (Turbo's start() alone would mint a new one)
    'resume keeps identity': () => {
        const env = page('/en/puzzle', { entries: ['/en/hub', '/en/faq', '/en/events'] });
        const before = { id: env.session.history.restorationIdentifier, index: env.session.history.currentIndex };
        env.layer('card');
        env.history.back();
        env.clock.advance(5);
        const afterBack = { id: env.session.history.restorationIdentifier, index: env.session.history.currentIndex, state: stateOf(env).turbo };
        env.layer('card');
        env.layers.requestClose('card');
        env.clock.advance(5);

        return { before, afterBack, afterClose: { id: env.session.history.restorationIdentifier, index: env.session.history.currentIndex }, starts: env.session.history.starts };
    },

    // long-press Back past the layer onto another page: the layer closes, one restore visit, one P0-03 history line
    'pop to another URL': () => {
        const env = page('/en/faq', { entries: ['/en/hub', '/en/puzzle'], instrument: true });
        env.layer('menu');
        env.history.go(-2);
        env.clock.advance(5);
        const restores = env.session.visits.filter((visit) => visit.action === 'restore');

        return {
            closes: env.closes,
            url: new URL(env.location.href).pathname,
            restores: restores.map((visit) => ({ url: new URL(visit.url).pathname, restorationIdentifier: visit.options.restorationIdentifier })),
            historyBeacons: env.beacons.filter((beacon) => beacon.k === 'history').map((beacon) => beacon.via),
        };
    },

    // P0-03: a layer close by Back is never counted; a real Back still is, exactly once
    'back logging': () => {
        const env = page('/en/faq', { entries: ['/en/hub'], instrument: true });
        env.layer('modal');
        env.history.back();
        env.clock.advance(5);
        const afterLayerClose = env.beacons.filter((beacon) => beacon.k === 'history').length;
        env.history.back();
        env.clock.advance(5);

        return { afterLayerClose, afterRealBack: env.beacons.filter((beacon) => beacon.k === 'history').map((beacon) => beacon.via), turboRestores: env.session.visits.filter((visit) => visit.action === 'restore').length };
    },

    // Forward onto a closed layer's entry: never reopened, no visit, no load (decision 58 D-4)
    'dead entry ignored': () => {
        const env = page();
        env.layer('menu');
        env.history.back();
        env.clock.advance(5);
        env.history.forward();
        env.clock.advance(5);

        return { closes: env.closes, isOpen: env.layers.isOpen(), visits: env.session.visits.length, emptyPops: env.session.emptyPops, url: new URL(env.location.href).pathname };
    },

    // (f) the native app: no entry, no pause, a close without back()
    'native app': () => {
        const env = page('/en/faq', { native: true });
        env.layer('menu');
        const opened = { calls: [...env.history.calls], stops: env.session.history.stops, isOpen: env.layers.isOpen('menu') };
        env.layers.requestClose('menu');
        env.layer('card');
        env.layer('modal');
        const { action } = turboClick(env, env.$('page-link'));

        return { opened, calls: env.history.calls, closes: env.closes, current: env.layers.current(), linkAction: action };
    },

    // Esc closes the top layer through Back, unless something already took it or a foreign modal dialog is open
    'escape': () => {
        const plain = page();
        plain.layer('menu');
        const handled = key(plain, 'Escape');
        plain.clock.advance(5);

        const prevented = page();
        prevented.layer('menu');
        key(prevented, 'Escape', { prevented: true });
        prevented.clock.advance(5);

        const foreign = page();
        foreign.layer('menu', foreign.$('menu-layer'));
        const confirm = foreign.$('confirm');
        const querySelector = foreign.document.querySelector.bind(foreign.document);
        foreign.document.querySelector = (selector) => (selector === 'dialog:modal' ? confirm : querySelector(selector));
        key(foreign, 'Escape');
        foreign.clock.advance(5);

        const own = page();
        const menu = own.$('menu-layer');
        own.layer('desktop-menu', menu);
        const ownQuery = own.document.querySelector.bind(own.document);
        own.document.querySelector = (selector) => (selector === 'dialog:modal' ? menu : ownQuery(selector));
        key(own, 'Escape');
        own.clock.advance(5);

        const other = page();
        other.layer('menu');
        key(other, 'Enter');
        other.clock.advance(5);

        return { plain: { handled, closes: plain.closes }, prevented: prevented.closes, foreignDialog: foreign.closes, ownModalDialog: own.closes, otherKey: other.closes };
    },

    // the safety net: an unmarked programmatic visit from a layer becomes a replace visit (guarded internal)
    'safety net': () => {
        const env = page('/en/faq', { entries: ['/en/hub'] });
        env.layer('modal');
        const visit = env.session.visit(ORIGIN + '/en/puzzle');
        env.session.render(visit);

        return { action: visit.action, urls: historyUrls(env), warned: env.warnings.filter((warning) => warning.includes('replace visit')).length };
    },

    // PROF-05c: Turbo-shaped page entries, so a later Turbo pop never hits historyPoppedWithEmptyState
    'page state shape': () => {
        const env = page('/en/puzzlers/abc/results', { entries: ['/en/hub'] });
        env.layers.pauseTurboHistory('results');
        env.layers.replacePageState('results', '/en/puzzlers/abc/results?sort=fastest');
        const replaced = stateOf(env);
        env.layers.pushPageState('results', '/en/puzzlers/abc/results?sort=fastest&page=2');
        const pushed = stateOf(env);

        return {
            replaced,
            pushed: { owner: pushed[PAGE_STATE], index: pushed.turbo.restorationIndex, idIsUuid: /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(pushed.turbo.restorationIdentifier) },
            url: env.location.href,
            length: env.history.length,
        };
    },

    // F3: Back through a page owner's own entries stays on the page; one more Back past them leaves it rendered
    'page owner pop': () => {
        const env = page('/en/puzzlers/abc/results', { entries: ['/en/hub'] });
        const ownPops = [];
        env.window.addEventListener('popstate', (event) => ownPops.push(event.state && event.state[PAGE_STATE]));
        env.layers.pauseTurboHistory('results');
        env.layers.replacePageState('results', '/en/puzzlers/abc/results');
        env.layers.pushPageState('results', '/en/puzzlers/abc/results?sort=fastest');
        env.history.back();
        env.clock.advance(5);
        const ownBack = { ownPops: [...ownPops], visits: env.session.visits.length, turboStarted: env.session.history.started };
        env.history.back();
        env.clock.advance(5);
        const restores = env.session.visits.filter((visit) => visit.action === 'restore');

        return {
            ownBack,
            ownPops,
            url: new URL(env.location.href).pathname,
            restores: restores.map((visit) => ({ url: new URL(visit.url).pathname, restorationIdentifier: visit.options.restorationIdentifier })),
            turboStarted: env.session.history.started,
            currentIndex: env.session.history.currentIndex,
        };
    },

    // a layer over a page owner: closing it neither restarts Turbo nor reaches the page's own popstate handler;
    // a long-press Back onto an earlier entry of the page owner closes the layer and lets the page render that entry
    'layer over page owner': () => {
        const env = page('/en/puzzlers/abc/results', { entries: ['/en/hub'] });
        const ownPops = [];
        env.window.addEventListener('popstate', (event) => ownPops.push(event.state && event.state[PAGE_STATE]));
        env.layers.pauseTurboHistory('results');
        env.layers.replacePageState('results', '/en/puzzlers/abc/results');
        env.layer('menu');
        env.history.back();
        env.clock.advance(5);
        const closeOnly = { ownPops: [...ownPops], closes: [...env.closes], turboStarted: env.session.history.started, visits: env.session.visits.length };

        env.layers.pushPageState('results', '/en/puzzlers/abc/results?sort=fastest');
        env.layer('menu');
        env.history.go(-2);
        env.clock.advance(5);

        return { closeOnly, longPress: { ownPops, closes: env.closes, turboStarted: env.session.history.started, visits: env.session.visits.length, url: env.location.href } };
    },

    // the page owner leaving its page by a Turbo visit gives Turbo its history back
    'page owner leaves': () => {
        const env = page('/en/puzzlers/abc/results');
        env.layers.pauseTurboHistory('results');
        env.session.visit(ORIGIN + '/en/puzzlers/abc/results?sort=name', { action: 'replace' });
        const sameOwnPage = env.session.history.started;
        env.session.visit(ORIGIN + '/en/puzzle/1');

        return { sameOwnPage, otherPage: env.session.history.started };
    },

    // F5: Turbo internals missing → today's behaviour + one warning each
    'runtime fallbacks': () => {
        const noIdentity = page('/en/faq', { turbo: { noIdentity: true } });
        noIdentity.layer('menu');
        noIdentity.layers.requestClose('menu');
        noIdentity.clock.advance(5);
        noIdentity.layer('menu');
        noIdentity.layers.requestClose('menu');
        noIdentity.clock.advance(5);

        const noNavigator = page('/en/faq', { turbo: { noNavigator: true } });
        noNavigator.layer('modal');
        noNavigator.session.visit(ORIGIN + '/en/puzzle');
        noNavigator.session.visit(ORIGIN + '/en/hub');

        const noTurbo = page('/en/faq', { noTurbo: true });
        noTurbo.layer('menu');
        noTurbo.layers.requestClose('menu');
        noTurbo.clock.advance(5);
        noTurbo.layer('menu');
        noTurbo.layers.visitFromLayer('/en/puzzle');

        return {
            noIdentity: { starts: noIdentity.session.history.starts, started: noIdentity.session.history.started, closes: noIdentity.closes, warnings: noIdentity.warnings.length },
            noNavigator: { closes: noNavigator.closes, warnings: noNavigator.warnings.length },
            noTurbo: { closes: noTurbo.closes, location: noTurbo.location.calls },
        };
    },

    'visitFromLayer': () => {
        const env = page();
        env.layers.visitFromLayer('/en/puzzle', { response: { statusCode: 200 } });
        env.layer('modal');
        env.layers.visitFromLayer('/en/help', { response: { statusCode: 200 } });

        return env.session.visits.map((visit) => ({ url: new URL(visit.url).pathname, action: visit.action, response: Boolean(visit.options.response) }));
    },

    'closeThen': () => {
        const env = page();
        const order = [];
        env.layers.closeThen(() => order.push('no layer'));
        env.layers.open('help', { onClose: () => order.push('closed') });
        env.layers.closeThen(() => order.push('then'));
        order.push('requested');
        env.clock.advance(5);

        return { order, backs: env.history.calls.filter((call) => call === 'back').length };
    },

    'closeForCache': () => {
        const env = page();
        env.layer('menu');
        env.layers.closeForCache();
        const left = page();
        left.layer('modal');
        left.session.visit(ORIGIN + '/en/puzzle', { action: 'replace' });
        left.layers.closeForCache();

        return { closes: env.closes, backs: env.history.calls.filter((call) => call === 'back').length, turboStarted: env.session.history.started, leftCloses: left.closes };
    },

    'bindDialog': () => {
        const env = page();
        const dialog = env.$('menu-layer');
        env.layers.bindDialog(dialog, 'overlay');
        env.layer('overlay', dialog);
        const cancel = new env.dom.window.Event('cancel', { cancelable: true });
        dialog.dispatchEvent(cancel);
        const backsAfterCancel = env.history.calls.filter((call) => call === 'back').length;
        env.clock.advance(5);
        // closed by the helper: its close event asks nothing more
        dialog.dispatchEvent(new env.dom.window.Event('close'));
        env.layer('overlay', dialog);
        // closed by something else (form method=dialog): one back()
        dialog.dispatchEvent(new env.dom.window.Event('close'));
        env.clock.advance(5);

        return { cancelPrevented: cancel.defaultPrevented, backsAfterCancel, backs: env.history.calls.filter((call) => call === 'back').length, closes: env.closes };
    },

    'startsPageVisit': () => {
        const env = page();

        return Object.fromEntries(['page-link', 'top-in-frame', 'in-top-frame', 'in-frame', 'opens-modal', 'modal-frame-link', 'modal-top-link', 'modal-top-form', 'modal-frame-form']
            .map((id) => [id, startsPageVisit(env.$(id), env.document)]));
    },

    'constants': () => ({ layer: LAYER_STATE, page: PAGE_STATE }),
};

const results = {};

for (const [name, run] of Object.entries(cases)) {
    try {
        results[name] = run();
    } catch (error) {
        results[name] = { threw: String(error && error.stack ? error.stack : error) };
    }
}

process.stdout.write(JSON.stringify(results));
