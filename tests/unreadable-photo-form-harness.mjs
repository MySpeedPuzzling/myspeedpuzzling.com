// Runs the add/edit-time form's real Stimulus controller (assets/controllers/submit_prevention_controller.js, with the
// real @hotwired/stimulus and assets/photo_upload.js) in jsdom for tests/UnreadablePhotoFormScriptTest.php: a photo the
// phone cannot read is picked, refused by the upload's start, or still in the form at Save - and what the form then
// shows, sends and reports. The scenarios' names come on stdin as a JSON list; one object of observed facts per
// scenario is printed.
//
// Faked: the file inputs' `files` (jsdom cannot set them), fetch (the upload's start), XMLHttpRequest (the PUT to object
// storage), navigator (online, sendBeacon = the telemetry). A submit that reaches the document was not stopped by the
// controller - it is recorded as "sent" with what the request would carry. `ppm` scenarios mount the pace check's
// confirm dialog (assets/controllers/ppm_validator_controller.js, its Bootstrap modal stubbed) on the same form.
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

const bootstrapStub = 'data:text/javascript,' + encodeURIComponent('const modal = { show() {}, hide() {} }; export const Modal = { getOrCreateInstance: () => modal, getInstance: () => modal };');

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier === '@hotwired/stimulus') {
            return { url: stimulusUrl, format: 'module', shortCircuit: true };
        }

        // ppm_validator_controller.js only shows and hides its modal
        if (specifier === 'bootstrap') {
            return { url: bootstrapStub, format: 'module', shortCircuit: true };
        }

        return nextResolve(specifier, context);
    },
});

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/en/puzzle-add', pretendToBeVisual: true });
const { window } = dom;

const world = { online: true, reports: [], starts: [], startAnswer: null, startDelayMs: 0, puts: 0 };

for (const name of ['window', 'document', 'MutationObserver', 'Element', 'HTMLElement', 'HTMLInputElement', 'HTMLFormElement', 'Node', 'Event', 'CustomEvent', 'KeyboardEvent', 'MouseEvent']) {
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

    if (world.startDelayMs > 0) {
        await new Promise((resolve) => setTimeout(resolve, world.startDelayMs));
    }

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

const { default: PpmValidatorController } = await import('../assets/controllers/ppm_validator_controller.js');

const application = Application.start(window.document.documentElement);
application.register('submit-prevention', SubmitPreventionController);
application.register('ppm-validator', PpmValidatorController);

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

// `newPuzzle`: the new-puzzle section (with the box photo) is on show, as time_form_autocomplete_controller.js opens it;
// `ppm`: the pace check with a time it asks about (1000 pieces in a minute)
function buildForm({ uploads = true, newPuzzle = false, ppm = false } = {}) {
    const form = window.document.createElement('form');
    form.setAttribute('action', '/en/puzzle-add');
    form.setAttribute('method', 'post');
    form.setAttribute('data-controller', ppm ? 'submit-prevention ppm-validator' : 'submit-prevention');

    if (ppm) {
        form.setAttribute('data-ppm-validator-active-puzzle-pieces-value', '1000');
    }

    const values = {
        'saving-text': 'SAVING',
        'drop-file-text': 'DROP A FILE',
        'save-failed-text': 'NETWORK',
        'photo-unreadable-text': 'UNREADABLE',
        'photo-unreadable-required-text': 'UNREADABLE REQUIRED',
        'save-failed-photo-text': 'PHOTO',
        'save-failed-either-text': 'EITHER',
        'save-failed-photo-required-text': 'PHOTO REQUIRED',
        'save-failed-either-required-text': 'EITHER REQUIRED',
        'save-without-photo-text': 'WITHOUT',
        'try-again-text': 'TRY AGAIN',
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
        <div data-field="${field}" class="${field === 'puzzlePhoto' && !newPuzzle ? 'd-none' : ''}">
            <div class="file-drop-area">
                <div class="file-drop-icon"><div class="file-drop-preview"><img alt=""></div></div>
                <span class="file-drop-message">an-earlier-photo.jpg</span>
                <input type="file" class="file-drop-input" name="puzzle_add_form[${field}]">
                <button type="button" class="file-drop-btn">Choose</button>
            </div>
        </div>`).join('') + `
        <input type="text" name="puzzle_add_form[comment]" value="typed by the player">
        ${ppm ? `
        <input type="text" value="0" data-ppm-validator-target="timeHours">
        <input type="text" value="1" data-ppm-validator-target="timeMinutes">
        <input type="text" value="0" data-ppm-validator-target="timeSeconds">
        <div data-ppm-validator-target="modal">
            <div data-ppm-validator-target="warningMessage"></div>
            <button type="button" data-ppm-confirm data-action="click->ppm-validator#confirmSubmit">Yes, time is correct</button>
        </div>` : ''}
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

// Reached only by a submit the controllers let through: what the request would carry
window.document.addEventListener('submit', (event) => {
    const form = event.target;

    // Stopped by the pace check's dialog - not sent
    if (event.defaultPrevented) {
        return;
    }

    sent.push({
        files: Object.fromEntries(FIELDS.map((field) => {
            const input = form.querySelector(`input[name="puzzle_add_form[${field}]"]`);

            return [field, input.disabled || input.files.length === 0 ? null : input.files[0].size];
        })),
        tokens: Object.fromEntries([...form.querySelectorAll('input[type="hidden"]')].filter((hidden) => hidden.name.startsWith('photo_stash[') && hidden.value !== '').map((hidden) => [hidden.name, hidden.value])),
        // What the page says it took out while saving - the next page's flash (EmptyPhotoParts)
        dropped: Object.fromEntries([...form.querySelectorAll('input[type="hidden"]')].filter((hidden) => hidden.name.startsWith('photo_dropped[')).map((hidden) => [hidden.name, hidden.value])),
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
        tryAgainButton: alert ? (alert.querySelector('[data-save-try-again]')?.textContent ?? null) : null,
        warning: form.querySelector('[data-ppm-validator-target="warningMessage"]')?.textContent ?? null,
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

    // A save that dies at once with a photo inside the request may be the connection or the photo: both are said the
    // first time ("Try again" + "Save without the photo"), the photo is named when it happens again at once
    async 'save-dies-at-once-with-photo'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();
        const afterFirst = look(form);
        form.querySelector('[data-save-try-again]').click();
        await until(() => sent.length === 2);
        answered(form, lostAtOnce());
        await settle();
        const afterSecond = look(form);
        form.querySelector('[data-save-without-photo]').click();
        await settle();
        const afterWithout = look(form);
        answered(form, saved());
        await settle();

        return facts(form, { afterFirst, afterSecond, afterWithout });
    },

    // "Save without the photo" right after the first failure
    async 'save-without-after-first-failure'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();
        form.querySelector('[data-save-without-photo]').click();
        await settle();

        return facts(form);
    },

    // A failure in between (the form refused, then sent again) starts the count anew: not "the photo" at once
    async 'instant-failures-not-in-a-row'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();
        save(form);
        await until(() => sent.length === 2);
        answered(form, { success: false, fetchResponse: { statusCode: 422, redirected: false, isHTML: true } });
        await settle();
        save(form);
        await until(() => sent.length === 3);
        answered(form, lostAtOnce());
        await settle();

        return facts(form);
    },

    // A NEW puzzle needs its box photo: when that is the photo in the request, nothing can be left out
    async 'new-puzzle-box-photo-in-form'() {
        const { form, inputs } = buildForm({ newPuzzle: true });
        await settle();
        inputs.puzzlePhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();
        const afterFirst = look(form);
        form.querySelector('[data-save-try-again]').click();
        await until(() => sent.length === 2);
        answered(form, lostAtOnce());
        await settle();

        return facts(form, { afterFirst });
    },

    // ...with a finished photo next to it: only that one goes, the box photo stays
    async 'new-puzzle-both-photos-in-form'() {
        const { form, inputs } = buildForm({ newPuzzle: true });
        await settle();
        inputs.puzzlePhoto.pick(photo(1000), true);
        inputs.finishedPuzzlesPhoto.pick(photo(2000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();
        const afterFirst = look(form);
        form.querySelector('[data-save-without-photo]').click();
        await until(() => sent.length === 2);

        return facts(form, { afterFirst });
    },

    // ...and with the finished photo as its only photo (it stands in for the box photo): nothing can be left out
    async 'new-puzzle-finished-photo-only'() {
        const { form, inputs } = buildForm({ newPuzzle: true });
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();

        return facts(form);
    },

    // The box photo went up in the background, the finished photo travels in the form: that one may go
    async 'new-puzzle-box-photo-uploaded'() {
        const { form, inputs } = buildForm({ newPuzzle: true });
        await settle();
        inputs.puzzlePhoto.pick(photo(1000));
        await until(() => look(form).statuses.puzzlePhoto[0] === 'DONE');
        inputs.finishedPuzzlesPhoto.pick(photo(2000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();
        const afterFirst = look(form);
        form.querySelector('[data-save-without-photo]').click();
        await until(() => sent.length === 2);

        return facts(form, { afterFirst });
    },

    // Without background uploads (the kill switch) a picked photo is checked all the same
    async 'pick-empty-without-uploads'() {
        const { form, inputs } = buildForm({ uploads: false });
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(0));
        await settle();
        const afterEmpty = look(form);
        inputs.finishedPuzzlesPhoto.pick(unreadablePhoto());
        await settle();
        const afterUnreadable = look(form);
        inputs.finishedPuzzlesPhoto.pick(photo(1000));
        await settle();
        const afterNormal = look(form);
        save(form);
        await until(() => sent.length === 1);

        return facts(form, { afterEmpty, afterUnreadable, afterNormal });
    },

    // Save tapped while the photo still uploads, then the start calls it empty: out, and the next page is told
    async 'start-says-empty-while-saving'() {
        world.startAnswer = { status: 422, body: { error: 'empty' } };
        world.startDelayMs = 150;
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000));
        await settle();
        save(form);
        await until(() => sent.length === 1);

        return facts(form);
    },

    // A marker is only for a photo that stays out: another photo picked for the field takes it away
    async 'photo-picked-again-after-save-without'() {
        const { form, inputs } = buildForm();
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => sent.length === 1);
        answered(form, lostAtOnce());
        await settle();
        form.querySelector('[data-save-without-photo]').click();
        await until(() => sent.length === 2);
        // Refused by the server (in the browser the form would be rendered again) - the player picks another photo
        answered(form, { success: false, fetchResponse: { statusCode: 422, redirected: false, isHTML: true } });
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(3000));
        await until(() => look(form).statuses.finishedPuzzlesPhoto[0] === 'DONE');
        save(form);
        await until(() => sent.length === 3);

        return facts(form);
    },

    // The pace check's dialog with a photo in the form: Save detours for the one-byte read before AND after "Yes, time
    // is correct" - the form must still be sent exactly once
    async 'ppm-confirm-with-photo-in-form'() {
        const { form, inputs } = buildForm({ ppm: true });
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(1000), true);
        save(form);
        await until(() => look(form).warning !== '');
        const whileAsked = { ...look(form), sent: sent.length };
        form.querySelector('[data-ppm-confirm]').click();
        await until(() => sent.length >= 1);
        await wait(300);

        return facts(form, { whileAsked });
    },

    async 'ppm-confirm-without-photo'() {
        const { form } = buildForm({ ppm: true });
        await settle();
        save(form);
        await until(() => look(form).warning !== '');
        const whileAsked = { ...look(form), sent: sent.length };
        form.querySelector('[data-ppm-confirm]').click();
        await until(() => sent.length >= 1);
        await wait(300);

        return facts(form, { whileAsked });
    },

    async 'ppm-confirm-with-empty-photo-in-form'() {
        const { form, inputs } = buildForm({ ppm: true, uploads: false });
        await settle();
        inputs.finishedPuzzlesPhoto.pick(photo(0), true);
        save(form);
        await until(() => look(form).warning !== '');
        form.querySelector('[data-ppm-confirm]').click();
        await until(() => sent.length >= 1);
        await wait(300);

        return facts(form);
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
    world.startDelayMs = 0;
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
