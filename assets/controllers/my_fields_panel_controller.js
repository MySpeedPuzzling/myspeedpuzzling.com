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
 * - Closing the dialog fetches the "My fields" block again and puts back what was typed into the old one.
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

        this.onClose = () => this.refreshBlock();
        this.onClick = (event) => {
            // The backdrop is the dialog element itself; its content sits in the children
            if (event.target === this.dialog || event.target.closest('[data-own-fields-close]')) {
                this.dialog.close();
            }
        };
        this.onFrameMissing = (event) => {
            // Signed out meanwhile, or an error page: never Turbo's "Content missing", never a visit away
            event.preventDefault();
            this.frame.innerHTML = '';
            this.unavailable.classList.remove('d-none');
        };

        this.dialog.addEventListener('close', this.onClose);
        this.dialog.addEventListener('click', this.onClick);
        this.frame.addEventListener('turbo:frame-missing', this.onFrameMissing);
    }

    disconnect() {
        if (!this.dialog) {
            return;
        }

        this.dialog.removeEventListener('close', this.onClose);
        this.dialog.removeEventListener('click', this.onClick);
        this.frame.removeEventListener('turbo:frame-missing', this.onFrameMissing);

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
            const typed = readTyped(this.blockTarget);

            this.blockTarget.innerHTML = markup;
            writeTyped(this.blockTarget, typed);
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
