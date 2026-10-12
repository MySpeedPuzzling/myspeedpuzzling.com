// Loads the real @hotwired/turbo dist (pinned to 8.0.23 in package.json) into jsdom and prints, as JSON, what the
// navigation helpers rely on (tests/TurboContractScriptTest.php, docs/features/navigation implementation plan
// decision 92, review F5): assets/layer_history.js uses session.history.{stop, start, replace, currentIndex,
// restorationIdentifier} and session.navigator.currentVisit.action, marks links in turbo:click and forms in
// turbo:submit-start; GEST-12/17 rely on the view-transition decision reading the live head meta.
// fetch is a stub answering HTML, so visits render without a network.

import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const ORIGIN = 'https://msp.test';
const page = (title) => `<!doctype html><html><head><title>${title}</title></head><body><main><h1>${title}</h1>`
    + '<a id="link" href="/en/linked">Linked</a>'
    + '<form id="page-form" method="post" action="/en/submit"><button id="page-submit">Send</button></form>'
    + '<turbo-frame id="frame"><form id="frame-form" method="post" action="/en/frame-submit"><button>Send</button></form></turbo-frame>'
    + '</main></body></html>';

// jsdom's own errors (a listener throwing) go to stderr; "not implemented" navigation noise is dropped
const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', (error) => {
    if (!String(error && error.message).includes('Not implemented')) {
        process.stderr.write(String(error && error.stack ? error.stack : error) + '\n');
    }
});
const dom = new JSDOM(page('Start'), { url: `${ORIGIN}/en/start`, pretendToBeVisual: true, virtualConsole });
const { window } = dom;

// Every request answers an HTML page; a POST answers as if redirected (Turbo requires a redirect after a form POST)
const requests = [];
window.fetch = async (url, options = {}) => {
    const method = String(options.method || 'GET').toUpperCase();
    const requested = new URL(String(url), window.location.href);
    const target = method === 'GET' ? requested : new URL('/en/after', ORIGIN);
    requests.push({ method, path: requested.pathname, turboFrame: new Headers(options.headers || {}).get('Turbo-Frame') });
    const html = page(target.pathname);

    return {
        ok: true,
        status: 200,
        redirected: method !== 'GET',
        url: target.href,
        headers: new Headers({ 'Content-Type': 'text/html; charset=utf-8' }),
        clone() {
            return this;
        },
        // A network body is read in a later task, never in the same microtask as the response
        text: () => new Promise((resolve) => setTimeout(() => resolve(html), 0)),
    };
};
window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
// A frame response is matched by CSS.escape(id); jsdom has no CSS namespace
window.CSS = { escape: (value) => String(value).replace(/[^a-zA-Z0-9_-]/g, (character) => `\\${character}`) };
// <turbo-frame> watches its appearance (lazy loading); jsdom has no IntersectionObserver
window.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

// The browser globals Turbo reads unqualified
for (const key of Object.getOwnPropertyNames(window)) {
    if (!(key in globalThis)) {
        try {
            globalThis[key] = window[key];
        } catch (e) {
            // read-only in node
        }
    }
}

// node has its own Event / FormData: Turbo must get jsdom's (new FormData(form), dispatchEvent on jsdom nodes)
for (const key of ['window', 'document', 'location', 'history', 'navigator', 'fetch', 'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent', 'FormData', 'requestAnimationFrame', 'cancelAnimationFrame', 'matchMedia', 'CSS', 'IntersectionObserver']) {
    Object.defineProperty(globalThis, key, { value: key === 'window' ? window : window[key], configurable: true, writable: true });
}

globalThis.addEventListener = window.addEventListener.bind(window);
globalThis.removeEventListener = window.removeEventListener.bind(window);
globalThis.dispatchEvent = window.dispatchEvent.bind(window);

const distPath = new URL('../node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js', import.meta.url);
const packageJson = JSON.parse(readFileSync(new URL('../node_modules/@hotwired/turbo/package.json', import.meta.url), 'utf8'));
const Turbo = await import(distPath.href);

const { session } = Turbo;
const turboHistory = session.history;
const document = window.document;

const nextEvent = (type, target = document) => new Promise((resolve) => target.addEventListener(type, resolve, { once: true }));
const settle = async (type) => {
    const event = await Promise.race([nextEvent(type), new Promise((resolve) => setTimeout(() => resolve(null), 3000))]);

    // turbo:load fires after the render; let its own microtasks finish
    await new Promise((resolve) => setTimeout(resolve, 20));

    return event;
};

const facts = { version: packageJson.version };

// ---- session.history ------------------------------------------------------------------------------------------------
facts.history = {
    stop: typeof turboHistory.stop,
    start: typeof turboHistory.start,
    replace: typeof turboHistory.replace,
    currentIndex: typeof turboHistory.currentIndex,
    restorationIdentifier: typeof turboHistory.restorationIdentifier,
    started: turboHistory.started,
};

{
    const before = turboHistory.restorationIdentifier;
    turboHistory.stop();
    const stopped = turboHistory.started;
    // start() reads the index from the current entry's Turbo state
    window.history.replaceState({ turbo: { restorationIdentifier: before, restorationIndex: 7 } }, '', window.location.href);
    turboHistory.start();
    const after = turboHistory.restorationIdentifier;
    facts.start = {
        stoppedFlag: stopped,
        mintsNewIdentifier: after !== before && typeof after === 'string' && after.length > 0,
        stateCarriesIt: window.history.state.turbo.restorationIdentifier === after,
        indexFromState: turboHistory.currentIndex,
    };

    // replace(url, id) writes {turbo: {restorationIdentifier: id, restorationIndex: currentIndex}}
    turboHistory.currentIndex = 3;
    turboHistory.replace(new URL(window.location.href), 'kept-identifier');
    facts.replace = { state: window.history.state, restorationIdentifier: turboHistory.restorationIdentifier };
}

// ---- a link: its action is read right after turbo:click; history changes at render ------------------------------------
{
    const lengthBefore = window.history.length;
    const link = document.getElementById('link');
    let atClick = null;
    let atVisit = null;
    document.addEventListener('turbo:click', (event) => {
        atClick = { target: event.target.id, cancelable: event.cancelable, hasOriginalEvent: Boolean(event.detail.originalEvent) };
        event.target.setAttribute('data-turbo-action', 'replace');
    }, { once: true });
    document.addEventListener('turbo:visit', (event) => {
        const visit = session.navigator.currentVisit;
        atVisit = {
            action: event.detail.action,
            currentVisitAction: visit ? visit.action : null,
            urlUnchanged: window.location.pathname === '/en/start',
            lengthUnchanged: window.history.length === lengthBefore,
        };
    }, { once: true });
    const loaded = settle('turbo:load');
    link.click();
    await loaded;
    facts.link = { atClick, atVisit, path: window.location.pathname, lengthAfter: window.history.length - lengthBefore };
}

// ---- the safety net: currentVisit.action changed at turbo:visit decides the history method at render ----------------
{
    const lengthBefore = window.history.length;
    let atVisit = null;
    document.addEventListener('turbo:visit', (event) => {
        const visit = session.navigator.currentVisit;
        atVisit = { action: event.detail.action, hasCurrentVisit: Boolean(visit), writable: false };

        if (visit) {
            visit.action = 'replace';
            atVisit.writable = visit.action === 'replace';
        }
    }, { once: true });
    const loaded = settle('turbo:load');
    Turbo.visit('/en/programmatic');
    await loaded;
    const replaced = { atVisit, path: window.location.pathname, lengthAfter: window.history.length - lengthBefore };

    // the same visit unchanged pushes
    const plainBefore = window.history.length;
    const plainLoaded = settle('turbo:load');
    Turbo.visit('/en/plain');
    await plainLoaded;
    facts.safetyNet = { replaced, plain: { path: window.location.pathname, lengthAfter: window.history.length - plainBefore } };
}

// ---- forms: Turbo-Frame header at submit-start, the action read after submit-end (F4) ----------------------------------
{
    const order = [];
    let headerAtStart = 'unset';
    const form = document.getElementById('page-form');
    const onStart = (event) => {
        order.push('submit-start');
        headerAtStart = event.detail.formSubmission.fetchRequest.headers['Turbo-Frame'] ?? null;
    };
    const onEnd = () => {
        order.push('submit-end');
        // set only now: if Turbo had read the action before this event, the visit would be 'advance'
        form.setAttribute('data-turbo-action', 'replace');
    };
    document.addEventListener('turbo:submit-start', onStart);
    document.addEventListener('turbo:submit-end', onEnd);
    let visitAction = null;
    document.addEventListener('turbo:visit', (event) => {
        order.push('visit');
        visitAction = event.detail.action;
    }, { once: true });
    const lengthBefore = window.history.length;
    const loaded = settle('turbo:load');
    form.requestSubmit();
    await loaded;
    document.removeEventListener('turbo:submit-start', onStart);
    document.removeEventListener('turbo:submit-end', onEnd);
    facts.pageForm = { order, headerAtStart, visitAction, path: window.location.pathname, lengthAfter: window.history.length - lengthBefore };
}

{
    let headerAtStart = 'unset';
    const frameForm = document.getElementById('frame-form');
    const started = new Promise((resolve) => document.addEventListener('turbo:submit-start', (event) => {
        headerAtStart = event.detail.formSubmission.fetchRequest.headers['Turbo-Frame'] ?? null;
        resolve();
    }, { once: true }));
    frameForm.requestSubmit();
    await Promise.race([started, new Promise((resolve) => setTimeout(resolve, 3000))]);
    await new Promise((resolve) => setTimeout(resolve, 50));
    facts.frameForm = { headerAtStart, request: requests.find((request) => request.path === '/en/frame-submit') || null };
}

// ---- restore visits started by the helper ----------------------------------------------------------------------------
{
    let atVisit = null;
    document.addEventListener('turbo:visit', (event) => {
        atVisit = { action: event.detail.action, restorationIdentifier: session.navigator.currentVisit ? session.navigator.currentVisit.restorationIdentifier : null };
    }, { once: true });
    const loaded = settle('turbo:load');
    Turbo.visit(window.location.href, { action: 'restore', historyChanged: true, restorationIdentifier: 'restore-me' });
    await loaded;
    facts.restoreVisit = atVisit;
}

// ---- the view-transition decision reads the live head meta (GEST-12/17) -------------------------------------------------
{
    const prefers = () => session.view.snapshot.prefersViewTransitions;
    const without = prefers();
    const meta = document.createElement('meta');
    meta.name = 'view-transition';
    meta.content = 'same-origin';
    document.head.appendChild(meta);
    const sameOrigin = prefers();
    meta.content = 'none';
    const none = prefers();
    meta.content = 'same-origin';
    facts.viewTransition = { without, sameOrigin, none, againSameOrigin: prefers(), shouldTransitionTo: typeof session.view.shouldTransitionTo };
}

facts.internals = {
    navigator: typeof session.navigator,
    visit: typeof session.visit,
    historyPoppedWithEmptyState: typeof session.historyPoppedWithEmptyState,
    historyPoppedToLocationWithRestorationIdentifierAndDirection: typeof session.historyPoppedToLocationWithRestorationIdentifierAndDirection,
};

process.stdout.write(JSON.stringify(facts));
process.exit(0);
