// Runs the inline stale-document script from base.html.twig (handed over by
// tests/StaleDocumentScriptTest.php exactly as a page rendered it) in a fake
// browser, and prints what it did per scenario as JSON. The script fails open
// by design, so a mistake in it would otherwise be silent forever.

const fs = require('fs');
const vm = require('vm');

const { script, scenarios } = JSON.parse(fs.readFileSync(0, 'utf8'));

async function run(scenario) {
    const did = { name: scenario.name, probes: 0, beacons: [], reloads: 0, cacheDeletes: [] };
    const storage = scenario.healedBefore ? { 'msp-stale-heal': '1' } : {};

    class FakeDate extends Date {
        static now() { return scenario.clientNow * 1000; }
    }

    const sandbox = {
        Date: FakeDate, Math, JSON, Promise, parseInt,
        navigator: {
            serviceWorker: scenario.controlled ? { controller: {} } : {},
            sendBeacon: (url, body) => { did.beacons.push({ url, payload: JSON.parse(body) }); return true; },
        },
        document: {
            wasDiscarded: false,
            querySelector: () => ({ getAttribute: () => String(scenario.renderedAt) }),
        },
        performance: {
            getEntriesByType: () => [{ type: scenario.navigationType, deliveryType: '', transferSize: 0, workerStart: 12.5 }],
        },
        fetch: (url, options) => {
            did.probes++;
            did.probe = { url, method: options.method, cache: options.cache };

            return Promise.resolve({
                headers: { get: (name) => (name === 'Date' ? new Date(scenario.serverNow * 1000).toUTCString() : null) },
            });
        },
        sessionStorage: {
            getItem: (key) => (key in storage ? storage[key] : null),
            setItem: (key, value) => { storage[key] = value; },
        },
        caches: {
            keys: () => Promise.resolve(['static-v7', 'images-v7']),
            open: (cacheName) => Promise.resolve({
                delete: (url) => { did.cacheDeletes.push(cacheName + ' ' + url); return Promise.resolve(true); },
            }),
        },
        location: {
            pathname: '/en/hub',
            href: 'https://myspeedpuzzling.com/en/hub',
            reload: () => { did.reloads++; },
        },
    };
    sandbox.window = sandbox;

    vm.runInNewContext(script, sandbox);
    await new Promise((resolve) => setTimeout(resolve, 20));

    return did;
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
