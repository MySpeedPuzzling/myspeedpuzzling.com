// Runs the REAL public/service-worker.js install handler against the entrypoints.json handed over on stdin and prints
// what it precached as JSON: {added: [[urls of each cache.addAll() call]], rejected: [reasons]}.
// Driven by tests/ServiceWorkerInstallTest.php - the expectations live there.
//
// cache.addAll() behaves like the browsers': a list holding the same URL twice is refused as a whole
// (InvalidStateError) - every Encore entry lists runtime.js.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ORIGIN = 'https://myspeedpuzzling.com';
const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'service-worker.js'), 'utf8');
const { entrypoints, manifest } = JSON.parse(fs.readFileSync(0, 'utf8'));

(async () => {
    const log = { added: [], rejected: [] };
    const handlers = {};

    const cache = {
        addAll: (urls) => {
            if (new Set(urls).size !== urls.length) {
                log.rejected.push('duplicate requests');
                return Promise.reject(new Error('InvalidStateError: duplicate requests'));
            }
            log.added.push(urls);
            return Promise.resolve();
        },
        put: () => Promise.resolve(),
        match: () => Promise.resolve(undefined),
        keys: () => Promise.resolve([]),
        delete: () => Promise.resolve(true),
    };

    const sandbox = {
        URL, Response, Headers, Promise, Set, Object, Math, console, setTimeout,
        caches: {
            open: () => Promise.resolve(cache),
            match: () => Promise.resolve(undefined),
            keys: () => Promise.resolve([]),
            delete: () => Promise.resolve(true),
        },
        fetch: (request) => {
            const url = new URL(typeof request === 'string' ? request : request.url, ORIGIN);
            const body = url.pathname === '/build/entrypoints.json' ? entrypoints : (url.pathname === '/build/manifest.json' ? manifest : null);

            return Promise.resolve(body === null
                ? new Response('', { status: 404 })
                : new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } }));
        },
    };
    sandbox.self = {
        location: { origin: ORIGIN },
        navigator: { onLine: true },
        addEventListener: (type, handler) => { handlers[type] = handler; },
        skipWaiting: () => Promise.resolve(),
        clients: { claim: () => Promise.resolve() },
    };

    vm.runInNewContext(source, sandbox);

    let installing = null;
    handlers.install({ waitUntil: (promise) => { installing = Promise.resolve(promise); } });
    await installing;

    process.stdout.write(JSON.stringify(log));
})().catch((error) => {
    process.stderr.write(String(error && error.stack || error));
    process.exit(1);
});
