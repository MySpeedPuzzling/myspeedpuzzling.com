// Runs the real assets/controllers/barcode_scanner_controller.js under Stimulus in jsdom on pages the PHP test rendered
// (tests/Navigation/SearchCameraScriptTest.php hands them over on stdin) and prints {case: facts} as JSON.
// The page search's scanner (both GlobalSearch instances of a member's page) renders an empty wrapper and copies the
// page's one camera <template> in on its first start (docs/features/navigation README §4.12, the chrome's weight):
// - before any start, a `barcode-scan:close` (every page search's opening sends one) and a disconnect leave it alone;
// - a start copies the markup in once - the same elements the instance rendered itself before (compared with the
//   markup the test renders the old way), the HUD's data-nav on this surface - and the camera runs in it: the video
//   gets the stream, the hint shows, the HUD's zoom and light work on the copied buttons, a stop releases everything,
//   a second start reuses the copy, a code read through it is handed over as before;
// - the page's own scanners (the add form's modal, multiscan) render their camera themselves: they start in their own
//   markup and copy nothing.
// The browser APIs the controller uses are fakes the harness controls: getUserMedia (one video track with zoom, light
// and focus), the video's size and play(), the canvas, BarcodeDetector (the browser's detector, so no script is
// loaded) and requestAnimationFrame (frames run on demand).

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { Application } from '@hotwired/stimulus';
import BarcodeScannerController from '../assets/controllers/barcode_scanner_controller.js';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');

const input = JSON.parse(readFileSync(0, 'utf8'));
const CODE = '4006381333931';

const settle = async (rounds = 10) => {
    for (let i = 0; i < rounds; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

/** One camera: a stream with one video track that records what is asked of it */
function fakeCamera(facts) {
    const track = {
        kind: 'video',
        label: 'fake camera',
        readyState: 'live',
        constraints: {},
        getCapabilities: () => ({ zoom: { min: 1, max: 4, step: 0.1 }, torch: true, focusMode: ['continuous', 'manual'], focusDistance: { min: 0.1, max: 1 } }),
        getSettings: () => ({ width: 1920, height: 1080, frameRate: 30 }),
        getConstraints() {
            return this.constraints;
        },
        applyConstraints(constraints) {
            this.constraints = constraints;
            facts.applied.push(constraints.advanced || []);

            return Promise.resolve();
        },
        stop() {
            this.readyState = 'ended';
        },
    };
    const stream = {
        get active() {
            return track.readyState === 'live';
        },
        getTracks: () => [track],
        getVideoTracks: () => [track],
    };

    return { stream, track };
}

/**
 * A page: jsdom with the rendered HTML, the controller registered on it and the fakes installed as the globals the
 * controller (and Stimulus) read at run time. Cases run one after another, each with its own page.
 */
async function page(html, { detect = () => [], url = 'https://msp.test/en/faq', onCamera = () => {} } = {}) {
    const dom = new JSDOM(html, { url, virtualConsole: new VirtualConsole() });
    const { window } = dom;
    const facts = { applied: [], streams: [], errors: [], consoleErrors: [], scanned: [] };
    const frames = new Map();
    let frameId = 0;

    window.HTMLMediaElement.prototype.play = function () {
        this.__playing = true;

        return Promise.resolve();
    };
    window.HTMLMediaElement.prototype.pause = function () {
        this.__playing = false;
    };
    window.HTMLMediaElement.prototype.load = function () {};
    Object.defineProperty(window.HTMLMediaElement.prototype, 'srcObject', {
        configurable: true,
        get() {
            return this.__srcObject || null;
        },
        set(value) {
            this.__srcObject = value;
        },
    });
    for (const [name, size] of [['videoWidth', 1920], ['videoHeight', 1080]]) {
        Object.defineProperty(window.HTMLVideoElement.prototype, name, {
            configurable: true,
            get() {
                return this.__srcObject ? size : 0;
            },
        });
    }
    const context = new Proxy({}, { get: (target, prop) => (prop in target ? target[prop] : () => {}), set: (target, prop, value) => ((target[prop] = value), true) });
    window.HTMLCanvasElement.prototype.getContext = () => context;

    class FakeBarcodeDetector {
        static getSupportedFormats() {
            return Promise.resolve(['ean_8', 'ean_13']);
        }

        detect() {
            return Promise.resolve(detect());
        }
    }
    window.BarcodeDetector = FakeBarcodeDetector;

    const navigator = {
        userAgent: 'Mozilla/5.0 (X11; Linux x86_64)',
        mediaDevices: {
            getUserMedia: () => {
                onCamera();
                const camera = fakeCamera(facts);
                facts.streams.push(camera);

                return Promise.resolve(camera.stream);
            },
            enumerateDevices: () => Promise.resolve([]),
        },
        vibrate: () => true,
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
        localStorage: window.localStorage,
        requestAnimationFrame: (callback) => {
            frameId++;
            frames.set(frameId, callback);

            return frameId;
        },
        cancelAnimationFrame: (id) => frames.delete(id),
    });
    Object.defineProperty(globalThis, 'navigator', { value: navigator, configurable: true, writable: true });

    const originalError = console.error;
    console.error = (...args) => facts.consoleErrors.push(args.map(String).join(' '));
    window.addEventListener('barcode-scanner:scanned', (event) => facts.scanned.push(event.detail.code));

    const application = Application.start(window.document.documentElement);
    application.handleError = (error) => facts.errors.push(String(error && error.stack ? error.stack : error));
    application.register('barcode-scanner', BarcodeScannerController);
    await settle();

    const doc = window.document;
    const rootOf = (selector) => {
        const element = doc.querySelector(selector);

        return element ? element.closest('[data-controller~="barcode-scanner"]') : null;
    };
    const controller = (root) => (root ? application.getControllerForElementAndIdentifier(root, 'barcode-scanner') : null);

    return {
        window,
        document: doc,
        facts,
        application,
        rootOf,
        controller,
        async frames(count) {
            for (let i = 0; i < count; i++) {
                const waiting = [...frames.entries()];
                frames.clear();
                waiting.forEach(([, callback]) => callback(window.performance.now()));
                await settle(4);
            }
        },
        async click(element) {
            element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
            await settle();
        },
        done() {
            application.stop();
            console.error = originalError;
        },
    };
}

/** Whitespace-only text nodes out, the rest trimmed: the DOM as the browser lays it out */
function normalized(node) {
    const clone = node.cloneNode(true);
    const walk = (parent) => {
        [...parent.childNodes].forEach((child) => {
            if (child.nodeType === 3) {
                if (child.textContent.trim() === '') {
                    child.remove();
                } else {
                    child.textContent = child.textContent.trim();
                }
            } else if (child.nodeType === 1) {
                walk(child);
            }
        });
    };
    walk(clone);

    return clone;
}

/** The wrapper's state as the PHP test reads it */
function wrapperFacts(p, wrapper) {
    const root = wrapper.closest('[data-controller~="barcode-scanner"]');
    const video = wrapper.querySelector('[data-barcode-scanner-target="video"]');
    const hint = wrapper.querySelector('[data-barcode-scanner-target="hint"]');
    const pill = wrapper.querySelector('[data-barcode-scanner-target="zoomContainer"]');
    const torch = wrapper.querySelector('[data-barcode-scanner-target="torchButton"]');
    const zoom = wrapper.querySelector('[data-barcode-scanner-target="zoomValue"]');
    const toggle = root.querySelector('[data-barcode-scanner-target="toggleButton"]');

    return {
        shown: !wrapper.classList.contains('d-none'),
        children: wrapper.childElementCount,
        videos: wrapper.querySelectorAll('video').length,
        streamLive: Boolean(video && video.srcObject && video.srcObject.active),
        playing: Boolean(video && video.__playing),
        hintShown: hint ? !hint.classList.contains('d-none') : null,
        pillShown: pill ? !pill.classList.contains('d-none') : null,
        torchShown: torch ? !torch.classList.contains('d-none') : null,
        torchPressed: torch ? torch.getAttribute('aria-pressed') : null,
        zoomLabel: zoom ? zoom.textContent : null,
        hudNavs: [...wrapper.querySelectorAll('[data-nav]')].map((element) => element.getAttribute('data-nav')),
        toggleActive: Boolean(toggle && toggle.classList.contains('active')),
    };
}

const output = {};
const run = async (name, fn) => {
    try {
        output[name] = await fn();
    } catch (error) {
        output[name] = { threw: String(error && error.stack ? error.stack : error) };
    }
};

// ---- The page search: before any start -----------------------------------------------------------------------------

await run('page search before any start', async () => {
    const p = await page(input.pages.faq);
    const template = p.document.getElementById('search-camera');
    const templateHtml = template ? template.innerHTML : null;
    const wrappers = ['search-scan-topbar', 'search-scan-menu'].map((id) => p.document.getElementById(id));

    // Every page search's opening and every Menu field's focus send this: no scanner may trip over its missing camera
    p.window.dispatchEvent(new p.window.CustomEvent('barcode-scan:close'));
    p.window.dispatchEvent(new p.window.CustomEvent('barcode-scan:pause'));
    p.window.dispatchEvent(new p.window.CustomEvent('barcode-scan:resume'));
    await settle();
    const afterClose = wrappers.map((w) => (w ? { children: w.childElementCount, hidden: w.classList.contains('d-none') } : null));

    // A Turbo visit takes the bars away: disconnect stops a scanner that never started
    wrappers.forEach((w) => w && w.closest('[data-controller~="barcode-scanner"]').remove());
    await settle();

    const facts = {
        templates: p.document.querySelectorAll('template#search-camera').length,
        templateInHeader: Boolean(template && template.closest('header.site-header')),
        templateNavs: template ? [...template.content.querySelectorAll('[data-nav]')].map((e) => e.getAttribute('data-nav')) : null,
        wrappers: wrappers.map((w) => (w ? { id: w.id, liveIgnore: w.hasAttribute('data-live-ignore'), children: w.childElementCount } : null)),
        pageVideos: p.document.querySelectorAll('video').length,
        afterClose,
        templateUnchanged: template ? template.innerHTML === templateHtml : null,
        errors: [...p.facts.errors, ...p.facts.consoleErrors],
    };
    p.done();

    return facts;
});

// ---- The page search: a start copies the camera in, the camera runs in it -------------------------------------------

for (const surface of ['topbar', 'menu']) {
    await run(`page search ${surface}`, async () => {
        // The copy as the camera is asked for - copied in, nothing changed in it yet by the camera's capabilities
        let copy = null;
        const p = await page(input.pages.faq, {
            onCamera: () => {
                copy = copy || normalized(p.document.getElementById(`search-scan-${surface}`)).innerHTML;
            },
        });
        const wrapper = p.document.getElementById(`search-scan-${surface}`);
        const other = p.document.getElementById(`search-scan-${surface === 'topbar' ? 'menu' : 'topbar'}`);
        const root = wrapper.closest('[data-controller~="barcode-scanner"]');
        const toggle = root.querySelector('[data-barcode-scanner-target="toggleButton"]');
        const template = p.document.getElementById('search-camera');
        const templateHtml = template.innerHTML;

        await p.click(toggle);
        const started = wrapperFacts(p, wrapper);
        const video = wrapper.querySelector('video');
        const expected = p.document.createElement('div');
        expected.innerHTML = input.expectedWrapper[surface];
        const before = normalized(expected).innerHTML;

        // The copied HUD works: zoom in, the light on
        await p.click(wrapper.querySelector('[data-barcode-scanner-target="zoomIn"]'));
        await p.click(wrapper.querySelector('[data-barcode-scanner-target="torchButton"]'));
        const hud = wrapperFacts(p, wrapper);
        const lastApplied = p.facts.applied[p.facts.applied.length - 1];

        // The scan loop runs on the copied video and canvas
        await p.frames(3);

        // Stop, start again: the same copy
        await p.click(toggle);
        const stopped = { ...wrapperFacts(p, wrapper), firstTrack: p.facts.streams[0].track.readyState };
        await p.click(toggle);
        const again = wrapperFacts(p, wrapper);

        const facts = {
            started,
            sameAsBefore: copy === before,
            copy: copy === before ? null : copy,
            before: copy === before ? null : before,
            videoAttributes: video ? ['muted', 'autoplay', 'playsinline'].filter((a) => video.hasAttribute(a)) : null,
            hud,
            lastApplied,
            stopped,
            again,
            sameVideo: wrapper.querySelector('video') === video,
            streams: p.facts.streams.length,
            otherWrapperChildren: other.childElementCount,
            templateUnchanged: template.innerHTML === templateHtml,
            errors: [...p.facts.errors, ...p.facts.consoleErrors],
        };
        p.done();

        return facts;
    });
}

// A read code: handed over (input, change, barcode-scanner:scanned) and the camera stops
await run('page search read', async () => {
    let reads = 0;
    const p = await page(input.pages.faq, { detect: () => (++reads, [{ rawValue: CODE, cornerPoints: [] }]) });
    const wrapper = p.document.getElementById('search-scan-topbar');
    const root = wrapper.closest('[data-controller~="barcode-scanner"]');
    const inputElement = root.querySelector('[data-barcode-scanner-target="input"]');
    let changes = 0;
    inputElement.addEventListener('change', () => changes++);

    await p.click(root.querySelector('[data-barcode-scanner-target="toggleButton"]'));
    await p.frames(14);

    const facts = {
        reads,
        input: inputElement.value,
        changes,
        scanned: p.facts.scanned,
        after: wrapperFacts(p, wrapper),
        trackEnded: p.facts.streams[0].track.readyState,
        errors: [...p.facts.errors, ...p.facts.consoleErrors],
    };
    p.done();

    return facts;
});

// ---- The page's own scanners: their own markup, nothing copied ------------------------------------------------------

for (const [name, selector] of [['add form', '#barcodeScannerModal'], ['multiscan', '[data-controller~="barcode-scanner"][data-barcode-scanner-continuous-value="true"]']]) {
    await run(`page scanner ${name}`, async () => {
        const html = name === 'add form' ? input.pages.add : input.pages.multiscan;
        const p = await page(html, { url: `https://msp.test/en/${name === 'add form' ? 'puzzle-add' : 'multiscan'}` });
        const root = p.document.querySelector(selector);
        const wrapper = root.querySelector('[data-barcode-scanner-target="wrapper"]');
        const before = { children: wrapper.childElementCount, html: wrapper.innerHTML };
        const video = wrapper.querySelector('video');
        const template = p.document.getElementById('search-camera');

        // multiscan starts by itself (autostart, on the next frame); the add form's modal through its toggle button
        if (name === 'multiscan') {
            await p.frames(1);
        } else {
            await p.click(root.querySelector('[data-barcode-scanner-target="toggleButton"]'));
        }
        const started = wrapperFacts(p, wrapper);

        const facts = {
            ownCamera: root.getAttribute('data-barcode-scanner-camera-value'),
            started,
            sameVideo: wrapper.querySelector('video') === video && Boolean(video.srcObject),
            nothingCopied: wrapper.childElementCount === before.children,
            searchWrappersEmpty: ['search-scan-topbar', 'search-scan-menu'].map((id) => (p.document.getElementById(id) ? p.document.getElementById(id).childElementCount : null)),
            template: template !== null,
            errors: [...p.facts.errors, ...p.facts.consoleErrors],
        };
        p.done();

        return facts;
    });
}

process.stdout.write(JSON.stringify(output));
