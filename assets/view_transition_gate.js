import { isNativeApp, os } from './platform.js';

// Which Turbo visits run as a view transition, and how they look (docs/features/navigation README §4.13 G12, G15;
// requirements GEST-12, GEST-17; decisions 63, 68).
//
// Turbo (pinned 8.0.23) wraps a visit's render in document.startViewTransition() when the current page's head and the
// new page's head both carry <meta name="view-transition" content="same-origin"> - it reads the live meta right before
// it renders (tests/TurboContractScriptTest.php pins that). The server always renders the meta (base.html.twig); this
// gate sets it per visit:
// - G12 place switches: an Android phone's browser keeps `same-origin` - a tap on a place link (the tab bar, the top
//   bar's places, the Menu's place rows) fades through (html[data-vt="place"], assets/styles/_view-transitions.scss:
//   old out in 90 ms, new in over 210 ms after 90 ms with a .92 → 1 scale), the bars, the rail and the docked column
//   stand still; every other visit there runs a view transition without any animation (an instant switch). iOS and
//   desktop get `none`: instant switches, no view transition at all.
// - G15 the thumbnail morph, on both phones: a tap on a catalogue, library or search card ([data-morph-source] = the
//   puzzle page's path) whose thumbnail is decoded names it `puzzle-hero` and sets `same-origin` for this visit (iOS
//   too); the new page's box image (img.puzzle-hero) gets the same name right before it renders, so the browser morphs
//   one into the other (html[data-vt="morph"]: the group in 300 ms, the page cross-fades in 150 ms). When the page
//   answers more than 400 ms after the tap the morph is dropped at turbo:before-fetch-response - Turbo decides the view
//   transition before turbo:before-render (decision 68) - and the visit goes on as any other.
// - Never on Back: a restore visit, and a popstate the browser animated itself (hasUAVisualTransition), set `none`.
// - Never with reduced motion (Turbo itself checks it as well), never inside the Hotwire Native app (README §4.14 (b):
//   the app animates its own screens).
// turbo:load puts the meta back to the device's default, clears data-vt and every inline view-transition-name (a
// snapshot or a cancelled visit never keeps one - a second `puzzle-hero` would abort the next transition).
//
// The decisions are pure (gateFor(), morphCandidate(), morphInTime()) and node-tested in
// tests/view-transition-gate-harness.mjs, install() too, against a fake document.

export const META_NAME = 'view-transition';
export const SAME_ORIGIN = 'same-origin';
export const NONE = 'none';

/** On <html> while a visit's transition is armed: `place` (G12) or `morph` (G15) */
export const VT_ATTRIBUTE = 'data-vt';
export const PLACE = 'place';
export const MORPH = 'morph';

/** The shared name of the tapped thumbnail and the puzzle page's box image */
export const HERO_NAME = 'puzzle-hero';
export const HERO_SELECTOR = 'img.puzzle-hero';

/** A card that may morph into its puzzle page: the attribute holds that page's path */
export const MORPH_SOURCE_ATTRIBUTE = 'data-morph-source';
export const MORPH_THUMBNAIL_SELECTOR = 'img.lazy-img, img';

/** The page has to answer within this many ms of the tap, or the morph is dropped (README §4.13 G15) */
export const MORPH_MAX_WAIT_MS = 400;

/**
 * Place links: the tab bar's tabs, the top bar's places and the rail (data-nav-place), the Menu's place rows (mr--place
 * - the place itself is read from data-nav there, GEST review Q3)
 */
export const PLACE_LINK_SELECTOR = 'a[data-nav-place], a.mr--place';

const PHONES = ['ios', 'android'];

/**
 * The meta's content and the <html data-vt> value for a visit.
 *
 * facts: {os, nativeApp, reducedMotion, restore, place, morph}
 * - `place`: the visit comes from a tap on a place link;
 * - `morph`: the tap was on a card whose thumbnail may morph (morphCandidate()) and the page is still in time
 *   (morphInTime());
 * - `restore`: a Back / Forward (a restore visit, or a popstate the browser animated itself).
 * Returns {meta: 'same-origin' | 'none', vt: 'place' | 'morph' | null}. Inside the app, on Back and with reduced motion
 * always {meta: 'none', vt: null}.
 */
export function gateFor(facts = {}) {
    const { os: device, nativeApp, reducedMotion, restore, place, morph } = facts || {};

    if (nativeApp || restore || reducedMotion) {
        return { meta: NONE, vt: null };
    }

    if (morph === true && PHONES.includes(device)) {
        return { meta: SAME_ORIGIN, vt: MORPH };
    }

    if (device === 'android') {
        return { meta: SAME_ORIGIN, vt: place === true ? PLACE : null };
    }

    return { meta: NONE, vt: null };
}

/**
 * May this tap morph? The link leads to the card's own puzzle page (a brand link inside the card does not) and the
 * card's thumbnail is decoded (an image still loading would morph from nothing).
 */
export function morphCandidate({ linkPath, sourcePath, imageReady } = {}) {
    return typeof sourcePath === 'string' && sourcePath !== '' && linkPath === sourcePath && imageReady === true;
}

/** Is the answer still in time for the morph (≤ 400 ms after the tap)? */
export function morphInTime(tappedAt, now) {
    return typeof tappedAt === 'number' && typeof now === 'number' && now - tappedAt <= MORPH_MAX_WAIT_MS;
}

/** The path of a URL (relative to `base`), null for anything that is no URL */
export function pathOf(url, base) {
    try {
        return new URL(String(url), base).pathname;
    } catch (e) {
        return null;
    }
}

function withoutHash(url, base) {
    try {
        const parsed = new URL(String(url), base);
        parsed.hash = '';

        return parsed.href;
    } catch (e) {
        return null;
    }
}

// ---- The DOM part (app.js) ------------------------------------------------------------------------------------------

/**
 * Wires the gate to Turbo's events. `now` is injected for the node test (performance.now in the browser).
 */
export function install({
    window: win = typeof window !== 'undefined' ? window : null,
    document: doc = typeof document !== 'undefined' ? document : null,
    now = () => (win && win.performance ? win.performance.now() : Date.now()),
} = {}) {
    if (!win || !doc || doc.__mspViewTransitionGate) {
        return;
    }

    doc.__mspViewTransitionGate = true;

    const root = () => doc.documentElement;
    const reducedMotion = typeof win.matchMedia === 'function' ? win.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const facts = (extra = {}) => ({
        os: os(root()),
        nativeApp: isNativeApp(root()),
        reducedMotion: reducedMotion ? reducedMotion.matches : false,
        ...extra,
    });

    // The armed visit: {vt, url, at}
    let armed = null;

    const setMeta = (content) => {
        const meta = doc.head ? doc.head.querySelector(`meta[name="${META_NAME}"]`) : null;

        if (meta && meta.getAttribute('content') !== content) {
            meta.setAttribute('content', content);
        }
    };

    const setVt = (vt) => {
        if (vt === null) {
            root().removeAttribute(VT_ATTRIBUTE);
        } else {
            root().setAttribute(VT_ATTRIBUTE, vt);
        }
    };

    const clearNames = () => {
        doc.querySelectorAll('[style*="view-transition-name"]').forEach((element) => element.style.removeProperty('view-transition-name'));
    };

    // Back to the device's default: nothing armed, no name left on the page
    const reset = (meta = gateFor(facts()).meta) => {
        armed = null;
        setVt(null);
        clearNames();
        setMeta(meta);
    };

    reset();

    doc.addEventListener('turbo:load', () => reset());

    doc.addEventListener('turbo:click', (event) => {
        const link = event.target;
        const url = event.detail && event.detail.url ? event.detail.url : null;

        // A tap that changes its mind (a cancelled visit) never leaves its name behind
        clearNames();

        if (event.defaultPrevented || !link || typeof link.closest !== 'function' || url === null) {
            reset();

            return;
        }

        const card = link.closest(`[${MORPH_SOURCE_ATTRIBUTE}]`);
        const thumbnail = card ? card.querySelector(MORPH_THUMBNAIL_SELECTOR) : null;
        const morph = card !== null && morphCandidate({
            linkPath: pathOf(url, win.location.href),
            sourcePath: card.getAttribute(MORPH_SOURCE_ATTRIBUTE),
            imageReady: thumbnail !== null && thumbnail.complete === true && thumbnail.naturalWidth > 0,
        });
        const decision = gateFor(facts({ place: link.matches(PLACE_LINK_SELECTOR), morph }));

        if (decision.vt === MORPH) {
            thumbnail.style.setProperty('view-transition-name', HERO_NAME);
        }

        setVt(decision.vt);
        setMeta(decision.meta);
        armed = decision.vt === null ? null : { vt: decision.vt, url: withoutHash(url, win.location.href), at: now() };
    });

    doc.addEventListener('turbo:visit', (event) => {
        const detail = event.detail || {};

        // Back / Forward is never animated by MSP (README §4.13: Back never animated)
        if (detail.action === 'restore') {
            reset(NONE);

            return;
        }

        // Another visit than the tapped link's (a guard stopped that one, a script visits elsewhere): nothing of the tap
        if (armed !== null && withoutHash(detail.url, win.location.href) !== armed.url) {
            reset();
        }
    });

    // A form submission is no place switch and no morph
    doc.addEventListener('turbo:submit-start', () => {
        if (armed !== null) {
            reset();
        }
    });

    // The browser animated this Back itself (Chrome's back-gesture preview, Safari's swipe): nothing on top of it
    win.addEventListener('popstate', (event) => {
        if (event && event.hasUAVisualTransition === true) {
            reset(NONE);
        }
    });

    // Decision 68: the slow-answer skip - Turbo decides the view transition before turbo:before-render. Only the page
    // visit's own fetch (dispatched on <html>; a frame's fetch is dispatched on the frame)
    doc.addEventListener('turbo:before-fetch-response', (event) => {
        if (armed === null || armed.vt !== MORPH || event.target !== root()) {
            return;
        }

        if (!morphInTime(armed.at, now())) {
            reset();
        }
    });

    // The new page's box image takes the thumbnail's name - only the new page's (an old page holding a box image of its
    // own never has two `puzzle-hero`s)
    doc.addEventListener('turbo:before-render', (event) => {
        if (armed === null || armed.vt !== MORPH) {
            return;
        }

        const body = event.detail ? event.detail.newBody : null;
        const hero = body && typeof body.querySelector === 'function' ? body.querySelector(HERO_SELECTOR) : null;

        if (hero) {
            hero.style.setProperty('view-transition-name', HERO_NAME);
        }
    });
}
