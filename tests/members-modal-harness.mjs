// Runs the real assets/controllers/members_modal_controller.js under Stimulus in jsdom on pages the PHP test rendered
// (tests/MembersModalScriptTest.php hands them over on stdin) and prints {page: facts} as JSON: for each open of the
// members modal (Bootstrap's show.bs.modal on #membersExclusiveModal, relatedTarget = the lock that was clicked) the
// trial form's `placement` field, the membership links' hrefs and the msp:members-modal events dispatched
// (docs/features/navigation TEASE-01).
//
// Input: {pages: {name: {html, opens: [css selector of the lock | null]}}}

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { Application } from '@hotwired/stimulus';
import MembersModalController from '../assets/controllers/members_modal_controller.js';
import { MEMBERS_MODAL_EVENT } from '../assets/nav_instrumentation.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const input = JSON.parse(readFileSync(0, 'utf8'));

const settle = async (rounds = 10) => {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

const output = {};

for (const [name, { html, opens }] of Object.entries(input.pages)) {
    const dom = new JSDOM(html, { url: 'https://msp.test/en/puzzler-card/x', virtualConsole: new VirtualConsole() });
    const { window } = dom;
    const facts = { errors: [], controller: false, opens: [] };

    Object.assign(globalThis, {
        window,
        document: window.document,
        MutationObserver: window.MutationObserver,
        Node: window.Node,
        Element: window.Element,
        HTMLElement: window.HTMLElement,
        Event: window.Event,
        CustomEvent: window.CustomEvent,
    });

    const events = [];
    window.document.addEventListener(MEMBERS_MODAL_EVENT, (event) => events.push(event.detail));

    const application = Application.start(window.document.documentElement);
    application.handleError = (error) => facts.errors.push(String(error && error.stack ? error.stack : error));
    application.register('members-modal', MembersModalController);
    await settle();

    const modal = window.document.getElementById('membersExclusiveModal');
    facts.controller = Boolean(modal && modal.getAttribute('data-controller') === 'members-modal');

    // A lock without a placement, as every other members-only lock of the site is
    const plainLock = window.document.createElement('button');
    plainLock.setAttribute('data-bs-toggle', 'modal');
    plainLock.setAttribute('data-bs-target', '#membersExclusiveModal');
    plainLock.id = 'harness-plain-lock';
    window.document.body.appendChild(plainLock);

    // A lock with a placement that is no id
    const junkLock = plainLock.cloneNode();
    junkLock.id = 'harness-junk-lock';
    junkLock.setAttribute('data-members-placement', '"><b>');
    window.document.body.appendChild(junkLock);

    for (const selector of opens) {
        const lock = selector === null ? null : window.document.querySelector(selector);
        const show = new window.Event('show.bs.modal', { bubbles: true });
        Object.defineProperty(show, 'relatedTarget', { value: lock });
        events.length = 0;

        if (modal) {
            modal.dispatchEvent(show);
        }

        await settle(2);

        facts.opens.push({
            lock: selector,
            lockFound: selector === null || lock !== null,
            field: [...window.document.querySelectorAll('#membersExclusiveModal input[name="placement"]')].map((field) => field.value),
            links: [...window.document.querySelectorAll('#membersExclusiveModal [data-members-modal-target="link"]')].map((link) => link.getAttribute('href')),
            events: [...events],
        });
    }

    application.stop();
    output[name] = facts;
}

process.stdout.write(JSON.stringify(output));
