// Runs the add/edit-time form's real Stimulus controller (assets/controllers/submit_prevention_controller.js, with the
// real @hotwired/stimulus and assets/photo_upload.js) in jsdom for tests/UnreadablePhotoFormScriptTest.php: a photo the
// phone cannot read is picked, refused by the upload's start, or still in the form at Save - and what the form then
// shows, sends and reports. The scenarios' names come on stdin as a JSON list; one object of observed facts per
// scenario is printed.
//
// Faked: the file inputs' `files` (jsdom cannot set them), fetch (the upload's start), XMLHttpRequest (the PUT to object
// storage), navigator (online, sendBeacon = the telemetry). A submit that reaches the document was not stopped by the
// controller - it is recorded as "sent" with what the request would carry.
//
// By hand: echo '["pick-empty"]' | node tests/unreadable-photo-form-harness.mjs

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

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/en/puzzle-add', pretendToBeVisual: true });
const { window } = dom;

const world = { online: true, reports: [], starts: [], startAnswer: null, puts: 0 };

for (const name of ['window', 'document', 'MutationObserver', 'Element', 'HTMLElement', 'HTMLInputElement', 'HTMLFormElement', 'Node', 'Event', 'CustomEvent']) {
    globalThis[name] = name === 'window' ? window : window[name];
}

Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: {
        get onLine() {
            return world.online;
        },
        sendBeacon(url, blob) {
            blob.text().then((body) => world.reports.push(JSON.parse(body)));

            return true;
        },
    },
});

globalThis.fetch = async (url, init) => {
    world.starts.push(JSON.parse(init.body));

    if (world.startAnswer === 'network') {
        throw new TypeError('Failed to fetch');
    }

    const { status, body } = world.startAnswer;

    return { ok: status >= 200 && status < 300, status, json: async () => body };
};

globalThis.XMLHttpRequest = class {
    constructor() {
        this.upload = {};
        this.status = 0;
    }

    open() {}

    setRequestHeader() {}

    send() {
        world.puts += 1;
        setTimeout(() => {
            this.status = 200;
            this.onload?.();
        }, 0);
    }

    abort() {
        this.onabort?.();
    }
};

const { Application } = await import(stimulusUrl);
const { default: SubmitPreventionController } = await import('../assets/controllers/submit_prevention_controller.js');

const application = Application.start(window.document.documentElement);
application.register('submit-prevention', SubmitPreventionController);

const wait = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

async function settle(rounds = 6) {
    for (let i = 0; i < rounds; i++) {
        await wait(0);
    }
}

async function until(predicate, timeoutMs = 4000) {
    const startedAt = Date.now();

    while (!predicate()) {
        if (Date.now() - startedAt > timeoutMs) {
            throw new Error('Waited in vain');
        }

        await wait(10);
    }

    await settle();
}

// ---------------------------------------------------------------------------------------------------------- files

const photo = (bytes) => new File([new Uint8Array(bytes)], 'IMG_20261114_101500.jpg', { type: 'image/jpeg' });

// Says it has bytes, and the browser cannot read a single one
const unreadablePhoto = () => ({
    name: 'IMG_20261114_101500.jpg',
    type: 'image/jpeg',
    size: 2_345_678,
    slice: () => ({
        arrayBuffer: () => Promise.reject(Object.assign(new Error('The requested file IMG_20261114_101500.jpg could not be read'), { name: 'NotReadableError' })),
    }),
});

// A read that never answers (a photo still coming from the cloud)
const slowPhoto = () => ({
    name: 'IMG_20261114_101500.jpg',
    type: 'image/jpeg',
    size: 2_345_678,
    slice: () => ({ arrayBuffer: () => new Promise(() => {}) }),
});

// ----------------------------------------------------------------------------------------------------------- form

const FIELDS = ['finishedPuzzlesPhoto', 'puzzlePhoto'];

function buildForm({ uploads = true } = {}) {
    const form = window.document.createElement('form');
    form.setAttribute('action', '/en/puzzle-add');
    form.setAttribute('method', 'post');
    form.setAttribute('data-controller', 'submit-prevention');

    const values = {
        'saving-text': 'SAVING',
        'drop-file-text': 'DROP A FILE',
        'save-failed-text': 'NETWORK',
        'photo-unreadable-text': 'UNREADABLE',
        'photo-unreadable-required-text': 'UNREADABLE REQUIRED',
        'save-failed-photo-text': 'PHOTO',
        'save-failed-either-text': 'EITHER',
        'save-without-photo-text': 'WITHOUT',
        ...(uploads ? {
            'upload-url': '/en/photo-upload',
            'upload-csrf-token': 'csrf',
            'report-url': '/-/photo-upload-report',
            'retrying-text': 'RETRYING',
            'upload-texts': JSON.stringify({ uploading: 'UPLOADING %percent%', uploading_slow: 'SLOW %percent%', retrying: 'RETRY', waiting: 'WAITING', done: 'DONE', with_form: 'WITH FORM' }),
        } : {}),
    };

    for (const [name, value] of Object.entries(values)) {
        form.setAttribute(`data-submit-prevention-${name}-value`, value);
    }

    form.innerHTML = FIELDS.map((field) => `
        <div data-field="${field}">
            <div class="file-drop-area">
                <div class="file-drop-icon"><div class="file-drop-preview"><img alt=""></div></div>
                <span class="file-drop-message">an-earlier-photo.jpg</span>
                <input type="file" class="file-drop-input" name="puzzle_add_form[${field}]">
                <button type="button" class="file-drop-btn">Choose</button>
            </div>
        </div>`).join('') + `
        <input type="text" name="puzzle_add_form[comment]" value="typed by the player">
        <button type="submit" data-submit-prevention-target="submit"><span data-submit-prevention-target="label">Save</span></button>`;

    const inputs = {};

    for (const field of FIELDS) {
        const input = form.querySelector(`input[name="puzzle_add_form[${field}]"]`);
        let files = [];

        Object.defineProperty(input, 'files', { configurable: true, get: () => files, set: (value) => { files = [...value]; } });
        Object.defineProperty(input, 'value', {
            configurable: true,
            get: () => (files[0] ? 'C:\\fakepath\\' + files[0].name : ''),
            set: (value) => {
                if (value === '') {
                    files = [];
                }
            },
        });

        inputs[field] = {
            input,
            // `silently`: the file is in the form without the page having noticed (an old page, another path)
            pick(file, silently = false) {
                files = [file];

                if (!silently) {
                    input.dispatchEvent(new window.Event('change', { bubbles: true }));
                }
            },
        };
    }

    window.document.body.append(form);

    return { form, inputs };
}

const sent = [];

// Reached only by a submit the controller let through: what the request would carry
window.document.addEventListener('submit', (event) => {
    const form = event.target;

    sent.push({
        files: Object.fromEntries(FIELDS.map((field) => {
            const input = form.querySelector(`input[name="puzzle_add_form[${field}]"]`);

            return [field, input.disabled || input.files.length === 0 ? null : input.files[0].size];
        })),
        tokens: Object.fromEntries([...form.querySelectorAll('input[type="hidden"]')].filter((hidden) => hidden.name.startsWith('photo_stash[') && hidden.value !== '').map((hidden) => [hidden.name, hidden.value])),
        comment: form.querySelector('input[name="puzzle_add_form[comment]"]').value,
    });
    event.preventDefault();
});

function look(form) {
    const text = (selector) => [...form.querySelectorAll(selector)].map((element) => element.textContent.trim());
    const alert = form.querySelector('[data-save-failed]');

    return {
        notes: Object.fromEntries(FIELDS.map((field) => [field, text(`[data-field="${field}"] [data-photo-unreadable]`)])),
        statuses: Object.fromEntries(FIELDS.map((field) => [field, text(`[data-field="${field}"] [data-photo-upload-status]`)])),
        areas: Object.fromEntries(FIELDS.map((field) => [field, form.querySelector(`[data-field="${field}"] .file-drop-message`).textContent])),
        previews: form.querySelectorAll('.file-drop-preview').length,
        alert: alert ? alert.childNodes[0].textContent : null,
        saveWithoutButton: alert ? (alert.querySelector('[data-save-without-photo]')?.textContent ?? null) : null,
        buttonDisabled: form.querySelector('button[type="submit"]').disabled,
        label: form.querySelector('[data-submit-prevention-target="label"]').textContent,
        files: Object.fromEntries(FIELDS.map((field) => [field, form.querySelector(`input[name="puzzle_add_form[${field}]"]`).files.length])),
    };
}

const save = (form) => form.requestSubmit();

function answered(form, detail) {
    form.dispatchEvent(new window.CustomEvent('turbo:submit-end', { bubbles: true, detail }));
}

const lostAtOnce = () => ({ success: false, error: Object.assign(new TypeError('Failed to fetch'), {}) });
const saved = () => ({ success: true, fetchResponse: { statusCode: 200, redirected: true, isHTML: true } });

function facts(form, more = {}) {
    return { ...look(form), sent: [...sent], reports: [...world.reports], starts: [...world.starts], puts: world.puts, ...more };
}

// ------------------------------------------------------------------------------------------------------ scenarios

const startOk = { status: 200, body: { token: 'a'.repeat(32), url: 'https://store.test/tmp-uploads/x', headers: { 'Content-Type': 'image/jpeg' }, expiresAtMs: Date.now() + 900000 } };

const scenarios = {
    // The incident: the phone hands over a photo of 0 bytes
    async 'pick-empty'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(0));
        await settle();
        const afterPick = look(form);
        save(form);
        await settle();
        answered(form, saved());
        await settle();

        return facts(form, { afterPick });
    },

    // The box photo of a new puzzle is required: another wording, same handling
    async 'pick-empty-box'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.puzzlePhoto.pick(photo(0));
        await settle();

        return facts(form);
    },

    async 'pick-unreadable'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(unreadablePhoto());
        await settle();
        const afterPick = look(form);
        save(form);
        await settle();

        return facts(form, { afterPick });
    },

    // Nothing changes for a photo that can be read: uploaded in the background, sent as its token
    async 'pick-normal'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000));
        await until(() => look(form).statuses.finishedPuzzlesPhoto[0] === 'DONE');
        const afterPick = look(form);
        save(form);
        await settle();

        return facts(form, { afterPick });
    },

    // A device that does not answer the one-byte read in time is not judged: the upload starts as it always did
    async 'pick-slow-read'() {
        const { form, inputs } = buildForm();
        await settle();
        const startedAt = Date.now();
        inputs.finishedPuzzlesPhoto.pick(slowPhoto());
        await until(() => look(form).statuses.finishedPuzzlesPhoto[0] === 'DONE');

        return facts(form, { tookMs: Date.now() - startedAt });
    },

    // The server's word: `empty` no longer means "send it with the form"
    async 'start-says-empty'() {
        world.startAnswer = { status: 422, body: { error: 'empty' } };
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000));
        await until(() => world.reports.some((report) => report.event === 'photo_dropped'));
        const afterPick = look(form);
        save(form);
        await settle();

        return facts(form, { afterPick });
    },

    // Every other refusal keeps the fallback: the photo goes with the form
    async 'start-says-too-large'() {
        world.startAnswer = { status: 422, body: { error: 'too_large' } };
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000));
        await until(() => look(form).statuses.finishedPuzzlesPhoto[0] === 'WITH FORM');
        const afterPick = look(form);
        save(form);
        await until(() => sent.length === 1);

        return facts(form, { afterPick });
    },

    // The defence at Save: an empty photo the page never noticed - out, and the same tap saves
    async 'save-with-empty-photo-in-form'() {
        const { form, inputs } = buildForm({ uploads: false });
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(0), true);
        save(form);
        await settle();

        return facts(form);
    },

    async 'save-with-unreadable-photo-in-form'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(unreadablePhoto(), true);
        inputs.puzzlePhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);

        return facts(form);
    },

    // The check cannot tell (the read hangs): the photo goes with the form, as before - after the check's own timeout
    async 'save-with-slow-read-in-form'() {
        const { form, inputs } = buildForm({ uploads: false });
        await settle();
        inputs.finishedPuzzlesPhoto.pick(slowPhoto(), true);
        const startedAt = Date.now();
        save(form);
        await settle();
        const whileChecking = look(form);
        await until(() => sent.length === 1);

        return facts(form, { whileChecking, tookMs: Date.now() - startedAt });
    },

    // A save that dies at once with a photo inside the request: not the connection - "Save without the photo"
    async 'save-dies-at-once-with-photo'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();
        const afterFailure = look(form);
        form.querySelector('[data-save-without-photo]').click();
        await settle();
        const afterWithout = look(form);
        answered(form, saved());
        await settle();

        return facts(form, { afterFailure, afterWithout });
    },

    // The same failure after a long wait is what the old message was written for
    async 'save-dies-slowly-with-photo'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        const realNow = Date.now;
        Date.now = () => realNow() + 45000;
        answered(form, lostAtOnce());
        Date.now = realNow;
        await settle();

        return facts(form);
    },

    async 'save-dies-after-seconds-with-photo'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        const realNow = Date.now;
        Date.now = () => realNow() + 4000;
        answered(form, lostAtOnce());
        Date.now = realNow;
        await settle();

        return facts(form);
    },

    async 'save-dies-offline-with-photo'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        world.online = false;
        answered(form, lostAtOnce());
        await settle();

        return facts(form);
    },

    // No photo in the request: the automatic resend, as before
    async 'save-dies-without-photo'() {
        const { form } = buildForm();
        await settle();
        save(form);
        await settle();
        answered(form, lostAtOnce());
        await settle();

        return facts(form);
    },
};

const answers = [];

for (const name of JSON.parse(readFileSync(0, 'utf8'))) {
    world.online = true;
    world.reports = [];
    world.starts = [];
    world.puts = 0;
    world.startAnswer = startOk;
    sent.length = 0;
    window.document.body.innerHTML = '';
    await settle();

    try {
        answers.push(await scenarios[name]());
    } catch (error) {
        answers.push({ crashed: String(error?.stack ?? error) });
    }
}

process.stdout.write(JSON.stringify(answers));
// Timers of the forms left behind (the "still saving" note, a resend) must not keep the run alive
process.exit(0);
