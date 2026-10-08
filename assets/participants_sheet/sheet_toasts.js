/**
 * Visible feedback of the participants spreadsheet (business review BR1; docs/features/competitions-management/
 * participants-spreadsheet.md "Client architecture (as built)" → `notify`): short messages in the sheet's status area
 * for what a sighted organiser would otherwise not see - a change that was refused, "nothing to undo", a typed value
 * dropped when the editor closed, a problem on another tab. The controller's `notify(text, {kind, anchor, actions})`
 * announces the text in the page's live region and hands it here; the toasts themselves are no live region (said once).
 *
 * - A stack under the sheet's bar on wide screens (newest first, at most TOAST_MAX), at the bottom above the safe area
 *   on phones (CSS) - an overlay: the grid never moves.
 * - `anchor` (wide screens only): the newest toast points at that cell (`{row, col}` - the controller finds it in the
 *   page's grids) or element - right under it, above when there is no room. It goes back to the stack when the
 *   organiser moves on (the focus moves, a click elsewhere) or the cell scrolls out of view.
 * - Errors and warnings stay TOAST_DURATIONS ms (~8 s), information ~4 s - the time stands still while the pointer is on
 *   the toast or the focus in it. A close button; Esc in a toast closes it (the focus goes back where it came from).
 *   The same message again replaces the one shown (its time starts again).
 * - `actions`: `[{label, run}]` buttons ("Show", "Try again") - a click runs it and closes the toast.
 * - While a modal dialog is open (a preview, the person editor, the help) the toasts show inside it - the page behind a
 *   modal dialog is inert; they come back to the stack when it closes.
 *
 * Pure DOM, no framework; `setTimer` / `clearTimer` / `now` can be swapped for a test clock.
 */

import { escapeHtml } from './sheet_grid.js';

export const TOAST_DURATIONS = { error: 8000, warning: 8000, info: 4000 };
export const TOAST_MAX = 3;
// Room kept between an anchored toast and its cell / the window's edges
const GAP = 6;
const EDGE = 8;
const KINDS = new Set(['error', 'warning', 'info']);
const ICONS = { error: 'bi-x-octagon', warning: 'bi-exclamation-triangle', info: 'bi-info-circle' };

let toastCounter = 0;

/** The topmost open modal dialog of the document (the page behind it is inert), or null. */
export function openModalDialog(doc = document) {
    const open = [...doc.querySelectorAll('dialog[open]')];

    for (let index = open.length - 1; index >= 0; index--) {
        let modal;

        try {
            modal = open[index].matches(':modal');
        } catch (e) {
            // A browser (or jsdom) without :modal - an open dialog is taken for a modal one
            modal = true;
        }

        if (modal) {
            return open[index];
        }
    }

    return null;
}

export class SheetToasts {
    /**
     * @param {object} options
     * @param {HTMLElement} options.region         the page's stack (placed by CSS)
     * @param {{t: function(string, object=): string}} options.texts   core texts (`notify_close`)
     * @param {function(*): (Element|null)} [options.resolveAnchor]   `{row, col}` / an element → the element pointed at
     * @param {function(): boolean} [options.isPhone]                  phones never point at a cell
     * @param {function(): void} [options.returnFocus]                 Esc / close with the focus in a toast that came
     *        from nowhere known: where the focus goes (the view's active cell)
     * @param {function(function, number): *} [options.setTimer]
     * @param {function(*): void} [options.clearTimer]
     * @param {function(): number} [options.now]
     */
    constructor(options) {
        this.options = options;
        this.region = options.region;
        this.texts = options.texts;
        this.setTimer = options.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
        this.clearTimer = options.clearTimer ?? ((id) => clearTimeout(id));
        this.now = options.now ?? (() => Date.now());
        this.toasts = [];
        this.dialogRegions = new Map();
        this.listening = false;
        this.frame = null;
        this.focusBefore = null;
        this.onViewportChange = () => this.scheduleReposition();
        this.onFocusMove = (event) => this.organiserMoved(event);
        this.region.classList.add('sheet-toasts');
        this.wireRegion(this.region);
    }

    /**
     * Shows a message. Returns `{id, dismiss()}`.
     *
     * @param {string} text
     * @param {{kind?: 'error'|'warning'|'info', anchor?: object|Element|null, actions?: Array<{label: string, run: function(): void}>}} [options]
     */
    show(text, { kind = 'info', anchor = null, actions = [] } = {}) {
        const level = KINDS.has(kind) ? kind : 'info';
        const message = String(text ?? '');

        // The same message again (a second refused click): the old one goes, this one comes on top with a fresh time
        for (const toast of this.toasts.filter((shown) => shown.text === message && shown.kind === level)) {
            this.remove(toast, { refocus: false });
        }

        const id = `sheet-toast-${++toastCounter}`;
        const element = document.createElement('div');
        element.className = `sheet-toast sheet-toast-${level}`;
        element.id = id;
        element.innerHTML = `<i class="bi ${ICONS[level]} sheet-toast-icon" aria-hidden="true"></i>`
            + `<span class="sheet-toast-text">${escapeHtml(message)}</span>`
            + `${(actions ?? []).map((action, index) => `<button type="button" class="btn btn-sm btn-link sheet-toast-action" data-toast-action="${index}">${escapeHtml(action.label)}</button>`).join('')}`
            + `<button type="button" class="btn-close sheet-toast-close" data-toast-close aria-label="${escapeHtml(this.texts.t('notify_close'))}"></button>`;

        const toast = {
            id,
            element,
            kind: level,
            text: message,
            actions: actions ?? [],
            anchor: null,
            timer: null,
            remaining: TOAST_DURATIONS[level],
            startedAt: 0,
            hovered: false,
            focused: false,
            armed: false,
        };

        const region = this.regionFor();
        region.prepend(element);
        this.toasts.push(toast);

        // At most TOAST_MAX: the oldest goes
        while (this.toasts.length > TOAST_MAX) {
            this.remove(this.toasts[0], { refocus: false });
        }

        if (anchor !== null && anchor !== undefined && !(this.options.isPhone?.() ?? false)) {
            // Only the newest toast points at a cell - an older one goes back to the stack
            this.toasts.filter((other) => other !== toast && other.anchor !== null).forEach((other) => this.dock(other));
            toast.anchor = anchor;

            if (this.position(toast)) {
                // The focus moves of the action that caused the toast (a click elsewhere, the blur committing the
                // edit) are not "moving on" - only what comes after them
                this.setTimer(() => {
                    toast.armed = true;
                }, 0);
            }
        }

        this.startTimer(toast);

        return { id, dismiss: () => this.dismiss(id) };
    }

    dismiss(id) {
        const toast = this.toasts.find((shown) => shown.id === id);

        if (toast) {
            this.remove(toast, { refocus: true });
        }
    }

    /** What is shown, newest first - `[{id, kind, text, anchored}]` (tests, the controller). */
    shown() {
        return this.toasts.slice().reverse().map((toast) => ({ id: toast.id, kind: toast.kind, text: toast.text, anchored: toast.anchor !== null }));
    }

    destroy() {
        for (const toast of this.toasts.slice()) {
            this.remove(toast, { refocus: false });
        }

        this.stopListening();
        cancelAnimationFrame(this.frame);
        this.dialogRegions.forEach(({ region, dialog, onClose }) => {
            dialog.removeEventListener('close', onClose);
            region.remove();
        });
        this.dialogRegions.clear();
        this.region.replaceChildren();
    }

    // ---------------------------------------------------------------- regions

    /** The open modal dialog's own stack (the page behind it is inert), else the page's. */
    regionFor() {
        const dialog = openModalDialog(this.region.ownerDocument);

        if (dialog === null || dialog.contains(this.region)) {
            return this.region;
        }

        let entry = this.dialogRegions.get(dialog);

        if (!entry || !dialog.contains(entry.region)) {
            const region = document.createElement('div');
            region.className = 'sheet-toasts sheet-toasts-dialog';
            dialog.append(region);
            this.wireRegion(region);
            // The dialog closes: its toasts come back to the page's stack (newest first, as they were)
            const onClose = () => {
                [...region.children].reverse().forEach((child) => this.region.prepend(child));
                region.remove();
                this.dialogRegions.delete(dialog);
            };
            dialog.addEventListener('close', onClose, { once: true });
            entry = { region, dialog, onClose };
            this.dialogRegions.set(dialog, entry);
        }

        return entry.region;
    }

    wireRegion(region) {
        region.addEventListener('click', (event) => {
            const toast = this.toastOf(event.target);

            if (toast === null) {
                return;
            }

            const action = event.target.closest('[data-toast-action]');

            if (action) {
                const run = toast.actions[Number(action.dataset.toastAction)]?.run;
                this.remove(toast, { refocus: false });
                run?.();
            } else if (event.target.closest('[data-toast-close]')) {
                this.remove(toast, { refocus: true });
            }
        });
        region.addEventListener('keydown', (event) => {
            const toast = this.toastOf(event.target);

            if (toast !== null && event.key === 'Escape') {
                // Not the dialog's Esc, nor the grid's: this toast goes
                event.preventDefault();
                event.stopPropagation();
                this.remove(toast, { refocus: true });
            }
        });
        region.addEventListener('focusin', (event) => {
            const toast = this.toastOf(event.target);

            // Where the focus came from - it goes back there when the toast closes
            if (event.relatedTarget && !region.contains(event.relatedTarget)) {
                this.focusBefore = event.relatedTarget;
            }

            if (toast !== null) {
                toast.focused = true;
                this.pause(toast);
            }
        });
        region.addEventListener('focusout', (event) => {
            const toast = this.toastOf(event.target);

            if (toast !== null && !toast.element.contains(event.relatedTarget)) {
                toast.focused = false;
                this.resume(toast);
            }
        });
        region.addEventListener('mouseover', (event) => {
            const toast = this.toastOf(event.target);

            if (toast !== null && !toast.hovered) {
                toast.hovered = true;
                this.pause(toast);
            }
        });
        region.addEventListener('mouseout', (event) => {
            const toast = this.toastOf(event.target);

            if (toast !== null && !toast.element.contains(event.relatedTarget)) {
                toast.hovered = false;
                this.resume(toast);
            }
        });
    }

    toastOf(target) {
        const element = target?.closest?.('.sheet-toast');

        return element ? (this.toasts.find((toast) => toast.element === element) ?? null) : null;
    }

    // ---------------------------------------------------------------- time

    startTimer(toast) {
        this.clearTimer(toast.timer);
        toast.startedAt = this.now();
        toast.timer = this.setTimer(() => this.remove(toast, { refocus: true }), toast.remaining);
    }

    pause(toast) {
        if (toast.timer === null) {
            return;
        }

        this.clearTimer(toast.timer);
        toast.timer = null;
        toast.remaining = Math.max(0, toast.remaining - (this.now() - toast.startedAt));
    }

    resume(toast) {
        if (toast.hovered || toast.focused || toast.timer !== null || !this.toasts.includes(toast)) {
            return;
        }

        // A moment to finish reading after the pointer or the focus left
        toast.remaining = Math.max(toast.remaining, 2000);
        this.startTimer(toast);
    }

    remove(toast, { refocus }) {
        const index = this.toasts.indexOf(toast);

        if (index === -1) {
            return;
        }

        this.toasts.splice(index, 1);
        this.clearTimer(toast.timer);
        toast.timer = null;
        const hadFocus = toast.element.contains(document.activeElement);
        toast.element.remove();

        if (hadFocus && refocus) {
            const back = this.focusBefore;
            this.focusBefore = null;

            if (back && back.isConnected && typeof back.focus === 'function') {
                back.focus({ preventScroll: true });
            } else {
                this.options.returnFocus?.();
            }
        }

        if (!this.toasts.some((shown) => shown.anchor !== null)) {
            this.stopListening();
        }
    }

    // ---------------------------------------------------------------- pointing at a cell

    /**
     * Puts an anchored toast next to its cell - false (and back to the stack) when the cell is gone or out of view.
     */
    position(toast) {
        const target = this.options.resolveAnchor?.(toast.anchor) ?? null;
        const view = window.innerHeight || document.documentElement.clientHeight || 0;
        const width = window.innerWidth || document.documentElement.clientWidth || 0;
        const rect = target?.isConnected ? target.getBoundingClientRect() : null;

        if (rect === null || rect.bottom <= 0 || rect.top >= view || (rect.width === 0 && rect.height === 0)) {
            this.dock(toast);

            return false;
        }

        const element = toast.element;
        element.classList.add('is-anchored');
        const box = element.getBoundingClientRect();
        const below = rect.bottom + GAP;
        const fitsBelow = below + box.height <= view - EDGE;
        const top = fitsBelow || rect.top - GAP - box.height < EDGE ? below : rect.top - GAP - box.height;
        const left = Math.max(EDGE, Math.min(rect.left, width - box.width - EDGE));
        element.classList.toggle('is-above', top < rect.top);
        element.style.top = `${Math.round(top)}px`;
        element.style.left = `${Math.round(left)}px`;
        // The little arrow points at the cell's left part
        element.style.setProperty('--sheet-toast-arrow', `${Math.round(Math.max(12, Math.min(rect.left - left + 16, box.width - 16)))}px`);

        if (!this.listening) {
            this.listening = true;
            document.addEventListener('scroll', this.onViewportChange, { capture: true, passive: true });
            window.addEventListener('resize', this.onViewportChange);
            document.addEventListener('focusin', this.onFocusMove, true);
            document.addEventListener('pointerdown', this.onFocusMove, true);
        }

        return true;
    }

    /** An anchored toast goes back to the stack (the organiser moved on, the cell went). */
    dock(toast) {
        toast.anchor = null;
        toast.element.classList.remove('is-anchored', 'is-above');
        toast.element.style.removeProperty('top');
        toast.element.style.removeProperty('left');
        toast.element.style.removeProperty('--sheet-toast-arrow');

        if (!this.toasts.some((shown) => shown.anchor !== null)) {
            this.stopListening();
        }
    }

    organiserMoved(event) {
        for (const toast of this.toasts.filter((shown) => shown.anchor !== null && shown.armed)) {
            if (!toast.element.contains(event.target)) {
                this.dock(toast);
            }
        }
    }

    scheduleReposition() {
        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => {
            this.toasts.filter((toast) => toast.anchor !== null).forEach((toast) => this.position(toast));
        });
    }

    stopListening() {
        if (!this.listening) {
            return;
        }

        this.listening = false;
        document.removeEventListener('scroll', this.onViewportChange, { capture: true });
        window.removeEventListener('resize', this.onViewportChange);
        document.removeEventListener('focusin', this.onFocusMove, true);
        document.removeEventListener('pointerdown', this.onFocusMove, true);
    }
}
