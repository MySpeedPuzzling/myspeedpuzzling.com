// Analytics Studio (docs/features/competitions-management/analytics-studio.md §7.3, trimmed by §0a R10) - the keymap:
// `/` search · Esc (clears the search, a second Esc leaves it; closes help / the detail sheet) · ↑ ↓ / j k move ·
// Enter open · c compare toggle · x clear compare · [ ] puzzle slot · ? help. While typing in the search box only Esc,
// ↑, ↓ and Enter act. A key with Ctrl / ⌘ / Alt is the browser's. Pure - the page adapter passes a KeyboardEvent-like
// `{key, ctrlKey, metaKey, altKey}`, prevents the default when an action comes back and applies it with
// model.js applyAction(). Pinned by tests/analytics-studio-harness.mjs.

export const KEYMAP = [
    { keys: ['/'], action: 'focus_search' },
    { keys: ['Esc'], action: 'escape' },
    { keys: ['↑', '↓', 'j', 'k'], action: 'move' },
    { keys: ['Enter'], action: 'open' },
    { keys: ['c'], action: 'compare' },
    { keys: ['x'], action: 'clear_compare' },
    { keys: ['[', ']'], action: 'slot' },
    { keys: ['?'], action: 'help' },
];

/**
 * The action for a key press, or null (not ours - the browser keeps it).
 *
 * @param {{key: string, ctrlKey?: boolean, metaKey?: boolean, altKey?: boolean}} event
 * @param {{typing?: boolean, query?: string, help?: boolean}} context  typing = the search box has the focus
 */
export function keyAction(event, { typing = false, query = '', help = false } = {}) {
    if (event === null || typeof event !== 'object' || typeof event.key !== 'string') {
        return null;
    }

    if (event.ctrlKey || event.metaKey || event.altKey) {
        return null;
    }

    const { key } = event;

    if (typing) {
        switch (key) {
            case 'Escape':
                return query !== '' ? { type: 'clearSearch' } : { type: 'blurSearch' };
            case 'ArrowDown':
                return { type: 'move', delta: 1 };
            case 'ArrowUp':
                return { type: 'move', delta: -1 };
            case 'Enter':
                return { type: 'open' };
            default:
                return null;
        }
    }

    if (help) {
        if (key === 'Escape' || key === '?') {
            return { type: 'closeHelp' };
        }

        return null;
    }

    switch (key) {
        case '/':
            return { type: 'focusSearch' };
        case 'Escape':
            return { type: 'escape' };
        case 'ArrowDown':
        case 'j':
            return { type: 'move', delta: 1 };
        case 'ArrowUp':
        case 'k':
            return { type: 'move', delta: -1 };
        case 'Enter':
            return { type: 'open' };
        case 'c':
            return { type: 'toggleCompare' };
        case 'x':
            return { type: 'clearCompare' };
        case '[':
            return { type: 'stepSlot', step: -1 };
        case ']':
            return { type: 'stepSlot', step: 1 };
        case '?':
            return { type: 'toggleHelp' };
        default:
            return null;
    }
}
