/* Stale-document beacon + self-heal. Pages are never meant to be answered
   from a cache, and when one is, the server cannot see it: the v6 service
   worker replayed cached HTML to every Chromium navigation for months
   (people had to refresh twice to see a time they had just added) and
   the only signal was users writing in.

   The timestamp lives in a <meta>, never inside the script: Turbo
   re-evaluates a head script whose text changed, and this one must run
   once per real page load, not on every Turbo visit.

   Only a page under a service worker is checked - that is the one thing
   of ours able to cache a document, and it keeps crawlers out (they
   render HTML fetched days earlier, with no worker). back_forward
   navigations are skipped because browsers reuse stale HTML for those on
   purpose. The visitor's clock only raises suspicion; the verdict comes
   from the server's own Date header, so a phone running minutes fast
   reports nothing. The HEAD probe is a static file, is not GET (the
   worker ignores it) and only happens for a document that looks old.

   Fail open, like the asset script above: anything unexpected does
   nothing, and healing is capped at once per session. */
(function () {
    var STALE_AFTER_SECONDS = 300;

    try {
        if (!navigator.serviceWorker || !navigator.serviceWorker.controller) return;
        if (!window.fetch || !window.performance || !performance.getEntriesByType) return;

        var meta = document.querySelector('meta[name="msp-rendered-at"]');
        var renderedAt = meta ? parseInt(meta.getAttribute('content'), 10) : 0;
        if (!renderedAt) return;

        var navigation = performance.getEntriesByType('navigation')[0];
        if (!navigation || (navigation.type !== 'navigate' && navigation.type !== 'reload')) return;

        if (Date.now() / 1000 - renderedAt < STALE_AFTER_SECONDS) return;

        fetch('/service-worker.js', { method: 'HEAD', cache: 'no-store' }).then(function (response) {
            var serverNow = Date.parse(response.headers.get('Date') || '') / 1000;
            var age = Math.round(serverNow - renderedAt);
            if (!(age >= STALE_AFTER_SECONDS)) return;

            var healed;
            try { healed = window.sessionStorage.getItem('msp-stale-heal') === '1'; }
            catch (e) { return; }

            if (navigator.sendBeacon) {
                navigator.sendBeacon('__STALE_DOCUMENT_URL__', JSON.stringify({
                    page: window.location.pathname,
                    age: age,
                    type: navigation.type,
                    delivery: navigation.deliveryType || '',
                    transferSize: navigation.transferSize,
                    throughWorker: navigation.workerStart > 0,
                    discarded: document.wasDiscarded === true,
                    retry: healed
                }));
            }

            if (healed || !window.caches) return;
            try { window.sessionStorage.setItem('msp-stale-heal', '1'); } catch (e) { return; }

            // Drop this URL from every cache first, or a worker that
            // answers from one would hand the same copy straight back.
            window.caches.keys().then(function (cacheNames) {
                return Promise.all(cacheNames.map(function (cacheName) {
                    return window.caches.open(cacheName).then(function (cache) {
                        return cache.delete(window.location.href);
                    });
                }));
            }).then(function () {
                window.location.reload();
            }, function () {
                window.location.reload();
            });
        }, function () {});
    } catch (e) {}
})();
