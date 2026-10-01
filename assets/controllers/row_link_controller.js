import { Controller } from '@hotwired/stimulus';

// Clicks on these (or inside them) keep their own meaning and never open the row's link
const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [role="button"], [contenteditable], [data-row-link-ignore]';

/**
 * Row Link Controller
 *
 * Makes a whole row/card open its primary link, e.g. a leaderboard row opening the result detail modal.
 *
 * Usage:
 *   <tr data-controller="row-link" data-action="click->row-link#open">
 *       <td><a href="..." data-row-link-target="link" data-turbo-frame="modal-frame">...</a></td>
 *       ...
 *   </tr>
 *
 * - A click anywhere in the element that is not on an interactive element (links, buttons, form fields,
 *   labels, [data-row-link-ignore]) and is not the end of a text selection clicks the link itself,
 *   so Turbo handles frame targeting exactly as if the link was clicked.
 * - Ctrl/Cmd/Shift click and middle click open the link's href in a new tab.
 * - Keyboard users tab to the real link - the row gets no tabindex on purpose.
 * - The `.row-link` class (cursor: pointer) is added on connect, styles in _dynamic-modal.scss.
 */
export default class extends Controller {
    static targets = ['link'];

    connect() {
        this.element.classList.add('row-link');

        // Middle click fires auxclick, not click - handled here so the markup needs only click->row-link#open
        this.element.addEventListener('auxclick', this.open);
    }

    disconnect() {
        this.element.classList.remove('row-link');
        this.element.removeEventListener('auxclick', this.open);
    }

    open = (event) => {
        if (!this.hasLinkTarget || event.defaultPrevented) {
            return;
        }

        // Only the primary button (click) and the middle button (auxclick)
        if (event.button !== 0 && event.button !== 1) {
            return;
        }

        const interactive = event.target instanceof Element ? event.target.closest(INTERACTIVE) : null;
        if (interactive && this.element.contains(interactive)) {
            return;
        }

        // The visitor was selecting text (e.g. copying a name or a time), not clicking the row
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed && selection.toString().trim() !== '') {
            return;
        }

        if (event.button === 1 || event.ctrlKey || event.metaKey || event.shiftKey) {
            window.open(this.linkTarget.href, '_blank', 'noopener');
            return;
        }

        this.linkTarget.click();
    };
}
