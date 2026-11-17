// Runs the REAL public/service-worker.js for the live result entry's offline copy (competition toolkit C6,
// docs/features/competitions-management/toolkit/C-judge-page.md §1.4, §7.2) and prints each scenario's steps as JSON.
// Driven by tests/ServiceWorkerLiveShellTest.php - the expectations live there.
//
// Unlike tests/service-worker-harness.js (every cache poisoned), the caches here are real in-memory caches, so a
// scenario can prepare the copy, go offline and see what a navigation gets. The network answers from
// `scenario.network` ({path: {status?, body?, contentType?, headers?} | 'reject' | 'hang'}), a navigation not listed
// there gets "FRESH" HTML; `offline: true` rejects every fetch from the step it is set on (`{offline: true}` step).
// Timers run 100x faster than asked (their delays are recorded), so their order is real: the 500 ms retry comes before
// the 4 s timeout. A network answer `{slow: true}` arrives after 6 s of that scale, 'hang' never.
//
// `routes: [paths]` instead of steps prints liveEntryRoute() of each path (tests/fixtures/live-entry-routes.json).

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ORIGIN = 'https://myspeedpuzzling.com';
const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'service-worker.js'), 'utf8');
const scenarios = JSON.parse(fs.readFileSync(0, 'utf8'));

function urlOf(request) {
    return typeof request === 'string' ? new URL(request, ORIGIN).href : request.url;
}

function keyOf(request) {
    const url = new URL(urlOf(request));
    return url.pathname + url.search;
}

async function run(scenario) {
    const handlers = {};
    const stores = new Map();
    const log = { networkFetches: [], reports: [], delays: [], cacheReads: [], cacheWrites: [] };
    let offline = scenario.offline === true;
    let failNext = false;

    function store(name) {
        if (!stores.has(name)) stores.set(name, new Map());
        const entries = stores.get(name);

        return {
            match: (request) => {
                log.cacheReads.push(name + ' ' + keyOf(request));
                const hit = entries.get(keyOf(request));
                return Promise.resolve(hit === undefined ? undefined : hit.clone());
            },
            put: async (request, response) => {
                log.cacheWrites.push(name + ' ' + keyOf(request));
                entries.set(keyOf(request), new Response(await response.arrayBuffer(), { status: response.status, headers: response.headers }));
            },
            keys: () => Promise.resolve([...entries.keys()].map((key) => ({ url: new URL(key, ORIGIN).href }))),
            delete: (request) => Promise.resolve(entries.delete(keyOf(request))),
            addAll: () => Promise.resolve(),
        };
    }

    for (const [name, files] of Object.entries(scenario.caches || {})) {
        const cache = store(name);
        for (const [file, body] of Object.entries(files)) {
            stores.get(name).set(file, new Response(typeof body === 'string' ? body : JSON.stringify(body), { headers: { 'Content-Type': file.endsWith('.json') ? 'application/json' : 'text/plain' } }));
        }
        void cache;
    }

    const sandbox = {
        URL, Response, Headers, Promise, Set, Map, Object, Math, JSON, Date, Array, String, console,
        caches: {
            match: async (request) => {
                for (const name of stores.keys()) {
                    const hit = await store(name).match(request);
                    if (hit !== undefined) return hit;
                }
                return undefined;
            },
            open: (name) => Promise.resolve(store(name)),
            keys: () => Promise.resolve([...stores.keys()]),
            delete: (name) => Promise.resolve(stores.delete(name)),
        },
        setTimeout: (callback, delay) => {
            log.delays.push(delay);
            return setTimeout(callback, Math.ceil(delay / 100));
        },
        clearTimeout: (id) => clearTimeout(id),
        fetch: (request, init) => {
            if (init && init.method === 'POST') {
                log.reports.push(JSON.parse(init.body).outcome);
                return Promise.resolve(new Response(null, { status: 204 }));
            }

            const key = keyOf(request);
            log.networkFetches.push({ path: key, cache: init && init.cache ? init.cache : null, credentials: init && init.credentials ? init.credentials : null });
            const answer = (scenario.network || {})[key];

            if (offline || answer === 'reject' || failNext) {
                failNext = false;
                return Promise.reject(new TypeError('Load failed'));
            }
            if (answer === 'hang') {
                return new Promise(() => {});
            }
            if (answer === undefined) {
                if (typeof request === 'object' && request.mode === 'navigate') {
                    return Promise.resolve(new Response('FRESH', { headers: { 'Content-Type': 'text/html; charset=UTF-8' } }));
                }
                if (key === '/build/entrypoints.json' || key === '/build/manifest.json') {
                    return Promise.resolve(new Response(JSON.stringify(scenario[key === '/build/entrypoints.json' ? 'entrypoints' : 'manifest'] || {}), { headers: { 'Content-Type': 'application/json' } }));
                }
                return Promise.resolve(new Response('missing', { status: 404 }));
            }

            if (answer.slow) {
                return new Promise((resolve) => setTimeout(() => resolve(new Response('FRESH', { headers: { 'Content-Type': 'text/html' } })), 60));
            }

            const headers = Object.assign({ 'Content-Type': answer.contentType || 'text/plain' }, answer.headers || {});
            return Promise.resolve(new Response(answer.body === undefined ? key : answer.body, { status: answer.status || 200, headers }));
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

    if (scenario.routes) {
        return { name: scenario.name, routes: scenario.routes.map((item) => ({ path: item, route: sandbox.liveEntryRoute(item) })) };
    }

    const steps = [];

    for (const step of scenario.steps) {
        for (const key of Object.keys(log)) log[key] = [];
        const pending = [];
        const result = { body: null, status: null, shellHeader: null, intercepted: false, pruned: null, messages: [] };

        if (step.offline !== undefined) {
            offline = step.offline;
            steps.push({ body: null, status: null, shellHeader: null, intercepted: false, pruned: null, messages: [], networkFetches: [], reports: [], delays: [], cacheReads: [], cacheWrites: [] });
            continue;
        }
        failNext = step.failOnce === true;

        if (step.message) {
            const messages = [];
            handlers.message({
                data: step.message,
                source: { postMessage: (message) => messages.push(message) },
                waitUntil: (promise) => pending.push(Promise.resolve(promise)),
            });
            await Promise.all(pending);
            result.messages = messages;
        } else if (step.prune) {
            result.pruned = await sandbox.pruneStaticCache();
        } else {
            const request = {
                method: 'GET',
                url: new URL(step.navigate || step.fetch, ORIGIN).href,
                mode: step.navigate ? 'navigate' : (step.mode || 'no-cors'),
                destination: step.navigate ? 'document' : (step.destination || 'script'),
                headers: new Headers({ Accept: step.navigate ? 'text/html' : '*/*' }),
                signal: { aborted: false },
            };
            let answer = null;
            handlers.fetch({
                request,
                respondWith: (response) => { answer = Promise.resolve(response); },
                waitUntil: (promise) => { pending.push(Promise.resolve(promise).catch(() => {})); },
            });
            result.intercepted = answer !== null;
            if (answer !== null) {
                const response = await answer;
                result.status = response.status;
                result.shellHeader = response.headers.get('X-MSP-Live-Shell');
                // A stored copy's markup is reduced to what its <body> says (the scenarios put a marker there)
                result.body = (await response.text()).replace(/^[\s\S]*<body>([\s\S]*)<\/body>[\s\S]*$/, '$1');
            }
            await Promise.all(pending);
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
        steps.push(Object.assign(result, {
            networkFetches: log.networkFetches,
            reports: log.reports,
            delays: log.delays,
            cacheReads: log.cacheReads,
            cacheWrites: log.cacheWrites,
        }));
    }

    const caches = {};
    for (const [name, entries] of stores) {
        caches[name] = [...entries.keys()].sort();
    }

    return { name: scenario.name, steps, caches };
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
