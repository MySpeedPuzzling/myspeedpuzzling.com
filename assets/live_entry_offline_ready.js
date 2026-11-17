/**
 * "Ready offline" (competition toolkit C6, docs/features/competitions-management/toolkit/C-judge-page.md §1.4): the
 * online live entry page asks its service worker to store the page's offline copy (`live_results_shell` of its locale)
 * with every file it needs - and the QR decoder when this browser reads no QR codes itself - and learns when it is
 * stored. Only referees' and organisers' devices ever ask (only they get the page's state). Pure apart from what is
 * handed in - run by tests/live-entry-shell-harness.mjs.
 */

// The worker downloads the page's files (≈ 0.5 MB on a first visit) - a slow venue network gets this long
export const PREPARE_TIMEOUT_MS = 60000;

/**
 * @param {object} options
 * @param {ServiceWorkerContainer|null|undefined} options.serviceWorker navigator.serviceWorker
 * @param {string} options.locale the page's locale
 * @param {boolean} options.scanner true = store the QR decoder too
 * @returns {Promise<{storedAt: number}|null>} null = not stored (no worker, refused, timed out)
 */
export function prepareOfflineCopy({ serviceWorker, locale, scanner = false, timeoutMs = PREPARE_TIMEOUT_MS, setTimer = setTimeout, clearTimer = clearTimeout }) {
    if (!serviceWorker || !serviceWorker.ready || typeof serviceWorker.ready.then !== 'function') {
        return Promise.resolve(null);
    }

    return Promise.resolve(serviceWorker.ready).then((registration) => new Promise((resolve) => {
        const worker = serviceWorker.controller ?? registration?.active ?? null;

        if (worker === null || typeof worker.postMessage !== 'function') {
            resolve(null);

            return;
        }

        let timer = null;
        const finish = (value) => {
            clearTimer(timer);
            serviceWorker.removeEventListener('message', onMessage);
            resolve(value);
        };
        const onMessage = (event) => {
            const data = event?.data;

            if (data === null || typeof data !== 'object' || data.locale !== locale) {
                return;
            }

            if (data.type === 'live-shell:ready') {
                finish({ storedAt: Number(data.storedAt) || Date.now() });
            } else if (data.type === 'live-shell:failed') {
                finish(null);
            }
        };

        serviceWorker.addEventListener('message', onMessage);
        timer = setTimer(() => finish(null), timeoutMs);
        worker.postMessage({ type: 'live-shell:prepare', locale, scanner: scanner === true });
    })).catch(() => null);
}
