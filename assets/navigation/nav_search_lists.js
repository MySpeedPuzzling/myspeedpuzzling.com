// The "Pages & settings" lists of the page search (nav-search.json, MENU-03) for the whole tab: one per URL, shared by
// both search fields and kept across Turbo visits, fetched when a field is focused (or typed in before that).
//
// - A failed load (a non-2xx answer - 5xx during a deploy, 429, a ban page -, a network error, a body that is not the
//   list) keeps nothing: the next focus or keystroke asks again, never the load itself - no retry loop, and whoever
//   waits for the list is told only when it is there (review F2: a 503 used to make update() and the load call each
//   other forever, a network error to start a new fetch per round).
// - A list of another deploy (its `v` differs from the `?v=` the page asked for: both deploys answer for a moment
//   during a blue-green rollout) is used, but kept only OTHER_VERSION_KEEP_MS - like its own max-age - so a later
//   search asks again and gets this deploy's list (review F10).
//
// Pure: fetch and the clock are handed in (tests/nav-search-harness.mjs runs it under node).

/** How long a list of another deploy is kept (NavigationSearchIndexController::OTHER_VERSION_MAX_AGE) */
export const OTHER_VERSION_KEEP_MS = 60_000;

/** The version a list URL asks for (`?v=`), or null */
export function askedVersion(url) {
    try {
        return new URL(String(url), 'http://localhost').searchParams.get('v');
    } catch (e) {
        return null;
    }
}

function isList(data) {
    return Boolean(data) && typeof data === 'object' && Array.isArray(data.entries);
}

/**
 * @param {{fetchImpl: (url: string, options: object) => Promise<{ok: boolean, json: () => Promise<any>}>, now?: () => number}} options
 */
export function createNavSearchLists({ fetchImpl, now = () => Date.now() } = {}) {
    // url => {data, waiting: Set<function>, keepUntil}
    const lists = new Map();

    function stale(entry) {
        return entry.keepUntil !== null && now() >= entry.keepUntil;
    }

    function settle(url, entry, data) {
        const waiting = [...entry.waiting];
        entry.waiting.clear();

        if (!isList(data)) {
            if (lists.get(url) === entry) {
                lists.delete(url);
            }

            return null;
        }

        entry.data = data;
        const asked = askedVersion(url);

        if (asked !== null && data.v !== asked) {
            entry.keepUntil = now() + OTHER_VERSION_KEEP_MS;
        }

        waiting.forEach((ready) => {
            try {
                ready(data);
            } catch (e) {
                // one field failing never keeps the list from the other
            }
        });

        return data;
    }

    function start(url) {
        const entry = { data: null, waiting: new Set(), keepUntil: null, promise: null };
        lists.set(url, entry);

        // No cookies: the list is the same public JSON for everybody (stateless firewall)
        entry.promise = Promise.resolve()
            .then(() => fetchImpl(url, { credentials: 'omit', headers: { Accept: 'application/json' } }))
            .then((response) => (response && response.ok ? response.json() : null))
            .then((data) => settle(url, entry, data), () => settle(url, entry, null));

        return entry;
    }

    function current(url) {
        const entry = lists.get(url);

        if (entry && entry.data !== null && stale(entry)) {
            lists.delete(url);

            return undefined;
        }

        return entry;
    }

    return {
        /** The loaded list (still to be kept), or null - never starts a load */
        get(url) {
            const entry = current(url);

            return entry && entry.data !== null ? entry.data : null;
        },

        /**
         * The list when it is there; else null, and a load is started unless one is on its way. `ready` (one function
         * per field, kept once) runs when this load brings the list - never when it fails.
         */
        want(url, ready = null) {
            let entry = current(url);

            if (entry && entry.data !== null) {
                return entry.data;
            }

            if (!entry) {
                entry = start(url);
            }

            if (typeof ready === 'function') {
                entry.waiting.add(ready);
            }

            return null;
        },

        /** The load in flight (tests) */
        pending(url) {
            const entry = lists.get(url);

            return entry && entry.data === null ? entry.promise : null;
        },
    };
}

/**
 * What a field's update() searches: the list, or null while it is not there - then a typed query asks for it (one
 * load at a time; a failed one is asked for again by the next update, never by itself).
 */
export function listForQuery(lists, url, query, ready) {
    if (!url) {
        return null;
    }

    return String(query ?? '').trim() !== '' ? lists.want(url, ready) : lists.get(url);
}
