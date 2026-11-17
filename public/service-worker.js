// v7: the fetch router used to classify every Chromium/Firefox navigation as an
// image and answer it from the image cache. Bumping the version is what evicts
// the HTML those browsers already stored under images-v6 - the activate handler
// deletes every cache not carrying the current suffix, and without that bump a
// signed-out homepage would keep being replayed on existing installs.
const CACHE_VERSION = 'v7';
const STATIC_CACHE = 'static-' + CACHE_VERSION;
const IMAGES_CACHE = 'images-' + CACHE_VERSION;
// The live result entry page's offline copy per locale + a record of the files it needs (toolkit C6)
const LIVE_SHELL_CACHE = 'live-shell-' + CACHE_VERSION;

const OFFLINE_URL = '/offline.html';
const ENTRYPOINTS_URL = '/build/entrypoints.json';
const MANIFEST_URL = '/build/manifest.json';
const IMAGES_CACHE_LIMIT = 200;

// A navigation whose fetch rejects is tried once more after this pause before
// the offline page is shown - see networkFirstNavigation()
const NAVIGATION_RETRY_DELAY_MS = 500;
const NAVIGATION_FAILURE_REPORT_URL = '/-/navigation-fetch-failure';

// ─── Live result entry offline copy (competition toolkit C6) ───────
// docs/features/competitions-management/toolkit/C-judge-page.md §1.4. A referee's live entry page asks the worker
// (message `live-shell:prepare`) to store the page's data-free offline copy (`live_results_shell`, one per locale) and
// every file it needs. A navigation to a live entry URL then goes to the network first; when the network fails, or
// says nothing for LIVE_ENTRY_TIMEOUT_MS (lie-fi at a venue), the stored copy of the URL's locale answers - the page
// fills itself from the device's own snapshots (C4/C5). The real page is never stored.
const LIVE_ENTRY_TIMEOUT_MS = 4000;
const LIVE_SHELL_LOCALES = ['en', 'cs', 'es', 'ja', 'fr', 'de'];
const LIVE_SHELL_RECORD_PREFIX = '/__live-shell/';
const LIVE_SHELL_ASSETS = /<script type="application\/json" id="msp-live-shell-assets">([^<]*)<\/script>/;
// The QR decoder for phones whose browser reads no QR codes itself (assets/official_results_scan.js) - immutable,
// the version is in the path
const SCANNER_URLS = [
    '/barcode-scanner/zbar-wasm-0.9.15/zbar.wasm',
    '/barcode-scanner/zbar-wasm-0.9.15/zbar-wasm.js',
    '/barcode-scanner/zbar-wasm-0.9.15/barcode-detector-polyfill.js',
];
// Exactly the paths assets/live_entry_route.js parseLiveEntryRoute() accepts (same rules: ids of any case, a trailing
// slash) - both are run over tests/fixtures/live-entry-routes.json and every router path (ServiceWorkerLiveRoutesTest).
// [pattern, locale of the copy: a fixed one, the match's group 1, or null = the path has none]
const LIVE_UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const LIVE_ENTRY_PATHS = [
    [new RegExp('^/zadavani-vysledku/' + LIVE_UUID + '/?$', 'i'), 'cs'],
    [new RegExp('^/(en|es|ja|fr|de)/live-results/' + LIVE_UUID + '/?$', 'i'), 1],
    [new RegExp('^/zadavani-vysledku-udalosti/' + LIVE_UUID + '/?$', 'i'), 'cs'],
    [new RegExp('^/(en|es|ja|fr|de)/live-results/event/' + LIVE_UUID + '/?$', 'i'), 1],
    [new RegExp('^/(en|cs|es|ja|fr|de)/live/' + LIVE_UUID + '/p/' + LIVE_UUID + '/?$', 'i'), 1],
    [new RegExp('^(?:/(en|cs|es|ja|fr|de))?/live/' + LIVE_UUID + '/g/' + LIVE_UUID + '/?$', 'i'), 1],
    [new RegExp('^(?:/(en|cs|es|ja|fr|de))?/live/' + LIVE_UUID + '/t/[1-9][0-9]{0,3}/?$', 'i'), 1],
    [new RegExp('^(?:/(en|cs|es|ja|fr|de))?/live/' + LIVE_UUID + '/e/' + LIVE_UUID + '/?$', 'i'), 1],
];

// Self-hosted fonts to precache on install (instant on repeat visits)
const FONT_URLS = [
    '/fonts/rubik/rubik-latin.woff2',
    '/fonts/rubik/rubik-latin-ext.woff2',
];

// Icon font source paths to resolve from manifest.json (content-hashed in production)
// Matched as PREFIXES, never as whole keys. Webpack keeps the query string the
// stylesheet asked for, so the manifest actually holds
// 'build/fonts/cartzilla-icons.woff?ufvuz0' and 'build/fonts/bootstrap-icons.woff2?'.
// Exact lookups returned undefined for both and this precaching silently did
// nothing. Hardcoding the suffixes would rot again - ?ufvuz0 is icomoon's own
// cache-buster and changes whenever the font is regenerated.
const ICON_FONT_KEY_PREFIXES = [
    'build/fonts/cartzilla-icons.woff',
    'build/fonts/bootstrap-icons.woff2',
];

// Encore entries NOT precached on install: their files are cached on first use only (cache-first like every /build
// file). Every other entry is precached for every visitor. Skipped - pages a handful of devices open, never downloaded
// by every visitor: `display` (the competition timer screens and stream overlays, competition toolkit D4),
// `live_entry` (the referees' live result entry: their devices store the page's offline copy and its files on purpose,
// `live-shell:prepare`, toolkit C6) and `live_entry_native` (Turbo for that page inside the native apps, which run no
// service worker at all), and the online competitions' style entries `compete`, `online_rounds`, `online_watch`
// (participants and organisers of an online round only - the install precache was a silent no-op before toolkit C2
// fixed it, so this keeps everyday visitors downloading only `app`).
const PRECACHE_SKIP_ENTRIES = ['display', 'live_entry', 'live_entry_native', 'compete', 'online_rounds', 'online_watch'];

// Patterns that should never be cached (network-only)
const NETWORK_ONLY_PATHS = [
    '/_components/', // Symfony Live Components
    '/_wdt/',        // Symfony Web Debug Toolbar
    '/_profiler/',   // Symfony Profiler
];

// ─── Install ────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(async (cache) => {
            // Always cache the offline page and self-hosted fonts
            await cache.addAll([OFFLINE_URL, ...FONT_URLS]);

            // Try to pre-cache current build assets from entrypoints.json.
            // cache:'no-cache' matters: entrypoints.json is served immutable with a
            // 1-year max-age, so a plain fetch could precache a year-old asset list.
            try {
                const response = await fetch(ENTRYPOINTS_URL, { cache: 'no-cache' });
                if (response.ok) {
                    await cache.addAll(precacheUrls(await response.json()));
                }
            } catch (e) {
                // First visit may be offline — skip precaching
            }

            // Pre-cache icon fonts (resolve content-hashed URLs from manifest)
            try {
                const manifestResponse = await fetch(MANIFEST_URL, { cache: 'no-cache' });
                if (manifestResponse.ok) {
                    const manifest = await manifestResponse.json();
                    const fontUrls = Object.keys(manifest)
                        .filter((key) => ICON_FONT_KEY_PREFIXES.some((prefix) => key.startsWith(prefix)))
                        .map((key) => manifest[key])
                        .filter(Boolean);
                    if (fontUrls.length) await cache.addAll(fontUrls);
                }
            } catch (e) {
                // Non-critical — fonts will load from network on first request
            }
        }).then(() => self.skipWaiting())
    );
});

// ─── Activate ───────────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys
                    .filter((key) => !key.endsWith('-' + CACHE_VERSION))
                    .map((key) => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// ─── Messages ───────────────────────────────────────────────────────
self.addEventListener('message', (event) => {
    const data = event.data;

    if (data === null || typeof data !== 'object' || data.type !== 'live-shell:prepare' || !LIVE_SHELL_LOCALES.includes(data.locale)) {
        return;
    }

    event.waitUntil(prepareLiveShell(data.locale, data.scanner === true).then((record) => {
        if (event.source && typeof event.source.postMessage === 'function') {
            event.source.postMessage(record === null
                ? { type: 'live-shell:failed', locale: data.locale }
                : { type: 'live-shell:ready', locale: data.locale, storedAt: record.storedAt });
        }
    }));
});

// ─── Fetch ──────────────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Only handle GET requests
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Skip cross-origin requests — let the browser's native HTTP cache handle them.
    // CDN images (img.myspeedpuzzling.com) have their own Cache-Control headers via Traefik.
    if (url.origin !== self.location.origin) return;

    // Network-only: specific paths
    if (NETWORK_ONLY_PATHS.some((path) => url.pathname.startsWith(path))) return;

    // Network-only: Mercure SSE connections
    if (url.pathname.startsWith('/.well-known/mercure')) return;

    // Network-only: Turbo Stream responses
    const accept = request.headers.get('Accept') || '';
    if (accept.includes('text/vnd.turbo-stream.html')) return;

    // Strategy: Cache-first for /build/* (content-hashed), /fonts/* (self-hosted fonts) and /barcode-scanner/* (the
    // zbar decoder, versioned path - an iPhone scanning a name tag after an offline reload needs it from here)
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/barcode-scanner/')) {
        event.respondWith(cacheFirst(event, request, STATIC_CACHE));
        return;
    }

    // Strategy: Network-only for HTML navigation (offline fallback only).
    //
    // This MUST stay above the image check. Chrome, Edge and Firefox send
    // "image/avif,image/webp" inside the Accept header of every top-level
    // navigation, so any Accept-based "is this an image?" test matches documents
    // too. While it did, every navigation in those browsers was answered from the
    // stale-while-revalidate cache: the PWA (start_url "/") relaunched into
    // whatever copy of the homepage was cached first - usually the signed-out one -
    // and personalized HTML was stored in a shared cache and replayed to whoever
    // opened the app next. Safari never matched, which is why only Chromium
    // users saw it.
    //
    // Real navigations only - never "anything that accepts text/html". That also
    // caught Turbo Drive visits, Turbo Frame loads and fetch() calls asking for
    // HTML, and answered their network errors with the offline page as a 200:
    // Turbo rendered it as the visited page instead of falling back to a full
    // page load (its own retry), a frame showed "Content missing", and the
    // first-try check pasted the whole offline page into the form. Left alone,
    // they see the error themselves; when the device really is offline, Turbo's
    // full page load comes back here as a navigation and gets the offline page.
    if (request.mode === 'navigate') {
        // The live result entry: its stored offline copy when the network fails or is too slow (toolkit C6)
        const liveEntry = liveEntryRoute(url.pathname);
        if (liveEntry !== null) {
            event.respondWith(liveEntryNavigation(event, request, liveEntry.locale));
            return;
        }

        event.respondWith(networkFirstNavigation(event, request));
        return;
    }

    // Strategy: Stale-while-revalidate for images
    if (isImageRequest(request, url)) {
        event.respondWith(staleWhileRevalidate(request, IMAGES_CACHE));
        return;
    }

    // Everything else is intentionally NOT intercepted: fewer interception
    // paths mean a service-worker bug cannot break requests it has no
    // business handling.
});

// ─── Strategies ─────────────────────────────────────────────────────

async function cacheFirst(event, request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;

    // A miss means a deploy changed asset URLs — a good, self-limiting moment
    // to drop cached assets from previous builds (they are never requested
    // again, but would otherwise accumulate in the cache forever).
    event.waitUntil(schedulePrune());

    // cache:'reload' bypasses the HTTP disk cache — a poisoned immutable
    // entry must never become the SW's permanent copy
    const response = await fetch(request, { cache: 'reload' });

    if (response.status !== 200) {
        return response;
    }

    // Buffer the full body before storing: arrayBuffer() rejects on a
    // truncated stream, so an incomplete download is never cached. cacheFirst
    // never revalidates within a CACHE_VERSION, so a corrupt entry would be
    // served forever — and SRI would silently reject it on every page load.
    const body = await response.arrayBuffer();
    const init = {
        status: response.status,
        statusText: response.statusText,
        headers: response.headers,
    };

    const cache = await caches.open(cacheName);
    await cache.put(request, new Response(body, init));

    return new Response(body, init);
}

// A rejected fetch() does not mean the device is offline. Safari rejects with
// "Load failed" when it reuses a connection that was torn down without it
// noticing - typically on the first request after a tab sat in the background -
// and a navigation the worker answers never falls back to the browser's own
// handling: whatever is returned here is what the visitor sees. Without the
// retry, a person browsing other sites just fine got "You are offline", and a
// reload worked at once (2026-10-06). A navigation through the worker is a GET
// without a body, so asking again is safe, and the failed connection is gone
// from the pool by then.
//
// Deliberately no timeout: with no cached copy to fall back to, a timeout can
// only turn a slow page on a slow network into a false offline page.
async function networkFirstNavigation(event, request) {
    let firstError;

    try {
        return await fetch(request);
    } catch (e) {
        firstError = e;
    }

    // A device that knows it is offline gets the offline page without waiting,
    // and a navigation the visitor abandoned has nobody waiting for it
    if ((self.navigator && self.navigator.onLine === false) || isAborted(request, firstError)) {
        return offlinePage();
    }

    await new Promise((resolve) => setTimeout(resolve, NAVIGATION_RETRY_DELAY_MS));

    if (isAborted(request, null)) {
        return offlinePage();
    }

    try {
        const response = await fetch(request);
        reportNavigationFailure(event, request, 'recovered', firstError, null);
        return response;
    } catch (retryError) {
        reportNavigationFailure(event, request, 'offline-page', firstError, retryError);
        return offlinePage();
    }
}

async function offlinePage() {
    const offline = await caches.match(OFFLINE_URL);
    return offline || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
}

function isAborted(request, error) {
    return (request.signal && request.signal.aborted === true)
        || (error !== null && typeof error === 'object' && error.name === 'AbortError');
}

// Counts how often a navigation needed the retry, and - when the report gets
// through - proves an offline page went to somebody who was online
// (NavigationFetchFailureController). Only the path: query strings can carry
// sign-in tokens. Must be called while respondWith() is still pending -
// waitUntil() throws once the event has settled - and must never affect the
// answer, so every failure is swallowed.
function reportNavigationFailure(event, request, outcome, firstError, retryError) {
    try {
        const report = fetch(NAVIGATION_FAILURE_REPORT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                outcome,
                page: new URL(request.url).pathname,
                error: describeError(firstError),
                retryError: retryError === null ? null : describeError(retryError),
                retryDelayMs: NAVIGATION_RETRY_DELAY_MS,
            }),
            credentials: 'omit',
            cache: 'no-store',
            keepalive: true,
        }).catch(() => {});

        event.waitUntil(report);
    } catch (e) {
        // Reporting is best-effort
    }
}

function describeError(error) {
    if (error === null || typeof error !== 'object') {
        return String(error).slice(0, 200);
    }

    return (String(error.name || 'Error') + ': ' + String(error.message || '')).slice(0, 200);
}

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    const fetchPromise = fetch(request).then((response) => {
        // Cache opaque responses (cross-origin) and successful same-origin responses.
        // Best-effort background write: images self-correct on the next request,
        // but trim must only run after the write to keep the count accurate.
        //
        // HTML is refused outright as a second line of defence. Routing already
        // keeps documents out of here, but this cache is keyed by URL alone and a
        // page carrying someone's name, times or admin links must never be
        // replayable to the next visitor if routing regresses again.
        if (isHtmlResponse(response)) {
            return response;
        }

        // The Cache API stores whatever it is given, Cache-Control included: an
        // image the server said never to keep (the result share card drawn over
        // the placeholder photo) would otherwise be replayed on the next view
        // instead of being asked for again.
        if (isNoStoreResponse(response)) {
            return response;
        }

        if (response.ok || response.type === 'opaque') {
            cache.put(request, response.clone())
                .then(() => trimCache(cacheName, IMAGES_CACHE_LIMIT))
                .catch(() => {});
        }
        return response;
    }).catch(() => cached);

    return cached || fetchPromise;
}

// ─── Live result entry offline copy (toolkit C6) ────────────────────

// {locale: 'cs'|…|null} for a live entry path (null = a printed tag path without a locale), else null
function liveEntryRoute(pathname) {
    for (const [pattern, locale] of LIVE_ENTRY_PATHS) {
        const match = pathname.match(pattern);
        if (match !== null) {
            if (locale === 1) {
                return { locale: match[1] === undefined ? null : match[1].toLowerCase() };
            }
            return { locale };
        }
    }
    return null;
}

function liveShellPath(locale) {
    return locale === 'cs' ? '/zadavani-vysledku-shell' : '/' + locale + '/live-results-shell';
}

// The network first - never a cache read while it answers - and the stored copy of the URL's locale when it fails
// (after the one retry of networkFirstNavigation(); none when the device knows it is offline) or says nothing for
// LIVE_ENTRY_TIMEOUT_MS. Whatever the network answers is passed through untouched (a sign-in redirect stays one).
// Without a stored copy: as any other page - waiting for a slow network, the offline page when it fails.
async function liveEntryNavigation(event, request, locale) {
    const FAILED = {};
    const TIMED_OUT = {};
    let retried = false;
    let firstError = null;

    const network = (async () => {
        try {
            return await fetch(request);
        } catch (e) {
            firstError = e;
        }

        if ((self.navigator && self.navigator.onLine === false) || isAborted(request, firstError)) {
            return FAILED;
        }

        await new Promise((resolve) => setTimeout(resolve, NAVIGATION_RETRY_DELAY_MS));

        if (isAborted(request, null)) {
            return FAILED;
        }

        retried = true;
        try {
            const response = await fetch(request);
            reportNavigationFailure(event, request, 'recovered', firstError, null);
            return response;
        } catch (e) {
            return FAILED;
        }
    })();

    let timer = null;
    const timeout = new Promise((resolve) => {
        timer = setTimeout(() => resolve(TIMED_OUT), LIVE_ENTRY_TIMEOUT_MS);
    });

    let outcome = await Promise.race([network, timeout]);
    clearTimeout(timer);

    if (outcome !== FAILED && outcome !== TIMED_OUT) {
        return outcome;
    }

    const shell = await storedLiveShell(locale);
    if (shell !== null) {
        return shell;
    }

    if (outcome === TIMED_OUT) {
        outcome = await network;
        if (outcome !== FAILED) {
            return outcome;
        }
    }

    if (retried) {
        reportNavigationFailure(event, request, 'offline-page', firstError, null);
    }

    return offlinePage();
}

// The copy of the locale asked for; else (a tag path without a locale, or a locale never prepared here) the copy this
// device stored last - a working page in another language beats the offline page
async function storedLiveShell(locale) {
    try {
        const cache = await caches.open(LIVE_SHELL_CACHE);
        let stored = locale === null ? undefined : await cache.match(liveShellPath(locale));

        if (stored === undefined) {
            const records = await liveShellRecords(cache);
            records.sort((a, b) => b.storedAt - a.storedAt);
            if (records.length > 0) {
                stored = await cache.match(liveShellPath(records[0].locale));
            }
        }

        if (stored === undefined) {
            return null;
        }

        return new Response(await stored.arrayBuffer(), {
            status: 200,
            headers: {
                'Content-Type': stored.headers.get('Content-Type') || 'text/html; charset=UTF-8',
                'Cache-Control': 'no-cache',
                'X-MSP-Live-Shell': '1',
            },
        });
    } catch (e) {
        return null;
    }
}

async function liveShellRecords(cache) {
    const records = [];
    for (const key of await cache.keys()) {
        if (!new URL(key.url).pathname.startsWith(LIVE_SHELL_RECORD_PREFIX)) continue;
        try {
            const record = await (await cache.match(key)).json();
            if (record && LIVE_SHELL_LOCALES.includes(record.locale) && Array.isArray(record.assets)) {
                records.push(record);
            }
        } catch (e) {
            // A broken record is skipped
        }
    }
    return records;
}

// Fetches the locale's copy and stores every file it lists (static cache, like cacheFirst), then - only when all of
// them are there - the copy itself + its record: a stored copy never points at a file the device lacks. The copy is
// the same for everybody (no cookies sent, a stateless route); anything else than a plain 200 HTML answer is refused.
// The scanner's decoder files are stored too when the page asks (best effort, never blocks the copy).
async function prepareLiveShell(locale, scanner) {
    try {
        const path = liveShellPath(locale);
        const response = await fetch(path, { cache: 'no-cache', credentials: 'omit', redirect: 'error' });

        // (Set-Cookie is never readable in a browser - credentials: 'omit' keeps cookies out; the check is for the record)
        if (response.status !== 200 || response.redirected || !isHtmlResponse(response) || response.headers.get('Set-Cookie') !== null) {
            return null;
        }

        const body = await response.text();
        const listed = body.match(LIVE_SHELL_ASSETS);
        if (listed === null) return null;

        const assets = [];
        for (const asset of JSON.parse(listed[1])) {
            const url = new URL(String(asset), self.location.origin);
            if (url.origin !== self.location.origin || !(url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/'))) {
                return null;
            }
            assets.push(url.pathname + url.search);
        }
        if (!assets.some((asset) => asset.endsWith('.js'))) return null;

        const staticCache = await caches.open(STATIC_CACHE);
        for (const asset of assets) {
            if (!(await storeStaticFile(staticCache, asset))) return null;
        }

        const record = { locale, assets, storedAt: Date.now() };
        const cache = await caches.open(LIVE_SHELL_CACHE);
        await cache.put(path, new Response(body, { headers: { 'Content-Type': response.headers.get('Content-Type') || 'text/html; charset=UTF-8' } }));
        await cache.put(LIVE_SHELL_RECORD_PREFIX + locale + '.json', new Response(JSON.stringify(record), { headers: { 'Content-Type': 'application/json' } }));

        if (scanner) {
            for (const file of SCANNER_URLS) {
                await storeStaticFile(staticCache, file);
            }
        }

        return record;
    } catch (e) {
        return null;
    }
}

// A file already stored stays; a missing one is downloaded whole (cache: 'reload', buffered - see cacheFirst())
async function storeStaticFile(cache, path) {
    try {
        if ((await cache.match(path)) !== undefined) return true;

        const response = await fetch(path, { cache: 'reload' });
        if (response.status !== 200) return false;

        const body = await response.arrayBuffer();
        await cache.put(path, new Response(body, { status: 200, statusText: response.statusText, headers: response.headers }));
        return true;
    } catch (e) {
        return false;
    }
}

// ─── Static cache pruning ───────────────────────────────────────────

// One prune per service-worker lifetime is enough: misses cluster right after
// a deploy, and the worker is regularly restarted by the browser anyway.
let prunePromise = null;

function schedulePrune() {
    if (prunePromise === null) {
        prunePromise = pruneStaticCache().then((succeeded) => {
            if (!succeeded) {
                prunePromise = null; // Offline/transient failure — retry on a future miss
            }
        });
    }

    return prunePromise;
}

// Delete cached /build/* entries that the current build no longer references.
// Non-build entries (fonts, offline page) are never touched.
async function pruneStaticCache() {
    try {
        const valid = new Set();

        const entrypointsResponse = await fetch(ENTRYPOINTS_URL, { cache: 'no-cache' });
        if (!entrypointsResponse.ok) return false;
        const entrypoints = await entrypointsResponse.json();
        for (const entry of Object.values(entrypoints.entrypoints || {})) {
            for (const fileUrl of [...(entry.js || []), ...(entry.css || [])]) {
                valid.add(new URL(fileUrl, self.location.origin).pathname);
            }
        }

        const manifestResponse = await fetch(MANIFEST_URL, { cache: 'no-cache' });
        if (manifestResponse.ok) {
            const manifest = await manifestResponse.json();
            for (const fileUrl of Object.values(manifest)) {
                valid.add(new URL(fileUrl, self.location.origin).pathname);
            }
        }

        // A failed/empty asset list must never wipe the whole cache
        if (valid.size === 0) return false;

        // The files of every stored live entry copy stay while the copy does (it may be older than this build)
        for (const record of await liveShellRecords(await caches.open(LIVE_SHELL_CACHE))) {
            for (const asset of record.assets) {
                valid.add(new URL(asset, self.location.origin).pathname);
            }
        }

        const cache = await caches.open(STATIC_CACHE);
        const cachedRequests = await cache.keys();
        await Promise.all(cachedRequests.map((cachedRequest) => {
            const pathname = new URL(cachedRequest.url).pathname;
            if (pathname.startsWith('/build/') && !valid.has(pathname)) {
                return cache.delete(cachedRequest);
            }
            return Promise.resolve(false);
        }));

        return true;
    } catch (e) {
        return false;
    }
}

// ─── Helpers ────────────────────────────────────────────────────────

// The files install() precaches: every entry's JS and CSS of entrypoints.json, except the entries in
// PRECACHE_SKIP_ENTRIES (a file a precached entry shares with a skipped one - runtime.js - is still precached).
function precacheUrls(entrypoints) {
    const urls = new Set();
    for (const [name, entry] of Object.entries((entrypoints && entrypoints.entrypoints) || {})) {
        if (PRECACHE_SKIP_ENTRIES.includes(name)) continue;
        for (const fileUrl of [...(entry.js || []), ...(entry.css || [])]) {
            urls.add(fileUrl);
        }
    }
    return [...urls];
}

// Opaque (cross-origin) responses expose no headers - type 'opaque' reports an
// empty Content-Type - so they read as "not HTML" and stay cacheable as today.
function isHtmlResponse(response) {
    return (response.headers.get('Content-Type') || '').indexOf('text/html') !== -1;
}

function isNoStoreResponse(response) {
    return /(^|,)\s*no-store\s*(,|$)/i.test(response.headers.get('Cache-Control') || '');
}

// Never test the Accept header here: a navigation's Accept advertises image
// formats too (image/avif, image/webp), so an Accept-based test classifies every
// Chromium/Firefox page load as an image. `destination` is the browser's own
// classification of what the request is FOR - 'image' for <img>/CSS images,
// 'document' for navigations - and cannot be confused by content negotiation.
function isImageRequest(request, url) {
    if (request.destination === 'image') return true;

    // A non-empty destination that is not 'image' has already answered the
    // question. Only fall back to the extension when the browser told us nothing.
    if (request.destination !== '') return false;

    const ext = url.pathname.split('.').pop().toLowerCase();
    return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico'].includes(ext);
}

async function trimCache(cacheName, maxItems) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();

    // keys() returns entries in insertion order — delete the oldest ones
    for (const key of keys.slice(0, Math.max(0, keys.length - maxItems))) {
        await cache.delete(key);
    }
}
