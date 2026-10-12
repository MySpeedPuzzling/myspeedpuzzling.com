// Runs assets/help_openers.js (the Help sheet's openers, HELP review F4/F5/Q2) through the scenarios
// tests/HelpOpenersScriptTest.php asks for and prints one JSON object of results. The document, the window and the
// layer helper are plain fakes: listeners kept in a map, elements answer closest()/getAttribute()/focus().

import { hrefForCurrentPage, HELP_PAGE_SELECTOR, installHelpOpeners, OPENER_SELECTOR, ownHelpTarget } from '../assets/help_openers.js';

const results = {};

// The address as it is now: path + query, set into url= (encoded), the rest of the href kept
results.rewrite = {
    page: hrefForCurrentPage('/en/help?url=%2Fen%2Fpuzzle', '/en/puzzle?search=abc&pieces=500', 'http://site/en/puzzle?search=abc&pieces=500'),
    otherParams: hrefForCurrentPage('/en/help?x=1&url=%2Fen%2Ffaq#top', '/en/faq?tab=2', 'http://site/en/faq?tab=2'),
    ja: hrefForCurrentPage('/ja/%E3%83%98%E3%83%AB%E3%83%97?url=%2Fja%2Fx', '/ja/%E3%83%91%E3%82%BA%E3%83%AB?q=1', 'http://site/ja/x'),
    bare: hrefForCurrentPage('/en/help', '/en/faq?x=1', 'http://site/en/faq'),
    notAPath: hrefForCurrentPage('/en/help?url=%2Fen%2Ffaq', 'en/faq', 'http://site/en/faq'),
    broken: hrefForCurrentPage('http://[broken', '/en/faq', 'http://site/en/faq'),
};

function element(attributes = {}, extra = {}) {
    const el = {
        attributes: { ...attributes },
        focused: false,
        scrolled: null,
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
        },
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
        closest(selector) {
            return selector === OPENER_SELECTOR && this.getAttribute('data-help-opener') !== null ? this : null;
        },
        focus() {
            this.focused = true;
        },
        scrollIntoView(options) {
            this.scrolled = options;
        },
        ...extra,
    };

    return el;
}

function helpPage({ field, signIn } = {}) {
    return {
        querySelector(selector) {
            if (selector === '[data-help-sheet-target="message"]') {
                return field || null;
            }

            if (selector === '[data-help-item="feedback_sign_in"] a') {
                return signIn || null;
            }

            return null;
        },
        contains: () => false,
    };
}

function world({ page = null, path = '/en/puzzle', search = '?search=abc' } = {}) {
    const listeners = {};
    const closed = [];
    const document = {
        addEventListener(type, fn, capture) {
            listeners[type + (capture ? ':capture' : '')] = fn;
        },
        removeEventListener(type, fn, capture) {
            if (listeners[type + (capture ? ':capture' : '')] === fn) {
                delete listeners[type + (capture ? ':capture' : '')];
            }
        },
        querySelector: (selector) => (selector === HELP_PAGE_SELECTOR ? page : null),
    };
    const window = { location: { pathname: path, search, href: 'http://site' + path + search } };
    const layers = {
        closeThen(fn) {
            closed.push('closeThen');
            fn();
        },
    };
    const uninstall = installHelpOpeners({ window, document, layers });

    const fire = (type, opener, props = {}) => {
        const event = {
            target: opener,
            defaultPrevented: false,
            button: 0,
            altKey: false,
            ctrlKey: false,
            metaKey: false,
            shiftKey: false,
            ...props,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        listeners[type + ':capture'](event);

        return event;
    };

    return { listeners, closed, fire, uninstall };
}

// F4: a page opener follows the address at the tap; a private link opener keeps its token-free path; a click someone
// already handled is left alone; the middle button's auxclick rewrites too (a new tab gets the page as it is now)
{
    const w = world();
    const opener = element({ href: '/en/help?url=%2Fen%2Fpuzzle', 'data-help-opener': 'page' });
    const event = w.fire('click', opener);
    const privateOpener = element({ href: '/en/help?url=%2Fen%2Fnewsletter%2Fconfirm%2F%7Btoken%7D', 'data-help-opener': 'private' });
    w.fire('click', privateOpener);
    const handled = element({ href: '/en/help?url=%2Fen%2Fpuzzle', 'data-help-opener': 'page' });
    w.fire('click', handled, { defaultPrevented: true });
    const middle = element({ href: '/en/help?url=%2Fen%2Fpuzzle', 'data-help-opener': 'page' });
    w.fire('auxclick', middle, { button: 1 });
    const plainLink = element({ href: '/en/faq' }, { closest: () => null });
    w.fire('click', plainLink);

    results.follow = {
        href: opener.getAttribute('href'),
        prevented: event.defaultPrevented,
        privateHref: privateOpener.getAttribute('href'),
        handledHref: handled.getAttribute('href'),
        middleHref: middle.getAttribute('href'),
        plainHref: plainLink.getAttribute('href'),
        layersUsed: w.closed.length,
    };

    w.uninstall();
    results.uninstalled = Object.keys(w.listeners).length;
}

// F5: on the help page an opener does not open the sheet over it - an open layer closes first, then the box's field is
// scrolled to and focused; a guest's page leads to "Sign in to send feedback"; a modified click (new tab) is the browser's
{
    const field = element();
    const w = world({ page: helpPage({ field }), path: '/en/help', search: '?url=%2Fen%2Ffaq' });
    const opener = element({ href: '/en/help', 'data-help-opener': 'page' });
    const event = w.fire('click', opener);
    const newTab = w.fire('click', element({ href: '/en/help', 'data-help-opener': 'page' }), { metaKey: true });

    results.ownBox = {
        prevented: event.defaultPrevented,
        layers: w.closed,
        focused: field.focused,
        scrolled: field.scrolled,
        href: opener.getAttribute('href'),
        newTabPrevented: newTab.defaultPrevented,
    };
}

{
    const signIn = element();
    const w = world({ page: helpPage({ signIn }), path: '/en/help', search: '' });
    const event = w.fire('click', element({ href: '/en/help', 'data-help-opener': 'page' }));

    results.guestPage = { prevented: event.defaultPrevented, focused: signIn.focused };
    results.targets = {
        field: ownHelpTarget(helpPage({ field: element({ id: 'f' }) }))?.getAttribute('id') ?? null,
        none: ownHelpTarget(helpPage({})),
        noPage: ownHelpTarget(null),
    };
}

process.stdout.write(JSON.stringify(results));
