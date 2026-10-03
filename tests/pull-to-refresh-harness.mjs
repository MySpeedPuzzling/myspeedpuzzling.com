// Runs assets/pull_to_refresh.js (the PWA's pull-to-refresh gesture) for the cases tests/PullToRefreshGestureTest.php
// hands over on stdin, and prints the results as JSON. Elements are plain objects with just what the module reads.

import { readFileSync } from 'node:fs';
import { canStartPull, PullGesture } from '../assets/pull_to_refresh.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

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
    const allowed = canStartPull(elementChain(testCase.path ?? []), {
        scrollY: testCase.scrollY ?? 0,
        overlayOpen: testCase.overlayOpen ?? false,
        styleOf: (element) => ({ overflowX: element.overflowX }),
    });

    const gesture = new PullGesture();
    gesture.start(0, 0, allowed, testCase.touches ?? 1);

    const prevented = testCase.moves.map(([x, y, page = {}]) => gesture.move(x, y, {
        scrollY: page.scrollY ?? 0,
        cancelable: page.cancelable ?? true,
    }));

    return { allowed, prevented, distance: gesture.end() };
})));
