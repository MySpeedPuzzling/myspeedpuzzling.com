// The display editor's pure modules (competition toolkit D6, D-displays-overlays.md §8.2, §11.2):
// - assets/displays/geometry.js: move, resize from each of the 8 handles, grid + canvas centre/edge snapping, clamping,
//   keyboard nudges, the kept ratio of pictures and QR codes, from-centre resizing, the hit test;
// - assets/displays/editor_state.js: add / duplicate / remove / reorder / hide / lock, undo and redo (coalesced steps,
//   the 100-step bound), the unsaved flag, the save payload = normaliseDesign()'s output, adopting the server's design;
// - assets/displays/sample_data.js: the preview phases, sample results, QR placeholders, never real puzzles.
// Prints {"<scenario>": "ok" | "<what failed>"} as JSON (DisplayEditorScriptTest).

import { readFileSync } from 'node:fs';
import {
    HANDLES, clampFrame, hitTest, keepsRatio, moveFrame, nudgeFrame, placeFrame, resizeFrame, stackOrder, toPercent,
} from '../assets/displays/geometry.js';
import { CATALOGUE, EditorState, UNDO_LIMIT } from '../assets/displays/editor_state.js';
import { ELEMENT_TYPES, PROP_SPECS, STYLE_SPEC, normaliseDesign } from '../assets/displays/schema.js';
import { PREVIEW_PHASES, previewState } from '../assets/displays/sample_data.js';
import { layerLabel, translator } from '../assets/displays/editor_panel.js';
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

check('no innerHTML in the editor modules', () => {
    const offenders = [];
    const files = [
        ...['editor_state.js', 'editor_panel.js', 'geometry.js', 'sample_data.js'].map((file) => new URL(`../assets/displays/${file}`, import.meta.url)),
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
