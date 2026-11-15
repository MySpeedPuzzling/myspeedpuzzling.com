// Runs the real Stimulus controller of the import's answers shown in place
// (assets/controllers/data_import_answers_controller.js, with the real @hotwired/stimulus) in jsdom for
// tests/ImportAnswersScriptTest.php: a button of a card is tapped - what is sent, what the card looks like while the
// answer is on its way, and what the screen and the focus are after the answer. The scenarios' names come on stdin
// as a JSON list; one object of observed facts per scenario is printed.
//
// Faked: fetch (every call is recorded and answered as the scenario says, when the scenario says) and
// @hotwired/turbo - `renderStreamMessage` puts replace / update / remove streams in place on the next frame, like
// Turbo; `visit` is recorded.
//
// By hand: echo '["answered"]' | node tests/import-answers-harness.mjs

import { createRequire, registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const stimulusUrl = pathToFileURL(path.join(path.dirname(require.resolve('@hotwired/stimulus/package.json')), 'dist/stimulus.js')).href;
const turboFake = `
    export function renderStreamMessage(html) { globalThis.world.renderStreams(html); }
    export function visit(url, options) { globalThis.world.visits.push({ url: String(url), options }); }
`;

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier === '@hotwired/stimulus') {
            return { url: stimulusUrl, format: 'module', shortCircuit: true };
        }

        if (specifier === '@hotwired/turbo') {
            return { url: 'data:text/javascript,' + encodeURIComponent(turboFake), format: 'module', shortCircuit: true };
        }

        return nextResolve(specifier, context);
    },
});

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/en/import/1/puzzles/probably?part=differs', pretendToBeVisual: true });
const { window } = dom;

for (const name of ['window', 'document', 'MutationObserver', 'Element', 'HTMLElement', 'HTMLFormElement', 'HTMLInputElement', 'HTMLImageElement', 'Node', 'Event', 'CustomEvent', 'FormData', 'URLSearchParams', 'URL']) {
    globalThis[name] = name === 'window' ? window : window[name];
}

// jsdom lays nothing out: what is brought into view is recorded
window.Element.prototype.scrollIntoView = function scrollIntoView() {
    globalThis.world.scrolledTo.push(this.id);
};

const world = {
    scrolledTo: [],
    calls: [],
    visits: [],
    // Each call waits here until the scenario answers it
    pending: [],
    renderStreams(html) {
        window.requestAnimationFrame(() => {
            const holder = document.createElement('div');
            holder.innerHTML = html;

            holder.querySelectorAll('turbo-stream').forEach((stream) => {
                const target = document.getElementById(stream.getAttribute('target'));
                const template = stream.querySelector('template');

                if (target === null) {
                    return;
                }

                if (stream.getAttribute('action') === 'remove') {
                    target.remove();
                } else if (stream.getAttribute('action') === 'update') {
                    target.innerHTML = template.innerHTML;
                } else {
                    target.outerHTML = template.innerHTML;
                }
            });
        });
    },
};
globalThis.world = world;

globalThis.fetch = (url, init) => new Promise((resolve, reject) => {
    world.calls.push({ url: String(url), method: init.method, accept: init.headers.Accept, body: Object.fromEntries(new URLSearchParams(init.body.toString())) });
    world.pending.push({ resolve, reject });
});

const streams = (html) => ({ ok: true, status: 200, redirected: false, url: '', headers: { get: () => 'text/vnd.turbo-stream.html; charset=UTF-8' }, text: async () => html });
const page = (url, html) => ({ ok: true, status: 200, redirected: true, url, headers: { get: () => 'text/html; charset=UTF-8' }, text: async () => html });
const error = (status) => ({ ok: false, status, redirected: false, url: '', headers: { get: () => 'text/html; charset=UTF-8' }, text: async () => '<h1>Error</h1>' });

const { Application } = await import(stimulusUrl);
const { default: AnswersController } = await import('../assets/controllers/data_import_answers_controller.js');

const application = Application.start(window.document.documentElement);
application.register('data-import-answers', AnswersController);

const settle = (ms = 60) => new Promise((resolve) => setTimeout(resolve, ms));

const card = (id) => `
    <div class="card" id="${id}" data-import-answers-scope>
        <form action="/en/import/1/puzzles/decide" method="post" data-import-answer>
            <input type="hidden" name="_token" value="csrf">
            <input type="hidden" name="puzzle" value="${id}">
            <button type="submit" name="action" value="catalogue" data-yes>Yes, this is it</button>
            <button type="submit" name="action" value="left_out" data-no>Don't import</button>
        </form>
        <a href="/chooser/${id}">Something else</a>
    </div>`;

// A screen as the templates render it: the element under the script, a pile with two cards, a form of the screen
// itself, the line that stays in view - and a form the script must leave alone
async function mount() {
    world.calls = [];
    world.visits = [];
    world.pending = [];
    world.scrolledTo = [];
    window.history.replaceState(null, '', '/en/import/1/puzzles/probably?part=differs');
    document.body.innerHTML = `
        <div data-controller="data-import-answers" data-data-import-answers-failed-value="NOT SAVED" data-data-import-answers-working-value="SAVING">
            <div id="import-pile">${card('one')}${card('two')}
                <form id="whole" action="/en/import/1/puzzles/decide" method="post" data-import-answer>
                    <button type="submit" name="action" value="all_in_pile" data-all>Continue</button>
                </form>
            </div>
            <form id="plain" action="/en/import/1/elsewhere" method="post"><button type="submit" data-plain>Plain</button></form>
            <div id="import-answer-said"></div>
        </div>`;
    document.addEventListener('submit', (event) => {
        if (!event.defaultPrevented) {
            world.leftToTheBrowser = event.target.id;
            event.preventDefault();
        }
    }, { once: true });
    world.leftToTheBrowser = null;
    await settle();
}

const tap = (selector) => document.querySelector(selector).click();
const look = (id) => {
    const element = document.getElementById(id);

    if (element === null) {
        return null;
    }

    return {
        busy: element.getAttribute('aria-busy'),
        disabled: [...element.querySelectorAll('button')].map((button) => button.disabled),
        spinners: element.querySelectorAll('[data-import-answers-spinner]').length,
        spinnerText: element.querySelector('[data-import-answers-spinner]')?.textContent ?? null,
        failed: [...element.querySelectorAll('[data-import-answers-failed]')].map((line) => `${line.getAttribute('role')}: ${line.textContent}`),
    };
};
const focused = () => {
    const element = document.activeElement;

    return element === null || element === document.body ? null : `${element.closest('[id]')?.id ?? ''} ${element.tagName.toLowerCase()} ${element.textContent.trim()}`.trim();
};
const said = (text, extra = '') => `<turbo-stream action="update" target="import-answer-said"><template><div class="card">${text}${extra}</div></template></turbo-stream>`;

const scenarios = {
    // A tap: sent once with the tapped button's value, the card waits, the answer takes the card away, focus moves up
    async answered() {
        await mount();
        tap('#one [data-yes]');
        tap('#one [data-yes]');
        tap('#one [data-no]');
        await settle();
        const waiting = look('one');
        const other = look('two');
        const calls = structuredClone(world.calls);
        world.pending.shift().resolve(streams(`<turbo-stream action="remove" target="one"></turbo-stream>${said('1 matched.', '<form data-import-answer><button>Undo the last one</button></form>')}`));
        await settle();

        return { calls, waiting, other, gone: document.getElementById('one') === null, after: look('two'), focused: focused(), said: document.getElementById('import-answer-said').textContent.trim(), visits: world.visits.length, location: window.location.pathname + window.location.search };
    },
    // The card stays where it is (a list): it is rendered again, focus stays on it
    async stays() {
        await mount();
        tap('#two [data-no]');
        await settle();
        world.pending.shift().resolve(streams(`<turbo-stream action="replace" target="two"><template><div class="card" id="two" data-import-answers-scope><span>Not imported</span><form data-import-answer><button data-back>Put back</button></form></div></template></turbo-stream>${said('1 puzzle will not be imported.')}`));
        await settle();

        return { body: world.calls[0].body, focused: focused(), card: look('two') };
    },
    // No connection: the card as it was, its buttons work again, one line says so - and the next tap is sent
    async offline() {
        await mount();
        tap('#one [data-yes]');
        await settle();
        world.pending.shift().reject(new TypeError('Failed to fetch'));
        await settle();
        const failed = look('one');
        const focusedAfter = focused();
        tap('#one [data-yes]');
        await settle();
        const again = look('one');

        return { failed, focused: focusedAfter, again, calls: world.calls.length, cards: document.querySelectorAll('.card[id]').length };
    },
    // An error page (500, a 403 of a token that ran out, a 422 page): never shown instead of the screen
    async refused() {
        await mount();
        tap('#one [data-no]');
        await settle();
        world.pending.shift().resolve(error(500));
        await settle();

        return { failed: look('one'), visits: world.visits.length, cards: document.querySelectorAll('.card[id]').length };
    },
    // The server answers with a page (a redirect to another screen): that page is shown, not asked for again
    async page() {
        await mount();
        tap('#whole [data-all]');
        await settle();
        const waiting = { disabled: document.querySelector('[data-all]').disabled, busy: document.getElementById('whole').getAttribute('aria-busy') };
        world.pending.shift().resolve(page('https://example.test/en/import/1/puzzles', '<html><body>The piles</body></html>'));
        await settle();

        return { waiting, visits: world.visits, calls: world.calls.length };
    },
    // Another screen takes the place (the next puzzle of a round): the address follows, its beginning is focused
    async fresh() {
        await mount();
        tap('#whole [data-all]');
        // An answer for the whole screen is on its way: a card's tap is not sent meanwhile
        tap('#one [data-yes]');
        await settle();
        const calls = world.calls.length;
        world.pending.shift().resolve(streams(`<turbo-stream action="replace" target="import-pile"><template><div id="import-pile" data-import-answers-show data-import-answers-url="/en/import/1/puzzle/2?flow=choose&amp;done=1"><h1>Next puzzle</h1><button>Pick</button></div></template></turbo-stream>${said('')}`));
        await settle();
        const screen = document.getElementById('import-pile');

        return { calls, location: window.location.pathname + window.location.search, focused: focused(), shown: screen.hasAttribute('data-import-answers-show'), scrolledTo: world.scrolledTo, tabindex: screen.querySelector('h1').getAttribute('tabindex') };
    },
    // Two cards tapped one after the other: sent in that order, the second only when the first is answered - and
    // while the screen is rendered again for the first, the second card stays switched off
    async queued() {
        await mount();
        tap('#one [data-yes]');
        tap('#two [data-yes]');
        await settle();
        const first = world.calls.map((call) => call.body.puzzle);
        world.pending.shift().resolve(streams(`<turbo-stream action="replace" target="import-pile"><template><div id="import-pile">${card('two')}</div></template></turbo-stream>${said('1 matched.')}`));
        await settle();
        const second = world.calls.map((call) => call.body.puzzle);
        const between = look('two');
        world.pending.shift().resolve(streams(`<turbo-stream action="remove" target="two"></turbo-stream>${said('1 matched.')}`));
        await settle();

        return { first, second, between, left: document.querySelectorAll('.card[id]').length, focused: focused() };
    },
    // A form that is not marked is not the script's: the browser (Turbo) sends it
    async plain() {
        await mount();
        tap('#plain [data-plain]');
        await settle();

        return { calls: world.calls.length, leftToTheBrowser: world.leftToTheBrowser };
    },
};

let input = '';

for await (const chunk of process.stdin) {
    input += chunk;
}

const results = [];

for (const name of JSON.parse(input)) {
    try {
        results.push(await scenarios[name]());
    } catch (crash) {
        results.push({ crashed: String(crash?.stack ?? crash) });
    }
}

process.stdout.write(JSON.stringify(results));
process.exit(0);
