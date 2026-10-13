/**
 * The state of a player's Results page as the address carries it (docs/features/navigation README §4.7, PROF-05c) -
 * the pure part of assets/controllers/player_results_controller.js, run under node by tests/PlayerResultsStateScriptTest.php.
 *
 * - urlFromForm(): the address a change of the filter form asks for, written like PlayerResultsCriteria::toQuery() -
 *   the grammar's order, defaults and empty values dropped, never `page` (a new list starts at its top) and never the
 *   form's own helpers (`pieces_was`, `pieces_min` / `pieces_max` become `pieces` by the server's rule)
 * - sameState(): two addresses of the same list (parameter order, `difficultyTiers[]` vs `difficultyTiers[0]`, `+` vs
 *   `%20`, defaults and `return*` do not count)
 * - historyAction(): push for a discrete change (tab, view, sort, a filter, a chip, Clear all), replace for typing and
 *   for paging (Show more / Show all / Show from the start), nothing when the list stays the same - and nothing inside
 *   the Hotwire Native app, where the page writes no history entry (NAV-14, tests/native-step-aside-harness.mjs)
 * - withTypedQuery(): a click while the search still waits for its 300 ms takes the typed text along (PROF review F4)
 * - streamApplies(): whether Show more's answer still belongs to the list and the page it was asked for (review F2)
 * - formStateFromUrl(): what the filter form shows for an address (after Back, a chip, Clear all)
 * - scrollKey() / scrollRestore(): the full-load Back path - where the page was when it was left, keyed by its address,
 *   restored only after a full load that brought the visitor back (never after a snapshot restore, GEST-15)
 */

/** The page's owner name in assets/layer_history.js (pauseTurboHistory / pushPageState) */
export const OWNER = 'results';

/** sessionStorage: where the page was when it was left, per address */
export const SCROLL_KEY_PREFIX = 'msp:results-scroll:';

/** Typing in the search waits this long before the list follows (and replaces the entry) */
export const TYPING_DEBOUNCE_MS = 300;

/** PlayerResultsCriteria::MAX_QUERY_LENGTH */
export const MAX_QUERY_LENGTH = 100;

/** The URL grammar in PlayerResultsCriteria::toQuery()'s order */
export const PARAMETERS = ['category', 'view', 'sort', 'q', 'brand', 'pieces', 'team', 'first', 'unboxed', 'relax', 'from', 'to', 'speed', 'speed_cmp', 'speed_unit', 'difficultyTiers', 'page'];

const DEFAULTS = { view: 'puzzles', sort: 'newest', speed_cmp: 'faster', speed_unit: 'ppm', page: '1' };

const FLAGS = ['first', 'unboxed', 'relax'];

const MAX_PIECES = 100000;

/** Changes that keep the entry (the address follows what the page shows): typing and paging */
const REPLACING = ['typing', 'more', 'all', 'start'];

// ---- Piece counts: PiecesRange's grammar (`N`, `A-B`, `A-`, `-B`) ------------------------------------------------

function validCount(count) {
    return count >= 1 && count <= MAX_PIECES;
}

/** PiecesRange::fromBounds(): out-of-range bounds ignored, swapped bounds put in order; null without a usable bound */
export function piecesFromBounds(min, max) {
    let low = min !== null && validCount(min) ? min : null;
    let high = max !== null && validCount(max) ? max : null;

    if (low === null && high === null) {
        return null;
    }

    if (low !== null && high !== null && low > high) {
        [low, high] = [high, low];
    }

    return { min: low, max: high };
}

/** PiecesRange::parse() (legacy `1001+` included); null for empty or malformed input */
export function parsePieces(value) {
    let text = value === null || value === undefined ? '' : String(value);
    const legacy = text.match(/^(\d{1,6})[+ ]$/);

    if (legacy) {
        text = legacy[1] + '-';
    }

    text = text.trim();

    if (/^\d{1,6}$/.test(text)) {
        const count = Number(text);

        return validCount(count) ? { min: count, max: count } : null;
    }

    const range = text.match(/^(\d{0,6})-(\d{0,6})$/);

    if (!range) {
        return null;
    }

    const min = range[1] !== '' ? Number(range[1]) : null;
    const max = range[2] !== '' ? Number(range[2]) : null;

    if ((min !== null && !validCount(min)) || (max !== null && !validCount(max))) {
        return null;
    }

    return piecesFromBounds(min, max);
}

/** PiecesRange::toParam() */
export function piecesParam(range) {
    if (!range || (range.min === null && range.max === null)) {
        return '';
    }

    if (range.min !== null && range.min === range.max) {
        return String(range.min);
    }

    return (range.min === null ? '' : String(range.min)) + '-' + (range.max === null ? '' : String(range.max));
}

function parseBound(value) {
    const text = value === null || value === undefined ? '' : String(value).trim();

    return /^\d{1,6}$/.test(text) ? Number(text) : null;
}

/**
 * PlayerResultsCriteria::piecesRange(): the form offers the counts twice - the `pieces` chips and the custom bounds -
 * and says in `pieces_was` what it showed; typed bounds that differ from it win, else the chip.
 * `pieces` is null when no chip is checked (a custom range is applied).
 */
export function piecesFromForm(pieces, min, max, was) {
    const bounds = piecesFromBounds(parseBound(min), parseBound(max));

    if (bounds !== null && (pieces === null || pieces === undefined || piecesParam(bounds) !== piecesParam(parsePieces(was)))) {
        return piecesParam(bounds);
    }

    return piecesParam(parsePieces(pieces));
}

// ---- Addresses ---------------------------------------------------------------------------------------------------

function toUrl(url, base) {
    try {
        return url instanceof URL ? url : new URL(String(url), base || 'http://localhost/');
    } catch (e) {
        return null;
    }
}

/** `difficultyTiers[]` and `difficultyTiers[0]` are one parameter */
function parameterName(name) {
    return String(name).replace(/\[\d*\]$/, '');
}

function flag(value) {
    return value === '1' || value === 'on' || value === 'true';
}

/**
 * The list's state as ordered [name, value] pairs: the grammar's parameters only, defaults and empty values dropped,
 * speed_cmp / speed_unit only with a speed, flags as '1'
 */
export function stateParams(search) {
    const params = new URLSearchParams(typeof search === 'string' ? search.replace(/^\?/, '') : search);
    const values = {};

    for (const [rawName, rawValue] of params) {
        const name = parameterName(rawName);

        if (!PARAMETERS.includes(name)) {
            continue;
        }

        (values[name] = values[name] || []).push(String(rawValue));
    }

    const pairs = [];
    const speed = (values.speed || [''])[0].trim();

    for (const name of PARAMETERS) {
        const list = values[name];

        if (!list) {
            continue;
        }

        if (name === 'difficultyTiers') {
            // DifficultyFilter::normalize(): each tier once, in order
            Array.from(new Set(list.map((value) => value.trim()).filter((value) => value !== '')))
                .sort()
                .forEach((value) => pairs.push([name, value]));

            continue;
        }

        let value = list[0].trim();

        if (FLAGS.includes(name)) {
            value = flag(value) ? '1' : '';
        } else if ((name === 'speed_cmp' || name === 'speed_unit') && speed === '') {
            value = '';
        }

        if (value === '' || DEFAULTS[name] === value) {
            continue;
        }

        pairs.push([name, value]);
    }

    return pairs;
}

/** The query string of the list's state (no `?`), `difficultyTiers[]` written with brackets as the form sends them */
export function stateQuery(search) {
    const params = new URLSearchParams();

    stateParams(search).forEach(([name, value]) => params.append(name === 'difficultyTiers' ? 'difficultyTiers[]' : name, value));

    return params.toString();
}

/** Two addresses of the same page and list */
export function sameState(a, b, base) {
    const first = toUrl(a, base);
    const second = toUrl(b, base);

    if (!first || !second) {
        return false;
    }

    return first.pathname === second.pathname && stateQuery(first.search) === stateQuery(second.search);
}

/**
 * The address a change of the filter form asks for. `entries`: the form's [name, value] pairs (FormData - a disabled
 * fieldset sends nothing, so a free viewer never asks for a members-only filter); `pathname` the page's own. The tab,
 * view and sort come from the form's hidden fields (the shown list's, as without JavaScript).
 */
export function urlFromForm(entries, pathname) {
    const get = (name) => {
        const found = entries.find(([key]) => key === name);

        return found ? String(found[1]) : null;
    };
    const params = new URLSearchParams();
    const add = (name, value) => {
        if (value !== null && value !== undefined && String(value).trim() !== '') {
            params.append(name, String(value).trim());
        }
    };

    add('category', get('category'));
    add('view', get('view'));
    add('sort', get('sort'));
    add('q', get('q') === null ? null : get('q').trim().slice(0, MAX_QUERY_LENGTH));
    add('brand', get('brand'));
    add('pieces', piecesFromForm(get('pieces'), get('pieces_min'), get('pieces_max'), get('pieces_was')));
    add('team', get('team'));
    FLAGS.forEach((name) => add(name, flag(get(name) || '') ? '1' : ''));
    add('from', get('from'));
    add('to', get('to'));
    add('speed', get('speed'));
    add('speed_cmp', get('speed_cmp'));
    add('speed_unit', get('speed_unit'));
    entries.filter(([key]) => parameterName(key) === 'difficultyTiers').forEach(([, value]) => add('difficultyTiers[]', value));

    const query = stateQuery(params.toString());

    return pathname + (query === '' ? '' : '?' + query);
}

/**
 * What a change does with the history: 'push' (a discrete change: tab, view, sort, a filter, a chip, Clear all, a
 * submit), 'replace' (typing, Show more, Show all) or 'none' (the same list - or inside the app, where the page writes
 * no entry)
 */
export function historyAction(kind, from, to, nativeApp = false, base = undefined) {
    if (nativeApp || sameState(from, to, base)) {
        return 'none';
    }

    return REPLACING.includes(kind) ? 'replace' : 'push';
}

/**
 * The address of a change made while the search still waits for its debounce (PROF review F4): the change's own
 * address with the typed text as its `q` (trimmed, cut like the field; no `q` when it is empty) - so neither the click
 * nor the typed text is lost. Everything else of the address stays (a tab, a sort, Show more's page).
 */
export function withTypedQuery(url, typed, base = undefined) {
    const parsed = toUrl(url, base);

    if (!parsed) {
        return String(url);
    }

    const text = String(typed === null || typed === undefined ? '' : typed).trim().slice(0, MAX_QUERY_LENGTH);

    if (text === '') {
        parsed.searchParams.delete('q');
    } else {
        parsed.searchParams.set('q', text);
    }

    return parsed.href;
}

/**
 * Does Show more's answer still apply (PROF review F2)? Only to the list it was asked for, on the page it was asked
 * on: nothing aborted it (a newer change of the list, a visit, the page leaving), the page and its footer are still in
 * the document, no other list is loading and the list shown is the one the request started from.
 */
export function streamApplies({ aborted = false, connected = true, footerConnected = true, pending = false, requestedFrom = '', shownUrl = '' } = {}, base = undefined) {
    return !aborted && connected && footerConnected && !pending && sameState(requestedFrom, shownUrl, base);
}

/** Does the page take its links and its form over (frame loads + its own entries)? Not inside the app */
export function takesOver(nativeApp) {
    return !nativeApp;
}

/** What the filter form shows for an address. `presets`: the piece-count chips' values on the page */
export function formStateFromUrl(url, presets = [], base = undefined) {
    const parsed = toUrl(url, base);
    const pairs = parsed ? stateParams(parsed.search) : [];
    const one = (name) => {
        const found = pairs.find(([key]) => key === name);

        return found ? found[1] : '';
    };
    const pieces = piecesParam(parsePieces(one('pieces')));
    const preset = pieces === '' || presets.includes(pieces);
    const range = preset ? null : parsePieces(pieces);

    return {
        q: one('q'),
        brand: one('brand'),
        team: one('team'),
        pieces: preset ? pieces : null,
        piecesMin: range && range.min !== null ? String(range.min) : '',
        piecesMax: range && range.max !== null ? String(range.max) : '',
        piecesWas: pieces,
        first: one('first') === '1',
        unboxed: one('unboxed') === '1',
        relax: one('relax') === '1',
        from: one('from'),
        to: one('to'),
        speed: one('speed'),
        speedCmp: one('speed_cmp') || DEFAULTS.speed_cmp,
        speedUnit: one('speed_unit') || DEFAULTS.speed_unit,
        difficultyTiers: pairs.filter(([key]) => key === 'difficultyTiers').map(([, value]) => value),
    };
}

// ---- The full-load Back path ---------------------------------------------------------------------------------------

/** sessionStorage key of an address: the page and its list (whatever order or form the address was written in) */
export function scrollKey(url, base = undefined) {
    const parsed = toUrl(url, base);

    if (!parsed) {
        return null;
    }

    const query = stateQuery(parsed.search);

    return SCROLL_KEY_PREFIX + parsed.pathname + (query === '' ? '' : '?' + query);
}

/**
 * Restore the saved position? Only after a full load that brought the visitor back to this address (app.js's restore
 * reload, a back/forward load) - never after a snapshot restore (Turbo kept the page and its scroll, GEST-15), never in
 * the app, never without a saved position. 'restore' | 'none'
 */
export function scrollRestore({ nativeApp = false, snapshotRestore = false, cameBack = false, saved = null } = {}) {
    if (nativeApp || snapshotRestore || !cameBack || !saved) {
        return 'none';
    }

    return typeof saved.y === 'number' || typeof saved.row === 'string' ? 'restore' : 'none';
}

/** A saved position read back: {y, row, rowTop} or null when missing or broken */
export function parseSavedScroll(text) {
    if (typeof text !== 'string' || text === '') {
        return null;
    }

    try {
        const value = JSON.parse(text);

        if (!value || typeof value !== 'object') {
            return null;
        }

        const y = typeof value.y === 'number' && Number.isFinite(value.y) && value.y >= 0 ? value.y : null;
        const row = typeof value.row === 'string' && /^[A-Za-z][\w-]*$/.test(value.row) ? value.row : null;
        const rowTop = typeof value.rowTop === 'number' && Number.isFinite(value.rowTop) ? value.rowTop : null;

        return y === null && row === null ? null : { y, row, rowTop };
    } catch (e) {
        return null;
    }
}

/**
 * Where to scroll so the saved row sits where it sat (its top `rowTop` px below the viewport's top); the saved scrollY
 * when the row is gone. `rowDocumentTop`: the row's top in the document now, or null.
 */
export function restoreTarget(saved, rowDocumentTop) {
    if (!saved) {
        return null;
    }

    if (typeof rowDocumentTop === 'number' && typeof saved.rowTop === 'number') {
        return Math.max(0, Math.round(rowDocumentTop - saved.rowTop));
    }

    return typeof saved.y === 'number' ? Math.max(0, Math.round(saved.y)) : null;
}
