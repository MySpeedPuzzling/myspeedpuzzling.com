// Runs the real assets/controllers/player_results_controller.js with the real Turbo (pinned 8.0.23) and Stimulus in
// jsdom, on a Results page this harness serves (the markup the page's templates render, reduced to what the controller
// reads), and prints {case: facts} as JSON for tests/PlayerResultsControllerScriptTest.php - the PROF review's races
// (docs/features/navigation/delivery/reviews/PROF.md F2-F5 + Q5):
//
// - F2: Show more's stream belongs to the list and the page it was asked for - a tab, a visit or the page leaving
//   aborts it, a late answer for another list is dropped (also when the network ignores the abort);
// - F3: a Show all superseded by another change never leaves the list "loading";
// - F4: a click while the search still waits takes the typed text along (or drops it with the search);
// - F5: a list answer without the list becomes a full visit, never "Content missing";
// - Q5: "Show from the start" loads the list from its first row and replaces the entry.
//
// Usage: node tests/player-results-controller-harness.mjs <case> [controller path relative to this file - a mutant, for
// mutation checks] - one case per process (Turbo is a singleton).
// fetch is a stub: every request answers from the routes below, after the delay the case sets; it honours the abort
// signal unless the case says the network ignores it.

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const ORIGIN = 'https://msp.test';
const PAGE = '/en/player-results/abc';
const PAGE_SIZE = 50;
const ROWS = 160;
const caseName = process.argv[2] || '';

const errors = [];
const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', (error) => {
    const text = String(error && error.stack ? error.stack : error);

    if (!text.includes('Not implemented')) {
        errors.push(text);
    }
});
virtualConsole.on('error', (...args) => errors.push(args.map(String).join(' ')));
process.on('unhandledRejection', (reason) => errors.push('unhandled: ' + String(reason && reason.stack ? reason.stack : reason)));

// ---- The server ----------------------------------------------------------------------------------------------------

/** The list an address shows: its tab, sort and search name the rows, `page` how many */
function listOf(url) {
    const params = new URL(url, ORIGIN).searchParams;
    const name = [params.get('category') || 'solo', params.get('sort') || 'newest', params.get('q') ? 'q-' + params.get('q') : ''].filter(Boolean).join('-');
    const page = Math.max(1, Number(params.get('page') || 1));
    const query = new URLSearchParams(params);
    query.delete('page');
    query.delete('fragment');

    return { name, page, query: query.toString() };
}

const address = (query, page = 1) => {
    const params = new URLSearchParams(query);

    if (page > 1) {
        params.set('page', String(page));
    }

    const text = params.toString();

    return PAGE + (text === '' ? '' : '?' + text);
};

const row = (list, n) => `<tr id="result-${list}-${n}"><td><a class="ps-puzzle-name" href="/en/puzzle/${list}-${n}">${list} ${n}</a></td></tr>`;

function footer({ name, page, query }) {
    const shown = Math.min(ROWS, page * PAGE_SIZE);

    if (shown >= ROWS) {
        return '<div id="player-results-more"></div>';
    }

    return '<div id="player-results-more">'
        + `<a href="${address(query, page + 1)}" data-results-more data-action="player-results#more">Show more</a>`
        + `<a href="${address(query, Math.ceil(ROWS / PAGE_SIZE))}" data-results-all data-action="player-results#all">Show all</a>`
        + '</div>';
}

/** `windowed` (Q5): a full page beyond Show all's limit renders only its last rows - here from row 51 */
function frameHtml(url, windowed = false) {
    const list = listOf(url);
    const first = windowed ? 51 : 1;
    const rows = [];

    for (let n = first; n <= Math.min(ROWS, list.page * PAGE_SIZE); n++) {
        rows.push(row(list.name, n));
    }

    const q = new URLSearchParams(list.query).get('q');

    return '<turbo-frame id="player-results" target="_top">'
        + `<span hidden data-results-state data-url="${address(list.query, list.page)}" data-category="" data-view="" data-sort="" data-filters="${q ? 0 : 0}" data-clear=""></span>`
        + `<a href="${address('category=duo' + (q ? '&q=' + encodeURIComponent(q) : ''))}" data-results-category="duo">Pairs</a>`
        + `<a href="${address('sort=fastest' + (q ? '&q=' + encodeURIComponent(q) : ''))}" data-results-sort="fastest">Fastest</a>`
        + (q ? `<a href="${address('')}" data-results-chip="q">Search: ${q}</a>` : '')
        + (windowed ? `<p data-testid="results-window"><a href="${address(list.query)}" data-results-start>Show from the start</a></p>` : '')
        + `<table><tbody id="player-results-rows">${rows.join('')}</tbody></table>`
        + footer(list)
        + '</turbo-frame>';
}

const resultsPage = (url) => '<!doctype html><html><head><title>Results</title></head><body><main>'
    + '<div data-controller="player-results">'
    + `<form id="player-results-form" method="get" action="${PAGE}"><input type="search" id="player-results-q" name="q" value="${new URL(url, ORIGIN).searchParams.get('q') || ''}">`
    + '<select name="brand"><option value="">All</option><option value="Ravensburger">Ravensburger</option></select></form>'
    + frameHtml(url, listOf(url).page >= 4)
    + '</div><a id="away" href="/en/puzzle/elsewhere">Elsewhere</a></main></body></html>';

const otherPage = (title) => `<!doctype html><html><head><title>${title}</title></head><body><main><h1>${title}</h1></main></body></html>`;

function streamFor(url) {
    const list = listOf(url);
    const rows = [];

    for (let n = (list.page - 1) * PAGE_SIZE + 1; n <= Math.min(ROWS, list.page * PAGE_SIZE); n++) {
        rows.push(row(list.name, n));
    }

    return `<turbo-stream action="append" target="player-results-rows"><template>${rows.join('')}</template></turbo-stream>`
        + `<turbo-stream action="replace" target="player-results-more"><template>${footer(list)}</template></turbo-stream>`;
}

// ---- The network ---------------------------------------------------------------------------------------------------

const requests = [];
// [matcher(url, frame) => bool, {delay, ignoreAbort, status, html}]: the first rule matching a request answers it
const rules = [];

/** A real Response (Turbo tells a response from a URL by `instanceof Response`), with the URL it came from */
function respond({ url, status = 200, html, type = 'text/html; charset=utf-8', redirected = false }) {
    const response = new Response(html, { status, headers: { 'Content-Type': type } });
    Object.defineProperty(response, 'url', { value: url });
    Object.defineProperty(response, 'redirected', { value: redirected });

    return response;
}

function answer(url, frame) {
    const parsed = new URL(url, ORIGIN);

    if (parsed.pathname !== PAGE) {
        return respond({ url: parsed.href, html: otherPage(parsed.pathname === '/en/puzzle/elsewhere' ? 'Elsewhere' : 'Puzzle') });
    }

    if (parsed.searchParams.get('fragment') === 'more') {
        return respond({ url: parsed.href, html: streamFor(parsed.href), type: 'text/vnd.turbo-stream.html; charset=UTF-8' });
    }

    return respond({ url: parsed.href, html: frame ? '<!doctype html><html><body>' + frameHtml(parsed.href) + '</body></html>' : resultsPage(parsed.href) });
}

function stubFetch(url, options = {}) {
    const href = new URL(String(url), ORIGIN).href;
    const frame = new Headers(options.headers || {}).get('Turbo-Frame');
    const record = { url: href.replace(ORIGIN, ''), frame, aborted: false, answered: false };
    requests.push(record);
    const rule = rules.find(([matches]) => matches(href, frame));
    const { delay = 0, ignoreAbort = false, status = null, html = null } = rule ? rule[1] : {};
    const signal = options.signal;

    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            record.answered = true;
            resolve(status !== null ? respond({ url: href, status, html }) : answer(href, frame));
        }, delay);

        if (signal && !ignoreAbort) {
            const abort = () => {
                record.aborted = true;
                clearTimeout(timer);
                reject(new window.DOMException('The operation was aborted.', 'AbortError'));
            };

            if (signal.aborted) {
                abort();
            } else {
                signal.addEventListener('abort', abort, { once: true });
            }
        } else if (signal) {
            signal.addEventListener('abort', () => {
                record.aborted = true;
            }, { once: true });
        }
    });
}

// ---- The browser ---------------------------------------------------------------------------------------------------

const startUrl = caseName === 'start link' ? PAGE + '?page=4' : PAGE;
const dom = new JSDOM(resultsPage(startUrl), { url: ORIGIN + startUrl, pretendToBeVisual: true, virtualConsole });
const { window } = dom;
window.fetch = stubFetch;
window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
window.CSS = { escape: (value) => String(value).replace(/[^a-zA-Z0-9_-]/g, (character) => `\\${character}`) };
window.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};
window.HTMLElement.prototype.scrollIntoView = function scrollIntoView() {};
window.scrollTo = () => {};

for (const key of Object.getOwnPropertyNames(window)) {
    if (!(key in globalThis)) {
        try {
            globalThis[key] = window[key];
        } catch (e) {
            // read-only in node
        }
    }
}

for (const key of ['window', 'document', 'location', 'history', 'navigator', 'fetch', 'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent', 'InputEvent', 'FormData', 'requestAnimationFrame', 'cancelAnimationFrame', 'matchMedia', 'CSS', 'IntersectionObserver', 'MutationObserver', 'sessionStorage', 'Node', 'Element', 'HTMLElement']) {
    Object.defineProperty(globalThis, key, { value: key === 'window' ? window : window[key], configurable: true, writable: true });
}

globalThis.addEventListener = window.addEventListener.bind(window);
globalThis.removeEventListener = window.removeEventListener.bind(window);
globalThis.dispatchEvent = window.dispatchEvent.bind(window);

// The same module instances the controller imports (one Turbo: window.Turbo is the one layer_history drives)
const Turbo = await import('@hotwired/turbo');
const { Application } = await import('@hotwired/stimulus');
const { default: Controller } = await import(process.argv[3] || '../assets/controllers/player_results_controller.js');

const document = window.document;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const application = Application.start(document.documentElement);
application.handleError = (error) => errors.push(String(error && error.stack ? error.stack : error));
application.register('player-results', Controller);
await wait(30);

const controller = () => {
    const element = document.querySelector('[data-controller~="player-results"]');

    return element ? application.getControllerForElementAndIdentifier(element, 'player-results') : null;
};
const click = (selector) => {
    const element = document.querySelector(selector);

    if (!element) {
        throw new Error('Nothing to click: ' + selector);
    }

    element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
};
const type = (text) => {
    const field = document.getElementById('player-results-q');
    field.value = text;
    field.dispatchEvent(new window.Event('input', { bubbles: true }));
};
const rowIds = () => [...document.querySelectorAll('#player-results-rows > tr[id]')].map((tr) => tr.id);
const lists = () => [...new Set(rowIds().map((id) => id.replace(/^result-/, '').replace(/-\d+$/, '')))];
const state = () => {
    const current = controller();

    return {
        url: window.location.pathname + window.location.search,
        historyLength: window.history.length,
        owner: window.history.state ? window.history.state.mspPage || null : null,
        turboStarted: Turbo.session.history.started,
        rows: rowIds().length,
        lists: lists(),
        more: (document.querySelector('a[data-results-more]') || { getAttribute: () => null }).getAttribute('href'),
        connected: current !== null,
        loading: current ? current.loading : null,
        pending: current ? current.pending !== null : null,
        title: document.title,
        contentMissing: document.body.textContent.includes('Content missing'),
        field: (document.getElementById('player-results-q') || {}).value ?? null,
        focus: document.activeElement && document.activeElement.closest ? (document.activeElement.closest('tr[id]') || {}).id || null : null,
    };
};
const listRequests = () => requests.filter((request) => request.url.startsWith(PAGE)).map(({ url, frame, aborted, answered }) => ({ url, frame, aborted, answered }));

const isStream = (url) => url.includes('fragment=more');
const isFrame = (url, frame) => frame === 'player-results';

const cases = {
    // The baseline: the rows of page 2 appended, the entry replaced by ?page=2, the first new row focused
    'more appends': async () => {
        const before = state();
        click('a[data-results-more]');
        await wait(200);

        return { before, after: state(), requests: listRequests() };
    },

    // F2 (a): a tab's list lands before the stream - the stream is aborted, the tab's list stays alone
    'more then tab': async () => {
        rules.push([(url) => isStream(url), { delay: 300 }]);
        const before = state();
        click('a[data-results-more]');
        await wait(20);
        click('a[data-results-category="duo"]');
        await wait(600);

        return { before, after: state(), requests: listRequests() };
    },

    // F2 (a) when the answer arrives anyway (the network ignored the abort): dropped by the list it was asked for
    'more then tab, late answer': async () => {
        rules.push([(url) => isStream(url), { delay: 300, ignoreAbort: true }]);
        const before = state();
        click('a[data-results-more]');
        await wait(20);
        click('a[data-results-category="duo"]');
        await wait(600);

        return { before, after: state(), requests: listRequests() };
    },

    // F2 (b): a visit lands before the stream - the visited page keeps its own entry, Turbo's history runs
    'more then visit': async () => {
        rules.push([(url) => isStream(url), { delay: 300 }]);
        const before = state();
        click('a[data-results-more]');
        await wait(20);
        Turbo.visit('/en/puzzle/elsewhere');
        await wait(600);

        return { before, after: state(), requests: listRequests() };
    },

    'more then visit, late answer': async () => {
        rules.push([(url) => isStream(url), { delay: 300, ignoreAbort: true }]);
        const before = state();
        click('a[data-results-more]');
        await wait(20);
        Turbo.visit('/en/puzzle/elsewhere');
        await wait(600);

        return { before, after: state(), requests: listRequests() };
    },

    // F2: the page leaves without a visit (its element removed) - the request is aborted
    'more then disconnect': async () => {
        rules.push([(url) => isStream(url), { delay: 300 }]);
        const before = state();
        click('a[data-results-more]');
        await wait(20);
        document.querySelector('[data-controller~="player-results"]').remove();
        await wait(500);

        return { before, after: state(), requests: listRequests() };
    },

    // F3: Show all superseded by a sort - nothing stays "loading", the sorted list's Show more works
    'all then sort': async () => {
        rules.push([(url, frame) => isFrame(url, frame) && url.includes('page=4'), { delay: 300 }]);
        click('a[data-results-all]');
        await wait(20);
        click('a[data-results-sort="fastest"]');
        await wait(600);
        const afterSort = state();
        click('a[data-results-more]');
        await wait(200);

        return { afterSort, after: state(), requests: listRequests() };
    },

    // F4: a click within the search's 300 ms takes the typed text along - one load, one entry
    'typing then tab': async () => {
        const before = state();
        type('night');
        await wait(50);
        click('a[data-results-category="duo"]');
        await wait(700);

        return { before, after: state(), requests: listRequests() };
    },

    // F4: the search's own chip drops the typed text with the search
    'typing then search chip': async () => {
        // A list with a search first
        type('night');
        await wait(700);
        const searched = state();
        type('nightfall');
        await wait(50);
        click('a[data-results-chip="q"]');
        await wait(700);

        return { searched, after: state(), requests: listRequests() };
    },

    // F4: Show more within the typing: the typed list up to the next page, loaded into the frame - no stream
    'typing then more': async () => {
        const before = state();
        type('night');
        await wait(50);
        click('a[data-results-more]');
        await wait(700);

        return { before, after: state(), requests: listRequests() };
    },

    // F4: the typing ends while a click's list is still loading - it continues that change instead of undoing it
    'tab then typing': async () => {
        rules.push([(url, frame) => isFrame(url, frame) && url.includes('category=duo'), { delay: 500 }]);
        const before = state();
        click('a[data-results-category="duo"]');
        await wait(20);
        type('night');
        await wait(1200);

        return { before, after: state(), requests: listRequests() };
    },

    // F5: the list's answer holds no list (a 404 page) - a full visit of that answer
    'frame missing': async () => {
        rules.push([(url, frame) => isFrame(url, frame), { status: 404, html: otherPage('Not found') }]);
        const before = state();
        click('a[data-results-sort="fastest"]');
        await wait(600);

        return { before, after: state(), requests: listRequests() };
    },

    // Q5: "Show from the start" above a windowed page: the list from its first row, the entry replaced
    'start link': async () => {
        const before = state();
        click('a[data-results-start]');
        await wait(400);

        return { before, after: state(), requests: listRequests() };
    },
};

const output = {};

try {
    if (!cases[caseName]) {
        throw new Error('Unknown case: ' + caseName);
    }

    output[caseName] = await cases[caseName]();
} catch (error) {
    output[caseName] = { threw: String(error && error.stack ? error.stack : error) };
}

await wait(50);
output[caseName].errors = errors;
process.stdout.write(JSON.stringify(output));
process.exit(0);
