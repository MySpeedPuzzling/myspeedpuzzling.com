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

for (const name of ['window', 'document', 'MutationObserver', 'Element', 'HTMLElement', 'HTMLFormElement', 'HTMLInputElement', 'HTMLImageElement', 'Node', 'Event', 'CustomEvent', 'KeyboardEvent', 'MouseEvent', 'FormData', 'URLSearchParams', 'URL']) {
    globalThis[name] = name === 'window' ? window : window[name];
}

// A browser lets a control's name shadow the form's own property: with `<button name="action">` in it, `form.action`
// is the button, not the address (jsdom does not do this by itself - and every answer form has such a button)
for (const property of ['action', 'method']) {
    const own = Object.getOwnPropertyDescriptor(window.HTMLFormElement.prototype, property);

    Object.defineProperty(window.HTMLFormElement.prototype, property, {
        configurable: true,
        get() {
            return this.elements.namedItem(property) ?? own.get.call(this);
        },
        set(value) {
            own.set.call(this, value);
        },
    });
}

// jsdom lays nothing out: what is brought into view is recorded
window.Element.prototype.scrollIntoView = function scrollIntoView() {
    globalThis.world.scrolledTo.push(this.id);
};

// ... so a scenario says where things are: `data-top` / `data-height` on an element are its box, the window is 700 px
// high, and what the script scrolls by is recorded
window.Element.prototype.getBoundingClientRect = function getBoundingClientRect() {
    const top = Number(this.getAttribute('data-top') ?? 300);
    const height = Number(this.getAttribute('data-height') ?? 0);

    return { top, bottom: top + height, height, left: 0, right: 0, width: 0, x: 0, y: top };
};
Object.defineProperty(window, 'innerHeight', { value: 700, configurable: true });
window.scrollBy = (options) => {
    globalThis.world.scrolledBy.push(Math.round(options.top));
};

const world = {
    scrolledBy: [],
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

const settle = (ms = 200) => new Promise((resolve) => setTimeout(resolve, ms));

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
    world.scrolledBy = [];
    window.history.replaceState(null, '', '/en/import/1/puzzles/probably?part=differs');
    document.body.innerHTML = `
        <div data-controller="data-import-answers" data-data-import-answers-failed-value="NOT SAVED" data-data-import-answers-working-value="SAVING">
            <div id="import-pile">${card('one')}${card('two')}
                <form id="whole" action="/en/import/1/puzzles/decide" method="post" data-import-answer>
                    <button type="submit" name="action" value="all_in_pile" data-all>Continue</button>
                </form>
            </div>
            <form id="plain" action="/en/import/1/elsewhere" method="post"><button type="submit" data-plain>Plain</button></form>
            <form id="person" action="/en/import/1/people" method="post" data-import-answer>
                <input type="radio" name="answer" value="guest" checked><input type="radio" name="answer" value="code">
                <input type="text" name="code" data-action="input->data-import-answers#pick" data-import-answers-picks="code">
                <button type="submit" data-save>Save</button>
            </form>
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
        // The line at the bottom is 90 px and still says what the answer before did; the card's button stands at
        // 650-694 of 700 px - under it
        const saidLine = document.getElementById('import-answer-said');
        saidLine.setAttribute('data-height', '90');
        saidLine.innerHTML = '<div>1 matched.</div>';
        document.querySelector('#one [data-yes]').setAttribute('data-top', '650');
        document.querySelector('#one [data-yes]').setAttribute('data-height', '44');
        tap('#one [data-yes]');
        await settle();
        world.pending.shift().reject(new TypeError('Failed to fetch'));
        await settle();
        const failed = look('one');
        const focusedAfter = focused();
        const inView = { said: saidLine.textContent.trim(), role: saidLine.firstElementChild.getAttribute('role'), scrolledBy: [...world.scrolledBy] };
        tap('#one [data-yes]');
        await settle();
        const again = look('one');

        return { failed, inView, focused: focusedAfter, again, calls: world.calls.length, cards: document.querySelectorAll('.card[id]').length };
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

        return { calls, location: window.location.pathname + window.location.search, focused: focused(), shown: screen.hasAttribute('data-import-answers-show'), scrolledBy: world.scrolledBy, tabindex: screen.querySelector('h1').getAttribute('tabindex') };
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
    // The screen is rendered again around what the player set and did not send (a refused "Yes, these are right",
    // a card answered in a pile): an unticked box stays unticked and is counted again, the answer picked for the
    // pile stays picked, a folded part they opened stays open
    async unsaved() {
        await mount();
        const pile = (boxes) => `<div id="import-pile">${card('one')}
            <form id="grid" action="/en/import/1/puzzles/decide" method="post" data-import-answer>
                <input type="checkbox" id="tile-a" name="cards[a][tick]" value="1" ${boxes}><input type="checkbox" id="tile-b" name="cards[b][tick]" value="1" checked>
                <input type="radio" name="choice" value="unlisted" checked><input type="radio" name="choice" value="left_out">
                <button type="submit" name="action" value="tick_all" data-grid>Yes</button>
            </form>
            <details data-testid="import-pile-see"><summary>See</summary></details>
            <details data-testid="import-pile-not-kept"><summary>Not kept</summary></details>
        </div>`;
        document.getElementById('import-pile').outerHTML = pile('checked');
        let counted = 0;
        document.addEventListener('input', () => { counted += 1; });
        document.getElementById('tile-a').checked = false;
        document.querySelector('input[value="left_out"]').checked = true;
        document.querySelector('[data-testid="import-pile-see"]').setAttribute('open', '');
        tap('#grid [data-grid]');
        await settle();
        const sent = world.calls[0].body;
        // The server's screen: every box ticked, the default answer, everything folded
        world.pending.shift().resolve(streams(`<turbo-stream action="replace" target="import-pile"><template>${pile('checked')}</template></turbo-stream>${said('Needs a closer look. Nothing was changed.')}`));
        await settle();
        const state = () => ({
            ticked: [document.getElementById('tile-a').checked, document.getElementById('tile-b').checked],
            choice: document.querySelector('input[name="choice"]:checked').value,
            open: [...document.querySelectorAll('details')].map((part) => part.hasAttribute('open')),
        });
        const kept = state();
        const focusedAfter = focused();
        // Another screen in its place starts as the server rendered it
        tap('#grid [data-grid]');
        await settle();
        world.pending.shift().resolve(streams(`<turbo-stream action="replace" target="import-pile"><template>${pile('checked').replace('id="import-pile"', 'id="import-pile" data-import-answers-show')}</template></turbo-stream>${said('')}`));
        await settle();

        return { sent, kept, counted, focused: focusedAfter, fresh: state() };
    },
    // The line that stays in view lies over the bottom of the screen: the page keeps room for it, the next card's
    // first button is brought wholly above it - and nothing moves when it already is; "Close" gives focus back
    async room() {
        await mount();
        const line = (height) => `<turbo-stream action="update" target="import-answer-said"><template><div data-import-answers-dismissable><span>1 matched.</span><button type="button" data-action="data-import-answers#dismiss" data-close>Close</button></div></template></turbo-stream>`;
        document.getElementById('import-answer-said').setAttribute('data-height', '90');
        // The next card's button is at 650-694 of a 700 px window: under the 90 px line
        document.querySelector('#two [data-yes]').setAttribute('data-top', '650');
        document.querySelector('#two [data-yes]').setAttribute('data-height', '44');
        tap('#one [data-yes]');
        await settle();
        world.pending.shift().resolve(streams(`<turbo-stream action="remove" target="one"></turbo-stream>${line()}`));
        await settle();
        const under = { scrolledBy: [...world.scrolledBy], padding: document.documentElement.style.scrollPaddingBottom, room: document.querySelector('[data-controller]').style.paddingBottom, focused: focused() };
        document.querySelector('[data-close]').focus();
        tap('[data-close]');
        await settle();
        const closed = { focused: focused(), padding: document.documentElement.style.scrollPaddingBottom, room: document.querySelector('[data-controller]').style.paddingBottom };
        // In view already (300-344): nothing moves
        await mount();
        document.querySelector('#two [data-yes]').setAttribute('data-height', '44');
        tap('#one [data-yes]');
        await settle();
        world.pending.shift().resolve(streams(`<turbo-stream action="remove" target="one"></turbo-stream>${line()}`));
        await settle();
        // An answer that was not taken says why on the card, under the header (at 40): shown below it and focused
        tap('#two [data-no]');
        await settle();
        const before = world.scrolledBy.length;
        world.pending.shift().resolve(streams(`<turbo-stream action="replace" target="two"><template><div class="card" id="two" data-import-answers-scope><div role="alert" data-import-answers-attention data-top="40" data-height="60">No player has this code.</div><form data-import-answer><button>Save</button></form></div></template></turbo-stream>`));
        await settle();

        return { under, closed, inView: world.scrolledBy.slice(0, before), refused: { scrolledBy: world.scrolledBy.slice(before), focused: focused() } };
    },
    // "Undo the last one" in the line brings a card back: that card is brought wholly above the line and its first
    // button focused - and "Close" then keeps focus on it, not on a card that was focused before
    async undone() {
        await mount();
        const saidLine = document.getElementById('import-answer-said');
        saidLine.setAttribute('data-height', '90');
        tap('#one [data-yes]');
        await settle();
        world.pending.shift().resolve(streams(`<turbo-stream action="remove" target="one"></turbo-stream><turbo-stream action="update" target="import-answer-said"><template><div data-import-answers-dismissable><span>1 matched.</span><form action="/en/import/1/puzzles/decide" method="post" data-import-answer data-import-answers-returns="one"><input type="hidden" name="puzzle" value="one"><button type="submit" name="action" value="undecided" data-undo>Undo the last one</button></form></div></template></turbo-stream>`));
        await settle();
        const before = focused();
        world.scrolledBy = [];
        tap('[data-undo]');
        await settle();
        const sent = world.calls[1].body;
        // The screen again, the card back in its place - its button at 650-694, under the 90 px line
        world.pending.shift().resolve(streams(`<turbo-stream action="replace" target="import-pile"><template><div id="import-pile">${card('one').replace('data-yes', 'data-yes data-top="650" data-height="44"')}${card('two')}</div></template></turbo-stream><turbo-stream action="update" target="import-answer-said"><template><div data-import-answers-dismissable><span>Undone.</span><button type="button" data-action="data-import-answers#dismiss" data-close>Close</button></div></template></turbo-stream>`));
        await settle();
        const after = { focused: focused(), scrolledBy: [...world.scrolledBy] };
        tap('[data-close]');
        await settle();

        return { before, sent, after, closed: focused() };
    },
    // The last card of the screen is answered: what the screen says now and its ONE way on are brought into view
    async ended() {
        await mount();
        tap('#one [data-yes]');
        await settle();
        world.pending.shift().resolve(streams(`<turbo-stream action="replace" target="import-pile"><template><div id="import-pile" data-top="73"><h1>Looks like a match</h1><p>Nothing left here.</p><a class="btn btn-primary" href="/next" id="continue">Continue</a></div></template></turbo-stream>${said('1 matched.', '<form data-import-answer><button>Undo the last one</button></form>')}`));
        await settle();

        return { focused: focused(), scrolledBy: world.scrolledBy };
    },
    // An answer for the whole screen that could not be saved is said in the line that stays in view - and the
    // line's "Close", and "No" on a question asked in place, take their part away without sending anything
    async inView() {
        await mount();
        tap('#whole [data-all]');
        await settle();
        world.pending.shift().reject(new TypeError('Failed to fetch'));
        await settle();
        const saidLine = document.getElementById('import-answer-said');
        const failed = { inSaid: saidLine.querySelectorAll('[data-import-answers-failed]').length, inForm: document.querySelectorAll('#whole [data-import-answers-failed]').length, text: saidLine.textContent.trim(), button: document.querySelector('[data-all]').disabled };
        saidLine.innerHTML = '<div data-import-answers-dismissable><span>1 matched.</span><button type="button" data-action="data-import-answers#dismiss" data-close>x</button></div>';
        await settle();
        tap('[data-close]');
        await settle();

        return { failed, closed: saidLine.childElementCount, calls: world.calls.length };
    },
    // Typing a code picks "It is the player with this code" - the code is never sent beside "keep as a guest"
    async code() {
        await mount();
        const field = document.querySelector('#person input[name="code"]');
        field.value = '#abc123';
        field.dispatchEvent(new window.Event('input', { bubbles: true }));
        tap('#person [data-save]');
        await settle();

        return { url: world.calls[0].url, body: world.calls[0].body };
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
