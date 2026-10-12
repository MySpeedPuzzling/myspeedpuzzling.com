// Runs assets/confirm_dialog.js (in-page confirmations, GEST-13) through every case of tests/ConfirmDialogScriptTest.php
// and prints what each one did as JSON: {case: facts}.
//
// The page is jsdom with the real <template id="msp-confirm-template"> cut out of templates/base.html.twig (its Twig
// labels replaced by fixed texts). jsdom has no showModal()/close(): the harness gives HTMLDialogElement both (open
// attribute, a recorded call, `close` fired like the browser). Events travel jsdom's real path - window capture first,
// so the module's Escape listener runs before a page's document listeners and a Bootstrap modal's own.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { createConfirmDialog, loadedByTraverse, reportsSuppressed, SUPPRESSED_WITHIN_MS, TEMPLATE_ID } from '../assets/confirm_dialog.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const BASE = readFileSync(new URL('../templates/base.html.twig', import.meta.url), 'utf8');
const TEMPLATE = (() => {
    const match = BASE.match(/<template id="msp-confirm-template">[\s\S]*?<\/template>/);

    if (!match) {
        throw new Error('templates/base.html.twig has no <template id="msp-confirm-template">');
    }

    return match[0]
        .replace("{{ 'confirm_dialog.cancel'|trans }}", 'Cancel (template)')
        .replace("{{ 'confirm_dialog.confirm'|trans }}", 'Confirm (template)');
})();

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/** A page: jsdom + the template + whatever markup the case needs; the module on it */
function page({ body = '', template = true, html = {}, env = {}, install = null } = {}) {
    const attributes = Object.entries(html).map(([name, value]) => ` ${name}="${value}"`).join('');
    const dom = new JSDOM(`<!DOCTYPE html><html${attributes}><body>${body}${template ? TEMPLATE : ''}</body></html>`, {
        url: 'https://msp.test/en/faq',
        virtualConsole: new VirtualConsole(),
    });
    const { window } = dom;
    const calls = [];

    Object.defineProperty(window.HTMLDialogElement.prototype, 'open', {
        configurable: true,
        get() {
            return this.hasAttribute('open');
        },
    });
    window.HTMLDialogElement.prototype.showModal = function showModal() {
        calls.push('showModal');
        this.setAttribute('open', '');
    };
    window.HTMLDialogElement.prototype.close = function close() {
        calls.push('close');
        this.removeAttribute('open');
        this.dispatchEvent(new window.Event('close'));
    };

    const instance = createConfirmDialog({ window, document: window.document, ...env });

    if (install) {
        instance.install(install);
    }

    return { window, document: window.document, instance, calls };
}

function dialogFacts(document) {
    const dialog = document.querySelector('dialog.msp-confirm');

    if (!dialog) {
        return null;
    }

    const message = dialog.querySelector('[data-msp-confirm-message]');
    const cancel = dialog.querySelector('[data-msp-confirm-cancel]');
    const action = dialog.querySelector('[data-msp-confirm-action]');
    // Cancel left, action right: the order of the buttons in the row
    const buttons = [...dialog.querySelectorAll('.msp-confirm-actions button')].map((button) => (button === cancel ? 'cancel' : 'action'));

    return {
        parent: dialog.parentElement.id || dialog.parentElement.localName,
        open: dialog.hasAttribute('open'),
        role: dialog.getAttribute('role'),
        labelledByMessage: dialog.getAttribute('aria-labelledby') === message.id && message.id !== '',
        message: message.textContent,
        cancel: cancel.textContent.trim(),
        action: action.textContent.trim(),
        actionClass: action.classList.contains('btn-danger') ? 'btn-danger' : (action.classList.contains('btn-primary') ? 'btn-primary' : 'none'),
        buttons,
        focused: document.activeElement === cancel ? 'cancel' : (document.activeElement === action ? 'action' : (document.activeElement ? document.activeElement.id || document.activeElement.localName : null)),
    };
}

function answer(promise) {
    const state = { value: 'pending' };
    promise.then((value) => {
        state.value = value;
    });

    return state;
}

async function ask(p, message, options) {
    const state = answer(p.instance.confirmDialog(message, options));
    await settle();

    return state;
}

function click(window, element) {
    element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
}

const cases = {
    'action resolves true': async () => {
        const p = page({ body: '<button id="trigger">Delete</button>' });
        const trigger = p.document.getElementById('trigger');
        trigger.focus();
        const state = await ask(p, 'Delete this stopwatch?\nIt cannot be undone.', { returnFocusTo: trigger });
        const opened = dialogFacts(p.document);
        click(p.window, p.document.querySelector('[data-msp-confirm-action]'));
        await settle();

        return { opened, answer: state.value, calls: p.calls, left: p.document.querySelectorAll('dialog').length, focus: p.document.activeElement.id, isOpen: p.instance.isOpen() };
    },

    'cancel resolves false': async () => {
        const p = page({ body: '<button id="trigger">Delete</button>' });
        const trigger = p.document.getElementById('trigger');
        const state = await ask(p, 'Delete?', { returnFocusTo: trigger });
        click(p.window, p.document.querySelector('[data-msp-confirm-cancel]'));
        await settle();

        return { answer: state.value, left: p.document.querySelectorAll('dialog').length, focus: p.document.activeElement.id };
    },

    'escape resolves false before anybody else sees it': async () => {
        const p = page({ body: '<button id="trigger">Delete</button>' });
        const seen = [];
        // A page's own Escape (live results: document capture), the layer helper's (document bubble)
        p.document.addEventListener('keydown', () => seen.push('document capture'), true);
        p.document.addEventListener('keydown', () => seen.push('document bubble'));
        const state = await ask(p, 'Delete?', { returnFocusTo: p.document.getElementById('trigger') });
        const event = new p.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        p.document.activeElement.dispatchEvent(event);
        await settle();
        const seenWhileOpen = [...seen];
        // Once closed, Escape belongs to the page again
        p.document.body.dispatchEvent(new p.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

        return { answer: state.value, prevented: event.defaultPrevented, seenWhileOpen, seenAfter: seen, focus: p.document.activeElement.id };
    },

    'cancel event resolves false': async () => {
        const p = page();
        const state = await ask(p, 'Delete?');
        const event = new p.window.Event('cancel', { cancelable: true });
        p.document.querySelector('dialog').dispatchEvent(event);
        await settle();

        return { answer: state.value, prevented: event.defaultPrevented, left: p.document.querySelectorAll('dialog').length };
    },

    'scrim resolves false': async () => {
        const p = page();
        const state = await ask(p, 'Delete?');
        const dialog = p.document.querySelector('dialog');
        // A press inside the box released on the scrim is no tap on the scrim
        dialog.querySelector('.msp-confirm-box').dispatchEvent(new p.window.MouseEvent('pointerdown', { bubbles: true }));
        click(p.window, dialog);
        await settle();
        const afterDrag = state.value;
        // Inside the box: nothing
        dialog.querySelector('.msp-confirm-box').dispatchEvent(new p.window.MouseEvent('pointerdown', { bubbles: true }));
        click(p.window, dialog.querySelector('[data-msp-confirm-message]'));
        await settle();
        const afterBox = state.value;
        dialog.dispatchEvent(new p.window.MouseEvent('pointerdown', { bubbles: true }));
        click(p.window, dialog);
        await settle();

        return { afterDrag, afterBox, answer: state.value };
    },

    'danger style, labels and focus': async () => {
        const p = page();
        await ask(p, 'Remove this referee?', { danger: true, actionLabel: '  Remove  ' });
        const danger = dialogFacts(p.document);
        p.instance.confirmDialog('Reveal now?');
        await settle();
        const plain = dialogFacts(p.document);

        return { danger, plain, dialogs: p.document.querySelectorAll('dialog').length };
    },

    'a newer question replaces an open one': async () => {
        const p = page();
        const first = await ask(p, 'First?');
        const second = await ask(p, 'Second?');

        return { first: first.value, second: second.value, dialogs: p.document.querySelectorAll('dialog').length, message: dialogFacts(p.document).message };
    },

    'inside an open Bootstrap modal': async () => {
        const p = page({ body: '<div id="report" class="modal show"><form><button id="submit" type="submit">Report</button></form></div>'
            + '<div id="other" class="modal show"></div><div id="closed" class="modal"></div><button id="page-button">x</button>' });
        await ask(p, 'Report?', { returnFocusTo: p.document.getElementById('submit') });
        const aroundTheTrigger = dialogFacts(p.document).parent;
        await ask(p, 'Delete?', { returnFocusTo: p.document.getElementById('page-button') });
        const lastOpenModal = dialogFacts(p.document).parent;
        p.document.querySelectorAll('.modal.show').forEach((modal) => modal.classList.remove('show'));
        await ask(p, 'Delete?', { returnFocusTo: p.document.getElementById('page-button') });
        const noModal = dialogFacts(p.document).parent;

        return { aroundTheTrigger, lastOpenModal, noModal };
    },

    'the modal around it hiding resolves false': async () => {
        const p = page({ body: '<div id="report" class="modal show"><button id="submit">Report</button></div><div id="other" class="modal show"></div>', install: {} });
        const state = await ask(p, 'Report?', { returnFocusTo: p.document.getElementById('submit') });
        // Another modal hiding: not ours
        p.document.getElementById('other').dispatchEvent(new p.window.Event('hide.bs.modal', { bubbles: true }));
        await settle();
        const afterOther = state.value;
        p.document.getElementById('report').dispatchEvent(new p.window.Event('hide.bs.modal', { bubbles: true }));
        await settle();

        return { afterOther, answer: state.value, left: p.document.querySelectorAll('dialog').length };
    },

    'leaving the page resolves false': async () => {
        const facts = {};

        for (const [name, fire] of [
            ['popstate', (p) => p.window.dispatchEvent(new p.window.PopStateEvent('popstate', { state: null }))],
            ['turbo:before-visit', (p) => p.document.documentElement.dispatchEvent(new p.window.CustomEvent('turbo:before-visit', { bubbles: true, cancelable: true, detail: { url: 'https://msp.test/en/hub' } }))],
            ['turbo:before-cache', (p) => p.document.documentElement.dispatchEvent(new p.window.CustomEvent('turbo:before-cache', { bubbles: true }))],
        ]) {
            const p = page({ install: {} });
            const state = await ask(p, 'Delete?');
            fire(p);
            await settle();
            facts[name] = { answer: state.value, left: p.document.querySelectorAll('dialog').length };
        }

        // Not installed: nothing listens (the browser instance is always installed by app.js)
        return facts;
    },

    'Turbo confirm method': async () => {
        const turbo = { config: { forms: { confirm: 'native' } } };
        const p = page({
            body: '<form id="revoke" data-turbo-confirm="Revoke?" data-turbo-confirm-danger><button id="revoke-submit" type="submit" data-turbo-confirm-action="Revoke access">Revoke access</button></form>'
                + '<form id="block"><button id="block-submit" type="submit" data-turbo-confirm="Block?" data-turbo-confirm-danger data-turbo-confirm-action="Block">Block</button></form>'
                + '<form id="convert" data-turbo-confirm="Convert?"><input name="x"><button id="convert-submit" type="submit">Convert</button></form>',
            install: { Turbo: turbo },
        });
        const method = turbo.config.forms.confirm;
        const installed = method === p.instance.turboConfirm;

        const byForm = answer(method('Revoke?', p.document.getElementById('revoke'), p.document.getElementById('revoke-submit')));
        await settle();
        const formDanger = dialogFacts(p.document);
        click(p.window, p.document.querySelector('[data-msp-confirm-cancel]'));
        await settle();
        const focusAfterCancel = p.document.activeElement.id;

        const bySubmitter = answer(method('Block?', p.document.getElementById('block'), p.document.getElementById('block-submit')));
        await settle();
        const submitterDanger = dialogFacts(p.document);
        click(p.window, p.document.querySelector('[data-msp-confirm-action]'));
        await settle();
        const focusAfterAction = p.document.activeElement.id;

        // Enter in a field: no submitter - the form's submit button gets the focus back
        const noSubmitter = answer(method('Convert?', p.document.getElementById('convert'), null));
        await settle();
        const plain = dialogFacts(p.document);
        click(p.window, p.document.querySelector('[data-msp-confirm-cancel]'));
        await settle();

        return {
            installed,
            formDanger,
            byForm: byForm.value,
            focusAfterCancel,
            submitterDanger,
            bySubmitter: bySubmitter.value,
            focusAfterAction,
            plain,
            noSubmitter: noSubmitter.value,
            focusWithoutSubmitter: p.document.activeElement.id,
        };
    },

    'guardLeave asks and visits again only on yes': async () => {
        const visits = [];
        let p = null;
        const layers = {
            lastLayerBackAt: () => null,
            // As Turbo's session.visit: turbo:before-visit is dispatched synchronously
            visitFromLayer(url) {
                const event = new p.window.CustomEvent('turbo:before-visit', { bubbles: true, cancelable: true, detail: { url } });
                p.document.documentElement.dispatchEvent(event);
                visits.push({ url, prevented: event.defaultPrevented });
            },
        };
        p = page({ env: { layers }, install: {} });
        let unsaved = true;
        const guardResults = [];
        // A controller's synchronous guard (registered after install, as Stimulus connects after app.js)
        p.document.addEventListener('turbo:before-visit', (event) => {
            if (unsaved) {
                guardResults.push(p.instance.guardLeave(event, 'Leave? Unsaved changes are lost.'));
            }
        });

        const leave = () => {
            const event = new p.window.CustomEvent('turbo:before-visit', { bubbles: true, cancelable: true, detail: { url: 'https://msp.test/en/events' } });
            p.document.documentElement.dispatchEvent(event);

            return event.defaultPrevented;
        };

        const firstPrevented = leave();
        await settle();
        // The window-capture cancel listener ran before the guard: the guard's own question stays open
        const asked = dialogFacts(p.document);
        click(p.window, p.document.querySelector('[data-msp-confirm-cancel]'));
        await settle();
        const visitsAfterNo = visits.length;

        const secondPrevented = leave();
        await settle();
        click(p.window, p.document.querySelector('[data-msp-confirm-action]'));
        await settle();

        // Nothing unsaved: no question
        unsaved = false;
        const cleanPrevented = leave();

        return { firstPrevented, asked, visitsAfterNo, secondPrevented, visits, guardResults, cleanPrevented, dialogs: p.document.querySelectorAll('dialog').length };
    },

    'leaveTo without the layer helper': async () => {
        const p = page();
        const visited = [];
        p.window.Turbo = { visit: (url) => visited.push(url) };
        p.instance.leaveTo('https://msp.test/en/hub');

        return { visited };
    },

    'fallback dialog without the template': async () => {
        const p = page({ template: false });
        const state = await ask(p, 'Delete?', { danger: true });
        const facts = dialogFacts(p.document);
        click(p.window, p.document.querySelector('[data-msp-confirm-action]'));
        await settle();

        return { facts, answer: state.value };
    },

    'message is plain text': async () => {
        const p = page();
        await ask(p, 'Remove <img src=x onerror=alert(1)> from the referees?');
        const dialog = p.document.querySelector('dialog');

        return { images: dialog.querySelectorAll('img').length, message: dialogFacts(p.document).message };
    },

    'watchdog': async () => {
        const facts = {};

        for (const [name, html, delay, nativeAnswer, layerBackAt, restore] of [
            ['ios refused at once', { 'data-os': 'ios' }, 0, false, 1234.5, false],
            ['ios refused at 50 ms', { 'data-os': 'ios' }, SUPPRESSED_WITHIN_MS, false, null, true],
            ['ios answered after 300 ms', { 'data-os': 'ios' }, 300, false, null, false],
            ['ios confirmed at once', { 'data-os': 'ios' }, 0, true, null, false],
            ['android refused at once', { 'data-os': 'android' }, 0, false, null, false],
            ['desktop refused at once', { 'data-os': 'other' }, 0, false, null, false],
            ['ios inside the app', { 'data-os': 'ios', 'data-native-app': 'ios' }, 0, false, null, false],
            ['GEST-15 restore kind', { 'data-os': 'ios' }, 0, false, null, 'snapshot'],
        ]) {
            const clock = { now: 0 };
            const reports = [];
            const native = [];
            const p = page({
                html,
                env: { now: () => clock.now, layers: { lastLayerBackAt: () => layerBackAt }, restoreKind: () => restore },
            });
            p.window.confirm = (message) => {
                native.push(message);
                clock.now += delay;

                return nativeAnswer;
            };
            p.instance.install({ report: (detail) => reports.push(detail) });
            p.instance.install({ report: () => reports.push('second install') });

            const answers = [p.window.confirm('Approve this application?'), p.window.confirm('Again?')];
            // A Turbo visit = a new page view: one more report allowed
            p.document.documentElement.dispatchEvent(new p.window.CustomEvent('turbo:visit', { bubbles: true }));
            answers.push(p.window.confirm('After a visit?'));

            facts[name] = { answers, native, reports };
        }

        return facts;
    },

    'reportsSuppressed matrix': () => ({
        refusedAtOnce: reportsSuppressed(false, 'ios', false, 3, false),
        atTheLimit: reportsSuppressed(false, 'ios', false, SUPPRESSED_WITHIN_MS, false),
        justOver: reportsSuppressed(false, 'ios', false, SUPPRESSED_WITHIN_MS + 0.1, false),
        confirmed: reportsSuppressed(false, 'ios', true, 3, false),
        alreadyReported: reportsSuppressed(false, 'ios', false, 3, true),
        android: reportsSuppressed(false, 'android', false, 3, false),
        other: reportsSuppressed(false, 'other', false, 3, false),
        nativeApp: reportsSuppressed(true, 'ios', false, 3, false),
        noTiming: reportsSuppressed(false, 'ios', false, undefined, false),
    }),

    'loadedByTraverse until GEST-15': () => ({
        traverse: loadedByTraverse({ navigation: { activation: { navigationType: 'traverse' } } }),
        push: loadedByTraverse({ navigation: { activation: { navigationType: 'push' } } }),
        reload: loadedByTraverse({ navigation: { activation: { navigationType: 'reload' } } }),
        noActivation: loadedByTraverse({ navigation: { activation: null } }),
        noNavigationApi: loadedByTraverse({}),
        throws: loadedByTraverse({ get navigation() { throw new Error('denied'); } }),
    }),

    'constants': () => ({ templateId: TEMPLATE_ID, within: SUPPRESSED_WITHIN_MS }),
};

const results = {};

for (const [name, run] of Object.entries(cases)) {
    try {
        results[name] = await run();
    } catch (error) {
        results[name] = { threw: String(error && error.stack ? error.stack : error) };
    }
}

process.stdout.write(JSON.stringify(results));
