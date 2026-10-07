/**
 * Undo / redo of the participants spreadsheet (D11, O14; docs/features/competitions-management/participants-spreadsheet.md
 * §6 "Undo" and "Client architecture (as built)") - per page view, a reload starts empty.
 *
 * A step = one user action (sheet_changes.js): `{label, groups, inverse, results?, inverseResults?}`. Undo sends the
 * inverse as **new** groups (fresh ids) and the swapped results changes - checked by the server like any edit, so a
 * value somebody changed meanwhile comes back as a conflict ("Can't undo - somebody changed it meanwhile") instead of
 * being overwritten. Only the parts the server applied (or that are still on their way) are undone: a forward group
 * that was refused, conflicted or found nothing to change has nothing to undo.
 *
 * The controller performs what undo()/redo() return exactly like an action (model.applyLocal + queue) and reports every
 * group's outcome with outcome(). Pure - pinned by tests/participants-sheet-core-harness.mjs.
 */

import { newClientId } from '../official_results_api.js';
import { invertGroups } from './sheet_changes.js';

export const UNDO_LIMIT = 100;

const UNDOABLE = new Set(['pending', 'applied']);

export class SheetUndo {
    constructor({ limit = UNDO_LIMIT, newId = newClientId } = {}) {
        this.limit = limit;
        this.newId = newId;
        this.undoStack = [];
        this.redoStack = [];
        // group id → 'pending' | 'applied' | 'unchanged' | 'conflict' | 'refused'
        this.statuses = new Map();
        // group id of an undo/redo group → 'undo' | 'redo' (its refusal is "Can't undo/redo")
        this.reversals = new Map();
    }

    /**
     * A user action was performed: a new undo step, the redo stack cleared (a new edit forks the history).
     */
    record(action) {
        if (!hasContent(action)) {
            return;
        }

        this.track(action);
        this.redoStack = [];
        this.push(this.undoStack, action);
    }

    track(action) {
        for (const group of action.groups) {
            if (!this.statuses.has(group.id)) {
                this.statuses.set(group.id, 'pending');
            }
        }
    }

    push(stack, action) {
        stack.push(action);

        while (stack.length > this.limit) {
            const dropped = stack.shift();
            dropped.groups.forEach((group) => {
                this.statuses.delete(group.id);
                this.reversals.delete(group.id);
            });
        }
    }

    /**
     * The server's answer about a group. Returns 'undo' / 'redo' when the group was an undo/redo the server did not
     * apply (the controller says "Can't undo - somebody changed it meanwhile"), else null.
     */
    outcome(groupId, status) {
        if (!this.statuses.has(groupId)) {
            return null;
        }

        this.statuses.set(groupId, status);
        const reversal = this.reversals.get(groupId) ?? null;

        return reversal !== null && (status === 'conflict' || status === 'refused') ? reversal : null;
    }

    canUndo() {
        return this.undoStack.length > 0;
    }

    canRedo() {
        return this.redoStack.length > 0;
    }

    peekUndo() {
        return this.undoStack[this.undoStack.length - 1] ?? null;
    }

    peekRedo() {
        return this.redoStack[this.redoStack.length - 1] ?? null;
    }

    /**
     * The action that undoes the last step - null when there is nothing (left) to undo. Performed by the caller, then
     * handed back with `done(action)`.
     */
    undo(model) {
        return this.reverse(this.undoStack, model, 'undo');
    }

    redo(model) {
        return this.reverse(this.redoStack, model, 'redo');
    }

    reverse(stack, model, kind) {
        while (stack.length > 0) {
            const step = stack.pop();
            const groups = step.inverse
                .filter((group) => UNDOABLE.has(this.statuses.get(group.inverseOf) ?? 'applied'))
                .map((group) => ({ id: this.newId(), changes: group.changes }));
            const results = step.inverseResults ?? [];

            if (groups.length === 0 && results.length === 0) {
                // Nothing of the step went through - nothing to take back; the next step is the one
                continue;
            }

            const action = {
                label: step.label,
                kind,
                groups,
                inverse: invertGroups(groups, model, this.newId),
                errors: [],
                results,
                inverseResults: step.results ?? [],
            };

            return action;
        }

        return null;
    }

    /**
     * The undo/redo action was performed: it becomes the step of the other stack.
     */
    done(action) {
        this.track(action);
        action.groups.forEach((group) => this.reversals.set(group.id, action.kind));
        this.push(action.kind === 'undo' ? this.redoStack : this.undoStack, action);
    }

    clear() {
        this.undoStack = [];
        this.redoStack = [];
        this.statuses.clear();
        this.reversals.clear();
    }
}

function hasContent(action) {
    return action.groups.length > 0 || (action.results ?? []).length > 0;
}
