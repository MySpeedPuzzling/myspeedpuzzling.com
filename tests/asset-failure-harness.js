// Runs the inline asset-failure script from base.html.twig (handed over by
// tests/AssetFailureScriptTest.php exactly as a page rendered it) in a fake
// browser, one page load after another, and prints what each load did as JSON.
// The script fails open by design, so a mistake in it would otherwise be silent
// forever - or, worse, reload a page in a loop.
//
// A scenario is a list of page loads. Each load says how it was reached
// ('navigate' starts a new history entry, 'reload' keeps it) and which /build
// files fail on it: 'error' fires an error event on the element, 'sweep' leaves
// a stylesheet without a sheet for the load-time sweep, 'both' does both.

const fs = require('fs');
const vm = require('vm');
const nodeCrypto = require('crypto');

const { script, scenarios } = JSON.parse(fs.readFileSync(0, 'utf8'));

function integrityOf(body) {
    return 'sha384-' + nodeCrypto.createHash('sha384').update(body).digest('base64');
}

async function run(scenario) {
    const storage = {};
    let historyState = null;
    const loads = [];

    for (const load of scenario.loads) {
        if (load.via === 'navigate') historyState = null;
        if (load.via === 'reload' && scenario.storage === 'wiped on reload') {
            for (const key of Object.keys(storage)) delete storage[key];
        }

        const did = { via: load.via, beacons: [], reloads: 0, refetches: [], cacheDeletes: [] };
        const listeners = { error: [], load: [] };
        const failing = load.failing || [];
        const network = scenario.network || {};

        const element = (asset) => {
            const isScript = asset.url.endsWith('.js');
            const integrity = asset.integrity === 'auto' && network[asset.url] && typeof network[asset.url].body === 'string'
                ? integrityOf(network[asset.url].body)
                : (asset.integrity && asset.integrity !== 'auto' ? asset.integrity : null);

            return {
                tagName: isScript ? 'SCRIPT' : 'LINK',
                src: isScript ? asset.url : '',
                href: isScript ? '' : asset.url,
                getAttribute: (name) => (name === 'integrity' ? integrity : null),
            };
        };

        const sessionStorage = {
            getItem: (key) => {
                if (scenario.storage === 'unavailable') throw new Error('SecurityError');
                return key in storage ? storage[key] : null;
            },
            setItem: (key, value) => {
                if (scenario.storage === 'unavailable') throw new Error('SecurityError');
                storage[key] = String(value);
            },
        };

        const sandbox = {
            JSON, Promise, Object, String, parseInt, Uint8Array,
            // Time runs fast here: the script's 10 s refetch deadline fires after 20 ms.
            setTimeout: (callback, delay) => setTimeout(callback, Math.min(delay, 20)).unref(),
            btoa: (value) => Buffer.from(value, 'binary').toString('base64'),
            crypto: { subtle: nodeCrypto.webcrypto.subtle },
            navigator: {
                serviceWorker: scenario.controlled ? { controller: {} } : {},
                webdriver: scenario.webdriver === true,
                sendBeacon: (url, body) => { did.beacons.push({ url, payload: JSON.parse(body) }); return true; },
            },
            document: {
                querySelector: (selector) => (selector === 'meta[name="msp-rendered-at"]'
                    ? { getAttribute: () => String(scenario.renderedAt) }
                    : null),
                querySelectorAll: () => (load.stylesheets || []).map((url) => {
                    const asset = failing.find((candidate) => candidate.url === url);
                    const broken = asset !== undefined && asset.via !== 'error';
                    return { ...element(asset || { url }), sheet: broken ? null : { cssRules: { length: 12 } } };
                }),
            },
            history: {
                get state() { return historyState; },
                replaceState: (state) => { historyState = JSON.parse(JSON.stringify(state)); },
            },
            sessionStorage,
            caches: {
                keys: () => Promise.resolve(['static-v7', 'images-v7']),
                open: (cacheName) => Promise.resolve({
                    keys: () => Promise.resolve([
                        { url: 'https://myspeedpuzzling.com/build/app.css' },
                        { url: 'https://myspeedpuzzling.com/fonts/rubik/rubik-latin.woff2' },
                    ]),
                    delete: (request) => { did.cacheDeletes.push(cacheName + ' ' + request.url); return Promise.resolve(true); },
                }),
            },
            fetch: (url, options) => {
                did.refetches.push({ url, cache: options.cache });
                const answer = network[url];
                if (!answer || answer.status === 0) return Promise.reject(new TypeError('Failed to fetch'));
                if (answer.status === 'stalled') return new Promise(() => {});
                const bytes = Buffer.from(answer.body || '');

                return Promise.resolve({
                    ok: answer.status >= 200 && answer.status < 300,
                    status: answer.status,
                    arrayBuffer: () => Promise.resolve(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.length)),
                });
            },
            location: {
                pathname: '/en/hub',
                reload: () => { did.reloads++; },
            },
            addEventListener: (type, listener) => { if (listeners[type]) listeners[type].push(listener); },
        };
        sandbox.window = sandbox;

        vm.runInNewContext(script, sandbox);

        for (const asset of failing) {
            if (asset.via === 'sweep') continue;
            for (const listener of listeners.error) listener({ target: element(asset) });
        }
        await new Promise((resolve) => setTimeout(resolve, 10));
        for (const listener of listeners.load) listener({});
        await new Promise((resolve) => setTimeout(resolve, 50));

        did.historyMarked = !!(historyState && historyState.mspAssetHeal);
        loads.push(did);
    }

    return { name: scenario.name, loads };
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
