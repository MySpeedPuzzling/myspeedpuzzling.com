/**
 * `document.fonts.ready`, asked only while the fonts may still change a measure (GEST-15 (e)).
 *
 * Reading the property once the fonts have settled makes Blink update the page's style and layout on the spot (a pending
 * style change could still start a font load) - so a controller that reads it in connect() laid the whole page out in
 * the middle of every Turbo render: on a Back restoring a long list from Turbo's snapshot that was a full extra pass.
 * A document's fonts settle once (font-display: optional - within 100 ms or never), so after the first `ready` nothing
 * waits for them again; a later page of the same document measures in its connect() and its ResizeObserver as before.
 */
let settled = false;

/** Calls `callback` when the fonts are ready - unless they already were (then false: the caller has measured already) */
export function whenFontsReady(callback, doc = document) {
    const fonts = doc ? doc.fonts : null;

    if (settled || !fonts) {
        return false;
    }

    // Read once: every read counts
    const ready = fonts.ready;

    if (!ready || typeof ready.then !== 'function') {
        return false;
    }

    ready.then(
        () => {
            settled = true;
            callback();
        },
        () => {},
    );

    return true;
}

/** Tests only: a new document */
export function forgetFontsSettled() {
    settled = false;
}
