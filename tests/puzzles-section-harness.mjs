// Runs assets/puzzles_section.js (the Puzzles place opens the section used last, docs/features/navigation PUZ-01)
// through the scenarios tests/PuzzlesSectionScriptTest.php asks for and prints one JSON object of results. Storage is a
// Map-backed fake (or one that throws, like blocked site data); the document is a fake with the two queries the module
// makes: <main data-puzzles-section> and the Puzzles place links.

import {
    applyPuzzlesSection,
    installPuzzlesSection,
    localStorageOf,
    parseSections,
    PUZZLES_SECTION_KEY,
    readSection,
    recordSection,
    targetHref,
} from '../assets/puzzles_section.js';

function storage() {
    const items = new Map();

    return {
        items,
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => items.set(key, String(value)),
        removeItem: (key) => items.delete(key),
    };
}

function throwingStorage() {
    const fail = () => {
        throw new Error('SecurityError: storage blocked');
    };

    return { getItem: fail, setItem: fail, removeItem: fail };
}

function link(href, sections) {
    const attributes = { href, 'data-nav-place': 'puzzles', 'data-puzzles-sections': sections === null ? null : JSON.stringify(sections) };

    return {
        getAttribute: (name) => (name in attributes ? attributes[name] : null),
        setAttribute: (name, value) => {
            attributes[name] = String(value);
        },
        href: () => attributes.href,
    };
}

function doc(section, links, readyState = 'complete') {
    const listeners = {};
    const main = section === null ? null : { getAttribute: (name) => (name === 'data-puzzles-section' ? section : null) };

    return {
        readyState,
        querySelector: (selector) => (selector === 'main[data-puzzles-section]' ? main : null),
        querySelectorAll: (selector) => (selector === 'a[data-nav-place="puzzles"][data-puzzles-sections]' ? links.filter((l) => l.getAttribute('data-puzzles-sections') !== null) : []),
        addEventListener: (type, listener) => {
            (listeners[type] = listeners[type] || []).push(listener);
        },
        fire: (type) => (listeners[type] || []).forEach((listener) => listener()),
    };
}

const MEMBER = { library: '/en/puzzle-library/018d0000-0000-0000-0000-000000000001', all: '/en/puzzle', marketplace: '/en/marketplace', next: '/en/what-to-solve-next' };
const GUEST = { all: '/en/puzzle', marketplace: '/en/marketplace', next: '/en/what-to-solve-next' };
const results = {};

results.key = PUZZLES_SECTION_KEY;

// Recording: the four sections, nothing else
{
    const s = storage();
    const recorded = {};

    for (const value of ['library', 'all', 'marketplace', 'next', 'puzzles', '', null, 'Marketplace']) {
        recorded[String(value)] = recordSection(s, value);
    }

    results.recording = { recorded, kept: s.items.get(PUZZLES_SECTION_KEY) };
}

// Nothing remembered: the default - My library for players, All puzzles for guests (the server's own hrefs: no change)
{
    const s = storage();
    const member = link(MEMBER.library, MEMBER);
    // The docked Menu's place row links the catalogue on the server; it follows the tab's default
    const dockRow = link('/en/puzzle', MEMBER);
    const guest = link(GUEST.all, GUEST);
    const changedMember = applyPuzzlesSection(doc(null, [member, dockRow]), s);
    const changedGuest = applyPuzzlesSection(doc(null, [guest]), s);

    results.defaults = { member: member.href(), dockRow: dockRow.href(), guest: guest.href(), changedMember, changedGuest, stored: s.items.has(PUZZLES_SECTION_KEY) };
}

// Visit the marketplace, then a page of no section (the Hub): every place link opens the marketplace
{
    const s = storage();
    applyPuzzlesSection(doc('marketplace', [link(MEMBER.library, MEMBER)]), s);
    const tab = link(MEMBER.library, MEMBER);
    const compact = link(MEMBER.library, MEMBER);
    const dockRow = link('/en/puzzle', MEMBER);
    const changed = applyPuzzlesSection(doc(null, [tab, compact, dockRow]), s);
    const again = applyPuzzlesSection(doc(null, [tab, compact, dockRow]), s);

    results.remembered = { tab: tab.href(), compact: compact.href(), dockRow: dockRow.href(), changed, again, read: readSection(s) };
}

// A guest ignores "library" (remembered while signed in, the guest's links have none): All puzzles
{
    const s = storage();
    recordSection(s, 'library');
    const guest = link(GUEST.all, GUEST);
    applyPuzzlesSection(doc(null, [guest]), s);
    results.guestIgnoresLibrary = { href: guest.href(), target: targetHref(GUEST, 'library') };
}

// The page's own section is recorded before the links follow: on What next? the links already lead there
{
    const s = storage();
    recordSection(s, 'all');
    const tab = link(GUEST.all, GUEST);
    applyPuzzlesSection(doc('next', [tab]), s);
    results.ownPage = { href: tab.href(), read: readSection(s) };
}

// Storage that throws, or none at all: no exception, the links keep the server's default
{
    const outcome = {};

    for (const [name, store] of [['throwing', throwingStorage()], ['missing', null]]) {
        const tab = link(MEMBER.library, MEMBER);
        let threw = false;

        try {
            applyPuzzlesSection(doc('marketplace', [tab]), store);
        } catch (e) {
            threw = true;
        }

        outcome[name] = { threw, href: tab.href(), read: readSection(store), recorded: recordSection(store, 'all') };
    }

    outcome.windowWithout = localStorageOf({});
    outcome.windowBlocked = localStorageOf(Object.defineProperty({}, 'localStorage', { get: () => { throw new Error('blocked'); } }));
    outcome.noWindow = localStorageOf(null);
    results.blockedStorage = outcome;
}

// A broken or hostile attribute: unknown sections, non-strings and other sites are dropped; broken JSON = no change
{
    const parsed = parseSections(JSON.stringify({ all: '/en/puzzle', marketplace: '//evil.example/x', next: 'https://evil.example', library: 5, extra: '/en/x' }));
    const broken = link('/en/puzzle', null);
    broken.setAttribute('data-puzzles-sections', '{not json');
    const s = storage();
    recordSection(s, 'marketplace');
    const changed = applyPuzzlesSection(doc(null, [broken]), s);
    results.hostile = { parsed, brokenParsed: parseSections('{not json'), nullParsed: parseSections(null), changed, href: broken.href() };
}

// An unknown stored value is no section: the default
{
    const s = storage();
    s.setItem(PUZZLES_SECTION_KEY, 'brands');
    const tab = link(MEMBER.library, MEMBER);
    applyPuzzlesSection(doc(null, [tab]), s);
    results.unknownStored = { read: readSection(s), href: tab.href() };
}

// install: on every turbo:load, and at once when the page is already parsed
{
    const win = { localStorage: storage() };
    win.localStorage.setItem(PUZZLES_SECTION_KEY, 'next');
    const parsedTab = link(MEMBER.library, MEMBER);
    const parsedDoc = doc(null, [parsedTab], 'complete');
    installPuzzlesSection({ window: win, document: parsedDoc });
    const atOnce = parsedTab.href();

    const loadingTab = link(MEMBER.library, MEMBER);
    const loadingDoc = doc(null, [loadingTab], 'loading');
    installPuzzlesSection({ window: win, document: loadingDoc });
    const beforeLoad = loadingTab.href();
    loadingDoc.fire('turbo:load');

    results.install = { atOnce, beforeLoad, afterLoad: loadingTab.href() };
}

process.stdout.write(JSON.stringify(results));
