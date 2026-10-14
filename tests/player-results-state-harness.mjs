// Runs assets/player_results_state.js (the Results page's address rules, docs/features/navigation PROF-05c) and
// assets/instant_back.js's createRestoreReload() (which document a Back brought back by a full load - PROF-05c's
// restore mark, folded there from assets/restore_reload.js by GEST-15) through the cases
// tests/PlayerResultsStateScriptTest.php asks for and prints one JSON object of results.

import {
    formStateFromUrl,
    historyAction,
    parsePieces,
    parseSavedScroll,
    piecesFromForm,
    piecesParam,
    restoreTarget,
    sameState,
    scrollKey,
    scrollRestore,
    stateQuery,
    streamApplies,
    takesOver,
    urlFromForm,
    withTypedQuery,
    OWNER,
    SCROLL_KEY_PREFIX,
    TYPING_DEBOUNCE_MS,
} from '../assets/player_results_state.js';
import { createRestoreReload, RESTORE_RELOAD_KEY, SNAPSHOT_RESTORES } from '../assets/instant_back.js';

const PAGE = '/en/player-results/abc';
const BASE = 'https://msp.test' + PAGE;
const abs = (url) => new URL(url, BASE).href;

const results = {};

function run(name, fn) {
    try {
        results[name] = fn();
    } catch (error) {
        results[name] = { threw: String(error && error.stack ? error.stack : error) };
    }
}

/** The filter form as a free viewer submits it untouched: every field, empty, the helpers included */
const UNTOUCHED = [
    ['q', ''],
    ['brand', ''],
    ['team', ''],
    ['pieces_was', ''],
    ['pieces', ''],
    ['pieces_min', ''],
    ['pieces_max', ''],
];

/** A member's form with the members' fieldset enabled: everything filled */
const MEMBER = [
    ['category', 'duo'],
    ['view', 'every'],
    ['sort', 'fastest'],
    ['q', '  Night  sky '],
    ['brand', 'Ravensburger'],
    ['team', '018d0000-0000-0000-0000-000000000001'],
    ['pieces_was', ''],
    ['pieces', '500'],
    ['pieces_min', ''],
    ['pieces_max', ''],
    ['difficultyTiers[]', '2'],
    ['difficultyTiers[]', '0'],
    ['relax', '1'],
    ['first', '1'],
    ['unboxed', '1'],
    ['from', '2026-01-01'],
    ['to', '2026-02-01'],
    ['speed_cmp', 'slower'],
    ['speed', '12.5'],
    ['speed_unit', 'min'],
];

function storage(initial = {}) {
    const items = new Map(Object.entries(initial));

    return {
        items,
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => items.set(key, String(value)),
        removeItem: (key) => items.delete(key),
    };
}

function fakeWindow(href, navigationType = 'navigate', navigationName = href) {
    return {
        location: { href },
        performance: { getEntriesByType: (type) => (type === 'navigation' ? [{ type: navigationType, name: navigationName }] : []) },
    };
}

run('constants', () => ({ owner: OWNER, prefix: SCROLL_KEY_PREFIX, debounce: TYPING_DEBOUNCE_MS, restoreKey: RESTORE_RELOAD_KEY, snapshots: SNAPSHOT_RESTORES }));

// The address of a form change: defaults, empty values and the form's helpers dropped, the grammar's order
run('url from form', () => ({
    untouched: urlFromForm(UNTOUCHED, PAGE),
    defaults: urlFromForm([['view', 'puzzles'], ['sort', 'newest'], ['speed_cmp', 'faster'], ['speed_unit', 'ppm'], ['page', '3'], ...UNTOUCHED], PAGE),
    // The comparison and unit of a speed mean nothing without one
    unitWithoutSpeed: urlFromForm([['speed_cmp', 'slower'], ['speed_unit', 'min'], ['speed', '']], PAGE),
    member: urlFromForm(MEMBER, PAGE),
    // Only the first 100 characters of a search
    longSearch: urlFromForm([['q', 'x'.repeat(130)]], PAGE).length - (PAGE + '?q=').length,
    flags: urlFromForm([['first', 'on'], ['unboxed', 'true'], ['relax', 'no']], PAGE),
}));

// PlayerResultsCriteria::piecesRange(): the chip, or typed bounds that differ from what was applied
run('pieces from form', () => ({
    chip: piecesFromForm('500', '', '', ''),
    any: piecesFromForm('', '', '', ''),
    typedNew: piecesFromForm('', '300', '700', ''),
    // The custom range applied before: no chip checked, the bounds shown back
    customKept: piecesFromForm(null, '300', '700', '300-700'),
    // A chip picked while the custom range is still shown back: the chip wins
    chipOverCustom: piecesFromForm('1000', '300', '700', '300-700'),
    // Typed bounds that differ from what was applied win over the chip
    typedOverChip: piecesFromForm('500', '100', '', '500'),
    swapped: piecesFromForm(null, '900', '100', ''),
    exact: piecesFromForm(null, '750', '750', ''),
    outOfRange: piecesFromForm(null, '0', '200000', ''),
    junk: piecesFromForm(null, '5a', 'x', ''),
    clearedCustom: piecesFromForm(null, '', '', '300-700'),
}));

run('pieces grammar', () => ({
    exact: piecesParam(parsePieces('500')),
    between: piecesParam(parsePieces('99-100')),
    atLeast: piecesParam(parsePieces('2000-')),
    atMost: piecesParam(parsePieces('-300')),
    legacyPlus: piecesParam(parsePieces('1001+')),
    legacySpace: piecesParam(parsePieces('1001 ')),
    swapped: piecesParam(parsePieces('900-100')),
    zero: parsePieces('0'),
    tooMany: parsePieces('100001'),
    junk: parsePieces('abc'),
    empty: parsePieces(''),
}));

// The same list however its address is written
run('same state', () => ({
    order: sameState(PAGE + '?sort=fastest&category=duo', PAGE + '?category=duo&sort=fastest', BASE),
    brackets: sameState(PAGE + '?difficultyTiers%5B0%5D=2&difficultyTiers%5B1%5D=0', PAGE + '?difficultyTiers%5B%5D=2&difficultyTiers%5B%5D=0', BASE),
    spaces: sameState(PAGE + '?q=night%20sky', PAGE + '?q=night+sky', BASE),
    defaults: sameState(PAGE + '?sort=newest&view=puzzles&page=1', PAGE, BASE),
    returnIgnored: sameState(PAGE + '?return=%2Fen%2Fhub&sort=fastest', PAGE + '?sort=fastest', BASE),
    unknownIgnored: sameState(PAGE + '?utm_source=x', PAGE, BASE),
    hashIgnored: sameState(PAGE + '?sort=name#result-1', PAGE + '?sort=name', BASE),
    otherSort: sameState(PAGE + '?sort=fastest', PAGE + '?sort=slowest', BASE),
    otherPage: sameState(PAGE + '?page=2', PAGE + '?page=3', BASE),
    otherPlayer: sameState(PAGE + '?sort=fastest', '/en/player-results/xyz?sort=fastest', BASE),
    explicitSolo: sameState(PAGE + '?category=solo', PAGE, BASE),
    tierOrder: sameState(PAGE + '?difficultyTiers[]=2&difficultyTiers[]=0&difficultyTiers[]=2', PAGE + '?difficultyTiers[]=0&difficultyTiers[]=2', BASE),
    query: stateQuery('?page=2&utm=1&sort=newest&q=a&category=group&difficultyTiers%5B3%5D=4'),
}));

// Push for a discrete change, replace for typing / Show more / Show all / the server's form, nothing for the same list
run('history action', () => ({
    discrete: historyAction('discrete', BASE, abs(PAGE + '?sort=fastest')),
    submit: historyAction('submit', BASE, abs(PAGE + '?brand=X')),
    typing: historyAction('typing', BASE, abs(PAGE + '?q=night')),
    more: historyAction('more', BASE, abs(PAGE + '?page=2')),
    all: historyAction('all', BASE, abs(PAGE + '?page=6')),
    start: historyAction('start', abs(PAGE + '?page=10'), BASE),
    sameList: historyAction('discrete', abs(PAGE + '?sort=newest'), BASE),
    sameTyping: historyAction('typing', abs(PAGE + '?q=night'), abs(PAGE + '?q=night+')),
    insideTheApp: historyAction('discrete', BASE, abs(PAGE + '?sort=fastest'), true),
    takesOverOnTheWeb: takesOver(false),
    takesOverInTheApp: takesOver(true),
}));

// PROF review F4: a click within the search's wait takes the typed text along
run('typed query', () => ({
    tab: withTypedQuery(abs(PAGE + '?category=duo'), ' night  sky ', BASE),
    replaced: withTypedQuery(abs(PAGE + '?q=old&sort=fastest'), 'new', BASE),
    paging: withTypedQuery(abs(PAGE + '?page=2'), 'night', BASE),
    emptied: withTypedQuery(abs(PAGE + '?q=old&brand=X'), '   ', BASE),
    long: new URL(withTypedQuery(BASE, 'x'.repeat(150), BASE)).searchParams.get('q').length,
}));

// PROF review F2: Show more's answer applies only to the list and the page it was asked for
run('stream applies', () => {
    const asked = { requestedFrom: abs(PAGE + '?sort=fastest'), shownUrl: abs(PAGE + '?sort=fastest') };

    return {
        same: streamApplies(asked, BASE),
        sameListOtherForm: streamApplies({ requestedFrom: abs(PAGE + '?sort=fastest&sort=fastest'), shownUrl: abs(PAGE + '?sort=fastest&return=x') }, BASE),
        aborted: streamApplies({ ...asked, aborted: true }, BASE),
        disconnected: streamApplies({ ...asked, connected: false }, BASE),
        footerGone: streamApplies({ ...asked, footerConnected: false }, BASE),
        anotherListLoading: streamApplies({ ...asked, pending: true }, BASE),
        anotherListShown: streamApplies({ ...asked, shownUrl: abs(PAGE + '?category=duo') }, BASE),
        anotherPage: streamApplies({ ...asked, shownUrl: abs('/en/puzzle/abc') }, BASE),
    };
});

// What the filter form shows for an address
run('form state', () => {
    const presets = ['99-100', '200', '300', '500', '750', '1000', '1500', '2000-'];

    return {
        empty: formStateFromUrl(PAGE, presets, BASE),
        member: formStateFromUrl(urlFromForm(MEMBER, PAGE), presets, BASE),
        custom: formStateFromUrl(PAGE + '?pieces=300-700', presets, BASE),
        atLeast: formStateFromUrl(PAGE + '?pieces=2000-', presets, BASE),
        // A preset the page does not offer (the player has nothing of it) is shown as a custom range
        notOffered: formStateFromUrl(PAGE + '?pieces=1500', ['500'], BASE),
        brackets: formStateFromUrl(PAGE + '?difficultyTiers%5B0%5D=3&difficultyTiers%5B1%5D=0', presets, BASE).difficultyTiers,
    };
});

// The full-load Back path: one key per list, restored only after a full load that came back
run('scroll', () => ({
    key: scrollKey(abs(PAGE + '?page=3&sort=fastest')),
    keySame: scrollKey(abs(PAGE + '?sort=fastest&page=3')) === scrollKey(abs(PAGE + '?page=3&sort=fastest&return=%2Fen%2Fhub#x')),
    keyBase: scrollKey(abs(PAGE + '?sort=newest')),
    restore: scrollRestore({ cameBack: true, saved: { y: 4000, row: 'result-1', rowTop: 200 } }),
    notBack: scrollRestore({ cameBack: false, saved: { y: 4000, row: null, rowTop: null } }),
    snapshot: scrollRestore({ snapshotRestore: true, cameBack: true, saved: { y: 4000, row: null, rowTop: null } }),
    app: scrollRestore({ nativeApp: true, cameBack: true, saved: { y: 4000, row: null, rowTop: null } }),
    nothingSaved: scrollRestore({ cameBack: true, saved: null }),
    parsed: parseSavedScroll(JSON.stringify({ y: 4000.4, row: 'result-01a-b', rowTop: 210 })),
    parsedJunk: [parseSavedScroll(''), parseSavedScroll('{'), parseSavedScroll('"x"'), parseSavedScroll(JSON.stringify({ y: -5, row: '<img>' })), parseSavedScroll(null)],
    // The row back where it sat; the saved scrollY without the row
    targetRow: restoreTarget({ y: 4000, row: 'r', rowTop: 210 }, 4410),
    targetNoRow: restoreTarget({ y: 4000, row: 'r', rowTop: 210 }, null),
    targetTop: restoreTarget({ y: 10, row: 'r', rowTop: 900 }, 300),
}));

// app.js marks the URL of a restore reload; the next document reads the mark once
run('restore reload', () => {
    const url = 'https://msp.test' + PAGE + '?page=3';
    const store = storage();

    const leaving = createRestoreReload({ window: fakeWindow(BASE), storage: store });
    leaving.mark(url);
    const marked = store.getItem(RESTORE_RELOAD_KEY);

    const next = createRestoreReload({ window: fakeWindow(url), storage: store });
    const cameBack = next.cameBackByFullLoad(url);
    const cameBackWithHash = next.cameBackByFullLoad(url + '#result-1');
    const markLeft = store.getItem(RESTORE_RELOAD_KEY);
    const otherUrl = next.cameBackByFullLoad('https://msp.test' + PAGE);
    next.visited();
    const afterVisit = next.cameBackByFullLoad(url);

    // A third document (a normal visit): the mark is gone
    const later = createRestoreReload({ window: fakeWindow(url), storage: store });

    const backForward = createRestoreReload({ window: fakeWindow(url, 'back_forward', url), storage: storage() });
    const backForwardElsewhere = createRestoreReload({ window: fakeWindow(url, 'back_forward', 'https://msp.test/en/hub'), storage: storage() });
    const reload = createRestoreReload({ window: fakeWindow(url, 'reload', url), storage: storage() });
    const throwing = createRestoreReload({
        window: fakeWindow(url),
        storage: { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } },
    });
    throwing.mark(url);

    return {
        marked,
        cameBack,
        cameBackWithHash,
        markLeft,
        otherUrl,
        afterVisit,
        later: later.cameBackByFullLoad(url),
        backForward: backForward.cameBackByFullLoad(url),
        backForwardElsewhere: backForwardElsewhere.cameBackByFullLoad(url),
        reload: reload.cameBackByFullLoad(url),
        throwing: throwing.cameBackByFullLoad(url),
        snapshot: next.isSnapshotRestore(),
    };
});

process.stdout.write(JSON.stringify(results));
