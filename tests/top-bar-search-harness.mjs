// Runs the real assets/controllers/nav_search_controller.js under Stimulus in jsdom on pages the PHP test rendered
// (tests/TopBarPageSearchTest.php hands them over on stdin, with each locale's real nav-search.json) and prints
// {case: facts} as JSON: what the top bar's search (every page's search, SRCH-01) shows in its "Pages & settings" group
// for a query typed into it - the rows (id, label, the line under it, "Sign in to …"), "Show N more", and where the
// group sits in the search panel. The list is served by a fake fetch; nothing else of the page runs.
//
// Input: {pages: {name: html}, lists: {url: list}, cases: [{page, url?, phone?, delayList?, queries: [{name, query,
// expand, serverResults?}]}]} - `url` the page's address (default /en/faq), `phone` the answer of matchMedia for the
// phone query (none: jsdom's missing matchMedia = a phone), `delayList` holds nav-search.json back until the first query
// with `serverResults` has shown the server's groups (SRCH+FOOT review F4), `serverResults` puts a players/puzzles group
// into the panel as the Live re-render would.

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

// A held list per page (delayList): the page's list URL gets its own marker, so the tab-wide list cache of the
// controller module loads it again
let heldLists = 0;

async function page(html, facts, { url = '/en/faq', phone, delayList = false } = {}) {
    const dom = new JSDOM(html, { url: new URL(url, 'https://msp.test').toString(), virtualConsole: new VirtualConsole() });
    const { window } = dom;
    const root = window.document.querySelector('.global-search-bar[data-nav-search-url-value]');

    if (delayList && root) {
        heldLists += 1;
        root.setAttribute('data-nav-search-url-value', `${root.getAttribute('data-nav-search-url-value')}&held=${heldLists}`);
    }

    window.fetch = (requested) => {
        const address = new URL(String(requested), 'https://msp.test');
        const path = address.pathname + address.search;
        facts.fetched.push(path);
        const held = address.searchParams.has('held');

        if (held) {
            address.searchParams.delete('held');
        }

        const list = input.lists[address.pathname + address.search];
        const answer = () => ({ ok: list !== undefined, json: () => Promise.resolve(list) });

        if (held) {
            return new Promise((resolve) => {
                facts.releaseList = () => resolve(answer());
            });
        }

        return Promise.resolve(answer());
    };

    if (phone !== undefined) {
        window.matchMedia = (query) => ({ matches: phone, media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    }

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

// What a screen reader reads of a node: its text without the aria-hidden parts, whitespace collapsed
function spoken(node) {
    if (node.nodeType === 3) {
        return node.textContent;
    }

    if (node.nodeType !== 1 || node.getAttribute('aria-hidden') === 'true') {
        return '';
    }

    return [...node.childNodes].map(spoken).join('');
}

function rowFacts(link) {
    const where = link.querySelector('.mr-d');
    const label = link.querySelector('.mr-l');

    return {
        id: link.getAttribute('data-nav-search-id'),
        label: label ? [...label.childNodes].filter((node) => node.nodeType === 3).map((node) => node.textContent).join('').trim() : null,
        where: where ? where.textContent : null,
        whereSpoken: where ? spoken(where).replace(/\s+/g, ' ').trim() : null,
        whereHidden: where ? [...where.querySelectorAll('[aria-hidden="true"]')].map((node) => node.textContent) : [],
        signInNote: where ? where.hasAttribute('data-nav-search-sign-in-note') : false,
        signIn: link.hasAttribute('data-nav-search-sign-in'),
        href: link.getAttribute('href'),
        nav: link.getAttribute('data-nav'),
        helpOpener: link.getAttribute('data-help-opener'),
        turboFrame: link.getAttribute('data-turbo-frame'),
        scrim: link.getAttribute('data-modal-scrim'),
        rel: link.getAttribute('rel'),
        name: spoken(link).replace(/\s+/g, ' ').trim(),
    };
}

const output = {};

// One page per locale and viewer; its queries typed one after another into the same field
for (const { page: pageName, url, phone, delayList = false, queries } of input.cases || []) {
    const facts = { fetched: [], errors: [], releaseList: null };
    let p = null;

    try {
        p = await page(input.pages[pageName], facts, { url, phone, delayList });
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
    const barHelp = p.document.querySelector('a[data-bar-action="help"]');
    field.dispatchEvent(new p.window.Event('focus'));

    for (const { name, query, expand = false, serverResults = false } of queries) {
        const errors = facts.errors.length;

        try {
            field.value = query;
            field.dispatchEvent(new p.window.Event('input', { bubbles: true }));
            await settle(20);

            // The Live re-render's players and puzzles for this query, after the group's place (as GlobalSearch renders)
            let groupBeforeList = null;

            if (serverResults) {
                const server = p.document.createElement('div');
                server.className = 'global-search-results';
                server.innerHTML = '<h3>Players</h3><a href="/en/player-profile/x">A player</a>';
                results.after(server);
                groupBeforeList = { hidden: results.hidden, rows: results.querySelectorAll('a.nav-search-row').length };

                if (typeof facts.releaseList === 'function') {
                    facts.releaseList();
                    facts.releaseList = null;
                    await settle(20);
                }
            }

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
                barHelpHref: barHelp ? barHelp.getAttribute('href') : null,
                groupBeforeList,
                audience: p.document.body.getAttribute('data-viewer-audience'),
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
