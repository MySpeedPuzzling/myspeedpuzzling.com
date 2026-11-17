// Analytics Studio (docs/features/competitions-management/analytics-studio.md §7, S3) - the page adapter: the only module
// with the real DOM, the network, timers and history. It wires the pure modules together:
// - refresh.js fetches state / analytics / entrant / predictions (the planner decides when);
// - model.js applyAction() is the view reducer, keys.js turns key presses into its actions, url.js keeps the view in
//   the address (history.replaceState);
// - render.js draws everything (created at the first state - before it the shell's "Loading…" stays).
// Clicks are read from the renderer's `data-as-*` attributes. On phones the entrant sheet gets a history entry, so the
// back button closes it. No Stimulus, no Turbo (the entry is standalone - AnalyticsStudioBundleTest).

import { applyAction, initialView } from './model.js';
import { keyAction } from './keys.js';
import { decodeView, encodeView, resolveView } from './url.js';
import { AnalyticsStudioRefresh } from './refresh.js';
import { createAnalyticsStudioRenderer } from './render.js';

const FETCH_TIMEOUT_MS = 15000;
const SELECT_DEBOUNCE_MS = 150;
const PHONE = '(max-width: 767.98px)';

function readJson(text) {
    try {
        const value = JSON.parse(text ?? 'null');

        return value !== null && typeof value === 'object' ? value : {};
    } catch {
        return {};
    }
}

/**
 * GET a JSON answer: {status: ok|gone|error, body, sentAtMs, receivedAtMs}. No cookie - the answers are stateless.
 */
export async function getJson(url, { noStore = false } = {}) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller !== null ? setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS) : null;
    const sentAtMs = Date.now();

    try {
        const response = await fetch(url, {
            cache: noStore ? 'no-store' : 'default',
            credentials: 'omit',
            headers: { Accept: 'application/json' },
            signal: controller?.signal,
        });
        const receivedAtMs = Date.now();

        if (response.status === 404) {
            return { status: 'gone' };
        }

        if (!response.ok) {
            return { status: 'error' };
        }

        return { status: 'ok', body: await response.json(), sentAtMs, receivedAtMs };
    } catch {
        return { status: 'error' };
    } finally {
        if (timer !== null) {
            clearTimeout(timer);
        }
    }
}

function withQuery(url, params) {
    const query = new URLSearchParams(params).toString();

    return query === '' ? url : `${url}${url.includes('?') ? '&' : '?'}${query}`;
}

export function startAnalyticsStudio(root, { window: win = window } = {}) {
    const doc = root.ownerDocument;
    const data = root.dataset;
    const labels = readJson(data.labels);
    const locale = data.locale || 'en';
    const placeholder = data.entryPlaceholder || '';
    const gone = doc.querySelector('[data-analytics-studio-gone]');
    const phone = typeof win.matchMedia === 'function' ? win.matchMedia(PHONE) : { matches: false };

    let view = initialView(decodeView(win.location.search));
    let viewResolved = false;
    let renderer = null;
    let shownKeys = [];
    let selectTimer = null;

    const model = { state: null, analytics: null, entrant: null, predictions: null };

    const refresh = new AnalyticsStudioRefresh({
        serverNowMs: Number(data.serverNowMs) || null,
        fetchState: async () => {
            const answer = await getJson(data.stateUrl, { noStore: true });

            return answer.status === 'ok' ? { ...answer, state: answer.body } : answer;
        },
        fetchAnalytics: async (hash) => {
            const answer = await getJson(withQuery(data.analyticsUrl, { v: hash }));

            return answer.status === 'ok' ? { status: 'ok', analytics: answer.body } : answer;
        },
        fetchEntrant: async (key, hash) => {
            const answer = await getJson(withQuery(data.entrantUrl.replace(placeholder, encodeURIComponent(key)), { v: hash }));

            return answer.status === 'ok' ? { status: 'ok', entrant: answer.body } : answer;
        },
        fetchPredictions: async (keys, hash) => {
            const answer = await getJson(withQuery(data.predictionsUrl, { entries: keys.join(','), v: hash }));

            return answer.status === 'ok' ? { status: 'ok', predictions: answer.body } : answer;
        },
        onState: (state) => {
            model.state = state;

            if (gone !== null) {
                gone.hidden = true;
            }

            if (!viewResolved) {
                viewResolved = true;
                const resolved = resolveView({ puzzle: view.puzzle, e: view.selected, sort: view.sort, q: view.query }, state);
                view = { ...view, puzzle: resolved.puzzle, selected: resolved.e, open: resolved.e !== null && view.open };
                refresh.select(view.selected);
            }

            render();
        },
        onAnalytics: (analytics) => {
            model.analytics = analytics;
            render();
        },
        onEntrant: (entrant) => {
            model.entrant = entrant;
            render();
        },
        onPredictions: (predictions) => {
            model.predictions = predictions;
            render();
        },
        onGone: () => {
            if (gone !== null) {
                gone.hidden = false;
            }
        },
    });

    function render() {
        if (model.state === null) {
            return;
        }

        if (renderer === null) {
            renderer = createAnalyticsStudioRenderer({ document: doc, root, labels, locale });
            renderer.search.addEventListener('input', () => dispatch({ type: 'search', query: renderer.search.value }));
        }

        const result = renderer.render({ ...model, view, nowMs: refresh.serverNow() });
        shownKeys = result.shown.map((row) => row.key);
    }

    function syncUrl() {
        const search = encodeView({ puzzle: view.puzzle, e: view.selected, sort: view.sort, q: view.query });

        if (search !== win.location.search) {
            try {
                win.history.replaceState(win.history.state, '', `${win.location.pathname}${search}${win.location.hash}`);
            } catch {
                // a sandboxed frame - the view just is not kept in the address
            }
        }
    }

    function scrollSelectedIntoView() {
        const row = root.querySelector('.as-row--selected');

        if (row !== null && typeof row.scrollIntoView === 'function') {
            row.scrollIntoView({ block: 'nearest' });
        }
    }

    function dispatch(action) {
        const before = view;
        const result = applyAction(view, action, { keys: shownKeys, state: model.state });
        view = result.view;

        if (result.effect === 'focusSearch' && renderer !== null) {
            renderer.search.focus();
            renderer.search.select();
        } else if (result.effect === 'blurSearch' && renderer !== null) {
            renderer.search.blur();
        }

        if (view.selected !== before.selected) {
            // Holding ↓ walks the list - the entrant answer only for where it stops
            clearTimeout(selectTimer);
            selectTimer = setTimeout(() => refresh.select(view.selected), action.type === 'move' ? SELECT_DEBOUNCE_MS : 0);
        }

        if (view.compare.join(',') !== before.compare.join(',')) {
            refresh.setCompare(view.compare);
        }

        // Phones: the open sheet is a history entry - the back button closes it
        const sheetOpen = view.open && view.selected !== null;
        const sheetWasOpen = before.open && before.selected !== null;

        if (phone.matches && sheetOpen && !sheetWasOpen) {
            try {
                win.history.pushState({ analyticsStudioSheet: true }, '', win.location.href);
            } catch {
                // no history entry - the × still closes it
            }
        } else if (!sheetOpen && sheetWasOpen && win.history.state?.analyticsStudioSheet === true) {
            win.history.back();
        }

        syncUrl();
        render();

        if (action.type === 'move') {
            scrollSelectedIntoView();
        }
    }

    // ---- input

    doc.addEventListener('keydown', (event) => {
        if (renderer === null) {
            return;
        }

        const typing = doc.activeElement === renderer.search;

        // Another field (the sort select): only Escape is ours
        if (!typing && event.target instanceof win.HTMLElement && event.target.closest('select, input, textarea') !== null && event.key !== 'Escape') {
            return;
        }

        const action = keyAction(event, { typing, query: view.query, help: view.help });

        if (action === null) {
            return;
        }

        event.preventDefault();
        dispatch(action);
    });

    root.addEventListener('click', (event) => {
        const target = event.target instanceof win.Element ? event.target : null;

        if (target === null) {
            return;
        }

        // A profile link opens as it is
        if (target.closest('a[href]') !== null) {
            return;
        }

        const compare = target.closest('[data-as-compare]');

        if (compare !== null) {
            dispatch({ type: 'toggleCompare', key: compare.getAttribute('data-as-compare') });

            return;
        }

        const slot = target.closest('[data-as-slot]');

        if (slot !== null) {
            dispatch({ type: 'slot', label: slot.getAttribute('data-as-slot') });

            return;
        }

        const filter = target.closest('[data-as-filter]');

        if (filter !== null) {
            dispatch({ type: 'filter', filter: filter.getAttribute('data-as-filter') });

            return;
        }

        const tab = target.closest('[data-as-tab]');

        if (tab !== null && tab !== root) {
            dispatch({ type: 'tab', tab: tab.getAttribute('data-as-tab') });

            return;
        }

        const button = target.closest('[data-as-action]');

        if (button !== null) {
            const name = button.getAttribute('data-as-action');

            if (name === 'close') {
                dispatch({ type: 'escape' });
            } else if (['clearCompare', 'toggleHelp', 'closeHelp'].includes(name)) {
                dispatch({ type: name });
            }

            return;
        }

        // The compare bar's count opens the compare table (on a phone: as the sheet)
        if (target.closest('.as-compare-bar__count') !== null && view.compare.length >= 2) {
            dispatch({ type: 'select', key: view.selected ?? view.compare[0] });

            return;
        }

        // A click on the help overlay's backdrop closes it
        if (target.classList.contains('as-help')) {
            dispatch({ type: 'closeHelp' });

            return;
        }

        const entry = target.closest('[data-as-entry]');

        if (entry !== null) {
            dispatch({ type: 'select', key: entry.getAttribute('data-as-entry') });
        }
    });

    root.addEventListener('change', (event) => {
        if (event.target instanceof win.HTMLSelectElement && event.target.hasAttribute('data-as-sort')) {
            dispatch({ type: 'sort', sort: event.target.value });
        }
    });

    // Back on a phone: the sheet closes (its history entry is gone); the address follows the view again
    win.addEventListener('popstate', () => {
        view = { ...view, open: false };
        syncUrl();
        render();
    });

    doc.addEventListener('visibilitychange', () => {
        if (doc.visibilityState === 'visible') {
            refresh.onVisible();
        }
    });

    // The clock and the reveal countdowns, every second between renders
    setInterval(() => {
        if (renderer !== null && doc.visibilityState !== 'hidden') {
            renderer.renderClock(refresh.serverNow());
        }
    }, 1000);

    refresh.start();

    return { refresh, dispatch, view: () => view };
}
