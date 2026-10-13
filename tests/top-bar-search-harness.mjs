// Runs the real assets/controllers/nav_search_controller.js under Stimulus in jsdom on pages the PHP test rendered
// (tests/TopBarPageSearchTest.php hands them over on stdin, with each locale's real nav-search.json) and prints
// {case: facts} as JSON: what the top bar's search (every page's search, SRCH-01) shows in its "Pages & settings" group
// for a query typed into it - the rows (id, label, the line under it, "Sign in to …"), "Show N more", and where the
// group sits in the search panel. The list is served by a fake fetch; nothing else of the page runs.
//
// Input: {pages: {name: html}, lists: {url: list}, cases: [{page, queries: [{name, query, expand}]}]}

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { Application } from '@hotwired/stimulus';
import NavSearchController from '../assets/controllers/nav_search_controller.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const input = JSON.parse(readFileSync(0, 'utf8'));

const settle = async (rounds = 10) => {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

async function page(html, facts) {
    const dom = new JSDOM(html, { url: 'https://msp.test/en/faq', virtualConsole: new VirtualConsole() });
    const { window } = dom;

    window.fetch = (url) => {
        const path = new URL(String(url), 'https://msp.test').pathname + new URL(String(url), 'https://msp.test').search;
        facts.fetched.push(path);
        const list = input.lists[path];

        return Promise.resolve({ ok: list !== undefined, json: () => Promise.resolve(list) });
    };

    Object.assign(globalThis, {
        window,
        document: window.document,
        MutationObserver: window.MutationObserver,
        Node: window.Node,
        Element: window.Element,
        HTMLElement: window.HTMLElement,
        Event: window.Event,
        CustomEvent: window.CustomEvent,
        KeyboardEvent: window.KeyboardEvent,
        MouseEvent: window.MouseEvent,
    });

    const application = Application.start(window.document.documentElement);
    application.handleError = (error) => facts.errors.push(String(error && error.stack ? error.stack : error));
    application.register('nav-search', NavSearchController);
    await settle();

    return { window, document: window.document, application };
}

function rowFacts(link) {
    const where = link.querySelector('.mr-d');
    const label = link.querySelector('.mr-l');

    return {
        id: link.getAttribute('data-nav-search-id'),
        label: label ? [...label.childNodes].filter((node) => node.nodeType === 3).map((node) => node.textContent).join('').trim() : null,
        where: where ? where.textContent : null,
        signIn: link.hasAttribute('data-nav-search-sign-in'),
        href: link.getAttribute('href'),
        nav: link.getAttribute('data-nav'),
    };
}

const output = {};

// One page per locale and viewer; its queries typed one after another into the same field
for (const { page: pageName, queries } of input.cases || []) {
    const facts = { fetched: [], errors: [] };
    let p = null;

    try {
        p = await page(input.pages[pageName], facts);
    } catch (error) {
        queries.forEach(({ name }) => {
            output[name] = { threw: String(error && error.stack ? error.stack : error) };
        });

        continue;
    }

    const root = p.document.querySelector('.global-search-bar[data-controller~="nav-search"]');
    const field = root.querySelector('[data-nav-search-target="input"]');
    const results = root.querySelector('[data-nav-search-results]');
    const panel = root.querySelector('.site-search-panel');
    field.dispatchEvent(new p.window.Event('focus'));

    for (const { name, query, expand = false } of queries) {
        const errors = facts.errors.length;

        try {
            field.value = query;
            field.dispatchEvent(new p.window.Event('input', { bubbles: true }));
            await settle(20);

            const more = results.querySelector('[data-nav-search-more]');
            const title = results.querySelector('.nav-search-title');
            const result = {
                title: title ? title.textContent : null,
                rows: [...results.querySelectorAll('a.nav-search-row')].map(rowFacts),
                more: more ? more.textContent : null,
                moreNav: more ? more.getAttribute('data-nav') : null,
                hidden: results.hidden,
                // The group is the panel's first element: the server's groups (players, puzzles) follow it
                firstInPanel: panel !== null && panel.firstElementChild === results,
                inMenu: results.closest('#menu-layer') !== null,
                fetched: [...facts.fetched],
            };

            if (expand && more) {
                more.dispatchEvent(new p.window.MouseEvent('click', { bubbles: true, cancelable: true }));
                await settle();
                result.expanded = [...results.querySelectorAll('a.nav-search-row')].map(rowFacts);
                result.moreAfter = results.querySelector('[data-nav-search-more]') !== null;
                result.focusedAfter = p.document.activeElement ? p.document.activeElement.getAttribute('data-nav-search-id') : null;
            }

            result.errors = facts.errors.slice(errors);
            output[name] = result;
        } catch (error) {
            output[name] = { threw: String(error && error.stack ? error.stack : error) };
        }
    }

    p.application.stop();
}

process.stdout.write(JSON.stringify(output));
