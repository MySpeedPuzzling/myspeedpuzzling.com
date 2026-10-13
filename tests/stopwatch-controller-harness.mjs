// Runs the real assets/controllers/stopwatch_controller.js under Stimulus in jsdom for tests/StopwatchControllerScriptTest.php
// (docs/features/navigation ADD-04 and its review, findings F2 and Q2) and prints {case: facts} as JSON:
// - F2: an answer that arrives after the page was left (a Turbo visit disconnects the controller and keeps <html>) never
//   marks the next page running (html[data-stopwatch-running] hides the phone tab bar), never starts a frame loop, a
//   wake lock or a history entry there - but the device's pill state still learns that the stopwatch runs;
// - Q2: a Stop / Continue queued offline (localStorage stopwatch-queue) and replayed by the server is applied to this
//   page's own stopwatch: status, buttons, time and the running mark.
// The browser APIs the controller uses are fakes the harness controls: fetch (answered when the case says so),
// requestAnimationFrame (frames run on demand), navigator.wakeLock, history.replaceState.

import { createRequire } from 'node:module';
import { Application } from '@hotwired/stimulus';
import StopwatchController from '../assets/controllers/stopwatch_controller.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const RUNNING = 'data-stopwatch-running';
const QUEUE_KEY = 'stopwatch-queue';
const LAST_STATE_KEY = 'msp:stopwatch-last';

const settle = async (rounds = 5) => {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

const ok = (data = {}) => ({ ok: true, status: 200, json: async () => data });

/** The stopwatch page's controller element (templates/stopwatch.html.twig, the targets and values the controller reads) */
function stopwatchMarkup({ status = 'not_started', stopwatchId = '', totalMs = 0, lastStart = '', serverNow = '', milestones = [] } = {}) {
    const hidden = (shown) => (shown ? '' : ' class="d-none"');
    const milestoneValue = JSON.stringify(milestones).replace(/"/g, '&quot;');

    return `<div id="stopwatch" data-controller="stopwatch"
        data-stopwatch-status-value="${status}"
        data-stopwatch-stopwatch-id-value="${stopwatchId}"
        data-stopwatch-total-milliseconds-value="${totalMs}"
        data-stopwatch-last-start-value="${lastStart}"
        data-stopwatch-server-now-value="${serverNow}"
        data-stopwatch-start-url-value="/api/stopwatch/start"
        data-stopwatch-finish-url-template-value="/en/save-stopwatch/__STOPWATCH_ID__"
        data-stopwatch-reset-url-value="/en/stopwatch"
        data-stopwatch-label-leave-running-value="Leave the stopwatch – it keeps running"
        data-stopwatch-milestones-value="${milestoneValue}">
        <a href="/en/puzzle-add" data-stopwatch-target="leaveLink">Leave</a>
        <span data-stopwatch-target="hours">00</span>:<span data-stopwatch-target="minutes">00</span>:<span data-stopwatch-target="seconds">00</span>
        <button data-stopwatch-target="startBtn"${hidden(status === 'not_started')}>Start</button>
        <button data-stopwatch-target="pauseBtn"${hidden(status === 'running')}>Stop</button>
        <button data-stopwatch-target="resumeBtn"${hidden(status === 'paused')}>Continue</button>
        <button data-stopwatch-target="finishBtn"${hidden(status === 'paused')}><a data-stopwatch-target="finishLink" href="#">Finish</a></button>
        <div data-stopwatch-target="milestoneBar" class="d-none"><span data-stopwatch-target="milestoneLabel"></span><div data-stopwatch-target="milestoneProgress" style="width: 0%"></div></div>
    </div>`;
}

/**
 * A page: jsdom with the markup, the controller registered on it and the fakes installed as the globals the controller
 * (and Stimulus) read at run time. Cases run one after another, each with its own page.
 */
async function page({ body, queue = null, fetchAnswer = () => ok(), wakeLockAnswer = null } = {}) {
    const dom = new JSDOM(`<!DOCTYPE html><html><body>${body}<nav class="bottom-nav" id="bottom-nav"></nav></body></html>`, {
        url: 'https://msp.test/en/stopwatch',
        virtualConsole: new VirtualConsole(),
    });
    const { window } = dom;
    const facts = { fetches: [], replaceState: 0, wakeLocks: [], errors: [] };
    const frames = new Map();
    let frameId = 0;

    if (queue !== null) {
        window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    }

    const navigator = {
        onLine: true,
        wakeLock: {
            request: () => {
                const lock = {
                    released: false,
                    release() {
                        this.released = true;
                    },
                    addEventListener() {},
                };
                facts.wakeLocks.push(lock);

                return wakeLockAnswer === null ? Promise.resolve(lock) : wakeLockAnswer.promise.then(() => lock);
            },
        },
    };

    Object.assign(globalThis, {
        window,
        document: window.document,
        MutationObserver: window.MutationObserver,
        Node: window.Node,
        Element: window.Element,
        HTMLElement: window.HTMLElement,
        KeyboardEvent: window.KeyboardEvent,
        MouseEvent: window.MouseEvent,
        Event: window.Event,
        CustomEvent: window.CustomEvent,
        localStorage: window.localStorage,
        history: { replaceState: () => facts.replaceState++ },
        requestAnimationFrame: (callback) => {
            frameId++;
            frames.set(frameId, callback);

            return frameId;
        },
        cancelAnimationFrame: (id) => frames.delete(id),
        fetch: (url, options = {}) => {
            facts.fetches.push({ url, body: options.body ? JSON.parse(options.body) : null });

            return Promise.resolve(fetchAnswer(url, options));
        },
    });
    Object.defineProperty(globalThis, 'navigator', { value: navigator, configurable: true, writable: true });
    window.navigator.wakeLock = navigator.wakeLock;

    const application = Application.start(window.document.documentElement);
    application.handleError = (error) => facts.errors.push(String(error && error.stack ? error.stack : error));
    application.register('stopwatch', StopwatchController);
    await settle();

    const element = () => window.document.getElementById('stopwatch');
    const controller = () => (element() ? application.getControllerForElementAndIdentifier(element(), 'stopwatch') : null);

    return {
        window,
        document: window.document,
        facts,
        application,
        element,
        controller,
        /** Runs the frames waiting now (one tick of the loop) */
        frame() {
            const waiting = [...frames.entries()];
            frames.clear();
            waiting.forEach(([, callback]) => callback(window.performance.now()));
        },
        framesWaiting: () => frames.size,
        running: () => window.document.documentElement.hasAttribute(RUNNING),
        /** A Turbo visit: the stopwatch is replaced by the next page's content, <html> stays */
        async leave() {
            window.document.body.innerHTML = '<main>The next page</main><nav class="bottom-nav" id="bottom-nav"></nav>';
            await settle();
        },
        shown(target) {
            const el = window.document.querySelector(`[data-stopwatch-target="${target}"]`);

            return el ? !el.classList.contains('d-none') : null;
        },
        digits() {
            return ['hours', 'minutes', 'seconds'].map((t) => window.document.querySelector(`[data-stopwatch-target="${t}"]`).textContent).join(':');
        },
        lastStates() {
            return JSON.parse(window.localStorage.getItem(LAST_STATE_KEY) || '{}');
        },
        storedQueue() {
            return JSON.parse(window.localStorage.getItem(QUEUE_KEY) || '[]');
        },
        stop() {
            application.stop();
        },
    };
}

// One after another: each page installs its own globals
const cases = [];
const run = (name, fn) => [name, fn];

// ---- F2: Start answered after the page was left -----------------------------------------------------------------

cases.push(run('start answered after leaving', async () => {
    const answer = deferred();
    const p = await page({ body: stopwatchMarkup(), fetchAnswer: () => answer.promise });
    const started = p.controller().start();
    const atTap = p.running();

    await p.leave();
    const afterLeave = p.running();
    const framesBefore = p.framesWaiting();

    answer.resolve(ok({ stopwatchId: 'sw-late' }));
    await started;
    await settle();

    const facts = {
        atTap,
        afterLeave,
        afterAnswer: p.running(),
        framesStarted: p.framesWaiting() - framesBefore,
        replaceState: p.facts.replaceState,
        wakeLocks: p.facts.wakeLocks.length,
        pill: p.lastStates()['sw-late']?.status ?? null,
        errors: p.facts.errors,
    };
    p.stop();

    return facts;
}));

cases.push(run('start answered on the page', async () => {
    const p = await page({ body: stopwatchMarkup(), fetchAnswer: () => ok({ stopwatchId: 'sw-here' }) });
    await p.controller().start();
    await settle();

    const facts = {
        running: p.running(),
        status: p.element().getAttribute('data-stopwatch-status-value'),
        stopShown: p.shown('pauseBtn'),
        framesWaiting: p.framesWaiting(),
        replaceState: p.facts.replaceState,
        wakeLocks: p.facts.wakeLocks.length,
        pill: p.lastStates()['sw-here']?.status ?? null,
        leaveName: p.document.querySelector('[data-stopwatch-target="leaveLink"]').getAttribute('aria-label'),
        errors: p.facts.errors,
    };
    p.stop();

    return facts;
}));

cases.push(run('start refused after leaving to a running stopwatch', async () => {
    const answer = deferred();
    const p = await page({ body: stopwatchMarkup(), fetchAnswer: () => answer.promise });
    const started = p.controller().start();

    // The next page is a stopwatch that runs: its own controller marks <html>
    p.document.body.innerHTML = stopwatchMarkup({ status: 'running', stopwatchId: 'sw-next', lastStart: new Date().toISOString(), serverNow: new Date().toISOString() })
        + '<nav class="bottom-nav" id="bottom-nav"></nav>';
    await settle();
    const nextPageRunning = p.running();

    answer.reject(new Error('offline'));
    await started;
    await settle();

    const facts = { nextPageRunning, afterRefusal: p.running(), errors: p.facts.errors };
    p.stop();

    return facts;
}));

cases.push(run('wake lock granted after leaving', async () => {
    const granted = deferred();
    const now = new Date();
    const p = await page({
        body: stopwatchMarkup({ status: 'running', stopwatchId: 'sw-1', lastStart: now.toISOString(), serverNow: now.toISOString() }),
        wakeLockAnswer: granted,
    });

    await p.leave();
    granted.resolve();
    await settle();

    const facts = { requested: p.facts.wakeLocks.length, released: p.facts.wakeLocks.map((lock) => lock.released), errors: p.facts.errors };
    p.stop();

    return facts;
}));

// ---- Q2: this page's queued Stop / Continue replayed ---------------------------------------------------------------

cases.push(run('replayed stop of this stopwatch', async () => {
    const now = Date.now();
    const p = await page({
        // Rendered running (the server did not know about the Stop yet): 2 minutes since the lap started
        body: stopwatchMarkup({
            status: 'running',
            stopwatchId: 'sw-1',
            lastStart: new Date(now - 120_000).toISOString(),
            serverNow: new Date(now).toISOString(),
            // At the rendered 2 minutes the first one is passed; at the Stop's 1:01 it is still ahead
            milestones: [{ timeSeconds: 100, label: 'First', type: 'leaderboard' }, { timeSeconds: 400, label: 'Second', type: 'leaderboard' }],
        }),
        queue: [{ action: 'pause', stopwatchId: 'sw-1', body: { shownTotalMs: 61_000 }, timestamp: now - 30_000 }],
    });
    await settle();

    const facts = {
        milestone: p.document.querySelector('[data-stopwatch-target="milestoneLabel"]').textContent.trim(),
        milestoneWidth: p.document.querySelector('[data-stopwatch-target="milestoneProgress"]').style.width,
        fetches: p.facts.fetches,
        status: p.element().getAttribute('data-stopwatch-status-value'),
        running: p.running(),
        stop: p.shown('pauseBtn'),
        resume: p.shown('resumeBtn'),
        finish: p.shown('finishBtn'),
        digits: p.digits(),
        framesWaiting: p.framesWaiting(),
        leaveName: p.document.querySelector('[data-stopwatch-target="leaveLink"]').getAttribute('aria-label'),
        queue: p.storedQueue(),
        errors: p.facts.errors,
    };
    p.stop();

    return facts;
}));

cases.push(run('replayed continue of this stopwatch', async () => {
    const now = Date.now();
    const p = await page({
        // Rendered paused at 1:01; Continue was tapped offline 5 s ago
        body: stopwatchMarkup({ status: 'paused', stopwatchId: 'sw-1', totalMs: 61_000, serverNow: new Date(now).toISOString() }),
        queue: [{ action: 'resume', stopwatchId: 'sw-1', body: {}, timestamp: now - 5_200 }],
    });
    await settle();
    p.frame();

    const facts = {
        fetches: p.facts.fetches.map((fetch) => fetch.url),
        status: p.element().getAttribute('data-stopwatch-status-value'),
        running: p.running(),
        stop: p.shown('pauseBtn'),
        resume: p.shown('resumeBtn'),
        digits: p.digits(),
        framesWaiting: p.framesWaiting(),
        wakeLocks: p.facts.wakeLocks.length,
        leaveName: p.document.querySelector('[data-stopwatch-target="leaveLink"]').getAttribute('aria-label'),
        errors: p.facts.errors,
    };
    p.stop();

    return facts;
}));

cases.push(run('replayed stop then continue', async () => {
    const now = Date.now();
    const p = await page({
        body: stopwatchMarkup({ status: 'running', stopwatchId: 'sw-1', lastStart: new Date(now - 600_000).toISOString(), serverNow: new Date(now).toISOString() }),
        queue: [
            { action: 'pause', stopwatchId: 'sw-1', body: { shownTotalMs: 61_000 }, timestamp: now - 30_000 },
            { action: 'resume', stopwatchId: 'sw-1', body: {}, timestamp: now - 10_200 },
        ],
    });
    await settle();
    p.frame();

    const facts = { status: p.element().getAttribute('data-stopwatch-status-value'), running: p.running(), digits: p.digits(), errors: p.facts.errors };
    p.stop();

    return facts;
}));

cases.push(run('replayed stop of another stopwatch', async () => {
    const now = Date.now();
    const p = await page({
        body: stopwatchMarkup({ status: 'running', stopwatchId: 'sw-1', lastStart: new Date(now - 120_000).toISOString(), serverNow: new Date(now).toISOString() }),
        queue: [{ action: 'pause', stopwatchId: 'sw-other', body: { shownTotalMs: 61_000 }, timestamp: now - 30_000 }],
    });
    await settle();

    const facts = { fetches: p.facts.fetches.map((fetch) => fetch.url), status: p.element().getAttribute('data-stopwatch-status-value'), running: p.running(), stop: p.shown('pauseBtn'), errors: p.facts.errors };
    p.stop();

    return facts;
}));

cases.push(run('replay refused', async () => {
    const now = Date.now();
    const item = { action: 'pause', stopwatchId: 'sw-1', body: { shownTotalMs: 61_000 }, timestamp: now - 30_000 };
    const p = await page({
        body: stopwatchMarkup({ status: 'running', stopwatchId: 'sw-1', lastStart: new Date(now - 120_000).toISOString(), serverNow: new Date(now).toISOString() }),
        queue: [item],
        fetchAnswer: () => ({ ok: false, status: 503, json: async () => ({}) }),
    });
    await settle();

    const facts = { status: p.element().getAttribute('data-stopwatch-status-value'), running: p.running(), queue: p.storedQueue(), errors: p.facts.errors };
    p.stop();

    return facts;
}));

cases.push(run('replay answered after leaving', async () => {
    const now = Date.now();
    const answer = deferred();
    const p = await page({
        body: stopwatchMarkup({ status: 'paused', stopwatchId: 'sw-1', totalMs: 61_000, serverNow: new Date(now).toISOString() }),
        queue: [{ action: 'resume', stopwatchId: 'sw-1', body: {}, timestamp: now - 5_200 }],
        fetchAnswer: () => answer.promise,
    });

    await p.leave();
    answer.resolve(ok());
    await settle();

    const facts = { running: p.running(), framesWaiting: p.framesWaiting(), wakeLocks: p.facts.wakeLocks.length, errors: p.facts.errors };
    p.stop();

    return facts;
}));

const results = {};

for (const [name, fn] of cases) {
    try {
        results[name] = await fn();
    } catch (error) {
        results[name] = { threw: String(error && error.stack ? error.stack : error) };
    }
}

process.stdout.write(JSON.stringify(results));
