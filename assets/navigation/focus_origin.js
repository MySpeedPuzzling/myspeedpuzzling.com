// Was the focus that is arriving moved by Tab / Shift+Tab? (docs/features/navigation NAV-11, WCAG 3.2.1 On Focus)
//
// The browser moves the focus as the default action of the Tab keydown, in the same task - so a capturing keydown
// listener marks "tabbing" and the next task clears it: a focus handler running in between knows the keyboard moved the
// focus there. The top bar's search field (global_search_controller.js) opens the search on a tap or a click into it,
// never when Tab merely passes through: on phones the opened search view hides ?, Inbox and ☰ and takes a history
// entry - a change of context on focus, and the rest of the bar would be out of the Tab order. Typing opens it.
//
// Platform-neutral; installed once per window by the first import.

let tabbing = false;

/** Installs the capturing keydown listener on `window` (once); returns false when it was already there */
export function installFocusOrigin(window) {
    if (window.__mspFocusOrigin) {
        return false;
    }

    window.__mspFocusOrigin = true;
    window.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab' || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        tabbing = true;
        window.setTimeout(() => {
            tabbing = false;
        }, 0);
    }, true);

    return true;
}

/** True while the focus moves because of Tab / Shift+Tab (the same task as its keydown) */
export function focusMovedByTab() {
    return tabbing;
}

if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
    installFocusOrigin(window);
}
