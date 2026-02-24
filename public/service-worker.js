const CACHE_VERSION = 'v2';
const STATIC_CACHE = 'static-' + CACHE_VERSION;
const IMAGES_CACHE = 'images-' + CACHE_VERSION;

const OFFLINE_URL = '/offline.html';
const ENTRYPOINTS_URL = '/build/entrypoints.json';
const IMAGES_CACHE_LIMIT = 200;

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
            // Always cache the offline page
            await cache.add(OFFLINE_URL);

            // Try to pre-cache current build assets from entrypoints.json
            try {
                const response = await fetch(ENTRYPOINTS_URL);
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
    // Exception: Google Fonts are cache-first below (immutable, great offline win).
    if (url.origin !== self.location.origin) {
        if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
            event.respondWith(cacheFirst(request, STATIC_CACHE));
        }
        return;
    }

    // Network-only: specific paths
    if (NETWORK_ONLY_PATHS.some((path) => url.pathname.startsWith(path))) return;

    // Network-only: Mercure SSE connections
    if (url.pathname.startsWith('/.well-known/mercure')) return;

    // Network-only: Turbo Stream responses
    const accept = request.headers.get('Accept') || '';
    if (accept.includes('text/vnd.turbo-stream.html')) return;

    // Strategy: Cache-first for /build/* (content-hashed assets)
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request, STATIC_CACHE));
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

    // Everything else: network-first, no proactive caching
    event.respondWith(networkFirst(request));
});

// ─── Strategies ─────────────────────────────────────────────────────

async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;

    const response = await fetch(request);
    if (response.ok || response.type === 'opaque') {
        const cache = await caches.open(cacheName);
        cache.put(request, response.clone());
    }
    return response;
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
        // Cache opaque responses (cross-origin) and successful same-origin responses
        if (response.ok || response.type === 'opaque') {
            cache.put(request, response.clone());
            trimCache(cacheName, IMAGES_CACHE_LIMIT);
        }
        return response;
    }).catch(() => cached);

    return cached || fetchPromise;
}

async function networkFirst(request) {
    try {
        return await fetch(request);
    } catch (e) {
        const cached = await caches.match(request);
        return cached || new Response('', { status: 503 });
    }
}

// ─── Helpers ────────────────────────────────────────────────────────

function isImageRequest(request, url) {
    const accept = request.headers.get('Accept') || '';
    if (accept.includes('image/')) return true;

    const ext = url.pathname.split('.').pop();
    return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico'].includes(ext);
}

function trimCache(cacheName, maxItems) {
    caches.open(cacheName).then((cache) => {
        cache.keys().then((keys) => {
            if (keys.length > maxItems) {
                cache.delete(keys[0]).then(() => trimCache(cacheName, maxItems));
            }
        });
    });
}
