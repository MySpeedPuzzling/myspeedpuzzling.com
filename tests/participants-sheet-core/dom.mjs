// A browser for the DOM suites (grid, people, controller): jsdom from the project's node_modules (a dev dependency in
// package-lock.json), its window installed as the globals the sheet's modules use. Not a suite itself.
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

export function setupDom({ url = 'https://example.test/en/participants-sheet/c1' } = {}) {
    const { JSDOM } = require('jsdom');
    const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>', { pretendToBeVisual: true, url });
    const win = dom.window;
    const globals = {
        window: win,
        document: win.document,
        HTMLElement: win.HTMLElement,
        Element: win.Element,
        Node: win.Node,
        Event: win.Event,
        KeyboardEvent: win.KeyboardEvent,
        MouseEvent: win.MouseEvent,
        FocusEvent: win.FocusEvent,
        CustomEvent: win.CustomEvent,
        MutationObserver: win.MutationObserver,
        getComputedStyle: win.getComputedStyle.bind(win),
        requestAnimationFrame: (fn) => setTimeout(fn, 0),
        cancelAnimationFrame: (id) => clearTimeout(id),
        CSS: { escape: (value) => String(value).replace(/["\\]/g, '\\$&') },
    };

    for (const [name, value] of Object.entries(globals)) {
        Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
    }

    win.requestAnimationFrame = globals.requestAnimationFrame;
    win.cancelAnimationFrame = globals.cancelAnimationFrame;
    win.Element.prototype.scrollIntoView = function () {};
    win.matchMedia = (query) => {
        const media = { matches: false, media: query, listeners: new Set() };
        media.addEventListener = (type, fn) => media.listeners.add(fn);
        media.removeEventListener = (type, fn) => media.listeners.delete(fn);

        return media;
    };

    // jsdom has no modal dialogs
    if (typeof win.HTMLDialogElement.prototype.showModal !== 'function') {
        win.HTMLDialogElement.prototype.showModal = function () {
            this.setAttribute('open', '');
        };
        win.HTMLDialogElement.prototype.close = function () {
            if (this.hasAttribute('open')) {
                this.removeAttribute('open');
                this.dispatchEvent(new win.Event('close'));
            }
        };
    }

    return dom;
}

/** A key pressed on an element (keydown, bubbling, cancelable). Returns the event. */
export function key(element, keyName, options = {}) {
    const event = new window.KeyboardEvent('keydown', { key: keyName, bubbles: true, cancelable: true, ...options });
    element.dispatchEvent(event);

    return event;
}

/** Texts answering their key (and the params a test may look for). */
export const TEXTS = {
    t: (key, params = {}) => (Object.keys(params).length > 0 ? `${key} ${JSON.stringify(params)}` : key),
    tc: (key, count) => `${key} ${count}`,
    has: () => true,
};

export async function tick(times = 3) {
    for (let i = 0; i < times; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}
