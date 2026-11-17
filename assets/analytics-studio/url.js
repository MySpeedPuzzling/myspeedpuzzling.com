// Analytics Studio (docs/features/competitions-management/analytics-studio.md §7.4 + §0a R10) - the view kept in the URL
// with history.replaceState: `?puzzle=B&e=<entryId>&sort=best&q=…`. Only these four (no `cmp`, no density). The puzzle
// is addressed by its slot label, never by an id; unknown values are dropped. Pure - pinned by
// tests/analytics-studio-harness.mjs.

import { DEFAULT_SORT, SORTS } from './model.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const LABEL = /^[A-Za-z0-9]{1,8}$/;
export const MAX_QUERY_LENGTH = 100;

/**
 * The view read from a query string (with or without "?") - every value checked, anything unknown is null.
 *
 * @returns {{puzzle: string|null, e: string|null, sort: string|null, q: string}}
 */
export function decodeView(search) {
    let params;

    try {
        params = new URLSearchParams(typeof search === 'string' ? search : '');
    } catch {
        params = new URLSearchParams('');
    }

    const puzzle = params.get('puzzle');
    const entry = params.get('e');
    const sort = params.get('sort');
    const query = params.get('q');

    return {
        puzzle: puzzle !== null && LABEL.test(puzzle) ? puzzle : null,
        e: entry !== null && UUID.test(entry) ? entry.toLowerCase() : null,
        sort: sort !== null && SORTS.includes(sort) ? sort : null,
        q: query !== null ? query.slice(0, MAX_QUERY_LENGTH) : '',
    };
}

/**
 * The query string for a view ("" for the default view, else "?…") - defaults are left out so a shared link stays short.
 */
export function encodeView({ puzzle = null, e = null, sort = null, q = '' } = {}) {
    const params = new URLSearchParams();

    if (typeof puzzle === 'string' && LABEL.test(puzzle)) {
        params.set('puzzle', puzzle);
    }

    if (typeof e === 'string' && UUID.test(e)) {
        params.set('e', e.toLowerCase());
    }

    if (typeof sort === 'string' && SORTS.includes(sort) && sort !== DEFAULT_SORT) {
        params.set('sort', sort);
    }

    if (typeof q === 'string' && q.trim() !== '') {
        params.set('q', q.slice(0, MAX_QUERY_LENGTH));
    }

    const text = params.toString();

    return text === '' ? '' : `?${text}`;
}

/**
 * The decoded view against the round's state: a label no slot has, an entry not in the round → null (ignored).
 */
export function resolveView(view, state) {
    const labels = Array.isArray(state?.slots) ? state.slots.map((slot) => slot?.label) : [];
    const keys = Array.isArray(state?.entrants) ? state.entrants.map((entrant) => String(entrant?.key ?? '').toLowerCase()) : [];

    return {
        ...view,
        puzzle: view.puzzle !== null && labels.includes(view.puzzle) ? view.puzzle : null,
        e: view.e !== null && keys.includes(view.e) ? view.e : null,
    };
}
