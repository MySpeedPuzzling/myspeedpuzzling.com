// A Turbo visit lands at once (docs/features/navigation NAV-07).
//
// Turbo puts the new page at its top - or at its #anchor, or at a restored position - with window.scrollTo() (or the
// anchor's scrollIntoView()) right after it dispatched turbo:render, in the same task. app.scss sets
// `html { scroll-behavior: smooth }` for in-page jumps ("Jump to me", #anchors on a page), which turns that scroll into a
// glide from the previous page's offset: the new page is painted part-way down first and slides up, the phone top bar
// reacting to the scroll on the way. From turbo:before-render until Turbo has scrolled (the frame after turbo:render,
// and turbo:load, whichever comes first) <html> scrolls instantly - one inline property, removed again; every other
// scroll keeps the stylesheet's behaviour. A morph (a page refresh keeping its scroll position) is left alone.
//
// Platform-neutral (Turbo scrolls the same way inside the Hotwire Native app). Imported by app.js; the listeners are
// installed once; tests/turbo-contract-harness.mjs runs it against the real Turbo dist.

export const PROPERTY = 'scroll-behavior';
export const INSTANT = 'auto';

/** Installs the listeners on `document` (once per window); returns false when they were already there */
export function installVisitScroll(window, document) {
    if (window.__mspVisitScroll) {
        return false;
    }

    window.__mspVisitScroll = true;
    let holding = false;

    const release = () => {
        if (holding) {
            holding = false;
            document.documentElement.style.removeProperty(PROPERTY);
        }
    };

    document.addEventListener('turbo:before-render', (event) => {
        if (event && event.detail && event.detail.renderMethod === 'morph') {
            return;
        }

        holding = true;
        document.documentElement.style.setProperty(PROPERTY, INSTANT);
    });

    // Turbo scrolls right after turbo:render, before the next frame
    document.addEventListener('turbo:render', () => {
        if (holding) {
            window.requestAnimationFrame(release);
        }
    });
    document.addEventListener('turbo:load', release);

    return true;
}

if (typeof window !== 'undefined' && typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
    installVisitScroll(window, document);
}
