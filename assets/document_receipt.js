// When this device received each page's HTML, on its own clock, keyed by the page's <meta name="msp-rendered-at">
// (server Unix seconds) - docs/features/navigation ADD-03 (review F5). Eager (imported by app.js), so the moment is
// taken when the HTML arrives, not when a lazily loaded controller connects: the running-stopwatch pill
// (stopwatch_pill_controller.js) measures the server-minus-device clock from it, and its lazy chunk's download no longer
// counts as clock difference.
//
// - a full page load: the navigation entry's first byte (performance.timeOrigin + responseStart);
// - a Turbo visit: Date.now() when the response arrived (turbo:before-fetch-response), filed under the new page's meta
//   at turbo:before-render - Turbo merges the new <head> before it dispatches that event.
// Without a receipt (a back/forward load from the cache, no Navigation Timing) the pill measures at connect, as before.

const MAX_RECEIPTS = 20;
// A response older than this when its page renders is somebody else's (a frame's or a stream's that rendered nothing)
const MAX_FETCH_TO_RENDER_MS = 60_000;

const receipts = new Map();
let fetchedAt = null;

/**
 * A full page load's receipt: the first byte of the response on this device's clock (ms). Null for a back/forward
 * load - its HTML may come from the cache long after the render - and without Navigation Timing.
 *
 * @param {null|{type?: string, responseStart?: number}} entry performance.getEntriesByType('navigation')[0]
 * @param {number} timeOrigin performance.timeOrigin
 * @returns {null|number}
 */
export function navigationReceipt(entry, timeOrigin) {
    if (!entry || entry.type === 'back_forward' || !(entry.responseStart > 0) || !Number.isFinite(timeOrigin)) {
        return null;
    }

    return timeOrigin + entry.responseStart;
}

function renderedAtOf(document) {
    const meta = document.querySelector('meta[name="msp-rendered-at"]');
    const renderedAt = meta ? parseInt(meta.getAttribute('content'), 10) : NaN;

    return Number.isFinite(renderedAt) && renderedAt > 0 ? renderedAt : null;
}

function remember(renderedAt, at) {
    if (renderedAt === null || at === null) {
        return;
    }

    receipts.delete(renderedAt);

    if (receipts.size >= MAX_RECEIPTS) {
        receipts.delete(receipts.keys().next().value);
    }

    receipts.set(renderedAt, at);
}

/**
 * The current page's receipt, or null.
 *
 * @param {Document} document
 * @returns {null|{renderedAt: number, at: number}}
 */
export function documentReceipt(document) {
    const renderedAt = renderedAtOf(document);

    return renderedAt !== null && receipts.has(renderedAt) ? { renderedAt, at: receipts.get(renderedAt) } : null;
}

export function installDocumentReceipts({ window, document }) {
    try {
        const performance = window.performance;
        const entry = performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
        remember(renderedAtOf(document), navigationReceipt(entry ?? null, performance ? performance.timeOrigin : NaN));
    } catch {
        // No receipt: the pill measures when it connects
    }

    document.addEventListener('turbo:before-fetch-response', () => {
        fetchedAt = Date.now();
    });

    // A frame's response renders into the frame, not as a page
    document.addEventListener('turbo:before-frame-render', () => {
        fetchedAt = null;
    });

    document.addEventListener('turbo:before-render', () => {
        const now = Date.now();
        remember(renderedAtOf(document), fetchedAt !== null && now - fetchedAt <= MAX_FETCH_TO_RENDER_MS ? fetchedAt : now);
        fetchedAt = null;
    });
}
