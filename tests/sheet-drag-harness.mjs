// Runs assets/sheet_drag.js (swipe down closes a bottom sheet, GEST-02) for the cases tests/SheetDragGestureTest.php
// hands over on stdin and prints the results as JSON. Elements are plain objects with just what the module reads.
//
// A case: {path?, zone?, moves: [[x, y, t], …], release?: t, height?: px, cancel?: bool}
// - path: the touched element first, its ancestors after it; the last one is the zone's root (the handle or the body)
// - moves: finger positions after the finger went down at (0, 0) at t = 0

import { readFileSync } from 'node:fs';
import { canStart, SheetDrag } from '../assets/sheet_drag.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

/** The touched element first, its ancestors after it; returns [touched, root] */
function elementChain(path) {
    const nodes = (path.length > 0 ? path : [{ tag: 'DIV' }]).map((node) => ({
        tagName: node.tag ?? 'DIV',
        scrollTop: node.scrollTop ?? 0,
        scrollHeight: node.scrollHeight ?? 100,
        clientHeight: node.clientHeight ?? 100,
        selectionStart: node.selection ? node.selection[0] : (node.tag === 'TEXTAREA' ? 0 : undefined),
        selectionEnd: node.selection ? node.selection[1] : (node.tag === 'TEXTAREA' ? 0 : undefined),
        isContentEditable: node.editable === true,
        attributes: node.attributes ?? {},
        parentElement: null,
        getAttribute(name) {
            return name in this.attributes ? this.attributes[name] : null;
        },
    }));

    nodes.forEach((node, index) => {
        node.parentElement = nodes[index + 1] ?? null;
    });

    // An element above the zone's root (the modal): the walk must stop at the root, never reach it
    nodes[nodes.length - 1].parentElement = { tagName: 'DIV', scrollTop: 500, parentElement: null, getAttribute: () => null };

    return [nodes[0], nodes[nodes.length - 1]];
}

process.stdout.write(JSON.stringify(cases.map((testCase) => {
    const [touched, root] = elementChain(testCase.path ?? []);
    const allowed = testCase.outside === true
        ? canStart(touched, { zone: testCase.zone ?? 'body', root: { tagName: 'DIV' } })
        : canStart(touched, { zone: testCase.zone ?? 'body', root });

    const drag = new SheetDrag();
    drag.start(0, 0, 0, allowed);

    const steps = (testCase.moves ?? []).map(([x, y, t]) => drag.move(x, y, t));
    const lastT = steps.length > 0 ? testCase.moves[testCase.moves.length - 1][2] : 0;
    const offset = drag.offset;
    const velocity = drag.dragging ? drag.velocity(testCase.release ?? lastT) : 0;
    const decision = testCase.cancel === true ? drag.cancel() : drag.release(testCase.height ?? 600, testCase.release ?? lastT);

    return {
        allowed,
        prevented: steps.map((step) => step.prevent),
        offsets: steps.map((step) => step.offset),
        offset,
        velocity: Math.round(velocity * 1000) / 1000,
        decision,
        state: drag.state,
    };
})));
