/**
 * The Puzzles place opens the section used last (docs/features/navigation README D6, PUZ-01): My library · All puzzles ·
 * Marketplace · What next?.
 *
 * - Recording: a page of a Puzzles section says which one on `<main data-puzzles-section="library|all|marketplace|next">`
 *   (base.html.twig - the four start pages and your own library's lists; object pages say nothing) and it is kept in
 *   localStorage `msp:puzzles-section`.
 * - Applying: every Puzzles place link - the tab bar / rail, the 768-1023 px top bar's places, the docked Menu's place
 *   row - carries its sections' links (`data-puzzles-sections`, server-rendered for the viewer: guests have no
 *   `library`) and gets the remembered one as its href; with nothing remembered (or a section the viewer has not, e.g.
 *   a guest after signing out) the default: My library for players, All puzzles for guests.
 *
 * Runs on every `turbo:load` (the first page load included), so the links are right before the next tap; a tap before
 * it uses the server's link (accepted, plan PUZ-01). Guests' HTML stays the same for everybody: all of this is the
 * browser's. Storage can be missing or throw (private mode, blocked site data): the links keep their defaults.
 * Plain functions over a Storage-like object and a document - tests/puzzles-section-harness.mjs runs them under node.
 */

export const PUZZLES_SECTION_KEY = 'msp:puzzles-section';

export const PUZZLES_SECTIONS = ['library', 'all', 'marketplace', 'next'];

/** window.localStorage, or null where reading it throws (blocked storage) */
export function localStorageOf(win) {
    try {
        return win && win.localStorage ? win.localStorage : null;
    } catch (e) {
        return null;
    }
}

function isSection(value) {
    return typeof value === 'string' && PUZZLES_SECTIONS.includes(value);
}

/** Keeps a valid section; anything else is ignored. True when stored */
export function recordSection(storage, section) {
    if (!isSection(section) || !storage) {
        return false;
    }

    try {
        storage.setItem(PUZZLES_SECTION_KEY, section);

        return true;
    } catch (e) {
        return false;
    }
}

/** The section used last, null when none (or an unknown value) is kept */
export function readSection(storage) {
    try {
        const value = storage ? storage.getItem(PUZZLES_SECTION_KEY) : null;

        return isSection(value) ? value : null;
    } catch (e) {
        return null;
    }
}

/** A link's data-puzzles-sections: section => same-site path, unknown sections and other values dropped */
export function parseSections(json) {
    const sections = {};

    try {
        const parsed = typeof json === 'string' ? JSON.parse(json) : null;

        if (!parsed || typeof parsed !== 'object') {
            return sections;
        }

        for (const section of PUZZLES_SECTIONS) {
            const href = parsed[section];

            if (typeof href === 'string' && href.startsWith('/') && !href.startsWith('//')) {
                sections[section] = href;
            }
        }
    } catch (e) {
        // A broken attribute leaves the link as the server rendered it
    }

    return sections;
}

/** Where the place leads: the remembered section when the viewer has it, else My library, else All puzzles */
export function targetHref(sections, remembered) {
    if (remembered && sections[remembered]) {
        return sections[remembered];
    }

    return sections.library || sections.all || null;
}

/**
 * Records this page's section, then points every Puzzles place link at the section used last. Returns the number of
 * links whose href changed.
 */
export function applyPuzzlesSection(doc, storage) {
    const main = doc.querySelector('main[data-puzzles-section]');

    if (main) {
        recordSection(storage, main.getAttribute('data-puzzles-section'));
    }

    const remembered = readSection(storage);
    let changed = 0;

    for (const link of doc.querySelectorAll('a[data-nav-place="puzzles"][data-puzzles-sections]')) {
        const href = targetHref(parseSections(link.getAttribute('data-puzzles-sections')), remembered);

        if (href && link.getAttribute('href') !== href) {
            link.setAttribute('href', href);
            changed += 1;
        }
    }

    return changed;
}

/**
 * app.js: on every Turbo load (the first page load included) - and at once when the page has already been parsed (the
 * bundle came after Turbo's first turbo:load); applying twice changes nothing
 */
export function installPuzzlesSection({ window: win, document: doc }) {
    const apply = () => applyPuzzlesSection(doc, localStorageOf(win));

    doc.addEventListener('turbo:load', apply);

    if (doc.readyState !== 'loading') {
        apply();
    }
}
