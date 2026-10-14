// Runs assets/pull_to_refresh.js (the PWA's pull-to-refresh gesture) for the cases tests/PullToRefreshGestureTest.php
// hands over on stdin, and prints the results as JSON. Elements are plain objects with just what the module reads.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import {
    canStartPull, DRAG_RATE, overlayOpen, pastThreshold, PULL_TARGET, pullEnabled, pullEnabledFor, pullOffset, pullProgress, PullGesture,
} from '../assets/pull_to_refresh.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

// An object asks one of these:
// - {overlay: [{body: 'class names', htmlAttributes: 'attributes of <html>', html, target: 'selector', layer}]}:
//   overlayOpen() on a real DOM (jsdom, real selectors), `layer` = the id holding a layer_history entry (or null)
// - {maths: [finger travel px, …]}: pullOffset() / pastThreshold() / pullProgress() for each, plus the constants
// - {enabled: [{standalone, nativeApp}, …]}: pullEnabled()
// - {roots: [{classes: [...], attributes: {name: value}}, …]}: pullEnabledFor() on a fake <html>
if (!Array.isArray(cases)) {
    if (cases.maths) {
        process.stdout.write(JSON.stringify({
            dragRate: DRAG_RATE,
            pullTarget: PULL_TARGET,
            pulls: cases.maths.map((distance) => ({
                distance,
                offset: pullOffset(distance),
                past: pastThreshold(distance),
                progress: pullProgress(distance),
            })),
        }));
        process.exit(0);
    }

    if (cases.enabled) {
        process.stdout.write(JSON.stringify(cases.enabled.map((flags) => pullEnabled(flags))));
        process.exit(0);
    }

    if (cases.roots) {
        process.stdout.write(JSON.stringify(cases.roots.map((root) => pullEnabledFor({
            getAttribute: (name) => (root.attributes && name in root.attributes ? root.attributes[name] : null),
            classList: { contains: (name) => (root.classes || []).includes(name) },
        }))));
        process.exit(0);
    }

    const require = createRequire(import.meta.url);
    const { JSDOM } = require('jsdom');

    process.stdout.write(JSON.stringify((cases.overlay || []).map((testCase) => {
        const { document } = new JSDOM(`<!doctype html><html ${testCase.htmlAttributes || ''}><body class="${testCase.body || ''}">${testCase.html}</body></html>`).window;

        return overlayOpen(document.querySelector(testCase.target), {
            hasBodyClass: (name) => document.body.classList.contains(name),
            matches: (selector) => document.querySelector(selector) !== null,
            layer: testCase.layer ?? null,
        });
    })));
    process.exit(0);
}

/** The touched element first, its ancestors after it, <body> and <html> appended */
function elementChain(path) {
    const nodes = [...path, { tag: 'BODY' }, { tag: 'HTML' }].map((node) => ({
        tagName: node.tag ?? 'DIV',
        scrollTop: node.scrollTop ?? 0,
        scrollWidth: node.scrollWidth ?? 300,
        clientWidth: node.clientWidth ?? 300,
        overflowX: node.overflowX ?? 'visible',
        attributes: node.attributes ?? [],
        parentElement: null,
        hasAttribute(name) {
            return this.attributes.includes(name);
        },
    }));

    nodes.forEach((node, index) => {
        node.parentElement = nodes[index + 1] ?? null;
    });

    return nodes[0];
}

process.stdout.write(JSON.stringify(cases.map((testCase) => {
    let formInputAsked = 0;
    const page = {
        scrollY: testCase.scrollY ?? 0,
        overlayOpen: testCase.overlayOpen ?? false,
        styleOf: (element) => ({ overflowX: element.overflowX }),
    };

    // `formInput`: the page holds typed form input (the controller's _hasActiveFormInput()); absent = not handed in
    if ('formInput' in testCase) {
        page.hasFormInput = () => {
            formInputAsked += 1;

            return testCase.formInput;
        };
    }

    const allowed = canStartPull(elementChain(testCase.path ?? []), page);

    const gesture = new PullGesture();
    gesture.start(0, 0, allowed, testCase.touches ?? 1);

    const prevented = testCase.moves.map(([x, y, page = {}]) => gesture.move(x, y, {
        scrollY: page.scrollY ?? 0,
        cancelable: page.cancelable ?? true,
    }));

    return { allowed, prevented, distance: gesture.end(), formInputAsked };
})));
