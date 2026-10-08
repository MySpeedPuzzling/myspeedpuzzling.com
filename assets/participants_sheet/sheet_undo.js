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
 *
 * - "Keep mine" (a conflict sent again over the other value) is a step of its own (the controller records it), so
 *   Ctrl+Z takes it back instead of skipping to an older step;
 * - an undo of a step that was still on its way and then turned out not saved says so ("…was not saved - nothing to
 *   undo"), not "somebody changed it";
 * - results changes of a step (`inverseResults`) tied to a sheet group (`inverseOf` - a deleted pair's table number)
 *   are undone only when that group went through;
 * - a place change of somebody removed from the event since then is left out of the undo (the server would refuse
 *   the whole group) and reported in `skipped` - e.g. the members of a deleted pair are put back only if still active.
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
        // group id of an undo/redo group → the group it takes back
        this.forwardOf = new Map();
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
                this.forwardOf.delete(group.id);
            });
        }
    }

    /**
     * The server's answer about a group. Returns 'undo' / 'redo' when the group was an undo/redo the server did not
     * apply (the controller says "Can't undo - somebody changed it meanwhile"), 'undo_unsaved' / 'redo_unsaved' when
     * what it took back was itself never saved ("…was not saved - nothing to undo"), else null.
     */
    outcome(groupId, status) {
        if (!this.statuses.has(groupId)) {
            return null;
        }

        this.statuses.set(groupId, status);
        const reversal = this.reversals.get(groupId) ?? null;

        if (reversal === null || (status !== 'conflict' && status !== 'refused')) {
            return null;
        }

        const forward = this.statuses.get(this.forwardOf.get(groupId)) ?? null;

        return forward === 'conflict' || forward === 'refused' ? `${reversal}_unsaved` : reversal;
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
            const skipped = [];
            const groups = step.inverse
                .filter((group) => this.wentThrough(group.inverseOf))
                .map((group) => ({ id: this.newId(), inverseOf: group.inverseOf, changes: this.applicable(group.changes, model, skipped) }))
                .filter((group) => group.changes.length > 0);
            const results = (step.inverseResults ?? []).filter((change) => change.inverseOf === undefined || this.wentThrough(change.inverseOf));

            if (groups.length === 0 && results.length === 0) {
                if (skipped.length > 0) {
                    // Everything left to take back concerns people removed meanwhile - said, and the step is gone
                    return { label: step.label, kind, groups: [], inverse: [], errors: [], results: [], inverseResults: [], skipped };
                }

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
                inverseResults: (step.results ?? []).filter((change) => change.inverseOf === undefined || this.wentThrough(change.inverseOf)),
                skipped,
            };

            return action;
        }

        return null;
    }

    wentThrough(groupId) {
        return UNDOABLE.has(this.statuses.get(groupId) ?? 'applied');
    }

    /**
     * The changes of an undo group the server can apply now: a place change of somebody removed from the event since
     * (and not restored by this very group) is left out - `skipped` says whom.
     */
    applicable(changes, model, skipped) {
        const restored = new Set();

        return changes.filter((change) => {
            if (change.op === 'restore') {
                restored.add(change.participant);
            }

            if (change.op === 'place' && !restored.has(change.participant) && model.isRemoved(change.participant)) {
                skipped.push(change);

                return false;
            }

            return true;
        });
    }

    /**
     * The undo/redo action was performed: it becomes the step of the other stack.
     */
    done(action) {
        if (!hasContent(action)) {
            return;
        }

        this.track(action);
        action.groups.forEach((group) => {
            this.reversals.set(group.id, action.kind);

            if (group.inverseOf !== undefined) {
                this.forwardOf.set(group.id, group.inverseOf);
            }
        });
        this.push(action.kind === 'undo' ? this.redoStack : this.undoStack, action);
    }

    clear() {
        this.undoStack = [];
        this.redoStack = [];
        this.statuses.clear();
        this.reversals.clear();
        this.forwardOf.clear();
    }
}

function hasContent(action) {
    return action.groups.length > 0 || (action.results ?? []).length > 0;
}
