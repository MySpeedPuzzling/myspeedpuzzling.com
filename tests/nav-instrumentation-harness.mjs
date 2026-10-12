// Runs assets/nav_instrumentation.js (navigation clicks, the viewport once per session, Back/Forward navigations) for
// the scenarios tests/NavInstrumentationScriptTest.php hands over on stdin, and prints what each one did as JSON:
// the beacons sent (parsed), the `msp:nav` events dispatched and the gtag calls - plus parseNav(), loggablePage() and
// viewportBucket() of the values it is given. The browser is a fake with just what the module touches; every "page
// load" of a scenario starts the module again on a fresh window that keeps the session storage (and, unless told
// otherwise, the history position).

import { readFileSync } from 'node:fs';
import { BEACON_URL, createNavInstrumentation, loggablePage, parseNav, viewportBucket } from '../assets/nav_instrumentation.js';

const input = JSON.parse(readFileSync(0, 'utf8'));

class Listeners {
    constructor() {
        this.byType = {};
    }

    addEventListener(type, listener, options) {
        (this.byType[type] = this.byType[type] || []).push({ listener, capture: options === true || Boolean(options && options.capture) });
    }

    fire(type, event) {
        for (const { listener } of this.byType[type] || []) {
            listener(event);
        }
    }
}

class FakeCustomEvent {
    constructor(type, init = {}) {
        this.type = type;
        this.bubbles = Boolean(init.bubbles);
        this.detail = init.detail;
    }
}

/** <tag data-nav=...> inside <body>: the clicked element first, its ancestors after it */
function elementChain(path, dispatched) {
    const nodes = [...path, { tag: 'BODY' }].map((node) => ({
        tagName: node.tag || 'SPAN',
        attributes: node.attributes || {},
        parentElement: null,
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
        },
        // Only what the module asks: a comma list of `tag[attribute]`
        closest(selector) {
            const wanted = selector.split(',').map((part) => part.trim().match(/^([a-z]+)\[([a-z-]+)\]$/));

            for (let element = this; element; element = element.parentElement) {
                if (wanted.some((match) => match && element.tagName === match[1].toUpperCase() && element.getAttribute(match[2]) !== null)) {
                    return element;
                }
            }

            return null;
        },
        dispatchEvent(event) {
            dispatched.push({ type: event.type, bubbles: event.bubbles, detail: event.detail, on: this.tagName });
            return true;
        },
    }));

    nodes.forEach((node, index) => {
        node.parentElement = nodes[index + 1] || null;
    });

    return nodes[0];
}

function sessionStorage(mode) {
    if (mode === 'throws') {
        return { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('SecurityError'); } };
    }

    const items = {};

    return {
        getItem: (key) => (Object.prototype.hasOwnProperty.call(items, key) ? items[key] : null),
        setItem: (key, value) => { items[key] = String(value); },
    };
}

function run(scenario) {
    const out = { beacons: [], events: [], gtag: [] };
    const storage = scenario.storage === 'none' ? null : sessionStorage(scenario.storage);
    let page = null;

    // One page load: a fresh window/document, the module started on it
    function load(options = {}) {
        const media = { '(pointer: coarse)': true, ...(options.media || scenario.media || {}) };
        const window = new Listeners();
        const document = new Listeners();
        const navigationApi = options.navigation === false ? null : Object.assign(new Listeners(), {
            currentEntry: { index: options.index ?? 0 },
            activation: options.activation === undefined ? { navigationType: 'push', from: null, entry: { index: options.index ?? 0 } } : options.activation,
        });

        Object.assign(window, {
            innerWidth: options.width ?? scenario.width ?? 360,
            location: { pathname: options.page || '/en/faq' },
            matchMedia: (query) => ({ matches: Boolean(media[query]) }),
            navigation: navigationApi,
            history: { state: options.turboIndex === undefined ? null : { turbo: { restorationIndex: options.turboIndex } } },
            performance: { getEntriesByType: () => (options.timingType ? [{ type: options.timingType }] : []) },
            CustomEvent: FakeCustomEvent,
            gaLoaded: Boolean(options.gaLoaded),
            gtag: (...args) => out.gtag.push(args),
        });

        const navigator = {
            webdriver: Boolean(options.webdriver),
            sendBeacon(url, body) {
                out.beacons.push({ url, ...JSON.parse(body) });
                return true;
            },
        };

        const instrumentation = createNavInstrumentation({ window, document, navigator, storage }).start();
        page = { window, document, navigationApi, instrumentation };
    }

    for (const step of scenario.steps) {
        switch (step.do) {
            case 'load':
                load(step);
                break;
            case 'click':
                page.document.fire('click', { type: 'click', target: elementChain(step.path, out.events) });
                break;
            case 'turbo:load':
                if (page.navigationApi) page.navigationApi.currentEntry = { index: step.index };
                page.window.history.state = { turbo: { restorationIndex: step.index } };
                page.document.fire('turbo:load', {});
                break;
            case 'navigate':
                // The Navigation API's navigate event of a traverse, then the popstate it ends in
                if (page.navigationApi) page.navigationApi.fire('navigate', { navigationType: 'traverse', hasUAVisualTransition: step.uaTransition });
                // falls through
            case 'popstate':
                if (page.navigationApi) page.navigationApi.currentEntry = { index: step.index };
                page.window.history.state = { turbo: { restorationIndex: step.index } };
                page.window.fire('popstate', step.do === 'popstate' ? { hasUAVisualTransition: step.uaTransition } : {});
                break;
            case 'turbo restore':
                page.instrumentation.turboRestore();
                break;
            case 'pageshow':
                // A back/forward cache restore: the module does not run again, the browser updates the activation
                if (step.activation && page.navigationApi) page.navigationApi.activation = step.activation;
                page.window.fire('pageshow', { persisted: Boolean(step.persisted) });
                break;
            default:
                throw new Error('Unknown step ' + step.do);
        }
    }

    return out;
}

process.stdout.write(JSON.stringify({
    beaconUrl: BEACON_URL,
    parsed: (input.parse || []).map((value) => parseNav(value)),
    pages: (input.pages || []).map((value) => loggablePage(value)),
    buckets: (input.widths || []).map((width) => viewportBucket(width)),
    scenarios: (input.scenarios || []).map(run),
}));
