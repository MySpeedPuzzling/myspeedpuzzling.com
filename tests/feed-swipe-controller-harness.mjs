// Runs the real assets/controllers/feed_swipe_controller.js (swipe between Home's two feeds, docs/features/navigation
// README §4.13 G14, GEST-16) under Stimulus in jsdom, with Bootstrap's real Tab, for tests/FeedSwipeScriptTest.php, and
// prints {case: facts} as JSON. The Platform feel review's F1: without `scrollend` (Safari before it, older iOS) a pane
// ≥ 60 % in view selects its tab at once - Bootstrap runs the link's `shown.bs.tab` synchronously inside show() - and
// nothing more: the pane the finger still holds never rests, never turns inert, keeps the scroller's height and the
// page's scroll until the scroller settles (the scroll at rest, the finger lifted). The scrollend path and the tab tap
// are run the same way.
//
// The browser is faked where jsdom has nothing: the layout (two 360 px panes 16 px apart, the panes' heights - a resting
// pane has none - the tab boxes), matchMedia (a phone, no reduced motion), IntersectionObserver (the harness reports what
// crosses 60 %), ResizeObserver, requestAnimationFrame, the scroll position, scrollTo() and the clock (setTimeout: the
// 150 ms idle wait runs when the case advances it).

import { createRequire, registerHooks } from 'node:module';
import { Application } from '@hotwired/stimulus';
import { PHONE_QUERY, SCROLL_IDLE_MS, SHOWN_EVENT } from '../assets/feed_swipe.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

// Bootstrap as the bundle takes it (package.json "module", its ESM build): node's own resolution hands over the UMD
// build, which has no named exports for `import { Tab } from 'bootstrap'`
const BOOTSTRAP_ESM = new URL('../node_modules/bootstrap/dist/js/bootstrap.esm.js', import.meta.url).href;

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier === 'bootstrap') {
            return { url: BOOTSTRAP_ESM, format: 'module', shortCircuit: true };
        }

        return nextResolve(specifier, context);
    },
});

// ---- The fake clock (the controller's setTimeout: the idle wait; Bootstrap's emulated transition end) -------------

const realSetTimeout = setTimeout;
const clock = { now: 0, id: 0, queue: [] };

globalThis.setTimeout = (callback, ms = 0, ...args) => {
    clock.id++;
    clock.queue.push({ id: clock.id, at: clock.now + Math.max(0, Number(ms) || 0), run: () => callback(...args) });

    return clock.id;
};
globalThis.clearTimeout = (id) => {
    clock.queue = clock.queue.filter((timer) => timer.id !== id);
};

function advance(ms) {
    const end = clock.now + ms;

    for (;;) {
        clock.queue.sort((a, b) => a.at - b.at || a.id - b.id);
        const next = clock.queue[0];

        if (!next || next.at > end) {
            break;
        }

        clock.queue.shift();
        clock.now = next.at;
        next.run();
    }

    clock.now = end;
}

const microtasks = async (rounds = 5) => {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => realSetTimeout(resolve, 0));
    }
};

// ---- One window for every case (Bootstrap registers its data-api on the document it was imported with) -------------

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', {
    url: 'https://msp.test/en/hub',
    pretendToBeVisual: false,
    virtualConsole: new VirtualConsole(),
});
const { window } = dom;
const { document } = window;

const frames = [];
const observers = [];
const facts = { pageScrolls: [], scrollerScrolls: [] };
const media = { phone: true };

class FakeIntersectionObserver {
    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.targets = [];
        this.disconnected = false;
        observers.push(this);
    }

    observe(target) {
        this.targets.push(target);
    }

    unobserve(target) {
        this.targets = this.targets.filter((t) => t !== target);
    }

    disconnect() {
        this.disconnected = true;
        this.targets = [];
    }
}

class FakeResizeObserver {
    observe() {}

    unobserve() {}

    disconnect() {}
}

Object.assign(globalThis, {
    window,
    document,
    Node: window.Node,
    Element: window.Element,
    HTMLElement: window.HTMLElement,
    Event: window.Event,
    CustomEvent: window.CustomEvent,
    MouseEvent: window.MouseEvent,
    KeyboardEvent: window.KeyboardEvent,
    MutationObserver: window.MutationObserver,
    getComputedStyle: (element, pseudo) => window.getComputedStyle(element, pseudo),
    IntersectionObserver: FakeIntersectionObserver,
    ResizeObserver: FakeResizeObserver,
    requestAnimationFrame: (callback) => frames.push(callback),
    cancelAnimationFrame: () => {},
});
Object.defineProperty(globalThis, 'navigator', { value: window.navigator, configurable: true, writable: true });

window.matchMedia = (query) => ({
    media: query,
    matches: query === PHONE_QUERY ? media.phone : false,
    addEventListener() {},
    removeEventListener() {},
});
window.scrollTo = (options) => facts.pageScrolls.push(options && typeof options === 'object' ? { top: options.top, behavior: options.behavior } : options);
Object.defineProperty(window, 'scrollY', { configurable: true, writable: true, value: 0 });

// jsdom knows `scrollend`; a case without it removes the handler property wherever it sits (the controller asks
// `'onscrollend' in window`) and puts it back afterwards
function withoutScrollEnd() {
    const removed = [];

    for (let object = window; object; object = Object.getPrototypeOf(object)) {
        const descriptor = Object.getOwnPropertyDescriptor(object, 'onscrollend');

        if (descriptor) {
            removed.push([object, descriptor]);
            delete object.onscrollend;
        }
    }

    return () => removed.forEach(([object, descriptor]) => Object.defineProperty(object, 'onscrollend', descriptor));
}

const { default: FeedSwipeController } = await import('../assets/controllers/feed_swipe_controller.js');

const application = Application.start(document.documentElement);
const errors = [];
application.handleError = (error) => errors.push(String(error && error.stack ? error.stack : error));
application.register('feed-swipe', FeedSwipeController);

// ---- The Hub's switcher (templates/hub.html.twig), Favorites first ---------------------------------------------------

const PANE_WIDTH = 360;
const GAP = 16;
const PITCH = PANE_WIDTH + GAP;
const HEIGHTS = [900, 700];
const TAB_BOXES = [{ left: 0, width: 190 }, { left: 190, width: 170 }];

function markup() {
    return `<div class="col-lg-6" data-controller="feed-swipe">
        <ul class="nav nav-fill nav-tabs hub-feed-tabs" role="tablist" data-feed-swipe-target="tabs">
            <li class="nav-item"><a class="nav-link active" id="tab-favorites" href="#recent-favorites-activity" data-bs-toggle="tab" role="tab" aria-controls="recent-favorites-activity" aria-selected="true">Favorites</a></li>
            <li class="nav-item"><a class="nav-link" id="tab-everyone" href="#recent-activity" data-bs-toggle="tab" role="tab" aria-controls="recent-activity" aria-selected="false">Everyone</a></li>
        </ul>
        <div class="tab-content hub-feeds" data-feed-swipe-target="scroller">
            <div class="tab-pane fade show active" id="recent-favorites-activity" role="tabpanel" data-feed-swipe-target="pane"><p>Favorites feed</p></div>
            <div class="tab-pane fade is-resting" id="recent-activity" role="tabpanel" data-feed-swipe-target="pane"><p>Everyone feed</p></div>
        </div>
    </div>`;
}

// What a case changed on the window (scrollend removed): put back after every case, whatever happened in it
let restoreWindow = () => {};

/** A page: the switcher with its layout faked, the controller connected */
async function page({ scrollEnd, scrolledBelowTabs = false }) {
    restoreWindow = scrollEnd ? () => {} : withoutScrollEnd();
    observers.length = 0;
    frames.length = 0;
    facts.pageScrolls = [];
    facts.scrollerScrolls = [];
    window.scrollY = scrolledBelowTabs ? 1200 : 0;

    document.body.innerHTML = markup();

    const scroller = document.querySelector('.hub-feeds');
    const tabList = document.querySelector('.hub-feed-tabs');
    const panes = [...scroller.querySelectorAll('.tab-pane')];
    const tabs = [...tabList.querySelectorAll('[data-bs-toggle="tab"]')];
    const shown = [];
    let scrollLeft = 0;

    Object.defineProperty(scroller, 'scrollLeft', { configurable: true, get: () => scrollLeft, set: (value) => { scrollLeft = Number(value); } });
    Object.defineProperty(scroller, 'clientWidth', { configurable: true, get: () => PANE_WIDTH });
    // The tab's own scroll: the controller asks for it, the browser's scroll events follow (the case sends them)
    scroller.scrollTo = (options) => facts.scrollerScrolls.push({ left: options.left, behavior: options.behavior });
    panes.forEach((pane, index) => {
        Object.defineProperty(pane, 'offsetLeft', { configurable: true, get: () => index * PITCH });
        pane.getBoundingClientRect = () => {
            const height = pane.classList.contains('is-resting') ? 0 : HEIGHTS[index];

            return { left: index * PITCH - scrollLeft, top: 200, width: PANE_WIDTH, height, right: index * PITCH - scrollLeft + PANE_WIDTH, bottom: 200 + height };
        };
    });
    tabs.forEach((tab, index) => {
        Object.defineProperty(tab, 'offsetLeft', { configurable: true, get: () => TAB_BOXES[index].left });
        Object.defineProperty(tab, 'offsetWidth', { configurable: true, get: () => TAB_BOXES[index].width });
        Object.defineProperty(tab, 'offsetTop', { configurable: true, get: () => 0 });
        Object.defineProperty(tab, 'offsetHeight', { configurable: true, get: () => 48 });
    });
    // Scrolled 1200 px down: the tabs went under the 56 px bar
    tabList.getBoundingClientRect = () => ({ top: scrolledBelowTabs ? -800 : 120, left: 0, width: PANE_WIDTH, height: 48 });
    document.addEventListener(SHOWN_EVENT, (event) => shown.push(event.detail.pane.id));

    await microtasks();

    const controller = application.getControllerForElementAndIdentifier(document.querySelector('[data-controller="feed-swipe"]'), 'feed-swipe');

    const state = () => ({
        scrollLeft,
        selectedTab: tabs.findIndex((tab) => tab.getAttribute('aria-selected') === 'true'),
        activeTab: tabs.findIndex((tab) => tab.classList.contains('active')),
        panes: panes.map((pane) => ({ resting: pane.classList.contains('is-resting'), inert: pane.inert === true })),
        height: scroller.style.height,
        shown: [...shown],
        pageScrolls: [...facts.pageScrolls],
    });

    return {
        controller,
        scroller,
        panes,
        tabs,
        state,
        observed: () => observers.filter((o) => !o.disconnected).map((o) => ({ threshold: o.options.threshold, root: o.options.root === scroller, targets: o.targets.length })),
        touch(type, remaining = 0) {
            const event = new window.Event(type, { bubbles: true });
            Object.defineProperty(event, 'touches', { value: Array.from({ length: remaining }, () => ({})) });
            panes[0].dispatchEvent(event);
        },
        /** The scroller moves to `left` (one frame): a scroll event, the frame; what crosses 60 % is reported */
        scrollTo(left) {
            const before = panes.map((pane, index) => visibleRatio(index, scrollLeft));
            scrollLeft = left;
            scroller.dispatchEvent(new window.Event('scroll'));
            frames.splice(0).forEach((callback) => callback(clock.now));
            const after = panes.map((pane, index) => visibleRatio(index, scrollLeft));
            const crossed = panes.map((pane, index) => ({ target: pane, isIntersecting: after[index] > 0, intersectionRatio: after[index] }))
                .filter((entry, index) => (before[index] >= 0.6) !== (after[index] >= 0.6));

            if (crossed.length > 0) {
                observers.filter((o) => !o.disconnected).forEach((o) => o.callback(crossed.filter((entry) => o.targets.includes(entry.target)), o));
            }

            advance(16);
        },
        scrollEnd() {
            scroller.dispatchEvent(new window.Event('scrollend'));
        },
        advance,
    };
}

function visibleRatio(index, scrollLeft) {
    const left = index * PITCH - scrollLeft;
    const visible = Math.min(left + PANE_WIDTH, PANE_WIDTH) - Math.max(left, 0);

    return Math.max(0, Math.min(1, visible / PANE_WIDTH));
}

/** A finger drags from pane 0 to `to` px in steps of 30 px (each a frame) */
function drag(p, to) {
    const from = p.scroller.scrollLeft;
    const step = to > from ? 30 : -30;

    for (let left = from + step; step > 0 ? left < to : left > to; left += step) {
        p.scrollTo(left);
    }

    p.scrollTo(to);
}

/** Facts noted after every frame of a drag: was the pane the finger came from ever resting, inert or cut short? */
function watch(p, index) {
    const seen = { resting: false, inert: false, heights: new Set(), pageScrolls: 0, shown: 0 };
    const original = p.scrollTo;

    p.scrollTo = (left) => {
        original(left);
        const st = p.state();
        seen.resting ||= st.panes[index].resting;
        seen.inert ||= st.panes[index].inert;
        seen.heights.add(st.height);
        seen.pageScrolls = st.pageScrolls.length;
        seen.shown = st.shown.length;
    };

    return () => ({ resting: seen.resting, inert: seen.inert, heights: [...seen.heights], pageScrolls: seen.pageScrolls, shown: seen.shown });
}

const cases = [];
const run = (name, fn) => cases.push([name, fn]);

// ---- F1: without scrollend -------------------------------------------------------------------------------------------

run('no scrollend: the observer', async () => {
    const p = await page({ scrollEnd: false });
    const result = { hasScrollEnd: p.controller.hasScrollEnd, observers: p.observed(), atLoad: p.state() };

    return result;
});

run('no scrollend: a finger holding at 65 % of the way', async () => {
    const p = await page({ scrollEnd: false, scrolledBelowTabs: true });
    p.touch('touchstart', 1);
    const atTouch = p.state();
    const seen = watch(p, 0);
    drag(p, Math.round(PITCH * 0.65));
    // The finger pauses: no scroll event for 300 ms (the idle wait runs out meanwhile)
    p.advance(300);
    const holding = p.state();
    const during = seen();

    // Lifted: the snap scrolls on to Everyone, the scroll rests
    p.touch('touchend', 0);
    drag(p, PITCH);
    const snapped = p.state();
    p.advance(SCROLL_IDLE_MS);
    const settled = p.state();

    return { atTouch, during, holding, snapped, settled };
});

run('no scrollend: to 65 % and back', async () => {
    const p = await page({ scrollEnd: false, scrolledBelowTabs: true });
    p.touch('touchstart', 1);
    const seen = watch(p, 0);
    drag(p, Math.round(PITCH * 0.65));
    const out = p.state();
    p.advance(300);
    drag(p, 0);
    const back = p.state();
    p.touch('touchend', 0);
    p.advance(SCROLL_IDLE_MS);
    const settled = p.state();

    return { out: out.selectedTab, back: back.selectedTab, during: seen(), settled };
});

run('no scrollend: a second finger lifting keeps the hold', async () => {
    const p = await page({ scrollEnd: false });
    p.touch('touchstart', 2);
    drag(p, Math.round(PITCH * 0.65));
    p.touch('touchend', 1);
    p.advance(300);
    const holding = p.state();
    p.touch('touchend', 0);
    drag(p, PITCH);
    p.advance(SCROLL_IDLE_MS);
    const settled = p.state();

    return { holding, settled };
});

run('no scrollend: a wheel or trackpad at rest settles after the idle wait', async () => {
    const p = await page({ scrollEnd: false });
    drag(p, PITCH);
    p.advance(SCROLL_IDLE_MS - 20);
    const waiting = p.state();
    p.advance(20);
    const settled = p.state();

    return { waiting, settled };
});

run('no scrollend: a tab tapped', async () => {
    const p = await page({ scrollEnd: false });
    p.tabs[1].click();
    const tapped = p.state();
    drag(p, PITCH);
    p.advance(SCROLL_IDLE_MS);
    const settled = p.state();

    return { scrollerScrolls: [...facts.scrollerScrolls], tapped, settled };
});

// ---- The scrollend path ----------------------------------------------------------------------------------------------

run('scrollend: a swipe settles at scrollend only', async () => {
    const p = await page({ scrollEnd: true, scrolledBelowTabs: true });
    p.touch('touchstart', 1);
    const seen = watch(p, 0);
    drag(p, Math.round(PITCH * 0.65));
    p.advance(300);
    const holding = p.state();
    p.touch('touchend', 0);
    drag(p, PITCH);
    p.advance(500);
    const beforeEnd = p.state();
    p.scrollEnd();
    const settled = p.state();

    return { hasScrollEnd: p.controller.hasScrollEnd, observers: p.observed(), during: seen(), holding, beforeEnd, settled };
});

run('scrollend: a tab tapped', async () => {
    const p = await page({ scrollEnd: true });
    p.tabs[1].click();
    drag(p, PITCH);
    p.scrollEnd();
    const settled = p.state();
    // Back to Favorites
    p.tabs[0].click();
    drag(p, 0);
    p.scrollEnd();
    const back = p.state();

    return { scrollerScrolls: [...facts.scrollerScrolls], settled, back };
});

run('from 768 px: Bootstrap tabs, only the hidden pane inert', async () => {
    media.phone = false;
    const p = await page({ scrollEnd: true });
    const atLoad = p.state();
    p.tabs[1].click();
    const clicked = p.state();
    const awake = p.scroller.classList.contains('is-awake');

    return { atLoad, clicked, awake, scrollerScrolls: [...facts.scrollerScrolls] };
});

const results = {};

for (const [name, fn] of cases) {
    try {
        results[name] = await fn();
    } catch (error) {
        results[name] = { threw: String(error && error.stack ? error.stack : error) };
    }

    restoreWindow();
    restoreWindow = () => {};
    media.phone = true;
    document.body.innerHTML = '';
    await microtasks();
}

results.errors = errors;
process.stdout.write(JSON.stringify(results));
