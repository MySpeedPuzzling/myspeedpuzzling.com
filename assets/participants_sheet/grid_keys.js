/**
 * Keyboard model of the participants spreadsheet - a pure state machine, no DOM
 * (docs/features/competitions-management/participants-spreadsheet.md §7). The WAI-ARIA APG grid pattern plus the
 * Sheets/Excel conventions people bring with them.
 *
 * nextAction(state, keyEvent) tells the grid what a key means; the grid does it. Every answer carries
 * `preventDefault`: false means the browser's own behaviour is wanted (typing into the editor, the native copy/cut/paste
 * events, Tab leaving the grid, IME composition).
 *
 * state = {
 *   mode: 'nav' | 'edit',
 *   editKind: 'type' | 'keep',       // edit started by typing (arrows commit) or by Enter/F2 (arrows move the caret)
 *   row, col,                        // the focused cell
 *   rowCount, colCount,
 *   pageSize: 20,                    // PgUp/PgDn
 *   columns: [{kind: 'text' | 'list' | 'checkbox' | 'readonly' | 'action', space?: 'panel'}],
 *   tabColumns: [0, 1, 2],           // columns Tab visits, default all
 * }
 *
 * macOS: metaKey counts as ctrlKey. Letters of shortcuts come from `key`, or from `code` on a non-Latin layout
 * (Cyrillic, Greek...), so Ctrl+Z is undo on a Czech QWERTZ keyboard (key "z") and on a Russian one (code "KeyZ").
 */

export const PAGE_SIZE = 20;

/**
 * @param {object} state
 * @param {{key: string, code?: string, shiftKey?: boolean, ctrlKey?: boolean, metaKey?: boolean, altKey?: boolean,
 *          isComposing?: boolean, keyCode?: number}} event
 * @returns {{action: string, preventDefault: boolean, row?: number, col?: number, replace?: boolean,
 *            move?: {row: number, col: number} | null, fill?: boolean, direction?: string}}
 */
export function nextAction(state, event) {
    if (event.isComposing || event.keyCode === 229 || event.key === 'Process') {
        // IME: the composition owns every key, Enter included; in navigation it may start an edit
        return state.mode === 'edit' ? none() : { action: 'startEdit', replace: true, preventDefault: false };
    }

    return state.mode === 'edit' ? editAction(state, event) : navAction(state, event);
}

function navAction(state, event) {
    const { key } = event;
    const mod = Boolean(event.ctrlKey || event.metaKey);
    const shift = Boolean(event.shiftKey);
    const alt = Boolean(event.altKey);
    const column = state.columns?.[state.col] ?? { kind: 'text' };
    const letter = shortcutLetter(event);

    if (mod && !alt && letter !== null) {
        switch (letter) {
            case 'c': return { action: 'copy', preventDefault: false };
            case 'x': return { action: 'cut', preventDefault: false };
            case 'v': return { action: 'paste', preventDefault: false };
            case 'd': return { action: 'fillDown', preventDefault: true };
            case 'a': return { action: 'selectAll', preventDefault: true };
            case 'z': return { action: shift ? 'redo' : 'undo', preventDefault: true };
            case 'y': return { action: 'redo', preventDefault: true };
            default: return none();
        }
    }

    if (alt && !mod && key === 'ArrowDown') {
        return isEditable(column) ? { action: 'openList', preventDefault: true } : none();
    }

    const target = navigationTarget(state, key, mod);

    if (target !== null) {
        if (alt) {
            return none();
        }

        return { action: shift ? 'extend' : 'move', row: target.row, col: target.col, preventDefault: true };
    }

    switch (key) {
        case 'Tab': {
            if (mod || alt) {
                return none();
            }

            const next = tabTarget(state, shift ? -1 : 1);

            return next === null
                ? { action: 'leave', direction: shift ? 'backward' : 'forward', preventDefault: false }
                : { action: 'move', row: next.row, col: next.col, preventDefault: true };
        }

        case 'Enter':
            if (mod) {
                return { action: 'fillSelection', preventDefault: true };
            }

            if (shift || alt) {
                return none();
            }

            return activateOrEdit(column, { action: 'startEdit', replace: false, preventDefault: true });

        case 'F2':
            return isEditable(column)
                ? { action: 'startEdit', replace: false, preventDefault: true }
                : { action: 'readonly', preventDefault: true };

        case ' ':
        case 'Spacebar':
            if (shift && !mod) {
                return { action: 'selectRow', preventDefault: true };
            }

            if (mod && !shift) {
                return { action: 'selectColumn', preventDefault: true };
            }

            if (column.space === 'panel') {
                return { action: 'openPanel', preventDefault: true };
            }

            return activateOrEdit(column, { action: 'startEdit', replace: true, preventDefault: false });

        case 'Delete':
        case 'Backspace':
            return mod || alt ? none() : { action: 'clear', preventDefault: true };

        case 'Escape':
            return { action: 'collapse', preventDefault: false };

        case 'Dead':
            // a dead key (´ ˇ on a Czech keyboard) composes with the next key, whose keydown carries "á"
            return none();

        default:
            break;
    }

    if (isPrintable(event)) {
        if (!isEditable(column)) {
            return { action: 'readonly', preventDefault: true };
        }

        // not prevented: the grid focuses the empty editor inside this keydown and the browser types the character
        // into it - dead keys, AltGr, Option and emoji included, no key-to-character mapping of ours
        return { action: 'startEdit', replace: true, preventDefault: false };
    }

    return none();
}

function editAction(state, event) {
    const { key } = event;
    const mod = Boolean(event.ctrlKey || event.metaKey);
    const shift = Boolean(event.shiftKey);
    const alt = Boolean(event.altKey);

    switch (key) {
        case 'Enter':
            if (alt) {
                return none();
            }

            if (mod) {
                return { action: 'commit', move: null, fill: true, preventDefault: true };
            }

            return {
                action: 'commit',
                move: clampedMove(state, shift ? -1 : 1, 0),
                fill: false,
                preventDefault: true,
            };

        case 'Tab':
            if (mod || alt) {
                return none();
            }

            return { action: 'commit', move: tabTarget(state, shift ? -1 : 1), fill: false, preventDefault: true };

        case 'Escape':
            return { action: 'cancel', preventDefault: true };

        case 'F2':
            return { action: 'toggleEditKind', preventDefault: true };

        case 'ArrowDown':
            if (alt && !mod) {
                return { action: 'openList', preventDefault: true };
            }
        // falls through
        case 'ArrowUp':
        case 'ArrowLeft':
        case 'ArrowRight': {
            if (state.editKind !== 'type' || shift || mod || alt) {
                return none();
            }

            const delta = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] }[key];

            return { action: 'commit', move: clampedMove(state, delta[0], delta[1]), fill: false, preventDefault: true };
        }

        default:
            return none();
    }
}

function navigationTarget(state, key, mod) {
    const lastRow = Math.max(0, state.rowCount - 1);
    const lastCol = Math.max(0, state.colCount - 1);
    const page = state.pageSize ?? PAGE_SIZE;
    const { row, col } = state;

    switch (key) {
        case 'ArrowUp': return { row: mod ? 0 : Math.max(0, row - 1), col };
        case 'ArrowDown': return { row: mod ? lastRow : Math.min(lastRow, row + 1), col };
        case 'ArrowLeft': return { row, col: mod ? 0 : Math.max(0, col - 1) };
        case 'ArrowRight': return { row, col: mod ? lastCol : Math.min(lastCol, col + 1) };
        case 'Home': return mod ? { row: 0, col: 0 } : { row, col: 0 };
        case 'End': return mod ? { row: lastRow, col: lastCol } : { row, col: lastCol };
        case 'PageUp': return { row: Math.max(0, row - page), col };
        case 'PageDown': return { row: Math.min(lastRow, row + page), col };
        default: return null;
    }
}

/**
 * The next (direction 1) or previous (-1) cell Tab visits, wrapping to the next/previous row; null past the very last
 * (or before the very first) cell, where Tab leaves the grid.
 */
export function tabTarget(state, direction) {
    const columns = (state.tabColumns ?? Array.from({ length: state.colCount }, (_, index) => index)).slice().sort((a, b) => a - b);

    if (columns.length === 0) {
        return null;
    }

    if (direction > 0) {
        const next = columns.find((col) => col > state.col);

        if (next !== undefined) {
            return { row: state.row, col: next };
        }

        return state.row + 1 < state.rowCount ? { row: state.row + 1, col: columns[0] } : null;
    }

    const previous = [...columns].reverse().find((col) => col < state.col);

    if (previous !== undefined) {
        return { row: state.row, col: previous };
    }

    return state.row > 0 ? { row: state.row - 1, col: columns[columns.length - 1] } : null;
}

function clampedMove(state, rowDelta, colDelta) {
    return {
        row: Math.min(Math.max(0, state.row + rowDelta), Math.max(0, state.rowCount - 1)),
        col: Math.min(Math.max(0, state.col + colDelta), Math.max(0, state.colCount - 1)),
    };
}

function activateOrEdit(column, editAnswer) {
    switch (column.kind) {
        case 'checkbox': return { action: 'toggle', preventDefault: true };
        case 'action': return { action: 'activate', preventDefault: true };
        case 'readonly': return { action: 'readonly', preventDefault: true };
        default: return editAnswer;
    }
}

function isEditable(column) {
    return column.kind === 'text' || column.kind === 'list' || column.kind === undefined;
}

/**
 * A key producing text: one character (one code point - emoji included), no Cmd, and no Ctrl unless Alt is held too
 * (AltGr on Windows reports Ctrl+Alt: AltGr+V = "@" on a Czech keyboard).
 */
export function isPrintable(event) {
    const { key } = event;

    if (typeof key !== 'string' || key.length === 0 || [...key].length !== 1) {
        return false;
    }

    if (event.metaKey) {
        return false;
    }

    return !event.ctrlKey || Boolean(event.altKey);
}

function shortcutLetter(event) {
    const key = typeof event.key === 'string' ? event.key : '';

    if (/^[a-z]$/i.test(key)) {
        return key.toLowerCase();
    }

    const code = /^Key([A-Z])$/.exec(event.code ?? '');

    return code === null ? null : code[1].toLowerCase();
}

function none() {
    return { action: 'none', preventDefault: false };
}
