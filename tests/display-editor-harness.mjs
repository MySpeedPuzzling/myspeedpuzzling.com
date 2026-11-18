// The display editor's pure modules (competition toolkit D6, D-displays-overlays.md §8.2, §11.2):
// - assets/displays/geometry.js: move, resize from each of the 8 handles, grid + canvas centre/edge + sibling snapping, clamping,
//   keyboard nudges, the kept ratio of pictures and QR codes, from-centre resizing, the hit test;
// - assets/displays/editor_state.js: add / duplicate / remove / reorder / hide / lock, undo and redo (coalesced steps,
//   the 100-step bound), the unsaved flag, the save payload = normaliseDesign()'s output, adopting the server's design;
// - assets/displays/arrange.js (v2 E1): group bounds, move / resize / nudge as a group, align, distribute, marquee;
//   EditorState's multi-selection and its one-undo-step group writes;
// - assets/displays/sample_data.js: the preview phases, sample results, QR placeholders, never real puzzles.
// Prints {"<scenario>": "ok" | "<what failed>"} as JSON (DisplayEditorScriptTest).

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import {
    HANDLES, clampFrame, frameLines, hitTest, keepsRatio, moveFrame, nudgeFrame, placeFrame, resizeFrame, snapLines,
    stackOrder, toPercent,
} from '../assets/displays/geometry.js';
import { CATALOGUE, EditorState, UNDO_LIMIT } from '../assets/displays/editor_state.js';
import {
    alignFrames, boundsOf, distributeFrames, groupKeepsRatio, marqueeHits, marqueeRect, moveGroup, nudgeGroup, resizeGroup,
} from '../assets/displays/arrange.js';
import { ELEMENT_TYPES, PROP_SPECS, STYLE_SPEC, normaliseDesign } from '../assets/displays/schema.js';
import { PREVIEW_PHASES, previewState } from '../assets/displays/sample_data.js';
import { EditorPanel, layerLabel, translator } from '../assets/displays/editor_panel.js';
import { stopwatchView } from '../assets/round_stopwatch_clock.js';

const COMPETITION = '018d0004-0000-0000-0000-00000000c0de';
const IMAGE = `competition-displays/${COMPETITION}/018d0004-0000-0000-0000-000000000001.png`;
const results = {};

function check(name, run) {
    try {
        const outcome = run();
        results[name] = outcome === undefined || outcome === true ? 'ok' : String(outcome);
    } catch (error) {
        results[name] = `threw ${error?.stack ?? error}`;
    }
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function equal(actual, expected, message) {
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        throw new Error(`${message}: ${JSON.stringify(actual)} !== ${JSON.stringify(expected)}`);
    }
}

function seeded(seed = 1) {
    let value = seed;

    return () => {
        value = (value * 16807) % 2147483647;

        return (value - 1) / 2147483646;
    };
}

function editor(design = { schema: 1 }) {
    return new EditorState({ design, designVersion: 3, competitionId: COMPETITION, random: seeded(7), texts: { text: 'Hello' } });
}

const frame = (x, y, w, h) => ({ x, y, w, h, rotate: 0 });

// ---- geometry

check('move: plain delta, 2 decimals, no snapping', () => {
    equal(moveFrame(frame(10, 10, 20, 20), 3.333, -1.111).frame, frame(13.33, 8.89, 20, 20), 'moved');
});

check('move: clamped inside the canvas on every side', () => {
    equal(moveFrame(frame(10, 10, 20, 20), -50, -50).frame, frame(0, 0, 20, 20), 'top left');
    equal(moveFrame(frame(10, 10, 20, 20), 500, 500).frame, frame(80, 80, 20, 20), 'bottom right');
});

check('move: snaps to the grid', () => {
    equal(moveFrame(frame(10, 10, 20, 20), 3.3, 4.6, { snap: true, threshold: 0.1 }).frame, frame(13, 15, 20, 20), 'grid');
});

check('move: the centre snaps to the canvas centre with a guide', () => {
    // centre x at 50.3 → 50, centre y at 49.8 → 50
    const { frame: moved, guides } = moveFrame(frame(10, 10, 20, 20), 30.3, 29.8, { snap: true, threshold: 0.5 });
    equal(moved, frame(40, 40, 20, 20), 'centred');
    equal(guides, [{ axis: 'x', at: 50 }, { axis: 'y', at: 50 }], 'guides');
});

check('move: edges snap to the canvas edges', () => {
    const { frame: moved, guides } = moveFrame(frame(10, 10, 20, 20), 69.7, -9.6, { snap: true, threshold: 0.5 });
    equal(moved, frame(80, 0, 20, 20), 'right + top edge');
    equal(guides, [{ axis: 'x', at: 100 }, { axis: 'y', at: 0 }], 'guides');
});

check('move: no snapping when off', () => {
    equal(moveFrame(frame(10, 10, 20, 20), 30.3, 0.37).frame, frame(40.3, 10.37, 20, 20), 'free');
});

check('resize from each of the 8 handles', () => {
    const start = frame(20, 20, 40, 40);
    const expected = {
        nw: frame(25, 25, 35, 35),
        n: frame(20, 25, 40, 35),
        ne: frame(20, 25, 45, 35),
        e: frame(20, 20, 45, 40),
        se: frame(20, 20, 45, 45),
        s: frame(20, 20, 40, 45),
        sw: frame(25, 20, 35, 45),
        w: frame(25, 20, 35, 40),
    };

    for (const handle of HANDLES) {
        equal(resizeFrame(start, handle, 5, 5).frame, expected[handle], handle);
    }
});

check('resize: never past the canvas, never below the minimum size', () => {
    equal(resizeFrame(frame(20, 20, 40, 40), 'se', 100, 100).frame, frame(20, 20, 80, 80), 'clamped to canvas');
    equal(resizeFrame(frame(20, 20, 40, 40), 'nw', -100, -100).frame, frame(0, 0, 60, 60), 'clamped to origin');
    equal(resizeFrame(frame(20, 20, 40, 40), 'e', -60, 0).frame, frame(20, 20, 0.5, 40), 'minimum width from east');
    equal(resizeFrame(frame(20, 20, 40, 40), 'w', 60, 0).frame, frame(59.5, 20, 0.5, 40), 'minimum width from west');
    equal(resizeFrame(frame(20, 20, 40, 40), 'n', 0, 80).frame, frame(20, 59.5, 40, 0.5), 'minimum height from north');
});

check('resize: moving edges snap to the grid and the canvas centre line', () => {
    equal(resizeFrame(frame(20, 20, 40, 40), 'e', 2.4, 0, { snap: true, threshold: 0.1 }).frame, frame(20, 20, 42, 40), 'grid');
    const { frame: resized, guides } = resizeFrame(frame(20, 20, 20, 20), 'e', 9.8, 0, { snap: true, threshold: 0.5 });
    equal(resized, frame(20, 20, 30, 20), 'to the centre line');
    equal(guides, [{ axis: 'x', at: 50 }], 'guide');
});

check('resize: Alt grows from the centre', () => {
    equal(resizeFrame(frame(20, 20, 40, 40), 'e', 5, 0, { fromCentre: true }).frame, frame(15, 20, 50, 40), 'both sides');
    equal(resizeFrame(frame(20, 20, 40, 40), 'se', 5, 5, { fromCentre: true }).frame, frame(15, 15, 50, 50), 'corner');
});

check('resize: the ratio is kept from a corner (anchored at the opposite corner)', () => {
    // A square QR code on the 16:9 canvas: w 10 %, h 17.78 %
    const start = frame(40, 40, 10, 20);
    const { frame: resized } = resizeFrame(start, 'se', 10, 2, { keepRatio: true });
    equal(resized, frame(40, 40, 20, 40), 'se: width leads');
    const { frame: fromNw } = resizeFrame(start, 'nw', -2, -20, { keepRatio: true });
    equal(fromNw, frame(30, 20, 20, 40), 'nw: height leads, anchored bottom right');
});

check('resize: the ratio is kept from a side (centred on the other axis)', () => {
    equal(resizeFrame(frame(40, 40, 10, 20), 'e', 10, 0, { keepRatio: true }).frame, frame(40, 30, 20, 40), 'e');
    equal(resizeFrame(frame(40, 40, 10, 20), 's', 0, 20, { keepRatio: true }).frame, frame(35, 40, 20, 40), 's');
});

check('resize: a kept ratio shrinks to fit the canvas instead of leaving it', () => {
    const { frame: resized } = resizeFrame(frame(60, 40, 20, 20), 'se', 80, 0, { keepRatio: true });
    equal(resized, frame(60, 40, 40, 40), 'limited by the right edge');
    assert(Math.abs(resized.w / resized.h - 1) < 0.001, 'ratio kept');
});

check('ratio lock: QR always, pictures unless Shift, others only with Shift', () => {
    equal(keepsRatio('qr', false), true, 'qr');
    equal(keepsRatio('qr', true), true, 'qr shift');
    equal(keepsRatio('image', false), true, 'image');
    equal(keepsRatio('image', true), false, 'image shift');
    equal(keepsRatio('timer', false), false, 'timer');
    equal(keepsRatio('timer', true), true, 'timer shift');
});

check('nudge: 0.1 %, Shift 1 %, clamped, other keys ignored', () => {
    equal(nudgeFrame(frame(10, 10, 20, 20), 'ArrowRight'), frame(10.1, 10, 20, 20), 'right');
    equal(nudgeFrame(frame(10, 10, 20, 20), 'ArrowUp', true), frame(10, 9, 20, 20), 'up big');
    equal(nudgeFrame(frame(0, 0, 20, 20), 'ArrowLeft', true), frame(0, 0, 20, 20), 'clamped');
    equal(nudgeFrame(frame(80, 80, 20, 20), 'ArrowDown'), frame(80, 80, 20, 20), 'clamped bottom');
    equal(nudgeFrame(frame(10, 10, 20, 20), 'a'), null, 'other key');
});

check('clampFrame: oversize and out of range values', () => {
    equal(clampFrame(frame(-5, 120, 150, 0.1)), frame(0, 99.5, 100, 0.5), 'clamped');
});

check('hit test: topmost by z then array order, hidden ones skipped', () => {
    const elements = [
        { id: 'a', z: 1, hidden: false, frame: frame(0, 0, 50, 50) },
        { id: 'b', z: 1, hidden: false, frame: frame(10, 10, 50, 50) },
        { id: 'c', z: 0, hidden: false, frame: frame(0, 0, 100, 100) },
        { id: 'd', z: 9, hidden: true, frame: frame(0, 0, 100, 100) },
    ];
    equal(hitTest(elements, { x: 20, y: 20 }), 'b', 'later of equal z');
    equal(hitTest(elements, { x: 5, y: 5 }), 'a', 'only a');
    equal(hitTest(elements, { x: 90, y: 90 }), 'c', 'background');
    equal(stackOrder(elements).map((element) => element.id), ['d', 'b', 'a', 'c'], 'stack order');
    equal(hitTest([], { x: 1, y: 1 }), null, 'empty');
});

check('pointer pixels to percent of the stage', () => {
    equal(toPercent(96, 54, { width: 960, height: 540 }), { x: 10, y: 10 }, 'percent');
    equal(toPercent(5, 5, { width: 0, height: 0 }), { x: 0, y: 0 }, 'no size');
});

// ---- the document

check('add every catalogue type: normalised, selected, on top, unique ids', () => {
    const state = editor();
    const types = CATALOGUE.flatMap((group) => group.types);
    equal([...types].sort(), [...ELEMENT_TYPES].sort(), 'the catalogue offers every v1 type');

    for (const type of types) {
        const id = state.addElement(type);
        assert(id !== null && /^[a-z0-9]{8}$/.test(id), `id of ${type}`);
        equal(state.selectedId, id, `selected ${type}`);
        equal(state.selected.type, type, `type ${type}`);
    }

    equal(new Set(state.design.elements.map((element) => element.id)).size, types.length, 'unique ids');
    equal(state.design.elements.map((element) => element.z), types.map((_, index) => index), 'each new one on top');
    equal(state.element(state.design.elements[3].id).props.source, undefined, 'clock has no source');
    const board = state.design.elements.find((element) => element.type === 'leaderboard');
    equal(board.props.source, 'published', 'results start on published results');
    const text = state.design.elements.find((element) => element.type === 'text');
    equal(text.props.text, 'Hello', 'text placeholder');
    const qr = state.design.elements.find((element) => element.type === 'qr');
    assert(Math.abs((qr.frame.w * 1920) / (qr.frame.h * 1080) - 1) < 0.01, 'a QR code starts square');
    assert(state.dirty, 'dirty');
});

check('remove, duplicate, hide, lock', () => {
    const state = editor();
    const a = state.addElement('timer');
    const copy = state.duplicateElement(a);
    assert(copy !== a, 'new id');
    equal(state.selectedId, copy, 'copy selected');
    equal(state.element(copy).frame.x, state.element(a).frame.x + 2, 'offset');
    assert(state.element(copy).z > state.element(a).z, 'copy on top');
    state.toggleHidden(a);
    equal(state.element(a).hidden, true, 'hidden');
    state.toggleLocked(a);
    equal(state.element(a).locked, true, 'locked');
    state.removeElement(copy);
    equal(state.element(copy), null, 'removed');
    equal(state.selectedId, null, 'selection dropped with the element');
});

check('reorder layers: one step up / down, z rewritten 0..n-1, ends refused', () => {
    const state = editor();
    const a = state.addElement('box');
    const b = state.addElement('timer');
    const c = state.addElement('text');
    equal(state.layers().map((element) => element.id), [c, b, a], 'top first');
    assert(state.moveLayer(a, 'up'), 'a up');
    equal(state.layers().map((element) => element.id), [c, a, b], 'a above b');
    equal(state.design.elements.map((element) => [element.id, element.z]), [[a, 1], [b, 0], [c, 2]], 'z rewritten');
    assert(!state.moveLayer(c, 'up'), 'top cannot go up');
    assert(!state.moveLayer(b, 'down'), 'bottom cannot go down');
});

check('undo / redo walk the steps; a new change drops redo', () => {
    const state = editor();
    const a = state.addElement('timer');
    state.setFrame(a, { x: 5 });
    state.setFrame(a, { x: 6 });
    state.undo();
    equal(state.element(a).frame.x, 5, 'undo once');
    state.undo();
    equal(state.element(a).frame.x, 20, 'undo twice');
    state.redo();
    equal(state.element(a).frame.x, 5, 'redo');
    state.setFrame(a, { x: 9 });
    assert(!state.canRedo, 'redo dropped');
    state.undo();
    state.undo();
    state.undo();
    equal(state.design.elements.length, 0, 'back to empty');
    assert(!state.canUndo, 'nothing left');
    assert(!state.dirty, 'back to the saved design');
});

check('undo: one drag (one key) is one step, a sealed key starts a new one', () => {
    const state = editor();
    const a = state.addElement('timer');

    for (let x = 1; x <= 10; x += 1) {
        state.setFrame(a, { x }, 'drag:1');
    }

    state.seal();
    state.setFrame(a, { x: 30 }, 'drag:1');
    state.undo();
    equal(state.element(a).frame.x, 10, 'second drag undone');
    state.undo();
    equal(state.element(a).frame.x, 20, 'first drag undone in one step');
});

check(`undo history is bounded to ${UNDO_LIMIT} steps`, () => {
    const state = editor();
    const a = state.addElement('timer');

    for (let i = 0; i < UNDO_LIMIT + 50; i += 1) {
        state.setFrame(a, { x: i % 2 === 0 ? 1 : 2 });
    }

    equal(state.undoStack.length, UNDO_LIMIT, 'bounded');
});

check('an unchanged commit is no step; selection survives undo only while the element exists', () => {
    const state = editor();
    const a = state.addElement('timer');
    const steps = state.undoStack.length;
    state.setFrame(a, { x: state.element(a).frame.x });
    equal(state.undoStack.length, steps, 'no step');
    state.undo();
    equal(state.selectedId, null, 'element gone with undo');
});

check('values out of range are normalised on commit (the schema decides)', () => {
    const state = editor();
    const a = state.addElement('timer');
    state.withElement(a, (element) => {
        element.style.fontSize = 5000;
        element.style.color = 'red;}';
        element.props.mode = 'nonsense';
        element.frame.x = -40;
    });
    equal(state.element(a).style, { fontSize: 900 }, 'style');
    equal(state.element(a).props.mode, 'auto', 'prop default');
    equal(state.element(a).frame.x, 0, 'frame');
});

check('the save payload is exactly normaliseDesign() of the document', () => {
    const state = editor({
        schema: 1,
        canvas: { background: { type: 'image', image: { path: IMAGE, fit: 'contain' } } },
        elements: [{ id: 'board001', type: 'leaderboard', frame: frame(1, 2, 30, 50), props: { source: 'live' } }],
    });
    state.addElement('qr');
    state.addElement('text');
    const payload = state.savePayload(true);
    equal(payload.baseVersion, 3, 'base version');
    equal(payload.liveConfirmed, true, 'live confirmed');
    const again = normaliseDesign(JSON.parse(JSON.stringify(payload.design)), { competitionId: COMPETITION });
    equal(again.errors, [], 'valid');
    equal(again.design, payload.design, 'equal to normaliseDesign output');
    equal(payload.design, state.design, 'equal to the document');
    equal(state.liveElementIds(), ['board001'], 'live elements');
});

check('adopting the server design: saved, not dirty, version taken; [] style/props read as objects', () => {
    const state = editor();
    const a = state.addElement('box');
    const server = JSON.parse(JSON.stringify(state.design));
    server.elements[0].props = [];
    state.adoptSaved(server, 4);
    assert(!state.dirty, 'not dirty');
    equal(state.designVersion, 4, 'version');
    equal(state.element(a).props, {}, 'props object');
    state.setFrame(a, { x: 1 });
    assert(state.dirty, 'dirty again');
    state.replaceWithSaved({ schema: 1 }, 7);
    assert(!state.dirty && !state.canUndo && state.design.elements.length === 0, 'replaced with theirs');
    equal(state.designVersion, 7, 'their version');
});

check('a refused design is never committed', () => {
    const state = editor();
    state.addElement('timer');
    const before = JSON.stringify(state.design);
    const accepted = state.changeDesign((next) => {
        next.elements[0].type = 'script';
    });
    equal(accepted, false, 'refused');
    equal(JSON.stringify(state.design), before, 'unchanged');
});

check('the panel specs cover every element type and every style key the normaliser keeps', () => {
    equal(Object.keys(PROP_SPECS).sort(), [...ELEMENT_TYPES].sort(), 'prop specs');
    const full = normaliseDesign({
        schema: 1,
        elements: [{
            id: 'aaaaaaaa', type: 'text', frame: frame(0, 0, 10, 10),
            style: {
                font: 'bebas', fontSize: 40, fontWeight: 700, color: '#ffffff', background: '#000000',
                gradient: { from: '#000000', to: '#ffffff', angle: 90 }, border: { width: 2, color: '#ff0000' }, radius: 4,
                padding: 4, opacity: 0.5, align: 'left', valign: 'top', shadow: 'soft', uppercase: true, letterSpacing: 1,
                lineHeight: 1.2, fit: 'shrink',
            },
        }],
    }, { competitionId: COMPETITION }).design;
    equal(Object.keys(full.elements[0].style), STYLE_SPEC.map((spec) => spec.key), 'style keys in spec order');
});

// ---- preview data

const BASE = {
    competition: { name: 'Results Cup' },
    round: null,
    stopwatch: null,
    puzzles: [{ name: 'REAL SECRET PUZZLE', brand: 'X', pieces: 1, imageUrl: null }],
    anyPuzzleRevealed: true,
    results: null,
    images: { [IMAGE]: 'https://img.example/a.png' },
    qr: {},
    texts: { category: null },
    serverNowMs: 1_763_380_000_000,
};

check('sample phases drive the clock as named', () => {
    const now = 1_763_380_000_000;
    const phases = {};

    for (const phase of PREVIEW_PHASES) {
        const { state, nowMs } = previewState(BASE, { mode: 'sample', phase, published: true, revealed: true, nowMs: now, design: { elements: [] } });
        phases[phase] = stopwatchView(state.stopwatch, nowMs).phase;
    }

    equal(phases, {
        idle: 'idle', scheduled: 'scheduled', last_seconds: 'scheduled', running: 'running', warning: 'running', times_up: 'times_up', stopped: 'stopped',
    }, 'phases');
    const warning = previewState(BASE, { mode: 'sample', phase: 'warning', published: true, revealed: true, nowMs: now, design: { elements: [] } });
    const view = stopwatchView(warning.state.stopwatch, now);
    equal(view.limitMs - view.elapsedMs, 270000, '4:30 left');
});

check('sample data: results only for designs with results elements, published flag, sample puzzles never real ones', () => {
    const now = 1_763_380_000_000;
    const withBoard = { elements: [{ id: 'board001', type: 'leaderboard' }] };
    const sample = previewState(BASE, { mode: 'sample', phase: 'running', published: false, revealed: true, nowMs: now, design: withBoard }).state;
    equal(sample.results.top.length, 20, '20 rows');
    equal(sample.results.published, false, 'unpublished');
    equal(sample.round.resultsPublished, false, 'round flag');
    assert(!JSON.stringify(sample.puzzles).includes('REAL SECRET'), 'no real puzzle in the sample');
    equal(sample.puzzles.length, 2, 'two sample puzzles');
    const hidden = previewState(BASE, { mode: 'sample', phase: 'running', published: true, revealed: false, nowMs: now, design: { elements: [] } }).state;
    equal(hidden.puzzles, [], 'not revealed');
    equal(hidden.anyPuzzleRevealed, false, 'flag');
    equal(hidden.results, null, 'no results elements = no results');
    equal(hidden.round.name, 'Round 1', 'sample round');
});

check('sample data: every standings element gets the 12-row sample standing with the texts for its numbers', () => {
    const now = 1_763_380_000_000;
    const design = { elements: [{ id: 'stand001', type: 'standings' }, { id: 'stand002', type: 'standings' }] };
    const texts = { standingProvisional: 'Průběžné', standingMissing: { 12: 'chybí 12 dílků' }, standingFinal: { 6: 'Konečné' } };
    const { state } = previewState(BASE, { mode: 'sample', phase: 'running', published: true, revealed: false, nowMs: now, design, labels: { standing: 'Mistrovství' }, standingTexts: texts });
    equal(Object.keys(state.standings), ['stand001', 'stand002'], 'one per element');
    const standing = state.standings.stand001;
    equal(standing.name, 'Mistrovství', 'label');
    equal(standing.rows.length, 12, '12 rows');
    equal([standing.roundsCounted, standing.roundsTotal, standing.rounds.length], [4, 6, 6], 'after 4 of 6');
    equal(standing.rows.filter((row) => row.totalMissingPieces > 0).map((row) => row.totalMissingPieces), [12], 'one row with missing pieces');
    equal(standing.rows.filter((row) => !row.complete).map((row) => row.rank), [null], 'one incomplete row without a rank');
    assert(standing.rows.every((row) => row.perRound.length === 6), 'a score per round');
    equal(state.texts.standingMissing, { 12: 'chybí 12 dílků' }, 'texts of the display language');
    equal(state.results, null, 'a standing needs no round results');
    equal(previewState(BASE, { mode: 'sample', phase: 'running', published: true, revealed: false, nowMs: now, design: { elements: [] } }).state.standings, undefined, 'none without an element');
    assert(CATALOGUE.find((group) => group.group === 'results').types.includes('standings'), 'in the results group');
});

check('preview: a sample live message for message elements only (v2 L1)', () => {
    const now = 1_763_380_000_000;
    const design = { elements: [{ id: 'mesg0001', type: 'message' }] };
    const { state } = previewState(BASE, { mode: 'sample', phase: 'running', published: false, revealed: false, nowMs: now, design, labels: { message: 'Pauza' } });
    equal(state.control, { message: 'Pauza', spotlight: null }, 'sample message in the display language');
    equal(previewState(BASE, { mode: 'sample', phase: 'running', published: false, revealed: false, nowMs: now, design: { elements: [] } }).state.control, undefined, 'none without an element');
    assert(CATALOGUE.find((group) => group.group === 'text').types.includes('message'), 'in the text group');

    // A spotlight (L2): a results element - sample results, and a sample entry "chosen"
    const spotlight = previewState(BASE, { mode: 'sample', phase: 'running', published: false, revealed: false, nowMs: now, design: { elements: [{ id: 'spot0001', type: 'spotlight' }] } }).state;
    equal(spotlight.control.spotlight.key, spotlight.results.top[1].key, 'the sample spotlight');
    assert(CATALOGUE.find((group) => group.group === 'results').types.includes('spotlight'), 'in the results group');
});

check('preview: uploaded pictures merged, unsaved QR codes get a placeholder, display mode keeps the real state', () => {
    const design = { elements: [{ id: 'qr000001', type: 'qr' }] };
    const extra = { 'competition-displays/x.png': 'https://img.example/x.png' };
    const { state } = previewState(BASE, { mode: 'display', phase: 'running', published: true, revealed: true, nowMs: 5, design, images: extra });
    equal(Object.keys(state.images).length, 2, 'images merged');
    equal(state.qr.qr000001.rows.length, 25, 'placeholder qr');
    assert(state.qr.qr000001.rows.every((row) => /^[01]{25}$/.test(row)), 'qr rows valid');
    equal(state.puzzles, BASE.puzzles, 'display mode = what the display shows');
    equal(state.round, null, 'no sample round in display mode');
});

check('a new element lands where nothing is', () => {
    const text = { x: 30, y: 10, w: 40, h: 10, rotate: 0 };
    equal(placeFrame(text, []), text, 'free default kept');
    equal(placeFrame(text, [{ x: 0, y: 0, w: 100, h: 100 }]), text, 'a full-canvas backdrop does not count');

    const badge = { x: 40, y: 8, w: 20, h: 7 };
    const placed = placeFrame(text, [badge]);
    const overlaps = (a, b) => Math.min(a.x + a.w, b.x + b.w) > Math.max(a.x, b.x) && Math.min(a.y + a.h, b.y + b.h) > Math.max(a.y, b.y);
    equal(overlaps(placed, badge), false, `moved off the badge: ${JSON.stringify(placed)}`);
    equal([placed.w, placed.h, placed.rotate], [40, 10, 0], 'size kept');
    equal(placed.y + placed.h <= 100 && placed.x + placed.w <= 100, true, 'inside the canvas');
    equal(Math.abs(placed.y - text.y) + Math.abs(placed.x - text.x) <= 10, true, `near the default: ${JSON.stringify(placed)}`);

    const state = new EditorState({ design: normaliseDesign({ elements: [] }, { competitionId: COMPETITION }), designVersion: 1, competitionId: COMPETITION });
    const first = state.element(state.addElement('text')).frame;
    const second = state.element(state.addElement('text')).frame;
    equal(overlaps(first, second), false, `two texts side by side: ${JSON.stringify([first, second])}`);
});

check('a layer is named by its own name or type, with what it shows', () => {
    const t = translator({ 'type.text': 'Text', 'type.field': 'Event detail', 'type.image': 'Picture', 'type.timer': 'Timer', 'type.qr': 'QR code', 'opt.round_name': 'Round name', 'opt.competition_logo': 'Event logo', 'opt.event_page': 'Event page' });
    const label = (type, name, props) => layerLabel({ type, name, props }, t);

    equal(label('timer', '', {}), { title: 'Timer', detail: '' }, 'type only');
    equal(label('text', '', { text: '  Welcome to\nthe   Results Cup!  ' }), { title: 'Text', detail: 'Welcome to the Results Cup!' }, 'text words, whitespace folded');
    equal(label('text', '', { text: 'x'.repeat(80) }).detail, `${'x'.repeat(39)}…`, 'long text cut to 40');
    equal(label('field', '', { field: 'round_name', prefix: 'Now:', suffix: '' }), { title: 'Event detail', detail: 'Now: Round name' }, 'field choice with prefix');
    equal(label('image', '', { source: 'competition_logo' }), { title: 'Picture', detail: 'Event logo' }, 'logo source');
    equal(label('image', '', { source: 'upload', path: 'x.png' }), { title: 'Picture', detail: '' }, 'an upload says nothing more');
    equal(label('image', 'Sponsor', { source: 'upload' }), { title: 'Sponsor', detail: 'Picture' }, 'own name first, type as the hint');
    equal(label('qr', 'Event page QR', { target: 'event_page' }), { title: 'Event page QR', detail: 'QR code · Event page' }, 'own name + type + target');
    equal(label('text', 'Welcome', { text: 'Welcome' }), { title: 'Welcome', detail: 'Text' }, 'no repeat of the name');
});

// ---- sibling snapping (v2 E1)

const el = (id, x, y, w, h, extra = {}) => ({ id, type: 'text', frame: frame(x, y, w, h), z: 0, hidden: false, locked: false, ...extra });

check('snap lines: 3 per axis of every visible element but the moving ones, locked counted, distinct and sorted', () => {
    equal(frameLines(frame(10, 20, 30, 40)), { x: [10, 25, 40], y: [20, 40, 60] }, 'frame lines');
    const elements = [
        el('a', 10, 10, 20, 20),
        el('b', 30, 10, 10, 20, { locked: true }),
        el('c', 60, 60, 10, 10, { hidden: true }),
        el('m', 70, 70, 5, 5),
    ];
    equal(snapLines(elements, ['m']), { x: [10, 20, 30, 35, 40], y: [10, 20, 30] }, 'lines');
    equal(snapLines([el('a', 10.004, 0, 33.333, 10)], []).x, [10, 26.67, 43.34], 'rounded to 2 decimals');
});

check('sibling snap: an edge meets a sibling edge with a sibling guide', () => {
    const lines = snapLines([el('a', 30, 70, 20, 10)]);
    // left edge 30.3 → 30 (the sibling's left edge)
    const { frame: moved, guides } = moveFrame(frame(10, 10, 15, 15), 20.3, 0.1, { snap: true, threshold: 0.5, lines });
    equal(moved, frame(30, 10, 15, 15), 'moved onto the line');
    equal(guides.filter((guide) => guide.axis === 'x'), [{ axis: 'x', at: 30, sibling: true }], 'x guide');
});

check('sibling snap: centre to a sibling centre and an edge to a sibling centre', () => {
    const lines = snapLines([el('a', 20, 70, 20, 10)]); // centre x 30
    const centred = moveFrame(frame(0, 10, 10, 10), 24.8, 0.1, { snap: true, threshold: 0.5, lines });
    equal(centred.frame.x, 25, 'centre 29.8 → 30');
    equal(centred.guides[0], { axis: 'x', at: 30, sibling: true }, 'centre guide');
    const edge = moveFrame(frame(0, 10, 10, 10), 30.2, 0.1, { snap: true, threshold: 0.5, lines });
    equal(edge.frame.x, 30, 'left edge 30.2 → the sibling centre 30');
});

check('sibling snap: the nearest line wins, the canvas wins a tie', () => {
    const lines = { x: [48.8, 51.1], y: [] };
    // right edge at 49 (0.2 from 48.8), left edge 39: nothing else → 48.8
    const near = moveFrame(frame(0, 10, 10, 10), 39, 0, { snap: true, threshold: 0.5, lines });
    equal(near.frame.x, 38.8, 'nearest sibling');
    // centre 50.3: canvas centre 0.3 away, sibling 51.1 0.8 away (out) → canvas
    const canvas = moveFrame(frame(0, 10, 10, 10), 45.3, 0, { snap: true, threshold: 1, lines });
    equal([canvas.frame.x, canvas.guides[0]], [45, { axis: 'x', at: 50 }], 'canvas nearer');
    // tie: left edge 50.2 is 0.2 from the canvas centre and 0.2 from a sibling at 50.4
    const tie = moveFrame(frame(0, 10, 10, 10), 50.2, 0, { snap: true, threshold: 0.5, lines: { x: [50.4], y: [] } });
    equal([tie.frame.x, tie.guides[0]], [50, { axis: 'x', at: 50 }], 'canvas on a tie');
});

check('sibling snap: hidden siblings ignored, the moving element itself excluded', () => {
    const elements = [el('self', 10, 10, 10, 10), el('hidden', 33, 50, 10, 10, { hidden: true })];
    const lines = snapLines(elements, ['self']);
    equal(lines, { x: [], y: [] }, 'no lines');
    const { frame: moved, guides } = moveFrame(elements[0].frame, 23.2, 0, { snap: true, threshold: 0.5, lines });
    equal([moved.x, guides], [33, []], 'grid only');
});

check('sibling snap: other sibling lines the frame now sits on are reported too', () => {
    // A: x 20..40, B: x 60..80 - a 40 wide frame whose left meets A's left has its right on B's left
    const lines = snapLines([el('a', 20, 70, 20, 10), el('b', 60, 70, 20, 10)]);
    const { frame: moved, guides } = moveFrame(frame(0, 10, 40, 10), 20.2, 0.3, { snap: true, threshold: 0.5, lines });
    equal(moved.x, 20, 'snapped');
    equal(guides.filter((guide) => guide.axis === 'x'), [
        { axis: 'x', at: 20, sibling: true },
        { axis: 'x', at: 40, sibling: true },
        { axis: 'x', at: 60, sibling: true },
    ], 'every line it sits on (its centre is on A right edge too)');
});

check('sibling snap: no lines = the old result exactly', () => {
    const cases = [
        [frame(10, 10, 20, 20), 30.3, 29.8],
        [frame(10, 10, 20, 20), 69.7, -9.6],
        [frame(10, 10, 20, 20), 3.3, 4.6],
    ];

    for (const [start, dx, dy] of cases) {
        equal(moveFrame(start, dx, dy, { snap: true, threshold: 0.5, lines: { x: [], y: [] } }), moveFrame(start, dx, dy, { snap: true, threshold: 0.5 }), `move ${dx}`);
    }

    equal(resizeFrame(frame(20, 20, 20, 20), 'e', 9.8, 0, { snap: true, threshold: 0.5, lines: { x: [], y: [] } }), resizeFrame(frame(20, 20, 20, 20), 'e', 9.8, 0, { snap: true, threshold: 0.5 }), 'resize');
    equal(moveFrame(frame(10, 10, 20, 20), 30.3, 0.37, { lines: { x: [40], y: [] } }).guides, [], 'snap off ignores lines');
});

check('sibling snap: a resized edge meets a sibling line; the canvas keeps a tie', () => {
    const lines = { x: [33], y: [71.5] };
    const { frame: resized, guides } = resizeFrame(frame(20, 20, 10, 10), 'se', 2.8, 41.3, { snap: true, threshold: 0.5, lines });
    equal(resized, frame(20, 20, 13, 51.5), 'right → 33, bottom → 71.5');
    equal(guides, [{ axis: 'x', at: 33, sibling: true }, { axis: 'y', at: 71.5, sibling: true }], 'guides');
    const tie = resizeFrame(frame(20, 20, 20, 20), 'e', 10.2, 0, { snap: true, threshold: 0.5, lines: { x: [50.4], y: [] } });
    equal([tie.frame.w, tie.guides[0]], [30, { axis: 'x', at: 50 }], 'canvas centre on a tie');
});

check('sibling snap: a ratio-kept resize keeps only the guides of edges that survived', () => {
    // A QR from se: the right edge snaps to 33 (width leads), the bottom edge does not survive the ratio
    const { frame: resized, guides } = resizeFrame(frame(20, 20, 10, 20), 'se', 2.9, 1.1, { snap: true, threshold: 0.5, keepRatio: true, lines: { x: [33], y: [41.3] } });
    equal(resized, frame(20, 20, 13, 26), 'ratio kept');
    equal(guides, [{ axis: 'x', at: 33, sibling: true }], 'only the surviving edge');
});

// ---- multi-select, align, distribute (v2 E1)

const item = (id, x, y, w, h, type = 'text') => ({ id, type, frame: frame(x, y, w, h) });

check('arrange: bounds of frames', () => {
    equal(boundsOf([]), null, 'none');
    equal(boundsOf([frame(10, 20, 10, 10), frame(40, 5, 20, 5)]), { x: 10, y: 5, w: 50, h: 25 }, 'box');
});

check('arrange: a group move keeps the spacing at the canvas edge', () => {
    const items = [item('a', 10, 10, 10, 10), item('b', 30, 40, 20, 10)];
    const { frames } = moveGroup(items, 60, 0);
    // bounds x 10..50 → may move 50 at most
    equal([frames.a.x, frames.b.x], [60, 80], 'clamped as a group');
    equal(frames.b.x - frames.a.x, 20, 'spacing kept');
    const up = moveGroup(items, 0, -30).frames;
    equal([up.a.y, up.b.y], [0, 30], 'top edge');
});

check('arrange: a group move snaps the group centre to the canvas centre and to sibling lines', () => {
    const items = [item('a', 10, 10, 10, 10), item('b', 30, 10, 10, 10)]; // bounds 10..40, centre 25
    const centred = moveGroup(items, 24.7, 0, { snap: true, threshold: 0.5 });
    equal([centred.frames.a.x, centred.frames.b.x], [35, 55], 'group centre on 50');
    equal(centred.guides[0], { axis: 'x', at: 50 }, 'canvas guide');
    const sibling = moveGroup(items, 54.8, 0, { snap: true, threshold: 0.5, lines: { x: [65], y: [] } });
    equal([sibling.frames.a.x, sibling.frames.b.x, sibling.guides[0]], [65, 85, { axis: 'x', at: 65, sibling: true }], 'group left edge on a sibling line');
});

check('arrange: a group resize maps members proportionally from se, nw and w', () => {
    const items = [item('a', 10, 10, 10, 10), item('b', 30, 30, 10, 10)]; // bounds 10..40
    const se = resizeGroup(items, 'se', 30, 30).frames;
    equal([se.a, se.b], [frame(10, 10, 20, 20), frame(50, 50, 20, 20)], 'se doubles');
    const nw = resizeGroup(items, 'nw', 15, 15).frames;
    equal([nw.a, nw.b], [frame(25, 25, 5, 5), frame(35, 35, 5, 5)], 'nw halves towards the far corner');
    const w = resizeGroup(items, 'w', -10, 0).frames;
    equal([w.a, w.b], [frame(0, 10, 13.33, 10), frame(26.67, 30, 13.33, 10)], 'w stretches x only');
});

check('arrange: a group resize keeps the minimum size and the ratio with a QR code inside', () => {
    const items = [item('a', 10, 10, 10, 10), item('b', 30, 30, 1, 1)];
    const small = resizeGroup(items, 'se', -29.8, -29.8).frames;
    assert(small.b.w >= 0.5 && small.b.h >= 0.5, `minimum kept: ${JSON.stringify(small.b)}`);
    const types = ['text', 'qr'];
    equal(groupKeepsRatio(types, false), true, 'qr: always');
    equal(groupKeepsRatio(types, true), true, 'qr: Shift too');
    equal(groupKeepsRatio(['text', 'image'], false), true, 'picture: by default');
    equal(groupKeepsRatio(['text', 'image'], true), false, 'picture: Shift frees');
    equal(groupKeepsRatio(['text', 'box'], false), false, 'text: free');
    equal(groupKeepsRatio(['text', 'box'], true), true, 'text: Shift keeps');
    const kept = resizeGroup([item('a', 10, 10, 10, 10), item('q', 30, 10, 10, 10, 'qr')], 'e', 30, 0, { keepRatio: true }).frames;
    equal([kept.a.w, kept.a.h], [20, 20], 'ratio kept for the members');
});

check('arrange: a group nudge moves every member and stops at the canvas as a group', () => {
    const items = [item('a', 0.05, 10, 10, 10), item('b', 30, 10, 10, 10)];
    const left = nudgeGroup(items, 'ArrowLeft');
    equal([left.a.x, left.b.x], [0, 29.95], 'clamped as a group');
    const right = nudgeGroup(items, 'ArrowRight', true);
    equal([right.a.x, right.b.x], [1.05, 31], 'Shift: 1 %');
    equal(nudgeGroup(items, 'a'), null, 'other keys');
});

check('arrange: align ×6 to the selection bounds, only changed frames', () => {
    const items = [item('a', 10, 10, 10, 10), item('b', 30, 40, 20, 20), item('c', 60, 5, 10, 5)];
    equal(alignFrames(items, 'left'), { b: frame(10, 40, 20, 20), c: frame(10, 5, 10, 5) }, 'left');
    equal(alignFrames(items, 'right'), { a: frame(60, 10, 10, 10), b: frame(50, 40, 20, 20) }, 'right');
    equal(alignFrames(items, 'center'), { a: frame(35, 10, 10, 10), c: frame(35, 5, 10, 5) }, 'centre 40 (b is centred already)');
    equal(alignFrames(items, 'top'), { a: frame(10, 5, 10, 10), b: frame(30, 5, 20, 20) }, 'top');
    equal(alignFrames(items, 'bottom'), { a: frame(10, 50, 10, 10), c: frame(60, 55, 10, 5) }, 'bottom');
    equal(alignFrames(items, 'middle'), { a: frame(10, 27.5, 10, 10), b: frame(30, 22.5, 20, 20), c: frame(60, 30, 10, 5) }, 'middle 32.5');
    equal(alignFrames(items.slice(0, 2), 'left'), { b: frame(10, 40, 20, 20) }, 'two items');
});

check('arrange: one element aligns to the canvas', () => {
    const one = [item('a', 10, 10, 20, 10)];
    equal(alignFrames(one, 'right'), { a: frame(80, 10, 20, 10) }, 'right = 100 - w');
    equal(alignFrames(one, 'center'), { a: frame(40, 10, 20, 10) }, 'centre');
    equal(alignFrames(one, 'middle'), { a: frame(10, 45, 20, 10) }, 'middle');
    equal(alignFrames(one, 'bottom'), { a: frame(10, 90, 20, 10) }, 'bottom');
    equal(alignFrames([item('a', 0, 10, 20, 10)], 'left'), {}, 'already there');
});

check('arrange: distribute with equal gaps on both axes, fewer than 3 = null', () => {
    const items = [item('a', 0, 0, 10, 10), item('b', 15, 30, 20, 10), item('c', 80, 70, 20, 10)];
    equal(distributeFrames(items, 'horizontal'), { b: frame(35, 30, 20, 10) }, 'gap 25: b at 35');
    equal(distributeFrames(items, 'vertical'), { b: frame(15, 35, 20, 10) }, 'vertical gap 25');
    equal(distributeFrames(items.slice(0, 2), 'horizontal'), null, 'two items');
});

check('arrange: distribute keeps the first and the furthest-reaching item, negative gaps, rounding (last exact)', () => {
    // a wide item that starts second but reaches furthest stays last
    const wide = [item('a', 0, 0, 10, 10), item('w', 5, 20, 90, 10), item('b', 50, 40, 10, 10)];
    const moved = distributeFrames(wide, 'horizontal');
    equal(Object.keys(moved), ['b'], 'only b moves');
    // span 95, sizes 110 → gap -7.5: b at 10 - 7.5 = 2.5
    equal(moved.b.x, 2.5, 'negative gap = equal overlap');
    const thirds = [item('a', 0, 0, 10, 10), item('b', 20, 20, 10, 10), item('c', 30, 30, 10, 10), item('d', 90, 40, 10, 10)];
    const spaced = distributeFrames(thirds, 'horizontal');
    // span 100, sizes 40 → gap 20: b 30, c 60, d stays 90
    equal([spaced.b.x, spaced.c.x, spaced.d], [30, 60, undefined], 'equal gaps');
    const odd = [item('a', 0, 0, 10, 10), item('b', 1, 0, 10, 10), item('c', 2, 0, 10, 10), item('d', 50, 0, 10, 10)];
    const rounded = distributeFrames(odd, 'horizontal');
    // gap = (60 - 40) / 3 = 6.666…
    equal([rounded.b.x, rounded.c.x], [16.67, 33.33], 'rounded per item');
    equal(rounded.d, undefined, 'the last one stays exactly');
});

check('arrange: the marquee from any direction, intersecting visible unlocked elements', () => {
    equal(marqueeRect({ x: 40, y: 50 }, { x: 10, y: 20 }), { x: 10, y: 20, w: 30, h: 30 }, 'normalised');
    equal(marqueeRect({ x: -5, y: 120 }, { x: 10, y: 90 }), { x: 0, y: 90, w: 10, h: 10 }, 'clamped');
    const elements = [
        el('a', 0, 0, 10, 10),
        el('b', 20, 20, 10, 10),
        el('c', 5, 5, 10, 10, { hidden: true }),
        el('d', 5, 5, 10, 10, { locked: true }),
        el('e', 15, 0, 5, 5),
    ];
    equal(marqueeHits(elements, { x: 8, y: 8, w: 15, h: 15 }), ['a', 'b'], 'intersects, document order');
    equal(marqueeHits(elements, { x: 10, y: 10, w: 5, h: 5 }), [], 'touching an edge is not enough');
});

const designOf = (...elements) => ({
    schema: 1,
    elements: elements.map((element, index) => ({ id: element.id, type: 'text', frame: element.frame, z: index, hidden: element.hidden ?? false, locked: element.locked ?? false, props: { text: element.id } })),
});

check('state: select, toggle, area select additive, select all skips hidden and locked', () => {
    const state = editor(designOf(el('aaaa0001', 0, 0, 10, 10), el('aaaa0002', 20, 0, 10, 10), el('aaaa0003', 40, 0, 10, 10, { hidden: true }), el('aaaa0004', 60, 0, 10, 10, { locked: true })));
    state.select('aaaa0001');
    equal([state.selectedId, state.selected?.id], ['aaaa0001', 'aaaa0001'], 'one');
    state.toggleSelected('aaaa0002');
    equal(state.selectedIds, ['aaaa0001', 'aaaa0002'], 'two');
    equal([state.selectedId, state.selected], [null, null], 'no single selection for two');
    state.toggleSelected('aaaa0001');
    equal(state.selectedIds, ['aaaa0002'], 'toggled off');
    state.selectIds(['aaaa0001', 'nope0000'], true);
    equal(state.selectedIds, ['aaaa0002', 'aaaa0001'], 'additive, unknown ids dropped');
    state.selectIds(['aaaa0004']);
    equal(state.selectedIds, ['aaaa0004'], 'replacing');
    state.selectAll();
    equal(state.selectedIds, ['aaaa0001', 'aaaa0002'], 'all visible unlocked');
    state.select(null);
    equal(state.selectedIds, [], 'cleared');
});

check('state: group move, align and distribute are one undo step each, undo restores all', () => {
    const state = editor(designOf(el('aaaa0001', 0, 0, 10, 10), el('aaaa0002', 20, 30, 10, 10), el('aaaa0003', 80, 60, 10, 10)));
    state.selectAll();
    const before = JSON.stringify(state.design);
    const items = state.movableItems();

    for (const dx of [1, 2, 3]) {
        state.setFrames(moveGroup(items, dx, 0).frames, 'drag:1');
    }

    state.seal();
    equal(state.undoStack.length, 1, 'a drag = one step');
    equal(state.design.elements.map((element) => element.frame.x), [3, 23, 83], 'moved together');
    state.undo();
    equal(JSON.stringify(state.design), before, 'undo restores all');
    state.redo();
    equal(state.design.elements[1].frame.x, 23, 'redo');

    const steps = state.undoStack.length;
    state.alignSelected('top');
    equal(state.undoStack.length, steps + 1, 'align = one step');
    equal(state.design.elements.map((element) => element.frame.y), [0, 0, 0], 'aligned');
    state.distributeSelected('horizontal');
    equal(state.undoStack.length, steps + 2, 'distribute = one step');
    equal(state.design.elements[1].frame.x, 43, 'equal gaps (3..93)');
    state.undo();
    state.undo();
    equal(state.design.elements.map((element) => [element.frame.x, element.frame.y]), [[3, 0], [23, 30], [83, 60]], 'both undone');
});

check('state: locked members stay put on transforms but are removed by delete', () => {
    const state = editor(designOf(el('aaaa0001', 0, 0, 10, 10), el('aaaa0002', 20, 30, 10, 10, { locked: true }), el('aaaa0003', 40, 50, 10, 10)));
    state.selectIds(['aaaa0001', 'aaaa0002', 'aaaa0003']);
    equal(state.movableSelected().map((element) => element.id), ['aaaa0001', 'aaaa0003'], 'movable');
    state.alignSelected('left');
    equal(state.design.elements.map((element) => element.frame.x), [0, 20, 0], 'locked one untouched');
    equal(state.distributeSelected('horizontal'), false, 'two movable: no distribute');
    state.removeSelected();
    equal(state.design.elements, [], 'all removed, locked too');
    equal(state.selectedIds, [], 'selection pruned');
});

check('state: duplicate keeps the relative stacking on top and selects the copies; hide/lock/style for all', () => {
    const state = editor(designOf(el('aaaa0001', 0, 0, 10, 10), el('aaaa0002', 20, 30, 10, 10), el('aaaa0003', 40, 50, 10, 10)));
    state.selectIds(['aaaa0003', 'aaaa0001']);
    const copies = state.duplicateSelected();
    equal(state.undoStack.length, 1, 'one step');
    equal(state.selectedIds, copies, 'copies selected');
    const [low, high] = copies.map((id) => state.element(id));
    equal([low.props.text, high.props.text], ['aaaa0001', 'aaaa0003'], 'bottom copy first');
    equal([low.z, high.z], [3, 4], 'on top, relative order kept');
    equal([low.frame.x, low.frame.y], [2, 2], 'offset');

    state.toggleHiddenSelected();
    equal(copies.map((id) => state.element(id).hidden), [true, true], 'hide all');
    state.toggleHiddenSelected();
    equal(copies.map((id) => state.element(id).hidden), [false, false], 'show all');
    state.toggleLockedSelected();
    equal(copies.map((id) => state.element(id).locked), [true, true], 'lock all');
    const steps = state.undoStack.length;
    state.editSelected((element) => {
        element.style.color = '#ff0000';
    }, 'sel.style.color');
    state.editSelected((element) => {
        element.style.color = '#00ff00';
    }, 'sel.style.color');
    equal(state.undoStack.length, steps + 1, 'typing one style field = one step');
    equal(copies.map((id) => state.element(id).style.color), ['#00ff00', '#00ff00'], 'style for all');
    equal(state.element('aaaa0002').style.color, undefined, 'others untouched');
    equal(JSON.stringify(state.savePayload().design), JSON.stringify(normaliseDesign(JSON.parse(JSON.stringify(state.design)), { competitionId: COMPETITION }).design), 'payload = normaliseDesign()');
});

check('state: undo prunes the selection to elements that exist', () => {
    const state = editor(designOf(el('aaaa0001', 0, 0, 10, 10)));
    const id = state.addElement('text');
    state.selectIds(['aaaa0001', id]);
    state.undo();
    equal(state.selectedIds, ['aaaa0001'], 'the added one is gone');
});

const { JSDOM } = createRequire(import.meta.url)('jsdom');

function panelFor(state, acts = [], options = {}) {
    const { window } = new JSDOM('<div id="panel"></div><ul id="layers"></ul>');
    const document = window.document;
    const panel = new EditorPanel({
        document,
        t: (key) => ({ selected_count: '%count% elements selected', 'prop.challenge_puzzle': 'Puzzle %position%' }[key] ?? key),
        commit: () => {},
        act: (name, arg) => acts.push([name, arg]),
        getImages: () => ({}),
        upload: async () => null,
        uploadAvailable: false,
        kind: 'screen',
        ...options,
    });
    const container = document.getElementById('panel');
    panel.render(container, state);

    return { window, document, panel, container, layers: document.getElementById('layers') };
}

check('panel: a multi-selection shows the count, Arrange, shared or Mixed values, and acts on all', () => {
    const design = designOf(el('aaaa0001', 0, 0, 20, 10), el('aaaa0002', 30, 0, 20, 10));
    design.elements[0].style = { color: '#ff0000' };
    design.elements[1].style = { color: '#00ff00' };
    const state = editor(design);
    state.selectAll();
    const acts = [];
    const { window, container } = panelFor(state, acts);
    equal(container.querySelector('[data-selected-count]').textContent, '2 elements selected', 'count');
    equal(container.querySelectorAll('[data-align]').length, 6, 'six align buttons');
    equal([...container.querySelectorAll('[data-distribute]')].map((button) => button.disabled), [true, true], 'distribute needs 3');
    const x = container.querySelector('[data-field="sel.frame.x"]');
    const w = container.querySelector('[data-field="sel.frame.w"]');
    equal([x.value, x.placeholder, w.value], ['', 'mixed', '20'], 'x mixed, width shared');
    const color = container.querySelector('[data-field="sel.style.color"]');
    equal([color.value, color.placeholder], ['', 'mixed'], 'colour mixed');
    container.querySelector('[data-align="left"]').click();
    w.value = '25';
    w.dispatchEvent(new window.Event('input'));
    container.querySelector('[data-selection-action="remove"]').click();
    equal(acts.map(([name]) => name), ['align', 'frame', 'remove'], 'actions');
    equal(acts[0][1], 'left', 'edge');
    equal(acts[1][1], { key: 'w', value: 25 }, 'width for all');
    assert(container.querySelector('[data-field$=".when.phases.idle"]') === null, 'no visibility rules in the multi view');
});

check('panel: one element keeps its view plus the canvas align row; layers mark every selected row', () => {
    const state = editor(designOf(el('aaaa0001', 0, 0, 20, 10), el('aaaa0002', 30, 0, 20, 10), el('aaaa0003', 60, 0, 20, 10)));
    state.select('aaaa0002');
    const acts = [];
    const single = panelFor(state, acts);
    assert(single.container.querySelector('[data-field="aaaa0002.frame.x"]') !== null, 'single fields');
    single.container.querySelector('[data-align="right"]').click();
    equal(acts, [['align', 'right']], 'aligns through the state');

    state.selectIds(['aaaa0001', 'aaaa0003']);
    const { panel, layers, window } = panelFor(state);
    const picks = [];
    panel.renderLayers(layers, state, { select: (id, event) => picks.push([id, event?.shiftKey === true]) });
    equal([...layers.querySelectorAll('.is-selected')].map((row) => row.dataset.layer).sort(), ['aaaa0001', 'aaaa0003'], 'both rows');
    equal(layers.querySelectorAll('.display-editor__layer-tools').length, 0, 'per-row tools only for one');
    layers.querySelector('[data-layer="aaaa0002"] .display-editor__layer-name').dispatchEvent(new window.MouseEvent('click', { shiftKey: true, bubbles: true }));
    equal(picks, [['aaaa0002', true]], 'the click carries the modifier');
});

check('panel: division and challenge fields only when the event has divisions / a block; choosing sets or removes the prop', () => {
    const DIVISION = '018d0004-0000-0000-0000-00000000d1d1';
    const state = editor(normaliseDesign({ schema: 1, elements: [{ id: 'aaaa0001', type: 'leaderboard', frame: frame(0, 0, 30, 30) }] }, { competitionId: COMPETITION }).design);
    state.select('aaaa0001');
    const field = (container, key) => container.querySelector(`[data-field="aaaa0001.props.${key}"]`);

    const plain = panelFor(state);
    assert(field(plain.container, 'division') === null && field(plain.container, 'challenge') === null, 'no fields without divisions / blocks');

    const commit = (change, key) => state.changeDesign(change, key);
    const options = {
        commit,
        divisions: [{ id: DIVISION, name: 'Senior 55+', shortName: 'S' }],
        challenges: [{ position: 1, labels: ['Challenge 1'] }, { position: 2, labels: ['Challenge 2', 'B'] }],
    };
    let { container, window } = panelFor(state, [], options);
    const division = field(container, 'division');
    const challenge = field(container, 'challenge');
    equal([...division.options].map((option) => [option.value, option.textContent]), [['', 'prop.division_all'], [DIVISION, 'Senior 55+']], 'division options');
    equal([...challenge.options].map((option) => option.textContent), ['prop.challenge_round', 'Puzzle 1 - Challenge 1', 'Puzzle 2 - Challenge 2 / B'], 'challenge options');
    division.value = DIVISION;
    division.dispatchEvent(new window.Event('change'));
    challenge.value = '2';
    challenge.dispatchEvent(new window.Event('change'));
    equal([state.element('aaaa0001').props.division, state.element('aaaa0001').props.challenge], [DIVISION, 2], 'set');
    ({ container, window } = panelFor(state, [], options));
    equal([field(container, 'division').value, field(container, 'challenge').value], [DIVISION, '2'], 'shown as chosen');
    field(container, 'challenge').value = '';
    field(container, 'challenge').dispatchEvent(new window.Event('change'));
    field(container, 'division').value = '';
    field(container, 'division').dispatchEvent(new window.Event('change'));
    equal(Object.hasOwn(state.element('aaaa0001').props, 'division') || Object.hasOwn(state.element('aaaa0001').props, 'challenge'), false, 'removed, not stored as null');

    // A ticker has a division, never a challenge
    state.changeDesign((next) => {
        next.elements[0].type = 'ticker';
        next.elements[0].props = { division: DIVISION };
    });
    ({ container } = panelFor(state, [], options));
    assert(field(container, 'division') !== null && field(container, 'challenge') === null, 'ticker: division only');
});

check('no innerHTML in the editor modules', () => {
    const offenders = [];
    const files = [
        ...['editor_state.js', 'editor_panel.js', 'geometry.js', 'arrange.js', 'sample_data.js'].map((file) => new URL(`../assets/displays/${file}`, import.meta.url)),
        new URL('../assets/controllers/display_editor_controller.js', import.meta.url),
    ];

    for (const file of files) {
        if (/innerHTML|outerHTML|insertAdjacentHTML/.test(readFileSync(file, 'utf8'))) {
            offenders.push(file.pathname);
        }
    }

    equal(offenders, [], 'offenders');
});

process.stdout.write(JSON.stringify(results));
