// v7: the fetch router used to classify every Chromium/Firefox navigation as an
// image and answer it from the image cache. Bumping the version is what evicts
// the HTML those browsers already stored under images-v6 - the activate handler
// deletes every cache not carrying the current suffix, and without that bump a
// signed-out homepage would keep being replayed on existing installs.
const CACHE_VERSION = 'v7';
const STATIC_CACHE = 'static-' + CACHE_VERSION;
const IMAGES_CACHE = 'images-' + CACHE_VERSION;
const PAGES_CACHE = 'pages-' + CACHE_VERSION;

// Routes that must keep working offline (results entry console at venues).
// Cached last state responds when the network is gone; the page's
// own IndexedDB outbox handles the data.
// PORT-TODO: PR #136 also cached the console's page shell here; main forbids caching any document
// (see networkFirstNavigation() and ServiceWorkerBehaviourTest), so only non-HTML responses are cached now -
// decide how the console page itself works offline.
const OFFLINE_CAPABLE_PATTERNS = [
    /\/manage-round-results\//,
    /\/vysledky-kola\//,
    /^\/round-results-state\//,
];

const OFFLINE_URL = '/offline.html';
const ENTRYPOINTS_URL = '/build/entrypoints.json';
const MANIFEST_URL = '/build/manifest.json';
const IMAGES_CACHE_LIMIT = 200;

// A navigation whose fetch rejects is tried once more after this pause before
// the offline page is shown - see networkFirstNavigation()
const NAVIGATION_RETRY_DELAY_MS = 500;
const NAVIGATION_FAILURE_REPORT_URL = '/-/navigation-fetch-failure';

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
                    const data = await response.json();
                    const urls = [];
                    for (const entry of Object.values(data.entrypoints || {})) {
                        if (entry.js) urls.push(...entry.js);
                        if (entry.css) urls.push(...entry.css);
                    }
                    await cache.addAll(urls);
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

    // Strategy: Cache-first for /build/* (content-hashed) and /fonts/* (self-hosted fonts)
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/')) {
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
        event.respondWith(networkFirstNavigation(event, request));
        return;
    }

    // Strategy: Stale-while-revalidate for images
    if (isImageRequest(request, url)) {
        event.respondWith(staleWhileRevalidate(request, IMAGES_CACHE));
        return;
    }

    // Strategy: network-first WITH cache for the offline-capable results console state
    // (never documents - navigations are handled above, HTML is refused in networkFirstWithCache())
    if (OFFLINE_CAPABLE_PATTERNS.some((pattern) => pattern.test(url.pathname))) {
        event.respondWith(networkFirstWithCache(request, PAGES_CACHE));
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

        if (response.ok || response.type === 'opaque') {
            cache.put(request, response.clone())
                .then(() => trimCache(cacheName, IMAGES_CACHE_LIMIT))
                .catch(() => {});
        }
        return response;
    }).catch(() => cached);

    return cached || fetchPromise;
}

async function networkFirstWithCache(request, cacheName) {
    try {
        const response = await fetch(request);
        if (response.ok && !isHtmlResponse(response)) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }
        return response;
    } catch (e) {
        const cached = await caches.match(request);
        if (cached) return cached;

        return new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
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

// Opaque (cross-origin) responses expose no headers - type 'opaque' reports an
// empty Content-Type - so they read as "not HTML" and stay cacheable as today.
function isHtmlResponse(response) {
    return (response.headers.get('Content-Type') || '').indexOf('text/html') !== -1;
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
