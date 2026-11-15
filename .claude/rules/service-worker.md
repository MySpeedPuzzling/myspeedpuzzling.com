---
paths:
  - "public/service-worker.js"
  - "public/offline.html"
  - "templates/base.html.twig"
  - "src/Controller/*AssetLoadFailure*"
  - "src/Controller/*StaleDocument*"
  - "src/Controller/*NavigationFetchFailure*"
  - "src/Services/**/*AssetLoadFailure*"
  - "tests/ServiceWorker*"
  - "tests/service-worker-harness.js"
  - "tests/AssetFailure*"
  - "tests/asset-failure-harness.js"
  - ".docker/merge-previous-build.php"
---

# Service worker, PWA and asset self-heal

## Service Worker (PWA)
- The service worker is at `public/service-worker.js` with a `CACHE_VERSION` constant
- **Bump `CACHE_VERSION` only when something already sitting in visitors' caches must be evicted**: the offline page (`public/offline.html`) or a precached self-hosted font changed, a non-content-hashed same-origin asset changed, or a bug wrote something into a cache that must not stay there (v7 = HTML in the image cache). The activate handler deletes every cache without the current suffix, so a bump makes every visitor re-download all `/build` assets
- **No bump needed** for a change to the worker's code alone (routing, strategies, retries): `/service-worker.js` is served `Cache-Control: no-cache` and the browser's update check compares the bytes, so any change installs the new worker (`skipWaiting()` + `clients.claim()`). Nor for `/build/*` asset changes — those are content-hashed by Webpack Encore and cached by URL, so new builds get new URLs automatically
- The service worker uses cache-first for `/build/*` (fetches with `cache: 'reload'` and buffers the full body before caching — a truncated or HTTP-cache-poisoned download must never become the permanent copy), network-only for HTML navigation (offline fallback only, no caching), and stale-while-revalidate for images (an answer with `Cache-Control: no-store` is never written to the cache). Requests it has no strategy for are NOT intercepted
- **Only real navigations (`request.mode === 'navigate'`) get the offline page** — never route on `Accept: text/html`: that catches Turbo Drive visits, Turbo Frame loads and `fetch()` calls asking for HTML, and answering their network error with the offline page (a 200) stops Turbo's own fallback to a full page load and pastes the page into frames and forms
- **A rejected navigation fetch is asked once more after 500 ms** before the offline page is shown (not when `navigator.onLine === false` or the navigation was abandoned; no timeout - there is no cached copy to fall back to). Safari rejects with "Load failed" on a connection that died unnoticed, typically the first request after a tab sat in the background (2026-10-06: "You are offline" while browsing other sites). Each retry is reported to `POST /-/navigation-fetch-failure` (`NavigationFetchFailureController`, path only, no cookies): `recovered` = info on the `service_worker` channel (own prod handler, count it in Loki), `offline-page` = warning → Sentry, since the report arriving proves the device was online
- Stale `/build/*` cache entries from previous deploys are pruned automatically (on cache miss, validated against current `entrypoints.json`/`manifest.json`)
- **Asset-failure telemetry + self-heal**: an inline ES5 script in `base.html.twig` reports failed `/build` script/link loads (incl. silent SRI rejections) via `sendBeacon` to `POST /-/asset-load-failure` (`AssetLoadFailureController`; `AssetLoadFailureClassifier` logs only actionable verdicts at warning → Sentry — fresh page missing an asset, corrupt bytes vs SRI, heal gave up — and crawlers, pages older than the 7-day carry-over, heals in progress and browser-side blocking at info; reports are bounded per session and a history-state mark stops reload loops even without sessionStorage) and, once per session, purges cached `/build` entries and reloads. It must stay inline and dependency-free — it runs exactly when the bundles don't
- **Stale-document telemetry + self-heal**: a second inline ES5 script in `base.html.twig` compares `<meta name="msp-rendered-at">` with the server's own `Date` header (HEAD `/service-worker.js`; the visitor's clock only raises suspicion, so a phone running fast reports nothing) on real `navigate`/`reload` page loads under a service worker. A document older than 5 min is reported via `sendBeacon` to `POST /-/stale-document` (`StaleDocumentController`: warning → Sentry, but only `info` for `deliveryType: cache`, i.e. a browser restoring a tab from its HTTP cache) and, once per session, the URL is dropped from CacheStorage and the page reloaded. It exists because the v6 worker answered every Chromium navigation stale-while-revalidate ("I have to refresh twice to see my new time") and nothing server-side could see it. The timestamp must stay in the `<meta>`, never in the script text — Turbo re-runs a head script whose text changed. `tests/ServiceWorkerBehaviourTest.php` executes the real worker under node (`tests/service-worker-harness.js`) and fails if any document request is ever answered from, or written to, a cache
- The Docker image carries recent releases' `/build` assets (`.docker/merge-previous-build.php`) so HTML a browser loaded before this release still resolves its assets. Retention is by **age** (`RETENTION_DAYS`, default 7), not by build count — a burst of deploys must not evict a generation clients still hold. Each image ships a `/build/.carried-assets.json` ledger recording when every carried file went stale; the next build reads it so the clock never resets
