// Runs the real Stimulus controller of "My data"'s cells edited in place (assets/controllers/my_data_edit_controller.js,
// with the real @hotwired/stimulus) in jsdom for tests/MyDataEditScriptTest.php: a cell is opened, typed into, saved,
// cancelled, refused - and what the table then shows and what was sent. The scenarios' names come on stdin as a JSON
// list; one object of observed facts per scenario is printed.
//
// Faked: fetch (the cell endpoint) - every call is recorded with its body and headers and answered as the scenario says.
//
// By hand: echo '["save"]' | node tests/my-data-edit-harness.mjs

import { readFileSync } from 'node:fs';
import { createRequire, registerHooks } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

// The package's "main" is its UMD build - the controller imports named exports, like the bundler gives it
const stimulusUrl = pathToFileURL(path.join(path.dirname(require.resolve('@hotwired/stimulus/package.json')), 'dist/stimulus.js')).href;

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier === '@hotwired/stimulus') {
            return { url: stimulusUrl, format: 'module', shortCircuit: true };
        }

        return nextResolve(specifier, context);
    },
});

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/en/my-data', pretendToBeVisual: true });
const { window } = dom;

for (const name of ['window', 'document', 'MutationObserver', 'Element', 'HTMLElement', 'HTMLInputElement', 'HTMLSelectElement', 'Node', 'Event', 'CustomEvent', 'KeyboardEvent', 'MouseEvent', 'URLSearchParams']) {
    globalThis[name] = name === 'window' ? window : window[name];
}

// jsdom has no CSS.escape - the browser's rule for what the controller escapes (column ids: letters, digits, `:`, `-`)
globalThis.CSS = { escape: (value) => String(value).replace(/[^a-zA-Z0-9_-]/g, (character) => `\\${character}`) };

const world = { calls: [], answer: null };

globalThis.fetch = async (url, init) => {
    world.calls.push({
        url: String(url),
        method: init.method,
        token: init.headers['X-CSRF-Token'],
        body: Object.fromEntries(new URLSearchParams(init.body.toString())),
    });

    if (world.answer === 'network') {
        throw new TypeError('Failed to fetch');
    }

    const { status, body } = world.answer;

    return { ok: status >= 200 && status < 300, status, json: async () => body };
};

const { Application } = await import(stimulusUrl);
const { default: MyDataEditController } = await import('../assets/controllers/my_data_edit_controller.js');

const application = Application.start(window.document.documentElement);
application.register('my-data-edit', MyDataEditController);

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));
const ACTION = 'dblclick->my-data-edit#open keydown->my-data-edit#key';
const STAR_OPTIONS = JSON.stringify([['', 'No rating'], ['1', '1 star'], ['2', '2 stars'], ['3', '3 stars']]);

// The table as templates/my_data/_results.html.twig renders it: one row, the cells that are edited in place and two that are not
async function mount() {
    document.body.innerHTML = `
        <div data-controller="my-data-edit" data-my-data-edit-token-value="csrf-token" data-my-data-edit-open-row-value="OPEN THE ROW"
             data-my-data-edit-failed-value="NOT SAVED" data-my-data-edit-hint-value="ENTER SAVES">
            <table>
                <thead><tr>
                    <th data-column="puzzle"></th><th data-column="brand"></th><th data-column="time"></th><th data-column="date"></th>
                    <th data-column="first_try"></th><th data-column="comment"></th><th data-column="ppm"></th>
                    <th data-column="f:notes"></th><th data-column="f:stars" data-edit-options='${STAR_OPTIONS}'></th>
                </tr></thead>
                <tbody><tr data-row-id="row-1" data-row-url="/en/my-data/result/row-1?return=/en/my-data" data-cell-url="/en/my-data/cell/result/row-1">
                    <th data-column="puzzle"><a href="/en/my-data/result/row-1">Puzzle</a></th>
                    <td data-column="brand">Brand</td>
                    <td data-column="time" data-edit="time" data-value="1:02:03" tabindex="0" data-action="${ACTION}">01:02:03 <span class="badge">Needs verification</span></td>
                    <td data-column="date" data-edit="date" data-value="2026-03-01" tabindex="0" data-action="${ACTION}"><time datetime="2026-03-01">01.03.2026</time></td>
                    <td data-column="first_try" data-edit="check" data-value="0" tabindex="0" data-action="${ACTION}"></td>
                    <td data-column="comment" data-edit="text" data-value="Old comment" tabindex="0" data-action="${ACTION}">Old comment</td>
                    <td data-column="ppm">8.06</td>
                    <td data-column="f:notes" data-edit="text" data-value="" tabindex="0" data-action="${ACTION}"></td>
                    <td data-column="f:stars" data-edit="select" data-value="9" tabindex="0" data-action="${ACTION}">9 stars</td>
                </tr></tbody>
            </table>
        </div>`;
    world.calls = [];
    world.answer = null;

    await settle();
}

const cell = (column) => Array.from(document.querySelectorAll('tbody td')).find((candidate) => candidate.dataset.column === column);
const editor = (column) => cell(column).querySelector('input, select');
const dblclick = (element) => element.dispatchEvent(new window.MouseEvent('dblclick', { bubbles: true }));
const press = (element, key) => element.dispatchEvent(new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));

function type(column, value) {
    const input = editor(column);

    if (input.type === 'checkbox') {
        input.checked = value === '1';
    } else {
        input.value = value;
    }

    return input;
}

// What a cell shows: its text, whether an editor is open in it, the note under it, the elements made in it
function shown(column) {
    const target = cell(column);
    const note = target.querySelector('.my-data-cell-note');
    const link = note?.querySelector('a') ?? null;
    const input = target.querySelector('input, select');

    return {
        text: Array.from(target.childNodes).filter((node) => !(node.classList?.contains('my-data-cell-note') || node.classList?.contains('my-data-editor'))).map((node) => node.textContent).join('').trim(),
        value: target.dataset.value,
        editing: input !== null,
        typed: input === null ? null : (input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value),
        disabled: input?.disabled ?? null,
        note: note === null ? null : Array.from(note.childNodes).filter((node) => node.nodeType === 3).map((node) => node.textContent).join(''),
        link: link === null ? null : { text: link.textContent, href: link.getAttribute('href'), frame: link.dataset.turboFrame },
        elements: Array.from(target.querySelectorAll('*')).map((element) => element.tagName.toLowerCase() + (element.className ? '.' + String(element.className).split(' ')[0] : '')),
    };
}

const scenarios = {
    // A double-click opens the editor with what the cell holds; Enter sends that ONE field with what the cell showed
    async save() {
        dblclick(cell('comment'));
        const opened = shown('comment');

        type('comment', 'New comment');
        world.answer = { status: 200, body: { status: 'saved', cells: { comment: { value: 'New comment', text: 'New comment' } } } };
        press(editor('comment'), 'Enter');
        await settle();

        return { opened, calls: world.calls, after: shown('comment'), focused: document.activeElement === cell('comment') };
    },

    // Enter and F2 on a focused cell open it too; any other key does not
    async keyboard() {
        press(cell('comment'), 'a');
        const afterLetter = shown('comment').editing;

        press(cell('comment'), 'Enter');
        const afterEnter = shown('comment').editing;
        press(editor('comment'), 'Escape');

        press(cell('date'), 'F2');

        return { afterLetter, afterEnter, afterEscape: shown('comment'), date: { editing: shown('date').editing, type: editor('date').type, typed: shown('date').typed } };
    },

    // Escape puts back exactly what the cell showed - the <time> element, the badge
    async cancel() {
        dblclick(cell('time'));
        const opened = shown('time');

        type('time', '9:99:99');
        press(editor('time'), 'Escape');

        dblclick(cell('date'));
        type('date', '2020-01-01');
        press(editor('date'), 'Escape');

        return { opened, time: shown('time'), date: shown('date'), calls: world.calls };
    },

    // Enter without a change saves nothing
    async unchanged() {
        dblclick(cell('comment'));
        press(editor('comment'), 'Enter');
        await settle();

        return { calls: world.calls, after: shown('comment') };
    },

    // The answer carries every cell of the row that changed with it: the time, its label, the pieces per minute
    async rowCells() {
        dblclick(cell('time'));
        type('time', '1:00:00');
        world.answer = { status: 200, body: { status: 'saved', cells: {
            time: { value: '1:00:00', text: '01:00:00', badge: 'Needs verification' },
            ppm: { value: '', text: '8.33' },
            first_try: { value: '1', text: 'Yes' },
            // No such cell in the row: left out
            source: { value: '', text: 'Import' },
        } } };
        press(editor('time'), 'Enter');
        await settle();

        return { calls: world.calls, time: shown('time'), ppm: shown('ppm'), firstTry: shown('first_try') };
    },

    // A tick: the checkbox, Enter, the icon
    async check() {
        dblclick(cell('first_try'));
        const opened = { type: editor('first_try').type, typed: shown('first_try').typed };

        type('first_try', '1');
        world.answer = { status: 200, body: { status: 'saved', cells: { first_try: { value: '1', text: 'Yes' } } } };
        press(editor('first_try'), 'Enter');
        await settle();

        return { opened, calls: world.calls, after: shown('first_try') };
    },

    // A value of an own field travels as my_fields[…]; a select offers the header's options and keeps a value that is no option any more
    async ownFields() {
        dblclick(cell('f:notes'));
        type('f:notes', 'top shelf');
        world.answer = { status: 200, body: { status: 'saved', cells: { 'f:notes': { value: 'top shelf', text: 'top shelf' } } } };
        press(editor('f:notes'), 'Enter');
        await settle();

        dblclick(cell('f:stars'));
        const select = editor('f:stars');
        const options = Array.from(select.options).map((option) => [option.value, option.textContent, option.selected]);

        type('f:stars', '2');
        world.answer = { status: 200, body: { status: 'saved', cells: { 'f:stars': { value: '2', text: '2 stars' } } } };
        press(select, 'Enter');
        await settle();

        return { calls: world.calls, notes: shown('f:notes'), options, stars: shown('f:stars') };
    },

    // Changed meanwhile: nothing saved, the cell shows what is stored now and says so; the next edit starts from it
    async conflict() {
        dblclick(cell('comment'));
        type('comment', 'Mine');
        world.answer = { status: 409, body: { status: 'changed', message: 'CHANGED MEANWHILE', cell: { value: 'Theirs', text: 'Theirs' } } };
        press(editor('comment'), 'Enter');
        await settle();
        const after = shown('comment');

        dblclick(cell('comment'));
        const reopened = shown('comment');

        type('comment', 'Mine after all');
        world.answer = { status: 200, body: { status: 'saved', cells: { comment: { value: 'Mine after all', text: 'Mine after all' } } } };
        press(editor('comment'), 'Enter');
        await settle();

        return { after, reopened, calls: world.calls, saved: shown('comment') };
    },

    // A refusal: the editor stays open with what was typed and the reason under it
    async refused() {
        dblclick(cell('time'));
        type('time', '4:75:00');
        world.answer = { status: 422, body: { status: 'refused', message: 'ENTER THE TIME AS', row: false } };
        press(editor('time'), 'Enter');
        await settle();
        const after = shown('time');

        // Fixed and saved: the note goes
        type('time', '4:15:00');
        world.answer = { status: 200, body: { status: 'saved', cells: { time: { value: '4:15:00', text: '04:15:00', badge: null } } } };
        press(editor('time'), 'Enter');
        await settle();

        return { after, calls: world.calls, saved: shown('time') };
    },

    // A refusal that is a question: answered in the row sheet - the way there is offered, nothing is retried
    async question() {
        dblclick(cell('first_try'));
        type('first_try', '1');
        world.answer = { status: 422, body: { status: 'refused', message: 'THIS PUZZLE ALREADY HAS A FIRST TRY', row: true } };
        press(editor('first_try'), 'Enter');
        await settle();

        return { after: shown('first_try'), calls: world.calls };
    },

    // The server down, the connection lost, an answer that is no JSON: said at the cell, nothing lost
    async network() {
        dblclick(cell('comment'));
        type('comment', 'Typed on a train');
        world.answer = 'network';
        press(editor('comment'), 'Enter');
        await settle();
        const offline = shown('comment');

        world.answer = { status: 500, body: null };
        press(editor('comment'), 'Enter');
        await settle();

        return { offline, error: shown('comment'), calls: world.calls.length };
    },

    // One editor at a time: a changed one is never thrown away by opening another cell, an unchanged one closes
    async oneAtATime() {
        dblclick(cell('comment'));
        dblclick(cell('date'));
        const unchanged = { comment: shown('comment').editing, date: shown('date').editing };

        type('date', '2020-01-01');
        dblclick(cell('comment'));

        return { unchanged, comment: shown('comment').editing, date: shown('date') };
    },

    // What comes back is text, whatever it looks like
    async textOnly() {
        const markup = '<img src=x onerror="window.hacked = true"><b>bold</b>';

        dblclick(cell('comment'));
        type('comment', markup);
        world.answer = { status: 200, body: { status: 'saved', cells: { comment: { value: markup, text: markup }, time: { value: '1:02:03', text: markup, badge: markup } } } };
        press(editor('comment'), 'Enter');
        await settle();

        dblclick(cell('f:notes'));
        type('f:notes', 'x');
        world.answer = { status: 422, body: { status: 'refused', message: markup, row: true } };
        press(editor('f:notes'), 'Enter');
        await settle();

        return { comment: shown('comment'), time: shown('time'), note: shown('f:notes'), images: document.querySelectorAll('img, b').length, hacked: window.hacked === true };
    },

    // A cell that is not edited in place, and a row without an address to save to
    async notEditable() {
        dblclick(cell('brand'));
        const brand = shown('brand').editing;

        document.querySelector('tbody tr').removeAttribute('data-cell-url');
        dblclick(cell('comment'));

        return { brand, comment: shown('comment').editing };
    },
};

const answers = [];

for (const name of JSON.parse(readFileSync(0, 'utf8'))) {
    try {
        await mount();
        answers.push(await scenarios[name]());
    } catch (error) {
        answers.push({ crashed: `${error?.stack ?? error}` });
    }
}

process.stdout.write(JSON.stringify(answers));
process.exit(0);
