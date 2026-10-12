// "Pages & settings" of the page search (docs/features/navigation MENU-03) for tests/NavigationSearchScriptTest.php:
// runs assets/navigation/nav_search.js on the real nav-search.json lists the test hands over on stdin
// ({lists: {locale: list}, private: [entries], cases: [{name, locale, query, audiences, guestSignIn, withPrivate, all}]})
// and on the module's own rules (kana, apostrophes, the zero-results report, the Menu path line).
// Prints {cases: {name: {ids, tiers, signIn}}, rules: {...}} as JSON.

import { readFileSync } from 'node:fs';
import {
    audiencesOf,
    foldQuery,
    locationOf,
    prepareIndex,
    reportsZero,
    search,
    tierOf,
    toHiragana,
    ZERO_MAX_PER_PAGE,
    zeroResultQuery,
} from '../assets/navigation/nav_search.js';

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
        ? index.entries.filter((entry) => viewer.has(entry.a)).map((entry) => ({ entry, tier: null, signIn: false }))
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

process.stdout.write(JSON.stringify({ cases, rules }));
