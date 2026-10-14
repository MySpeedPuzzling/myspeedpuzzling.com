// Runs assets/tab_reselect.js (tap the active tab, docs/features/navigation GEST-03) through the cases
// tests/TabReselectScriptTest.php asks for and prints one JSON object {case: decision}.
// Paths are made up; a signed-in player's tab bar: Home /en/hub, Puzzles /en/my-library/p1 (or the section used last),
// Add /en/puzzle-add, Community /en/community, Profile /en/player-profile/p1.

import {
    CLOSE_LAYER,
    decide,
    decideFor,
    FEED_REFRESH_EVENT,
    isStartPage,
    NAVIGATE,
    NONE,
    normalizePath,
    placeOfNav,
    REFRESH_FEED,
    SCROLL_TOP,
    scrollBehavior,
} from '../assets/tab_reselect.js';

const HUB = { tabPlace: 'home', currentPlace: 'home', tabPath: '/en/hub', currentPath: '/en/hub' };
const ADD = { tabPlace: 'add', currentPlace: 'add', tabPath: '/en/puzzle-add', currentPath: '/en/puzzle-add' };

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

/** A fake <html> with the platform flags (assets/platform.js reads getAttribute) */
function root(nativeApp) {
    return { getAttribute: (name) => (name === 'data-native-app' && nativeApp ? 'android' : null), classList: { contains: () => false } };
}

const results = Object.fromEntries([
    run('constants', () => ({ CLOSE_LAYER, NAVIGATE, SCROLL_TOP, REFRESH_FEED, NONE, FEED_REFRESH_EVENT })),

    // 1. A layer holds the history entry (GEST review Q1: any layer, the Menu panel one of them)
    run('Menu open, the current place\'s tab → close the Menu', () => decide({ ...HUB, layer: 'menu', scrollY: 800 })),
    run('Menu open, the current place\'s tab on a deeper page → still only closes', () => decide({ ...HUB, currentPath: '/en/recent-activity', layer: 'menu' })),
    run('Menu open, another tab → navigate (a replace visit)', () => decide({ ...HUB, tabPlace: 'community', tabPath: '/en/community', layer: 'menu' })),
    run('the player card open on the scrolled Hub, Home → close the card (no glide behind it)', () => decide({ ...HUB, layer: 'player-card', scrollY: 800 })),
    run('a sheet open at the top of the Hub, Home → close the sheet (no feed refresh behind it)', () => decide({ ...HUB, layer: 'modal', scrollY: 0 })),
    run('the search view open on the add form, Add → close the search view', () => decide({ ...ADD, layer: 'search', scrollY: 0 })),
    run('the player card open, another tab → navigate (a replace visit)', () => decide({ ...HUB, tabPlace: 'community', tabPath: '/en/community', layer: 'player-card' })),
    run('no layer (null or empty) → the page rules', () => [decide({ ...HUB, layer: null, scrollY: 800 }), decide({ ...HUB, layer: '', scrollY: 800 })]),

    // 2. Another place
    run('another place\'s tab → navigate', () => decide({ ...HUB, tabPlace: 'puzzles', tabPath: '/en/my-library/p1', scrollY: 800 })),
    run('a page of no place (FAQ), Home → navigate', () => decide({ ...HUB, currentPlace: 'none', currentPath: '/en/faq' })),
    run('no current place at all → navigate', () => decide({ ...HUB, currentPlace: null })),

    // 3. Same place, deeper page → the start page (the tab's own link)
    run('puzzle page scrolled 800, Puzzles → navigate to the start page', () => decide({ tabPlace: 'puzzles', currentPlace: 'puzzles', tabPath: '/en/my-library/p1', currentPath: '/en/puzzle/x1', scrollY: 800 })),
    run('own statistics page, Profile → navigate to the profile', () => decide({ tabPlace: 'profile', currentPlace: 'profile', tabPath: '/en/player-profile/p1', currentPath: '/en/player-statistics/p1', scrollY: 0 })),
    run('the recap after adding a time, Add → navigate to the add form', () => decide({ ...ADD, currentPath: '/en/added-time-recap/t1', scrollY: 300 })),

    // 4. The start page scrolled → to the top
    run('Hub scrolled 800, Home → scroll to the top, smooth', () => decide({ ...HUB, scrollY: 800 })),
    run('Hub scrolled 800 with reduced motion → scroll to the top, instant', () => decide({ ...HUB, scrollY: 800, reducedMotion: true })),
    run('a Puzzles section root scrolled (the section used last) → scroll to the top', () => decide({ tabPlace: 'puzzles', currentPlace: 'puzzles', tabPath: '/en/puzzle', currentPath: '/en/puzzle', scrollY: 1 })),
    run('the add form scrolled → scroll to the top', () => decide({ ...ADD, scrollY: 400 })),
    run('a start page with a trailing slash scrolled → scroll to the top', () => decide({ tabPlace: 'community', currentPlace: 'community', tabPath: '/en/community', currentPath: '/en/community/', scrollY: 250 })),

    // 5. At the top
    run('top of the Hub, Home → refresh the feed', () => decide({ ...HUB, scrollY: 0 })),
    run('top of the guests\' homepage "/", Home → refresh the feed', () => decide({ tabPlace: 'home', currentPlace: 'home', tabPath: '/', currentPath: '/', scrollY: 0 })),
    run('iOS rubber band above the top (-40) counts as the top', () => decide({ ...HUB, scrollY: -40 })),
    run('top of the add form, Add → nothing (never reloads)', () => decide({ ...ADD, scrollY: 0 })),
    run('top of the add form with a chosen puzzle, Add → nothing', () => decide({ ...ADD, currentPath: '/en/puzzle-add/x1', scrollY: 0 })),
    run('the add form with a chosen puzzle scrolled → scroll to the top', () => decide({ ...ADD, currentPath: '/en/puzzle-add/x1', scrollY: 600 })),
    run('top of Community, Community → navigate (same URL)', () => decide({ tabPlace: 'community', currentPlace: 'community', tabPath: '/en/community', currentPath: '/en/community', scrollY: 0 })),
    run('top of the own profile, Profile → navigate (same URL)', () => decide({ tabPlace: 'profile', currentPlace: 'profile', tabPath: '/en/player-profile/p1', currentPath: '/en/player-profile/p1', scrollY: 0 })),

    // The server's start page (GEST review F4): a Puzzles section root whose tab still links the default (storage blocked)
    run('Marketplace scrolled, the Puzzles tab still on My library, the server says start page → scroll to the top', () => decide({ tabPlace: 'puzzles', currentPlace: 'puzzles', tabPath: '/en/my-library/p1', currentPath: '/en/marketplace', startPage: true, scrollY: 800 })),
    run('the same without the server\'s word → navigate', () => decide({ tabPlace: 'puzzles', currentPlace: 'puzzles', tabPath: '/en/my-library/p1', currentPath: '/en/marketplace', startPage: false, scrollY: 800 })),

    // A page showing the solving-time form is Add's start page (GEST review F3): the stopwatch's save form, the edit form
    run('the stopwatch\'s save form at the top, Add → nothing', () => decide({ ...ADD, currentPath: '/en/save-stopwatch/s1', formShown: true, scrollY: 0 })),
    run('the stopwatch\'s save form scrolled, Add → scroll to the top', () => decide({ ...ADD, currentPath: '/en/save-stopwatch/s1', formShown: true, scrollY: 500 })),
    run('the stopwatch\'s save form without the form fact, Add → navigate', () => decide({ ...ADD, currentPath: '/en/save-stopwatch/s1', scrollY: 0 })),
    run('the edit form at the top, Add → nothing', () => decide({ ...ADD, currentPath: '/en/edit-time/t1', formShown: true, scrollY: 0 })),
    run('the form fact counts for Add only', () => decide({ tabPlace: 'community', currentPlace: 'community', tabPath: '/en/community', currentPath: '/en/events', formShown: true, scrollY: 0 })),

    // Start pages: the tab's href path (D-5); Add also its form with a chosen puzzle - nothing that only starts alike
    run('isStartPage', () => ({
        same: isStartPage('home', '/en/hub', '/en/hub'),
        trailingSlash: isStartPage('community', '/en/community', '/en/community/'),
        prefixIsNotAPage: isStartPage('home', '/en/hub', '/en/hub-x'),
        deeperOfHome: isStartPage('home', '/en/hub', '/en/hub/x'),
        addWithPuzzle: isStartPage('add', '/en/puzzle-add', '/en/puzzle-add/x1'),
        addLookalike: isStartPage('add', '/en/puzzle-add', '/en/puzzle-addition'),
        rootIsNoPrefix: isStartPage('add', '/', '/en/hub'),
        noTabPath: isStartPage('home', null, '/en/hub'),
        noCurrentPath: isStartPage('home', '/en/hub', ''),
        serverSays: isStartPage('puzzles', '/en/my-library/p1', '/en/marketplace', { startPage: true }),
        formShown: isStartPage('add', '/en/puzzle-add', '/en/save-stopwatch/s1', { formShown: true }),
        formShownOtherPlace: isStartPage('profile', '/en/player-profile/p1', '/en/edit-time/t1', { formShown: true }),
        truthyIsNotTrue: isStartPage('puzzles', '/en/my-library/p1', '/en/marketplace', { startPage: 'page', formShown: 1 }),
    })),
    // The place of a link without data-nav-place (GEST review F1 + Q3): + Add and the docked column's place rows
    run('placeOfNav', () => ['topbar/add/add', 'menu/home/home', 'menu/puzzles/puzzles_place', 'menu/community/community_place',
        'menu/puzzling/profile', 'menu/puzzling/stats', 'topbar/none/search', 'tabbar/places/home', '', null, 42].map(placeOfNav)),
    run('normalizePath', () => ['/en/hub/', '/', '//', '/en', '', null, 42].map(normalizePath)),
    run('scrollBehavior', () => [scrollBehavior(false), scrollBehavior(true)]),

    // Junk and nothing
    run('no facts at all → navigate', () => decide()),
    run('null facts → navigate', () => decide(null)),
    run('an empty tab place never counts as the current one', () => decide({ ...HUB, tabPlace: '', currentPlace: '', scrollY: 800 })),
    run('a scroll position that is no number counts as the top', () => decide({ ...HUB, scrollY: 'x' })),

    // Inside the Hotwire Native app: the link does what it says (there is no web tab bar)
    run('native app → navigate, whatever else', () => [
        decide({ ...HUB, scrollY: 800, nativeApp: true }),
        decide({ ...HUB, layer: 'menu', nativeApp: true }),
        decide({ ...ADD, nativeApp: true }),
    ]),
    run('decideFor reads the app flag of <html>', () => ({
        app: decideFor(root(true), { ...HUB, scrollY: 800 }),
        web: decideFor(root(false), { ...HUB, scrollY: 800 }),
    })),
]);

process.stdout.write(JSON.stringify(results));
