import { Controller } from '@hotwired/stimulus';

/**
 * The player's own fields in the add-result form: "Add your own field" / "Manage my fields" open the manage page's
 * body in a dialog over the form (docs/features/custom-fields.md "Making and managing fields from the add form").
 *
 * The rules this keeps:
 * - Nothing here submits, re-renders or navigates the form behind the dialog. The dialog's own forms live in a
 *   Turbo Frame and navigate that frame only.
 * - The area sits inside the result's <form>, and a form can not hold another form: the (empty) dialog is moved out
 *   of the form before anything is loaded into it - into the Bootstrap modal the form is in when there is one (a
 *   modal takes the focus back from anything outside it), else to the end of the page.
 * - Closing the dialog after something was changed in it fetches the "My fields" block again and puts back what was
 *   typed into the old one; closing it after only looking changes nothing.
 * - Anything that fails leaves the form as it was. Without <dialog> the link opens the manage page in a new tab,
 *   as it did before this existed.
 */
export default class extends Controller {
    static targets = ['block', 'dialog'];
    static values = { panelUrl: String, blockUrl: String };

    connect() {
        this.dialog = this.hasDialogTarget ? this.dialogTarget : null;

        if (!this.dialog || typeof this.dialog.showModal !== 'function') {
            this.dialog = null;

            return;
        }

        this.frame = this.dialog.querySelector('[data-own-fields-frame]');
        this.loadingMarkup = this.frame.innerHTML;
        this.unavailable = this.dialog.querySelector('[data-own-fields-unavailable]');

        (this.element.closest('.modal-content') || document.body).appendChild(this.dialog);

        this.changed = false;
        this.refreshes = 0;

        this.onClose = () => {
            // Only looked: the block stays exactly as it is
            if (this.changed) {
                this.changed = false;
                this.refreshBlock();
            }
        };
        this.onMouseDown = (event) => {
            this.pressedOnBackdrop = event.target === this.dialog;
        };
        this.onClick = (event) => {
            // The backdrop is the dialog element itself; its content sits in the children. A text selection dragged
            // out of an input ends as a click on the backdrop too - only a press that began there closes
            if ((event.target === this.dialog && this.pressedOnBackdrop) || event.target.closest('[data-own-fields-close]')) {
                this.dialog.close();
            }
        };
        this.onSubmitEnd = () => {
            this.changed = true;
        };
        this.onFrameMissing = (event) => {
            // Signed out meanwhile, or an error page: never Turbo's "Content missing", never a visit away
            event.preventDefault();
            this.showUnavailable();
        };
        this.onFetchError = () => this.showUnavailable();

        this.dialog.addEventListener('close', this.onClose);
        this.dialog.addEventListener('mousedown', this.onMouseDown);
        this.dialog.addEventListener('click', this.onClick);
        this.frame.addEventListener('turbo:submit-end', this.onSubmitEnd);
        this.frame.addEventListener('turbo:frame-missing', this.onFrameMissing);
        this.frame.addEventListener('turbo:fetch-request-error', this.onFetchError);
    }

    showUnavailable() {
        this.frame.innerHTML = '';
        this.unavailable.classList.remove('d-none');
    }

    disconnect() {
        if (!this.dialog) {
            return;
        }

        this.dialog.removeEventListener('close', this.onClose);
        this.dialog.removeEventListener('mousedown', this.onMouseDown);
        this.dialog.removeEventListener('click', this.onClick);
        this.frame.removeEventListener('turbo:submit-end', this.onSubmitEnd);
        this.frame.removeEventListener('turbo:frame-missing', this.onFrameMissing);
        this.frame.removeEventListener('turbo:fetch-request-error', this.onFetchError);

        if (this.dialog.open) {
            this.dialog.close();
        }

        // Back where the template put it, so a reconnect finds it
        this.frame.removeAttribute('src');
        this.frame.innerHTML = this.loadingMarkup;
        this.element.appendChild(this.dialog);
        this.dialog = null;
    }

    open(event) {
        if (!this.dialog) {
            return; // the link's own href: the manage page in a new tab
        }

        event.preventDefault();

        this.unavailable.classList.add('d-none');
        this.frame.innerHTML = this.loadingMarkup;
        this.frame.removeAttribute('src');
        this.frame.setAttribute('src', this.panelUrlValue);

        if (!this.dialog.open) {
            this.dialog.showModal();
        }
    }

    async refreshBlock() {
        if (!this.hasBlockTarget) {
            return;
        }

        // Closed twice quickly: only the answer to the last question is used
        const refresh = ++this.refreshes;

        try {
            const response = await fetch(this.blockUrlValue, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });

            // Anything but the block itself (an error, a sign-in page after a redirect): the form stays as it is
            if (!response.ok || response.redirected) {
                return;
            }

            const markup = await response.text();

            if (refresh !== this.refreshes || !this.hasBlockTarget) {
                return;
            }

            const typed = readTyped(this.blockTarget);
            // Typing in the block already (a slow answer): the same input gets the focus and the caret back
            const active = this.blockTarget.contains(document.activeElement) ? document.activeElement : null;
            const focused = active && active.name ? { name: active.name, value: active.value, start: active.selectionStart, end: active.selectionEnd, radio: active.type === 'radio' } : null;

            this.blockTarget.innerHTML = markup;
            writeTyped(this.blockTarget, typed);

            if (focused) {
                const again = Array.from(this.blockTarget.querySelectorAll('[name]')).find((input) => input.name === focused.name && (!focused.radio || input.value === focused.value));

                if (again) {
                    again.focus({ preventScroll: true });

                    if (!focused.radio && typeof focused.start === 'number' && typeof again.setSelectionRange === 'function') {
                        try {
                            again.setSelectionRange(focused.start, focused.end);
                        } catch (error) {
                            // An input type without a text selection
                        }
                    }
                }
            }
        } catch (error) {
            // The form keeps the block it had
        }
    }
}

/**
 * What the player typed into the block, by input name. A radio group is its checked value.
 */
function readTyped(block) {
    const typed = new Map();

    block.querySelectorAll('[name^="my_fields["]').forEach((input) => {
        if (input.type === 'radio') {
            if (input.checked) {
                typed.set(input.name, input.value);
            }

            return;
        }

        typed.set(input.name, input.value);
    });

    return typed;
}

function writeTyped(block, typed) {
    block.querySelectorAll('[name^="my_fields["]').forEach((input) => {
        if (!typed.has(input.name)) {
            return;
        }

        const value = typed.get(input.name);

        if (input.type === 'radio') {
            input.checked = input.value === value;

            return;
        }

        if (input.tagName === 'SELECT') {
            // A choice taken off the list meanwhile is not put back
            if (Array.from(input.options).some((option) => option.value === value)) {
                input.value = value;
            }

            return;
        }

        input.value = value;
    });
}
