// "Pages & settings" of the page search (docs/features/navigation MENU-03) for tests/NavigationSearchScriptTest.php:
// runs assets/navigation/nav_search.js on the real nav-search.json lists the test hands over on stdin
// ({lists: {locale: list}, private: [entries], cases: [{name, locale, query, audiences, guestSignIn, withPrivate, all}]})
// and on the module's own rules (kana, apostrophes, the zero-results report, the Menu path line), plus how the fields
// load the list (assets/navigation/nav_search_lists.js: a failed load never loops, another deploy's list is kept briefly).
// Prints {cases: {name: {ids, tiers, signIn}}, rules: {...}} as JSON.

import { readFileSync } from 'node:fs';
import {
    audiencesOf,
    foldQuery,
    isVisibleTo,
    locationOf,
    prepareIndex,
    reportsZero,
    search,
    tierOf,
    toHiragana,
    ZERO_MAX_PER_PAGE,
    zeroResultQuery,
} from '../assets/navigation/nav_search.js';
import { askedVersion, createNavSearchLists, listForQuery, OTHER_VERSION_KEEP_MS } from '../assets/navigation/nav_search_lists.js';

const input = JSON.parse(readFileSync(0, 'utf8') || '{}');
const indexes = new Map();

function indexOf(locale, withPrivate) {
    const key = `${locale}|${withPrivate ? 'private' : ''}`;

    if (!indexes.has(key)) {
        indexes.set(key, prepareIndex(input.lists[locale], withPrivate ? input.private : []));
    }

    return indexes.get(key);
}

const cases = {};

for (const { name, locale, query, audiences, guestSignIn = false, withPrivate = false, all = false } of input.cases || []) {
    const index = indexOf(locale, withPrivate);
    const viewer = new Set(audiences);
    // `all`: every entry this viewer is shown at all (the audience filter alone)
    const results = all
        ? index.entries.filter((entry) => isVisibleTo(entry, viewer)).map((entry) => ({ entry, tier: null, signIn: false }))
        : search(index, query, { audiences: viewer, guestSignIn });

    cases[name] = {
        ids: results.map((result) => result.entry.id),
        tiers: results.map((result) => result.tier),
        signIn: results.filter((result) => result.signIn).map((result) => result.entry.id),
    };
}

// Made-up entries for the rules (the keys as PHP folds them: lower case, no accents, no apostrophes)
const made = prepareIndex({
    v: 'test',
    entries: [
        { id: 'password', l: 'パスワード', u: '/p', a: 'everyone', o: 30, k: 'パスワード\nぱすわーど変更' },
        { id: 'wishlist', l: 'Wunschliste', u: '/w', a: 'everyone', o: 20, k: 'wunschliste\nwunschzettel' },
        { id: 'ideas', l: 'Ideen', u: '/i', a: 'everyone', o: 10, k: 'ideen\nwunsche' },
        { id: 'wally', l: 'Where’s Wally', u: '/x', a: 'everyone', o: 40, k: 'wheres wally' },
        { id: 'members', l: 'Members only', u: '/m', a: 'member', o: 50, k: 'members only' },
    ],
});
const everyone = new Set(['everyone']);
const ids = (query, audiences = everyone) => search(made, query, { audiences }).map((result) => result.entry.id);
const reported = new Set();

for (let i = 0; i < ZERO_MAX_PER_PAGE; i += 1) {
    reported.add(`query ${i}`);
}

const rules = {
    hiragana: [toHiragana('パスワード'), toHiragana('ヴァイオリン ヽヾ'), toHiragana('Hello ひらがな ー')],
    // Half-width katakana: NFKC (foldSearchText) makes them full-width, then hiragana
    foldedKana: [foldQuery('ﾊﾟｽﾜｰﾄﾞ'), foldQuery('パスワード'), foldQuery('ぱすわーど')],
    kanaFinds: [ids('パスワード'), ids('ぱすわーど'), ids('ﾊﾟｽﾜｰﾄﾞ'), ids('ぱすわ'), ids('変更')],
    apostrophes: [foldQuery("Where's"), foldQuery('Where’s'), foldQuery('WHERE´S'), ids("where's"), ids('wheres wal'), ids('Where’s Wally')],
    // A prefix of the label beats a word of a synonym; a compound's middle is a substring hit
    ranking: [
        ids('wunsch'),
        ids('liste'),
        ids('zettel'),
        tierOf(made.entries.find((entry) => entry.id === 'wishlist'), 'wunschliste', ['wunschliste']),
        tierOf(made.entries.find((entry) => entry.id === 'wishlist'), 'wunsch', ['wunsch']),
        tierOf(made.entries.find((entry) => entry.id === 'ideas'), 'wunsch', ['wunsch']),
        tierOf(made.entries.find((entry) => entry.id === 'wishlist'), 'liste', ['liste']),
    ],
    audience: [ids('members'), ids('members', new Set(['everyone', 'member'])), [...audiencesOf('')], [...audiencesOf(' everyone  signed_in member ')]],
    nothing: [ids(''), ids('   '), ids('!!!')],
    location: [
        locationOf({ p: '☰ › Account › Settings › Password' }),
        locationOf({ p: '☰ › Account › Your data' }),
        locationOf({ p: '☰ › Home' }),
        locationOf({ p: '' }),
    ],
    zeroQuery: [zeroResultQuery('  Ünïcödé  QUERY  '), Array.from(zeroResultQuery('x'.repeat(60))).length],
    zero: {
        reported: reportsZero('xyzzy', 0, true, new Set()),
        short: reportsZero('xy', 0, true, new Set()),
        pageHas: reportsZero('xyzzy', 1, true, new Set()),
        serverHas: reportsZero('xyzzy', 0, false, new Set()),
        again: reportsZero('xyzzy', 0, true, new Set(['xyzzy'])),
        cap: reportsZero('xyzzy', 0, true, reported),
    },
};

// ---- the list loader (review F2, F10) ------------------------------------------------------------------------------

const flush = async (rounds = 50) => {
    for (let i = 0; i < rounds; i += 1) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

/**
 * A search field as nav_search_controller.js wires it: focus asks for the list (want + ready), every keystroke runs
 * update() (listForQuery) and a list that arrived runs update() once more. More than 1000 updates = the old loop.
 */
function field(lists, url) {
    const f = { query: '', updates: 0, searched: 0 };
    f.ready = () => f.update();
    f.update = () => {
        f.updates += 1;

        if (f.updates > 1000) {
            throw new Error('update() loops');
        }

        if (listForQuery(lists, url, f.query, f.ready)) {
            f.searched += 1;
        }
    };
    f.focus = () => lists.want(url, f.ready);
    f.type = (text) => {
        f.query = text;
        f.update();
    };

    return f;
}

const LIST_URL = '/en/nav-search.json?v=new';

/** Focus, two keystrokes before the answer, two after it: how many fetches and updates that took */
async function typedWhileFailing(answer) {
    let fetches = 0;
    const lists = createNavSearchLists({
        fetchImpl: async () => {
            fetches += 1;

            return answer();
        },
    });
    const f = field(lists, LIST_URL);

    try {
        f.focus();
        f.type('p');
        f.type('pa');
        await flush();
        const beforeAnswer = { fetches, updates: f.updates };
        f.type('pas');
        f.type('pass');
        await flush();
        const typed = { fetches, updates: f.updates };
        // Nothing more happens by itself
        await flush(200);

        return { beforeAnswer, typed, idle: { fetches, updates: f.updates }, searched: f.searched, kept: lists.get(LIST_URL) !== null };
    } catch (error) {
        return { threw: String(error && error.message) };
    }
}

async function listRules() {
    const result = {};

    result.status503 = await typedWhileFailing(() => ({ ok: false, status: 503, json: async () => ({ entries: [] }) }));
    result.networkError = await typedWhileFailing(() => {
        throw new TypeError('Failed to fetch');
    });
    // A captive portal's HTML page answered 200
    result.notJson = await typedWhileFailing(() => ({ ok: true, status: 200, json: async () => JSON.parse('<!doctype html>') }));

    // The list arrives: focus loads it once, the field searches it from then on
    {
        let fetches = 0;
        const lists = createNavSearchLists({ fetchImpl: async () => ((fetches += 1), { ok: true, json: async () => ({ v: 'new', entries: [] }) }) });
        const f = field(lists, LIST_URL);
        f.focus();
        f.type('p');
        await flush();
        f.type('pa');
        f.type('pas');
        result.loaded = { fetches, updates: f.updates, searched: f.searched };
    }

    // Another deploy answered (its `v` is not the URL's): used, kept OTHER_VERSION_KEEP_MS only, then asked again
    {
        let clock = 1_000_000;
        let fetches = 0;
        let version = 'old';
        const lists = createNavSearchLists({
            fetchImpl: async () => ((fetches += 1), { ok: true, json: async () => ({ v: version, entries: [] }) }),
            now: () => clock,
        });
        lists.want(LIST_URL);
        await flush();
        const used = lists.get(LIST_URL) !== null;
        clock += OTHER_VERSION_KEEP_MS - 1;
        const beforeExpiry = lists.get(LIST_URL) !== null;
        clock += 1;
        const atExpiry = lists.get(LIST_URL) !== null;
        version = 'new';
        lists.want(LIST_URL);
        await flush();
        const refetched = lists.get(LIST_URL);
        clock += 10 * 86_400_000;
        result.otherVersion = { used, beforeExpiry, atExpiry, fetches, refetchedVersion: refetched ? refetched.v : null, thisDeployKept: lists.get(LIST_URL) !== null };
    }

    result.askedVersion = [askedVersion('/en/nav-search.json?v=abc123'), askedVersion('/en/nav-search.json'), askedVersion('/ja/nav-search.json?x=1&v=z')];

    return result;
}

rules.lists = await listRules();

process.stdout.write(JSON.stringify({ cases, rules }));
