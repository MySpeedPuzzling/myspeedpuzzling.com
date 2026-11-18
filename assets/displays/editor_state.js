// The display editor's document (docs/features/competitions-management/toolkit/D-displays-overlays.md §8.2,
// competition toolkit D6, v2 E1) - pure, no DOM: the design being edited (always normalised by schema.js), the
// selection (one or more elements, outside the undo history), undo/redo (in memory, 100 steps), the unsaved flag and
// the save payload. Layers: add from the catalogue, reorder, hide, lock, duplicate, delete; group writes of a
// multi-selection (move, align, distribute, remove, duplicate, hide, lock, style) - each one commit = one undo step.
// Run under node by tests/display-editor-harness.mjs.

import { normaliseDesign, RESULTS_TYPES } from './schema.js';
import { clampFrame, placeFrame, stackOrder } from './geometry.js';
import { alignFrames, distributeFrames } from './arrange.js';

export const UNDO_LIMIT = 100;

// The "Add element" menu, grouped (§8.2)
export const CATALOGUE = [
    { group: 'clock', types: ['timer', 'status', 'progress', 'clock'] },
    { group: 'text', types: ['text', 'field', 'round_badge', 'message'] },
    { group: 'pictures', types: ['image', 'rotator', 'qr', 'box'] },
    { group: 'results', types: ['leaderboard', 'finished_count', 'ticker', 'standings', 'spotlight'] },
    { group: 'other', types: ['puzzle'] },
];

// Where a new element lands (% of the canvas); a QR code and a picture start square on the 16:9 canvas (h = w × 16/9)
const DEFAULT_FRAMES = {
    timer: { x: 20, y: 30, w: 60, h: 30 },
    status: { x: 25, y: 62, w: 50, h: 8 },
    progress: { x: 10, y: 85, w: 80, h: 3 },
    clock: { x: 75, y: 5, w: 20, h: 8 },
    text: { x: 30, y: 10, w: 40, h: 10 },
    field: { x: 20, y: 8, w: 60, h: 10 },
    round_badge: { x: 40, y: 20, w: 20, h: 7 },
    image: { x: 42.5, y: 36.67, w: 15, h: 26.67 },
    rotator: { x: 35, y: 35, w: 30, h: 20 },
    qr: { x: 85, y: 75, w: 10, h: 17.78 },
    box: { x: 25, y: 25, w: 50, h: 50 },
    puzzle: { x: 30, y: 30, w: 40, h: 40 },
    finished_count: { x: 35, y: 80, w: 30, h: 8 },
    leaderboard: { x: 60, y: 10, w: 35, h: 80 },
    ticker: { x: 5, y: 80, w: 60, h: 15 },
    standings: { x: 58, y: 6, w: 38, h: 88 },
    // The live control's message: a lower third
    message: { x: 10, y: 78, w: 80, h: 12 },
    // The live control's spotlight: a name plate in the lower left
    spotlight: { x: 4, y: 70, w: 42, h: 20 },
};

const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

export function newId(random, taken) {
    for (;;) {
        let id = '';

        for (let i = 0; i < 8; i += 1) {
            id += ID_ALPHABET[Math.floor(random() * ID_ALPHABET.length) % ID_ALPHABET.length];
        }

        if (!taken.has(id)) {
            return id;
        }
    }
}

/**
 * A new element of the catalogue: its default frame, on top of everything (z), props left to the schema's defaults -
 * a text gets the given placeholder, a box a visible panel. Results elements start on published results (§5.1).
 */
export function newElement(type, id, z, { text = 'Text' } = {}) {
    const element = {
        id,
        type,
        name: '',
        frame: { ...(DEFAULT_FRAMES[type] ?? { x: 40, y: 40, w: 20, h: 20 }), rotate: 0 },
        z,
        hidden: false,
        locked: false,
        style: {},
        props: {},
    };

    if (type === 'text') {
        element.props.text = text;
    }

    if (type === 'box') {
        element.style = { background: '#ffffff26', radius: 24 };
    }

    return element;
}

export class EditorState {
    constructor({ design, designVersion, competitionId, random = Math.random, texts = {} }) {
        this.competitionId = competitionId;
        this.random = random;
        this.texts = texts;
        this.design = this.normalise(design) ?? this.normalise({ schema: 1 });
        this.designVersion = designVersion;
        this.savedJson = JSON.stringify(this.design);
        // The selection in the order it was made; selectedId / selected = the one element when exactly one is selected
        this.selectedIds = [];
        this.undoStack = [];
        this.redoStack = [];
        this.lastKey = null;
    }

    normalise(design) {
        const { design: normalised } = normaliseDesign(clone(design), { competitionId: this.competitionId });

        return normalised;
    }

    get dirty() {
        return JSON.stringify(this.design) !== this.savedJson;
    }

    get canUndo() {
        return this.undoStack.length > 0;
    }

    get canRedo() {
        return this.redoStack.length > 0;
    }

    get selectedId() {
        return this.selectedIds.length === 1 ? this.selectedIds[0] : null;
    }

    get selected() {
        return this.element(this.selectedId);
    }

    isSelected(id) {
        return this.selectedIds.includes(id);
    }

    /** The selected elements in document order */
    selectedElements() {
        return this.design.elements.filter((element) => this.selectedIds.includes(element.id));
    }

    /** What group transforms move: the selected elements that are not locked */
    movableSelected() {
        return this.selectedElements().filter((element) => !element.locked);
    }

    /** The movable selection as arrange.js items */
    movableItems() {
        return this.movableSelected().map((element) => ({ id: element.id, type: element.type, frame: { ...element.frame } }));
    }

    pruneSelection() {
        this.selectedIds = this.selectedIds.filter((id) => this.element(id) !== null);
    }

    element(id) {
        return this.design.elements.find((element) => element.id === id) ?? null;
    }

    /**
     * Applies a new design (normalised; refused = false). Changes with the same `key` one after another (typing in one
     * field, one drag) are one undo step.
     */
    commit(next, key = null) {
        const normalised = this.normalise(next);

        if (normalised === null) {
            return false;
        }

        if (JSON.stringify(normalised) === JSON.stringify(this.design)) {
            return true;
        }

        if (key === null || key !== this.lastKey) {
            this.undoStack.push(this.design);

            if (this.undoStack.length > UNDO_LIMIT) {
                this.undoStack.shift();
            }
        }

        this.lastKey = key;
        this.redoStack = [];
        this.design = normalised;
        this.pruneSelection();

        return true;
    }

    /** Ends the coalescing of the current key: the next change is its own undo step */
    seal() {
        this.lastKey = null;
    }

    undo() {
        if (!this.canUndo) {
            return false;
        }

        this.redoStack.push(this.design);
        this.design = this.undoStack.pop();
        this.afterHistoryStep();

        return true;
    }

    redo() {
        if (!this.canRedo) {
            return false;
        }

        this.undoStack.push(this.design);
        this.design = this.redoStack.pop();
        this.afterHistoryStep();

        return true;
    }

    afterHistoryStep() {
        this.lastKey = null;
        this.pruneSelection();
    }

    /** Only this element (null: nothing) */
    select(id) {
        this.selectedIds = id !== null && this.element(id) !== null ? [id] : [];
        this.lastKey = null;
    }

    /** Shift/Ctrl/Cmd+click: adds the element, or takes it out when it is selected */
    toggleSelected(id) {
        if (this.isSelected(id)) {
            this.selectedIds = this.selectedIds.filter((selected) => selected !== id);
        } else if (this.element(id) !== null) {
            this.selectedIds = [...this.selectedIds, id];
        }

        this.lastKey = null;
    }

    /** These elements (an area selection); additive = added to the current selection */
    selectIds(ids, additive = false) {
        const next = additive ? [...this.selectedIds] : [];

        for (const id of ids) {
            if (!next.includes(id) && this.element(id) !== null) {
                next.push(id);
            }
        }

        this.selectedIds = next;
        this.lastKey = null;
    }

    /** Ctrl/Cmd+A: every element that is neither hidden nor locked */
    selectAll() {
        this.selectIds(this.design.elements.filter((element) => !element.hidden && !element.locked).map((element) => element.id));
    }

    withElement(id, change, key = null) {
        const next = clone(this.design);
        const element = next.elements.find((item) => item.id === id);

        if (!element) {
            return false;
        }

        change(element, next);

        return this.commit(next, key);
    }

    topZ() {
        return this.design.elements.reduce((max, element) => Math.max(max, element.z), -1);
    }

    takenIds() {
        return new Set(this.design.elements.map((element) => element.id));
    }

    addElement(type) {
        const id = newId(this.random, this.takenIds());
        const next = clone(this.design);
        const element = newElement(type, id, Math.min(999, this.topZ() + 1), { text: this.texts.text ?? 'Text' });
        // Not on top of what is there already (the default spot when it is free)
        element.frame = placeFrame(element.frame, next.elements.filter((other) => !other.hidden).map((other) => other.frame));
        next.elements.push(element);

        if (!this.commit(next)) {
            return null;
        }

        this.select(id);

        return id;
    }

    duplicateElement(id) {
        const source = this.element(id);

        if (source === null) {
            return null;
        }

        const copy = clone(source);
        copy.id = newId(this.random, this.takenIds());
        copy.z = Math.min(999, this.topZ() + 1);
        copy.locked = false;
        copy.frame = clampFrame({ ...copy.frame, x: copy.frame.x + 2, y: copy.frame.y + 2 });
        const next = clone(this.design);
        next.elements.push(copy);

        if (!this.commit(next)) {
            return null;
        }

        this.select(copy.id);

        return copy.id;
    }

    removeElement(id) {
        const next = clone(this.design);
        next.elements = next.elements.filter((element) => element.id !== id);

        return this.commit(next);
    }

    /** The layers list: top first */
    layers() {
        return stackOrder(this.design.elements);
    }

    /**
     * One step up (towards the top) or down in the stack; afterwards every element has its own z (0 = bottom).
     */
    moveLayer(id, direction) {
        const order = this.layers().map((element) => element.id);
        const index = order.indexOf(id);
        const target = direction === 'up' ? index - 1 : index + 1;

        if (index < 0 || target < 0 || target >= order.length) {
            return false;
        }

        [order[index], order[target]] = [order[target], order[index]];
        const next = clone(this.design);

        for (const element of next.elements) {
            element.z = order.length - 1 - order.indexOf(element.id);
        }

        return this.commit(next);
    }

    toggleHidden(id) {
        return this.withElement(id, (element) => {
            element.hidden = !element.hidden;
        });
    }

    toggleLocked(id) {
        return this.withElement(id, (element) => {
            element.locked = !element.locked;
        });
    }

    /** Several frames in one commit ({[id]: frame}, partial frames merged) - one undo step, a key coalesces a drag */
    setFrames(frames, key = null) {
        const ids = Object.keys(frames);

        if (ids.length === 0) {
            return false;
        }

        const next = clone(this.design);

        for (const element of next.elements) {
            if (frames[element.id]) {
                element.frame = { ...element.frame, ...frames[element.id] };
            }
        }

        return this.commit(next, key);
    }

    alignSelected(edge) {
        return this.setFrames(alignFrames(this.movableItems(), edge));
    }

    distributeSelected(axis) {
        const frames = distributeFrames(this.movableItems(), axis);

        return frames === null ? false : this.setFrames(frames);
    }

    /** Delete: every selected element, locked ones too (as a single selection) */
    removeSelected() {
        if (this.selectedIds.length === 1) {
            return this.removeElement(this.selectedIds[0]);
        }

        const ids = new Set(this.selectedIds);

        if (ids.size === 0) {
            return false;
        }

        const next = clone(this.design);
        next.elements = next.elements.filter((element) => !ids.has(element.id));

        return this.commit(next);
    }

    /**
     * Duplicate: copies of every selected element on top, in their relative stacking order, +2 % offset, unlocked;
     * the copies become the selection.
     */
    duplicateSelected() {
        if (this.selectedIds.length === 1) {
            const id = this.duplicateElement(this.selectedIds[0]);

            return id === null ? [] : [id];
        }

        const sources = stackOrder(this.selectedElements()).reverse();

        if (sources.length === 0) {
            return [];
        }

        const taken = this.takenIds();
        const next = clone(this.design);
        const top = this.topZ();
        const ids = [];

        sources.forEach((source, index) => {
            const copy = clone(source);
            copy.id = newId(this.random, taken);
            taken.add(copy.id);
            copy.z = Math.min(999, top + 1 + index);
            copy.locked = false;
            copy.frame = clampFrame({ ...copy.frame, x: copy.frame.x + 2, y: copy.frame.y + 2 });
            next.elements.push(copy);
            ids.push(copy.id);
        });

        if (!this.commit(next)) {
            return [];
        }

        this.selectIds(ids);

        return ids;
    }

    /** Any selected element visible → hide all, else show all */
    toggleHiddenSelected() {
        const hide = this.selectedElements().some((element) => !element.hidden);

        return this.editSelected((element) => {
            element.hidden = hide;
        });
    }

    /** Any selected element unlocked → lock all, else unlock all */
    toggleLockedSelected() {
        const lock = this.selectedElements().some((element) => !element.locked);

        return this.editSelected((element) => {
            element.locked = lock;
        });
    }

    /** change(element) for every selected element in one commit (the panel's group edits) */
    editSelected(change, key = null) {
        const ids = new Set(this.selectedIds);

        if (ids.size === 0) {
            return false;
        }

        return this.changeDesign((next) => {
            for (const element of next.elements) {
                if (ids.has(element.id)) {
                    change(element);
                }
            }
        }, key);
    }

    setFrame(id, frame, key = null) {
        return this.withElement(id, (element) => {
            element.frame = { ...element.frame, ...frame };
        }, key);
    }

    changeDesign(change, key = null) {
        const next = clone(this.design);
        change(next);

        return this.commit(next, key);
    }

    liveElementIds() {
        return this.design.elements
            .filter((element) => RESULTS_TYPES.includes(element.type) && element.props.source === 'live')
            .map((element) => element.id);
    }

    /** What the save endpoint gets: exactly normaliseDesign()'s output (D2 contract) */
    savePayload(liveConfirmed = false) {
        return { design: this.normalise(this.design), baseVersion: this.designVersion, liveConfirmed };
    }

    /** The server's normalised design after a save: becomes the saved state (undo history kept) */
    adoptSaved(design, designVersion) {
        const normalised = this.normalise(design);

        if (normalised === null) {
            return false;
        }

        if (JSON.stringify(normalised) !== JSON.stringify(this.design)) {
            this.undoStack.push(this.design);
            this.redoStack = [];
            this.design = normalised;
        }

        this.designVersion = designVersion;
        this.savedJson = JSON.stringify(normalised);
        this.afterHistoryStep();

        return true;
    }

    /** "Load the saved version" after a conflict: local changes and history dropped */
    replaceWithSaved(design, designVersion) {
        const normalised = this.normalise(design);

        if (normalised === null) {
            return false;
        }

        this.design = normalised;
        this.designVersion = designVersion;
        this.savedJson = JSON.stringify(normalised);
        this.undoStack = [];
        this.redoStack = [];
        this.afterHistoryStep();

        return true;
    }
}
