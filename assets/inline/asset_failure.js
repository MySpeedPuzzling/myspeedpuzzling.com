/* Asset-failure beacon + self-heal. A corrupt cached bundle is refused by
   SRI on every page load and Sentry never hears about it (its browser SDK
   lives inside the dead bundle), so this must stay inline, dependency-free
   and ES5: it runs exactly when the bundles do not.

   Two ways in, because an `error` event is not guaranteed: the listener
   below catches outright load failures, and a load-time sweep of
   document.styleSheets catches a stylesheet that is missing or empty
   without ever firing one (wrong MIME type, empty 200, silent SRI drop).
   Both can see the same file, so each URL counts once per page load.

   Recovery purges CacheStorage AND refetches each asset with
   cache:'reload'. That refetch is the whole point: /build is served
   immutable for a year, so a poisoned entry in the browser's *HTTP*
   cache survives both a CacheStorage purge and a reload, and only a
   forced revalidation can overwrite it. Purging alone left users stuck
   until the entry expired. The refetch also hashes what it received
   against the element's SRI hash, and the report carries the verdict:
   "corrupt" means we deliver bad bytes, "intact" plus a page that still
   fails means something inside the browser blocks the load (the headless
   scrapers that block stylesheets look exactly like that).

   Reports are bounded per session: every heal reports once (the server
   logs those at info - the outcome is still open), and when the heals
   run out one final report says the page is still broken. After that the
   session stays silent, instead of reporting every page view.

   Fail open everywhere: a false positive would reload-loop, which is far
   worse than the bug. Anything unexpected (no caches, sessionStorage
   unavailable, cssRules not readable) does nothing at all, and attempts
   are hard-capped. The cap lives in sessionStorage, but one Chrome
   reloaded 95 times in 22 s because its sessionStorage did not survive
   the reload - so every reload of ours also marks the history entry, and
   a page that finds that mark with no attempt on record never reloads. */
(function () {
    var MAX_HEALS = 2;
    var MAX_REPORTS = 3;

    function read(key) {
        try { return window.sessionStorage.getItem(key); } catch (e) { return undefined; }
    }

    function write(key, value) {
        try { window.sessionStorage.setItem(key, value); return true; } catch (e) { return false; }
    }

    /* Read once, before this page load changes anything - a second broken
       file on the same page must not look like a retry of the first. */
    var storedHeals = read('msp-asset-heal');
    var heals = storedHeals === undefined ? null : (parseInt(storedHeals || '0', 10) || 0);
    var reportedBefore = read('msp-asset-reported') === '1';
    var reloadedByUs = false;
    try { reloadedByUs = !!(window.history.state && window.history.state.mspAssetHeal); } catch (e) {}

    var mayHeal = heals !== null && heals < MAX_HEALS && !!window.caches && !!window.fetch
        && !(reloadedByUs && heals === 0);

    var failures = [];
    var seen = {};
    var sent = 0;
    var healing = false;

    function verdicts() {
        try { return JSON.parse(read('msp-asset-refetch') || '{}') || {}; } catch (e) { return {}; }
    }

    function send(failure, isHealing, verdict) {
        if (failure.sent || sent >= MAX_REPORTS || !navigator.sendBeacon) return;
        failure.sent = true;
        sent++;

        var meta = document.querySelector('meta[name="msp-rendered-at"]');
        try {
            navigator.sendBeacon('__ASSET_LOAD_FAILURE_URL__', JSON.stringify({
                v: 2,
                url: failure.url,
                page: window.location.pathname,
                controlled: !!(navigator.serviceWorker && navigator.serviceWorker.controller),
                retry: heals !== null && heals > 0,
                healing: isHealing,
                refetch: verdict || '',
                rendered: meta ? parseInt(meta.getAttribute('content'), 10) || 0 : 0,
                webdriver: navigator.webdriver === true
            }));
        } catch (e) {}
    }

    /* What the network delivers for this URL right now: 'intact' or
       'corrupt' against the SRI hash, 'fetched' when it cannot be checked,
       'http-<status>', 'unreachable', or 'timeout' - a stalled mobile
       connection must not hold the report and the reload back for minutes. */
    function refetch(failure) {
        return Promise.race([download(failure), new Promise(function (resolve) {
            window.setTimeout(function () { resolve('timeout'); }, 10000);
        })]);
    }

    function download(failure) {
        return window.fetch(failure.url, { cache: 'reload', credentials: 'same-origin' }).then(function (response) {
            var subtle = window.crypto && window.crypto.subtle;
            if (!response.ok) return 'http-' + response.status;
            if (!subtle || !window.btoa || !window.Uint8Array || failure.integrity.indexOf('sha384-') !== 0) return 'fetched';

            return response.arrayBuffer().then(function (body) {
                return subtle.digest('SHA-384', body);
            }).then(function (digest) {
                var bytes = new Uint8Array(digest);
                var binary = '';
                for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
                return failure.integrity.split(/\s+/).indexOf('sha384-' + window.btoa(binary)) === -1 ? 'corrupt' : 'intact';
            });
        }, function () {
            return 'unreachable';
        }).then(null, function () {
            return 'fetched';
        });
    }

    function reload() {
        /* The loop guard that does not depend on sessionStorage. Turbo
           keeps its own data in history.state, so merge rather than replace. */
        try {
            var state = window.history.state;
            var marked = {};
            if (state && typeof state === 'object') {
                for (var key in state) {
                    if (Object.prototype.hasOwnProperty.call(state, key)) marked[key] = state[key];
                }
            }
            marked.mspAssetHeal = heals + 1;
            window.history.replaceState(marked, '');
        } catch (e) {
            return;
        }
        window.location.reload();
    }

    /* One heal per page load: every failing asset fires its own error event
       and the sweep can fire as well, but one purge covers every /build
       entry. Failures that arrive while it runs join the same report. */
    function heal() {
        if (healing) return;
        healing = true;

        if (!write('msp-asset-heal', String(heals + 1))) {
            mayHeal = false;
            for (var i = 0; i < failures.length; i++) send(failures[i], false, '');
            return;
        }

        window.caches.keys().then(function (cacheNames) {
            return Promise.all(cacheNames.map(function (cacheName) {
                return window.caches.open(cacheName).then(function (cache) {
                    return cache.keys().then(function (requests) {
                        return Promise.all(requests.filter(function (cachedRequest) {
                            return cachedRequest.url.indexOf('/build/') !== -1;
                        }).map(function (cachedRequest) {
                            return cache.delete(cachedRequest);
                        }));
                    });
                });
            }));
        }).then(null, function () {}).then(function () {
            var batch = failures.slice(0, MAX_REPORTS);

            return Promise.all(batch.map(refetch)).then(function (results) {
                var stored = verdicts();
                for (var i = 0; i < batch.length; i++) {
                    stored[batch[i].url] = results[i];
                    send(batch[i], true, results[i]);
                }
                write('msp-asset-refetch', JSON.stringify(stored));
            });
        }).then(null, function () {}).then(function () {
            for (var i = 0; i < failures.length; i++) send(failures[i], true, '');
            reload();
        });
    }

    function fail(assetUrl, integrity) {
        if (!assetUrl || assetUrl.indexOf('/build/') === -1 || seen[assetUrl]) return;
        seen[assetUrl] = true;

        var failure = { url: assetUrl, integrity: integrity || '', sent: false };
        failures.push(failure);

        if (mayHeal) {
            heal();
        } else if (!reportedBefore) {
            /* Out of heals: say once that the page stays broken, with what
               the last refetch of this file found, then go quiet. */
            send(failure, false, verdicts()[assetUrl]);
            write('msp-asset-reported', '1');
        }
    }

    window.addEventListener('error', function (event) {
        var element = event.target;
        if (!element || !element.tagName) return;
        if (element.tagName !== 'SCRIPT' && element.tagName !== 'LINK') return;
        fail(element.src || element.href || '', element.getAttribute('integrity'));
    }, true);

    /* link.sheet is null when a stylesheet was never applied - failed to
       load, or silently rejected by SRI. A sheet that loaded but is
       unusable (wrong MIME type, empty 200) applies with zero rules.
       Read straight off the elements rather than asking Encore which
       files it emitted: encore_entry_css_files() marks them as returned
       and encore_entry_link_tags() would then render nothing at all.
       On load, not DOMContentLoaded: whether the latter waits for a
       pending stylesheet depends on script-blocking rules, and a sweep
       that runs too early would call a healthy page broken and reload it.
       Healing a few hundred ms later costs a visitor who is already
       looking at a broken page nothing. */
    window.addEventListener('load', function () {
        var links = document.querySelectorAll('link[rel="stylesheet"]');

        for (var i = 0; i < links.length; i++) {
            var href = links[i].href || '';
            if (href.indexOf('/build/') === -1) continue;

            var sheet;
            try { sheet = links[i].sheet; } catch (e) { continue; }
            if (sheet === null || sheet === undefined) { fail(href, links[i].getAttribute('integrity')); continue; }

            /* Unreadable rules mean opaque, not broken - leave it alone. */
            try { if (sheet.cssRules.length === 0) fail(href, links[i].getAttribute('integrity')); } catch (e) {}
        }
    });
})();
