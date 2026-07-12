const CACHE_VERSION = 'v6';
const STATIC_CACHE = 'static-' + CACHE_VERSION;
const IMAGES_CACHE = 'images-' + CACHE_VERSION;

const OFFLINE_URL = '/offline.html';
const ENTRYPOINTS_URL = '/build/entrypoints.json';
const MANIFEST_URL = '/build/manifest.json';
const IMAGES_CACHE_LIMIT = 200;

// Self-hosted fonts to precache on install (instant on repeat visits)
const FONT_URLS = [
    '/fonts/rubik/rubik-latin.woff2',
    '/fonts/rubik/rubik-latin-ext.woff2',
];

// Icon font source paths to resolve from manifest.json (content-hashed in production)
const ICON_FONT_KEYS = [
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
                    const fontUrls = ICON_FONT_KEYS
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

    // Strategy: Stale-while-revalidate for images
    if (isImageRequest(request, url)) {
        event.respondWith(staleWhileRevalidate(request, IMAGES_CACHE));
        return;
    }

    // Strategy: Network-only for HTML navigation (offline fallback only)
    if (request.mode === 'navigate' || accept.includes('text/html')) {
        event.respondWith(networkFirstNavigation(request));
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

async function networkFirstNavigation(request) {
    try {
        const response = await fetch(request);
        return response;
    } catch (e) {
        // Offline — show offline page
        const offline = await caches.match(OFFLINE_URL);
        return offline || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
    }
}

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    const fetchPromise = fetch(request).then((response) => {
        // Cache opaque responses (cross-origin) and successful same-origin responses.
        // Best-effort background write: images self-correct on the next request,
        // but trim must only run after the write to keep the count accurate.
        if (response.ok || response.type === 'opaque') {
            cache.put(request, response.clone())
                .then(() => trimCache(cacheName, IMAGES_CACHE_LIMIT))
                .catch(() => {});
        }
        return response;
    }).catch(() => cached);

    return cached || fetchPromise;
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

function isImageRequest(request, url) {
    const accept = request.headers.get('Accept') || '';
    if (accept.includes('image/')) return true;

    const ext = url.pathname.split('.').pop();
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
