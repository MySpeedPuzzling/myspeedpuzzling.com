// Where did the focus that is arriving come from? (docs/features/navigation NAV-11, WCAG 3.2.1 On Focus)
//
// Tab / Shift+Tab: the browser moves the focus as the default action of the Tab keydown, in the same task - so a
// capturing keydown listener marks "tabbing" and the next task clears it: a focus handler running in between knows the
// keyboard moved the focus there. Option+Tab counts (Safari on macOS and iPadOS moves the focus through links and buttons
// only with it unless "Press Tab to highlight each item" is on - follow-up review F4); Alt+Tab never arrives as a Tab
// keydown that moves the focus (the system takes it on Windows and Linux). Ctrl / Cmd+Tab switch browser tabs.
//
// The window coming back: when a window, tab or app gets the focus back, the browser fires `focus` on the window and
// then, in the same task, on the element that had the focus before (Chromium and WebKit: window first, then the
// element) - a capturing focus listener on the window marks that task (follow-up review F3).
//
// The top bar's search field (global_search_controller.js) opens the search on a tap or a click into it, never when Tab
// merely passes through nor when the focus only comes back with the window: on phones the opened search view hides ?,
// Inbox and ☰ and takes a history entry - a change of context on focus, and the rest of the bar would be out of the Tab
// order. Typing opens it.
//
// Platform-neutral; installed once per window by the first import.

let tabbing = false;
let windowReturning = false;

/** Installs the capturing keydown and focus listeners on `window` (once); returns false when they were already there */
export function installFocusOrigin(window) {
    if (window.__mspFocusOrigin) {
        return false;
    }

    window.__mspFocusOrigin = true;
    window.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab' || event.ctrlKey || event.metaKey) {
            return;
        }

        tabbing = true;
        window.setTimeout(() => {
            tabbing = false;
        }, 0);
    }, true);

    window.addEventListener('focus', (event) => {
        // Capturing on the window sees every element's focus on its way down too: only the window's own one counts
        if (event.target !== window) {
            return;
        }

        windowReturning = true;
        window.setTimeout(() => {
            windowReturning = false;
        }, 0);
    }, true);

    return true;
}

/** True while the focus moves because of Tab / Shift+Tab / Option+Tab (the same task as its keydown) */
export function focusMovedByTab() {
    return tabbing;
}

/** True while the browser hands the focus back to an element because its window got the focus again (the same task) */
export function focusReturnedWithWindow() {
    return windowReturning;
}

if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
    installFocusOrigin(window);
}
