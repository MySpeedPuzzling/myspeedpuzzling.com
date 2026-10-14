// Runs the real assets/controllers/dismiss_hint_controller.js under Stimulus in jsdom on a page the PHP test rendered
// (tests/DismissHintScriptTest.php hands it over on stdin: the Hub with the navigation launch's pointer in two places -
// the banner and the line at the top of the Menu) and prints {case: facts} as JSON. Each case loads the page afresh, adds
// a hint of another type, focuses the × of `focus` (or nothing), clicks the × of `click` and reports which hints are
// left, the requests the controller sent and where the focus went (docs/features/hint-dismissing.md; navigation
// decision 52; LAUNCH review F5, F8).
//
// Input: {html, cases: {name: {click: css selector of the hint, focus: true | false}}}

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { Application } from '@hotwired/stimulus';
import DismissHintController from '../assets/controllers/dismiss_hint_controller.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const input = JSON.parse(readFileSync(0, 'utf8'));

const settle = async (rounds = 10) => {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

const describe = (element) => {
    if (element === null || element === undefined) {
        return null;
    }

    return {
        tag: element.tagName.toLowerCase(),
        inMenu: element.closest('#site-menu') !== null,
        inMain: element.closest('main') !== null,
        href: element.getAttribute('href'),
        tabindex: element.getAttribute('tabindex'),
    };
};

const output = {};

for (const [name, { click, focus }] of Object.entries(input.cases)) {
    const dom = new JSDOM(input.html, { url: 'https://msp.test/en/hub', virtualConsole: new VirtualConsole() });
    const { window } = dom;
    const facts = { errors: [], before: [], after: [], requests: [], active: null, expectedFirstRow: null };
    const requests = [];

    Object.assign(globalThis, {
        window,
        document: window.document,
        MutationObserver: window.MutationObserver,
        Node: window.Node,
        Element: window.Element,
        HTMLElement: window.HTMLElement,
        Event: window.Event,
        CustomEvent: window.CustomEvent,
        // Stimulus checks every action's event against these
        KeyboardEvent: window.KeyboardEvent,
        MouseEvent: window.MouseEvent,
        fetch: (url, options = {}) => {
            requests.push({ url: String(url), method: options.method ?? 'GET', body: options.body ?? null });

            return Promise.resolve({ ok: true, status: 204 });
        },
    });

    // A hint of another type on the same page (the getting-started card, a marketplace hint …): it stays
    const other = window.document.createElement('div');
    other.className = 'harness-other-hint';
    other.setAttribute('data-controller', 'dismiss-hint');
    other.setAttribute('data-dismiss-hint-url-value', '/en/dismiss-hint');
    other.setAttribute('data-dismiss-hint-type-value', 'getting_started_checklist');
    other.innerHTML = '<button type="button" data-action="dismiss-hint#dismiss">×</button>';
    const main = window.document.querySelector('main');
    (main ?? window.document.body).appendChild(other);

    const application = Application.start(window.document.documentElement);
    application.handleError = (error) => facts.errors.push(String(error && error.stack ? error.stack : error));
    application.register('dismiss-hint', DismissHintController);
    await settle();

    const hints = () => [...window.document.querySelectorAll('[data-controller~="dismiss-hint"]')].map((hint) => ({
        type: hint.getAttribute('data-dismiss-hint-type-value'),
        inMenu: hint.closest('#site-menu') !== null,
    }));

    facts.before = hints();

    const hint = window.document.querySelector(click);
    const button = hint === null ? null : hint.querySelector('[data-action~="dismiss-hint#dismiss"]');
    const menu = window.document.getElementById('site-menu');
    const firstRow = menu === null ? null : [...menu.querySelectorAll('li a[href]')].find((link) => hint === null || !hint.contains(link));
    facts.expectedFirstRow = firstRow === undefined ? null : firstRow.getAttribute('href');

    if (button !== null) {
        if (focus) {
            button.focus();
        }

        facts.focusedBefore = describe(window.document.activeElement);
        button.click();
    }

    await settle();

    facts.after = hints();
    facts.requests = requests;
    facts.active = describe(window.document.activeElement);

    application.stop();
    output[name] = facts;
}

process.stdout.write(JSON.stringify(output));
