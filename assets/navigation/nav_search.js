import { foldSearchText } from '../search_fold.js';

// "Pages & settings" in the page search (docs/features/navigation README §4.12 "Search", MENU-03; SRCH-01 adds the
// top bar's options): the registry's list, nav-search.json (NavigationSearchIndex), filtered by the viewer's audiences
// and ranked against what was typed - no statement, no request but the list itself.
//
// Matching: the list's keys are folded once by PHP (SearchText::fold()); what is typed is folded here by its browser
// twin foldSearchText() (assets/search_fold.js - no second fold), which already drops apostrophes ("where's" = "wheres").
// On top of it, JS-only and on both sides: katakana → hiragana (Japanese is typed either way). Every typed word must
// hit a key: as the start of one of its words, or anywhere in it (German compounds: "liste" finds "Wunschliste").
// Ranking: the exact label > every word starts a label word > every word starts a word of one synonym > the label
// holds every word > one synonym holds every word > the words spread over the keys; ties keep the Menu's order.
//
// Pure: no DOM, no fetch (tests/nav-search-harness.mjs runs it under node).

export const MENU_LIMIT = 8;
export const BAR_LIMIT = 3;

// Zero results (README §4.12, decision 39): reported through the P0-03 beacon once the typing paused, only for a
// real query, at most a few times per page view, cut short
export const ZERO_MIN_LENGTH = 3;
export const ZERO_IDLE_MS = 1500;
export const ZERO_MAX_PER_PAGE = 5;
export const ZERO_MAX_LENGTH = 40;

export const TIER_EXACT = 0;
export const TIER_LABEL_PREFIX = 1;
export const TIER_SYNONYM_PREFIX = 2;
export const TIER_LABEL_SUBSTRING = 3;
export const TIER_SYNONYM_SUBSTRING = 4;
export const TIER_SPREAD = 5;

// Guests: the fixed page audience (NavigationViewer::pageAudience() for every guest)
const GUEST_AUDIENCES = ['everyone', 'guest'];

// The audience a guest is shown as "Sign in to …" (SRCH-01): pages any signed-in player opens
const SIGN_IN_AUDIENCE = 'signed_in';

const WORD_SEPARATOR = /[^\p{L}\p{N}\p{M}]+/u;

/** Katakana → hiragana (ァ..ヶ and the iteration marks); everything else as it is */
export function toHiragana(text) {
    return String(text ?? '').replace(/[ァ-ヶヽヾ]/g, (character) => String.fromCharCode(character.charCodeAt(0) - 0x60));
}

/** What was typed, as the keys are: foldSearchText() + katakana → hiragana */
export function foldQuery(text) {
    return toHiragana(foldSearchText(text));
}

function wordsOf(line) {
    return line.split(WORD_SEPARATOR).filter((word) => word !== '');
}

/** The viewer's audiences from `<body data-viewer-audience>` ("everyone guest" for guests) */
export function audiencesOf(attribute) {
    const audiences = String(attribute ?? '').trim().split(/\s+/).filter((audience) => audience !== '');

    return new Set(audiences.length > 0 ? audiences : GUEST_AUDIENCES);
}

/**
 * The list ready to search: the public entries plus the page's private ones (#nav-search-private, moderators and
 * admins), each with its keys split into lines (the label first) and words, in the Menu's order. The private entries
 * are marked `own`: the server rendered them for this viewer only, so the audience filter never drops them (their
 * role audiences are not page audiences, NavigationViewer::pageAudience() - review F1).
 */
export function prepareIndex(data, privateEntries = []) {
    const list = data && Array.isArray(data.entries) ? data.entries.map((entry) => (entry ? { ...entry, own: false } : entry)) : [];
    const extra = Array.isArray(privateEntries) ? privateEntries.map((entry) => (entry ? { ...entry, own: true } : entry)) : [];
    const entries = [...list, ...extra]
        .filter((entry) => entry && typeof entry.id === 'string' && typeof entry.u === 'string')
        .map((entry, index) => {
            const lines = String(entry.k ?? '').split('\n').map((line) => toHiragana(line)).filter((line) => line !== '');

            return {
                ...entry,
                lines,
                words: lines.map(wordsOf),
                rank: typeof entry.o === 'number' ? entry.o : Number.MAX_SAFE_INTEGER,
                index,
            };
        })
        .sort((a, b) => (a.rank === b.rank ? a.index - b.index : a.rank - b.rank));

    return {
        version: data && typeof data.v === 'string' ? data.v : null,
        groups: data && data.groups && typeof data.groups === 'object' ? data.groups : {},
        entries,
    };
}

function everyWordStarts(queryWords, keyWords) {
    return queryWords.every((word) => keyWords.some((keyWord) => keyWord.startsWith(word)));
}

function everyWordIn(queryWords, line) {
    return queryWords.every((word) => line.includes(word));
}

/** How well the entry matches (TIER_*), or null when it does not */
export function tierOf(entry, folded, queryWords) {
    const [label = '', ...synonyms] = entry.lines;
    const [labelWords = [], ...synonymWords] = entry.words;

    if (label === folded) {
        return TIER_EXACT;
    }

    if (everyWordStarts(queryWords, labelWords)) {
        return TIER_LABEL_PREFIX;
    }

    if (synonymWords.some((words) => everyWordStarts(queryWords, words))) {
        return TIER_SYNONYM_PREFIX;
    }

    if (everyWordIn(queryWords, label)) {
        return TIER_LABEL_SUBSTRING;
    }

    if (synonyms.some((line) => everyWordIn(queryWords, line))) {
        return TIER_SYNONYM_SUBSTRING;
    }

    if (queryWords.every((word) => entry.lines.some((line) => line.includes(word)))) {
        return TIER_SPREAD;
    }

    return null;
}

/** May this viewer open the entry? An entry of the page's own (#nav-search-private) always; else by its audience */
export function isVisibleTo(entry, audiences) {
    return entry.own === true || audiences.has(entry.a);
}

/**
 * The entries matching the query that the viewer may open, best first: [{entry, tier, signIn}]. With `guestSignIn`
 * (the top bar, SRCH-01) a guest also gets the pages of any signed-in player, marked signIn ("Sign in to …").
 */
export function search(index, query, { audiences = new Set(GUEST_AUDIENCES), guestSignIn = false } = {}) {
    const folded = foldQuery(query);
    const queryWords = wordsOf(folded);

    if (queryWords.length === 0 || !index) {
        return [];
    }

    const signInAllowed = guestSignIn && audiences.has('guest');
    const results = [];

    for (const entry of index.entries) {
        const visible = isVisibleTo(entry, audiences);
        const signIn = !visible && signInAllowed && entry.a === SIGN_IN_AUDIENCE;

        if (!visible && !signIn) {
            continue;
        }

        const tier = tierOf(entry, folded, queryWords);

        if (tier !== null) {
            results.push({ entry, tier, signIn });
        }
    }

    // Stable: equal tiers keep the Menu's order
    return results
        .map((result, position) => ({ result, position }))
        .sort((a, b) => (a.result.tier === b.result.tier ? a.position - b.position : a.result.tier - b.result.tier))
        .map(({ result }) => result);
}

/**
 * Where the entry lives, for the line under its label: its Menu path without "☰" and without the entry itself
 * ("Account › Settings" for Password); empty for a top-level row.
 */
export function locationOf(entry, separator = ' › ') {
    const parts = String(entry.p ?? '').split(separator);

    if (parts.length > 0 && parts[0].trim() === '☰') {
        parts.shift();
    }

    if (parts.length > 0) {
        parts.pop();
    }

    return parts.join(separator);
}

/** The query as the zero-results beacon sends it: folded, cut to ZERO_MAX_LENGTH characters */
export function zeroResultQuery(query) {
    return Array.from(foldQuery(query)).slice(0, ZERO_MAX_LENGTH).join('');
}

/**
 * Should a search without results be reported? A real query (ZERO_MIN_LENGTH+ characters folded), nothing on the page
 * for it (no page entry, no player, no puzzle), not reported before on this page view, and fewer than
 * ZERO_MAX_PER_PAGE reports so far.
 */
export function reportsZero(query, pageResults, serverEmpty, reported) {
    const sent = reported instanceof Set ? reported : new Set();
    const q = zeroResultQuery(query);

    return Array.from(foldQuery(query)).length >= ZERO_MIN_LENGTH
        && pageResults === 0
        && serverEmpty === true
        && !sent.has(q)
        && sent.size < ZERO_MAX_PER_PAGE;
}
