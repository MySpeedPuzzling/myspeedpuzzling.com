// Runs the real assets/controllers/where_did_it_go_controller.js under Stimulus in jsdom on "Where did it go?" pages the
// PHP test rendered (tests/WhereDidItGoScriptTest.php hands them over on stdin) and prints {page: facts} as JSON: for each
// step typed into the filter box the rows and sections shown, the status line's text, whether it is still the element the
// server rendered (never hidden), how many times the script wrote it, and the msp:search-zero events dispatched
// (docs/features/navigation LAUNCH-02; LAUNCH review F5, Q3).
//
// Input: {pages: {name: {html, steps: [{type: string} | {wait: ms}]}}}

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { Application } from '@hotwired/stimulus';
import WhereDidItGoController from '../assets/controllers/where_did_it_go_controller.js';
import { SEARCH_ZERO_EVENT } from '../assets/nav_instrumentation.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const input = JSON.parse(readFileSync(0, 'utf8'));

const settle = async (rounds = 10) => {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

const output = {};

for (const [name, { html, steps }] of Object.entries(input.pages)) {
    const dom = new JSDOM(html, { url: 'https://msp.test/en/where-did-it-go', virtualConsole: new VirtualConsole() });
    const { window } = dom;
    const facts = { errors: [], connected: false, barShown: false, initialStatus: null, steps: [] };

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
    });

    const events = [];
    window.document.addEventListener(SEARCH_ZERO_EVENT, (event) => events.push(event.detail));

    const status = window.document.querySelector('[data-where-did-it-go-target="status"]');
    let writes = 0;

    if (status !== null) {
        new window.MutationObserver((records) => {
            writes += records.length;
        }).observe(status, { childList: true, characterData: true, subtree: true });
    }

    const application = Application.start(window.document.documentElement);
    application.handleError = (error) => facts.errors.push(String(error && error.stack ? error.stack : error));
    application.register('where-did-it-go', WhereDidItGoController);
    await settle();

    const field = window.document.getElementById('wdig-filter');
    const bar = window.document.querySelector('[data-where-did-it-go-target="bar"]');
    facts.connected = field !== null && status !== null;
    facts.barShown = bar !== null && !bar.hidden;
    facts.initialStatus = status === null ? null : status.textContent;

    for (const step of steps) {
        if (step.wait !== undefined) {
            await new Promise((resolve) => setTimeout(resolve, step.wait));
            facts.steps.push({ wait: step.wait, events: [...events] });

            continue;
        }

        writes = 0;
        field.value = step.type;
        field.dispatchEvent(new window.Event('input', { bubbles: true }));
        await settle(2);

        const current = window.document.querySelector('[data-where-did-it-go-target="status"]');
        facts.steps.push({
            type: step.type,
            rows: [...window.document.querySelectorAll('.wdig-row')].filter((row) => !row.hidden).length,
            allRows: window.document.querySelectorAll('.wdig-row').length,
            sections: [...window.document.querySelectorAll('.wdig-section')].filter((section) => !section.hidden).length,
            status: current === null ? null : current.textContent,
            sameStatus: current === status && status.isConnected && !status.hidden && status.closest('[hidden]') === null,
            role: current === null ? null : current.getAttribute('role'),
            writes,
            events: [...events],
        });
    }

    application.stop();
    output[name] = facts;
}

process.stdout.write(JSON.stringify(output));
