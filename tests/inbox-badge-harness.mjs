// Runs assets/inbox_badge.js (the browser twin of InboxBadgeParts) and assets/translation_choice.js for what the PHP
// tests hand over on stdin, and prints the results as JSON:
// - `states` (tests/Value/InboxBadgeScriptTest.php): numbers → the badge texts, the target tab and the accessible name;
// - `linkStrings` (the same test): which link's hrefs and labels linkStrings() reads - the link's own, the page's first
//   Inbox link's (the only one carrying them, inbox_link_strings()), or none;
// - `pages` (tests/Navigation/InboxLinkStringsTest.php): a rendered page's HTML; each push is applied to every
//   .inbox-badge as a realtime push does, with the Menu as rendered (in its slot = the docked column; the `menu` page's
//   content), moved into its shell (the phone panel and the desktop overlay, assets/controllers/menu_layer_controller.js)
//   and moved ahead of the top bar, and every Inbox link's href, accessible name and count is reported after each push.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { applyInboxBadge, displayCount, inboxBadgeState, linkStrings } from '../assets/inbox_badge.js';
import { chooseTranslation } from '../assets/translation_choice.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const input = JSON.parse(readFileSync(0, 'utf8'));
const output = {};

function dom(html) {
    return new JSDOM(html, { url: 'https://msp.test/en/faq', virtualConsole: new VirtualConsole() }).window.document;
}

if (input.states !== undefined) {
    output.states = input.states.map(({ forYou, conversations, requests, labels }) => {
        const state = inboxBadgeState({ forYou, conversations, requests });
        const label = labels[state.labelKind];

        return {
            total: displayCount(state.parts.total),
            for_you: displayCount(state.parts.for_you),
            messages: displayCount(state.parts.messages),
            target: state.target,
            label: chooseTranslation(label.message, state.labelCount, label.locale),
        };
    });
}

if (input.linkStrings !== undefined) {
    output.linkStrings = Object.fromEntries(Object.entries(input.linkStrings).map(([name, { body, link }]) => {
        const document = dom(`<!doctype html><html lang="en"><body>${body}</body></html>`);

        return [name, linkStrings(document.querySelector(link))];
    }));
}

const PLACEMENTS = {
    // Server-side the Menu sits in its slot - the docked column (NAV-13) - or is the `menu` page's content
    'as rendered': () => true,
    // The phone panel (MENU-02b) and the desktop overlay (NAV-13) move the one #site-menu into the shell's body
    'phone panel and desktop overlay': (document) => {
        const menu = document.getElementById('site-menu');
        const shell = document.querySelector('#menu-layer [data-menu-layer-target="body"]');

        if (menu === null || shell === null) {
            return false;
        }

        shell.append(menu);

        return true;
    },
    // Document order never decides which link a push reads the strings from
    'Menu ahead of the top bar': (document) => {
        const menu = document.getElementById('site-menu');

        if (menu === null || document.querySelector('.site-header') === null) {
            return false;
        }

        document.body.prepend(menu);

        return true;
    },
};

if (input.pages !== undefined) {
    output.pages = input.pages.map(({ html, pushes }) => {
        const results = {};

        for (const [placement, place] of Object.entries(PLACEMENTS)) {
            const document = dom(html);

            if (!place(document)) {
                continue;
            }

            results[placement] = pushes.map((push) => {
                applyInboxBadge(document.querySelectorAll('.inbox-badge'), push);

                return [...document.querySelectorAll('a[data-inbox-link]')].map((link) => ({
                    nav: link.dataset.nav,
                    href: link.getAttribute('href'),
                    label: link.getAttribute('aria-label'),
                    count: link.dataset.barCount,
                }));
            });
        }

        return results;
    });
}

process.stdout.write(JSON.stringify(output));
