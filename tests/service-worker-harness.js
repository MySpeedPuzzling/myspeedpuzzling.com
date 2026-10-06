// Runs the REAL public/service-worker.js against requests shaped like the ones
// browsers actually send, and prints what each one was answered with as JSON.
// Driven by tests/ServiceWorkerBehaviourTest.php - the expectations live there.
//
// Every cache lookup for a page or image URL answers with a poisoned "STALE"
// entry, and the network always answers "FRESH". A request that comes back
// STALE was served from a cache; nothing about the source text is assumed.
//
// A scenario can make the network reject: `offline` rejects every fetch,
// `failingFetches: N` only the first N (a connection that died once). POSTs to
// the worker's failure report are recorded in `reports` and never fail or
// count as fetches. Timers fire at once and their delays are recorded.
//
// Requests are plain objects on purpose: `new Request(url, { mode: 'navigate' })`
// throws by specification, so a real navigation request cannot be constructed.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ORIGIN = 'https://myspeedpuzzling.com';
const OFFLINE_URL = '/offline.html';

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'service-worker.js'), 'utf8');
const scenarios = JSON.parse(fs.readFileSync(0, 'utf8'));

function urlOf(request) {
    return typeof request === 'string' ? new URL(request, ORIGIN).href : request.url;
}

async function run(scenario) {
    const log = { cacheReads: [], cacheWrites: [], networkFetches: [], reports: [], delays: [] };
    const handlers = {};

    function lookup(request) {
        const url = urlOf(request);
        log.cacheReads.push(url);

        if (new URL(url).pathname === OFFLINE_URL) {
            return Promise.resolve(new Response('OFFLINE', { headers: { 'Content-Type': 'text/html' } }));
        }

        return Promise.resolve(new Response('STALE', { headers: { 'Content-Type': scenario.cachedContentType || 'text/html' } }));
    }

    const cache = {
        match: lookup,
        put: (request) => { log.cacheWrites.push(urlOf(request)); return Promise.resolve(); },
        keys: () => Promise.resolve([]),
        delete: () => Promise.resolve(true),
        addAll: () => Promise.resolve(),
    };

    const sandbox = {
        URL, Response, Headers, Promise, Set, Object, Math, console,
        caches: {
            match: lookup,
            open: () => Promise.resolve(cache),
            keys: () => Promise.resolve([]),
            delete: () => Promise.resolve(true),
        },
        setTimeout: (callback, delay) => {
            log.delays.push(delay);
            return setTimeout(callback, 0);
        },
        fetch: (request, init) => {
            if (init && init.method === 'POST') {
                log.reports.push({ url: urlOf(request), credentials: init.credentials, body: JSON.parse(init.body) });
                return Promise.resolve(new Response(null, { status: 204 }));
            }

            log.networkFetches.push(urlOf(request));

            if (scenario.offline || log.networkFetches.length <= (scenario.failingFetches || 0)) {
                return Promise.reject(new TypeError('Load failed'));
            }

            return Promise.resolve(new Response('FRESH', {
                headers: { 'Content-Type': scenario.networkContentType || 'text/html; charset=UTF-8' },
            }));
        },
    };
    sandbox.self = {
        location: { origin: ORIGIN },
        navigator: { onLine: scenario.onLine !== false },
        addEventListener: (type, handler) => { handlers[type] = handler; },
        skipWaiting: () => Promise.resolve(),
        clients: { claim: () => Promise.resolve() },
    };

    vm.runInNewContext(source, sandbox);

    const request = {
        method: scenario.method || 'GET',
        url: new URL(scenario.path, ORIGIN).href,
        mode: scenario.mode,
        destination: scenario.destination,
        headers: new Headers({ Accept: scenario.accept }),
        signal: { aborted: scenario.aborted === true },
    };

    let answer = null;
    const pending = [];

    handlers.fetch({
        request,
        respondWith: (response) => { answer = Promise.resolve(response); },
        waitUntil: (promise) => { pending.push(Promise.resolve(promise).catch(() => {})); },
    });

    const result = {
        name: scenario.name,
        intercepted: answer !== null,
        body: null,
        cacheReads: log.cacheReads,
        cacheWrites: log.cacheWrites,
        networkFetches: log.networkFetches,
        reports: log.reports,
        delays: log.delays,
    };

    if (answer !== null) {
        result.body = await (await answer).text();
    }

    // Background work (the revalidation half of stale-while-revalidate writes
    // to the cache after the response was already handed over).
    await Promise.all(pending);
    await new Promise((resolve) => setTimeout(resolve, 10));

    return result;
}

(async () => {
    const results = [];

    for (const scenario of scenarios) {
        results.push(await run(scenario));
    }

    process.stdout.write(JSON.stringify(results));
})().catch((error) => {
    process.stderr.write(String(error && error.stack || error));
    process.exit(1);
});
